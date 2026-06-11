<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * S1.2 — Subscription Renewal & Trial Expiry Check (cron daily).
 *
 * Iterasi semua subscription dan handle 4 transition:
 *
 *   1. TRIAL expired (trial_ends_at <= NOW)
 *      - Kalau ada billing info / payment method → downgrade ke STARTER otomatis
 *      - Kalau belum ada → ubah status ke PAST_DUE (akan di-handle dunning)
 *
 *   2. ACTIVE current_period_end <= NOW + auto_renew=1
 *      - Roll period_start/end ke periode baru (MONTHLY +1 bulan / YEARLY +1 tahun)
 *      - Tulis subscription_event RENEWED
 *      - Generate invoice (placeholder — S1.5 nanti integrate Midtrans/Xendit)
 *
 *   3. ACTIVE current_period_end <= NOW + auto_renew=0
 *      - Status ke EXPIRED (user batalin auto-renewal)
 *
 *   4. PAST_DUE > 30 hari → status ke CANCELLED otomatis (hard cancel)
 *      Note: dunning sudah handle suspend H+10. Cron ini fallback bila dunning
 *            gagal atau suspend tidak di-resolve.
 *
 * Usage:
 *   php spark billing:check-renewals
 *   php spark billing:check-renewals --dry-run
 *
 * Recommended cron:
 *   0 0 * * * cd /var/www/hris && php spark billing:check-renewals
 *
 * Pure logic (renewal date math) di-isolasi sebagai static helper supaya
 * testable tanpa DB.
 */
