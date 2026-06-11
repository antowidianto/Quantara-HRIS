<?php

namespace App\Libraries\Billing;

use Config\Database;

/**
 * S1.6 — Dunning Service.
 *
 * Logic progressive collection workflow untuk subscription PAST_DUE:
 *   - H+1  → REMINDER_FRIENDLY (email ramah)
 *   - H+3  → WARNING (email + WhatsApp, status PAST_DUE)
 *   - H+7  → SUSPEND_NOTICE (email "Akan disuspend H+10")
 *   - H+10 → SUSPENDED (read-only mode via SubscriptionService.suspend)
 *   - H+30 → HARD_CANCELLED (cancel + offer reactivate)
 *
 * Pure helpers `computeActionForOverduePure()` testable tanpa DB.
 *
 * Idempotent: cek dunning_actions sebelum kirim — supaya tidak spam
 * tenant kalau cron run double atau sudah pernah kirim untuk hari ini.
 */
class DunningService
{
    /**
     * Pure helper: compute action type berdasarkan days_overdue.
     *
     * Return null kalau days_overdue tidak match milestone (mis. H+5).
     */
    public static function computeActionForOverduePure(int $daysOverdue): ?string
    {
        if ($daysOverdue >= 30) {
            return 'HARD_CANCELLED';
        }
        if ($daysOverdue >= 10) {
            return 'SUSPENDED';
        }
        if ($daysOverdue >= 7) {
            return 'SUSPEND_NOTICE';
        }
        if ($daysOverdue >= 3) {
            return 'WARNING';
        }
        if ($daysOverdue >= 1) {
            return 'REMINDER_FRIENDLY';
        }
        return null;
    }

    /**
     * Pure helper: kalkulasi days_overdue dari current_period_end vs now.
     */
    public static function daysOverduePure(string $periodEnd, string $now): int
    {
        $end = strtotime($periodEnd);
        $current = strtotime($now);
        if ($end === false || $current === false || $current <= $end) {
            return 0;
        }
        return (int) floor(($current - $end) / 86400);
    }

    /**
     * Subject + body template per action type.
     * Pure helper — tidak akses DB / config.
     */
    public static function buildEmailPure(string $actionType, array $context): array
    {
        $companyName = (string) ($context['company_name'] ?? 'Pelanggan');
        $planName    = (string) ($context['plan_name'] ?? 'Quantara HRIS');
        $daysOverdue = (int) ($context['days_overdue'] ?? 0);
        $appName     = (string) ($context['app_name'] ?? 'Quantara HRIS');
        $supportEmail = (string) ($context['support_email'] ?? 'support@quantara.io');

        switch ($actionType) {
            case 'REMINDER_FRIENDLY':
                return [
                    'subject' => "[{$appName}] Pengingat: Pembayaran {$planName} jatuh tempo",
                    'body'    => "<p>Halo {$companyName},</p>
<p>Pembayaran subscription <strong>{$planName}</strong> Anda jatuh tempo {$daysOverdue} hari yang lalu. Mohon segera selesaikan pembayaran agar layanan tetap berjalan.</p>
<p>Bila sudah membayar, abaikan email ini.</p>
<p>Hubungi kami di <a href=\"mailto:{$supportEmail}\">{$supportEmail}</a> untuk bantuan.</p>
<p>Terima kasih,<br>Tim {$appName}</p>",
                ];
            case 'WARNING':
                return [
                    'subject' => "[{$appName}] PERINGATAN: Tagihan {$planName} sudah {$daysOverdue} hari overdue",
                    'body'    => "<p>Halo {$companyName},</p>
<p><strong>Tagihan Anda untuk {$planName} telah lewat {$daysOverdue} hari dari jatuh tempo.</strong></p>
<p>Status subscription saat ini: <span style=\"color:#f59e0b\">PAST_DUE</span>. Mohon segera lakukan pembayaran untuk menghindari pembatasan akses.</p>
<p>Tagihan tertunggak dapat dilihat di halaman <strong>Billing → Invoices</strong>.</p>
<p>Hubungi <a href=\"mailto:{$supportEmail}\">{$supportEmail}</a> jika ada pertanyaan.</p>",
                ];
            case 'SUSPEND_NOTICE':
                return [
                    'subject' => "[{$appName}] PENTING: Akun akan disuspend dalam 3 hari",
                    'body'    => "<p>Halo {$companyName},</p>
<p>Tagihan {$planName} sudah lewat {$daysOverdue} hari. Bila tidak ada pembayaran dalam 3 hari ke depan, akun Anda akan masuk mode <strong>read-only</strong> (suspend).</p>
<p>Dampak suspend:</p>
<ul>
<li>Karyawan tidak bisa clock-in/out</li>
<li>HR tidak bisa run payroll</li>
<li>Approval tertahan</li>
</ul>
<p><strong>Segera bayar untuk menghindari gangguan operasional.</strong></p>
<p>Bantuan: <a href=\"mailto:{$supportEmail}\">{$supportEmail}</a></p>",
                ];
            case 'SUSPENDED':
                return [
                    'subject' => "[{$appName}] Akun Anda telah di-SUSPEND (read-only)",
                    'body'    => "<p>Halo {$companyName},</p>
<p>Akun <strong>{$companyName}</strong> telah di-suspend per hari ini karena tagihan {$planName} sudah lewat {$daysOverdue} hari.</p>
<p>Mode saat ini: <strong>READ-ONLY</strong>. User tetap bisa login dan melihat data, tapi tidak bisa membuat transaksi baru.</p>
<p>Untuk mengaktifkan kembali, lakukan pembayaran lalu klik <strong>Reactivate</strong> di halaman Billing.</p>
<p>Bantuan: <a href=\"mailto:{$supportEmail}\">{$supportEmail}</a></p>",
                ];
            case 'HARD_CANCELLED':
                return [
                    'subject' => "[{$appName}] Subscription Anda telah dibatalkan",
                    'body'    => "<p>Halo {$companyName},</p>
<p>Karena tagihan {$planName} sudah lewat {$daysOverdue} hari tanpa pembayaran, subscription Anda kini berstatus <strong>CANCELLED</strong>.</p>
<p>Data Anda akan tetap aman selama 90 hari ke depan. Untuk reactivate, hubungi tim kami di <a href=\"mailto:{$supportEmail}\">{$supportEmail}</a>.</p>
<p>Setelah 90 hari tanpa reactivate, data akan dihapus permanen sesuai kebijakan retensi (UU PDP).</p>",
                ];
        }
        return ['subject' => '', 'body' => ''];
    }

