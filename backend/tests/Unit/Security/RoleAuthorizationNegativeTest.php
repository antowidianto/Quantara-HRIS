<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Filters\PermissionFilter;
use App\Libraries\System\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class RoleAuthorizationNegativeTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('tenant');
        parent::tearDown();
    }

    /** @return iterable<string, array{string[], string}> */
    public static function deniedRoleCases(): iterable
    {
        yield 'employee cannot finalize payroll' => [['payslip.view_self', 'leave.create'], 'payroll.finalize'];
        yield 'supervisor cannot calculate payroll' => [['attendance.approve', 'overtime.approve'], 'payroll.calculate'];
        yield 'manager cannot use payroll break glass' => [['leave.approve', 'employee.view'], 'payroll.break_glass'];
        yield 'HR admin cannot approve own payroll boundary' => [['employee.manage', 'payroll.manage'], 'payroll.finalize'];
        yield 'payroll admin cannot finalize' => [['payroll.calculate', 'payroll.manage'], 'payroll.finalize'];
        yield 'finance cannot prepare employee master' => [['payroll.finalize', 'reimbursement.approve'], 'employee.manage'];
        yield 'CFO cannot mutate attendance devices' => [['payroll.finalize', 'payroll.break_glass'], 'attendance.manage_devices'];
        yield 'auditor is read only' => [['audit.view', 'payroll.view'], 'employee.manage'];
    }

    #[DataProvider('deniedRoleCases')]
    public function testNamedBusinessRolesReceive403WithoutRequiredPermission(array $granted, string $required): void
    {
        $this->injectTenant($granted);

        $result = (new PermissionFilter())->before(Services::request(), [$required]);

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
        self::assertStringContainsString('FORBIDDEN', $result->getBody());
    }

    public function testAnyOfPolicyAllowsEitherSelfOrAllPermission(): void
    {
        $this->injectTenant(['payslip.view_all']);

        $result = (new PermissionFilter())->before(
            Services::request(),
            ['payslip.view_self', 'payslip.view_all'],
        );

        self::assertNull($result);
    }

    public function testSuperAdminBypassesRolePermissionLookup(): void
    {
        $tenant = new class extends TenantContext {
            public function __construct()
            {
                $this->setRoleId(1);
            }
        };
        Services::injectMock('tenant', $tenant);

        self::assertNull((new PermissionFilter())->before(Services::request(), ['employee.manage']));
    }

    private function injectTenant(array $permissions): void
    {
        $tenant = new class($permissions) extends TenantContext {
            public function __construct(private readonly array $permissions)
            {
                $this->setCompanyId(1);
                $this->setRoleId(99);
            }

            public function hasPermission(string $slug): bool
            {
                return in_array($slug, $this->permissions, true);
            }
        };
        Services::injectMock('tenant', $tenant);
    }
}
