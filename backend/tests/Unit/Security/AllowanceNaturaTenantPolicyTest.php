<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class AllowanceNaturaTenantPolicyTest extends TestCase
{
    public function testAllowanceMutationsPersistNaturaAndValidateTenantReferences(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Payroll/AllowanceController.php');
        self::assertNotFalse($source);
        self::assertGreaterThanOrEqual(8, substr_count($source, 'requireCompanyId()'));
        self::assertStringContainsString("'is_taxable_natura' => \$isNatura", $source);
        self::assertStringContainsString("'natura_tax_threshold' =>", $source);
        self::assertStringContainsString("tenantReferenceExists(\$db, 'payroll_components'", $source);
        self::assertStringContainsString('invalidRateScope($db, $data, $companyId)', $source);
        self::assertStringContainsString('INVALID_DATE_RANGE', $source);
        self::assertStringContainsString('t.company_id = r.company_id', $source);
        self::assertStringContainsString('jg.company_id = r.company_id', $source);
        self::assertStringContainsString('br.company_id = r.company_id', $source);
    }

    public function testAllowanceServiceScopesSnapshotsAndPropagatesNaturaToPayroll(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Payroll/AllowanceService.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('recomputeEmployee(int $employeeId, int $expectedCompanyId)', $source);
        self::assertStringContainsString("->where('company_id', \$expectedCompanyId)", $source);
        self::assertStringContainsString("->where('a.company_id', \$companyId)", $source);
        self::assertStringContainsString('t.company_id = a.company_id', $source);
        self::assertStringContainsString('t.is_taxable_natura', $source);
        self::assertStringContainsString('t.natura_tax_threshold', $source);
        self::assertStringContainsString('NaturaTaxCalculator::buildEntry(', $source);
        self::assertStringContainsString("\$entry['is_natura'] = 0", $source);
    }

    public function testPayrollPassesTenantContextIntoAllowanceHandoff(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($source);
        self::assertStringContainsString("recomputeEmployee((int) \$emp['id'], \$companyId)", $source);
        self::assertStringContainsString("earningsForPayroll((int) \$emp['id'], \$companyId)", $source);
    }
}
