<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\LoanApprovalDomainAdapter;
use App\Libraries\Payroll\LoanApprovalException;
use CodeIgniter\Test\CIUnitTestCase;

final class LoanApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['loan_installments', 'loans', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, full_name TEXT, user_id INTEGER)');
        $db->query('CREATE TABLE db_loans (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, loan_number TEXT, installment_amount REAL, installments INTEGER, status TEXT, first_installment_date TEXT, approved_by INTEGER, approved_at TEXT, rejection_note TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_loan_installments (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, loan_id INTEGER, installment_number INTEGER, due_date TEXT, amount REAL, status TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('employees')->insert([
            'id' => 801, 'company_id' => 801, 'full_name' => 'Loan Test', 'user_id' => 9801,
        ]);
    }

    public function testFinalApprovalCreatesInstallmentsExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('loans')->insert([
            'id' => 801, 'company_id' => 801, 'employee_id' => 801,
            'loan_number' => 'LOAN-801', 'installment_amount' => 500_000,
            'installments' => 3, 'status' => 'PENDING', 'first_installment_date' => '2026-08-01',
        ]);
        $adapter = new LoanApprovalDomainAdapter();
        $request = ['entity_id' => 801, 'company_id' => 801];
        $result = $adapter->apply($request, 'APPROVED', 8801, 'eligible');

        $loan = $db->table('loans')->where('id', 801)->get()->getRowArray();
        $installments = $db->table('loan_installments')->where('loan_id', 801)
            ->orderBy('installment_number', 'ASC')->get()->getResultArray();
        $this->assertSame('RUNNING', $loan['status']);
        $this->assertSame(8801, (int) $loan['approved_by']);
        $this->assertSame(3, $result['installments_created']);
        $this->assertCount(3, $installments);
        $this->assertSame('2026-08-01', $installments[0]['due_date']);
        $this->assertSame('2026-10-01', $installments[2]['due_date']);

        try {
            $adapter->apply($request, 'APPROVED', 8801, 'duplicate');
            $this->fail('Duplicate final approval must fail');
        } catch (LoanApprovalException) {
            $this->assertSame(3, $db->table('loan_installments')->where('loan_id', 801)->countAllResults());
        }
    }

    public function testRejectRequiresReasonAndIsTenantScoped(): void
    {
        $db = \Config\Database::connect();
        $db->table('loans')->insert([
            'id' => 802, 'company_id' => 801, 'employee_id' => 801,
            'loan_number' => 'LOAN-802', 'installment_amount' => 500_000,
            'installments' => 3, 'status' => 'PENDING', 'first_installment_date' => '2026-08-01',
        ]);
        $adapter = new LoanApprovalDomainAdapter();
        try {
            $adapter->apply(['entity_id' => 802, 'company_id' => 801], 'REJECTED', 8801, 'no');
            $this->fail('Short rejection reason must fail');
        } catch (LoanApprovalException $exception) {
            $this->assertSame('REASON_REQUIRED', $exception->errorCode);
        }
        try {
            $adapter->apply(['entity_id' => 802, 'company_id' => 999], 'REJECTED', 8801, 'not eligible');
            $this->fail('Cross-tenant rejection must fail');
        } catch (LoanApprovalException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame('PENDING', $db->table('loans')->where('id', 802)->get()->getRowArray()['status']);
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
