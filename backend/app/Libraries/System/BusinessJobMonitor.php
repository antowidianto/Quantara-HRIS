<?php

namespace App\Libraries\System;

use CodeIgniter\Database\BaseConnection;

final class BusinessJobMonitor
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function start(string $jobName, ?int $companyId = null, ?string $jobId = null, array $context = []): int
    {
        if (! $this->db->tableExists('business_job_runs')) return 0;
        $now = date('Y-m-d H:i:s');
        $this->db->table('business_job_runs')->insert([
            'company_id' => $companyId, 'job_name' => $jobName, 'job_id' => $jobId,
            'request_id' => $GLOBALS['__request_id'] ?? null, 'status' => 'RUNNING',
            'context_json' => $this->safeContext($context), 'started_at' => $now, 'created_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    public function succeed(int $runId, int $processed = 0, array $context = []): void
    {
        $this->finish($runId, 'SUCCESS', $processed, 0, null, $context);
    }

    public function fail(int $runId, string $errorCode, int $failed = 1, array $context = []): void
    {
        $this->finish($runId, 'FAILED', 0, $failed, $errorCode, $context);
    }

    private function finish(int $runId, string $status, int $processed, int $failed, ?string $error, array $context): void
    {
        if ($runId <= 0 || ! $this->db->tableExists('business_job_runs')) return;
        $row = $this->db->table('business_job_runs')->select('started_at')->where('id', $runId)->get()->getRowArray();
        if (! $row) return;
        $duration = max(0, (int) round((microtime(true) - (float) strtotime((string) $row['started_at'])) * 1000));
        $this->db->table('business_job_runs')->where('id', $runId)->update([
            'status' => $status, 'processed_count' => $processed, 'failed_count' => $failed,
            'duration_ms' => $duration, 'error_code' => $error,
            'context_json' => $this->safeContext($context), 'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function safeContext(array $context): string
    {
        foreach (['password', 'token', 'secret', 'bank_account', 'nik', 'npwp'] as $key) unset($context[$key]);
        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
