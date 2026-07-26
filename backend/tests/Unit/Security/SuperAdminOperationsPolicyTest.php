<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Admin\SuperAdminOperationsService;
use CodeIgniter\Test\CIUnitTestCase;

final class SuperAdminOperationsPolicyTest extends CIUnitTestCase
{
    public function testOperationsEndpointIsSuperAdminOnlyAndPayloadSafe(): void
    {
        $routes = service('routes');
        self::assertSame(
            ['jwt', 'superadmin'],
            $routes->getRoutesOptions('api/v1/admin/operations/overview', 'GET')['filter'] ?? [],
        );

        $root = dirname(__DIR__, 3);
        $controller = file_get_contents(
            $root . '/app/Controllers/Api/V1/Admin/SuperAdminOperationsController.php'
        );
        $service = file_get_contents(
            $root . '/app/Libraries/Admin/SuperAdminOperationsService.php'
        );

        self::assertStringContainsString('isSuperAdmin()', $controller);
        self::assertStringContainsString('SUPER_ADMIN_ONLY', $controller);
        self::assertStringContainsString('Metadata kegagalan', file_get_contents(
            dirname($root) . '/frontend/src/views/Admin/SuperAdminDashboard.vue'
        ));
        self::assertStringNotContainsString("select('payload", $service);
        self::assertStringNotContainsString("select('body_html", $service);
    }

    public function testOverviewContractSurvivesOptionalModuleDifferences(): void
    {
        $overview = (new SuperAdminOperationsService())->overview();

        self::assertContains($overview['status'], ['HEALTHY', 'DEGRADED', 'CRITICAL']);
        self::assertArrayHasKey('snapshot_at', $overview);
        self::assertArrayHasKey('health', $overview);
        self::assertArrayHasKey('tenants', $overview);
        self::assertArrayHasKey('queues', $overview);
        self::assertArrayHasKey('workflows', $overview);
        self::assertArrayHasKey('payroll', $overview);
        self::assertArrayHasKey('security', $overview);
        self::assertArrayHasKey('business_flows', $overview);
        self::assertArrayHasKey('alerts', $overview);
        self::assertArrayHasKey('tenant_risks', $overview);
        self::assertArrayHasKey('recent_failures', $overview);
    }
}
