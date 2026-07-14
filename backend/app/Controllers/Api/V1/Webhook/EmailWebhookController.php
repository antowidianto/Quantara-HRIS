<?php

namespace App\Controllers\Api\V1\Webhook;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Notification\EmailProviders\SesAdapter;
use App\Libraries\Notification\EmailProviders\EmailProviderInterface;
use Config\Database;

/**
 * S2.4 — Email webhook receiver (PUBLIC, signature-verified).
 *
 * Endpoint:
 *   POST /api/v1/webhooks/email/{provider}
 *
 * Provider yang didukung:
 *   - ses        → AWS SES via SNS
 *   - postmark   → Postmark webhook (TODO)
 *   - sendgrid   → Sendgrid event webhook (TODO)
 *
 * Action:
 *   - BOUNCE  (HARD)  → set users.email_bounced_at + email_bounce_reason
 *   - BOUNCE  (SOFT)  → log only (don't suppress; transient)
 *   - COMPLAINT       → set users.email_complaint_at + auto-unsubscribe semua kategori
 *   - DELIVERY        → log only (analytics)
 *
 * Semua event tercatat di tabel `email_events` untuk audit + dashboard.
 */
class EmailWebhookController extends ApiController
{
    /**
     * POST /api/v1/webhooks/email/{provider}
     */
    public function receive(string $provider = 'ses')
    {
        $rawBody = (string) $this->request->getBody();
        if ($rawBody === '') {
            return $this->fail('Empty body', 400, 'EMPTY_BODY');
        }

        $adapter = $this->resolveAdapter($provider);
        if (! $adapter) {
            return $this->fail('Unknown email provider: ' . $provider, 400, 'UNKNOWN_PROVIDER');
        }

        // Signature verification — adapter handles per provider quirk
        $headers = [];
        foreach ($this->request->headers() as $h) {
            $headers[strtolower($h->getName())] = $h->getValueLine();
        }

        if (! $adapter->verifyWebhook($headers, $rawBody)) {
            log_message('warning', "[EmailWebhook] {$provider} signature verification failed");
            return $this->fail('Signature verification failed', 401, 'BAD_SIGNATURE');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->fail('Invalid JSON payload', 400, 'BAD_JSON');
        }

        $event = $adapter->parseWebhookEvent($payload);
        if (! $event) {
            // Bisa jadi SubscriptionConfirmation / event tidak relevan (open/click tanpa user)
            return $this->ok(['ignored' => true]);
        }

        try {
            $processed = $this->processEvent($adapter, $event);
        } catch (\Throwable $e) {
            log_message('error', '[EmailWebhook] processEvent failed: ' . $e->getMessage());
            // Return 200 supaya provider tidak retry (event sudah recorded di log)
            return $this->ok(['received' => true, 'processing_failed' => true]);
        }

        return $this->ok([
            'received'  => true,
            'duplicate' => ! $processed,
            'event'     => $event['event'],
        ]);
    }

    /**
     * Resolve adapter instance per provider name.
     */
    private function resolveAdapter(string $provider): ?EmailProviderInterface
    {
        switch (strtolower($provider)) {
            case 'ses':
            case 'aws':
                return new SesAdapter();
            // case 'postmark': return new PostmarkAdapter();
            // case 'sendgrid': return new SendgridAdapter();
            default:
                return null;
        }
    }

