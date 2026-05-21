<?php

namespace App\Notifications\Channels;

use App\Notifications\NotificationChannel;
use Config\Services;

/**
 * EmailChannel — enqueue ke `email_queue`. Pengiriman aktual oleh
 * `email:process` worker / cron yang sudah ada di NotificationService.
 *
 * Retry strategy: ditangani oleh `processEmailQueue()` dengan exponential
 * backoff (`retry_after` dari migrasi #27): 30s → 2m → 10m → 1h → 6h. Setelah
 * 5x gagal, status di-set FAILED dan tidak retry lagi.
 *
 * Idempotency: payload `notification_id` di-include sebagai bagian dari
 * email body (opsional, untuk dedup di sisi user kalau perlu). Queue insert
 * sendiri tidak dedup — caller (NotificationService) yang harus pastikan
 * tidak panggil send() dua kali untuk notif sama.
 */
class EmailChannel implements NotificationChannel
{
    public function slug(): string
    {
        return 'email';
    }

    public function isAvailable(array $user): bool
    {
        return ! empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL);
    }

    public function send(array $user, array $payload): bool
    {
        try {
            $bodyHtml = $this->renderHtml(
                (string) $payload['title'],
                (string) $payload['message'],
                $payload['link_url'] ?? null,
            );
            Services::notification()->queueEmail(
                companyId: (int) ($payload['company_id'] ?? 0) ?: null,
                toEmail: (string) $user['email'],
                toName: (string) ($user['name'] ?? ''),
                subject: (string) $payload['title'],
                bodyHtml: $bodyHtml,
            );
            return true;
        } catch (\Throwable $e) {
            log_message('warning', 'EmailChannel send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Template email sederhana. Untuk template advance (mis. branded), hook
     * ke `View()` engine di NotificationService::renderEmailHtml() existing.
     */
    private function renderHtml(string $title, string $message, ?string $linkUrl): string
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $messageEsc = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $linkBtn = '';
        if ($linkUrl) {
            $linkUrlEsc = htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8');
            $linkBtn = '<p><a href="' . $linkUrlEsc . '" '
                . 'style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;'
                . 'text-decoration:none;border-radius:6px">Buka di HRIS</a></p>';
        }
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#222">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px">'
            . '<h2 style="color:#1d4ed8;margin:0 0 12px">' . $titleEsc . '</h2>'
            . '<p style="font-size:14px;line-height:1.6">' . $messageEsc . '</p>'
            . $linkBtn
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">'
            . '<p style="font-size:11px;color:#6b7280">'
            . 'Notifikasi otomatis dari HRIS. Atur preferensi notifikasi di Profile → Notifikasi.'
            . '</p></div></body></html>';
    }
}