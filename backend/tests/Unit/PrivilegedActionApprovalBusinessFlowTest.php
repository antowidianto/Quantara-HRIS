<?php

namespace Tests\Unit;

use App\Libraries\Approval\PrivilegedActionApprovalService;
use CodeIgniter\Test\CIUnitTestCase;

final class PrivilegedActionApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */ private array $started=[];
    protected function setUp():void
    {
        parent::setUp();$this->db=\Config\Database::connect();foreach(['privileged_action_requests','employees','users'] as $t)$this->db->query('DROP TABLE IF EXISTS db_'.$t);
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_privileged_action_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, request_number TEXT, action_group TEXT, http_method TEXT, request_path TEXT, request_hash TEXT, status TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, expires_at TEXT, used_at TEXT, rejected_by INTEGER, rejected_at TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id'=>20,'company_id'=>1,'employee_id'=>200,'is_active'=>1]);$this->db->table('employees')->insert(['id'=>200,'company_id'=>1,'is_active'=>1]);
    }
    public function testExactActionWaitsThenBecomesSingleUse():void
    {
        $s=$this->service();$first=$s->authorizeOrSubmit(1,20,'RBAC','PUT','/api/v1/users/9','hash-a');
        $this->assertSame('PENDING',$first['state']);$this->assertSame(1,$this->started['meta']['is_rbac']);
        $this->assertSame('PENDING',$s->authorizeOrSubmit(1,20,'RBAC','PUT','/api/v1/users/9','hash-a')['state']);
        $s->approve($this->request(),99);
        $this->assertSame('AUTHORIZED',$s->authorizeOrSubmit(1,20,'RBAC','PUT','/api/v1/users/9','hash-a')['state']);
        $row=$this->db->table('privileged_action_requests')->where('id',$this->started['entity_id'])->get()->getRowArray();$this->assertSame('USED',$row['status']);$this->assertNotEmpty($row['used_at']);
        $new=$s->authorizeOrSubmit(1,20,'RBAC','PUT','/api/v1/users/9','hash-a');$this->assertSame('PENDING',$new['state']);$this->assertNotSame($row['id'],$new['request']['id']);
    }
    public function testDifferentBodyHashCannotReuseApproval():void
    {$s=$this->service();$s->authorizeOrSubmit(1,20,'PII_EXPORT','GET','/api/v1/payroll-periods/1/bank-export','hash-a');$s->approve($this->request(),99);$different=$s->authorizeOrSubmit(1,20,'PII_EXPORT','GET','/api/v1/payroll-periods/1/bank-export','hash-b');$this->assertSame('PENDING',$different['state']);}
    private function service():PrivilegedActionApprovalService{return new PrivilegedActionApprovalService($this->db,function(array $p):array{$this->started=$p;return['id'=>900,'status'=>'PENDING'];});}
    /** @return array<string,mixed> */ private function request():array{return['id'=>900,'company_id'=>1,'module'=>'PRIVILEGED_ACTION','entity_table'=>'privileged_action_requests','entity_id'=>$this->started['entity_id'],'employee_id'=>200,'meta'=>$this->started['meta']];}
    protected function tearDown():void{foreach(['privileged_action_requests','employees','users'] as $t)$this->db->query('DROP TABLE IF EXISTS db_'.$t);parent::tearDown();}
}
