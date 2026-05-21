<?php

namespace App\Libraries;

use Config\Services;

/**
 * NotificationService - sends in-app + (optionally) email notifications.
 */
class NotificationService
{
    /**
     * Send a notification to a user. Optionally also enqueue an email.
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

        if ($sendEmail) {
            $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
            if ($user && ! empty($user['email'])) {
                $this->queueEmail($companyId, $user['email'], $user['name'], $title, $this->renderEmailHtml($title, $message, $linkUrl));
                $db->table('notifications')->where('id', $notifId)->update([
                    'sent_email'    => 1,
                    'email_sent_at' => $now,
                ]);
            }
        }

        return $notifId;
    }

    public function queueEmail(?int $companyId, string $toEmail, ?string $toName, string $subject, string $bodyHtml, array $attachments = []): int
    {
        $db = \Config\Database::connect();
        $db->table('email_queue')->insert([
            'company_id'  => $companyId,
            'to_email'    => $toEmail,
            'to_name'     => $toName,
            'subject'     => $subject,
            'body_html'   => $bodyHtml,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'status'      => 'QUEUED',
            'attempts'    => 0,
            'scheduled_at'=> date('Y-m-d H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    /**
     * Process the email queue. Run via cron or `php spark email:process`.
     */
    public function processEmailQueue(int $batch = 20): int
    {
        $db = \Config\Database::connect();
        $rows = $db->table('email_queue')
            ->whereIn('status', ['QUEUED', 'FAILED'])
            ->where('attempts <', 3)
            ->where('scheduled_at <=', date('Y-m-d H:i:s'))
            ->orderBy('id', 'ASC')
            ->limit($batch)
            ->get()
            ->getResultArray();

        $sent = 0;
        foreach ($rows as $row) {
            $db->table('email_queue')->where('id', $row['id'])->update([
                'status'   => 'SENDING',
                'attempts' => (int) $row['attempts'] + 1,
            ]);
            try {
                $this->dispatch($row);
                $db->table('email_queue')->where('id', $row['id'])->update([
                    'status'   => 'SENT',
                    'sent_at'  => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $db->table('email_queue')->where('id', $row['id'])->update([
                    'status'    => 'FAILED',
                    'last_error'=> substr($e->getMessage(), 0, 1000),
                    'updated_at'=> date('Y-m-d H:i:s'),
                ]);
            }
        }
        return $sent;
    }

    private function dispatch(array $row): void
    {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = (string) env('mail.host', 'smtp.gmail.com');
        $mailer->Port       = (int) env('mail.port', 587);
        $mailer->SMTPAuth   = true;
        $mailer->Username   = (string) env('mail.username', '');
        $mailer->Password   = (string) env('mail.password', '');
        $smtpEnc = (string) env('mail.encryption', 'tls');
        if ($smtpEnc === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpEnc === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom((string) env('mail.fromAddress', 'noreply@hris.local'), (string) env('mail.fromName', 'HRIS'));
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