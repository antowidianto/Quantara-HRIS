<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Notification\EmailProviders\SesAdapter;
use PHPUnit\Framework\TestCase;

final class WebhookIdempotencyPolicyTest extends TestCase
{
    public function testGenericInboundWebhookRequiresProviderEventIdentity(): void
    {
        $controller = file_get_contents(
            APPPATH . 'Controllers/Api/V1/Webhook/InboundWebhookController.php'
        );
        self::assertNotFalse($controller);
        self::assertStringContainsString('SOURCE_EVENT_ID_REQUIRED', $controller);
        self::assertStringContainsString('X-HRIS-Source-Event', $controller);
    }

    public function testSesAdapterPreservesStableSnsEventIdentity(): void
    {
        $message = [
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'ses-message-1'],
            'delivery' => [
                'recipients' => ['employee@example.test'],
                'timestamp' => '2026-07-13T10:00:00Z',
            ],
        ];
        $payload = [
            'Type' => 'Notification',
            'MessageId' => 'sns-event-stable-1',
            'Message' => json_encode($message, JSON_THROW_ON_ERROR),
        ];

        $event = (new SesAdapter())->parseWebhookEvent($payload);

        self::assertNotNull($event);
        self::assertSame('sns-event-stable-1', $event['provider_event_id']);
        self::assertSame('ses-message-1', $event['provider_message_id']);
    }

    public function testEmailEventSchemaHasProviderUniqueKey(): void
    {
        $migration = file_get_contents(
            APPPATH . 'Database/Migrations/2026-09-01-000013_AddEmailEventDeduplication.php'
        );
        self::assertNotFalse($migration);
        self::assertStringContainsString('provider_event_key', $migration);
        self::assertStringContainsString('uniq_email_provider_event', $migration);
    }
}
