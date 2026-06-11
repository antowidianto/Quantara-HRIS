<?php

namespace Tests\Unit;

use App\Libraries\Billing\FeatureGateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk FeatureGateService::resolvePure (S1.1 Roadmap SaaS).
 *
 * Test fokus pada hierarki resolusi:
 *   1. Override aktif → menang
 *   2. Plan → menang kalau tidak ada override aktif
 *   3. Default registry → fallback terakhir
 *
 * Run: vendor/bin/phpunit --filter FeatureGateServiceTest
 */
class FeatureGateServiceTest extends TestCase
{
    public function testDefaultRegistryWhenNoOverrideNoPlan(): void
    {
        $r = FeatureGateService::resolvePure(
            overrides: [],
            planFeatures: [],
            defaults: ['payroll.basic' => true, 'ai.copilot' => false],
        );

        $this->assertSame('DEFAULT', $r['payroll.basic']['source']);
        $this->assertTrue($r['payroll.basic']['enabled']);
        $this->assertFalse($r['ai.copilot']['enabled']);
    }

    public function testPlanOverridesDefault(): void
    {
        $r = FeatureGateService::resolvePure(
            overrides: [],
            planFeatures: [
                'ai.copilot' => ['is_included' => true, 'quota_value' => null],
            ],
            defaults: ['ai.copilot' => false],
        );

        $this->assertSame('PLAN', $r['ai.copilot']['source']);
        $this->assertTrue($r['ai.copilot']['enabled']);
    }

    public function testOverrideWinsOverPlan(): void
    {
        $r = FeatureGateService::resolvePure(
            overrides: [
                'ai.copilot' => ['is_enabled' => true, 'quota_value' => 100, 'expires_at' => null],
            ],
            planFeatures: [
                'ai.copilot' => ['is_included' => false, 'quota_value' => null],
            ],
            defaults: ['ai.copilot' => false],
        );

        $this->assertSame('OVERRIDE', $r['ai.copilot']['source']);
        $this->assertTrue($r['ai.copilot']['enabled']);
        $this->assertSame(100, $r['ai.copilot']['quota_value']);
    }

    public function testExpiredOverrideFallsBackToPlan(): void
    {
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $r = FeatureGateService::resolvePure(
            overrides: [
                'ai.copilot' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => $yesterday],
            ],
            planFeatures: [
                'ai.copilot' => ['is_included' => false, 'quota_value' => null],
            ],
            defaults: ['ai.copilot' => false],
        );

        // Override sudah expired → fallback ke plan
        $this->assertSame('PLAN', $r['ai.copilot']['source']);
        $this->assertFalse($r['ai.copilot']['enabled']);
    }

    public function testFutureExpiringOverrideStillActive(): void
    {
        $tomorrow = date('Y-m-d H:i:s', strtotime('+1 day'));
        $r = FeatureGateService::resolvePure(
            overrides: [
                'feat.x' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => $tomorrow],
            ],
            planFeatures: [],
            defaults: ['feat.x' => false],
        );

        $this->assertSame('OVERRIDE', $r['feat.x']['source']);
        $this->assertTrue($r['feat.x']['enabled']);
    }

    public function testOverrideCanForceDisableEvenIfPlanIncludes(): void
    {
        // Skenario: super admin force-disable fitur saat insiden security
        $r = FeatureGateService::resolvePure(
            overrides: [
                'integration.api_access' => ['is_enabled' => false, 'quota_value' => null, 'expires_at' => null],
            ],
            planFeatures: [
                'integration.api_access' => ['is_included' => true, 'quota_value' => null],
            ],
            defaults: ['integration.api_access' => false],
        );

        $this->assertSame('OVERRIDE', $r['integration.api_access']['source']);
        $this->assertFalse($r['integration.api_access']['enabled']);
    }

    public function testCustomNowParameter(): void
    {
        // Override expired di now=tomorrow tapi masih aktif di now=today
        $tomorrow = date('Y-m-d H:i:s', strtotime('+1 day'));

        $r1 = FeatureGateService::resolvePure(
            overrides: [
                'feat.y' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => $tomorrow],
            ],
            planFeatures: [],
            defaults: ['feat.y' => false],
            now: date('Y-m-d H:i:s'), // today — override masih aktif
        );
        $this->assertSame('OVERRIDE', $r1['feat.y']['source']);

        $r2 = FeatureGateService::resolvePure(
            overrides: [
                'feat.y' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => $tomorrow],
            ],
            planFeatures: [],
            defaults: ['feat.y' => false],
            now: date('Y-m-d H:i:s', strtotime('+2 days')), // 2 hari lagi — sudah expired
        );
        $this->assertSame('DEFAULT', $r2['feat.y']['source']);
    }

    public function testQuotaValuePropagation(): void
    {
        $r = FeatureGateService::resolvePure(
            overrides: [],
            planFeatures: [
                'feat.metered' => ['is_included' => true, 'quota_value' => 5000],
            ],
            defaults: [],
        );
        $this->assertSame(5000, $r['feat.metered']['quota_value']);
    }

    public function testMixedFeaturesIndependent(): void
    {
        $r = FeatureGateService::resolvePure(
            overrides: [
                'feat.a' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => null],
            ],
            planFeatures: [
                'feat.b' => ['is_included' => true, 'quota_value' => null],
                'feat.c' => ['is_included' => false, 'quota_value' => null],
            ],
            defaults: [
                'feat.a' => false,
                'feat.b' => false,
                'feat.c' => true,
                'feat.d' => true,
            ],
        );

        $this->assertSame('OVERRIDE', $r['feat.a']['source']);
        $this->assertSame('PLAN', $r['feat.b']['source']);
        $this->assertSame('PLAN', $r['feat.c']['source']);
        $this->assertSame('DEFAULT', $r['feat.d']['source']);

        $this->assertTrue($r['feat.a']['enabled']);
        $this->assertTrue($r['feat.b']['enabled']);
        $this->assertFalse($r['feat.c']['enabled']);
        $this->assertTrue($r['feat.d']['enabled']);
    }
}