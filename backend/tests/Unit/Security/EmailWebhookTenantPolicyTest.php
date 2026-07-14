<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Notification\EmailProviders\SesAdapter;
use PHPUnit\Framework\TestCase;

final class EmailWebhookTenantPolicyTest extends TestCase
{
    public function testSesCompanyTagSurvivesWebhookNormalization(): void
    {
        $message = [
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => 'ses-message-1',
                'tags' => ['company_id' => ['42']],
            ],
            'delivery' => [
                'recipients' => ['shared@example.test'],
                'timestamp' => '2026-07-13T00:00:00Z',
            ],
        ];
        $event = (new SesAdapter())->parseWebhookEvent([
            'Type' => 'Notification',
            'MessageId' => 'sns-event-1',
            'Message' => json_encode($message),
        ]);

        self::assertSame(42, $event['company_id']);
        self::assertSame('shared@example.test', $event['email']);
    }

    public function testControllerFailsClosedForAmbiguousLegacyRecipientAndScopesUpdates(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Webhook/EmailWebhookController.php');
        self::assertIsString($source);
        self::assertStringContainsString('count($matches) > 1', $source);
        self::assertStringContainsString("->where('company_id', \$eventCompanyId)", $source);
        self::assertStringContainsString('state update skipped', $source);
    }

    public function testNotificationDispatchRequiresRecipientCompanyMatch(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Notification/NotificationService.php');
        self::assertIsString($source);
        self::assertStringContainsString("->where('id', \$userId)", $source);
        self::assertStringContainsString("->where('company_id', \$companyId)", $source);
        self::assertStringContainsString('recipient does not belong to the requested tenant', $source);
    }
}
