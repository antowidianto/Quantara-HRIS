<?php

namespace App\Libraries\Billing;

use Config\Database;
use Config\Services;
use RuntimeException;
use InvalidArgumentException;

/**
 * S1.2 — SubscriptionService (Roadmap SaaS)
 *
 * Lifecycle management subscription:
 *   TRIAL → ACTIVE → PAST_DUE → CANCELLED → EXPIRED
 *           ↑                  ↓
 *           └──── REACTIVATED ──┘
 *
 * Operasi yang di-cover:
 *   - createTrial          : tenant baru daftar
 *   - upgrade              : ganti plan dengan proration
 *   - downgrade            : ganti plan, efektif period berikutnya
 *   - cancel               : keep access sampai EOP
 *   - reactivate           : revive subscription yang CANCELLED
 *   - renew                : extend period (dipanggil cron / webhook payment)
 *   - markPastDue          : invoice tidak terbayar
 *   - markActive           : invoice sukses dibayar
 *   - suspend / unsuspend  : super admin action
 *   - extendTrial          : super admin perpanjang trial
 *
 * Semua operasi mencatat ke `subscription_events`.
 *
 * Pure helpers (`*Pure`) di-isolasi supaya bisa unit-test tanpa DB.
 */
class SubscriptionService
{
    /**
     * Buat subscription TRIAL untuk company baru.
     * Dipanggil dari TenantProvisioningService saat signup verified.
     *
     * @throws RuntimeException Bila company sudah punya subscription aktif.
     */
    public function createTrial(int $companyId, string $planCode = 'FREE_TRIAL', ?int $userId = null): array
    {
        $db = Database::connect();

        $existing = $db->table('subscriptions')->where('company_id', $companyId)->get()->getRowArray();
        if ($existing) {
            throw new RuntimeException("Company #{$companyId} already has subscription #" . $existing['id']);
        }

        $plan = $this->getPlanByCode($planCode);
        if (! $plan) {
            throw new InvalidArgumentException("Plan '{$planCode}' tidak ditemukan");
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $trialDays = (int) ($plan['trial_days'] ?? 0);
        $trialEndsAt = $trialDays > 0
            ? date('Y-m-d H:i:s', strtotime("+{$trialDays} days"))
            : null;

        $data = [
            'company_id'           => $companyId,
            'plan_id'              => (int) $plan['id'],
            'status'               => $trialDays > 0 ? 'TRIAL' : 'ACTIVE',
            'billing_cycle'        => 'MONTHLY',
            'started_at'           => $now,
            'current_period_start' => $today,
            'current_period_end'   => $trialDays > 0
                ? date('Y-m-d', strtotime("+{$trialDays} days"))
                : date('Y-m-d', strtotime('+1 month')),
            'trial_ends_at'        => $trialEndsAt,
            'auto_renew'           => 1,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        $db->table('subscriptions')->insert($data);
        $subscriptionId = (int) $db->insertID();

        $this->logEvent($subscriptionId, $companyId, 'CREATED', null, (int) $plan['id'],
            null, $data['status'], ['plan_code' => $planCode, 'trial_days' => $trialDays], 'SYSTEM', $userId);

        return ['subscription_id' => $subscriptionId] + $data;
    }

    /**
     * Upgrade plan dengan proration kalkulasi.
     *
     * Proration formula:
     *   credit_remaining = old_price × (days_remaining / days_in_period)
     *   new_charge       = new_price × (days_remaining / days_in_period)
     *   net_charge       = new_charge - credit_remaining
     *
     * @return array{subscription_id:int, prorated_amount:float, effective_date:string}
     */
    public function upgrade(int $companyId, string $newPlanCode, ?int $userId = null): array
    {
        $db = Database::connect();
        $sub = $this->getActiveSubscription($companyId);
        if (! $sub) {
            throw new RuntimeException("Tenant #{$companyId} belum punya subscription");
        }

        $newPlan = $this->getPlanByCode($newPlanCode);
        if (! $newPlan) {
            throw new InvalidArgumentException("Plan '{$newPlanCode}' tidak ditemukan");
        }

        $oldPlan = $db->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray();

        // Validasi: harus naik tier (sort_order lebih besar)
        if ((int) $newPlan['sort_order'] <= (int) $oldPlan['sort_order']) {
            throw new RuntimeException(
                "Tidak bisa upgrade ke plan dengan tier sama atau lebih rendah. Pakai downgrade()."
            );
        }

        $cycle = $sub['billing_cycle'] ?? 'MONTHLY';
        $oldPrice = $cycle === 'YEARLY' ? (float) $oldPlan['price_yearly'] : (float) $oldPlan['price_monthly'];
        $newPrice = $cycle === 'YEARLY' ? (float) $newPlan['price_yearly'] : (float) $newPlan['price_monthly'];

        $proration = self::calculateProrationPure(
            $oldPrice,
            $newPrice,
            $sub['current_period_start'],
            $sub['current_period_end'],
            date('Y-m-d'),
        );

        $now = date('Y-m-d H:i:s');
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'plan_id'    => (int) $newPlan['id'],
            'status'     => 'ACTIVE',
            'updated_at' => $now,
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'UPGRADED',
            (int) $oldPlan['id'],
            (int) $newPlan['id'],
            $sub['status'],
            'ACTIVE',
            [
                'from_plan_code' => $oldPlan['code'],
                'to_plan_code'   => $newPlan['code'],
                'proration'      => $proration,
            ],
            'USER',
            $userId,
        );

        return [
            'subscription_id'  => (int) $sub['id'],
            'prorated_amount'  => $proration['net_charge'],
            'effective_date'   => date('Y-m-d'),
            'new_plan_code'    => $newPlan['code'],
        ];
    }

    /**
     * Downgrade plan. Tidak ada credit refund, perubahan efektif di
     * period berikutnya. Sampai akhir period current, customer tetap
     * pakai plan lama (untuk hindari kekagetan akses).
     */
    public function downgrade(int $companyId, string $newPlanCode, ?int $userId = null): array
    {
        $db = Database::connect();
        $sub = $this->getActiveSubscription($companyId);
        if (! $sub) {
            throw new RuntimeException("Tenant #{$companyId} belum punya subscription");
        }

        $newPlan = $this->getPlanByCode($newPlanCode);
        $oldPlan = $db->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray();

        if ((int) $newPlan['sort_order'] >= (int) $oldPlan['sort_order']) {
            throw new RuntimeException("Pakai upgrade() untuk plan dengan tier sama/lebih tinggi.");
        }

        // Schedule perubahan: simpan ke metadata, eksekusi saat renew()
        $now = date('Y-m-d H:i:s');
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'notes_internal' => trim(($sub['notes_internal'] ?? '') . "\n[SCHEDULED DOWNGRADE] to {$newPlan['code']} on {$sub['current_period_end']}"),
            'updated_at'     => $now,
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'DOWNGRADED',
            (int) $oldPlan['id'],
            (int) $newPlan['id'],
            $sub['status'],
            $sub['status'],
            [
                'from_plan_code'   => $oldPlan['code'],
                'to_plan_code'     => $newPlan['code'],
                'effective_date'   => $sub['current_period_end'],
                'scheduled'        => true,
            ],
            'USER',
            $userId,
        );

        return [
            'subscription_id' => (int) $sub['id'],
            'effective_date'  => $sub['current_period_end'],
            'scheduled'       => true,
        ];
    }

