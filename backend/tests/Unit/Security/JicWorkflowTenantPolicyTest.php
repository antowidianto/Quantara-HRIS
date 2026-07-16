<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Employee\JicService;
use App\Libraries\Employee\JicWorkflowException;
use App\Libraries\Employee\JicWorkflowService;
use App\Repositories\Employee\JicWorkflowRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class JicWorkflowTenantPolicyTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private JicWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'foreignKeys' => true, 'DBDebug' => true], false);
        $this->connection->query('CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, user_id INTEGER, deleted_at TEXT)');
        $this->connection->query('CREATE TABLE positions (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, tier TEXT)');
        $this->connection->query('CREATE TABLE job_information_changes (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
            status TEXT NOT NULL, change_type TEXT, salary_change_pct REAL, new_position_id INTEGER,
            employee_consent_required INTEGER DEFAULT 0, employee_consent_status TEXT,
            sk_number TEXT, sk_generated_at TEXT, created_by INTEGER, updated_at TEXT,
            supervisor_user_id INTEGER, supervisor_action_at TEXT, supervisor_decision TEXT, supervisor_note TEXT,
            dept_head_user_id INTEGER, dept_head_action_at TEXT, dept_head_decision TEXT, dept_head_note TEXT,
            hr_user_id INTEGER, hr_action_at TEXT, hr_decision TEXT, hr_note TEXT,
            bod_user_id INTEGER, bod_action_at TEXT, bod_decision TEXT, bod_note TEXT,
            employee_consent_at TEXT, employee_consent_note TEXT, employee_consent_signed_path TEXT,
            cancelled_at TEXT, cancelled_by INTEGER, cancellation_reason TEXT
        )');
        foreach ([1, 2] as $companyId) {
            $this->connection->table('employees')->insert(['id' => $companyId, 'company_id' => $companyId, 'user_id' => $companyId * 10]);
            $this->connection->table('positions')->insert(['id' => $companyId, 'company_id' => $companyId, 'tier' => 'STAFF']);
            $this->connection->table('job_information_changes')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'employee_id' => $companyId,
                'status' => 'PENDING_SUPERVISOR', 'change_type' => 'LATERAL',
                'employee_consent_status' => 'NOT_REQUIRED', 'created_by' => $companyId * 10,
            ]);
        }
        $this->workflow = new JicWorkflowService(new JicWorkflowRepository($this->connection), new JicService());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function testSupervisorApprovalUpdatesOnlyOwnedTenantRow(): void
    {
        $row = $this->row(1);
        $result = $this->workflow->approve($row, 91, 'Looks good', true, false, false, false);
        self::assertSame('PENDING_DEPT_HEAD', $result['status']);
        self::assertSame('PENDING_DEPT_HEAD', $this->row(1)['status']);
        self::assertSame('PENDING_SUPERVISOR', $this->row(2)['status']);
        self::assertSame(91, (int) $this->row(1)['supervisor_user_id']);
    }

    public function testUnauthorizedApprovalFailsWithoutMutation(): void
    {
        try {
            $this->workflow->approve($this->row(1), 92, null, false, false, false, false);
            self::fail('Unauthorized JIC approval must fail');
        } catch (JicWorkflowException $exception) {
            self::assertSame(403, $exception->httpStatus);
        }
        self::assertSame('PENDING_SUPERVISOR', $this->row(1)['status']);
    }

    public function testConsentAndCancellationRemainTenantScoped(): void
    {
        $this->connection->table('job_information_changes')->where('id', 1)->where('company_id', 1)->update([
            'status' => 'PENDING_EMPLOYEE_CONSENT', 'employee_consent_required' => 1, 'employee_consent_status' => 'PENDING',
        ]);
        $status = $this->workflow->consent($this->row(1), 10, ['decision' => 'AGREED', 'note' => 'Agreed']);
        self::assertSame('APPROVED', $status);
        self::assertSame('APPROVED', $this->row(1)['status']);
        self::assertSame('PENDING_SUPERVISOR', $this->row(2)['status']);
        self::assertStringStartsWith('SK-JIC/', (string) $this->row(1)['sk_number']);

        $this->workflow->cancel($this->row(1), 10, true, 'Correction required');
        self::assertSame('CANCELLED', $this->row(1)['status']);
        self::assertSame('PENDING_SUPERVISOR', $this->row(2)['status']);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return $this->connection->table('job_information_changes')->where('id', $id)->get()->getRowArray();
    }
}
