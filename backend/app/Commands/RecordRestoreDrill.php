<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class RecordRestoreDrill extends BaseCommand
{
    protected $group = 'Backup';
    protected $name = 'db:restore-drill-record';
    protected $description = 'Record independently verified evidence from an isolated restore drill';
    protected $usage = 'db:restore-drill-record <backup-run-id> <PASSED|FAILED> <environment> <rto-minutes> <evidence-reference> [--counts] [--hashes] [--attachments] [--login]';

    public function run(array $params)
    {
        $flags = array_fill_keys(array_values(array_filter($params, static fn (string $value): bool => str_starts_with($value, '--'))), true);
        $values = array_values(array_filter($params, static fn (string $value): bool => ! str_starts_with($value, '--')));
        if (count($values) < 5 || ! ctype_digit($values[0]) || ! is_numeric($values[3])) {
            CLI::error('Invalid arguments. Usage: ' . $this->usage);
            return EXIT_ERROR;
        }

        [$backupRunId, $status, $environment, $rtoMinutes, $evidence] = $values;
        $status = strtoupper($status);
        if (! in_array($status, ['PASSED', 'FAILED'], true) || trim($environment) === '' || (float) $rtoMinutes < 0 || trim($evidence) === '') {
            CLI::error('Status/environment/RTO/evidence is invalid.');
            return EXIT_ERROR;
        }

        $checks = [
            'record_counts_verified' => isset($flags['--counts']),
            'hashes_verified' => isset($flags['--hashes']),
            'attachments_verified' => isset($flags['--attachments']),
            'login_verified' => isset($flags['--login']),
        ];
        if ($status === 'PASSED' && in_array(false, $checks, true)) {
            CLI::error('PASSED requires --counts, --hashes, --attachments, and --login evidence.');
            return EXIT_ERROR;
        }

        $db = \Config\Database::connect();
        if (! $db->tableExists('restore_drills')) {
            CLI::error('restore_drills table is missing; apply migrations first.');
            return EXIT_ERROR;
        }
        $backup = $db->table('backup_runs')->where('id', (int) $backupRunId)
            ->whereIn('status', ['SUCCESS', 'VERIFIED'])->get()->getRowArray();
        if (! $backup || empty($backup['finished_at'])) {
            CLI::error('The source backup must exist and be SUCCESS or VERIFIED.');
            return EXIT_ERROR;
        }

        $finishedAt = date('Y-m-d H:i:s');
        $rtoSeconds = (int) round((float) $rtoMinutes * 60);
        $startedAt = date('Y-m-d H:i:s', time() - $rtoSeconds);
        $db->table('restore_drills')->insert(array_merge($checks, [
            'backup_run_id' => (int) $backupRunId,
            'status' => $status,
            'environment' => trim($environment),
            'rpo_seconds' => max(0, time() - strtotime((string) $backup['finished_at'])),
            'rto_seconds' => $rtoSeconds,
            'evidence_reference' => trim($evidence),
            'notes' => $values[5] ?? null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt,
        ]));

        CLI::write(sprintf('Restore drill #%d recorded as %s.', (int) $db->insertID(), $status), $status === 'PASSED' ? 'green' : 'yellow');
        return EXIT_SUCCESS;
    }
}
