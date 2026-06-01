<?php

namespace App\Libraries\Notification;

use App\Notifications\NotificationChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;
use Config\Services;

/**
 * NotificationService - dispatch notifikasi ke multiple channel berdasar user prefs.
 *
 * Architecture (Sprint 4 item K):
 *   1. send() insert ke tabel `notifications` (in-app, ground truth)
 *   2. Resolve channels yang aktif untuk user + notif type, intersect dengan
 *      channel yang available untuk user itu
 *   3. Dispatch ke setiap channel via NotificationChannel::send()
 *   4. Channel gagal di-log saja, tidak gagalkan keseluruhan operasi
 *
 * Default channel set per type ada di method `defaultChannelsFor()`. User bisa
 * override via tabel `user_notification_preferences`.
 */
class NotificationService
{
    /** @var NotificationChannel[]|null Cache channels (sekali instantiate per request) */
    private ?array $channels = null;

    /**
     * Send a notification to a user. Channel dispatch (email/WA/push) ditentukan
     * oleh preference user di tabel user_notification_preferences. Kalau user
     * belum punya pref, fallback ke default per notif type.
     *
     * Param `$sendEmail` (legacy, deprecated): kalau true, paksa email channel
     * walaupun user disable. Untuk backward-compat dengan caller existing.
     */
    public function send(
        int $userId,
        int $companyId,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?string $linkUrl = null,
        bool $sendEmail = false
    ): int {
        $db = \Config\Database::connect();

        $now = date('Y-m-d H:i:s');
        $db->table('notifications')->insert([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'data'       => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'link_url'   => $linkUrl,
            'is_read'    => 0,
            'sent_email' => 0,
            'created_at' => $now,
        ]);
        $notifId = (int) $db->insertID();

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user) {
            return $notifId;
        }

        $payload = [
            'notification_id' => $notifId,
            'notif_type'      => $type,
            'title'           => $title,
            'message'         => $message,
            'data'            => $data,
            'link_url'        => $linkUrl,
            'company_id'      => $companyId,
        ];

        $channels = $this->resolveChannelsFor($userId, $type, $sendEmail);
        $sentChannels = [];
        foreach ($channels as $channel) {
            if (! $channel->isAvailable($user)) continue;
            try {
                if ($channel->send($user, $payload)) {
                    $sentChannels[] = $channel->slug();
                }
            } catch (\Throwable $e) {
                log_message('warning', 'Notif dispatch via ' . $channel->slug() . ' gagal: ' . $e->getMessage());
            }
        }

        // Update flag sent_email kalau email channel sukses (backward-compat).
        if (in_array('email', $sentChannels, true)) {
            $db->table('notifications')->where('id', $notifId)->update([
                'sent_email'    => 1,
                'email_sent_at' => $now,
            ]);
        }

