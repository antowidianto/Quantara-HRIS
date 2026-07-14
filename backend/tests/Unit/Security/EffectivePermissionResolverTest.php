<?php

namespace Tests\Unit\Security;

use App\Libraries\Identity\EffectivePermissionResolver;
use CodeIgniter\Test\CIUnitTestCase;

final class EffectivePermissionResolverTest extends CIUnitTestCase
{
    public function testMergeProducesStableUniquePermissionList(): void
    {
        $actual = EffectivePermissionResolver::merge(
            ['attendance.view', 'attendance.clock', ''],
            ['attendance.clock', 'attendance.request_change'],
        );

        $this->assertSame([
            'attendance.clock',
            'attendance.request_change',
            'attendance.view',
        ], $actual);
    }

    public function testAllRuntimeConsumersUseTenantEffectivePermissions(): void
    {
        $root = dirname(__DIR__, 3);
        $tenant = file_get_contents($root . '/app/Libraries/System/TenantContext.php');
        $auth = file_get_contents($root . '/app/Controllers/Api/V1/Auth/AuthController.php');
        $menu = file_get_contents($root . '/app/Controllers/Api/V1/Identity/MenuController.php');

        $this->assertStringContainsString('new EffectivePermissionResolver()', $tenant);
        $this->assertStringContainsString('$this->tenant()->getPermissions()', $auth);
        $this->assertStringContainsString('$tenant->getPermissions()', $menu);
        $this->assertStringNotContainsString('loadUserPermissions(', $menu);
    }
}
