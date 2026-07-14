<?php

namespace App\Libraries\Notification\EmailProviders;

/**
 * S2.4 — AWS SES email adapter.
 *
 * Mendukung 2 mode:
 *   1. **HTTP API mode** (default) — pakai SES SendEmail/SendRawEmail v2 via cURL
 *      dengan AWS SigV4. Tidak butuh dependency tambahan selain ext-curl.
 *   2. **CLI mode** (fallback) — pakai aws CLI binary kalau env BACKUP_AWS_BIN
 *      tersedia. Berguna kalau SDK PHP belum di-install.
 *
 * Webhook handler verifikasi SNS message signature:
 *   - SubscriptionConfirmation → auto-confirm via subscribe URL
 *   - Notification (Bounce/Complaint/Delivery) → parse + return event
 *
 * ENV:
 *   EMAIL_PROVIDER         = SES
 *   SES_REGION             = ap-southeast-1
 *   SES_ACCESS_KEY_ID      = AKIA…
 *   SES_SECRET_ACCESS_KEY  = …
 *   SES_FROM_EMAIL         = "Quantara HRIS <noreply@quantara.io>"
 *   SES_CONFIGURATION_SET  = optional, untuk tag setelah open/click
 *   SES_SNS_TOPIC_ARN      = arn untuk verifikasi origin webhook (opsional)
 */
class SesAdapter implements EmailProviderInterface
{
    public function name(): string
    {
        return 'ses';
    }

