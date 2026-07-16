<?php

namespace Tests\Unit;

use App\Libraries\Asset\AssetApprovalException;
use App\Libraries\Asset\AssetApprovalWorkflowService;
use CodeIgniter\Test\CIUnitTestCase;

final class AssetApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach ($this->tables() as $table) $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_companies (id INTEGER PRIMARY KEY, name TEXT, address TEXT)');
        $this->db->query('CREATE TABLE db_positions (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->query('CREATE TABLE db_departments (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, full_name TEXT, employee_number TEXT, position_id INTEGER, department_id INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_assets (id INTEGER PRIMARY KEY, company_id INTEGER, asset_number TEXT, category TEXT, name TEXT, brand TEXT, model TEXT, serial_number TEXT, purchase_price REAL, condition TEXT, status TEXT, current_assignment_id INTEGER, current_employee_id INTEGER, branch_id INTEGER, approval_action TEXT, approval_employee_id INTEGER, approval_assignment_id INTEGER, approval_payload TEXT, approval_rejected_by INTEGER, approval_rejected_at TEXT, approval_rejection_reason TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_asset_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, asset_id INTEGER, employee_id INTEGER, assigned_at TEXT, returned_at TEXT, expected_return TEXT, purpose TEXT, condition_when_assigned TEXT, condition_when_returned TEXT, damage_note TEXT, bast_html TEXT, bast_return_html TEXT, employee_acknowledged_at TEXT, returned_acknowledged_at TEXT, assigned_by INTEGER, returned_by INTEGER, created_at TEXT, updated_at TEXT)');
        $this->db->table('companies')->insert(['id' => 1, 'name' => 'FMCG Test', 'address' => 'Jakarta']);
        $this->db->table('employees')->insert(['id' => 10, 'company_id' => 1, 'user_id' => 110, 'full_name' => 'Pemegang Asset', 'employee_number' => 'EMP-10', 'deleted_at' => null]);
        $this->db->table('users')->insert(['id' => 20, 'company_id' => 1, 'employee_id' => 20, 'is_active' => 1]);
        $this->db->table('assets')->insert(['id' => 40, 'company_id' => 1, 'asset_number' => 'AST-40', 'category' => 'LAPTOP', 'name' => 'Laptop Sales', 'purchase_price' => 60000000, 'condition' => 'GOOD', 'status' => 'AVAILABLE', 'updated_at' => '2026-07-16 10:00:00', 'deleted_at' => null]);
    }

    public function testAssignmentIsCreatedOnlyAfterFinalApproval(): void
    {
        $service = $this->service();
        $submitted = $service->submitAssign(40, 1, 20, 10, ['purpose' => 'Sales visit']);
        $this->assertSame('ASSIGN', $submitted['asset']['approval_action']);
        $this->assertSame(0, $this->db->table('asset_assignments')->countAllResults());
        $this->assertSame(0, $this->started['meta']['is_loss_or_damage']);

        $result = $service->approve($this->request(), 99);
        $this->assertSame('ASSIGNED', $result['status']);
        $this->assertSame(1, $this->db->table('asset_assignments')->countAllResults());
        $asset = $this->db->table('assets')->where('id', 40)->get()->getRowArray();
        $this->assertSame('ASSIGNED', $asset['status']);
        $this->assertSame(10, (int) $asset['current_employee_id']);
        $this->assertNull($asset['approval_action']);
    }

    public function testLostHighValueReturnUsesExtraRiskMetadataAndFinalizesOnce(): void
    {
        $this->makeAssigned();
        $service = $this->service();
        $service->submitReturn(40, 1, 20, ['condition_when_returned' => 'LOST', 'damage_note' => 'Tidak ditemukan']);
        $this->assertSame(1, $this->started['meta']['is_loss_or_damage']);
        $this->assertSame(1, $this->started['meta']['is_high_value_loss']);
        $this->assertNull($this->db->table('asset_assignments')->where('id', 70)->get()->getRowArray()['returned_at']);

        $result = $service->approve($this->request(), 99);
        $this->assertSame('LOST', $result['status']);
        $this->assertNotEmpty($this->db->table('asset_assignments')->where('id', 70)->get()->getRowArray()['returned_at']);
        $this->expectException(AssetApprovalException::class);
        $service->approve($this->request(), 99);
    }

    public function testRejectedTransactionLeavesPhysicalAssetStateUntouched(): void
    {
        $service = $this->service();
        $service->submitAssign(40, 1, 20, 10, []);
        $result = $service->reject(40, 1, 99, 'assignment belum diperlukan');
        $this->assertSame('AVAILABLE', $result['status']);
        $asset = $this->db->table('assets')->where('id', 40)->get()->getRowArray();
        $this->assertSame('AVAILABLE', $asset['status']);
        $this->assertNull($asset['approval_action']);
        $this->assertSame(0, $this->db->table('asset_assignments')->countAllResults());
    }

    public function testStaleOrCrossTenantAssetIsRejected(): void
    {
        $service = $this->service();
        try {
            $service->submitAssign(40, 2, 20, 10, []);
            $this->fail('Expected tenant rejection');
        } catch (AssetApprovalException $exception) {
            $this->assertSame(404, $exception->httpStatus);
        }
        $service->submitAssign(40, 1, 20, 10, []);
        $this->db->table('assets')->where('id', 40)->update(['purchase_price' => 70000000]);
        $this->expectException(AssetApprovalException::class);
        $this->expectExceptionMessage('Asset berubah setelah submit');
        $service->approve($this->request(), 99);
    }

    private function service(): AssetApprovalWorkflowService
    {
        return new AssetApprovalWorkflowService($this->db, function (array $params): array {
            $this->started = $params;
            return ['id' => 800, 'status' => 'PENDING'];
        });
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return ['id' => 800, 'company_id' => 1, 'module' => 'ASSET_TRANSACTION',
            'entity_table' => 'assets', 'entity_id' => 40, 'employee_id' => 10,
            'meta' => $this->started['meta']];
    }

    private function makeAssigned(): void
    {
        $this->db->table('asset_assignments')->insert(['id' => 70, 'company_id' => 1, 'asset_id' => 40,
            'employee_id' => 10, 'assigned_at' => '2026-07-01 10:00:00', 'condition_when_assigned' => 'GOOD']);
        $this->db->table('assets')->where('id', 40)->update(['status' => 'ASSIGNED', 'current_assignment_id' => 70, 'current_employee_id' => 10]);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['asset_assignments', 'assets', 'users', 'employees', 'positions', 'departments', 'companies'];
    }

    protected function tearDown(): void
    {
        foreach ($this->tables() as $table) $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
