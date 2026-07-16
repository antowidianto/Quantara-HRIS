<?php

namespace Tests\Unit;

use App\Libraries\Employee\EmployeeSensitiveChangeApprovalService;
use CodeIgniter\Test\CIUnitTestCase;

final class EmployeeSensitiveChangeApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach (['employee_sensitive_change_requests', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, employee_number TEXT, nik_encrypted TEXT, nik_hash TEXT, npwp_encrypted TEXT, bank_name TEXT, bank_account_encrypted TEXT, bank_account_holder TEXT, bpjs_kesehatan_no TEXT, bpjs_tk_no TEXT, tax_method TEXT, ptkp_status TEXT, pph21_method TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_employee_sensitive_change_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, request_number TEXT, status TEXT, changed_fields TEXT, payload_encrypted TEXT, payload_hash TEXT, employee_snapshot_hash TEXT, submitted_by INTEGER, submitted_at TEXT, approved_by INTEGER, approved_at TEXT, rejected_by INTEGER, rejected_at TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id' => 20, 'company_id' => 1, 'employee_id' => 200, 'is_active' => 1]);
        $this->db->table('employees')->insert([
            'id' => 10, 'company_id' => 1, 'user_id' => 10, 'employee_number' => 'EMP010',
            'bank_name' => 'Bank Lama', 'tax_method' => 'GROSS', 'ptkp_status' => 'TK/0',
            'pph21_method' => 'TER', 'updated_at' => '2026-07-16 09:00:00', 'deleted_at' => null,
        ]);
    }

    public function testPayloadStaysEncryptedAndOnlyAppliesAfterFinalApproval(): void
    {
        $service = $this->service();
        $result = $service->submit(10, 1, 20, [
            'bank_name' => 'Bank Baru', 'bank_account' => '1234567890', 'npwp' => '01.234.567.8-999.000',
        ]);
        $this->assertSame('PENDING_APPROVAL', $result['request']['status']);
        $this->assertStringNotContainsString('1234567890', (string) $result['request']['payload_encrypted']);
        $this->assertStringNotContainsString('01.234.567', (string) $result['request']['payload_encrypted']);
        $this->assertSame(['bank_name', 'bank_account', 'npwp'], json_decode($result['request']['changed_fields'], true));
        $this->assertSame('EMPLOYEE_SENSITIVE_CHANGE', $this->started['module']);
        $this->assertSame(1, $this->started['meta']['is_payroll_sensitive']);
        $this->assertSame('Bank Lama', $this->db->table('employees')->where('id', 10)->get()->getRowArray()['bank_name']);

        $approved = $service->approve($this->approvalRequest(), 99);
        $this->assertSame('APPROVED', $approved['status']);
        $employee = $this->db->table('employees')->where('id', 10)->get()->getRowArray();
        $this->assertSame('Bank Baru', $employee['bank_name']);
        $this->assertNotEmpty($employee['bank_account_encrypted']);
        $this->assertNotEmpty($employee['npwp_encrypted']);
    }

    public function testSalaryCannotUseSensitiveChangeShortcut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Compensation Review');
        $this->service()->submit(10, 1, 20, ['basic_salary' => 15000000]);
    }

    public function testStaleEmployeeSnapshotBlocksFinalization(): void
    {
        $service = $this->service();
        $service->submit(10, 1, 20, ['employee_number' => 'EMP011']);
        $this->db->table('employees')->where('id', 10)->update(['updated_at' => '2026-07-16 10:00:00']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('berubah setelah submit');
        $service->approve($this->approvalRequest(), 99);
    }

    public function testTenantAndRejectionReasonAreEnforced(): void
    {
        try {
            $this->service()->submit(10, 2, 20, ['bank_name' => 'Lain']);
            $this->fail('Expected tenant isolation');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tenant aktif', $exception->getMessage());
        }
        $service = $this->service();
        $service->submit(10, 1, 20, ['bank_name' => 'Bank Baru']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minimal 5');
        $service->reject((int) $this->started['entity_id'], 1, 99, 'no');
    }

    private function service(): EmployeeSensitiveChangeApprovalService
    {
        return new EmployeeSensitiveChangeApprovalService($this->db, function (array $params): array {
            $this->started = $params;
            return ['id' => 900, 'status' => 'PENDING'];
        });
    }

    /** @return array<string,mixed> */
    private function approvalRequest(): array
    {
        return [
            'id' => 900, 'company_id' => 1, 'module' => 'EMPLOYEE_SENSITIVE_CHANGE',
            'entity_table' => 'employee_sensitive_change_requests', 'entity_id' => $this->started['entity_id'],
            'employee_id' => 10, 'meta' => $this->started['meta'],
        ];
    }

    protected function tearDown(): void
    {
        foreach (['employee_sensitive_change_requests', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
