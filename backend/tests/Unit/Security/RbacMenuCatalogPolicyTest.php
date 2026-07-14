<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Identity\RbacMenuCatalogService;
use PHPUnit\Framework\TestCase;

final class RbacMenuCatalogPolicyTest extends TestCase
{
    public function testMenuPermissionSupportsAnyOfSemantics(): void
    {
        self::assertTrue(RbacMenuCatalogService::allows(
            'employee.view,payroll.view',
            ['payroll.view'],
        ));
        self::assertTrue(RbacMenuCatalogService::allows(
            'employee.view,payroll.view',
            ['employee.view'],
        ));
        self::assertFalse(RbacMenuCatalogService::allows(
            'employee.view,payroll.view',
            ['attendance.view'],
        ));
    }

    public function testCatalogContainsNewFrontendRoutesAndRbacUnblock(): void
    {
        $catalog = file_get_contents(APPPATH . 'Libraries/Identity/RbacMenuCatalogService.php');
        self::assertNotFalse($catalog);
        foreach (['/hr/data-quality', '/hr/compensation-review'] as $route) {
            self::assertStringContainsString($route, $catalog);
        }
        foreach (['admin.enterprise', 'admin.enterprise.erp_journal', 'admin.enterprise.timesheet'] as $code) {
            self::assertStringContainsString($code, $catalog);
        }
        self::assertStringContainsString("'super_admin_only' => 0", $catalog);
    }

    public function testRuntimeMenuFilteringUsesCatalogMatcher(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Identity/MenuController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('RbacMenuCatalogService::allows', $controller);
    }
}
