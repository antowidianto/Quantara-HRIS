<?php

namespace Tests\Unit;

use App\Libraries\Performance\PerformanceGoalApprovalService;
use CodeIgniter\Test\CIUnitTestCase;

final class PerformanceGoalApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach (['performance_cycles', 'users', 'goals'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_performance_cycles (id INTEGER PRIMARY KEY, company_id INTEGER, start_date TEXT, end_date TEXT, goal_setting_deadline TEXT, status TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_goals (id INTEGER PRIMARY KEY, company_id INTEGER, cycle_id INTEGER, employee_id INTEGER, parent_id INTEGER, goal_type TEXT, category TEXT, title TEXT, description TEXT, measurement_type TEXT, target_value REAL, unit TEXT, weight REAL, due_date TEXT, status TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, approval_rejected_by INTEGER, approval_rejected_at TEXT, approval_rejection_reason TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id' => 20, 'company_id' => 1, 'employee_id' => 200, 'is_active' => 1]);
        $this->db->table('users')->insert(['id' => 21, 'company_id' => 1, 'employee_id' => 201, 'is_active' => 1]);
        $this->db->table('performance_cycles')->insert(['id' => 30, 'company_id' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'goal_setting_deadline' => null,
            'status' => 'GOAL_SETTING', 'deleted_at' => null]);
        $this->db->table('goals')->insert(['id' => 10, 'company_id' => 1, 'cycle_id' => 30,
            'employee_id' => 200, 'goal_type' => 'KPI', 'title' => 'OTIF Distribution',
            'measurement_type' => 'PERCENT', 'target_value' => 98, 'unit' => '%', 'weight' => 100,
            'due_date' => '2026-12-31', 'status' => 'DRAFT', 'updated_at' => '2026-07-16 09:00:00',
            'deleted_at' => null]);
    }

    public function testOwnerSubmitsBeforeManagerCanFinalize(): void
    {
        $service = $this->service();
        $result = $service->submit(10, 1, 200, 20);
        $this->assertSame('PENDING_APPROVAL', $result['goal']['status']);
        $this->assertSame('PERFORMANCE_GOAL', $this->started['module']);
        $this->assertSame(200, $this->started['employee_id']);
        $this->assertSame(20, $this->started['meta']['submitted_by']);

        $approved = $service->approve($this->request(), 90);
        $this->assertSame('APPROVED', $approved['status']);
        $goal = $this->db->table('goals')->where('id', 10)->get()->getRowArray();
        $this->assertSame('APPROVED', $goal['status']);
        $this->assertSame(90, (int) $goal['approved_by']);
    }

    public function testAnotherEmployeeCannotSubmitGoal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hanya pemilik goal');
        $this->service()->submit(10, 1, 201, 21);
    }

    public function testTotalWeightMustEqualOneHundred(): void
    {
        $this->db->table('goals')->where('id', 10)->update(['weight' => 80]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('harus 100%');
        $this->service()->submit(10, 1, 200, 20);
    }

    public function testMaterialMutationAfterSubmitBlocksFinalApproval(): void
    {
        $service = $this->service();
        $service->submit(10, 1, 200, 20);
        $this->db->table('goals')->where('id', 10)->update(['title' => 'Target diubah diam-diam']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('berubah setelah submit');
        $service->approve($this->request(), 90);
    }

    public function testRejectRequiresReasonAndCanBeResubmitted(): void
    {
        $service = $this->service();
        $service->submit(10, 1, 200, 20);
        try {
            $service->reject(10, 1, 90, 'no');
            $this->fail('Expected short rejection reason to fail');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('minimal 5', $exception->getMessage());
        }
        $rejected = $service->reject(10, 1, 90, 'target belum terukur');
        $this->assertSame('APPROVAL_REJECTED', $rejected['status']);
        $resubmitted = $service->submit(10, 1, 200, 20);
        $this->assertSame('PENDING_APPROVAL', $resubmitted['goal']['status']);
    }

    private function service(): PerformanceGoalApprovalService
    {
        return new PerformanceGoalApprovalService($this->db, function (array $params): array {
            $this->started = $params;
            return ['request_id' => 900, 'status' => 'PENDING'];
        });
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return ['id' => 900, 'company_id' => 1, 'module' => 'PERFORMANCE_GOAL',
            'entity_table' => 'goals', 'entity_id' => 10, 'employee_id' => 200,
            'meta' => $this->started['meta']];
    }

    protected function tearDown(): void
    {
        foreach (['performance_cycles', 'users', 'goals'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
