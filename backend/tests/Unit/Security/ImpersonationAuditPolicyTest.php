<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class ImpersonationAuditPolicyTest extends TestCase
{
    public function testImpersonationIsShortLivedReasonedAndAuditedFailClosed(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Admin/AdminTenantController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString("'reason' => 'required|min_length[10]|max_length[500]'", $controller);
        self::assertStringContainsString("'is_impersonated' => true", $controller);
        self::assertStringContainsString("'impersonator_id' =>", $controller);
        self::assertStringContainsString('15 * 60', $controller);
        self::assertStringContainsString("action: 'admin.impersonate'", $controller);
        self::assertStringContainsString("severity: 'HIGH'", $controller);
        self::assertStringContainsString('PRIVILEGED_AUDIT_UNAVAILABLE', $controller);
    }

    public function testHeaderTenantSwitchIsAuditedAndFailsClosedWhenAuditIsUnavailable(): void
    {
        $filter = file_get_contents(APPPATH . 'Filters/JwtAuthFilter.php');
        self::assertNotFalse($filter);
        self::assertStringContainsString("action: 'auth.tenant.switch'", $filter);
        self::assertStringContainsString("severity: 'NOTICE'", $filter);
        self::assertStringContainsString('target_company_id', $filter);
        self::assertStringContainsString('return $this->auditUnavailable()', $filter);
        self::assertStringContainsString('PRIVILEGED_AUDIT_UNAVAILABLE', $filter);
    }
}
