<?php

namespace Tests\Unit;

use App\Libraries\PrivacyScope;
use App\Libraries\TenantContext;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test PrivacyScope dengan TenantContext stub. Tidak butuh DB karena cuma
 * test logika permission resolution & sanitasi filter.
 *
 * Strategi: subclass TenantContext, override hasPermission() untuk return
 * preset list permissions per skenario test.
 */
class PrivacyScopeTest extends TestCase
{
    /**
     * Helper: bikin TenantContext stub dengan preset perms + employee_id +
     * isSuperAdmin flag.
     */
    private function makeTenant(
        bool $isSuperAdmin,
        int $employeeId,
        int $companyId,
        array $perms = [],
    ): TenantContext {
        return new class($isSuperAdmin, $employeeId, $companyId, $perms) extends TenantContext {
            public function __construct(bool $superAdmin, int $empId, int $compId, private array $perms)
            {
                $this->setEmployeeId($empId);
                $this->setCompanyId($compId);
                $this->setUserId(99);
                // Trigger isSuperAdmin via setRoleId(1)
                if ($superAdmin) $this->setRoleId(1);
            }
            public function hasPermission(string $slug): bool
            {
                if ($this->isSuperAdmin()) return true;
                return in_array($slug, $this->perms, true);
            }
            public function getPermissions(): array { return $this->perms; }
        };
    }

    #[Test]
    public function super_admin_can_view_all_modules(): void
    {
        $tenant = $this->makeTenant(true, 0, 0);
        $scope  = new PrivacyScope($tenant);

        $this->assertTrue($scope->canViewAll('leave'));
        $this->assertTrue($scope->canViewAll('payslip'));
        $this->assertTrue($scope->canViewAll('overtime'));
        $this->assertTrue($scope->canViewAll('any-random-module'));
    }

    #[Test]
    public function hr_with_leave_approve_can_view_all_leave(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.approve']);
        $scope  = new PrivacyScope($tenant);

        $this->assertTrue($scope->canViewAll('leave'));
        $this->assertTrue($scope->canViewOwn('leave'));
        // Tidak punya akses module lain
        $this->assertFalse($scope->canViewAll('payslip'));
        $this->assertFalse($scope->canViewAll('overtime'));
    }

    #[Test]
    public function employee_with_leave_view_can_only_view_own(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.view', 'leave.create']);
        $scope  = new PrivacyScope($tenant);

        $this->assertFalse($scope->canViewAll('leave'));
        $this->assertTrue($scope->canViewOwn('leave'));
    }

    #[Test]
    public function user_without_any_perm_cannot_view(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, []);
        $scope  = new PrivacyScope($tenant);

        $this->assertFalse($scope->canViewAll('leave'));
        $this->assertFalse($scope->canViewOwn('leave'));
    }

    #[Test]
    public function payslip_view_self_grants_view_own_only(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['payslip.view_self']);
        $scope  = new PrivacyScope($tenant);

        $this->assertFalse($scope->canViewAll('payslip'));
        $this->assertTrue($scope->canViewOwn('payslip'));
    }

    #[Test]
    public function payslip_view_all_grants_view_all_and_own(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['payslip.view_all']);
        $scope  = new PrivacyScope($tenant);

        $this->assertTrue($scope->canViewAll('payslip'));
        $this->assertTrue($scope->canViewOwn('payslip'));
    }

    #[Test]
    public function sanitize_employee_filter_view_own_forces_self(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.view']);
        $scope  = new PrivacyScope($tenant);

        // User request employee_id=999 (orang lain) tapi cuma view.own
        $effective = $scope->sanitizeEmployeeFilter(999, 'leave');
        $this->assertSame(100, $effective, 'Harus paksa ke employee_id user (100), bukan 999');

        // Request null/0 → tetap diisi dengan empId user (forced self filter)
        $effective2 = $scope->sanitizeEmployeeFilter(null, 'leave');
        $this->assertSame(100, $effective2);
    }

    #[Test]
    public function sanitize_employee_filter_view_all_passes_through(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.approve']);
        $scope  = new PrivacyScope($tenant);

        // HR request 999 → boleh
        $this->assertSame(999, $scope->sanitizeEmployeeFilter(999, 'leave'));

        // HR request null → 0 (no filter)
        $this->assertSame(0, $scope->sanitizeEmployeeFilter(null, 'leave'));
    }

    #[Test]
    public function can_access_row_blocks_cross_tenant(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.approve']);
        $scope  = new PrivacyScope($tenant);

        // Row dari company lain → blocked walau punya canViewAll
        $row = ['company_id' => 99, 'employee_id' => 100];
        $this->assertFalse($scope->canAccessRow($row, 'leave'));
    }

    #[Test]
    public function can_access_row_allows_own_row_even_without_view_all(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.view']);
        $scope  = new PrivacyScope($tenant);

        // Row miliknya → boleh
        $own = ['company_id' => 1, 'employee_id' => 100];
        $this->assertTrue($scope->canAccessRow($own, 'leave'));

        // Row orang lain di company sama → tidak boleh (cuma view.own)
        $other = ['company_id' => 1, 'employee_id' => 200];
        $this->assertFalse($scope->canAccessRow($other, 'leave'));
    }

    #[Test]
    public function can_access_row_allows_other_employee_with_view_all(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.approve']);
        $scope  = new PrivacyScope($tenant);

        $other = ['company_id' => 1, 'employee_id' => 200];
        $this->assertTrue($scope->canAccessRow($other, 'leave'));
    }

    #[Test]
    public function sanitize_filters_strips_cross_employee_for_view_own(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.view']);
        $scope  = new PrivacyScope($tenant);

        $input = [
            'status'        => 'PENDING',
            'employee_id'   => 999,         // harus di-strip
            'branch_id'     => 5,           // harus di-strip
            'department_id' => 3,           // harus di-strip
            'start_date'    => '2026-01-01',
        ];
        $clean = $scope->sanitizeFilters($input, 'leave',
            ['status', 'employee_id', 'branch_id', 'department_id', 'start_date']);

        $this->assertArrayHasKey('status', $clean);
        $this->assertArrayHasKey('start_date', $clean);
        $this->assertArrayNotHasKey('employee_id', $clean);
        $this->assertArrayNotHasKey('branch_id', $clean);
        $this->assertArrayNotHasKey('department_id', $clean);
    }

    #[Test]
    public function sanitize_filters_passes_through_for_view_all(): void
    {
        $tenant = $this->makeTenant(false, 100, 1, ['leave.approve']);
        $scope  = new PrivacyScope($tenant);

        $input = ['employee_id' => 999, 'branch_id' => 5, 'status' => 'APPROVED'];
        $clean = $scope->sanitizeFilters($input, 'leave',
            ['employee_id', 'branch_id', 'status']);

        $this->assertSame(999, (int) $clean['employee_id']);
        $this->assertSame(5, (int) $clean['branch_id']);
        $this->assertSame('APPROVED', $clean['status']);
    }
}