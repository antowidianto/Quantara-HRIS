<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\PayrollAdjustmentApprovalDomainAdapter;
use App\Libraries\Payroll\PayrollAdjustmentApprovalException;
use App\Libraries\Payroll\PayrollAdjustmentApprovalWorkflowService;
use App\Libraries\Payroll\PayrollAdjustmentService;
use App\Repositories\Payroll\PayrollRepository;
use CodeIgniter\Test\CIUnitTestCase;

final class PayrollAdjustmentApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['payroll_adjustments', 'payroll_periods'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_payroll_periods (id INTEGER PRIMARY KEY, company_id INTEGER, status TEXT, total_gross REAL, total_deduction REAL, total_net REAL, prepared_by INTEGER, prepared_at TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, snapshot_json TEXT, snapshot_hash TEXT, snapshot_created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_payroll_adjustments (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, original_period_id INTEGER, target_period_id INTEGER, reversal_of_id INTEGER UNIQUE, adjustment_type TEXT, amount REAL, reason TEXT, is_taxable INTEGER, status TEXT, created_by INTEGER, created_at TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, posted_by INTEGER, posted_at TEXT, rejected_by INTEGER, rejected_at TEXT, rejection_reason TEXT, updated_at TEXT)');
        $db->table('payroll_periods')->insertBatch([
            ['id' => 1301, 'company_id' => 1301, 'status' => 'FINALIZED'],
            ['id' => 1302, 'company_id' => 1301, 'status' => 'DRAFT'],
        ]);
    }

    public function testFinalDecisionIsTenantScopedAndExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $this->seedAdjustment(1301);
        $adapter = new PayrollAdjustmentApprovalDomainAdapter(
            new PayrollAdjustmentApprovalWorkflowService($db)
        );
        $request = ['entity_id' => 1301, 'company_id' => 1301];
        $adapter->apply($request, 'APPROVED', 13301, 'verified');
        $row = $db->table('payroll_adjustments')->where('id', 1301)->get()->getRowArray();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame(13301, (int) $row['approved_by']);
        try {
            $adapter->apply($request, 'APPROVED', 13301, 'duplicate');
            $this->fail('Duplicate approval must fail');
        } catch (PayrollAdjustmentApprovalException $exception) {
            $this->assertSame('APPROVAL_INVALID_STATE', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 1301, 'company_id' => 999], 'REJECTED', 13301, 'wrong tenant');
            $this->fail('Cross-tenant decision must fail');
        } catch (PayrollAdjustmentApprovalException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
    }

    public function testFinalApprovalRechecksTargetPeriodLockAndRejectRequiresReason(): void
    {
        $db = \Config\Database::connect();
        $this->seedAdjustment(1302);
        $adapter = new PayrollAdjustmentApprovalDomainAdapter(
            new PayrollAdjustmentApprovalWorkflowService($db)
        );
        $db->table('payroll_periods')->where('id', 1302)->update(['status' => 'FINALIZED']);
        try {
            $adapter->apply(['entity_id' => 1302, 'company_id' => 1301], 'APPROVED', 13301, 'verified');
            $this->fail('Locked target period must fail');
        } catch (PayrollAdjustmentApprovalException $exception) {
            $this->assertSame('PAYROLL_PERIOD_LOCKED', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 1302, 'company_id' => 1301], 'REJECTED', 13301, 'no');
            $this->fail('Short rejection reason must fail');
        } catch (PayrollAdjustmentApprovalException $exception) {
            $this->assertSame('REASON_REQUIRED', $exception->errorCode);
        }
        $this->assertSame('PENDING_APPROVAL', $db->table('payroll_adjustments')->where('id', 1302)->get()->getRowArray()['status']);
    }

    public function testReversalSourceChangesOnlyWhenApprovedReversalIsPosted(): void
    {
        $db = \Config\Database::connect();
        $db->table('payroll_adjustments')->insert([
            'id' => 1310, 'company_id' => 1301, 'employee_id' => 1301,
            'original_period_id' => 1301, 'target_period_id' => 1301,
            'adjustment_type' => 'EARNING', 'amount' => 750_000, 'reason' => 'original correction',
            'is_taxable' => 1, 'status' => 'POSTED', 'created_by' => 13101,
        ]);
        $reversal = (new PayrollAdjustmentService($db))->reverse(
            1301, 1310, 13101, 1302, 'reverse duplicated payment'
        );
        $this->assertSame('DRAFT', $reversal['status']);
        $this->assertSame('POSTED', $db->table('payroll_adjustments')->where('id', 1310)->get()->getRowArray()['status']);

        $db->table('payroll_adjustments')->where('id', (int) $reversal['id'])->update(['status' => 'APPROVED']);
        (new PayrollRepository($db))->completeCalculation(1302, 1301, 13401, [
            'total_gross' => 0, 'total_deduction' => 750_000, 'total_net' => -750_000,
        ]);
        $this->assertSame('POSTED', $db->table('payroll_adjustments')->where('id', (int) $reversal['id'])->get()->getRowArray()['status']);
        $this->assertSame('REVERSED', $db->table('payroll_adjustments')->where('id', 1310)->get()->getRowArray()['status']);
    }

    private function seedAdjustment(int $id): void
    {
        \Config\Database::connect()->table('payroll_adjustments')->insert([
            'id' => $id, 'company_id' => 1301, 'employee_id' => 1301,
            'original_period_id' => 1301, 'target_period_id' => 1302,
            'adjustment_type' => 'EARNING', 'amount' => 1_000_000,
            'status' => 'PENDING_APPROVAL', 'submitted_by' => 13101,
        ]);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
