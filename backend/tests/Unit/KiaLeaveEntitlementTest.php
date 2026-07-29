<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Leave\LeavePolicyService;
use PHPUnit\Framework\TestCase;

final class KiaLeaveEntitlementTest extends TestCase
{
    private LeavePolicyService $service;

    protected function setUp(): void
    {
        /** Method yang diuji pure dan tidak menggunakan repository. */
        $this->service = (new \ReflectionClass(LeavePolicyService::class))
            ->newInstanceWithoutConstructor();
    }

    public function testMaternityBaseAndConditionalMaximum(): void
    {
        $base = $this->service->kiaEntitlement('MATERNITY');
        self::assertSame('MONTH', $base['duration_unit']);
        self::assertSame(3, $base['base_duration']);
        self::assertSame(0, $base['conditional_additional_duration']);
        self::assertSame(6, $base['max_duration']);

        $special = $this->service->kiaEntitlement('MATERNITY', true);
        self::assertSame(3, $special['conditional_additional_duration']);
        self::assertSame(1.0, $special['pay_phases'][0]['minimum_pay_rate']);
        self::assertSame(0.75, $special['pay_phases'][1]['minimum_pay_rate']);
    }

    public function testPaternityAdditionalDaysAreCappedAtThree(): void
    {
        $entitlement = $this->service->kiaEntitlement('PATERNITY', false, 99);
        self::assertSame(2, $entitlement['base_duration']);
        self::assertSame(3, $entitlement['conditional_additional_duration']);
        self::assertSame(5, $entitlement['max_duration']);
    }

    public function testMiscarriageFollowsMedicalCertificate(): void
    {
        $entitlement = $this->service->kiaEntitlement('MISCARRIAGE');
        self::assertSame(1.5, $entitlement['base_duration']);
        self::assertTrue($entitlement['duration_follows_medical_certificate']);
        self::assertSame('UU_4_2024', $entitlement['legal_basis']);
    }
}