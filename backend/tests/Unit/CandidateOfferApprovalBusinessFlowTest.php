<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Recruitment\CandidateOfferApprovalException;
use App\Libraries\Recruitment\CandidateOfferApprovalWorkflowService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CandidateOfferApprovalBusinessFlowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'foreignKeys' => false, 'DBDebug' => true,
        ], false);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, role_id INTEGER, is_active INTEGER)',
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE candidates (id INTEGER PRIMARY KEY, company_id INTEGER, job_posting_id INTEGER, current_stage TEXT, user_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE job_postings (id INTEGER PRIMARY KEY, company_id INTEGER, hiring_manager_id INTEGER, salary_min REAL, salary_max REAL, deleted_at TEXT)',
            'CREATE TABLE candidate_offers (id INTEGER PRIMARY KEY, company_id INTEGER, candidate_id INTEGER, offer_number TEXT, job_posting_id INTEGER, position_id INTEGER, department_id INTEGER, branch_id INTEGER, employment_status TEXT, proposed_join_date TEXT, probation_months INTEGER, basic_salary REAL, allowances_json TEXT, joining_bonus REAL, benefits_json TEXT, currency TEXT, status TEXT, response_deadline TEXT, sent_at TEXT, sent_by INTEGER, approval_rejected_by INTEGER, approval_rejected_at TEXT, approval_rejection_reason TEXT, deleted_at TEXT, updated_at TEXT)',
        ] as $sql) $this->db->query($sql);
        $this->db->table('employees')->insert(['id' => 10, 'company_id' => 1]);
        $this->db->table('employees')->insert(['id' => 11, 'company_id' => 1]);
        $this->db->table('users')->insert(['id' => 90, 'company_id' => 1, 'employee_id' => 10, 'role_id' => 2, 'is_active' => 1]);
        $this->db->table('users')->insert(['id' => 91, 'company_id' => 1, 'employee_id' => 11, 'role_id' => 3, 'is_active' => 1]);
        $this->db->table('candidates')->insert([
            'id' => 20, 'company_id' => 1, 'job_posting_id' => 30,
            'current_stage' => 'OFFER', 'user_id' => 501,
        ]);
        $this->db->table('job_postings')->insert([
            'id' => 30, 'company_id' => 1, 'hiring_manager_id' => 91,
            'salary_min' => 7000000, 'salary_max' => 9000000,
        ]);
        $this->db->table('candidate_offers')->insert([
            'id' => 40, 'company_id' => 1, 'candidate_id' => 20, 'offer_number' => 'OFR-2099-00001',
            'job_posting_id' => 30, 'position_id' => 2, 'department_id' => 3, 'branch_id' => 4,
            'employment_status' => 'PKWTT', 'proposed_join_date' => '2099-05-01', 'probation_months' => 3,
            'basic_salary' => 8000000, 'allowances_json' => '[{"label":"Transport","amount":500000}]',
            'joining_bonus' => 0, 'benefits_json' => '[]', 'currency' => 'IDR', 'status' => 'DRAFT',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testOfferIsSentOnlyAfterFinalApproval(): void
    {
        $captured = [];
        $workflow = $this->workflow($captured);
        $submitted = $workflow->submit(40, 1, 90, 'Standard offer');

        self::assertSame('PENDING_APPROVAL', $submitted['offer']['status']);
        self::assertSame('CANDIDATE_OFFER', $captured['module']);
        self::assertSame(8500000.0, $captured['meta']['amount']);
        self::assertSame(0, $captured['meta']['is_policy_exception']);
        self::assertSame(30, $captured['meta']['job_posting_id']);

        $result = $workflow->approve([
            'entity_id' => 40, 'company_id' => 1, 'meta' => $captured['meta'],
        ], 92);
        self::assertSame('SENT', $result['status']);
        self::assertSame(501, $result['candidate_user_id']);
        self::assertSame('SENT', $this->offerStatus());
        try {
            $workflow->approve(['entity_id' => 40, 'company_id' => 1, 'meta' => $captured['meta']], 93);
            self::fail('Duplicate final approval was accepted.');
        } catch (CandidateOfferApprovalException $exception) {
            self::assertSame('OFFER_APPROVAL_INVALID_STATE', $exception->errorCode);
        }
    }

    public function testPolicySnapshotTenantAndRejectedResubmissionFailClosed(): void
    {
        $this->db->table('candidate_offers')->where('id', 40)->update([
            'basic_salary' => 9500000, 'joining_bonus' => 2000000,
        ]);
        $captured = [];
        $workflow = $this->workflow($captured);
        $workflow->submit(40, 1, 90);
        self::assertSame(1, $captured['meta']['is_policy_exception']);

        $this->db->table('candidate_offers')->where('id', 40)->update(['basic_salary' => 9750000]);
        try {
            $workflow->approve(['entity_id' => 40, 'company_id' => 1, 'meta' => $captured['meta']], 92);
            self::fail('Changed offer was approved.');
        } catch (CandidateOfferApprovalException $exception) {
            self::assertSame('OFFER_APPROVAL_CONFLICT', $exception->errorCode);
        }
        self::assertSame('PENDING_APPROVAL', $this->offerStatus());
        try {
            $workflow->reject(40, 2, 92, 'Wrong tenant');
            self::fail('Cross-tenant rejection was accepted.');
        } catch (CandidateOfferApprovalException $exception) {
            self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        try {
            $workflow->reject(40, 1, 92, 'no');
            self::fail('Short reason was accepted.');
        } catch (CandidateOfferApprovalException $exception) {
            self::assertSame('REASON_REQUIRED', $exception->errorCode);
        }
        $workflow->reject(40, 1, 92, 'Salary exceeds approved band');
        self::assertSame('APPROVAL_REJECTED', $this->offerStatus());

        $recaptured = [];
        $resubmitted = $this->workflow($recaptured)->submit(40, 1, 90, 'Revised exception');
        self::assertSame('PENDING_APPROVAL', $resubmitted['offer']['status']);
        self::assertNull($resubmitted['offer']['approval_rejection_reason']);
    }

    /** @param array<string,mixed> $captured */
    private function workflow(array &$captured): CandidateOfferApprovalWorkflowService
    {
        return new CandidateOfferApprovalWorkflowService(
            $this->db,
            static function (array $params) use (&$captured): array {
                $captured = $params;
                return ['request_id' => 9001, 'status' => 'PENDING'];
            }
        );
    }

    private function offerStatus(): string
    {
        return (string) $this->db->table('candidate_offers')->where('id', 40)->get()->getRowArray()['status'];
    }
}
