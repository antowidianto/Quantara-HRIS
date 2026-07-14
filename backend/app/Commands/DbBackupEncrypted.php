<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Backup\BackupService;

/**
 * S2.3 — Backup nightly (cron 02:00).
 *
 * Usage:
 *   php spark db:backup-encrypted
 *   php spark db:backup-encrypted --cleanup     # juga purge expired backup
 *
 * Recommended cron:
 *   0 2 * * * cd /var/www/hris && php spark db:backup-encrypted --cleanup >> writable/logs/backup.log 2>&1
 */
class DbBackupEncrypted extends BaseCommand
{
    protected $group       = 'Backup';
    protected $name        = 'db:backup-encrypted';
    protected $description = 'Run encrypted full database backup (mysqldump + gzip + GPG + S3)';
    protected $usage       = 'db:backup-encrypted [--cleanup]';
    protected $options     = [
        '--cleanup' => 'Hapus backup expired berdasarkan retention policy setelah dump',
    ];

    public function run(array $params)
    {
        $svc   = new BackupService();
        $runId = 0;
        $monitor = new \App\Libraries\System\BusinessJobMonitor();
        $monitorRunId = $monitor->start('backup.full');

        try {
            $runId = $svc->startRun('FULL');
            CLI::write('[' . date('Y-m-d H:i:s') . '] Backup run id=' . $runId . ' started', 'cyan');

            $info = $svc->executeDump();

            CLI::write(sprintf(
                '[%s] Dump complete: path=%s size=%d bytes sha256=%s gpg=%s',
                date('Y-m-d H:i:s'),
                $info['storage_path'],
                $info['size_bytes'],
                substr($info['sha256'], 0, 16) . '…',
                $info['gpg_encrypted'] ? 'yes' : 'no'
            ), 'green');

            $svc->completeRun($runId, $info);
            $monitor->succeed($monitorRunId, 1, ['backup_run_id' => $runId, 'size_bytes' => $info['size_bytes']]);
            CLI::write('[' . date('Y-m-d H:i:s') . '] Run id=' . $runId . ' marked SUCCESS', 'green');

            // Audit log severity NOTICE — supaya muncul di dashboard SaaS metrics
            $this->writeAudit('backup.success', $runId, [
                'storage_path' => $info['storage_path'],
                'size_bytes'   => $info['size_bytes'],
                'sha256'       => $info['sha256'],
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $monitor->fail($monitorRunId, 'BACKUP_FAILED', 1, ['backup_run_id' => $runId]);
            CLI::error('[' . date('Y-m-d H:i:s') . '] Backup failed: ' . $msg);
            if ($runId > 0) {
                $svc->failRun($runId, $msg);
            }
            $this->writeAudit('backup.failed', $runId, ['error' => $msg], 'CRITICAL');
            // exit non-zero supaya cron monitor (heartbeat / healthchecks.io) detect
            exit(1);
        }

        if (in_array('--cleanup', $params, true) || (isset($params['cleanup']) && $params['cleanup'])) {
            $this->cleanupExpired($svc);
        }
    }

    private function cleanupExpired(BackupService $svc): void
    {
        $expired = $svc->findExpired();
        if (empty($expired)) {
            CLI::write('[' . date('Y-m-d H:i:s') . '] No expired backups to clean up', 'yellow');
            return;
        }

        CLI::write('[' . date('Y-m-d H:i:s') . '] Cleaning up ' . count($expired) . ' expired backup file(s)…', 'yellow');
        $db = \Config\Database::connect();

        foreach ($expired as $row) {
            $path = (string) ($row['storage_path'] ?? '');
            $deleted = false;

            if ($path !== '' && str_starts_with($path, 's3://')) {
                $deleted = $this->deleteS3Object($path);
            } elseif ($path !== '' && file_exists($path)) {
                $deleted = @unlink($path);
            }

            $db->table('backup_runs')->where('id', $row['id'])->update([
                'status'      => $deleted ? 'EXPIRED_DELETED' : 'EXPIRED_ORPHAN',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            CLI::write('  - id=' . $row['id'] . ' path=' . $path . ' deleted=' . ($deleted ? 'yes' : 'no'),
                $deleted ? 'green' : 'red');
        }
    }

    private function deleteS3Object(string $s3Path): bool
    {
        // s3://bucket/key…
        if (! preg_match('#^s3://([^/]+)/(.+)$#', $s3Path, $m)) {
            return false;
        }
        [, $bucket, $key] = $m;
        $awsBin = (string) env('BACKUP_AWS_BIN', 'aws');
        $region = (string) env('BACKUP_S3_REGION', 'ap-southeast-1');

        $cmd = sprintf(
            '%s s3 rm s3://%s/%s --region %s',
            escapeshellcmd($awsBin),
            escapeshellarg($bucket),
            escapeshellarg($key),
            escapeshellarg($region)
        );

        $output = [];
        $code   = 0;
        exec($cmd . ' 2>&1', $output, $code);
        return $code === 0;
    }

    private function writeAudit(string $event, int $runId, array $meta = [], string $severity = 'NOTICE'): void
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }
            $db->table('audit_logs')->insert([
                'company_id'    => null,
                'user_id'       => null,
                'event'         => $event,
                'entity_type'   => 'backup_runs',
                'entity_id'     => $runId,
                'severity'      => $severity,
                'metadata_json' => json_encode($meta),
                'ip_address'    => 'cli',
                'user_agent'    => 'spark:db:backup-encrypted',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[Backup] Failed to write audit log: ' . $e->getMessage());
        }
    }
}
