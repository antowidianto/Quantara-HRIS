<?php

namespace App\Controllers\Api\V1\CustomerSuccess;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\CustomerSuccess\CustomerHealthService;
use Config\Database;

/**
 * S3.4 — Customer Success Controller.
 *
 * Endpoint:
 *   GET   /api/v1/onboarding/progress             — list step progress untuk current user
 *   POST  /api/v1/onboarding/{step}/complete      — mark step done
 *   GET   /api/v1/announcements/unread            — list announcement belum dibaca
 *   POST  /api/v1/announcements/{id}/read         — mark read + track CTA click
 *   GET   /api/v1/companies/me/health-score       — current tenant health (super_company_admin only)
 *   GET   /api/v1/admin/cs/health-scores          — list semua tenant + score (super admin)
 *   POST  /api/v1/admin/cs/recompute/{id}         — force recompute (super admin)
 */
class CustomerSuccessController extends ApiController
{
    /**
     * Whitelist step keys yang valid untuk onboarding.
     */
    private const STEP_KEYS = [
        'logo_uploaded',
        'timezone_set',
        'first_employee_invited',
        'payroll_period_created',
        'first_payslip_generated',
        'first_leave_approved',
        'integrations_explored',
        'docs_read',
    ];

    /**
     * GET /onboarding/progress
     */
    public function progress()
    {
        $userId    = $this->userId();
        $companyId = $this->companyId();
        if (! $userId || ! $companyId) {
            return $this->fail('Auth context required', 401);
        }

        $db = Database::connect();
        if (! $db->tableExists('onboarding_progress')) {
            return $this->ok(['steps' => [], 'completion_percent' => 0]);
        }

        $rows = $db->table('onboarding_progress')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['step_key']] = $r;
        }

        $out = [];
        $completed = 0;
        foreach (self::STEP_KEYS as $key) {
            $isDone = isset($byKey[$key]) && ! empty($byKey[$key]['completed_at']);
            if ($isDone) $completed++;
            $out[] = [
                'step_key'     => $key,
                'completed'    => $isDone,
                'completed_at' => $byKey[$key]['completed_at'] ?? null,
            ];
        }

        return $this->ok([
            'steps'              => $out,
            'completion_percent' => round(($completed / count(self::STEP_KEYS)) * 100, 1),
            'total_steps'        => count(self::STEP_KEYS),
            'completed'          => $completed,
        ]);
    }

    /**
     * POST /onboarding/{step}/complete
     */
    public function completeStep(string $step)
    {
        $userId    = $this->userId();
        $companyId = $this->companyId();
        if (! $userId || ! $companyId) {
            return $this->fail('Auth context required', 401);
        }
        if (! in_array($step, self::STEP_KEYS, true)) {
            return $this->fail('Unknown step key: ' . $step, 422, 'UNKNOWN_STEP');
        }

        $db = Database::connect();
        if (! $db->tableExists('onboarding_progress')) {
            return $this->fail('Onboarding tracking belum aktif', 503);
        }

        $now = date('Y-m-d H:i:s');
        $existing = $db->table('onboarding_progress')
            ->where('user_id', $userId)
            ->where('step_key', $step)
            ->get()->getRowArray();

        $payload = $this->getJsonInput();

        if ($existing) {
            $db->table('onboarding_progress')->where('id', $existing['id'])->update([
                'completed_at'  => $now,
                'metadata_json' => $payload ? json_encode($payload) : null,
                'updated_at'    => $now,
            ]);
        } else {
            $db->table('onboarding_progress')->insert([
                'company_id'    => $companyId,
                'user_id'       => $userId,
                'step_key'      => $step,
                'completed_at'  => $now,
                'metadata_json' => $payload ? json_encode($payload) : null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        return $this->ok(['step_key' => $step, 'completed_at' => $now], 'Step marked complete');
    }

    /**
     * GET /announcements/unread
     */
    public function unreadAnnouncements()
    {
        $userId    = $this->userId();
        $companyId = $this->companyId();
        if (! $userId) {
            return $this->fail('Auth context required', 401);
        }

        $db = Database::connect();
        if (! $db->tableExists('release_announcements')) {
            return $this->ok([]);
        }

        $now = date('Y-m-d H:i:s');

        // Subquery: ID announcement yang sudah dibaca user
        $readIdsRaw = $db->tableExists('announcement_reads')
            ? $db->table('announcement_reads')
                ->select('announcement_id')
                ->where('user_id', $userId)
                ->get()->getResultArray()
            : [];
        $readIds = array_column($readIdsRaw, 'announcement_id');

        $builder = $db->table('release_announcements')
            ->where('deleted_at IS NULL')
            ->where('published_at IS NOT NULL')
            ->where('published_at <=', $now)
            ->groupStart()
                ->where('expires_at IS NULL')
                ->orWhere('expires_at >', $now)
            ->groupEnd()
            ->orderBy('published_at', 'DESC')
            ->limit(20);

        if (! empty($readIds)) {
            $builder->whereNotIn('id', $readIds);
        }

        $rows = $builder->get()->getResultArray();

        // Filter berdasarkan target_plans (kalau ada)
        $tenantPlanCode = $this->resolveTenantPlanCode($companyId);
        $filtered = array_filter($rows, function ($r) use ($tenantPlanCode) {
            $targetPlans = ! empty($r['target_plans_json'])
                ? json_decode($r['target_plans_json'], true)
                : null;
            if (! is_array($targetPlans) || empty($targetPlans)) {
                return true; // null = semua plan
            }
            return in_array($tenantPlanCode, $targetPlans, true);
        });

        return $this->ok(array_values($filtered));
    }

    /**
     * POST /announcements/{id}/read
     */
    public function markRead(int $id)
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Auth context required', 401);
        }

        $db = Database::connect();
        if (! $db->tableExists('announcement_reads')) {
            return $this->fail('Tracking belum aktif', 503);
        }

        $payload = $this->getJsonInput();
        $ctaClicked = ! empty($payload['cta_clicked']) ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('announcement_reads')
            ->where('announcement_id', $id)
            ->where('user_id', $userId)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('announcement_reads')->where('id', $existing['id'])->update([
                'cta_clicked' => max((int) $existing['cta_clicked'], $ctaClicked),
            ]);
        } else {
            $db->table('announcement_reads')->insert([
                'announcement_id' => $id,
                'user_id'         => $userId,
                'read_at'         => $now,
                'cta_clicked'     => $ctaClicked,
            ]);
        }
        return $this->ok(null, 'Marked as read');
    }

    /**
     * GET /companies/me/health-score
     */
    public function myHealth()
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return $this->fail('Tenant context required', 401);
        }
        // Permission: tenant boleh lihat score sendiri (super_company_admin)
        if (! $this->tenant()->isSuperAdmin() && method_exists($this->tenant(), 'getRole')) {
            $role = strtolower((string) $this->tenant()->getRole());
            if (! in_array($role, ['super_company_admin', 'super_admin', 'company_admin'], true)) {
                return $this->fail('Forbidden', 403);
            }
        }

        $svc = new CustomerHealthService();
        $latest = $svc->getLatest($companyId);
        if (! $latest) {
            // Compute on-the-fly kalau belum ada cached score
            $latest = $svc->compute($companyId);
        }
        return $this->ok($latest);
    }

    /**
     * GET /admin/cs/health-scores (super admin)
     */
    public function adminHealthScores()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }

        $db = Database::connect();
        if (! $db->tableExists('customer_health_scores')) {
            return $this->ok([]);
        }

        $tier = trim((string) $this->request->getGet('tier'));

        // Latest score per company via subquery
        $sql = "SELECT chs.*, c.name AS company_name, c.code AS company_code
                FROM customer_health_scores chs
                INNER JOIN (
                    SELECT company_id, MAX(calculated_at) AS max_calc
                    FROM customer_health_scores
                    GROUP BY company_id
                ) latest ON latest.company_id = chs.company_id AND latest.max_calc = chs.calculated_at
                LEFT JOIN companies c ON c.id = chs.company_id
                WHERE c.deleted_at IS NULL";

        $params = [];
        if ($tier !== '' && in_array($tier, ['GREEN', 'YELLOW', 'RED'], true)) {
            $sql .= " AND chs.tier = ?";
            $params[] = $tier;
        }
        $sql .= " ORDER BY chs.score ASC LIMIT 200";

        $rows = $db->query($sql, $params)->getResultArray();
        foreach ($rows as &$r) {
            $r['breakdown'] = json_decode($r['breakdown_json'] ?? '{}', true);
            unset($r['breakdown_json']);
        }
        return $this->ok($rows);
    }

    /**
     * POST /admin/cs/recompute/{companyId} (super admin)
     */
    public function adminRecompute(int $companyId)
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        try {
            $svc = new CustomerHealthService();
            $result = $svc->compute($companyId);
            return $this->ok($result, 'Recomputed');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    private function resolveTenantPlanCode(int $companyId): ?string
    {
        if ($companyId <= 0) return null;
        $db = Database::connect();
        if (! $db->tableExists('subscriptions') || ! $db->tableExists('subscription_plans')) {
            return null;
        }
        $row = $db->table('subscriptions s')
            ->select('p.code')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->where('s.company_id', $companyId)
            ->limit(1)->get()->getRowArray();
        return $row ? (string) ($row['code'] ?? '') : null;
    }
}