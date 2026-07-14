<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FeatureReadinessPolicyTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    public function testUnimplementedSsoIsNotIncludedInAnyPlan(): void
    {
        $seeder = file_get_contents($this->root('backend/app/Database/Seeds/SubscriptionPlanSeeder.php'));

        self::assertIsString($seeder);
        self::assertStringContainsString("'integration.sso'           => [0, 0, 0]", $seeder);
        self::assertStringContainsString('Reserved placeholder: Single Sign-On', $seeder);
        self::assertStringNotContainsString('API key + OAuth 2.0', $seeder);
    }

    public function testWebPushFailsClosedWithoutOptionalDeliveryLibrary(): void
    {
        $channel = file_get_contents($this->root('backend/app/Notifications/Channels/WebPushChannel.php'));

        self::assertIsString($channel);
        self::assertStringContainsString("class_exists('\\Minishlink\\WebPush\\WebPush')", $channel);
        self::assertStringContainsString("class_exists('\\Minishlink\\WebPush\\Subscription')", $channel);
        self::assertMatchesRegularExpression('/class_exists.+?return false;/s', $channel);
        self::assertStringNotContainsString('subscription endpoint belum', strtolower($channel));
    }

    public function testReadinessMatrixAndReadmeDiscloseBetaAndPlaceholderCapabilities(): void
    {
        $matrix = file_get_contents($this->root('docs/02_Business_Features/FEATURE_READINESS_MATRIX_2026-07-14.md'));
        $readme = file_get_contents($this->root('README.md'));

        self::assertIsString($matrix);
        self::assertIsString($readme);
        foreach (['Production-ready', 'Beta', 'Backend only', 'Frontend only', 'Partially wired', 'Feature-flagged', 'Placeholder', 'Deprecated'] as $status) {
            self::assertStringContainsString($status, $matrix);
        }
        self::assertStringContainsString('| SSO SAML/OIDC | Placeholder |', $matrix);
        self::assertStringContainsString('Web Push VAPID beta', $readme);
    }
}
