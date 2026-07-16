<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\LeaveApprovalDomainAdapter;
use App\Libraries\Leave\LeaveWorkflowException;
use CodeIgniter\Test\CIUnitTestCase;

final class LeaveApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['attendances', 'leave_balances', 'leaves', 'leave_types', 'employees', 'companies'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) {
            $db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $db->query('CREATE TABLE db_companies (id INTEGER PRIMARY KEY, code TEXT, name TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, employee_number TEXT, first_name TEXT, full_name TEXT, join_date TEXT, is_active INTEGER, user_id INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_leave_types (id INTEGER PRIMARY KEY, company_id INTEGER, code TEXT, name TEXT, category TEXT, default_days INTEGER, is_paid INTEGER, reset_yearly INTEGER, is_active INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_leaves (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, leave_type_id INTEGER, request_number TEXT, start_date TEXT, end_date TEXT, days REAL, reason TEXT, status TEXT, approved_by INTEGER, approved_at TEXT, rejection_note TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_leave_balances (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, leave_type_id INTEGER, period_year INTEGER, allocated REAL, carried_over REAL DEFAULT 0, used REAL, remaining REAL, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_attendances (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, work_date TEXT, status TEXT, clock_in_at TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function testFinalApprovalAppliesLeaveSideEffectsExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $workDate = date('Y-m-d', strtotime('next monday'));

        $db->table('companies')->insert([
            'id' => 991,
            'code' => 'ADAPTER-TEST',
            'name' => 'PT Adapter Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('employees')->insert([
            'id' => 991,
            'company_id' => 991,
            'employee_number' => 'EMP-ADAPTER-991',
            'first_name' => 'Adapter',
            'full_name' => 'Adapter Test',
            'join_date' => date('Y-m-d', strtotime('-2 years')),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('leave_types')->insert([
            'id' => 991,
            'company_id' => 991,
            'code' => 'ANNUAL-ADAPTER',
            'name' => 'Cuti Tahunan Adapter',
            'category' => 'ANNUAL',
            'default_days' => 12,
            'is_paid' => 1,
            'reset_yearly' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('leaves')->insert([
            'id' => 991,
            'company_id' => 991,
            'employee_id' => 991,
            'leave_type_id' => 991,
            'request_number' => 'LV-ADAPTER-991',
            'start_date' => $workDate,
            'end_date' => $workDate,
            'days' => 1,
            'reason' => 'Integration test',
            'status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adapter = new LeaveApprovalDomainAdapter();
        $request = ['entity_id' => 991, 'company_id' => 991];
        $adapter->apply($request, 'APPROVED', 777, 'OK');

        $leave = $db->table('leaves')->where('id', 991)->get()->getRowArray();
        $balance = $db->table('leave_balances')->where('employee_id', 991)->get()->getRowArray();
        $this->assertSame('APPROVED', $leave['status']);
        $this->assertSame(777, (int) $leave['approved_by']);
        $this->assertSame(1.0, (float) $balance['used']);
        $this->assertSame(11.0, (float) $balance['remaining']);
        $this->assertSame(1, $db->table('attendances')->where('employee_id', 991)->countAllResults());

        try {
            $adapter->apply($request, 'APPROVED', 777, 'duplicate');
            $this->fail('Duplicate finalization must be rejected');
        } catch (LeaveWorkflowException) {
            $balance = $db->table('leave_balances')->where('employee_id', 991)->get()->getRowArray();
            $this->assertSame(1.0, (float) $balance['used']);
            $this->assertSame(1, $db->table('attendances')->where('employee_id', 991)->countAllResults());
        }
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) {
            $db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
