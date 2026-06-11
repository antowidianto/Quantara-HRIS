<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * S1.2 — Trial Expiry Notification (cron daily).
 *
 * Kirim email reminder ke tenant TRIAL pada interval H-7, H-3, H-1 sebelum
 * trial_ends_at. Tujuan: menaikkan trial-to-paid conversion (target ≥ 25%).
 *
 * Dedup logic: track via subscription_events.event_type = 'TRIAL_REMINDER_SENT'
 * dengan metadata `days_before` supaya tiap interval hanya kirim sekali.
 *
 * Usage:
 *   php spark billing:trial-expiry-notify
 *   php spark billing:trial-expiry-notify --dry-run
 *
 * Recommended cron:
 *   0 9 * * * cd /var/www/hris && php spark billing:trial-expiry-notify
 *   (jam 09:00 supaya email datang waktu kerja)
 */
class BillingTrialExpiryNotify extends BaseCommand
{
    protected $group       = 'Billing';
    protected $name        = 'billing:trial-expiry-notify';
    protected $description = 'Email reminder H-7/H-3/H-1 trial expiry (S1.2 Roadmap SaaS)';
    protected $usage       = 'billing:trial-expiry-notify [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Simulasi saja, tidak commit / tidak kirim email',
    ];

    /**
     * Interval reminder dalam hari sebelum trial expire.
     * H-7 = early warning, H-3 = mid push, H-1 = last call.
     */
    private const REMINDER_DAYS = [7, 3, 1];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null;
        $now = date('Y-m-d H:i:s');

        CLI::write('[' . $now . '] Starting trial expiry notification' . ($dryRun ? ' (DRY-RUN)' : ''), 'cyan');

        $totalSent = 0;
        foreach (self::REMINDER_DAYS as $daysBefore) {
            $sent = $this->processInterval($daysBefore, $dryRun);
            CLI::write(sprintf('  H-%d: %d notification(s)', $daysBefore, $sent), 'white');
            $totalSent += $sent;
        }

        CLI::newLine();
        CLI::write("Total sent: {$totalSent} reminder(s)", 'green');

        if (! $dryRun) {
            $this->writeAudit('billing.trial_reminder_batch', [
                'total_sent' => $totalSent,
                'intervals'  => self::REMINDER_DAYS,
            ]);
        }
    }

    /**
     * Cari subscription TRIAL dengan trial_ends_at = today + N days,
     * yang belum pernah di-reminder pada interval N.
     */
    private function processInterval(int $daysBefore, bool $dryRun): int
    {
        $db = Database::connect();
        if (! $db->tableExists('subscriptions')) return 0;

        $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
        $now = date('Y-m-d H:i:s');

        // Subquery: subscription IDs yang sudah di-reminder utk interval ini
        $sentIds = [];
        if ($db->tableExists('subscription_events')) {
            $rows = $db->table('subscription_events')
                ->select('subscription_id')
                ->where('event_type', 'TRIAL_REMINDER_SENT')
                ->like('note', "days_before={$daysBefore}")
                ->get()->getResultArray();
            $sentIds = array_column($rows, 'subscription_id');
        }

        $builder = $db->table('subscriptions s')
            ->select('s.id, s.company_id, s.trial_ends_at, c.name AS company_name, c.email AS company_email,
                      u.email AS admin_email, u.name AS admin_name')
            ->join('companies c', 'c.id = s.company_id', 'left')
            ->join('users u', 'u.company_id = s.company_id AND u.role_id = 1 AND u.is_active = 1', 'left')
            ->where('s.status', 'TRIAL')
            ->where('DATE(s.trial_ends_at)', $targetDate);
        if (! empty($sentIds)) {
            $builder->whereNotIn('s.id', $sentIds);
        }
        $rows = $builder->get()->getResultArray();

        if (empty($rows)) return 0;

        $sent = 0;
        // Track unique subscription IDs supaya satu sub tidak di-flag ganda
        // kalau ada multi user (LEFT JOIN bikin row duplikat per admin user)
        $processed = [];
        foreach ($rows as $row) {
            $subId = (int) $row['id'];
            if (in_array($subId, $processed, true)) continue;
            $processed[] = $subId;

            $email = $row['admin_email'] ?: $row['company_email'];
            if (! $email) {
                CLI::write("  [skip] sub#{$subId} no admin email", 'yellow');
                continue;
            }

            CLI::write("  • sub#{$subId} {$row['company_name']} → {$email}", 'white');

            if ($dryRun) {
                $sent++;
                continue;
            }

            try {
                $this->sendEmail($email, (string) ($row['admin_name'] ?: $row['company_name']), $daysBefore, $row['trial_ends_at']);
            } catch (\Throwable $e) {
                CLI::write("    [warn] email failed: {$e->getMessage()}", 'yellow');
                // Tidak fatal — tetap mark supaya tidak retry tiap hari
            }

            // Mark sent supaya tidak di-resend besok
            $db->table('subscription_events')->insert([
                'subscription_id' => $subId,
                'event_type'      => 'TRIAL_REMINDER_SENT',
                'note'            => "days_before={$daysBefore} email={$email}",
                'created_at'      => $now,
            ]);

            $sent++;
        }
        return $sent;
    }

    private function sendEmail(string $to, string $name, int $daysBefore, string $trialEndsAt): void
    {
        $subject = $this->subjectForInterval($daysBefore);
        $body    = $this->bodyForInterval($daysBefore, $name, $trialEndsAt);

        $emailService = \Config\Services::email();
        $emailService->setFrom(
            (string) (env('email.fromEmail') ?: 'noreply@quantara.io'),
            'Quantara HRIS',
        );
        $emailService->setTo($to);
        $emailService->setSubject($subject);
        $emailService->setMessage($body);
        $emailService->setMailType('html');
        if (! $emailService->send(false)) {
            throw new \RuntimeException('SMTP send failed');
        }
    }

    private function subjectForInterval(int $days): string
    {
        return match ($days) {
            7  => 'Trial Anda berakhir 7 hari lagi — jangan kehilangan akses HR otomasi',
            3  => 'Trial Anda berakhir 3 hari lagi — upgrade sekarang biar payroll tetap jalan',
            1  => '[Action Required] Trial Anda habis besok',
            default => 'Trial Quantara HRIS akan berakhir',
        };
    }

    private function bodyForInterval(int $days, string $name, string $trialEndsAt): string
    {
        $endsFmt = date('d M Y', strtotime($trialEndsAt));
        $appUrl = rtrim((string) (env('app.frontendURL') ?: env('FRONTEND_URL') ?: site_url()), '/');
        $billingUrl = $appUrl . '/billing/plan';
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        $intro = match ($days) {
            7  => 'Trial 14 hari Anda akan berakhir <strong>7 hari lagi</strong>. Pilih plan berbayar sekarang supaya transisi mulus tanpa gangguan operasional.',
            3  => 'Tinggal <strong>3 hari lagi</strong> sebelum trial habis. Tenant Anda akan masuk mode read-only kalau belum upgrade — payroll & approval tidak bisa diproses.',
            1  => 'Trial Anda <strong>berakhir besok</strong> (' . htmlspecialchars($endsFmt, ENT_QUOTES, 'UTF-8') . '). Upgrade sekarang untuk menghindari lock-out, atau aktifkan ulang nanti dengan billing tertunggak.',
            default => 'Trial Quantara HRIS Anda akan berakhir.',
        };

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<body style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; color: #333;">
  <h2 style="color: #2563eb;">Quantara HRIS</h2>
  <p>Halo <strong>{$safeName}</strong>,</p>
  <p>{$intro}</p>
  <p style="text-align: center; margin: 32px 0;">
    <a href="{$billingUrl}" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
      Pilih Plan Berbayar
    </a>
  </p>
  <p style="color: #666; font-size: 13px;">
    Quantara mendukung pembayaran via transfer bank, virtual account (Midtrans/Xendit), atau invoice korporat (PO) untuk pelanggan ENTERPRISE.
  </p>
  <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
  <p style="color: #999; font-size: 12px;">
    Quantara HRIS · HR & Payroll otomatis · PMK 168, UU PDP, BPJS compliant
  </p>
</body>
</html>
HTML;
    }

    private function writeAudit(string $event, array $metadata): void
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
                'severity'      => 'NOTICE',
                'metadata_json' => json_encode($metadata),
                'ip_address'    => 'cli',
                'user_agent'    => 'spark:billing:trial-expiry-notify',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[BillingTrialExpiryNotify] audit failed: ' . $e->getMessage());
        }
    }
}