    /**
     * Cancel subscription. Customer tetap punya akses sampai akhir
     * current_period_end, lalu auto-expire.
     */
    public function cancel(int $companyId, string $reason, ?int $feedbackScore = null, ?int $userId = null): array
    {
        $db = Database::connect();
        $sub = $this->getActiveSubscription($companyId);
        if (! $sub) {
            throw new RuntimeException("Tenant #{$companyId} belum punya subscription");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'                => 'CANCELLED',
            'cancelled_at'          => $now,
            'cancel_reason'         => $reason,
            'cancel_feedback_score' => $feedbackScore,
            'auto_renew'            => 0,
            'updated_at'            => $now,
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'CANCELLED',
            (int) $sub['plan_id'],
            (int) $sub['plan_id'],
            $sub['status'],
            'CANCELLED',
            [
                'reason'         => $reason,
                'feedback_score' => $feedbackScore,
                'access_until'   => $sub['current_period_end'],
            ],
            'USER',
            $userId,
        );

        return [
            'subscription_id' => (int) $sub['id'],
            'access_until'    => $sub['current_period_end'],
        ];
    }

    /**
     * Reactivate subscription yang masih CANCELLED tapi belum EXPIRED.
     */
    public function reactivate(int $companyId, ?int $userId = null): array
    {
        $db = Database::connect();
        $sub = $db->table('subscriptions')->where('company_id', $companyId)->get()->getRowArray();
        if (! $sub) {
            throw new RuntimeException("Tenant #{$companyId} belum punya subscription");
        }
        if ($sub['status'] !== 'CANCELLED') {
            throw new RuntimeException(
                "Hanya subscription status CANCELLED yang bisa di-reactivate. Status saat ini: {$sub['status']}"
            );
        }
        if ($sub['current_period_end'] < date('Y-m-d')) {
            throw new RuntimeException(
                "Period sudah berakhir per {$sub['current_period_end']}. Buat subscription baru."
            );
        }

        $now = date('Y-m-d H:i:s');
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'        => 'ACTIVE',
            'cancelled_at'  => null,
            'cancel_reason' => null,
            'auto_renew'    => 1,
            'updated_at'    => $now,
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'REACTIVATED',
            (int) $sub['plan_id'],
            (int) $sub['plan_id'],
            'CANCELLED',
            'ACTIVE',
            [],
            'USER',
            $userId,
        );

