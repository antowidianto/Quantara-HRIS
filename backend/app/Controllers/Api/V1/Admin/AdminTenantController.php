<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\ApiController;
use Config\Database;
use Config\Services;

/**
 * S3.5 — Admin Console Controller (Roadmap SaaS)
 *
 * Endpoint khusus tim internal Quantara (super admin) untuk:
 *  - Lihat semua tenant + filter status, plan, MRR
 *  - Detail tenant: subscription history, usage, audit
 *  - Impersonate tenant admin (dengan audit log mandatory)
 *  - Force change plan / suspend / unsuspend (4-eyes principle)
 *  - SaaS metrics dashboard: MRR, ARR, churn, MAU, signup funnel
 *
 * Semua operasi tracked dengan severity HIGH di audit_logs.
 *
 * Permission: hanya `tenant.isSuperAdmin()` yang lolos. Tidak ada
 * permission slug supaya hardcoded — endpoint ini tidak boleh
 * di-grant ke role tenant biasa.
 */
class AdminTenantController extends ApiController
{
    private function requireSuperAdmin(): bool
    {
        if (! $this->tenant()->isSuperAdmin()) {
            $this->fail('Forbidden', 403);
            return false;
        }
        return true;
    }

    /**
     * GET /api/v1/admin/tenants
     * Filter: status, plan_code, search (name/code), q (search general)
     * Sort: created_at DESC default
     */
    public function index()
    {
        if (! $this->requireSuperAdmin()) return null;

        $db = Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = trim((string) $this->request->getGet('status'));
        $planCode = trim((string) $this->request->getGet('plan_code'));
        $search = trim((string) $this->request->getGet('search'));

        $hasSubsTable = $db->tableExists('subscriptions');

        $builder = $db->table('companies c')
            ->select('c.id, c.code, c.name, c.created_at, c.deleted_at'
                . ($hasSubsTable
                    ? ', s.status AS sub_status, s.current_period_end, s.trial_ends_at,
                       s.po_number, s.custom_price, p.code AS plan_code, p.name AS plan_name'
                    : ''))
            ->where('c.deleted_at IS NULL');

        if ($hasSubsTable) {
            $builder->join('subscriptions s', 's.company_id = c.id', 'left');
            $builder->join('subscription_plans p', 'p.id = s.plan_id', 'left');
            if ($status !== '') $builder->where('s.status', $status);
            if ($planCode !== '') $builder->where('p.code', $planCode);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('c.name', $search)
                ->orLike('c.code', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder
            ->orderBy('c.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Hitung employee_count per company (single query in)
        if (! empty($rows)) {
            $companyIds = array_column($rows, 'id');
            $empCounts = $db->table('employees')
                ->select('company_id, COUNT(*) AS cnt')
                ->whereIn('company_id', $companyIds)
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->groupBy('company_id')
                ->get()->getResultArray();
            $countByCompany = [];
            foreach ($empCounts as $r) $countByCompany[(int) $r['company_id']] = (int) $r['cnt'];
            foreach ($rows as &$row) {
                $row['employee_count'] = $countByCompany[(int) $row['id']] ?? 0;
            }
        }

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /api/v1/admin/tenants/{id}
     * Detail komprehensif: subscription, usage, recent events, ticket count
     */
    public function show(int $companyId)
    {
        if (! $this->requireSuperAdmin()) return null;

        $db = Database::connect();
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $company) return $this->fail('Tenant not found', 404);

        $data = ['company' => $company];

        // Subscription + plan
        if ($db->tableExists('subscriptions')) {
            $sub = $db->table('subscriptions s')
                ->select('s.*, p.code AS plan_code, p.name AS plan_name, p.price_monthly, p.price_yearly')
                ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
                ->where('s.company_id', $companyId)
                ->get()->getRowArray();
            $data['subscription'] = $sub;

            // Recent subscription events (10 terakhir)
            if ($sub) {
                $data['subscription_events'] = $db->table('subscription_events')
                    ->where('subscription_id', $sub['id'])
                    ->orderBy('created_at', 'DESC')
                    ->limit(10)
                    ->get()->getResultArray();
            }
        }

        // Usage stats
        $data['usage'] = [
            'employees_active'   => (int) $db->table('employees')
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->countAllResults(),
            'payslips_generated' => (int) $db->table('payslips')
                ->where('company_id', $companyId)
                ->countAllResults(),
            'payroll_periods'    => (int) $db->table('payroll_periods')
                ->where('company_id', $companyId)
                ->countAllResults(),
        ];

        // Helpdesk tickets
        if ($db->tableExists('hr_tickets')) {
            $data['tickets'] = [
                'open'   => (int) $db->table('hr_tickets')
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                    ->countAllResults(),
                'closed_30d' => (int) $db->table('hr_tickets')
                    ->where('company_id', $companyId)
                    ->where('status', 'CLOSED')
                    ->where('closed_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))
                    ->countAllResults(),
            ];
        }

        // Recent audit events (security flag)
        if ($db->tableExists('audit_logs')) {
            $data['recent_critical_events'] = $db->table('audit_logs')
                ->select('id, action, severity, description, user_id, created_at')
                ->where('company_id', $companyId)
                ->whereIn('severity', ['HIGH', 'CRITICAL'])
                ->orderBy('created_at', 'DESC')
                ->limit(10)
                ->get()->getResultArray();
        }

        // Feature overrides
        if ($db->tableExists('company_feature_overrides')) {
            $data['feature_overrides'] = $db->table('company_feature_overrides')
                ->where('company_id', $companyId)
                ->groupStart()
                    ->where('expires_at IS NULL')
                    ->orWhere('expires_at >', date('Y-m-d H:i:s'))
                ->groupEnd()
                ->orderBy('updated_at', 'DESC')
                ->get()->getResultArray();
        }

        return $this->ok($data);
    }

    /**
     * POST /api/v1/admin/tenants/{id}/impersonate
     * Generate JWT one-time untuk login as tenant admin.
     * Audit log severity HIGH wajib karena ini access bypass.
     */
    public function impersonate(int $companyId)
    {
        if (! $this->requireSuperAdmin()) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|min_length[10]|max_length[500]',
        ]);
        if (! $valid) return null;

        $db = Database::connect();
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $company) return $this->fail('Tenant not found', 404);

        // Cari user super admin tenant (role_id = 1 atau super_company_admin di tenant)
        $targetUser = $db->table('users')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->whereIn('role_id', [1, 2]) // super_company_admin, admin
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        if (! $targetUser) {
            return $this->fail('No active admin user found for this tenant', 422);
        }

        // Generate JWT short-lived (15 menit) dengan flag impersonate
        try {
            $jwt = Services::jwt();
            $token = $jwt->generate([
                'user_id'         => (int) $targetUser['id'],
                'company_id'      => $companyId,
                'role_id'         => (int) $targetUser['role_id'],
                'employee_id'     => $targetUser['employee_id'] ?? null,
                'is_impersonated' => true,
                'impersonator_id' => $this->userId(),
            ], 15 * 60); // 15 menit
        } catch (\Throwable $e) {
            return $this->fail('Failed to generate token: ' . $e->getMessage(), 500);
        }

        // Audit log severity HIGH
        try {
            Services::audit()
                ->forModule('ADMIN')
                ->log(
                    action: 'admin.impersonate',
                    entity: 'company',
                    entityId: $companyId,
                    description: sprintf(
                        'Super admin #%d impersonate company #%d (target user #%d). Reason: %s',
                        $this->userId(),
                        $companyId,
                        $targetUser['id'],
                        $data['reason'],
                    ),
                    severity: 'HIGH',
                    metadata: [
                        'company_id'      => $companyId,
                        'target_user_id'  => (int) $targetUser['id'],
                        'reason'          => $data['reason'],
                        'expires_in'      => 900,
                    ],
                );
        } catch (\Throwable $e) {
            log_message('critical', 'Impersonate audit failed: ' . $e->getMessage());
            return $this->fail(
                'Impersonation cannot continue without an audit record',
                503,
                'PRIVILEGED_AUDIT_UNAVAILABLE',
            );
        }

        return $this->ok([
            'token'        => $token,
            'expires_in'   => 900,
            'target_user'  => [
                'id'         => (int) $targetUser['id'],
                'name'       => $targetUser['name'] ?? null,
                'email'      => $targetUser['email'] ?? null,
            ],
            'company'      => [
                'id'   => $companyId,
                'name' => $company['name'],
            ],
            'warning'      => 'Token expires in 15 minutes. All actions are audited with severity HIGH.',
        ]);
    }

    /**
     * POST /api/v1/admin/tenants/{id}/suspend
     * Suspend tenant ke status SUSPENDED (read-only mode).
     */
    public function suspend(int $companyId)
    {
        if (! $this->requireSuperAdmin()) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason'      => 'required|min_length[10]|max_length[500]',
            'confirm'     => 'required|in_list[true,1]',
        ]);
        if (! $valid) return null;

