<?php

namespace App\Controllers\Api\V1\Billing;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Billing\FeatureGateService;
use App\Libraries\Billing\SubscriptionService;
use App\Libraries\Billing\UsageMeterService;
use App\Libraries\Billing\FeatureNotAvailableException;
use Config\Database;

/**
 * S1.1 + S1.2 — BillingController (Roadmap SaaS)
 *
 * Endpoint:
 *   GET    /api/v1/plans                          (publik) — list plan public
 *   GET    /api/v1/features/me                    — list feature aktif untuk current tenant
 *   GET    /api/v1/billing/subscription           — info subscription tenant saat ini
 *   GET    /api/v1/billing/usage                  — usage meters dashboard (S1.4)
 *   POST   /api/v1/billing/upgrade                — request upgrade plan
 *   POST   /api/v1/billing/downgrade              — request downgrade plan
 *   POST   /api/v1/billing/cancel                 — cancel subscription
 *   POST   /api/v1/billing/reactivate             — revive cancelled subscription
 *
 * Super Admin only:
 *   POST   /api/v1/admin/tenants/{id}/extend-trial
 *   POST   /api/v1/admin/tenants/{id}/feature-override
 *
 * Permission `billing.manage` untuk operasi mengubah subscription
 * (dimiliki super_company_admin secara default).
 */
class BillingController extends ApiController
{
    /**
     * GET /api/v1/plans (publik, no auth)
     * Untuk pricing page marketing site.
     */
    public function plans()
    {
        $db = Database::connect();
        $rows = $db->table('subscription_plans')
            ->where('is_active', 1)
            ->where('is_public', 1)
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        // Strip data sensitif (notes_internal, dll) — biar aman publik
        $public = array_map(fn ($p) => [
            'code'                => $p['code'],
            'name'                => $p['name'],
            'description'         => $p['description'],
            'price_monthly'       => (float) $p['price_monthly'],
            'price_yearly'        => (float) $p['price_yearly'],
            'currency'            => $p['currency'],
            'max_employees'       => $p['max_employees'] !== null ? (int) $p['max_employees'] : null,
            'max_storage_gb'      => $p['max_storage_gb'] !== null ? (float) $p['max_storage_gb'] : null,
            'max_api_calls_month' => $p['max_api_calls_month'] !== null ? (int) $p['max_api_calls_month'] : null,
            'trial_days'          => (int) $p['trial_days'],
            'sort_order'          => (int) $p['sort_order'],
        ], $rows);

        // Sertakan feature matrix per plan untuk pricing comparison page
        $featuresByPlan = [];
        foreach ($rows as $p) {
            $features = $db->table('plan_features pf')
                ->select('pf.feature_key, pf.is_included, ff.category, ff.description')
                ->join('feature_flags ff', 'ff.key = pf.feature_key', 'left')
                ->where('pf.plan_id', $p['id'])
                ->where('pf.is_included', 1)
                ->get()->getResultArray();
            $featuresByPlan[$p['code']] = array_map(fn ($f) => [
                'key'         => $f['feature_key'],
                'category'    => $f['category'],
                'description' => $f['description'],
            ], $features);
        }

        return $this->ok([
            'plans'         => $public,
            'features_map'  => $featuresByPlan,
        ]);
    }

