<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\BonusApprovalDomainAdapter;
use App\Libraries\Payroll\BonusApprovalException;
use App\Libraries\Payroll\BonusApprovalWorkflowService;
use CodeIgniter\Test\CIUnitTestCase;

final class BonusApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['bonus_recipients', 'bonus_runs'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_bonus_runs (id INTEGER PRIMARY KEY, company_id INTEGER, status TEXT, total_amount REAL, recipient_count INTEGER, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, rejected_by INTEGER, rejected_at TEXT, rejection_reason TEXT, updated_at TEXT, deleted_at TEXT)');
        $db->query('CREATE TABLE db_bonus_recipients (id INTEGER PRIMARY KEY, company_id INTEGER, bonus_run_id INTEGER, amount REAL, is_excluded INTEGER)');
    }

    public function testFinalApprovalLocksSnapshotAndRunsExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $this->seedRun(1201, 1201, 300_000);
        $adapter = new BonusApprovalDomainAdapter(new BonusApprovalWorkflowService($db));
        $request = ['entity_id' => 1201, 'company_id' => 1201];
        $adapter->apply($request, 'APPROVED', 12201, 'budget verified');

        $row = $db->table('bonus_runs')->where('id', 1201)->get()->getRowArray();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame(12201, (int) $row['approved_by']);

        try {
            $adapter->apply($request, 'APPROVED', 12201, 'duplicate');
            $this->fail('Duplicate final approval must fail');
        } catch (BonusApprovalException $exception) {
            $this->assertSame('APPROVAL_INVALID_STATE', $exception->errorCode);
        }
    }

    public function testChangedRecipientSnapshotCrossTenantAndShortReasonFailClosed(): void
    {
        $db = \Config\Database::connect();
        $this->seedRun(1202, 1201, 500_000);
        $adapter = new BonusApprovalDomainAdapter(new BonusApprovalWorkflowService($db));
        $db->table('bonus_recipients')->where('bonus_run_id', 1202)->update(['amount' => 600_000]);
        try {
            $adapter->apply(['entity_id' => 1202, 'company_id' => 1201], 'APPROVED', 12201, 'verified');
            $this->fail('Changed total must fail');
        } catch (BonusApprovalException $exception) {
            $this->assertSame('APPROVAL_CONFLICT', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 1202, 'company_id' => 999], 'REJECTED', 12201, 'budget ditolak');
            $this->fail('Cross-tenant decision must fail');
        } catch (BonusApprovalException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 1202, 'company_id' => 1201], 'REJECTED', 12201, 'no');
            $this->fail('Short reason must fail');
        } catch (BonusApprovalException $exception) {
            $this->assertSame('REASON_REQUIRED', $exception->errorCode);
        }
        $this->assertSame('PENDING_APPROVAL', $db->table('bonus_runs')->where('id', 1202)->get()->getRowArray()['status']);
    }

    private function seedRun(int $id, int $companyId, float $amount): void
    {
        $db = \Config\Database::connect();
        $db->table('bonus_runs')->insert([
            'id' => $id, 'company_id' => $companyId, 'status' => 'PENDING_APPROVAL',
            'total_amount' => $amount, 'recipient_count' => 1, 'submitted_by' => 12101,
        ]);
        $db->table('bonus_recipients')->insert([
            'id' => $id, 'company_id' => $companyId, 'bonus_run_id' => $id,
            'amount' => $amount, 'is_excluded' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
