<?php

namespace Tests\Unit\Security;

use CodeIgniter\Test\CIUnitTestCase;

final class RbacConsistencyTest extends CIUnitTestCase
{
    public function testEveryRoutePermissionIsDeclaredInSeedOrMigration(): void
    {
        $root = dirname(__DIR__, 3);
        $routePermissions = [];
        foreach (glob($root . '/app/Config/Routes/*.php') ?: [] as $file) {
            preg_match_all('/permission:([a-zA-Z0-9_.,-]+)/', (string) file_get_contents($file), $matches);
            foreach ($matches[1] ?? [] as $requirement) {
                foreach (explode(',', $requirement) as $slug) {
                    $routePermissions[trim($slug)] = true;
                }
            }
        }

        $declared = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/Database'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            preg_match_all("/'slug'\\s*=>\\s*'([a-zA-Z0-9_.-]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] ?? [] as $slug) $declared[$slug] = true;
        }

        $missing = array_values(array_diff(array_keys($routePermissions), array_keys($declared)));
        sort($missing);
        $this->assertSame([], $missing, 'Route permissions missing from RBAC master: ' . implode(', ', $missing));
    }

    public function testSelfServiceRoutesUseNarrowPermissions(): void
    {
        $root = dirname(__DIR__, 3);
        $employeeRoutes = (string) file_get_contents($root . '/app/Config/Routes/EmployeeRoutes.php');
        $lifecycleRoutes = (string) file_get_contents($root . '/app/Config/Routes/LifecycleRoutes.php');
        $coreRoutes = (string) file_get_contents($root . '/app/Config/Routes/CoreRoutes.php');
        $recruitmentRoutes = (string) file_get_contents($root . '/app/Config/Routes/RecruitmentRoutes.php');

        $this->assertStringContainsString('permission:employee.team_view,employee.view', $employeeRoutes);
        $this->assertStringContainsString('permission:bonus.view_self,bonus.view', $lifecycleRoutes);
        $this->assertStringContainsString('permission:resignation.view_self,resignation.view', $lifecycleRoutes);
        $this->assertStringContainsString('permission:employee_transfer.view_self,employee_transfer.view', $lifecycleRoutes);
        $this->assertStringContainsString('permission:review.conduct_self,review.conduct', $coreRoutes);
        $this->assertStringContainsString('permission:recruitment.view,referral.create_self', $recruitmentRoutes);
    }

    public function testSelfServiceMenuRequirementsAreCentrallyManaged(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = (string) file_get_contents($root . '/app/Libraries/Identity/RbacMenuCatalogService.php');
        foreach ([
            'my.team', 'my.attendance.list', 'my.bonuses', 'my.performance.peer_review',
            'my.talent_marketplace', 'my.referral', 'my.transfer', 'my.resignation',
        ] as $code) {
            $this->assertStringContainsString("'{$code}' =>", $catalog);
        }
        $this->assertStringContainsString('syncPermissionRequirements()', $catalog);
    }
}
