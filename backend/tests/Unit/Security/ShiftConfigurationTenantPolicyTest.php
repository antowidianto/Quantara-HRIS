<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class ShiftConfigurationTenantPolicyTest extends TestCase
{
    public function testShiftAssignmentMutationsAndPreviewAreTenantBound(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/ShiftAssignmentController.php');
        self::assertNotFalse($source);
        self::assertGreaterThanOrEqual(4, substr_count($source, 'requireCompanyId()'));
        self::assertStringContainsString('->where(\'company_id\', $companyId)', $source);
        self::assertStringContainsString('invalidScopeReference($db, $data, $companyId)', $source);
        self::assertStringContainsString('(new EmployeeModel())->findScoped($employeeId)', $source);
        self::assertStringContainsString('INVALID_DATE_RANGE', $source);
        foreach (['shifts', 'departments', 'branches', 'work_locations', 'job_grades'] as $table) {
            self::assertStringContainsString("'{$table}'", $source);
        }
    }

    public function testShiftPatternAndNestedAssignmentsAreTenantBound(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Attendance/ShiftPatternController.php');
        self::assertNotFalse($source);
        self::assertGreaterThanOrEqual(5, substr_count($source, 'requireCompanyId()'));
        self::assertStringContainsString('->where(\'company_id\', $companyId)', $source);
        self::assertStringContainsString('invalidPatternShiftId($db, $patternData, $companyId)', $source);
        self::assertStringContainsString('invalidAssignmentScope($db, $data, $companyId)', $source);
        self::assertStringContainsString('spa.company_id = p.company_id', $source);
        self::assertStringContainsString('INVALID_SHIFT', $source);
        self::assertStringContainsString('INVALID_SCOPE', $source);
        self::assertStringContainsString('INVALID_DATE_RANGE', $source);
        foreach (['employees', 'departments', 'branches', 'work_locations', 'job_grades'] as $table) {
            self::assertStringContainsString("'{$table}'", $source);
        }
    }
}