    /**
     * Cari subscription yang PAST_DUE / overdue dan butuh dunning action.
     *
     * @return array<int, array> baris subscription dengan computed action
     */
    public function findCandidates(): array
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $rows = $db->table('subscriptions s')
            ->select('s.*, p.name AS plan_name, p.price_monthly, c.name AS company_name')
            ->join('subscription_plans p', 'p.id = s.plan_id', 'left')
            ->join('companies c', 'c.id = s.company_id', 'left')
            ->whereIn('s.status', ['ACTIVE', 'PAST_DUE'])
            ->where('s.current_period_end <', $now)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $days   = self::daysOverduePure((string) $r['current_period_end'], $now);
            $action = self::computeActionForOverduePure($days);
            if ($action === null) {
                continue;
            }
            $r['_days_overdue']    = $days;
            $r['_recommended_action'] = $action;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Cek apakah action sudah pernah dijalankan untuk subscription ini hari ini.
     * Idempotency guard untuk cron yang run double / multiple times.
     */
    public function hasActionToday(int $subscriptionId, string $actionType): bool
    {
        $db = Database::connect();
        if (! $db->tableExists('dunning_actions')) {
            return false;
        }
        $today = date('Y-m-d');
        $count = $db->table('dunning_actions')
            ->where('subscription_id', $subscriptionId)
            ->where('action_type', $actionType)
            ->where('sent_at >=', $today . ' 00:00:00')
            ->where('sent_at <=', $today . ' 23:59:59')
            ->countAllResults();
        return $count > 0;
    }

    /**
     * Catat action ke dunning_actions table.
     */
    public function recordAction(
        int $subscriptionId,
        int $companyId,
        string $actionType,
        int $daysOverdue,
        string $channel = 'email',
        bool $success = true,
        ?string $errorMessage = null,
        array $metadata = []
    ): int {
        $db = Database::connect();
        if (! $db->tableExists('dunning_actions')) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $db->table('dunning_actions')->insert([
            'subscription_id' => $subscriptionId,
            'company_id'      => $companyId,
            'action_type'     => $actionType,
            'days_overdue'    => $daysOverdue,
            'channel'         => $channel,
            'success'         => $success ? 1 : 0,
            'error_message'   => $errorMessage,
            'metadata_json'   => $metadata ? json_encode($metadata) : null,
            'sent_at'         => $now,
            'created_at'      => $now,
        ]);
        return (int) $db->insertID();
    }

    /**
     * Apply state transition kalau action membutuhkan state change:
     *   - WARNING → status = PAST_DUE
     *   - SUSPENDED → SubscriptionService.suspend()
     *   - HARD_CANCELLED → SubscriptionService.cancel()
     */
    public function applyStateTransition(int $subscriptionId, string $actionType, int $companyId): void
    {
        $db = Database::connect();

        if ($actionType === 'WARNING') {
            $db->table('subscriptions')
                ->where('id', $subscriptionId)
                ->where('status', 'ACTIVE')
                ->update([
                    'status'     => 'PAST_DUE',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            return;
        }

        if ($actionType === 'SUSPENDED') {
            // Wire ke SubscriptionService — biar audit log konsisten
            if (class_exists('\\App\\Libraries\\Billing\\SubscriptionService')) {
                try {
                    $svc = new \App\Libraries\Billing\SubscriptionService();
                    if (method_exists($svc, 'suspend')) {
                        $svc->suspend($companyId, 'Auto-suspended by dunning H+10', null);
                    }
                } catch (\Throwable $e) {
                    log_message('warning', '[Dunning] suspend failed: ' . $e->getMessage());
                }
            }
            return;
        }

        if ($actionType === 'HARD_CANCELLED') {
            if (class_exists('\\App\\Libraries\\Billing\\SubscriptionService')) {
                try {
                    $svc = new \App\Libraries\Billing\SubscriptionService();
                    if (method_exists($svc, 'cancel')) {
                        $svc->cancel($companyId, 'Auto-cancelled by dunning H+30', null, null);
                    }
                } catch (\Throwable $e) {
                    log_message('warning', '[Dunning] cancel failed: ' . $e->getMessage());
                }
            }
        }
    }
}