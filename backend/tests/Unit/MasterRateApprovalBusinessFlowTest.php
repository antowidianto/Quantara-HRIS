<?php

namespace Tests\Unit;

use App\Libraries\Payroll\MasterRateApprovalService;
use CodeIgniter\Test\CIUnitTestCase;

final class MasterRateApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */ private array $started = [];

    protected function setUp(): void
    {
        parent::setUp(); $this->db = \Config\Database::connect();
        foreach (['master_rate_change_requests','payroll_components','users'] as $table) $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_payroll_components (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, code TEXT, name TEXT, type TEXT, category TEXT, is_taxable INTEGER, is_fixed INTEGER, amount REAL, formula TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_master_rate_change_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, request_number TEXT, entity_type TEXT, change_action TEXT, target_id INTEGER, status TEXT, payload_json TEXT, payload_hash TEXT, target_snapshot_hash TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, rejected_by INTEGER, rejected_at TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id'=>20,'company_id'=>1,'employee_id'=>200,'is_active'=>1]);
        $this->db->table('payroll_components')->insert(['id'=>10,'company_id'=>1,'code'=>'MEAL','name'=>'Meal','type'=>'EARNING','category'=>'ALLOWANCE','amount'=>100000,'is_active'=>1,'updated_at'=>'2026-07-16 09:00:00']);
    }

    public function testCreateOnlyAppliesAfterFinanceApproval(): void
    {
        $service = $this->service();
        $result = $service->submit('PAYROLL_COMPONENT','CREATE',null,['code'=>'SHIFT','name'=>'Shift','type'=>'EARNING','category'=>'ALLOWANCE','amount'=>150000],1,20);
        $this->assertSame('PENDING_APPROVAL', $result['request']['status']);
        $this->assertSame(0, $this->db->table('payroll_components')->where('code','SHIFT')->countAllResults());
        $approved = $service->approve($this->request(),99);
        $this->assertSame('APPROVED',$approved['status']);
        $this->assertSame(1, $this->db->table('payroll_components')->where('code','SHIFT')->countAllResults());
    }

    public function testStaleMasterBlocksUpdateFinalization(): void
    {
        $service=$this->service(); $service->submit('PAYROLL_COMPONENT','UPDATE',10,['amount'=>125000],1,20);
        $this->db->table('payroll_components')->where('id',10)->update(['updated_at'=>'2026-07-16 10:00:00']);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('berubah setelah submit');
        $service->approve($this->request(),99);
    }

    public function testDeleteCarriesHighRiskMetadataAndWaits(): void
    {
        $service=$this->service(); $service->submit('PAYROLL_COMPONENT','DELETE',10,[],1,20);
        $this->assertSame(1,$this->started['meta']['is_delete']);
        $this->assertNull($this->db->table('payroll_components')->where('id',10)->get()->getRowArray()['deleted_at']);
    }

    private function service(): MasterRateApprovalService
    { return new MasterRateApprovalService($this->db,function(array $params): array {$this->started=$params; return ['id'=>900,'status'=>'PENDING'];}); }
    /** @return array<string,mixed> */
    private function request(): array
    { return ['id'=>900,'company_id'=>1,'module'=>'MASTER_RATE_CHANGE','entity_table'=>'master_rate_change_requests','entity_id'=>$this->started['entity_id'],'employee_id'=>200,'meta'=>$this->started['meta']]; }
    protected function tearDown(): void
    { foreach (['master_rate_change_requests','payroll_components','users'] as $table) $this->db->query('DROP TABLE IF EXISTS db_' . $table); parent::tearDown(); }
}
