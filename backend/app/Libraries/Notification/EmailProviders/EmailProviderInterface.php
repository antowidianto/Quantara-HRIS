<?php

namespace App\Libraries\Notification\EmailProviders;

/**
 * S2.4 — Email Provider abstraction.
 *
 * Adapter pattern memungkinkan switch antara native PHP mailer (default),
 * AWS SES, Postmark, Sendgrid, Mailgun tanpa ubah caller code.
 *
 * Pilih provider via env `EMAIL_PROVIDER` (lihat EmailService::factory).
 */
interface EmailProviderInterface
{
    /**
     * Kirim email. Implementor wajib:
     *   - throw \RuntimeException kalau gagal kirim
     *   - return message ID dari provider untuk audit/tracking
     *
     * @param array $payload {
     *   to: string,
     *   subject: string,
     *   body_html: string,
     *   body_text?: string,
     *   from?: string,
     *   reply_to?: string,
     *   cc?: array<string>,
     *   bcc?: array<string>,
     *   headers?: array<string,string>,
     *   tag?: string,        // untuk filter di provider dashboard
     *   metadata?: array,    // custom metadata (Postmark/Sendgrid)
     *   attachments?: array<{filename, content, mime}>,
     * }
     *
     * @return string Message ID dari provider (atau hash internal)
     */
    public function send(array $payload): string;

    /**
     * Verify webhook dari provider — signature check + HMAC.
     * Return true kalau payload valid (untuk diproses oleh handleBounce).
     */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    /**
     * Parse webhook payload jadi struktur normalized:
     *   {
     *     event: 'BOUNCE'|'COMPLAINT'|'DELIVERY'|'OPEN'|'CLICK',
     *     email: string,
     *     reason?: string,
     *     bounce_type?: 'HARD'|'SOFT',
     *     occurred_at: 'Y-m-d H:i:s',
     *     provider_message_id?: string,
     *     raw: array  // payload mentah untuk audit
     *   }
     *
     * Return null kalau event tidak relevan (mis. spam complaint feedback loop)
     */
    public function parseWebhookEvent(array $payload): ?array;

    /**
     * Identifier provider (untuk log + audit).
     */
    public function name(): string;
}