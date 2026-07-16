<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Payroll\BonusWorkflowService;
use App\Libraries\Payroll\BonusApprovalException;
use App\Libraries\Payroll\BonusApprovalWorkflowService;
use App\Repositories\Payroll\BonusRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class BonusTenantPolicyTest extends CIUnitTestCase
{
    private BaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'foreignKeys' => true, 'DBDebug' => true,
        ], false);
        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function testRepositoryFailsClosedBeforeDatabaseAccess(): void
    {
        $repository = new BonusRepository($this->createMock(BaseConnection::class));
        foreach ([
            static fn () => $repository->findScheme(1, 0),
            static fn () => $repository->findRun(1, 0),
            static fn () => $repository->findRecipient(1, 0),
            static fn () => $repository->findEmployee(1, 0),
            static fn () => $repository->paginateSchemes(0, 1, 20, null, ''),
            static fn () => $repository->paginateRuns(0, 1, 20, null, null, null),
        ] as $operation) {
            try {
                $operation();
                self::fail('Bonus repository must reject a missing company context.');
            } catch (\DomainException $exception) {
                self::assertSame('Active company context is required', $exception->getMessage());
            }
        }
    }

    public function testGuessedCrossTenantRunAndRecipientCannotBeMutated(): void
    {
        $workflow = new BonusWorkflowService($this->connection);
        $approval = new BonusApprovalWorkflowService($this->connection);
        foreach ([
            static fn () => $approval->approve(2, 1, 91),
            static fn () => $workflow->overrideRecipient(2, 1, ['amount' => 999999]),
        ] as $operation) {
            try {
                $operation();
                self::fail('Cross-tenant bonus mutation must fail.');
            } catch (\InvalidArgumentException|BonusApprovalException $exception) {
                self::assertStringContainsString('tidak ditemukan', $exception->getMessage());
            }
        }
        self::assertSame('PENDING_APPROVAL', $this->runStatus(2));
        self::assertSame(200.0, $this->recipientAmount(2));
    }

    public function testApprovalAndRecipientAggregateAreScopedToOwningTenant(): void
    {
        $approval = new BonusApprovalWorkflowService($this->connection);
        $approval->approve(1, 1, 91);
        self::assertSame('APPROVED', $this->runStatus(1));
        self::assertSame('PENDING_APPROVAL', $this->runStatus(2));

        $this->connection->table('bonus_runs')->where('id', 1)->update(['status' => 'CALCULATED']);
        $workflow = new BonusWorkflowService($this->connection);
        $workflow->overrideRecipient(1, 1, ['amount' => 150, 'reason' => 'Calibration']);
        self::assertSame(150.0, $this->recipientAmount(1));
        self::assertSame(200.0, $this->recipientAmount(2));
        $run = $this->connection->table('bonus_runs')->where('id', 1)->get()->getRowArray();
        self::assertSame(150.0, (float) $run['total_amount']);
        self::assertSame(1, (int) $run['recipient_count']);
    }

    public function testDisburserMustDifferFromSubmitterAndFinalApprover(): void
    {
        $approval = new BonusApprovalWorkflowService($this->connection);
        $approval->approve(1, 1, 91);
        $workflow = new BonusWorkflowService($this->connection);
        foreach ([91, 81] as $sameActor) {
            try {
                $workflow->markPaid(1, 1, $sameActor);
                self::fail('Maker or approver must not disburse the same bonus run.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('Disburser harus berbeda', $exception->getMessage());
            }
        }
        $workflow->markPaid(1, 1, 101);
        self::assertSame('PAID', $this->runStatus(1));
    }

    private function runStatus(int $id): string
    {
        $row = $this->connection->table('bonus_runs')->where('id', $id)->get()->getRowArray();
        return (string) $row['status'];
    }

    private function recipientAmount(int $id): float
    {
        $row = $this->connection->table('bonus_recipients')->where('id', $id)->get()->getRowArray();
        return (float) $row['amount'];
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE bonus_schemes (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, code TEXT NOT NULL, is_active INTEGER NOT NULL,
            cap_amount REAL NULL, deleted_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE bonus_runs (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, bonus_scheme_id INTEGER NOT NULL,
            status TEXT NOT NULL, total_amount REAL NOT NULL, recipient_count INTEGER NOT NULL,
            submitted_by INTEGER NULL, submitted_at TEXT NULL, approved_by INTEGER NULL, approved_at TEXT NULL,
            paid_by INTEGER NULL, paid_at TEXT NULL,
            rejected_by INTEGER NULL, rejected_at TEXT NULL, rejection_reason TEXT NULL,
            updated_at TEXT NULL, deleted_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE bonus_recipients (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, bonus_run_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL, employee_name TEXT NOT NULL, amount REAL NOT NULL,
            amount_overridden INTEGER NOT NULL DEFAULT 0, override_reason TEXT NULL,
            is_excluded INTEGER NOT NULL DEFAULT 0, exclude_reason TEXT NULL, updated_at TEXT NULL
        )');
    }

    private function seed(): void
    {
        foreach ([1, 2] as $companyId) {
            $this->connection->table('bonus_schemes')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'code' => "BONUS-{$companyId}",
                'is_active' => 1, 'cap_amount' => 1000,
            ]);
            $this->connection->table('bonus_runs')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'bonus_scheme_id' => $companyId,
                'status' => 'PENDING_APPROVAL', 'total_amount' => $companyId * 100,
                'recipient_count' => 1, 'submitted_by' => 81,
            ]);
            $this->connection->table('bonus_recipients')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'bonus_run_id' => $companyId,
                'employee_id' => $companyId, 'employee_name' => "Employee {$companyId}",
                'amount' => $companyId * 100,
            ]);
        }
    }
}
