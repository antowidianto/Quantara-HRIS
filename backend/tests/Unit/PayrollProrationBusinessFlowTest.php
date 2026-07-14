<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Payroll\PayrollService;
use App\Libraries\Payroll\Pph21TerCalculator;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class PayrollProrationBusinessFlowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        if (! $db->tableExists('ter_rates')) {
            $forge = Database::forge($db);
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'category' => ['type' => 'VARCHAR', 'constraint' => 1],
                'effective_year' => ['type' => 'INTEGER'],
                'min_income' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'max_income' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'rate' => ['type' => 'DECIMAL', 'constraint' => '8,6'],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('ter_rates');
        }
        $db->table('ter_rates')->truncate();
        $db->table('ter_rates')->insert([
            'category' => 'A', 'effective_year' => 2024,
            'min_income' => 0, 'max_income' => null, 'rate' => 0.05,
        ]);
    }

    public function testMidMonthJoinFlowsIntoProratedBasicAndPph21Input(): void
    {
        $basic = PayrollService::prorateBasicSalary(
            31_000_000,
            '2026-07-15',
            null,
            '2026-07-01',
            '2026-07-31',
        );

        self::assertSame(17_000_000.0, $basic);

        $tax = (new Pph21TerCalculator())->calculateMonthlyTer($basic, 'TK0', 2026);
        self::assertGreaterThan(0, $tax['tax']);
        self::assertLessThan($basic, $tax['tax']);
    }

    public function testFullPeriodAndMidMonthResignationBoundaries(): void
    {
        self::assertSame(10_000_000.0, PayrollService::prorateBasicSalary(
            10_000_000, '2020-01-01', null, '2026-07-01', '2026-07-31',
        ));
        self::assertSame(5_000_000.0, PayrollService::prorateBasicSalary(
            10_000_000, '2020-01-01', '2026-07-15', '2026-07-01', '2026-07-30',
        ));
    }

    public function testEmployeeOutsidePeriodReceivesNoBasicEarning(): void
    {
        self::assertSame(0.0, PayrollService::prorateBasicSalary(
            10_000_000, '2026-08-01', null, '2026-07-01', '2026-07-31',
        ));
    }

    public function testApprovedHalfDayUnpaidLeaveFlowsIntoPayrollDeduction(): void
    {
        $days = PayrollService::unpaidLeaveDaysInPeriod([
            [
                'start_date' => '2026-07-10',
                'end_date' => '2026-07-10',
                'days' => 0.5,
                'is_half_day' => 1,
            ],
        ], '2026-07-01', '2026-07-31');

        self::assertSame(0.5, $days);
        self::assertSame(250_000.0, PayrollService::unpaidLeaveDeduction(10_000_000, 20, $days));
    }

    public function testUnpaidLeaveOutsidePeriodIsExcludedAndOverlapIsClipped(): void
    {
        $days = PayrollService::unpaidLeaveDaysInPeriod([
            ['start_date' => '2026-06-29', 'end_date' => '2026-07-02', 'days' => 4],
            ['start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'days' => 2],
        ], '2026-07-01', '2026-07-31');

        self::assertSame(2.0, $days);
        self::assertSame(0.0, PayrollService::unpaidLeaveDeduction(10_000_000, 20, 0));
    }
}