        return $notifId;
    }

    /**
     * Tentukan channel mana yang aktif untuk user+type. In-app selalu aktif.
     * Email/WA/push: cek user prefs, fallback ke default per type.
     *
     * @param bool $forceEmail Override pref email (untuk backward-compat caller lama)
     * @return NotificationChannel[]
     */
    private function resolveChannelsFor(int $userId, string $type, bool $forceEmail = false): array
    {
        $defaults = $this->defaultChannelsFor($type);
        if ($forceEmail && ! in_array('email', $defaults, true)) {
            $defaults[] = 'email';
        }

        // Lookup overrides di user_notification_preferences. Cari yang
        // notif_type=$type dulu, lalu fallback ke notif_type='*' kalau tidak ada.
        $db = \Config\Database::connect();
        $prefs = [];
        try {
            $rows = $db->table('user_notification_preferences')
                ->where('user_id', $userId)
                ->whereIn('notif_type', [$type, '*'])
                ->get()->getResultArray();
            foreach ($rows as $r) {
                // Specific (notif_type=$type) menang atas wildcard '*'
                $key = $r['channel'];
                if (isset($prefs[$key]) && $prefs[$key]['notif_type'] === $type) continue;
                $prefs[$key] = $r;
            }
        } catch (\Throwable $e) {
            // Tabel belum migrate — pakai defaults saja
        }

        $enabledSlugs = [];
        foreach ($this->channels() as $channel) {
            $slug = $channel->slug();
            // In-app selalu di-include (tidak boleh di-disable)
            if ($slug === 'in_app') {
                $enabledSlugs[] = $slug;
                continue;
            }
            if (isset($prefs[$slug])) {
                if ((int) $prefs[$slug]['enabled'] === 1) $enabledSlugs[] = $slug;
            } elseif (in_array($slug, $defaults, true)) {
                $enabledSlugs[] = $slug;
            }
        }

        // Filter ke instance channel sesuai enabled slugs.
        return array_values(array_filter(
            $this->channels(),
            fn ($c) => in_array($c->slug(), $enabledSlugs, true),
        ));
    }

    /**
     * Default channel set per notif type. Bisa di-override di setting per company.
     * Fallback '*' default = in_app saja.
     *
     * @return string[] list slug channel
     */
    private function defaultChannelsFor(string $type): array
    {
        // Pola: <module>.<action> → set channel default
        $rules = [
            'leave.requested'         => ['in_app', 'email'],
            'overtime.requested'      => ['in_app', 'email'],
            'attendance.change_requested' => ['in_app', 'email'],
            'approval.pending'        => ['in_app', 'email', 'whatsapp'],
            'leave.approved'          => ['in_app', 'email'],
            'leave.rejected'          => ['in_app', 'email'],
            'overtime.approved'       => ['in_app', 'email'],
            'overtime.rejected'       => ['in_app', 'email'],
            'payslip.published'       => ['in_app', 'email'],
            'contract.expiring'       => ['in_app', 'email'],
            'cert.expiring'           => ['in_app', 'email'],
            'sp.issued'               => ['in_app', 'email'],
        ];
        return $rules[$type] ?? ['in_app'];
    }

    /** @return NotificationChannel[] */
    private function channels(): array
    {
        if ($this->channels === null) {
            $this->channels = [
                new InAppChannel(),
                new EmailChannel(),
                new WhatsAppChannel(),
                new WebPushChannel(),
            ];
        }
        return $this->channels;
    }

    /** Set preference channel untuk user + notif type. Idempotent (UPSERT). */
    public function setPreference(int $userId, string $notifType, string $channel, bool $enabled): void
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $exists = $db->table('user_notification_preferences')
            ->where('user_id', $userId)
            ->where('notif_type', $notifType)
            ->where('channel', $channel)
            ->countAllResults();
        if ($exists > 0) {
            $db->table('user_notification_preferences')
                ->where('user_id', $userId)
                ->where('notif_type', $notifType)
                ->where('channel', $channel)
                ->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => $now]);
        } else {
            $db->table('user_notification_preferences')->insert([
                'user_id'    => $userId,
                'notif_type' => $notifType,
                'channel'    => $channel,
                'enabled'    => $enabled ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Get all preferences for a user (untuk UI Profile → Notifikasi). */
    public function getPreferences(int $userId): array
    {
        $db = \Config\Database::connect();
        return $db->table('user_notification_preferences')
            ->where('user_id', $userId)
            ->orderBy('notif_type', 'ASC')
            ->orderBy('channel', 'ASC')
            ->get()->getResultArray();
    }

    public function queueEmail(?int $companyId, string $toEmail, ?string $toName, string $subject, string $bodyHtml, array $attachments = []): int
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('email_queue')->insert([
            'company_id'  => $companyId,
            'to_email'    => $toEmail,
            'to_name'     => $toName,
            'subject'     => $subject,
            'body_html'   => $bodyHtml,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'status'      => 'QUEUED',
            'attempts'    => 0,
            'scheduled_at'=> $now,
            // Sprint 7 — kolom retry tracking. Default null (boleh kirim sekarang).
            'next_retry_at'   => null,
            'last_attempt_at' => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        return (int) $db->insertID();
    }

    /**
     * Process the email queue. Run via cron or `php spark email:process`.
     *
     * Sprint 7 hardening:
     *  - Pakai `App\Libraries\System\EmailQueueBackoff` untuk schedule retry
     *    dengan exponential backoff + jitter (1m → 5m → 30m).
     *  - Skip row yang `next_retry_at` masih di masa depan.
     *  - Set `next_retry_at` saat fail; saat attempt habis (≥ MAX_ATTEMPTS),
     *    biarkan FAILED final (tidak akan di-retry lagi).
     */
    public function processEmailQueue(int $batch = 20): int
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('email_queue')
            ->whereIn('status', ['QUEUED', 'FAILED'])
            ->where('attempts <', \App\Libraries\System\EmailQueueBackoff::MAX_ATTEMPTS)
            ->where('scheduled_at <=', $now)
            // next_retry_at NULL atau ≤ now (boleh retry sekarang)
            ->groupStart()
                ->where('next_retry_at IS NULL')
                ->orWhere('next_retry_at <=', $now)
            ->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit($batch)
            ->get()
            ->getResultArray();

        $sent = 0;
        foreach ($rows as $row) {
            $newAttempt = (int) $row['attempts'] + 1;
            $db->table('email_queue')->where('id', $row['id'])->update([
                'status'          => 'SENDING',
                'attempts'        => $newAttempt,
                'last_attempt_at' => $now,
            ]);
            try {
                $this->dispatch($row);
                $db->table('email_queue')->where('id', $row['id'])->update([
                    'status'        => 'SENT',
                    'sent_at'       => date('Y-m-d H:i:s'),
                    'next_retry_at' => null,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                // Schedule next retry kalau masih ada budget attempts.
                // Kalau sudah habis, set status FAILED final tanpa next_retry_at.
                $exhausted = $newAttempt >= \App\Libraries\System\EmailQueueBackoff::MAX_ATTEMPTS;
                $update = [
                    'status'     => 'FAILED',
                    'last_error' => substr($e->getMessage(), 0, 1000),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if (! $exhausted) {
                    $update['next_retry_at'] = \App\Libraries\System\EmailQueueBackoff::nextRetryAt($newAttempt);
                } else {
                    $update['next_retry_at'] = null;  // tidak retry lagi
                }
                $db->table('email_queue')->where('id', $row['id'])->update($update);
            }
        }
        return $sent;
    }

    private function dispatch(array $row): void
    {
        // Resolve config: per-company DB setting (encrypted password) →
        // env fallback. Setting di DB di-set lewat
        // `EmailSettingsController::update` (UI Admin → Email Settings).
        $cfg = $this->resolveSmtpConfig($row['company_id'] ? (int) $row['company_id'] : null);

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $cfg['host'];
        $mailer->Port       = $cfg['port'];
        $mailer->SMTPAuth   = $cfg['username'] !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $cfg['username'];
            $mailer->Password = $cfg['password'];
        }
        if ($cfg['encryption'] === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cfg['encryption'] === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($cfg['from_address'], $cfg['from_name']);
        $mailer->addAddress($row['to_email'], $row['to_name'] ?? '');
        $mailer->Subject = $row['subject'];
        $mailer->isHTML(true);
        $mailer->Body    = $row['body_html'];
        $mailer->AltBody = strip_tags($row['body_html']);

        if (! empty($row['attachments'])) {
            $atts = json_decode($row['attachments'], true) ?: [];
            foreach ($atts as $att) {
                if (isset($att['path']) && is_file($att['path'])) {
                    $mailer->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                }
            }
        }
        $mailer->send();
    }

    /**
     * Resolve SMTP config: per-company DB setting (paling spesifik) →
     * env fallback. Password di DB di-encrypt at-rest pakai
     * `Services::encryption()`; di-decrypt baru saat send.
     *
     * Field di tabel `settings` (key prefix `email.`):
     *   - email.host
     *   - email.port
     *   - email.username
     *   - email.password_enc  (encrypted)
     *   - email.encryption    (tls|ssl|none)
     *   - email.from_address
     *   - email.from_name
     *
     * Setting di-edit dari frontend Admin → Email Settings.
     */
    private function resolveSmtpConfig(?int $companyId): array
    {
        $cfg = [
            'host'         => (string) env('mail.host', 'smtp.gmail.com'),
            'port'         => (int) env('mail.port', 587),
            'username'     => (string) env('mail.username', ''),
            'password'     => (string) env('mail.password', ''),
            'encryption'   => strtolower((string) env('mail.encryption', 'tls')),
            'from_address' => (string) env('mail.fromAddress', 'noreply@hris.local'),
            'from_name'    => (string) env('mail.fromName', 'HRIS'),
        ];
        if (! $companyId) return $cfg;

        $db = \Config\Database::connect();
        if (! $db->tableExists('settings')) return $cfg;
        $rows = $db->table('settings')
            ->where('company_id', $companyId)
            ->whereIn('key', [
                'email.host','email.port','email.username','email.password_enc',
                'email.encryption','email.from_address','email.from_name',
            ])
            ->get()->getResultArray();
        $kv = array_column($rows, 'value', 'key');

        if (isset($kv['email.host'])         && $kv['email.host']         !== '') $cfg['host']         = $kv['email.host'];
        if (isset($kv['email.port'])         && $kv['email.port']         !== '') $cfg['port']         = (int) $kv['email.port'];
        if (isset($kv['email.username'])     && $kv['email.username']     !== '') $cfg['username']     = $kv['email.username'];
        if (isset($kv['email.encryption'])   && $kv['email.encryption']   !== '') $cfg['encryption']   = strtolower($kv['email.encryption']);
        if (isset($kv['email.from_address']) && $kv['email.from_address'] !== '') $cfg['from_address'] = $kv['email.from_address'];
        if (isset($kv['email.from_name'])    && $kv['email.from_name']    !== '') $cfg['from_name']    = $kv['email.from_name'];

        // Password decrypt — kalau gagal, fallback ke env supaya email
        // tidak tiba-tiba mati gara-gara key rotation issue.
        if (! empty($kv['email.password_enc'])) {
            $plain = Services::encryption()->decrypt($kv['email.password_enc']);
            if ($plain !== null && $plain !== '') {
                $cfg['password'] = $plain;
            }
        }
        return $cfg;
    }

    private function renderEmailHtml(string $title, string $message, ?string $linkUrl): string
    {
        $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $linkHtml = '';
        if ($linkUrl) {
            $safeLink = htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8');
            $linkHtml = "<p><a href=\"{$safeLink}\" style=\"display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;\">Buka di HRIS</a></p>";
        }
        return <<<HTML
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><title>{$safeTitle}</title></head>
<body style="font-family: Arial, sans-serif; background:#f3f4f6; padding:24px;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px 0;color:#111827;">{$safeTitle}</h2>
    <p style="color:#374151;line-height:1.5;">{$safeMessage}</p>
    {$linkHtml}
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="color:#9ca3af;font-size:12px;">Email otomatis dari sistem HRIS. Mohon tidak membalas email ini.</p>
  </div>
</body>
</html>
HTML;
    }
}