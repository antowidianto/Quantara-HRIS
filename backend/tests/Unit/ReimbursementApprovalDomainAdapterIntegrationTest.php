<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\ReimbursementApprovalDomainAdapter;
use App\Libraries\Payroll\ReimbursementApprovalException;
use CodeIgniter\Test\CIUnitTestCase;

final class ReimbursementApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['employee_benefit_balances', 'benefit_policies', 'reimbursements', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, full_name TEXT, user_id INTEGER)');
        $db->query('CREATE TABLE db_reimbursements (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, request_number TEXT, category TEXT, expense_date TEXT, amount REAL, status TEXT, approved_by INTEGER, approved_at TEXT, rejection_note TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_benefit_policies (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, limit_amount REAL)');
        $db->query('CREATE TABLE db_employee_benefit_balances (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, policy_id INTEGER, year INTEGER, allocated_amount REAL, used_amount REAL, created_at TEXT, updated_at TEXT)');
        $db->table('employees')->insert([
            'id' => 701, 'company_id' => 701, 'full_name' => 'Reimbursement Test', 'user_id' => 9701,
        ]);
        $db->table('benefit_policies')->insert([
            'id' => 701, 'company_id' => 701, 'name' => 'MEDICAL', 'limit_amount' => 1_000_000,
        ]);
    }

    public function testFinalApprovalConsumesBenefitExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('employee_benefit_balances')->insert([
            'id' => 701, 'company_id' => 701, 'employee_id' => 701, 'policy_id' => 701,
            'year' => 2026, 'allocated_amount' => 1_000_000, 'used_amount' => 100_000,
        ]);
        $db->table('reimbursements')->insert([
            'id' => 701, 'company_id' => 701, 'employee_id' => 701,
            'request_number' => 'RB-701', 'category' => 'MEDICAL',
            'expense_date' => '2026-07-10', 'amount' => 250_000, 'status' => 'PENDING',
        ]);
        $adapter = new ReimbursementApprovalDomainAdapter();
        $request = ['entity_id' => 701, 'company_id' => 701];
        $result = $adapter->apply($request, 'APPROVED', 7701, 'verified');

        $claim = $db->table('reimbursements')->where('id', 701)->get()->getRowArray();
        $balance = $db->table('employee_benefit_balances')->where('id', 701)->get()->getRowArray();
        $this->assertSame('APPROVED', $claim['status']);
        $this->assertSame(7701, (int) $claim['approved_by']);
        $this->assertSame(350_000.0, (float) $balance['used_amount']);
        $this->assertSame(701, (int) $result['benefit_balance_id']);

        try {
            $adapter->apply($request, 'APPROVED', 7701, 'duplicate');
            $this->fail('Duplicate final approval must fail');
        } catch (ReimbursementApprovalException) {
            $balance = $db->table('employee_benefit_balances')->where('id', 701)->get()->getRowArray();
            $this->assertSame(350_000.0, (float) $balance['used_amount']);
        }
    }

    public function testInsufficientBalanceAndWrongTenantDoNotMutateClaim(): void
    {
        $db = \Config\Database::connect();
        $db->table('employee_benefit_balances')->insert([
            'id' => 702, 'company_id' => 701, 'employee_id' => 701, 'policy_id' => 701,
            'year' => 2026, 'allocated_amount' => 1_000_000, 'used_amount' => 900_000,
        ]);
        $db->table('reimbursements')->insert([
            'id' => 702, 'company_id' => 701, 'employee_id' => 701,
            'request_number' => 'RB-702', 'category' => 'MEDICAL',
            'expense_date' => '2026-07-10', 'amount' => 250_000, 'status' => 'PENDING',
        ]);
        $adapter = new ReimbursementApprovalDomainAdapter();

        try {
            $adapter->apply(['entity_id' => 702, 'company_id' => 701], 'APPROVED', 7701, 'verified');
            $this->fail('Insufficient balance must fail');
        } catch (ReimbursementApprovalException $exception) {
            $this->assertSame('BENEFIT_BALANCE_EXCEEDED', $exception->errorCode);
        }
        $claim = $db->table('reimbursements')->where('id', 702)->get()->getRowArray();
        $balance = $db->table('employee_benefit_balances')->where('id', 702)->get()->getRowArray();
        $this->assertSame('PENDING', $claim['status']);
        $this->assertSame(900_000.0, (float) $balance['used_amount']);

        try {
            $adapter->apply(['entity_id' => 702, 'company_id' => 999], 'APPROVED', 7701, 'verified');
            $this->fail('Cross-tenant approval must fail');
        } catch (ReimbursementApprovalException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame('PENDING', $db->table('reimbursements')->where('id', 702)->get()->getRowArray()['status']);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
