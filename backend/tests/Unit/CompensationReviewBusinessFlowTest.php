<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Compensation\CompensationReviewService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CompensationReviewBusinessFlowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'foreignKeys' => false, 'DBDebug' => true], false);
        foreach ([
            'CREATE TABLE departments (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)', 'CREATE TABLE cost_centers (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)',
            'CREATE TABLE job_grades (id INTEGER PRIMARY KEY, company_id INTEGER, min_salary REAL, max_salary REAL)',
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, employee_number TEXT, full_name TEXT, department_id INTEGER, cost_center_id INTEGER, job_grade_id INTEGER, position_id INTEGER, manager_id INTEGER, supervisor_id INTEGER, branch_id INTEGER, work_location_id INTEGER, basic_salary REAL, fixed_allowance REAL, employment_status TEXT, employment_type TEXT, contract_end_date TEXT, deleted_at TEXT, updated_at TEXT)',
            'CREATE TABLE positions (id INTEGER PRIMARY KEY, company_id INTEGER)', 'CREATE TABLE branches (id INTEGER PRIMARY KEY, company_id INTEGER)', 'CREATE TABLE work_locations (id INTEGER PRIMARY KEY, company_id INTEGER)',
            'CREATE TABLE benefit_policies (id INTEGER PRIMARY KEY, company_id INTEGER, job_grade_id INTEGER)',
            'CREATE TABLE employee_contracts (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, contract_number TEXT, contract_type TEXT, start_date TEXT, end_date TEXT, basic_salary REAL, position_id INTEGER, department_id INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE employee_master_history (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, valid_from TEXT, valid_to TEXT, status TEXT, position_id INTEGER, manager_id INTEGER, supervisor_id INTEGER, department_id INTEGER, branch_id INTEGER, cost_center_id INTEGER, job_grade_id INTEGER, work_location_id INTEGER, basic_salary REAL, fixed_allowance REAL, employment_status TEXT, employment_type TEXT, contract_type TEXT, contract_start_date TEXT, contract_end_date TEXT, benefit_eligibility_json TEXT, changed_fields_json TEXT, source_type TEXT, source_id INTEGER, approved_by INTEGER, approved_at TEXT, applied_at TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(company_id, employee_id, valid_from))',
            'CREATE TABLE compensation_review_cycles (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, code TEXT, name TEXT, effective_date TEXT, status TEXT, approval_levels INTEGER, created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(company_id, code))',
            'CREATE TABLE compensation_review_budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, cycle_id INTEGER, department_id INTEGER, cost_center_id INTEGER, budget_amount REAL, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE compensation_review_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, cycle_id INTEGER, employee_id INTEGER, department_id INTEGER, cost_center_id INTEGER, current_salary REAL, proposed_salary REAL, range_min REAL, range_max REAL, compa_ratio REAL, range_penetration REAL, performance_score REAL, calibration_input TEXT, manager_note TEXT, status TEXT, approval_level INTEGER, proposed_by INTEGER, approved_by INTEGER, approved_at TEXT, letter_reference TEXT, history_snapshot_id INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(company_id, cycle_id, employee_id))',
            'CREATE TABLE compensation_review_approvals (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, proposal_id INTEGER, approval_level INTEGER, approver_id INTEGER, decision TEXT, created_at TEXT, UNIQUE(company_id, proposal_id, approval_level), UNIQUE(company_id, proposal_id, approver_id))',
        ] as $sql) $this->db->query($sql);
        foreach (['departments', 'cost_centers', 'positions', 'branches', 'work_locations'] as $table) { $this->db->table($table)->insert(['id' => 10, 'company_id' => 1, ...($table === 'departments' || $table === 'cost_centers' ? ['name' => 'Scope A'] : [])]); $this->db->table($table)->insert(['id' => 20, 'company_id' => 2, ...($table === 'departments' || $table === 'cost_centers' ? ['name' => 'Scope B'] : [])]); }
        $this->db->table('job_grades')->insert(['id' => 10, 'company_id' => 1, 'min_salary' => 4000000, 'max_salary' => 8000000]);
        $this->db->table('employees')->insert(['id' => 1, 'company_id' => 1, 'employee_number' => 'A-1', 'full_name' => 'Employee A', 'department_id' => 10, 'cost_center_id' => 10, 'job_grade_id' => 10, 'position_id' => 10, 'branch_id' => 10, 'work_location_id' => 10, 'basic_salary' => 5000000, 'fixed_allowance' => 0, 'employment_status' => 'PKWTT', 'employment_type' => 'FULL_TIME']);
        $this->db->table('employee_master_history')->insert(['company_id' => 1, 'employee_id' => 1, 'valid_from' => '2024-01-01', 'status' => 'ACTIVE', 'department_id' => 10, 'cost_center_id' => 10, 'job_grade_id' => 10, 'position_id' => 10, 'branch_id' => 10, 'work_location_id' => 10, 'basic_salary' => 5000000, 'fixed_allowance' => 0, 'employment_status' => 'PKWTT', 'employment_type' => 'FULL_TIME', 'benefit_eligibility_json' => '[]', 'changed_fields_json' => '[]', 'source_type' => 'BACKFILL', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00']);
    }

    protected function tearDown(): void { $this->db->close(); parent::tearDown(); }

    public function testTwoLevelApprovalSchedulesSalaryAndProducesLetter(): void
    {
        $service = new CompensationReviewService($this->db); $cycle = $service->createCycle(1, ['code' => 'MERIT-2099', 'name' => 'Merit 2099', 'effective_date' => '2099-01-01', 'approval_levels' => 2], 90);
        $service->replaceBudgets(1, (int) $cycle['id'], [['department_id' => 10, 'cost_center_id' => 10, 'budget_amount' => 1000000]]); $service->openCycle(1, (int) $cycle['id']);
        $proposal = $service->propose(1, (int) $cycle['id'], 1, 5500000, 4.5, 'High performer', 91);
        self::assertSame(0.9167, (float) $proposal['compa_ratio']); self::assertSame(0.375, (float) $proposal['range_penetration']);
        self::assertSame('IN_APPROVAL', $service->approve(1, (int) $proposal['id'], 92)['status']);
        $approved = $service->approve(1, (int) $proposal['id'], 93); self::assertSame('APPROVED', $approved['status']); self::assertNotNull($approved['history_snapshot_id']);
        $snapshot = $this->db->table('employee_master_history')->where('id', $approved['history_snapshot_id'])->get()->getRowArray(); self::assertSame('SCHEDULED', $snapshot['status']); self::assertSame(5500000.0, (float) $snapshot['basic_salary']); self::assertSame('COMPENSATION_REVIEW', $snapshot['source_type']);
        $letter = $service->letter(1, (int) $proposal['id']); self::assertSame('Employee A', $letter['employee']['name']); self::assertSame(500000.0, $letter['increase']);
        $summary = $service->detail(1, (int) $cycle['id'])['summary']; self::assertSame(500000.0, $summary['approved_increase']); self::assertSame(500000.0, $summary['remaining']);
    }

    public function testBudgetTenantAndSegregationControlsFailClosed(): void
    {
        $service = new CompensationReviewService($this->db); $cycle = $service->createCycle(1, ['code' => 'TIGHT', 'name' => 'Tight', 'effective_date' => '2099-02-01', 'approval_levels' => 1], 90);
        try { $service->replaceBudgets(1, (int) $cycle['id'], [['department_id' => 20, 'budget_amount' => 100]]); self::fail('Cross-tenant budget scope accepted.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('active company', $e->getMessage()); }
        $service->replaceBudgets(1, (int) $cycle['id'], [['department_id' => 10, 'cost_center_id' => 10, 'budget_amount' => 100000]]); $service->openCycle(1, (int) $cycle['id']); $proposal = $service->propose(1, (int) $cycle['id'], 1, 5500000, null, null, 91);
        try { $service->approve(1, (int) $proposal['id'], 91); self::fail('Self approval accepted.'); } catch (\RuntimeException $e) { self::assertStringContainsString('own proposal', $e->getMessage()); }
        try { $service->approve(1, (int) $proposal['id'], 92); self::fail('Budget overrun accepted.'); } catch (\RuntimeException $e) { self::assertStringContainsString('budget', strtolower($e->getMessage())); }
        self::assertSame('SUBMITTED', $this->db->table('compensation_review_proposals')->where('id', $proposal['id'])->get()->getRowArray()['status']); self::assertSame(0, $this->db->table('employee_master_history')->where('source_type', 'COMPENSATION_REVIEW')->countAllResults());
    }
}