class BillingCheckRenewals extends BaseCommand
{
    protected $group       = 'Billing';
    protected $name        = 'billing:check-renewals';
    protected $description = 'Auto-renew ACTIVE subscriptions + handle trial expiry (S1.2 Roadmap SaaS)';
    protected $usage       = 'billing:check-renewals [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Simulasi saja, tidak commit ke DB',
    ];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null;
        $now    = date('Y-m-d H:i:s');

        CLI::write('[' . $now . '] Starting renewal check' . ($dryRun ? ' (DRY-RUN)' : ''), 'cyan');

        $stats = [
            'trial_expired'         => 0,
            'trial_to_past_due'     => 0,
            'active_renewed'        => 0,
            'active_expired'        => 0,
            'past_due_hard_cancel'  => 0,
            'errors'                => 0,
        ];

        try {
            $stats['trial_expired']        = $this->processExpiredTrials($dryRun);
            $stats['active_renewed']       = $this->processActiveRenewals($dryRun);
            $stats['active_expired']       = $this->processNonRenewableActive($dryRun);
            $stats['past_due_hard_cancel'] = $this->processStuckPastDue($dryRun);
        } catch (\Throwable $e) {
            CLI::error('Renewal check failed: ' . $e->getMessage());
            $stats['errors']++;
            $this->writeAudit('billing.renewal_check_failed', $stats, 'CRITICAL');
            exit(1);
        }

        // Summary
        CLI::newLine();
        CLI::write('Summary:', 'yellow');
        foreach ($stats as $key => $val) {
            CLI::write(sprintf('  %-25s : %d', $key, $val));
        }

        if (! $dryRun) {
            $this->writeAudit('billing.renewal_check_completed', $stats, 'NOTICE');
        }
    }

    /**
     * Trial expired → coba downgrade auto, kalau gagal mark PAST_DUE.
     */
    private function processExpiredTrials(bool $dryRun): int
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) return 0;

        $now = date('Y-m-d H:i:s');
        $rows = $db->table('subscriptions')
            ->where('status', 'TRIAL')
            ->where('trial_ends_at IS NOT NULL')
            ->where('trial_ends_at <=', $now)
            ->get()->getResultArray();

        if (empty($rows)) return 0;
        CLI::write('Found ' . count($rows) . ' trial(s) expired', 'yellow');

        $count = 0;
        foreach ($rows as $sub) {
            $companyId = (int) $sub['company_id'];
            CLI::write("  • company #{$companyId} trial expired (ends {$sub['trial_ends_at']})", 'white');

            if ($dryRun) {
                $count++;
                continue;
            }

            // Untuk MVP: pindahkan ke PAST_DUE supaya dunning ambil alih.
            // Future: cek apakah tenant sudah punya payment_method dan
            // auto-charge pakai PaymentGateway (S1.5).
            $db->table('subscriptions')
                ->where('id', $sub['id'])
                ->update([
                    'status'     => 'PAST_DUE',
                    'updated_at' => $now,
                ]);

            $db->table('subscription_events')->insert([
                'subscription_id' => $sub['id'],
                'event_type'      => 'TRIAL_EXPIRED',
                'note'            => 'Auto-marked PAST_DUE oleh BillingCheckRenewals; dunning akan eskalasi.',
                'created_at'      => $now,
            ]);

            $count++;
        }
        return $count;
    }

    /**
     * ACTIVE dengan period_end ≤ NOW + auto_renew=1 → roll period.
     */
    private function processActiveRenewals(bool $dryRun): int
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) return 0;

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $rows = $db->table('subscriptions')
            ->where('status', 'ACTIVE')
            ->where('auto_renew', 1)
            ->where('current_period_end <=', $today)
            ->get()->getResultArray();

        if (empty($rows)) return 0;
        CLI::write('Found ' . count($rows) . ' subscription(s) due for renewal', 'yellow');

        $count = 0;
        foreach ($rows as $sub) {
            $companyId = (int) $sub['company_id'];
            $cycle = $sub['billing_cycle'] ?? 'MONTHLY';

            $newStart = $today;
            $newEnd = self::nextPeriodEndPure($newStart, $cycle);

            CLI::write("  • company #{$companyId} renew {$cycle}: {$newStart} → {$newEnd}", 'white');

            if ($dryRun) {
                $count++;
                continue;
            }

            $db->table('subscriptions')
                ->where('id', $sub['id'])
                ->update([
                    'current_period_start' => $newStart,
                    'current_period_end'   => $newEnd,
                    'updated_at'           => $now,
                ]);

            $db->table('subscription_events')->insert([
                'subscription_id' => $sub['id'],
                'event_type'      => 'RENEWED',
                'note'            => "Auto-renewed by cron. Cycle: {$cycle}, period: {$newStart} → {$newEnd}",
                'created_at'      => $now,
            ]);

            // Future: trigger generateInvoice($sub) di S1.5 Payment Gateway
            $count++;
        }
        return $count;
    }

    /**
     * ACTIVE dengan auto_renew=0 dan period habis → set EXPIRED.
     */
    private function processNonRenewableActive(bool $dryRun): int
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) return 0;

        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('subscriptions')
            ->where('status', 'ACTIVE')
            ->where('auto_renew', 0)
            ->where('current_period_end <=', $today)
            ->get()->getResultArray();

        if (empty($rows)) return 0;
        CLI::write('Found ' . count($rows) . ' subscription(s) expired (auto_renew=off)', 'yellow');

        $count = 0;
        foreach ($rows as $sub) {
            $companyId = (int) $sub['company_id'];
            CLI::write("  • company #{$companyId} EXPIRED (period ended)", 'white');

            if ($dryRun) {
                $count++;
                continue;
            }

            $db->table('subscriptions')
                ->where('id', $sub['id'])
                ->update([
                    'status'     => 'EXPIRED',
                    'updated_at' => $now,
                ]);

            $db->table('subscription_events')->insert([
                'subscription_id' => $sub['id'],
                'event_type'      => 'EXPIRED',
                'note'            => 'Auto-renew off, period ended. User can reactivate.',
                'created_at'      => $now,
            ]);

            $count++;
        }
        return $count;
    }

    /**
     * PAST_DUE > 30 hari → hard cancel (fallback dari dunning).
     */
    private function processStuckPastDue(bool $dryRun): int
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) return 0;

        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('subscriptions')
            ->where('status', 'PAST_DUE')
            ->where('updated_at <=', $cutoff)
            ->get()->getResultArray();

        if (empty($rows)) return 0;
        CLI::write('Found ' . count($rows) . ' PAST_DUE subscription(s) > 30 days', 'red');

        $count = 0;
        foreach ($rows as $sub) {
            $companyId = (int) $sub['company_id'];
            CLI::write("  • company #{$companyId} hard-cancelled (30d past_due)", 'red');

            if ($dryRun) {
                $count++;
                continue;
            }

            $db->table('subscriptions')
                ->where('id', $sub['id'])
                ->update([
                    'status'        => 'CANCELLED',
                    'cancelled_at'  => $now,
                    'cancel_reason' => 'Auto-cancel: PAST_DUE > 30 days, no payment received.',
                    'updated_at'    => $now,
                ]);

            $db->table('subscription_events')->insert([
                'subscription_id' => $sub['id'],
                'event_type'      => 'HARD_CANCELLED',
                'note'            => 'Auto hard-cancel after 30 days PAST_DUE.',
                'created_at'      => $now,
            ]);

            $count++;
        }
        return $count;
    }

    /**
     * Pure helper: next period end calculation.
     * Public + static supaya bisa di-unit-test tanpa DB.
     */
    public static function nextPeriodEndPure(string $startDate, string $cycle): string
    {
        $start = new \DateTime($startDate);
        $next = clone $start;
        $next->modify($cycle === 'YEARLY' ? '+1 year' : '+1 month');
        // -1 day supaya inklusif (e.g. start 1 Jan, end 31 Jan utk monthly)
        $next->modify('-1 day');
        return $next->format('Y-m-d');
    }

    private function writeAudit(string $event, array $metadata, string $severity): void
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('audit_logs')) return;
            $db->table('audit_logs')->insert([
                'company_id'    => null,
                'user_id'       => null,
                'event'         => $event,
                'entity_type'   => 'subscriptions',
                'entity_id'     => null,
                'severity'      => $severity,
                'metadata_json' => json_encode($metadata),
                'ip_address'    => 'cli',
                'user_agent'    => 'spark:billing:check-renewals',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[BillingCheckRenewals] audit failed: ' . $e->getMessage());
        }
    }
}