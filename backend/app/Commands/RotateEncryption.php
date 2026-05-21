<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * RotateEncryption — re-encrypt PII pakai key versi terbaru.
 *
 * Use case: rotasi encryption key periodik (default 90 hari per AUDIT.md item B).
 *
 * SOP rotasi:
 *   1. Generate key baru:
 *      php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
 *   2. Tambah ke .env:
 *      encryption.key_v2 = base64:NEW_KEY_HERE
 *      encryption.active_version = v2
 *   3. (penting) JANGAN hapus encryption.key (v1) dulu — masih dipakai untuk
 *      decrypt data lama saat rotation berlangsung.
 *   4. Run: php spark hr:rotate-encryption --batch=500
 *   5. Setelah selesai (semua row migrated), tunggu 1 minggu untuk audit,
 *      lalu archive key v1 ke vault offline (NIST SP 800-57 retention).
 *
 * Tabel yang di-rotate (dari migrasi audit):
 *   employees.nik_encrypted, npwp_encrypted, bank_account_encrypted
 *
 * Idempotent: row yang sudah pakai key terbaru di-skip via
 * Encryption::needsRotation().
 */
class RotateEncryption extends BaseCommand
{
    protected $group       = 'HR';
    protected $name        = 'hr:rotate-encryption';
    protected $description = 'Re-encrypt PII employees pakai active key version (rotation).';
    protected $usage       = 'hr:rotate-encryption [options]';
    protected $options     = [
        '--batch'   => 'Jumlah row per batch (default 500)',
        '--table'   => 'Hanya proses tabel tertentu (default semua)',
        '--dry-run' => 'Hitung yang akan ter-rotate tanpa commit',
    ];

    /** Mapping tabel → kolom-kolom encrypted yang perlu di-rotate */
    private const TARGETS = [
        'employees' => [
            'columns'    => ['nik_encrypted', 'npwp_encrypted', 'bank_account_encrypted'],
            'pk'         => 'id',
            'version_col'=> 'key_version',
        ],
    ];

    public function run(array $params)
    {
        $batchSize  = max(1, (int) (CLI::getOption('batch') ?? 500));
        $tableFilter= (string) (CLI::getOption('table') ?? '');
        $dryRun     = (bool) CLI::getOption('dry-run');

        $enc = Services::encryption();
        $activeVersion = $enc->activeVersion();
        $available = $enc->availableVersions();

        CLI::write('=== Rotate Encryption ===', 'yellow');
        CLI::write("Active version  : {$activeVersion}");
        CLI::write('Available keys  : ' . implode(', ', $available));
        if ($dryRun) CLI::write('Mode            : DRY-RUN (tidak commit)', 'cyan');
        CLI::newLine();

        if (count($available) < 2) {
            CLI::error('Cuma 1 versi key tersedia. Set encryption.key_v2 di .env terlebih dulu.');
            return EXIT_USER_INPUT;
        }
        if ($activeVersion === 'v1') {
            CLI::error('active_version masih v1. Set encryption.active_version=vN di .env supaya rotasi efektif.');
            return EXIT_USER_INPUT;
        }

        $db = \Config\Database::connect();

        $totalRotated = 0;
        foreach (self::TARGETS as $table => $spec) {
            if ($tableFilter !== '' && $tableFilter !== $table) continue;

            CLI::write("→ Tabel `{$table}`", 'green');

            // Filter row yang key_version != active. Kita pakai numeric
            // versi (1 untuk v1, 2 untuk v2, dst.) untuk perbandingan cepat.
            $activeNum = (int) substr($activeVersion, 1);
            $hasVersionCol = in_array($spec['version_col'], $db->getFieldNames($table), true);

            $offset = 0;
            $tableRotated = 0;
            while (true) {
                $builder = $db->table($table)->select($spec['pk'] . ',' . implode(',', $spec['columns'])
                    . ($hasVersionCol ? ',' . $spec['version_col'] : ''));
                if ($hasVersionCol) {
                    $builder->where($spec['version_col'] . ' <', $activeNum);
                }
                $rows = $builder->limit($batchSize, $offset)->get()->getResultArray();
                if (empty($rows)) break;

                if ($dryRun) {
                    $tableRotated += count($rows);
                    $offset += $batchSize;
                    if ($offset > 100000) break; // safety untuk dry-run
                    continue;
                }

                foreach ($rows as $row) {
                    $update = [];
                    foreach ($spec['columns'] as $col) {
                        $cipher = $row[$col] ?? null;
                        if ($cipher === null || $cipher === '') continue;
                        if (! $enc->needsRotation($cipher)) continue;

                        $plaintext = $enc->decrypt($cipher);
                        if ($plaintext === null) {
                            CLI::write("  ⚠ row #{$row[$spec['pk']]} kolom {$col} gagal decrypt — skip", 'yellow');
                            continue;
                        }
                        $update[$col] = $enc->encrypt($plaintext);
                    }
                    if ($hasVersionCol) {
                        $update[$spec['version_col']] = $activeNum;
                    }
                    if (! empty($update)) {
                        $update['updated_at'] = date('Y-m-d H:i:s');
                        $db->table($table)
                            ->where($spec['pk'], (int) $row[$spec['pk']])
                            ->update($update);
                        $tableRotated++;
                    }
                }
                CLI::write("  · batch offset={$offset}, rotated={$tableRotated} so far");
                // Tidak increment offset karena kita sudah update; query
                // berikutnya akan ambil row yang masih perlu rotate.
                if ($hasVersionCol) {
                    // Kalau ada key_version, query selalu fresh. Tapi kalau row
                    // tidak ter-update (gagal decrypt), bisa loop infinite.
                    // Kita hentikan kalau jumlah tidak naik.
                    static $lastCount = -1;
                    if ($lastCount === $tableRotated) break;
                    $lastCount = $tableRotated;
                } else {
                    $offset += $batchSize;
                }
            }

            CLI::write("  Tabel {$table}: {$tableRotated} rows " . ($dryRun ? 'akan ter-rotate' : 'ter-rotate'));
            $totalRotated += $tableRotated;
        }

        CLI::newLine();
        CLI::write('=== Selesai ===', 'yellow');
        CLI::write('Total rows rotated: ' . $totalRotated, $totalRotated > 0 ? 'green' : 'white');

        try {
            Services::audit()->forModule('SECURITY')->log(
                action: 'encryption.rotation',
                entity: 'system',
                description: "Rotasi encryption key v1→{$activeVersion}: {$totalRotated} rows" . ($dryRun ? ' [DRY-RUN]' : ''),
                metadata: [
                    'active_version' => $activeVersion,
                    'total_rotated'  => $totalRotated,
                    'dry_run'        => $dryRun,
                ],
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}

        return EXIT_SUCCESS;
    }
}