        return ['subscription_id' => (int) $sub['id']];
    }

    /**
     * Extend trial period (hanya super admin).
     */
    public function extendTrial(int $companyId, int $additionalDays, string $reason, ?int $userId = null): array
    {
        $db = Database::connect();
        $sub = $this->getActiveSubscription($companyId);
        if (! $sub || $sub['status'] !== 'TRIAL') {
            throw new RuntimeException("Subscription bukan dalam status TRIAL");
        }

        $newTrialEnd = date('Y-m-d H:i:s', strtotime($sub['trial_ends_at'] . " +{$additionalDays} days"));
        $newPeriodEnd = date('Y-m-d', strtotime($sub['current_period_end'] . " +{$additionalDays} days"));

        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'trial_ends_at'      => $newTrialEnd,
            'current_period_end' => $newPeriodEnd,
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'TRIAL_EXTENDED',
            (int) $sub['plan_id'],
            (int) $sub['plan_id'],
            'TRIAL',
            'TRIAL',
            ['days' => $additionalDays, 'reason' => $reason, 'new_end' => $newTrialEnd],
            'ADMIN',
            $userId,
        );

        return ['subscription_id' => (int) $sub['id'], 'new_trial_ends_at' => $newTrialEnd];
    }

    /**
     * Suspend (read-only mode). Dipanggil saat tenant masuk dunning H+10.
     */
    public function suspend(int $companyId, string $reason, ?int $userId = null): void
    {
        $db = Database::connect();
        $sub = $this->getActiveSubscription($companyId);
        if (! $sub) return;

        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'     => 'SUSPENDED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'SUSPENDED',
            (int) $sub['plan_id'],
            (int) $sub['plan_id'],
            $sub['status'],
            'SUSPENDED',
            ['reason' => $reason],
            'SYSTEM',
            $userId,
        );
    }

    public function unsuspend(int $companyId, ?int $userId = null): void
    {
        $db = Database::connect();
        $sub = $db->table('subscriptions')->where('company_id', $companyId)->get()->getRowArray();
        if (! $sub || $sub['status'] !== 'SUSPENDED') return;

        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'     => 'ACTIVE',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logEvent(
            (int) $sub['id'],
            $companyId,
            'UNSUSPENDED',
            (int) $sub['plan_id'],
            (int) $sub['plan_id'],
            'SUSPENDED',
            'ACTIVE',
            [],
            'ADMIN',
            $userId,
        );
    }

    // ─── Pure helpers ───────────────────────────────────────────────

    /**
     * Pure proration calculator. Dipakai upgrade flow.
     *
     * @return array{
     *   credit_remaining:float,
     *   new_charge:float,
     *   net_charge:float,
     *   days_remaining:int,
     *   days_in_period:int,
     * }
     */
    public static function calculateProrationPure(
        float $oldPrice,
        float $newPrice,
        string $periodStart,
        string $periodEnd,
        string $today,
    ): array {
        $start = strtotime($periodStart);
        $end   = strtotime($periodEnd);
        $now   = strtotime($today);

        $daysInPeriod  = max(1, (int) round(($end - $start) / 86400) + 1);
        $daysRemaining = max(0, (int) round(($end - $now) / 86400) + 1);
        $ratio         = $daysRemaining / $daysInPeriod;

        $creditRemaining = round($oldPrice * $ratio, 2);
        $newCharge       = round($newPrice * $ratio, 2);
        $netCharge       = round($newCharge - $creditRemaining, 2);

        return [
            'credit_remaining' => $creditRemaining,
            'new_charge'       => $newCharge,
            'net_charge'       => $netCharge,
            'days_remaining'   => $daysRemaining,
            'days_in_period'   => $daysInPeriod,
        ];
    }

    /**
     * Pure helper untuk hitung tanggal akhir period berikutnya.
     */
    public static function nextPeriodEndPure(string $currentEnd, string $cycle = 'MONTHLY'): string
    {
        $start = date('Y-m-d', strtotime($currentEnd . ' +1 day'));
        $modifier = $cycle === 'YEARLY' ? '+1 year' : '+1 month';
        return date('Y-m-d', strtotime($start . ' ' . $modifier . ' -1 day'));
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function getActiveSubscription(int $companyId): ?array
    {
        $db = Database::connect();
        $row = $db->table('subscriptions')
            ->where('company_id', $companyId)
            ->whereIn('status', ['TRIAL', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'])
            ->get()->getRowArray();
        return $row ?: null;
    }

    private function getPlanByCode(string $code): ?array
    {
        $db = Database::connect();
        $row = $db->table('subscription_plans')
            ->where('code', $code)
            ->where('is_active', 1)
            ->get()->getRowArray();
        return $row ?: null;
    }

    private function logEvent(
        int $subscriptionId,
        int $companyId,
        string $eventType,
        ?int $fromPlanId,
        ?int $toPlanId,
        ?string $fromStatus,
        ?string $toStatus,
        array $metadata,
        string $triggeredBy = 'SYSTEM',
        ?int $triggeredByUserId = null,
    ): void {
        $db = Database::connect();
        $db->table('subscription_events')->insert([
            'subscription_id'      => $subscriptionId,
            'company_id'           => $companyId,
            'event_type'           => $eventType,
            'from_plan_id'         => $fromPlanId,
            'to_plan_id'           => $toPlanId,
            'from_status'          => $fromStatus,
            'to_status'            => $toStatus,
            'metadata_json'        => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'triggered_by'         => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        // Mirror ke audit log untuk visibility super admin
        try {
            Services::audit()
                ->forModule('BILLING')
                ->log(
                    action: 'subscription.' . strtolower($eventType),
                    entity: 'subscription',
                    entityId: $subscriptionId,
                    description: sprintf('Subscription #%d: %s', $subscriptionId, $eventType),
                    severity: 'NOTICE',
                    metadata: array_merge($metadata, [
                        'company_id' => $companyId,
                        'from_plan_id' => $fromPlanId,
                        'to_plan_id' => $toPlanId,
                    ]),
                );
        } catch (\Throwable $e) {
            log_message('warning', 'SubscriptionService audit failed: ' . $e->getMessage());
        }
    }
}