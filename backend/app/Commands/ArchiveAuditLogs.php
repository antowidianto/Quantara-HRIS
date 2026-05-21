<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * ArchiveAuditLogs — soft-archive audit log lama (item N).
 *
 * Strategi: tetap simpan di tabel `audit_logs` tapi set `archived_at` =
 * NOW() supaya query default exclude. AuditLogController sudah filter
 * `WHERE archived_at IS NULL` di list (perubahan minor diperlukan).
 *
 * Setelah 5 tahun, archived rows boleh dipindah ke storage offline
 * (CSV S3 / cold backup) lewat varian command `--purge` (future).
 *
 * Cron mingguan:
 *   0 3 * * 0  cd /var/www/hris/backend && php spark hr:archive-audit
 *
 * Default retention: 730 hari (2 tahun) — sesuai requirement audit
 * UU PDP & ISO 27001. Override dengan --before atau --days.
 */
class ArchiveAuditLogs extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:archive-audit';
    protected $description = 'Set archived_at untuk audit_logs lebih lama dari retention.';
    protected $usage       = 'hr:archive-audit [options]';
    protected $options     = [
        '--days'    => 'Retention dalam hari (default 730)',
        '--before'  => 'Cutoff date (Y-m-d). Override --days kalau di-set.',
        '--batch'   => 'Update per batch (default 5000)',
        '--dry-run' => 'Hanya hitung row yang akan diarchive',
    ];

    public function run(array $params)
    {
        $days   = (int) (CLI::getOption('days') ?? 730);
        $before = (string) (CLI::getOption('before') ?? '');
        $batch  = max(100, (int) (CLI::getOption('batch') ?? 5000));
        $dryRun = (bool) CLI::getOption('dry-run');

        if ($before === '') {
            $before = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}/', $before)) {
            CLI::error("Invalid --before. Format: Y-m-d");
            return EXIT_USER_INPUT;
        }

        CLI::write('=== Archive Audit Logs ===', 'yellow');
        CLI::write("Cutoff (created_at <): {$before}");
        if ($dryRun) CLI::write('Mode: DRY-RUN', 'cyan');
        CLI::newLine();

        $db = \Config\Database::connect();
        if (! in_array('archived_at', $db->getFieldNames('audit_logs'), true)) {
            CLI::error("Kolom audit_logs.archived_at belum ada. Jalankan migrate dulu.");
            return EXIT_USER_INPUT;
        }

        $totalToArchive = $db->table('audit_logs')
            ->where('created_at <', $before)
            ->where('archived_at IS NULL')
            ->countAllResults();
        CLI::write("Rows that would be archived: {$totalToArchive}");

        if ($totalToArchive === 0) {
            CLI::write('Tidak ada yang perlu di-archive.', 'green');
            return EXIT_SUCCESS;
        }
        if ($dryRun) {
            return EXIT_SUCCESS;
        }

        $now = date('Y-m-d H:i:s');
        $archived = 0;
        while (true) {
            // Update per batch supaya tidak lock tabel terlalu lama
            $affected = $db->query(
                "UPDATE audit_logs SET archived_at = ? "
              . "WHERE created_at < ? AND archived_at IS NULL "
              . "LIMIT {$batch}",
                [$now, $before]
            );
            $rows = $db->affectedRows();
            if ($rows <= 0) break;
            $archived += $rows;
            CLI::write("  · batch +{$rows} (total {$archived}/{$totalToArchive})");
        }

        CLI::newLine();
        CLI::write("Archived: {$archived} rows", 'green');

        try {
            Services::audit()->forModule('SYSTEM')->log(
                action: 'audit.archive',
                entity: 'audit_logs',
                description: "Archive {$archived} audit log rows (cutoff < {$before})",
                metadata: [
                    'cutoff'   => $before,
                    'archived' => $archived,
                ],
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return EXIT_SUCCESS;
    }
}