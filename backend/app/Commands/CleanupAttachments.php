<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * CleanupAttachments — hapus selfie absensi yang lebih lama dari retention.
 *
 * Pakai cron mingguan:
 *   0 4 * * 0  cd /var/www/hris/backend && php spark hr:cleanup-attachments
 *
 * Logika:
 *   1. Loop per company, baca setting `selfie_retention_days` (default 90).
 *   2. Cari attendance dimana clock_in_selfie_path / clock_out_selfie_path
 *      yang attendance_date < now - retention_days.
 *   3. SKIP yang anomaly_score >= 30 (untuk bukti audit jangka panjang).
 *   4. Hapus file via FileStorage::delete (idempotent), null-kan kolom path,
 *      log ke audit_logs (NOTICE).
 */
class CleanupAttachments extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:cleanup-attachments';
    protected $description = 'Hapus selfie absensi lama berdasarkan retention setting per company.';
    protected $usage       = 'hr:cleanup-attachments [options]';
    protected $options     = [
        '--dry-run'   => 'Tidak hapus apapun, hanya tampilkan yang akan terhapus',
        '--company'   => 'Hanya proses 1 company (id)',
        '--keep-anomaly-min' => 'Override anomaly score minimum yang di-skip (default 30)',
    ];

    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run');
        $companyFilter = (int) (CLI::getOption('company') ?? 0);
        $keepAnomalyMin = (int) (CLI::getOption('keep-anomaly-min') ?? 30);

        CLI::write('=== Cleanup Selfie Attachments ===', 'yellow');
        if ($dryRun) CLI::write('Mode: DRY-RUN — tidak ada file yang dihapus', 'cyan');

        $db = \Config\Database::connect();
        $storage = Services::fileStorage();

        // Ambil daftar company
        $companyBuilder = $db->table('companies')->where('is_active', 1);
        if ($companyFilter) $companyBuilder->where('id', $companyFilter);
        $companies = $companyBuilder->get()->getResultArray();

        $totalDeleted = 0;
        $totalKept = 0;
        $totalSizeBytes = 0;

        foreach ($companies as $c) {
            $cid = (int) $c['id'];
            // Resolve retention dari settings
            $row = $db->table('settings')
                ->where('company_id', $cid)
                ->where('key', 'selfie_retention_days')
                ->get()->getRowArray();
            $retentionDays = $row ? max(7, (int) $row['value']) : 90;
            $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));

            CLI::write("\n→ Company #{$cid} ({$c['name']}) — retention {$retentionDays} hari, cutoff {$cutoffDate}", 'green');

            // Ambil semua attendance dengan selfie path yang lebih tua dari cutoff
            $rows = $db->table('attendances')
                ->select('id, clock_in_selfie_path, clock_out_photo, anomaly_score, work_date')
                ->where('company_id', $cid)
                ->where('work_date <', $cutoffDate)
                ->groupStart()
                    ->where('clock_in_selfie_path IS NOT NULL')
                    ->orWhere('clock_out_photo IS NOT NULL')
                ->groupEnd()
                ->get()->getResultArray();

            $deleted = 0; $kept = 0; $bytes = 0;
            foreach ($rows as $r) {
                if ((int) $r['anomaly_score'] >= $keepAnomalyMin) {
                    $kept++;
                    continue;
                }

                $update = ['updated_at' => date('Y-m-d H:i:s')];
                foreach (['clock_in_selfie_path', 'clock_out_photo'] as $col) {
                    $path = $r[$col] ?? null;
                    if (! $path) continue;
                    // Path tersimpan sebagai `uploads/selfies/...` — strip prefix
                    // 'uploads/' karena FileStorage root sudah `WRITEPATH/uploads`.
                    $rel = preg_replace('#^uploads/#', '', $path);
                    if ($storage->exists($rel)) {
                        $bytes += $storage->size($rel);
                        if (! $dryRun) {
                            $storage->delete($rel);
                        }
                    }
                    $update[$col] = null;
                }

                if (! $dryRun) {
                    $db->table('attendances')->where('id', (int) $r['id'])->update($update);
                }
                $deleted++;
            }

            CLI::write("  Deleted: {$deleted} files | Kept (anomaly): {$kept} | Size freed: " . round($bytes / 1024 / 1024, 2) . ' MB');

            $totalDeleted += $deleted;
            $totalKept += $kept;
            $totalSizeBytes += $bytes;
        }

        CLI::newLine();
        CLI::write('=== Total ===', 'yellow');
        CLI::write("Files deleted: {$totalDeleted}", $totalDeleted > 0 ? 'green' : 'white');
        CLI::write("Anomaly-flagged kept: {$totalKept}");
        CLI::write('Size freed: ' . round($totalSizeBytes / 1024 / 1024, 2) . ' MB');

        // Audit log untuk record bahwa cleanup jalan
        try {
            Services::audit()->forModule('SYSTEM')->log(
                action: 'attachments.cleanup',
                entity: 'system',
                description: "Cleanup attachments: deleted={$totalDeleted}, kept={$totalKept}, freed=" . round($totalSizeBytes / 1024 / 1024, 2) . 'MB' . ($dryRun ? ' [DRY-RUN]' : ''),
                metadata: [
                    'deleted'    => $totalDeleted,
                    'kept'       => $totalKept,
                    'bytes'      => $totalSizeBytes,
                    'dry_run'    => $dryRun,
                ],
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return EXIT_SUCCESS;
    }
}