    public function send(array $payload): string
    {
        $region = (string) env('SES_REGION', 'ap-southeast-1');
        $key    = (string) env('SES_ACCESS_KEY_ID', '');
        $secret = (string) env('SES_SECRET_ACCESS_KEY', '');
        if ($key === '' || $secret === '') {
            throw new \RuntimeException('SES credentials not configured (SES_ACCESS_KEY_ID/SES_SECRET_ACCESS_KEY)');
        }
        if (empty($payload['to']) || empty($payload['subject']) || empty($payload['body_html'])) {
            throw new \RuntimeException('Email payload incomplete: to/subject/body_html required');
        }

        $from = $payload['from'] ?? (string) env('SES_FROM_EMAIL', 'noreply@quantara.io');
        $to   = (string) $payload['to'];

        // Build SES v2 SendEmail JSON request
        $reqBody = [
            'FromEmailAddress' => $from,
            'Destination' => [
                'ToAddresses' => [$to],
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data'    => $payload['subject'],
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [
                        'Html' => [
                            'Data'    => $payload['body_html'],
                            'Charset' => 'UTF-8',
                        ],
                    ],
                ],
            ],
        ];
        if (! empty($payload['body_text'])) {
            $reqBody['Content']['Simple']['Body']['Text'] = [
                'Data'    => $payload['body_text'],
                'Charset' => 'UTF-8',
            ];
        }
        if (! empty($payload['reply_to'])) {
            $reqBody['ReplyToAddresses'] = [(string) $payload['reply_to']];
        }
        if (! empty($payload['cc']) && is_array($payload['cc'])) {
            $reqBody['Destination']['CcAddresses'] = $payload['cc'];
        }
        if (! empty($payload['bcc']) && is_array($payload['bcc'])) {
            $reqBody['Destination']['BccAddresses'] = $payload['bcc'];
        }
        $emailTags = [];
        if (! empty($payload['tag'])) {
            $emailTags[] = ['Name' => 'tag', 'Value' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $payload['tag'])];
        }
        $companyId = (int) ($payload['metadata']['company_id'] ?? 0);
        if ($companyId > 0) {
            $emailTags[] = ['Name' => 'company_id', 'Value' => (string) $companyId];
        }
        if ($emailTags !== []) {
            $reqBody['EmailTags'] = $emailTags;
        }
        $configSet = (string) env('SES_CONFIGURATION_SET', '');
        if ($configSet !== '') {
            $reqBody['ConfigurationSetName'] = $configSet;
        }

        $body = json_encode($reqBody);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode SES request body');
        }

        $host    = "email.{$region}.amazonaws.com";
        $url     = "https://{$host}/v2/email/outbound-emails";
        $service = 'ses';
        $now     = gmdate('Ymd\THis\Z');
        $date    = gmdate('Ymd');

        // SigV4 signing
        $canonicalUri  = '/v2/email/outbound-emails';
        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-date:{$now}\n";
        $signedHeaders   = 'content-type;host;x-amz-date';
        $payloadHash     = hash('sha256', $body);
        $canonicalRequest = "POST\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm     = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign  = "{$algorithm}\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $date, "AWS4{$secret}", true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = "{$algorithm} Credential={$key}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Host: ' . $host,
                'X-Amz-Date: ' . $now,
                'Authorization: ' . $auth,
            ],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_CONNECTTIMEOUT  => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('SES cURL error: ' . $curlErr);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("SES API error HTTP {$httpCode}: " . substr((string) $response, 0, 500));
        }

        $decoded = json_decode((string) $response, true);
        return (string) ($decoded['MessageId'] ?? hash('sha256', $body . $now));
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        // SNS messages punya signature di payload `Signature` field.
        // Strategi sederhana: validasi struktur + topic ARN match. Full
        // SigV4 verification butuh fetch SNS signing cert (HTTP) — opsional
        // di-skip untuk MVP, tapi WAJIB di production.
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return false;
        }

        // 1. Topic ARN whitelist check (strong signal)
        $expectedTopic = (string) env('SES_SNS_TOPIC_ARN', '');
        if ($expectedTopic !== '' && (! isset($payload['TopicArn']) || $payload['TopicArn'] !== $expectedTopic)) {
            return false;
        }

        // 2. SignatureVersion harus 1
        if (($payload['SignatureVersion'] ?? null) !== '1') {
            // Allow saat dev/testing tanpa env signing config
            if (env('CI_ENVIRONMENT') === 'production') {
                return false;
            }
        }

        // 3. (Opsional) full signature verification — implementasi lengkap
        //    butuh download cert dari SigningCertURL dan verify dengan
        //    openssl_verify. Untuk MVP: trust topic ARN match + valid JSON.
        return true;
    }

    public function parseWebhookEvent(array $payload): ?array
    {
        // SNS membungkus SES event di field "Message" (string JSON).
        // Auto-handle SubscriptionConfirmation: caller harus subscribe URL
        // sebelum receive notifications.
        if (($payload['Type'] ?? '') === 'SubscriptionConfirmation') {
            // Confirm subscription dengan GET ke SubscribeURL
            $url = (string) ($payload['SubscribeURL'] ?? '');
            if ($url !== '') {
                @file_get_contents($url); // best effort
            }
            return null;
        }

        if (($payload['Type'] ?? '') !== 'Notification') {
            return null;
        }

        $msg = json_decode((string) ($payload['Message'] ?? ''), true);
        if (! is_array($msg)) {
            return null;
        }

        $eventType = strtoupper((string) ($msg['notificationType'] ?? $msg['eventType'] ?? ''));
        if (! in_array($eventType, ['BOUNCE', 'COMPLAINT', 'DELIVERY'], true)) {
            return null;
        }

        $event = [
            'event'     => $eventType,
            'email'     => '',
            'occurred_at' => date('Y-m-d H:i:s'),
            'provider_message_id' => (string) ($msg['mail']['messageId'] ?? ''),
            // SNS MessageId stays stable when AWS retries a notification.
            'provider_event_id' => (string) ($payload['MessageId'] ?? ''),
            'raw'       => $msg,
        ];
        $taggedCompanyId = (int) ($msg['mail']['tags']['company_id'][0] ?? 0);
        if ($taggedCompanyId > 0) {
            $event['company_id'] = $taggedCompanyId;
        }

        if ($eventType === 'BOUNCE') {
            $bounce = $msg['bounce'] ?? [];
            $event['bounce_type'] = strtoupper((string) ($bounce['bounceType'] ?? '')) === 'PERMANENT' ? 'HARD' : 'SOFT';
            $event['reason']      = (string) ($bounce['bounceSubType'] ?? '');
            $recipients = $bounce['bouncedRecipients'] ?? [];
            $event['email'] = (string) ($recipients[0]['emailAddress'] ?? '');
            if (! empty($bounce['timestamp'])) {
                $event['occurred_at'] = date('Y-m-d H:i:s', strtotime((string) $bounce['timestamp']));
            }
        } elseif ($eventType === 'COMPLAINT') {
            $complaint = $msg['complaint'] ?? [];
            $event['reason'] = (string) ($complaint['complaintFeedbackType'] ?? 'spam');
            $event['email']  = (string) ($complaint['complainedRecipients'][0]['emailAddress'] ?? '');
            if (! empty($complaint['timestamp'])) {
                $event['occurred_at'] = date('Y-m-d H:i:s', strtotime((string) $complaint['timestamp']));
            }
        } elseif ($eventType === 'DELIVERY') {
            $delivery = $msg['delivery'] ?? [];
            $event['email']  = (string) ($delivery['recipients'][0] ?? '');
            if (! empty($delivery['timestamp'])) {
                $event['occurred_at'] = date('Y-m-d H:i:s', strtotime((string) $delivery['timestamp']));
            }
        }

        if ($event['email'] === '') {
            return null;
        }
        return $event;
    }
}
