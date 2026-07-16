<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Payroll\PayrollFinalizationService;
use App\Libraries\Payroll\PayrollPendingApprovalService;
use App\Repositories\Payroll\PayrollRepository;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

final class PayrollArchitectureTenantPolicyTest extends TestCase
{
    public function testExtractedPayrollBoundariesFailClosedWithoutTenant(): void
    {
        $db = $this->createMock(BaseConnection::class);

        foreach ([
            fn () => (new PayrollRepository($db))->findPeriod(1, 0),
            fn () => (new PayrollPendingApprovalService($db))->scan(1, 0, ['company_id' => 0]),
            fn () => (new PayrollFinalizationService($db))->finalize(1, 0, 7),
        ] as $operation) {
            try {
                $operation();
                self::fail('Payroll boundary accepted a missing tenant context');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('company context', $exception->getMessage());
            }
        }
    }

    public function testPayrollServiceIsAnOrchestratorWithoutDirectDatabaseQueries(): void
    {
        $service = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollService.php');
        self::assertNotFalse($service);
        self::assertStringNotContainsString('Database::connect', $service);
        self::assertStringNotContainsString('->table(', $service);
        self::assertStringContainsString('new PayrollRepository()', $service);
        self::assertStringContainsString('new PayrollPendingApprovalService', $service);
        self::assertStringContainsString('new PayrollEmployeeCalculationService', $service);
        self::assertStringContainsString('new PayrollFinalizationService', $service);
    }

    public function testExtractedQueriesKeepTenantQualifiedEmployeeJoins(): void
    {
        $pending = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollPendingApprovalService.php');
        $calculation = file_get_contents(APPPATH . 'Libraries/Payroll/PayrollEmployeeCalculationService.php');
        self::assertNotFalse($pending);
        self::assertNotFalse($calculation);
        self::assertStringContainsString('e.company_id = o.company_id', $pending);
        self::assertStringContainsString('e.company_id = r.company_id', $pending);
        self::assertStringContainsString("->where('company_id', \$companyId)", $calculation);
    }
}