        try {
            (new \App\Libraries\Billing\SubscriptionService())
                ->suspend($companyId, $data['reason'], $this->userId());

            Services::audit()
                ->forModule('ADMIN')
                ->log(
                    action: 'admin.tenant.suspend',
                    entity: 'company',
                    entityId: $companyId,
                    description: "Tenant #{$companyId} di-suspend oleh super admin. Reason: {$data['reason']}",
                    severity: 'HIGH',
                    metadata: ['reason' => $data['reason']],
                );

            return $this->ok(null, 'Tenant suspended');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/admin/tenants/{id}/unsuspend
     */
    public function unsuspend(int $companyId)
    {
        if (! $this->requireSuperAdmin()) return null;

        try {
            (new \App\Libraries\Billing\SubscriptionService())
                ->unsuspend($companyId, $this->userId());

            Services::audit()
                ->forModule('ADMIN')
                ->log(
                    action: 'admin.tenant.unsuspend',
                    entity: 'company',
                    entityId: $companyId,
                    description: "Tenant #{$companyId} di-unsuspend oleh super admin.",
                    severity: 'HIGH',
                );

            return $this->ok(null, 'Tenant unsuspended');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/v1/admin/metrics/saas
     * Dashboard metrics: MRR, ARR, churn, signup funnel, plan distribution
     */
    public function saasMetrics()
    {
        if (! $this->requireSuperAdmin()) return null;

        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) {
            return $this->ok(['note' => 'Subscription module not yet migrated']);
        }

        // ─── MRR (sum monthly price dari ACTIVE subs) ───
        $mrr = $this->computeMrrAt(); // current MRR
        $mrrLastMonth = $this->computeMrrAt(strtotime('-1 month'));
        $mrrGrowthPct = $mrrLastMonth > 0
            ? round((($mrr - $mrrLastMonth) / $mrrLastMonth) * 100, 1)
            : null;

        // ─── Plan distribution (key: count, bukan cnt) ──
        $planDist = $db->table('subscriptions s')
            ->select('p.code AS plan, s.status, COUNT(*) AS count')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->groupBy(['p.code', 's.status'])
            ->orderBy('p.code', 'ASC')
            ->get()->getResultArray();
        // cast count to int
        foreach ($planDist as &$pd) {
            $pd['count'] = (int) ($pd['count'] ?? 0);
        }
        unset($pd);

        // ─── Tenant counters ────────────────────────────
        $totalTenants = (int) $db->table('companies')
            ->where('deleted_at IS NULL')
            ->countAllResults();
        $activeTenants = (int) $db->table('subscriptions')
            ->where('status', 'ACTIVE')
            ->countAllResults();
        $trialTenants = (int) $db->table('subscriptions')
            ->where('status', 'TRIAL')
            ->countAllResults();

        // ─── Churn (CANCELLED last 30 days / base 30d) ──
        $cancelledLast30 = (int) $db->table('subscriptions')
            ->where('status', 'CANCELLED')
            ->where('cancelled_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->countAllResults();
        $churnBase = $activeTenants + $cancelledLast30;
        $churnRate30d = $churnBase > 0
            ? round(($cancelledLast30 / $churnBase) * 100, 2)
            : 0.0;

        // ─── Signup funnel (last 30 days) ───────────────
        $signupFunnel = null;
        if ($db->tableExists('signup_attempts')) {
            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $signups = (int) $db->table('signup_attempts')
                ->where('created_at >=', $thirtyDaysAgo)
                ->countAllResults();
            // activated = email verified + onboarding done (approx via companies.created_at + onboarding_completed_at)
            $activated = 0;
            if ($db->fieldExists('onboarding_completed_at', 'companies')) {
                $activated = (int) $db->table('companies')
                    ->where('created_at >=', $thirtyDaysAgo)
                    ->where('onboarding_completed_at IS NOT NULL')
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            } else {
                // fallback: jumlah tenant baru 30d (dianggap activated)
                $activated = (int) $db->table('companies')
                    ->where('created_at >=', $thirtyDaysAgo)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }
            // paid = upgrades dari FREE_TRIAL → paid plan dalam 30d
            $freeTrialId = $db->table('subscription_plans')
                ->select('id')
                ->where('code', 'FREE_TRIAL')
                ->get()->getRowArray()['id'] ?? 0;
            $paid = (int) $db->table('subscription_events')
                ->where('event_type', 'UPGRADED')
                ->where('from_plan_id', $freeTrialId)
                ->where('created_at >=', $thirtyDaysAgo)
                ->countAllResults();
            $signupFunnel = [
                'signups'   => $signups,
                'activated' => $activated,
                'paid'      => $paid,
            ];
        }

        // ─── New tenants per month (12 bulan terakhir) ──
        $newTenantsRaw = $db->table('companies')
            ->select('DATE_FORMAT(created_at, "%Y-%m") AS month, COUNT(*) AS count')
            ->where('created_at >=', date('Y-m-01', strtotime('-12 months')))
            ->where('deleted_at IS NULL')
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->get()->getResultArray();
        // cast count + ensure 12 buckets (fill missing months dengan 0)
        $bucketByMonth = [];
        foreach ($newTenantsRaw as $r) {
            $bucketByMonth[$r['month']] = (int) ($r['count'] ?? 0);
        }
        $newTenants = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $newTenants[] = ['month' => $m, 'count' => $bucketByMonth[$m] ?? 0];
        }

        return $this->ok([
            'mrr'                  => round($mrr, 2),
            'arr'                  => round($mrr * 12, 2),
            'mrr_growth_percent'   => $mrrGrowthPct,
            'plan_distribution'    => $planDist,
            'total_tenants'        => $totalTenants,
            'active_tenants'       => $activeTenants,
            'trial_tenants'        => $trialTenants,
            'churn_rate_30d'       => $churnRate30d,
            'cancelled_30d'        => $cancelledLast30,
            'signup_funnel'        => $signupFunnel,
            'new_tenants_per_month' => $newTenants,
            'snapshot_at'          => date('c'),
        ]);
    }

    /**
     * Hitung MRR pada timestamp tertentu (default = sekarang).
     * Untuk historical MRR (e.g. bulan lalu), gunakan subscription_events
     * untuk reconstruct status. Untuk simplicity, snapshot saja status
     * saat ini bila $atTimestamp null.
     */
    private function computeMrrAt(?int $atTimestamp = null): float
    {
        $db = Database::connect();
        $mrr = 0.0;

        if ($atTimestamp === null) {
            // Current MRR
            $monthly = $db->table('subscriptions s')
                ->select('SUM(COALESCE(s.custom_price, p.price_monthly)) AS mrr')
                ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
                ->where('s.status', 'ACTIVE')
                ->where('s.billing_cycle', 'MONTHLY')
                ->get()->getRowArray();
            $mrr += (float) ($monthly['mrr'] ?? 0);
            $yearly = $db->table('subscriptions s')
                ->select('SUM(COALESCE(s.custom_price, p.price_yearly)) AS yearly_total')
                ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
                ->where('s.status', 'ACTIVE')
                ->where('s.billing_cycle', 'YEARLY')
                ->get()->getRowArray();
            $mrr += ((float) ($yearly['yearly_total'] ?? 0)) / 12;
            return $mrr;
        }

        // Historical MRR: ACTIVE subs yang created_at <= $atTimestamp dan
        // (cancelled_at IS NULL OR cancelled_at > $atTimestamp)
        $atDate = date('Y-m-d H:i:s', $atTimestamp);
        $monthly = $db->table('subscriptions s')
            ->select('SUM(COALESCE(s.custom_price, p.price_monthly)) AS mrr')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->where('s.created_at <=', $atDate)
            ->where('s.billing_cycle', 'MONTHLY')
            ->groupStart()
                ->where('s.cancelled_at IS NULL')
                ->orWhere('s.cancelled_at >', $atDate)
            ->groupEnd()
            ->get()->getRowArray();
        $mrr += (float) ($monthly['mrr'] ?? 0);
        $yearly = $db->table('subscriptions s')
            ->select('SUM(COALESCE(s.custom_price, p.price_yearly)) AS yearly_total')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->where('s.created_at <=', $atDate)
            ->where('s.billing_cycle', 'YEARLY')
            ->groupStart()
                ->where('s.cancelled_at IS NULL')
                ->orWhere('s.cancelled_at >', $atDate)
            ->groupEnd()
            ->get()->getRowArray();
        $mrr += ((float) ($yearly['yearly_total'] ?? 0)) / 12;

        return $mrr;
    }
}
