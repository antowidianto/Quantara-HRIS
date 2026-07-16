<?php

namespace Tests\Unit;

use App\Libraries\Recruitment\HireApprovalException;
use App\Libraries\Recruitment\HireApprovalWorkflowService;
use CodeIgniter\Test\CIUnitTestCase;

final class HireApprovalBusinessFlowTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $started = [];
    private int $conversions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        foreach (['candidate_offers', 'job_postings', 'users', 'candidates'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        $this->db->query('CREATE TABLE db_users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE db_candidates (id INTEGER PRIMARY KEY, company_id INTEGER, job_posting_id INTEGER, full_name TEXT, current_stage TEXT, converted_employee_id INTEGER, hire_approval_status TEXT, hire_payload_encrypted TEXT, hire_payload_hash TEXT, hire_submitted_by INTEGER, hire_submitted_at TEXT, hire_approved_by INTEGER, hire_approved_at TEXT, hire_rejected_by INTEGER, hire_rejected_at TEXT, hire_rejection_reason TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_job_postings (id INTEGER PRIMARY KEY, company_id INTEGER, position_id INTEGER, department_id INTEGER, branch_id INTEGER, status TEXT, headcount INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE db_candidate_offers (id INTEGER PRIMARY KEY, company_id INTEGER, candidate_id INTEGER, job_posting_id INTEGER, position_id INTEGER, department_id INTEGER, branch_id INTEGER, employment_status TEXT, proposed_join_date TEXT, basic_salary REAL, status TEXT, responded_at TEXT, deleted_at TEXT)');
        $this->db->table('users')->insert(['id' => 20, 'company_id' => 1, 'employee_id' => 200, 'is_active' => 1]);
        $this->db->table('candidates')->insert(['id' => 10, 'company_id' => 1, 'job_posting_id' => 30,
            'full_name' => 'Calon Karyawan', 'current_stage' => 'HIRED', 'updated_at' => '2026-07-16 09:00:00', 'deleted_at' => null]);
        $this->db->table('job_postings')->insert(['id' => 30, 'company_id' => 1, 'position_id' => 3,
            'department_id' => 4, 'branch_id' => 5, 'status' => 'OPEN', 'headcount' => 2, 'deleted_at' => null]);
        $this->db->table('candidate_offers')->insert(['id' => 40, 'company_id' => 1, 'candidate_id' => 10,
            'job_posting_id' => 30, 'position_id' => 3, 'department_id' => 4, 'branch_id' => 5,
            'employment_status' => 'PKWTT', 'proposed_join_date' => '2026-08-01', 'basic_salary' => 10000000,
            'status' => 'ACCEPTED', 'responded_at' => '2026-07-15 10:00:00', 'deleted_at' => null]);
    }

    public function testSensitiveHirePayloadWaitsEncryptedUntilFinalApproval(): void
    {
        $service = $this->service();
        $result = $service->submit(10, 1, 20, [
            'basic_salary' => 10000000, 'employment_status' => 'PKWTT',
            'join_date' => '2026-08-01', 'bank_account' => '1234567890', 'npwp' => '01.234.567.8-999.000',
        ]);
        $this->assertSame('PENDING', $result['candidate']['hire_approval_status']);
        $this->assertNull($result['candidate']['converted_employee_id']);
        $this->assertSame(0, $this->conversions);
        $encrypted = (string) $result['candidate']['hire_payload_encrypted'];
        $this->assertStringNotContainsString('1234567890', $encrypted);
        $this->assertStringNotContainsString('01.234.567', $encrypted);
        $this->assertSame('HIRE_CONVERSION', $this->started['module']);

        $approved = $service->approve($this->request(), 99);
        $this->assertSame(501, $approved['employee_id']);
        $this->assertSame(1, $this->conversions);
        $candidate = $this->db->table('candidates')->where('id', 10)->get()->getRowArray();
        $this->assertSame('APPROVED', $candidate['hire_approval_status']);
        $this->assertSame(501, (int) $candidate['converted_employee_id']);
    }

    public function testMaterialTermsCannotDifferFromAcceptedOffer(): void
    {
        $this->expectException(HireApprovalException::class);
        $this->expectExceptionMessage('basic_salary berbeda dari offer');
        $this->service()->submit(10, 1, 20, ['basic_salary' => 12000000]);
    }

    public function testJoinDateChangeTriggersDirectorPolicyMetadata(): void
    {
        $this->service()->submit(10, 1, 20, ['join_date' => '2026-08-15']);
        $this->assertSame(1, $this->started['meta']['join_date_changed']);
        $this->assertSame(1, $this->started['meta']['is_policy_exception']);
    }

    public function testStaleOfferBlocksFinalConversion(): void
    {
        $service = $this->service();
        $service->submit(10, 1, 20, []);
        $this->db->table('candidate_offers')->where('id', 40)->update(['proposed_join_date' => '2026-09-01']);
        $this->expectException(HireApprovalException::class);
        $this->expectExceptionMessage('berubah setelah submit');
        $service->approve($this->request(), 99);
    }

    public function testRejectRequiresReasonAndAllowsCleanResubmit(): void
    {
        $service = $this->service();
        $service->submit(10, 1, 20, []);
        try {
            $service->reject(10, 1, 99, 'no');
            $this->fail('Expected short reason rejection');
        } catch (HireApprovalException $exception) {
            $this->assertSame(422, $exception->httpStatus);
        }
        $result = $service->reject(10, 1, 99, 'data payroll belum lengkap');
        $this->assertSame('HIRE_REJECTED', $result['status']);
        $resubmitted = $service->submit(10, 1, 20, []);
        $this->assertSame('PENDING', $resubmitted['candidate']['hire_approval_status']);
    }

    private function service(): HireApprovalWorkflowService
    {
        return new HireApprovalWorkflowService(
            $this->db,
            function (array $params): array {
                $this->started = $params;
                return ['id' => 900, 'status' => 'PENDING'];
            },
            function (int $candidateId, int $companyId, array $payload): array {
                $this->conversions++;
                $this->assertSame('1234567890', $payload['bank_account'] ?? '1234567890');
                $this->db->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)
                    ->update(['converted_employee_id' => 501, 'current_stage' => 'HIRED']);
                return ['employee_id' => 501, 'user_id' => null, 'user_email' => null,
                    'temp_password' => null, 'auto_completed_tasks' => 0];
            },
        );
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return ['id' => 900, 'company_id' => 1, 'module' => 'HIRE_CONVERSION',
            'entity_table' => 'candidates', 'entity_id' => 10, 'employee_id' => 200,
            'meta' => $this->started['meta']];
    }

    protected function tearDown(): void
    {
        foreach (['candidate_offers', 'job_postings', 'users', 'candidates'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS db_' . $table);
        }
        parent::tearDown();
    }
}
