<?php

namespace Tests\Unit;

use App\Libraries\Performance\IdpApprovalWorkflowService;
use App\Libraries\Performance\IdpService;
use CodeIgniter\Test\CIUnitTestCase;

final class IdpActivationApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach (['performance_reviews', 'performance_cycles', 'individual_development_plans', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, employee_number TEXT, full_name TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_performance_cycles (id INTEGER PRIMARY KEY, company_id INTEGER, code TEXT, name TEXT, start_date TEXT, end_date TEXT, status TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_performance_reviews (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, cycle_id INTEGER, status TEXT, final_score REAL, improvements TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_individual_development_plans (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, cycle_id INTEGER, review_id INTEGER, plan_period_start TEXT, plan_period_end TEXT, gaps_json TEXT, target_competencies_json TEXT, recommended_trainings_json TEXT, action_items_json TEXT, manager_notes TEXT, employee_notes TEXT, status TEXT, progress_percent INTEGER, finalized_at TEXT, completed_at TEXT, created_by INTEGER, activation_submitted_by INTEGER, activation_submitted_at TEXT, activation_approved_by INTEGER, activation_approved_at TEXT, activation_rejected_by INTEGER, activation_rejected_at TEXT, activation_rejection_reason TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id' => 20, 'company_id' => 1, 'employee_id' => 200, 'is_active' => 1]);
        $this->db->table('employees')->insert(['id' => 200, 'company_id' => 1, 'user_id' => 20,
            'employee_number' => 'EMP-200', 'full_name' => 'Budi Manager', 'deleted_at' => null]);
        $this->db->table('performance_cycles')->insert(['id' => 30, 'company_id' => 1, 'code' => 'ANNUAL-2026', 'name' => 'Annual 2026',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'IN_PROGRESS', 'deleted_at' => null]);
        $this->db->table('individual_development_plans')->insert([
            'id' => 10, 'company_id' => 1, 'employee_id' => 200, 'cycle_id' => 30,
            'plan_period_start' => '2026-07-01', 'plan_period_end' => '2026-12-31',
            'action_items_json' => json_encode([['action' => 'Coaching distributor negotiation',
                'owner' => 'Employee', 'due_date' => '2026-10-01', 'status' => 'PENDING']]),
            'status' => 'DRAFT', 'progress_percent' => 0, 'created_by' => 20,
            'created_at' => '2026-07-16 09:00:00', 'updated_at' => '2026-07-16 09:00:00', 'deleted_at' => null,
        ]);
    }

    public function testActivationWaitsForApprovalAndFinalizesOnce(): void
    {
        $service = $this->service();
        $result = $service->submitActivation(10, 1, 20);
        $this->assertSame('PENDING_APPROVAL', $result['idp']['status']);
        $this->assertSame('IDP_ACTIVATION', $this->started['module']);
        $this->assertSame(200, $this->started['employee_id']);
        $this->assertSame(1, $this->started['meta']['action_item_count']);

        $approved = $service->approve($this->request(), 90);
        $this->assertSame('ACTIVE', $approved['status']);
        $idp = $this->db->table('individual_development_plans')->where('id', 10)->get()->getRowArray();
        $this->assertSame('ACTIVE', $idp['status']);
        $this->assertSame(90, (int) $idp['activation_approved_by']);
        $this->assertNotEmpty($idp['finalized_at']);
    }

    public function testMissingOwnerOrDueDateBlocksSubmission(): void
    {
        $this->db->table('individual_development_plans')->where('id', 10)->update([
            'action_items_json' => json_encode([['action' => 'Coaching', 'status' => 'PENDING']]),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('owner dan due_date');
        $this->service()->submitActivation(10, 1, 20);
    }

    public function testMaterialMutationAfterSubmitBlocksActivation(): void
    {
        $service = $this->service();
        $service->submitActivation(10, 1, 20);
        $this->db->table('individual_development_plans')->where('id', 10)
            ->update(['manager_notes' => 'changed after submit']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('berubah setelah submit');
        $service->approve($this->request(), 90);
    }

    public function testRejectRequiresReasonAndAllowsRevisionCycle(): void
    {
        $service = $this->service();
        $service->submitActivation(10, 1, 20);
        $service->reject(10, 1, 90, 'action plan belum spesifik');
        $row = $this->db->table('individual_development_plans')->where('id', 10)->get()->getRowArray();
        $this->assertSame('APPROVAL_REJECTED', $row['status']);
        $resubmitted = $service->submitActivation(10, 1, 20);
        $this->assertSame('PENDING_APPROVAL', $resubmitted['idp']['status']);
    }

    public function testTenantScopedLoadCannotReadAnotherCompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IdpService($this->db))->load(10, 2);
    }

    private function service(): IdpApprovalWorkflowService
    {
        return new IdpApprovalWorkflowService($this->db, function (array $params): array {
            $this->started = $params;
            return ['request_id' => 901, 'status' => 'PENDING'];
        });
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return ['id' => 901, 'company_id' => 1, 'module' => 'IDP_ACTIVATION',
            'entity_table' => 'individual_development_plans', 'entity_id' => 10,
            'employee_id' => 200, 'meta' => $this->started['meta']];
    }

    protected function tearDown(): void
    {
        foreach (['performance_reviews', 'performance_cycles', 'individual_development_plans', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
