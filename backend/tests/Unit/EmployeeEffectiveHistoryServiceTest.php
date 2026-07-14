<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Employee\EmployeeEffectiveHistoryService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class EmployeeEffectiveHistoryServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'foreignKeys' => false, 'DBDebug' => true], false);
        foreach ([
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, position_id INTEGER, manager_id INTEGER, supervisor_id INTEGER, department_id INTEGER, branch_id INTEGER, cost_center_id INTEGER, job_grade_id INTEGER, work_location_id INTEGER, basic_salary REAL, fixed_allowance REAL, employment_status TEXT, employment_type TEXT, contract_end_date TEXT, deleted_at TEXT, updated_at TEXT)',
            'CREATE TABLE positions (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE departments (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE branches (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE cost_centers (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE job_grades (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE work_locations (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE benefit_policies (id INTEGER PRIMARY KEY, company_id INTEGER, job_grade_id INTEGER)',
            'CREATE TABLE employee_contracts (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, contract_number TEXT, contract_type TEXT, start_date TEXT, end_date TEXT, basic_salary REAL, position_id INTEGER, department_id INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE employee_master_history (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, valid_from TEXT, valid_to TEXT, status TEXT, position_id INTEGER, manager_id INTEGER, supervisor_id INTEGER, department_id INTEGER, branch_id INTEGER, cost_center_id INTEGER, job_grade_id INTEGER, work_location_id INTEGER, basic_salary REAL, fixed_allowance REAL, employment_status TEXT, employment_type TEXT, contract_type TEXT, contract_start_date TEXT, contract_end_date TEXT, benefit_eligibility_json TEXT, changed_fields_json TEXT, source_type TEXT, source_id INTEGER, approved_by INTEGER, approved_at TEXT, applied_at TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(company_id, employee_id, valid_from))',
        ] as $sql) $this->db->query($sql);
        foreach (['positions', 'departments', 'branches', 'cost_centers', 'job_grades', 'work_locations'] as $table) {
            $this->db->table($table)->insert(['id' => 10, 'company_id' => 1]);
            $this->db->table($table)->insert(['id' => 20, 'company_id' => 2]);
        }
        $this->db->table('benefit_policies')->insert(['id' => 7, 'company_id' => 1, 'job_grade_id' => 10]);
        $this->db->table('employees')->insert(['id' => 1, 'company_id' => 1, 'position_id' => 10, 'manager_id' => null, 'supervisor_id' => null, 'department_id' => 10, 'branch_id' => 10, 'cost_center_id' => 10, 'job_grade_id' => 10, 'work_location_id' => 10, 'basic_salary' => 5000000, 'fixed_allowance' => 500000, 'employment_status' => 'PKWTT', 'employment_type' => 'FULL_TIME', 'contract_end_date' => null, 'deleted_at' => null]);
        $this->db->table('employee_contracts')->insert(['company_id' => 1, 'employee_id' => 1, 'contract_number' => 'C-1', 'contract_type' => 'PKWTT', 'start_date' => '2024-01-01', 'end_date' => null, 'basic_salary' => 5000000]);
        $this->db->table('employee_master_history')->insert(['company_id' => 1, 'employee_id' => 1, 'valid_from' => '2024-01-01', 'valid_to' => null, 'status' => 'ACTIVE', 'position_id' => 10, 'department_id' => 10, 'branch_id' => 10, 'cost_center_id' => 10, 'job_grade_id' => 10, 'work_location_id' => 10, 'basic_salary' => 5000000, 'fixed_allowance' => 500000, 'employment_status' => 'PKWTT', 'employment_type' => 'FULL_TIME', 'contract_type' => 'PKWTT', 'contract_start_date' => '2024-01-01', 'benefit_eligibility_json' => '[7]', 'changed_fields_json' => '[]', 'source_type' => 'BACKFILL', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testFutureApprovedChangePreservesHistoryAndAppliesWhenDue(): void
    {
        $service = new EmployeeEffectiveHistoryService($this->db);
        $scheduled = $service->schedule(1, 1, '2099-01-01', ['basic_salary' => 7000000, 'fixed_allowance' => 750000, 'benefit_policy_ids' => [7]], 99);
        self::assertSame('SCHEDULED', $scheduled['status']);
        self::assertSame(5000000.0, (float) $this->db->table('employees')->where('id', 1)->get()->getRowArray()['basic_salary']);
        self::assertSame(5000000.0, (float) $service->asOf(1, 1, '2025-01-01')['basic_salary']);

        $result = $service->applyDue(1, '2099-01-01');
        self::assertSame(['processed' => 1, 'succeeded' => 1, 'failed' => 0, 'errors' => []], $result);
        self::assertSame(7000000.0, (float) $this->db->table('employees')->where('id', 1)->get()->getRowArray()['basic_salary']);
        self::assertSame('2098-12-31', $service->asOf(1, 1, '2025-01-01')['valid_to']);
        self::assertSame(7000000.0, (float) $service->asOf(1, 1, '2099-01-01')['basic_salary']);
        self::assertSame([7], $service->asOf(1, 1, '2099-01-01')['benefit_policy_ids']);
    }

    public function testTenantReferencesFailClosedAndWorkflowSnapshotIsIdempotent(): void
    {
        $service = new EmployeeEffectiveHistoryService($this->db);
        try {
            $service->schedule(1, 1, '2099-02-01', ['position_id' => 20], 99);
            self::fail('Cross-tenant reference was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('active company', $e->getMessage());
        }

        $first = $service->recordAppliedSnapshot(1, 1, '2026-01-01', 'JIC', 123, 99);
        $second = $service->recordAppliedSnapshot(1, 1, '2026-01-01', 'JIC', 123, 99);
        self::assertSame($first['id'], $second['id']);
        self::assertSame('JIC', $second['source_type']);
        self::assertSame(1, $this->db->table('employee_master_history')->where('company_id', 1)->where('employee_id', 1)->where('valid_from', '2026-01-01')->countAllResults());
        self::assertCount(2, $service->history(1, 1));
    }
}
