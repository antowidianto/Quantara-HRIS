<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\RouteSource;

final class CentralAuthorizationPolicyTest extends TestCase
{
    public function testPlatformAdminRoutesUseCentralSuperAdminFilter(): void
    {
        $routes = RouteSource::all();
        foreach (['impersonate', 'suspend', 'unsuspend', 'extend-trial', 'feature-override', 'admin/cs'] as $route) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote($route, '/') . ".{0,180}filter' => 'superadmin'/s",
                $routes,
                "{$route} must remain super-admin-only at the route boundary."
            );
        }
    }

    public function testTenantDataExportAndPrivacyReviewHaveExplicitPermissions(): void
    {
        $routes = RouteSource::all();
        self::assertMatchesRegularExpression('/companies\/me\/data-exports.{0,200}permission:data_export\.manage/s', $routes);
        self::assertMatchesRegularExpression('/admin\/privacy\/deletion-requests.{0,200}permission:privacy\.review/s', $routes);
    }

    public function testSuperAdminFilterIsRegistered(): void
    {
        $filters = file_get_contents(APPPATH . 'Config/Filters.php');
        self::assertIsString($filters);
        self::assertStringContainsString("'superadmin'", $filters);
        self::assertStringContainsString('SuperAdminFilter::class', $filters);
    }

    public function testMasterResourcesSeparateReadAndManagePolicies(): void
    {
        $routes = RouteSource::all();
        foreach (['company', 'branch', 'department', 'position', 'job_title'] as $permission) {
            self::assertStringContainsString("permission:{$permission}.view", $routes);
            self::assertStringContainsString("permission:{$permission}.manage", $routes);
        }
    }

    public function testOwnAttendanceIsJwtSelfServiceWithoutFunctionalRolePermission(): void
    {
        $routes = RouteSource::all();
        self::assertMatchesRegularExpression(
            "/get\('attendance\/my',\s*'Attendance\\\\AttendanceController::my'\);/s",
            $routes,
        );
        self::assertStringNotContainsString(
            "attendance/my', 'Attendance\\AttendanceController::my', ['filter' => 'permission:",
            $routes,
        );
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/AttendanceController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('(new EmployeeModel())->findScoped((int) $employeeId)', $controller);
        self::assertStringContainsString('EMPLOYEE_TENANT_MISMATCH', $controller);
    }
}
