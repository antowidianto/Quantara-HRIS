<?php

namespace Tests\Unit;

use App\Libraries\Payroll\JicRetroPayrollService;
use CodeIgniter\Test\CIUnitTestCase;

final class JicRetroPayrollBusinessFlowTest extends CIUnitTestCase
{
    public function testPastEffectiveSalaryIncreaseIsProratedByCalendarDay(): void
    {
        self::assertSame(500_000.0, JicRetroPayrollService::salaryDelta(
            10_000_000,
            11_000_000,
            '2026-06-01',
            '2026-06-30',
            '2026-06-16',
        ));
    }

    public function testPastEffectiveSalaryDecreaseBecomesNegativeDelta(): void
    {
        self::assertSame(-1_000_000.0, JicRetroPayrollService::salaryDelta(
            10_000_000,
            8_000_000,
            '2026-06-01',
            '2026-06-30',
            '2026-06-16',
        ));
    }

    public function testRetroBaselineComesFromIntegrityCheckedFinalizedSnapshot(): void
    {
        $json = json_encode([
            'payslips' => [[
                'employee_id' => 17,
                'employee_master' => ['basic_salary' => '10000000.00'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);

        self::assertSame(10_000_000.0, JicRetroPayrollService::snapshotMonthlySalary($json, $hash, 17));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity');
        JicRetroPayrollService::snapshotMonthlySalary($json . 'tampered', $hash, 17);
    }

    public function testJicApplyCreatesTraceableDraftAdjustmentBeforeMasterUpdate(): void
    {
        $jic = file_get_contents(APPPATH . 'Libraries/Employee/JicService.php');
        $retro = file_get_contents(APPPATH . 'Libraries/Payroll/JicRetroPayrollService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Employee/JicController.php');

        self::assertIsString($jic);
        self::assertIsString($retro);
        self::assertIsString($controller);
        self::assertStringContainsString('createForJic', $jic);
        self::assertStringContainsString("'source_type' => 'JIC'", $retro);
        self::assertStringContainsString("'status' => 'DRAFT'", $retro);
        self::assertStringContainsString('editable payroll period', $retro);
        self::assertStringNotContainsString('Tanggal efektif tidak boleh di masa lalu', $controller);
    }
}