    /**
     * GET /api/v1/features/me
     * List feature aktif untuk current tenant (auth required).
     */
    public function myFeatures()
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 401);

        $svc = new FeatureGateService();
        return $this->ok($svc->listForCompany($companyId));
    }

    /**
     * GET /api/v1/billing/subscription
     * Info subscription tenant saat ini.
     */
    public function mySubscription()
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 401);

        $db = Database::connect();
        $sub = $db->table('subscriptions s')
            ->select('s.*, p.code AS plan_code, p.name AS plan_name, p.price_monthly, p.price_yearly, p.currency, p.max_employees')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->where('s.company_id', $companyId)
            ->get()->getRowArray();

        if (! $sub) {
            return $this->ok(null, 'No subscription found');
        }

        // Hitung usage employees aktual untuk display banner
        $employeeCount = (int) $db->table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->countAllResults();

        // Days remaining di trial / period
        $now = strtotime(date('Y-m-d'));
        $periodEnd = strtotime($sub['current_period_end']);
        $daysRemaining = max(0, (int) round(($periodEnd - $now) / 86400));

        $trialDaysRemaining = null;
        if (! empty($sub['trial_ends_at'])) {
            $trialEnd = strtotime($sub['trial_ends_at']);
            $trialDaysRemaining = max(0, (int) round(($trialEnd - $now) / 86400));
        }

        return $this->ok([
            'subscription'   => $sub,
            'usage'          => [
                'employees_current' => $employeeCount,
                'employees_limit'   => $sub['max_employees'],
                'employees_pct'     => $sub['max_employees']
                    ? round(($employeeCount / max(1, (int) $sub['max_employees'])) * 100, 1)
                    : null,
            ],
            'days_remaining'        => $daysRemaining,
            'trial_days_remaining'  => $trialDaysRemaining,
        ]);
    }

    /**
     * GET /api/v1/billing/usage
     * S1.4 — Dashboard usage meters per company.
     *
     * Return semua meter (employees, storage_mb, payslips_generated,
     * whatsapp_sent, api_calls, data_exports) dengan current_value,
     * limit_value, percent_used.
     */
    public function myUsage()
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return $this->fail('Company context required', 401);
        }

        $svc = new UsageMeterService();
        $rows = $svc->getAllUsage($companyId);

        // Tambah summary status (CRITICAL kalau >= 90%, WARNING >= 75%, OK <= 74%)
        foreach ($rows as &$r) {
            $pct = (float) ($r['percent_used'] ?? 0);
            if ($r['limit_value'] < 0) {
                $r['status'] = 'UNLIMITED';
            } elseif ($pct >= 100) {
                $r['status'] = 'EXCEEDED';
            } elseif ($pct >= 90) {
                $r['status'] = 'CRITICAL';
            } elseif ($pct >= 75) {
                $r['status'] = 'WARNING';
            } else {
                $r['status'] = 'OK';
            }
        }
        unset($r);

        return $this->ok($rows);
    }

    /**
     * POST /api/v1/billing/usage/recompute (super admin only)
     * Re-sync counter dari source table — utility untuk fix drift.
     * Body: { meter_key: string }
     */
    public function recomputeUsage()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'meter_key' => 'required|max_length[64]',
        ]);
        if (! $valid) return null;

        try {
            $svc = new UsageMeterService();
            $newValue = $svc->recomputeFromSource($companyId, (string) $data['meter_key']);
            return $this->ok([
                'meter_key' => $data['meter_key'],
                'new_value' => $newValue,
            ], 'Recompute success');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'UNKNOWN_METER');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/billing/upgrade
     * Body: { plan_code: string }
     */
    public function upgrade()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'plan_code' => 'required|in_list[STARTER,PRO,ENTERPRISE]',
        ]);
        if (! $valid) return null;

        try {
            $result = (new SubscriptionService())->upgrade(
                $companyId,
                $data['plan_code'],
                $this->userId(),
            );
            return $this->ok($result, 'Upgrade berhasil');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_PLAN');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'UPGRADE_NOT_ALLOWED');
        }
    }

    /**
     * POST /api/v1/billing/downgrade
     * Body: { plan_code: string }
     */
    public function downgrade()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'plan_code' => 'required|in_list[FREE_TRIAL,STARTER,PRO]',
        ]);
        if (! $valid) return null;

        try {
            $result = (new SubscriptionService())->downgrade(
                $companyId,
                $data['plan_code'],
                $this->userId(),
            );
            return $this->ok($result, 'Downgrade dijadwalkan untuk period berikutnya');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'DOWNGRADE_NOT_ALLOWED');
        }
    }

    /**
     * POST /api/v1/billing/cancel
     * Body: { reason: string, feedback_score?: int }
     */
    public function cancel()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason'         => 'required|min_length[10]|max_length[1000]',
            'feedback_score' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[5]',
        ]);
        if (! $valid) return null;

        try {
            $result = (new SubscriptionService())->cancel(
                $companyId,
                $data['reason'],
                isset($data['feedback_score']) ? (int) $data['feedback_score'] : null,
                $this->userId(),
            );
            return $this->ok($result, 'Subscription dibatalkan, akses tetap berjalan sampai akhir period');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/billing/reactivate
     */
    public function reactivate()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        try {
            $result = (new SubscriptionService())->reactivate($companyId, $this->userId());
            return $this->ok($result, 'Subscription reactivated');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── SUPER ADMIN ENDPOINTS ──────────────────────────────────────

    /**
     * POST /api/v1/admin/tenants/{companyId}/extend-trial
     * Body: { additional_days: int, reason: string }
     */
    public function extendTrial(int $companyId)
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'additional_days' => 'required|integer|greater_than[0]|less_than_equal_to[90]',
            'reason'          => 'required|min_length[10]|max_length[500]',
        ]);
        if (! $valid) return null;

        try {
            $result = (new SubscriptionService())->extendTrial(
                $companyId,
                (int) $data['additional_days'],
                $data['reason'],
                $this->userId(),
            );
            return $this->ok($result, 'Trial extended');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/admin/tenants/{companyId}/feature-override
     * Body: { feature_key: string, is_enabled: bool, reason: string, expires_at?: datetime }
     */
    public function featureOverride(int $companyId)
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'feature_key' => 'required|max_length[80]',
            'is_enabled'  => 'required|in_list[0,1,true,false]',
            'reason'      => 'required|min_length[10]|max_length[500]',
            'expires_at'  => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;

        $db = Database::connect();
        // Cek feature key valid
        $exists = $db->table('feature_flags')->where('key', $data['feature_key'])->countAllResults();
        if ($exists === 0) {
            return $this->fail("Feature key '{$data['feature_key']}' tidak terdaftar", 422, 'UNKNOWN_FEATURE_KEY');
        }

        $isEnabled = filter_var($data['is_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('company_feature_overrides')
            ->where('company_id', $companyId)
            ->where('feature_key', $data['feature_key'])
            ->get()->getRowArray();

        $payload = [
            'company_id'         => $companyId,
            'feature_key'        => $data['feature_key'],
            'is_enabled'         => $isEnabled,
            'reason'             => $data['reason'],
            'expires_at'         => $data['expires_at'] ?? null,
            'created_by_user_id' => $this->userId(),
            'updated_at'         => $now,
        ];

        if ($existing) {
            $db->table('company_feature_overrides')->where('id', $existing['id'])->update($payload);
            $overrideId = (int) $existing['id'];
        } else {
            $payload['created_at'] = $now;
            $db->table('company_feature_overrides')->insert($payload);
            $overrideId = (int) $db->insertID();
        }

        // Flush cache supaya request berikutnya dari tenant tsb baca state baru
        (new FeatureGateService())->flushCache($companyId);

        // Audit log severity HIGH
        try {
            \Config\Services::audit()
                ->forModule('BILLING')
                ->log(
                    action: 'feature_override.set',
                    entity: 'company_feature_override',
                    entityId: $overrideId,
                    description: sprintf(
                        'Override feature "%s" → %s untuk company #%d. Reason: %s',
                        $data['feature_key'],
                        $isEnabled ? 'ENABLED' : 'DISABLED',
                        $companyId,
                        $data['reason'],
                    ),
                    severity: 'HIGH',
                    metadata: $payload,
                );
        } catch (\Throwable $e) {
            log_message('warning', 'Audit feature_override failed: ' . $e->getMessage());
        }

        return $this->ok(['id' => $overrideId] + $payload, 'Override saved');
    }
}