    /**
     * Apply event ke users table + insert ke email_events untuk audit.
     */
    private function processEvent(EmailProviderInterface $adapter, array $event): bool
    {
        $db = Database::connect();
        $email = strtolower(trim((string) $event['email']));
        if ($email === '') {
            return true;
        }

        $providerEventKey = $this->providerEventKey($adapter, $event, $email);

        // Resolve recipient inside one tenant. For legacy untagged messages,
        // fail closed when the same address belongs to multiple companies.
        $user = null;
        $eventCompanyId = (int) ($event['company_id'] ?? 0);
        if ($db->tableExists('users')) {
            $userQuery = $db->table('users')->where('email', $email);
            if ($eventCompanyId > 0) {
                $user = $userQuery->where('company_id', $eventCompanyId)->get()->getRowArray();
            } else {
                $matches = $userQuery->limit(2)->get()->getResultArray();
                if (count($matches) === 1) {
                    $user = $matches[0];
                    $eventCompanyId = (int) ($user['company_id'] ?? 0);
                } elseif (count($matches) > 1) {
                    log_message('warning', '[EmailWebhook] ambiguous cross-tenant recipient; state update skipped');
                }
            }
        }

        // Insert ke email_events
        if ($db->tableExists('email_events')) {
            $row = [
                'company_id'          => $eventCompanyId > 0 ? $eventCompanyId : null,
                'user_id'             => $user['id'] ?? null,
                'provider'            => $adapter->name(),
                'event'               => (string) $event['event'],
                'email'               => $email,
                'bounce_type'         => $event['bounce_type'] ?? null,
                'reason'              => $event['reason']      ?? null,
                'provider_message_id' => $event['provider_message_id'] ?? null,
                'occurred_at'         => $event['occurred_at'] ?? date('Y-m-d H:i:s'),
                'raw_json'            => json_encode($event['raw'] ?? []),
                'created_at'          => date('Y-m-d H:i:s'),
            ];
            if ($db->fieldExists('provider_event_key', 'email_events')) {
                $row['provider_event_key'] = $providerEventKey;
            }

            try {
                $inserted = $db->table('email_events')->insert($row);
                if ($inserted === false) {
                    throw new \RuntimeException('Failed to persist email webhook event');
                }
            } catch (\Throwable $e) {
                $duplicate = $db->fieldExists('provider_event_key', 'email_events')
                    && $db->table('email_events')
                        ->where('provider', $adapter->name())
                        ->where('provider_event_key', $providerEventKey)
                        ->countAllResults() > 0;
                if ($duplicate) {
                    return false;
                }
                throw $e;
            }
        }

        // Apply state change ke users (kalau user ditemukan)
        if (! $user || ! $db->tableExists('users')) {
            return true;
        }

        $update = [];
        switch ($event['event']) {
            case 'BOUNCE':
                // HARD bounce → suppress permanen. SOFT bounce → log only
                if (($event['bounce_type'] ?? '') === 'HARD') {
                    $update = [
                        'email_bounced_at'    => $event['occurred_at'] ?? date('Y-m-d H:i:s'),
                        'email_bounce_reason' => $event['reason'] ?? '',
                        'email_bounce_type'   => 'HARD',
                    ];
                }
                break;

            case 'COMPLAINT':
                // User mark as spam → stop semua email kecuali transactional critical
                $update = [
                    'email_complaint_at'           => $event['occurred_at'] ?? date('Y-m-d H:i:s'),
                    'unsubscribed_categories_json' => json_encode(['marketing', 'newsletter', 'product_update']),
                ];
                break;

            case 'DELIVERY':
                // Reset bounce kalau ada delivery sukses (recovery dari soft bounce)
                if (! empty($user['email_bounced_at']) && ($user['email_bounce_type'] ?? '') === 'SOFT') {
                    $update = [
                        'email_bounced_at'    => null,
                        'email_bounce_reason' => null,
                        'email_bounce_type'   => null,
                    ];
                }
                break;
        }

        if (! empty($update)) {
            $db->table('users')
                ->where('id', $user['id'])
                ->where('company_id', $eventCompanyId)
                ->update($update);
            log_message('info', sprintf(
                '[EmailWebhook] %s/%s applied to user_id=%d email=%s',
                $adapter->name(), $event['event'], $user['id'], $email
            ));
        }

        return true;
    }

    /** Build a stable key from provider identity, with a deterministic fallback. */
    private function providerEventKey(
        EmailProviderInterface $adapter,
        array $event,
        string $email,
    ): string {
        $identity = trim((string) ($event['provider_event_id'] ?? ''));
        if ($identity === '') {
            $identity = implode('|', [
                (string) ($event['provider_message_id'] ?? ''),
                (string) ($event['event'] ?? ''),
                $email,
                (string) ($event['occurred_at'] ?? ''),
            ]);
        }

        return hash('sha256', strtolower($adapter->name()) . '|' . $identity);
    }
}
