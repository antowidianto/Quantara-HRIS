<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Database;

/**
 * S2.1 — Prometheus Metrics Endpoint (Roadmap SaaS)
 *
 * GET /api/v1/metrics
 *
 * Format Prometheus exposition (text/plain). Tidak pakai
 * promphp/prometheus_client_php library karena overhead instalasi
 * untuk metric set sederhana ini tidak sebanding. Implementasi manual
 * dengan format yang sesuai spec Prometheus.
 *
 * Auth: hanya super admin (atau internal scrape via IP allowlist).
 * Untuk production, pasang reverse proxy nginx yang only-allow internal IP.
 *
 * Metrics yang di-emit:
 *   - hris_app_info{version, env}            gauge — informasi versi app
 *   - hris_companies_total                   gauge — total tenant aktif
 *   - hris_employees_total{company_id}       gauge — karyawan aktif per tenant
 *   - hris_subscription_active_count{plan}   gauge — subscription per plan
 *   - hris_payslips_calculated_total         counter — total payslip ter-calculate
 *   - hris_jobs_pending                      gauge — queue pending depth
 *   - hris_jobs_failed                       gauge — queue failed count
 *   - hris_audit_log_critical_24h            gauge — security events 24 jam terakhir
 */
class MetricsController extends ApiController
{
    public function index()
    {
        // Allowlist: super admin OR internal IP (configurable via env)
        $allowedIps = array_filter(array_map('trim', explode(',', env('METRICS_ALLOWED_IPS', '127.0.0.1'))));
        $clientIp = $this->request->getIPAddress();
        $isLocalAllowed = in_array($clientIp, $allowedIps, true);

        if (! $isLocalAllowed && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }

        $lines = [];

        // ─── App info ────────────────────────────────────────────────
        $version = env('APP_VERSION', '1.0.0');
        $env = env('CI_ENVIRONMENT', 'production');
        $lines[] = '# HELP hris_app_info Information about the running HRIS application';
        $lines[] = '# TYPE hris_app_info gauge';
        $lines[] = sprintf('hris_app_info{version="%s",env="%s"} 1', $version, $env);

        $db = Database::connect();

        // ─── Companies (tenants) ─────────────────────────────────────
        $totalCompanies = (int) $db->table('companies')->where('deleted_at IS NULL')->countAllResults();
        $lines[] = '# HELP hris_companies_total Total active tenants';
        $lines[] = '# TYPE hris_companies_total gauge';
        $lines[] = "hris_companies_total {$totalCompanies}";

        // ─── Employees per company (top 50 to avoid cardinality blowup) ──
        $empRows = $db->table('employees e')
            ->select('e.company_id, COUNT(*) AS cnt')
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->groupBy('e.company_id')
            ->orderBy('cnt', 'DESC')
            ->limit(50)
            ->get()->getResultArray();
        if (! empty($empRows)) {
            $lines[] = '# HELP hris_employees_total Active employees per tenant';
            $lines[] = '# TYPE hris_employees_total gauge';
            foreach ($empRows as $r) {
                $lines[] = sprintf('hris_employees_total{company_id="%d"} %d',
                    (int) $r['company_id'], (int) $r['cnt']);
            }
        }

        // ─── Subscriptions per plan (S1.2) ───────────────────────────
        if ($db->tableExists('subscriptions')) {
            $subRows = $db->table('subscriptions s')
                ->select('p.code AS plan_code, s.status, COUNT(*) AS cnt')
                ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
                ->groupBy(['p.code', 's.status'])
                ->get()->getResultArray();
            if (! empty($subRows)) {
                $lines[] = '# HELP hris_subscription_count Subscription count per plan and status';
                $lines[] = '# TYPE hris_subscription_count gauge';
                foreach ($subRows as $r) {
                    $lines[] = sprintf(
                        'hris_subscription_count{plan="%s",status="%s"} %d',
                        $r['plan_code'] ?: 'unknown',
                        $r['status'] ?: 'unknown',
                        (int) $r['cnt'],
                    );
                }
            }
        }

        // ─── Payslips calculated (counter, not reset) ────────────────
        $payslipsTotal = (int) $db->table('payslips')->countAllResults();
        $lines[] = '# HELP hris_payslips_calculated_total Total payslips ever calculated';
        $lines[] = '# TYPE hris_payslips_calculated_total counter';
        $lines[] = "hris_payslips_calculated_total {$payslipsTotal}";

        // ─── Queue depth ─────────────────────────────────────────────
        if ($db->tableExists('jobs')) {
            $pending = (int) $db->table('jobs')->where('status', 'PENDING')->countAllResults();
            $failed = (int) $db->table('jobs')->where('status', 'FAILED')->countAllResults();
            $running = (int) $db->table('jobs')->where('status', 'RUNNING')->countAllResults();

            $lines[] = '# HELP hris_jobs_count Job queue depth by status';
            $lines[] = '# TYPE hris_jobs_count gauge';
            $lines[] = sprintf('hris_jobs_count{status="pending"} %d', $pending);
            $lines[] = sprintf('hris_jobs_count{status="running"} %d', $running);
            $lines[] = sprintf('hris_jobs_count{status="failed"} %d', $failed);
        }

        // Business outcomes: duration/failure and last-success evidence.
        if ($db->tableExists('business_job_runs')) {
            $outcomes = $db->table('business_job_runs')
                ->select('job_name, status, COUNT(*) AS cnt, AVG(duration_ms) AS avg_duration_ms, MAX(finished_at) AS last_finished_at, SUM(processed_count) AS processed')
                ->where('started_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->groupBy(['job_name', 'status'])->get()->getResultArray();
            $lines[] = '# HELP hris_business_job_runs_24h Business job outcomes in the last 24 hours';
            $lines[] = '# TYPE hris_business_job_runs_24h gauge';
            $lines[] = '# HELP hris_business_job_duration_ms Average business job duration in milliseconds';
            $lines[] = '# TYPE hris_business_job_duration_ms gauge';
            foreach ($outcomes as $row) {
                $job = addslashes((string) $row['job_name']);
                $status = strtolower((string) $row['status']);
                $lines[] = sprintf('hris_business_job_runs_24h{job="%s",status="%s"} %d', $job, $status, (int) $row['cnt']);
                $lines[] = sprintf('hris_business_job_duration_ms{job="%s",status="%s"} %.0f', $job, $status, (float) $row['avg_duration_ms']);
            }
        }

        if ($db->tableExists('approval_requests')) {
            $stuck = (int) $db->table('approval_requests')->where('company_id >', 0)
                ->where('status', 'PENDING')->where('updated_at <', date('Y-m-d H:i:s', strtotime('-48 hours')))
                ->countAllResults();
            $lines[] = '# HELP hris_approvals_stuck_sla Pending approvals older than 48 hours';
            $lines[] = '# TYPE hris_approvals_stuck_sla gauge';
            $lines[] = "hris_approvals_stuck_sla {$stuck}";
        }

        if ($db->tableExists('email_queue')) {
            $emailFailed = (int) $db->table('email_queue')->where('company_id >', 0)->where('status', 'FAILED')->countAllResults();
            $lines[] = '# HELP hris_email_delivery_failed Failed email deliveries';
            $lines[] = '# TYPE hris_email_delivery_failed gauge';
            $lines[] = "hris_email_delivery_failed {$emailFailed}";
        }
        if ($db->tableExists('webhook_deliveries')) {
            $webhookFailed = (int) $db->table('webhook_deliveries')->where('company_id >', 0)->where('status', 'FAILED')->countAllResults();
            $lines[] = '# HELP hris_webhook_dead_letter_count Exhausted webhook deliveries';
            $lines[] = '# TYPE hris_webhook_dead_letter_count gauge';
            $lines[] = "hris_webhook_dead_letter_count {$webhookFailed}";
        }
        if ($db->tableExists('backup_runs')) {
            $backup = $db->table('backup_runs')->select('finished_at')->where('status', 'SUCCESS')
                ->orderBy('finished_at', 'DESC')->get()->getRowArray();
            $age = $backup ? max(0, time() - strtotime((string) $backup['finished_at'])) : -1;
            $lines[] = '# HELP hris_backup_age_seconds Age of last successful encrypted backup; -1 means never';
            $lines[] = '# TYPE hris_backup_age_seconds gauge';
            $lines[] = "hris_backup_age_seconds {$age}";
        }
        if ($db->tableExists('restore_drills')) {
            $drill = $db->table('restore_drills')->select('finished_at')->where('status', 'PASSED')
                ->orderBy('finished_at', 'DESC')->get()->getRowArray();
            $age = $drill ? max(0, time() - strtotime((string) $drill['finished_at'])) : -1;
            $lines[] = '# HELP hris_restore_drill_age_seconds Age of last successful isolated restore drill; -1 means never';
            $lines[] = '# TYPE hris_restore_drill_age_seconds gauge';
            $lines[] = "hris_restore_drill_age_seconds {$age}";
        }

        // ─── Audit log critical events (24h window) ─────────────────
        if ($db->tableExists('audit_logs')) {
            $criticalCount = (int) $db->table('audit_logs')
                ->whereIn('severity', ['CRITICAL', 'HIGH'])
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->countAllResults();
            $lines[] = '# HELP hris_audit_log_critical_24h Count of CRITICAL/HIGH audit log events in last 24 hours';
            $lines[] = '# TYPE hris_audit_log_critical_24h gauge';
            $lines[] = "hris_audit_log_critical_24h {$criticalCount}";
        }

        // ─── PHP runtime info ────────────────────────────────────────
        $lines[] = '# HELP hris_php_memory_usage_bytes Current PHP memory usage';
        $lines[] = '# TYPE hris_php_memory_usage_bytes gauge';
        $lines[] = 'hris_php_memory_usage_bytes ' . memory_get_usage(true);
        $lines[] = '# HELP hris_php_memory_peak_bytes Peak PHP memory usage';
        $lines[] = '# TYPE hris_php_memory_peak_bytes gauge';
        $lines[] = 'hris_php_memory_peak_bytes ' . memory_get_peak_usage(true);

        $body = implode("\n", $lines) . "\n";

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->setBody($body);
    }
}
