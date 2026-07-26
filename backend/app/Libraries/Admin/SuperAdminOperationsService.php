<?php

namespace App\Libraries\Admin;

use App\Libraries\System\HealthChecker;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Cross-tenant operational observability for the internal Super Admin console.
 *
 * Only aggregate operational metadata is returned. Employee, payroll, email,
 * and webhook payloads are deliberately excluded from this dashboard.
 */
final class SuperAdminOperationsService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $health = (new HealthChecker())->all();
        $tenants = $this->tenantSummary();
        $queues = $this->queueSummary();
        $workflows = $this->workflowSummary();
        $payroll = $this->payrollSummary();
        $security = $this->securitySummary();
        $businessFlows = $this->businessFlowSummary();
        $alerts = $this->alerts($health, $queues, $workflows, $payroll, $security);

        return [
            'status' => $this->overallStatus($health, $alerts),
            'snapshot_at' => date('c'),
            'refresh_after_seconds' => 30,
            'health' => $health,
            'tenants' => $tenants,
            'queues' => $queues,
            'workflows' => $workflows,
            'payroll' => $payroll,
            'security' => $security,
            'business_flows' => $businessFlows,
            'alerts' => $alerts,
            'tenant_risks' => $this->tenantRisks(),
            'recent_failures' => $this->recentFailures(),
        ];
    }

    /** @return array<string,int> */
    private function tenantSummary(): array
    {
        $summary = [
            'total' => $this->count('companies', fn ($b) => $b->where('deleted_at IS NULL')),
            'active' => $this->count('companies', fn ($b) => $b->where('deleted_at IS NULL')->where('is_active', 1)),
            'employees_active' => $this->count('employees', fn ($b) => $b->where('deleted_at IS NULL')->where('is_active', 1)),
            'users_active' => $this->count('users', fn ($b) => $b->where('is_active', 1)),
            'locked_users' => $this->count('users', fn ($b) => $b->where('locked_until >', date('Y-m-d H:i:s'))),
            'trial' => 0,
            'past_due' => 0,
            'suspended' => 0,
        ];
        if ($this->db->tableExists('subscriptions')) {
            $summary['trial'] = $this->count('subscriptions', fn ($b) => $b->where('status', 'TRIAL'));
            $summary['past_due'] = $this->count('subscriptions', fn ($b) => $b->where('status', 'PAST_DUE'));
            $summary['suspended'] = $this->count('subscriptions', fn ($b) => $b->where('status', 'SUSPENDED'));
        }
        return $summary;
    }

    /** @return array<string,array<string,int>> */
    private function queueSummary(): array
    {
        return [
            'jobs' => $this->statusCounts('job_queue', ['PENDING', 'RUNNING', 'DONE', 'FAILED', 'CANCELLED']),
            'email' => $this->statusCounts('email_queue', ['QUEUED', 'SENDING', 'SENT', 'FAILED']),
            'webhooks' => $this->statusCounts('webhook_deliveries', ['QUEUED', 'SENDING', 'DELIVERED', 'FAILED']),
            'approval_outbox' => $this->statusCounts(
                'approval_outbox_events',
                ['PENDING', 'PROCESSING', 'PUBLISHED', 'FAILED']
            ),
            'business_jobs' => array_merge(
                $this->statusCounts('business_job_runs', ['RUNNING', 'SUCCESS', 'FAILED']),
                [
                    'STUCK' => $this->count(
                        'business_job_runs',
                        fn ($b) => $b->where('status', 'RUNNING')
                            ->where('started_at <', date('Y-m-d H:i:s', strtotime('-30 minutes')))
                    ),
                ],
            ),
        ];
    }

    /** @return array<string,int> */
    private function workflowSummary(): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            'pending' => $this->count('approval_requests', fn ($b) => $b->where('status', 'PENDING')),
            'stuck_24h' => $this->count(
                'approval_requests',
                fn ($b) => $b->where('status', 'PENDING')
                    ->where('created_at <', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ),
            'approved_24h' => $this->count(
                'approval_requests',
                fn ($b) => $b->where('status', 'APPROVED')
                    ->where('updated_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ),
            'rejected_24h' => $this->count(
                'approval_requests',
                fn ($b) => $b->where('status', 'REJECTED')
                    ->where('updated_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ),
            'privileged_pending' => $this->count(
                'privileged_action_requests',
                fn ($b) => $b->where('status', 'PENDING_APPROVAL')->where('deleted_at IS NULL')
            ),
            'privileged_ready' => $this->count(
                'privileged_action_requests',
                fn ($b) => $b->where('status', 'APPROVED')->where('used_at IS NULL')
                    ->where('expires_at >=', $now)->where('deleted_at IS NULL')
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function payrollSummary(): array
    {
        $statuses = $this->statusCounts(
            'payroll_periods',
            ['DRAFT', 'CALCULATED', 'REVIEW', 'SUBMITTED', 'APPROVED', 'FINALIZED', 'PAID', 'FAILED']
        );
        $openStatuses = ['CALCULATED', 'REVIEW', 'SUBMITTED', 'APPROVED'];
        return [
            'by_status' => $statuses,
            'in_process' => $this->count('payroll_periods', fn ($b) => $b->whereIn('status', $openStatuses)),
            'failed' => (int) ($statuses['FAILED'] ?? 0),
            'overdue_pay_date' => $this->count(
                'payroll_periods',
                fn ($b) => $b->where('pay_date <', date('Y-m-d'))
                    ->whereNotIn('status', ['PAID', 'FINALIZED'])
            ),
            'pending_approval' => $this->count(
                'approval_requests',
                fn ($b) => $b->where('module', 'PAYROLL')->where('status', 'PENDING')
            ),
            'calculated_30d' => $this->count(
                'business_job_runs',
                fn ($b) => $b->where('job_name', 'payroll.calculate')
                    ->where('status', 'SUCCESS')
                    ->where('started_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ),
        ];
    }

    /** @return array<string,int> */
    private function securitySummary(): array
    {
        return [
            'failed_logins_1h' => $this->count(
                'login_attempts',
                fn ($b) => $b->where('success', 0)
                    ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ),
            'failed_logins_24h' => $this->count(
                'login_attempts',
                fn ($b) => $b->where('success', 0)
                    ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ),
            'critical_audit_24h' => $this->count(
                'audit_logs',
                fn ($b) => $b->whereIn('severity', ['HIGH', 'CRITICAL'])
                    ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ),
            'rbac_pending' => $this->count(
                'privileged_action_requests',
                fn ($b) => $b->where('action_group', 'RBAC')
                    ->where('status', 'PENDING_APPROVAL')->where('deleted_at IS NULL')
            ),
            'inactive_users' => $this->count('users', fn ($b) => $b->where('is_active', 0)),
        ];
    }

    /** @return array<string,array<string,int>> */
    private function businessFlowSummary(): array
    {
        return [
            'attendance' => [
                'changes_pending' => $this->pendingAcross(
                    ['attendance_change_requests', 'attendance_correction_requests'],
                    ['PENDING', 'SUBMITTED']
                ),
                'wfh_pending' => $this->count('wfh_requests', fn ($b) => $b->where('status', 'PENDING')),
                'overtime_pending' => $this->count('overtime_requests', fn ($b) => $b->where('status', 'PENDING')),
            ],
            'leave' => [
                'pending' => $this->count('leave_requests', fn ($b) => $b->where('status', 'PENDING')),
            ],
            'recruitment' => [
                'open_jobs' => $this->count('job_postings', fn ($b) => $b->where('status', 'OPEN')),
                'active_candidates' => $this->count(
                    'candidates',
                    fn ($b) => $b->where('deleted_at IS NULL')->whereNotIn('status', ['HIRED', 'REJECTED', 'WITHDRAWN'])
                ),
                'offers_pending' => $this->count(
                    'candidate_offers',
                    fn ($b) => $b->whereIn('status', ['DRAFT', 'PENDING_APPROVAL', 'SENT'])
                ),
            ],
            'employee_lifecycle' => [
                'onboarding_active' => $this->count(
                    'onboarding_processes',
                    fn ($b) => $b->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
                ),
                'resignations_pending' => $this->count(
                    'resignation_requests',
                    fn ($b) => $b->whereIn('status', ['PENDING', 'SUBMITTED', 'IN_REVIEW'])
                ),
                'probation_due_30d' => $this->count(
                    'employees',
                    fn ($b) => $b->where('deleted_at IS NULL')
                        ->where('probation_end_date >=', date('Y-m-d'))
                        ->where('probation_end_date <=', date('Y-m-d', strtotime('+30 days')))
                ),
            ],
            'service' => [
                'tickets_open' => $this->count(
                    'hr_tickets',
                    fn ($b) => $b->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                ),
                'tickets_overdue' => $this->count(
                    'hr_tickets',
                    fn ($b) => $b->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        ->where('due_at <', date('Y-m-d H:i:s'))
                ),
            ],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $health
     * @param array<string,array<string,int>> $queues
     * @param array<string,int> $workflows
     * @param array<string,mixed> $payroll
     * @param array<string,int> $security
     * @return array<int,array<string,mixed>>
     */
    private function alerts(
        array $health,
        array $queues,
        array $workflows,
        array $payroll,
        array $security
    ): array {
        $alerts = [];
        foreach ($health as $key => $check) {
            if (! ($check['ok'] ?? false)) {
                $alerts[] = $this->alert(
                    ($check['critical'] ?? false) ? 'CRITICAL' : 'WARNING',
                    'Dependency ' . $key . ' bermasalah',
                    (string) ($check['error'] ?? 'Periksa detail health check'),
                    '/admin/operations'
                );
            }
        }
        $this->pushCountAlert($alerts, $queues['jobs']['FAILED'] ?? 0, 'CRITICAL', 'Background job gagal', '/admin/operations');
        $this->pushCountAlert($alerts, $queues['business_jobs']['STUCK'] ?? 0, 'CRITICAL', 'Business job macet >30 menit', '/admin/operations');
        $this->pushCountAlert($alerts, $queues['email']['FAILED'] ?? 0, 'WARNING', 'Email gagal dikirim', '/admin/email-settings');
        $this->pushCountAlert($alerts, $queues['webhooks']['FAILED'] ?? 0, 'WARNING', 'Webhook gagal dikirim', '/admin/operations');
        $this->pushCountAlert($alerts, $workflows['stuck_24h'] ?? 0, 'WARNING', 'Approval tertahan >24 jam', '/approvals/inbox');
        $this->pushCountAlert($alerts, $payroll['failed'] ?? 0, 'CRITICAL', 'Payroll berstatus gagal', '/payroll/periods');
        $this->pushCountAlert($alerts, $payroll['overdue_pay_date'] ?? 0, 'WARNING', 'Payroll melewati pay date', '/payroll/periods');
        if (($security['failed_logins_1h'] ?? 0) >= 10) {
            $this->pushCountAlert($alerts, $security['failed_logins_1h'], 'WARNING', 'Lonjakan login gagal dalam 1 jam', '/admin/audit-logs');
        }
        return array_slice($alerts, 0, 20);
    }

    /** @return array<int,array<string,mixed>> */
    private function tenantRisks(): array
    {
        $scores = [];
        $this->addGroupedRisk($scores, 'approval_requests', 2, fn ($b) => $b->where('status', 'PENDING')
            ->where('created_at <', date('Y-m-d H:i:s', strtotime('-24 hours'))));
        $this->addGroupedRisk($scores, 'job_queue', 4, fn ($b) => $b->where('status', 'FAILED'));
        $this->addGroupedRisk($scores, 'email_queue', 2, fn ($b) => $b->where('status', 'FAILED'));
        $this->addGroupedRisk($scores, 'webhook_deliveries', 3, fn ($b) => $b->where('status', 'FAILED'));
        $this->addGroupedRisk($scores, 'business_job_runs', 4, fn ($b) => $b->where('status', 'FAILED')
            ->where('started_at >=', date('Y-m-d H:i:s', strtotime('-24 hours'))));
        if ($scores === []) return [];

        arsort($scores);
        $companyIds = array_slice(array_keys($scores), 0, 8);
        $companies = $this->db->table('companies')->select('id, code, name')
            ->whereIn('id', $companyIds)->get()->getResultArray();
        $byId = [];
        foreach ($companies as $company) $byId[(int) $company['id']] = $company;

        $result = [];
        foreach ($companyIds as $companyId) {
            $company = $byId[$companyId] ?? null;
            if (! $company) continue;
            $result[] = [
                'company_id' => $companyId,
                'company_code' => $company['code'],
                'company_name' => $company['name'],
                'risk_score' => $scores[$companyId],
            ];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function recentFailures(): array
    {
        $items = [];
        if ($this->db->tableExists('business_job_runs')) {
            foreach ($this->db->table('business_job_runs b')
                ->select('b.id, b.company_id, b.job_name, b.error_code, b.started_at, c.name AS company_name')
                ->join('companies c', 'c.id = b.company_id', 'left')
                ->where('b.status', 'FAILED')->orderBy('b.started_at', 'DESC')->limit(8)
                ->get()->getResultArray() as $row
            ) {
                $items[] = [
                    'source' => 'BUSINESS_JOB',
                    'reference' => $row['job_name'] . ' #' . $row['id'],
                    'company_id' => $row['company_id'],
                    'company_name' => $row['company_name'],
                    'error' => $row['error_code'] ?: 'JOB_FAILED',
                    'occurred_at' => $row['started_at'],
                ];
            }
        }
        if ($this->db->tableExists('webhook_deliveries')) {
            foreach ($this->db->table('webhook_deliveries w')
                ->select('w.id, w.company_id, w.event_type, w.last_error, w.created_at, c.name AS company_name')
                ->join('companies c', 'c.id = w.company_id', 'left')
                ->where('w.status', 'FAILED')->orderBy('w.created_at', 'DESC')->limit(8)
                ->get()->getResultArray() as $row
            ) {
                $items[] = [
                    'source' => 'WEBHOOK',
                    'reference' => $row['event_type'] . ' #' . $row['id'],
                    'company_id' => $row['company_id'],
                    'company_name' => $row['company_name'],
                    'error' => mb_substr((string) ($row['last_error'] ?: 'DELIVERY_FAILED'), 0, 180),
                    'occurred_at' => $row['created_at'],
                ];
            }
        }
        usort($items, fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
        return array_slice($items, 0, 12);
    }

    /** @param array<string,array<string,mixed>> $health @param array<int,array<string,mixed>> $alerts */
    private function overallStatus(array $health, array $alerts): string
    {
        foreach ($health as $check) {
            if (($check['critical'] ?? false) && ! ($check['ok'] ?? false)) return 'CRITICAL';
        }
        foreach ($alerts as $alert) {
            if (($alert['severity'] ?? '') === 'CRITICAL') return 'CRITICAL';
        }
        return $alerts === [] ? 'HEALTHY' : 'DEGRADED';
    }

    /** @return array<string,mixed> */
    private function alert(string $severity, string $title, string $detail, string $link): array
    {
        return compact('severity', 'title', 'detail', 'link');
    }

    /** @param array<int,array<string,mixed>> $alerts */
    private function pushCountAlert(
        array &$alerts,
        int $count,
        string $severity,
        string $title,
        string $link
    ): void {
        if ($count <= 0) return;
        $alerts[] = $this->alert($severity, $title, "{$count} item membutuhkan perhatian", $link);
    }

    /** @return array<string,int> */
    private function statusCounts(string $table, array $statuses): array
    {
        $result = array_fill_keys($statuses, 0);
        if (! $this->db->tableExists($table)) return $result;
        $rows = $this->db->table($table)->select('status, COUNT(*) AS total')
            ->whereIn('status', $statuses)->groupBy('status')->get()->getResultArray();
        foreach ($rows as $row) $result[(string) $row['status']] = (int) $row['total'];
        return $result;
    }

    private function count(string $table, callable $scope): int
    {
        if (! $this->db->tableExists($table)) return 0;
        try {
            $builder = $this->db->table($table);
            $scope($builder);
            return (int) $builder->countAllResults();
        } catch (\Throwable) {
            // Optional modules can be at an older migration level. A missing
            // column must not take the whole command center down.
            return 0;
        }
    }

    private function pendingAcross(array $tables, array $statuses): int
    {
        $total = 0;
        foreach ($tables as $table) {
            $total += $this->count($table, fn ($b) => $b->whereIn('status', $statuses));
        }
        return $total;
    }

    /** @param array<int,int> $scores */
    private function addGroupedRisk(array &$scores, string $table, int $weight, callable $scope): void
    {
        if (! $this->db->tableExists($table) || ! $this->db->fieldExists('company_id', $table)) return;
        try {
            $builder = $this->db->table($table)->select('company_id, COUNT(*) AS total')
                ->where('company_id >', 0);
            $scope($builder);
            foreach ($builder->groupBy('company_id')->get()->getResultArray() as $row) {
                $companyId = (int) $row['company_id'];
                $scores[$companyId] = ($scores[$companyId] ?? 0) + ((int) $row['total'] * $weight);
            }
        } catch (\Throwable) {
            // Keep the rest of the dashboard available when an optional table
            // has not reached the expected migration yet.
        }
    }
}
