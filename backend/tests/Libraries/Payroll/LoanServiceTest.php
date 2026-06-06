<?php

namespace Tests\Libraries\Payroll;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Libraries\Payroll\LoanService;
use App\Models\Payroll\LoanModel;
use App\Models\Payroll\LoanInstallmentModel;

class LoanServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // For testing we will just use the default test database
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    protected $loanService;
    protected $companyId = 1;
    protected $employeeId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed dummy company and employee
        $db = \Config\Database::connect();
        
        $db->table('companies')->ignore(true)->insert([
            'id' => $this->companyId,
            'name' => 'PT Test Company',
            'code' => 'TEST',
            'domain' => 'test',
        ]);
        
        $db->table('employees')->ignore(true)->insert([
            'id' => $this->employeeId,
            'company_id' => $this->companyId,
            'full_name' => 'John Doe Test',
            'employee_number' => 'EMP-001',
            'basic_salary' => 5000000,
            'is_active' => 1,
            'join_date' => date('Y-m-d', strtotime('-1 year')),
        ]);

        $this->loanService = new LoanService();
    }

    public function testApplyLoan()
    {
        $principal = 12000000;
        $interestRate = 10; // 10% flat
        $installments = 12;
        $reason = 'Need to buy a new laptop';

        $loan = $this->loanService->applyLoan(
            $this->companyId,
            $this->employeeId,
            $principal,
            $interestRate,
            $installments,
            $reason
        );

        $this->assertNotEmpty($loan);
        $this->assertEquals($principal, $loan['principal']);
        $this->assertEquals($interestRate, $loan['interest_rate']);
        
        // Total interest = 12,000,000 * (10/100) * (12/12) = 1,200,000
        $this->assertEquals(1200000, $loan['total_interest']);
        
        // Total amount = 13,200,000
        $this->assertEquals(13200000, $loan['total_amount']);
        $this->assertEquals(13200000, $loan['remaining']);
        
        // Installment = 13,200,000 / 12 = 1,100,000
        $this->assertEquals(1100000, $loan['installment_amount']);
        
        $this->assertEquals('PENDING', $loan['status']);
        
        return $loan['id']; // Pass to next test
    }

    /**
     * @depends testApplyLoan
     */
    public function testApproveLoan($loanId)
    {
        $approverUserId = 99; // Mock user
        $firstInstallmentDate = date('Y-m-01', strtotime('+1 month'));

        $result = $this->loanService->approveLoan($loanId, $approverUserId, $firstInstallmentDate);
        $this->assertTrue($result);

        $loanModel = new LoanModel();
        $loan = $loanModel->findScoped($loanId);
        
        $this->assertEquals('RUNNING', $loan['status']);
        $this->assertEquals($approverUserId, $loan['approved_by']);
        
        $installmentModel = new LoanInstallmentModel();
        $installments = $installmentModel->scoped()->where('loan_id', $loanId)->findAll();
        
        $this->assertCount(12, $installments);
        $this->assertEquals($firstInstallmentDate, $installments[0]['due_date']);
        $this->assertEquals('UNPAID', $installments[0]['status']);
        $this->assertEquals(1100000, $installments[0]['amount']);
    }
}
