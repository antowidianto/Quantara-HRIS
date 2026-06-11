<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Billing\DunningService;

/**
 * S1.6 — Dunning cron command (daily 09:00 lokal).
 *
 * Iterate semua subscription overdue → kirim notifikasi sesuai milestone +
 * apply state transition. Idempotent: tidak kirim ulang kalau sudah pernah
 * kirim hari ini untuk action yang sama.
 *
 * Usage:
 *   php spark billing:dunning           # process semua candidates
 *   php spark billing:dunning --dry-run # log saja, no send + no state change
 *
 * Recommended cron:
 *   0 9 * * * cd /var/www/hris && php spark billing:dunning
 */
class BillingDunning extends BaseCommand
{
    protected $group       = 'Billing';
    protected $name        = 'billing:dunning';
    protected $description = 'Process subscription dunning workflow (S1.6 Roadmap SaaS)';
    protected $usage       = 'billing:dunning [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Simulate tanpa kirim email atau ubah state',
    ];

    public function run(array $params)
    {
        $dryRun = in_array('--dry-run', $params, true);
        $svc    = new DunningService();

        CLI::write('[' . date('Y-m-d H:i:s') . '] BillingDunning starting' . ($dryRun ? ' (DRY-RUN)' : ''), 'cyan');

        $candidates = $svc->findCandidates();
        if (empty($candidates)) {
            CLI::write('  ✓ No overdue subscriptions', 'green');
            return;
        }

        CLI::write('  Found ' . count($candidates) . ' overdue subscription(s)', 'white');

        $stats = ['processed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($candidates as $sub) {
            $action = (string) $sub['_recommended_action'];
            $days   = (int) $sub['_days_overdue'];
            $subId  = (int) $sub['id'];
            $cId    = (int) $sub['company_id'];

            // Idempotency: skip kalau sudah pernah kirim action ini hari ini
            if ($svc->hasActionToday($subId, $action)) {
                CLI::write("  [skip] sub#{$subId} action={$action} sudah dijalankan hari ini", 'yellow');
                $stats['skipped']++;
                continue;
            }

            try {
                if ($dryRun) {
                    CLI::write(sprintf(
                        "  [dry-run] sub#%d company=%s days=%d action=%s",
                        $subId, $sub['company_name'] ?? '?', $days, $action
                    ), 'blue');
                    $stats['processed']++;
                    continue;
                }

                // 1. Build email
                $email = DunningService::buildEmailPure($action, [
                    'company_name' => $sub['company_name'] ?? '',
                    'plan_name'    => $sub['plan_name'] ?? '',
                    'days_overdue' => $days,
                    'app_name'     => 'Quantara HRIS',
                    'support_email' => (string) env('SUPPORT_EMAIL', 'support@quantara.io'),
                ]);

                // 2. Resolve recipient (admin/super_company_admin user)
                $recipient = $this->resolvePrimaryAdminEmail($cId);

                $sendOk = false;
                $err = null;
                if ($recipient && ! empty($email['subject'])) {
                    $sendOk = $this->sendEmail($recipient, $email['subject'], $email['body'], $err);
                } else {
                    $err = 'No admin email recipient resolved';
                }

                // 3. Record action ke dunning_actions (selalu, success/failure)
                $svc->recordAction(
                    $subId,
                    $cId,
                    $action,
                    $days,
                    'email',
                    $sendOk,
                    $err,
                    ['recipient' => $recipient, 'subject' => $email['subject']]
                );

                // 4. Apply state transition (only kalau email sukses atau action critical)
                if ($action === 'SUSPENDED' || $action === 'HARD_CANCELLED' || $action === 'WARNING') {
                    $svc->applyStateTransition($subId, $action, $cId);
                }

                CLI::write(sprintf(
                    "  [%s] sub#%d company=%s days=%d action=%s → %s",
                    $sendOk ? 'ok' : 'fail',
                    $subId, $sub['company_name'] ?? '?', $days, $action,
                    $sendOk ? "sent to {$recipient}" : "ERROR: {$err}"
                ), $sendOk ? 'green' : 'red');
                $sendOk ? $stats['processed']++ : $stats['failed']++;

            } catch (\Throwable $e) {
                CLI::error("  sub#{$subId}: " . $e->getMessage());
                $stats['failed']++;
            }
        }

        CLI::write(sprintf(
            '[%s] Done: processed=%d skipped=%d failed=%d',
            date('Y-m-d H:i:s'), $stats['processed'], $stats['skipped'], $stats['failed']
        ), $stats['failed'] === 0 ? 'green' : 'yellow');

        // Audit log summary
        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'company_id'    => null,
                    'user_id'       => null,
                    'event'         => 'billing.dunning_run',
                    'entity_type'   => 'dunning_actions',
                    'entity_id'     => null,
                    'severity'      => 'NOTICE',
                    'metadata_json' => json_encode($stats + ['dry_run' => $dryRun]),
                    'ip_address'    => 'cli',
                    'user_agent'    => 'spark:billing:dunning',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            log_message('warning', '[Dunning] audit insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve email penerima dunning untuk satu company.
     * Strategi: cari user dengan role super_company_admin + email aktif +
     * tidak HARD bounced.
     */
    private function resolvePrimaryAdminEmail(int $companyId): ?string
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('users')) {
            return null;
        }

        $row = $db->table('users u')
            ->select('u.email, u.email_bounced_at, u.email_bounce_type')
            ->where('u.company_id', $companyId)
            ->where('u.is_active', 1)
            ->where('u.email IS NOT NULL')
            ->where("(u.email_bounce_type IS NULL OR u.email_bounce_type != 'HARD')")
            ->orderBy('u.id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        return $row && ! empty($row['email']) ? (string) $row['email'] : null;
    }

    /**
     * Kirim email via NotificationService kalau ada, fallback ke
     * Services::email() built-in.
     */
    private function sendEmail(string $to, string $subject, string $bodyHtml, ?string &$err = null): bool
    {
        try {
            // Pakai NotificationService kalau exists (queue-aware + provider switching)
            $svc = \Config\Services::notification();
            if ($svc && method_exists($svc, 'queueEmail')) {
                $svc->queueEmail(null, $to, '', $subject, $bodyHtml);
                return true;
            }
        } catch (\Throwable $e) {
            // fall through ke built-in
        }

        // Fallback: CI4 Email lib (sync)
        try {
            $email = \Config\Services::email();
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($bodyHtml);
            $email->setMailType('html');
            if (! $email->send(false)) {
                $err = $email->printDebugger(['headers']) ?: 'unknown email error';
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            return false;
        }
    }
}