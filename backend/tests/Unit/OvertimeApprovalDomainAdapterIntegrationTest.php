<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\OvertimeApprovalDomainAdapter;
use App\Libraries\Attendance\OvertimeApprovalException;
use CodeIgniter\Test\CIUnitTestCase;

final class OvertimeApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['leave_balances', 'leave_types', 'overtimes', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, full_name TEXT, basic_salary REAL, user_id INTEGER)');
        $db->query('CREATE TABLE db_overtimes (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, request_number TEXT, work_date TEXT, hours REAL, day_type TEXT, compensation_type TEXT, status TEXT, approved_by INTEGER, approved_at TEXT, rejection_note TEXT, computed_amount REAL, leave_balance_id INTEGER, leave_hours_credited REAL, leave_conversion_ratio REAL, updated_at TEXT)');
        $db->query('CREATE TABLE db_leave_types (id INTEGER PRIMARY KEY, company_id INTEGER, source TEXT, is_active INTEGER, deleted_at TEXT)');
        $db->query('CREATE TABLE db_leave_balances (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, leave_type_id INTEGER, period_year INTEGER, allocated REAL, carried_over REAL, used REAL, remaining REAL, created_at TEXT, updated_at TEXT)');
        $db->table('employees')->insert([
            'id' => 501, 'company_id' => 501, 'full_name' => 'Overtime Test',
            'basic_salary' => 5_000_000, 'user_id' => 9001,
        ]);
    }

    public function testCashApprovalIsAppliedExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('overtimes')->insert([
            'id' => 501, 'company_id' => 501, 'employee_id' => 501,
            'request_number' => 'OT-CASH-501', 'work_date' => '2026-07-20',
            'hours' => 2, 'day_type' => 'WEEKDAY', 'compensation_type' => 'CASH',
            'status' => 'PENDING', 'computed_amount' => 0,
        ]);
        $adapter = new OvertimeApprovalDomainAdapter();
        $request = ['entity_id' => 501, 'company_id' => 501];
        $result = $adapter->apply($request, 'APPROVED', 7001, 'approved');
        $row = $db->table('overtimes')->where('id', 501)->get()->getRowArray();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame(7001, (int) $row['approved_by']);
        $this->assertGreaterThan(0, (float) $row['computed_amount']);
        $this->assertSame((float) $row['computed_amount'], (float) $result['amount']);

        $amount = (float) $row['computed_amount'];
        try {
            $adapter->apply($request, 'APPROVED', 7001, 'duplicate');
            $this->fail('Duplicate approval must fail');
        } catch (OvertimeApprovalException) {
            $row = $db->table('overtimes')->where('id', 501)->get()->getRowArray();
            $this->assertSame($amount, (float) $row['computed_amount']);
        }
    }

    public function testLeaveCompensationCreditsBalanceExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('leave_types')->insert([
            'id' => 502, 'company_id' => 501, 'source' => 'OVERTIME_COMP',
            'is_active' => 1, 'deleted_at' => null,
        ]);
        $db->table('overtimes')->insert([
            'id' => 502, 'company_id' => 501, 'employee_id' => 501,
            'request_number' => 'OT-LEAVE-502', 'work_date' => '2026-07-20',
            'hours' => 4, 'day_type' => 'WEEKDAY', 'compensation_type' => 'LEAVE',
            'status' => 'PENDING', 'computed_amount' => 0,
        ]);
        $adapter = new OvertimeApprovalDomainAdapter();
        $request = ['entity_id' => 502, 'company_id' => 501];
        $adapter->apply($request, 'APPROVED', 7001, 'approved');
        $balance = $db->table('leave_balances')->where('employee_id', 501)->get()->getRowArray();
        $row = $db->table('overtimes')->where('id', 502)->get()->getRowArray();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame(4.0, (float) $row['leave_hours_credited']);
        $this->assertSame(0.5, (float) $balance['allocated']);
        $this->assertSame(0.5, (float) $balance['remaining']);

        try {
            $adapter->apply($request, 'APPROVED', 7001, 'duplicate');
            $this->fail('Duplicate approval must fail');
        } catch (OvertimeApprovalException) {
            $balance = $db->table('leave_balances')->where('employee_id', 501)->get()->getRowArray();
            $this->assertSame(0.5, (float) $balance['allocated']);
        }
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
