<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Backup\BackupService;

/**
 * S2.3 — Backup Verification (cron weekly).
 *
 * Verify integrity of latest SUCCESS backup:
 *   1. Check file exists (local) atau S3 head-object (cloud)
 *   2. Re-compute SHA-256 dan match dengan stored hash
 *   3. (Opsional) test gunzip --test untuk validate gzip integrity
 *
 * Usage:
 *   php spark db:backup-verify              # verify latest only
 *   php spark db:backup-verify --all-recent # verify semua run dalam 7 hari
 *
 * Recommended cron:
 *   0 4 * * 0 cd /var/www/hris && php spark db:backup-verify --all-recent
 */
class DbBackupVerify extends BaseCommand
{
    protected $group       = 'Backup';
    protected $name        = 'db:backup-verify';
    protected $description = 'Verify integrity of recent backups (file exists + sha256 match + gzip valid)';
    protected $usage       = 'db:backup-verify [--all-recent]';
    protected $options     = [
        '--all-recent' => 'Verifikasi semua run SUCCESS dalam 7 hari (default: latest only)',
    ];

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $svc = new BackupService();

        $verifyAll = in_array('--all-recent', $params, true);

        $query = $db->table('backup_runs')->where('status', 'SUCCESS');
        if ($verifyAll) {
            $query->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')));
        }
        $rows = $query->orderBy('created_at', 'DESC')->get()->getResultArray();

        if (empty($rows)) {
            CLI::write('No SUCCESS backup runs found to verify', 'yellow');
            return;
        }

        if (! $verifyAll) {
            $rows = [$rows[0]];
        }

        CLI::write('[' . date('Y-m-d H:i:s') . '] Verifying ' . count($rows) . ' backup run(s)…', 'cyan');

        $passed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $path   = (string) ($row['storage_path'] ?? '');
            $hash   = (string) ($row['sha256'] ?? '');
            $size   = (int) ($row['size_bytes'] ?? 0);

            CLI::write("  - Run id={$id} path={$path}", 'white');

            $result = $this->verifyOne($path, $hash, $size);
            if ($result['ok']) {
                $svc->markVerified($id);
                CLI::write('    ✓ VERIFIED', 'green');
                $passed++;
            } else {
                CLI::error('    ✗ FAILED: ' . $result['reason']);
                $db->table('backup_runs')->where('id', $id)->update([
                    'status'        => 'FAILED',
                    'error_message' => 'Verification failed: ' . $result['reason'],
                ]);
                $this->writeAudit($id, $result['reason']);
                $failed++;
            }
        }

        CLI::write(sprintf(
            '[%s] Summary: passed=%d failed=%d',
            date('Y-m-d H:i:s'),
            $passed,
            $failed
        ), $failed === 0 ? 'green' : 'red');

        if ($failed > 0) {
            exit(1);
        }
    }

    /**
     * Verify single backup file. Returns ['ok' => bool, 'reason' => string].
     */
    private function verifyOne(string $path, string $expectedHash, int $expectedSize): array
    {
        if ($path === '') {
            return ['ok' => false, 'reason' => 'storage_path empty'];
        }

        // S3: hanya bisa verify size + ETag (tidak bisa hash penuh tanpa download).
        // Strategy: head-object dulu, kalau ada budget → download + sha256.
        if (str_starts_with($path, 's3://')) {
            return $this->verifyS3($path, $expectedHash, $expectedSize);
        }

        // Local file
        if (! file_exists($path)) {
            return ['ok' => false, 'reason' => 'file not found: ' . $path];
        }
        $actualSize = filesize($path);
        if ($expectedSize > 0 && $actualSize !== $expectedSize) {
            return ['ok' => false, 'reason' => "size mismatch: expected {$expectedSize}, got {$actualSize}"];
        }
        $actualHash = hash_file('sha256', $path);
        if ($expectedHash !== '' && $actualHash !== $expectedHash) {
            return ['ok' => false, 'reason' => 'sha256 mismatch'];
        }

        // gzip integrity test (skip untuk file .gpg karena terenkripsi)
        if (str_ends_with($path, '.sql.gz')) {
            $gzip = (string) env('BACKUP_GZIP_BIN', 'gzip');
            $cmd  = sprintf('%s --test %s 2>&1', escapeshellcmd($gzip), escapeshellarg($path));
            $output = [];
            $code   = 0;
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return ['ok' => false, 'reason' => 'gzip --test failed: ' . implode(' | ', $output)];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    private function verifyS3(string $s3Path, string $expectedHash, int $expectedSize): array
    {
        if (! preg_match('#^s3://([^/]+)/(.+)$#', $s3Path, $m)) {
            return ['ok' => false, 'reason' => 'invalid s3 path'];
        }
        [, $bucket, $key] = $m;
        $awsBin = (string) env('BACKUP_AWS_BIN', 'aws');
        $region = (string) env('BACKUP_S3_REGION', 'ap-southeast-1');

        // head-object → JSON output dengan ContentLength
        $cmd = sprintf(
            '%s s3api head-object --bucket %s --key %s --region %s 2>&1',
            escapeshellcmd($awsBin),
            escapeshellarg($bucket),
            escapeshellarg($key),
            escapeshellarg($region)
        );
        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            return ['ok' => false, 'reason' => 's3 head-object failed: ' . implode(' | ', array_slice($output, -3))];
        }
        $meta = json_decode(implode("\n", $output), true);
        if (! is_array($meta)) {
            return ['ok' => false, 'reason' => 's3 head-object returned non-JSON'];
        }
        $actualSize = (int) ($meta['ContentLength'] ?? 0);
        if ($expectedSize > 0 && $actualSize !== $expectedSize) {
            return ['ok' => false, 'reason' => "s3 size mismatch: expected {$expectedSize}, got {$actualSize}"];
        }

        // SHA-256 verification via download requires temp space; skip by default
        // tapi bisa diaktifkan via env BACKUP_VERIFY_DOWNLOAD=1
        if (env('BACKUP_VERIFY_DOWNLOAD') && $expectedHash !== '') {
            $tmp = tempnam(sys_get_temp_dir(), 'hris_backup_verify_');
            $dl  = sprintf(
                '%s s3 cp s3://%s/%s %s --region %s 2>&1',
                escapeshellcmd($awsBin),
                escapeshellarg($bucket),
                escapeshellarg($key),
                escapeshellarg($tmp),
                escapeshellarg($region)
            );
            $out = [];
            $rc  = 0;
            exec($dl, $out, $rc);
            if ($rc !== 0 || ! file_exists($tmp)) {
                @unlink($tmp);
                return ['ok' => false, 'reason' => 's3 download failed'];
            }
            $actualHash = hash_file('sha256', $tmp);
            @unlink($tmp);
            if ($actualHash !== $expectedHash) {
                return ['ok' => false, 'reason' => 's3 sha256 mismatch'];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    private function writeAudit(int $runId, string $reason): void
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }
            $db->table('audit_logs')->insert([
                'company_id'    => null,
                'user_id'       => null,
                'event'         => 'backup.verify_failed',
                'entity_type'   => 'backup_runs',
                'entity_id'     => $runId,
                'severity'      => 'CRITICAL',
                'metadata_json' => json_encode(['reason' => $reason]),
                'ip_address'    => 'cli',
                'user_agent'    => 'spark:db:backup-verify',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[BackupVerify] Failed to write audit log: ' . $e->getMessage());
        }
    }
}