<?php

namespace Tests\Unit;

use App\Libraries\Lifecycle\GeneratedContractApprovalException;
use App\Libraries\Lifecycle\GeneratedContractApprovalWorkflowService;
use CodeIgniter\Test\CIUnitTestCase;

final class GeneratedContractApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach (['generated_contracts', 'employee_contracts', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $this->db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, user_id INTEGER, full_name TEXT, basic_salary REAL, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_employee_contracts (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, contract_type TEXT, basic_salary REAL)');
        $this->db->query('CREATE TABLE db_generated_contracts (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, employee_contract_id INTEGER, template_id INTEGER, contract_number TEXT, subject TEXT, rendered_html TEXT, variables_snapshot TEXT, status TEXT, sent_at TEXT, signed_at TEXT, signed_url TEXT, approval_action TEXT, approval_previous_status TEXT, approval_rejected_by INTEGER, approval_rejected_at TEXT, approval_rejection_reason TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->table('employees')->insert([
            'id' => 10, 'company_id' => 1, 'user_id' => 110, 'full_name' => 'Karyawan',
            'basic_salary' => 10000000, 'deleted_at' => null,
        ]);
        $this->db->table('users')->insert([
            'id' => 20, 'company_id' => 1, 'employee_id' => 20, 'is_active' => 1,
        ]);
        $this->db->table('employee_contracts')->insert([
            'id' => 30, 'company_id' => 1, 'employee_id' => 10,
            'contract_type' => 'PKWTT', 'basic_salary' => 12500000,
        ]);
        $this->insertContract();
    }

    public function testSendWaitsForFinalApprovalAndPublishesSnapshotMetadata(): void
    {
        $service = $this->service();
        $submitted = $service->submit(40, 1, 20, 'SEND');
        $this->assertSame('PENDING_APPROVAL', $submitted['contract']['status']);
        $this->assertSame('GENERATED_CONTRACT', $this->started['module']);
        $this->assertSame(10, $this->started['employee_id']);
        $this->assertSame(2500000.0, $this->started['meta']['amount']);
        $this->assertSame(1, $this->started['meta']['is_salary_change']);
        $this->assertSame(1, $this->started['meta']['is_policy_exception']);
        $this->assertNotEmpty($this->started['meta']['document_snapshot_hash']);

        $request = $this->requestFromStarted();
        $result = $service->approve($request, 99);
        $this->assertSame('SENT', $result['status']);
        $row = $this->db->table('generated_contracts')->where('id', 40)->get()->getRowArray();
        $this->assertSame('SENT', $row['status']);
        $this->assertNotEmpty($row['sent_at']);
        $this->assertNull($row['approval_action']);
    }

    public function testDocumentOrCurrentSalaryChangeBlocksFinalApproval(): void
    {
        $service = $this->service();
        $service->submit(40, 1, 20, 'SEND');
        $request = $this->requestFromStarted();
        $this->db->table('generated_contracts')->where('id', 40)->update(['rendered_html' => '<p>changed</p>']);
        try {
            $service->approve($request, 99);
            $this->fail('Expected stale document conflict');
        } catch (GeneratedContractApprovalException $exception) {
            $this->assertStringContainsString('Isi kontrak berubah', $exception->getMessage());
        }

        $this->db->table('generated_contracts')->where('id', 40)->update(['rendered_html' => '<p>contract</p>']);
        $this->db->table('employees')->where('id', 10)->update(['basic_salary' => 11000000]);
        $this->expectException(GeneratedContractApprovalException::class);
        $this->expectExceptionMessage('Gaji berjalan karyawan berubah');
        $service->approve($request, 99);
    }

    public function testRejectedRevocationRestoresSignedContract(): void
    {
        $this->db->table('generated_contracts')->where('id', 40)->update([
            'status' => 'SIGNED', 'signed_at' => '2026-07-01 10:00:00',
        ]);
        $service = $this->service();
        $service->submit(40, 1, 20, 'REVOKE');
        $this->assertSame(1, $this->started['meta']['is_policy_exception']);
        $result = $service->reject(40, 1, 99, 'pencabutan tidak disetujui');
        $this->assertSame('SIGNED', $result['status']);
        $row = $this->db->table('generated_contracts')->where('id', 40)->get()->getRowArray();
        $this->assertSame('SIGNED', $row['status']);
        $this->assertSame('pencabutan tidak disetujui', $row['approval_rejection_reason']);
    }

    public function testCrossTenantAndShortRejectReasonAreRejected(): void
    {
        $service = $this->service();
        try {
            $service->submit(40, 2, 20, 'SEND');
            $this->fail('Expected tenant rejection');
        } catch (GeneratedContractApprovalException $exception) {
            $this->assertSame(404, $exception->httpStatus);
        }
        $service->submit(40, 1, 20, 'SEND');
        $this->expectException(GeneratedContractApprovalException::class);
        $this->expectExceptionMessage('minimal 5 karakter');
        $service->reject(40, 1, 99, 'no');
    }

    private function service(): GeneratedContractApprovalWorkflowService
    {
        return new GeneratedContractApprovalWorkflowService($this->db, function (array $params): array {
            $this->started = $params;
            return ['id' => 700, 'status' => 'PENDING'];
        });
    }

    /** @return array<string,mixed> */
    private function requestFromStarted(): array
    {
        return [
            'id' => 700, 'company_id' => 1, 'entity_id' => 40,
            'entity_table' => 'generated_contracts', 'employee_id' => 10,
            'module' => 'GENERATED_CONTRACT', 'meta' => $this->started['meta'],
        ];
    }

    private function insertContract(): void
    {
        $variables = [
            'contract' => ['contract_type' => 'PKWTT', 'basic_salary' => 12500000],
            'salary' => ['basic' => 12500000],
        ];
        $this->db->table('generated_contracts')->insert([
            'id' => 40, 'company_id' => 1, 'employee_id' => 10,
            'employee_contract_id' => 30, 'template_id' => 50,
            'contract_number' => 'CTR-001', 'subject' => 'Kontrak PKWTT',
            'rendered_html' => '<p>contract</p>',
            'variables_snapshot' => json_encode($variables), 'status' => 'DRAFT',
            'created_by' => 20, 'created_at' => '2026-07-16 10:00:00',
            'updated_at' => '2026-07-16 10:00:00', 'deleted_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['generated_contracts', 'employee_contracts', 'users', 'employees'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
