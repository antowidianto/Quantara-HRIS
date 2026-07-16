<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Leave\LeaveWorkflowException;
use App\Libraries\Leave\LeaveWorkflowService;
use App\Repositories\Leave\LeaveRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class LeaveWorkflowTenantPolicyTest extends CIUnitTestCase
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

    public function testRepositoryFailsClosedWithoutCompanyContext(): void
    {
        $repository = new LeaveRepository($this->createMock(BaseConnection::class));
        foreach ([
            static fn () => $repository->employee(1, 0),
            static fn () => $repository->leaveType(1, 0),
            static fn () => $repository->leave(1, 0),
            static fn () => $repository->balances(1, 0, 2026),
            static fn () => $repository->paginate(0, null, null, 1, 20),
        ] as $operation) {
            try {
                $operation();
                self::fail('Leave repository must reject a missing company context.');
            } catch (\DomainException $exception) {
                self::assertSame('Active company context is required', $exception->getMessage());
            }
        }
    }

    public function testGuessedCrossTenantLeaveCannotBeApprovedOrCancelled(): void
    {
        $workflow = new LeaveWorkflowService($this->connection);
        foreach ([
            static fn () => $workflow->approve(2, 1, 91),
            static fn () => $workflow->cancel(2, 1, 91, true, false, 'Cancel request', '2026-07-15'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Cross-tenant leave mutation must fail.');
            } catch (LeaveWorkflowException $exception) {
                self::assertSame(404, $exception->status);
            }
        }
        self::assertSame('PENDING', $this->leaveStatus(2));
    }

    public function testApprovalAndCancellationAtomicallyUpdateBalanceAndAttendance(): void
    {
        $workflow = new LeaveWorkflowService($this->connection);
        $workflow->approve(1, 1, 91);

        self::assertSame('APPROVED', $this->leaveStatus(1));
        $balance = $this->connection->table('leave_balances')->where('company_id', 1)->get()->getRowArray();
        self::assertSame(1.0, (float) $balance['used']);
        self::assertSame(11.0, (float) $balance['remaining']);
        self::assertSame(1, $this->connection->table('attendances')->where('company_id', 1)->countAllResults());
        self::assertSame(0, $this->connection->table('attendances')->where('company_id', 2)->countAllResults());

        $workflow->cancel(1, 1, 10, true, false, 'Schedule changed', '2026-07-15');
        self::assertSame('CANCELLED', $this->leaveStatus(1));
        $balance = $this->connection->table('leave_balances')->where('company_id', 1)->get()->getRowArray();
        self::assertSame(0.0, (float) $balance['used']);
        self::assertSame(12.0, (float) $balance['remaining']);
        self::assertSame(0, $this->connection->table('attendances')->where('company_id', 1)->countAllResults());
        self::assertSame('PENDING', $this->leaveStatus(2));
    }

    private function leaveStatus(int $id): string
    {
        $row = $this->connection->table('leaves')->where('id', $id)->get()->getRowArray();
        return (string) $row['status'];
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE employees (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, user_id INTEGER NULL,
            full_name TEXT NOT NULL, deleted_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE leave_types (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL,
            reset_yearly INTEGER NOT NULL, default_days REAL NOT NULL, deleted_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE leaves (
            id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
            leave_type_id INTEGER NOT NULL, request_number TEXT NOT NULL, start_date TEXT NOT NULL,
            end_date TEXT NOT NULL, days REAL NOT NULL, status TEXT NOT NULL, approved_by INTEGER NULL,
            approved_at TEXT NULL, cancellation_reason TEXT NULL, cancelled_by INTEGER NULL,
            cancelled_at TEXT NULL, updated_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE leave_balances (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
            leave_type_id INTEGER NOT NULL, period_year INTEGER NOT NULL, allocated REAL NOT NULL,
            used REAL NOT NULL, remaining REAL NOT NULL, created_at TEXT NULL, updated_at TEXT NULL
        )');
        $this->connection->query('CREATE TABLE attendances (
            id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
            work_date TEXT NOT NULL, status TEXT NOT NULL, clock_in_at TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL
        )');
    }

    private function seed(): void
    {
        foreach ([1, 2] as $companyId) {
            $this->connection->table('employees')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'user_id' => $companyId * 10,
                'full_name' => "Employee {$companyId}",
            ]);
            $this->connection->table('leave_types')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'code' => "ANNUAL-{$companyId}",
                'name' => 'Annual Leave', 'reset_yearly' => 1, 'default_days' => 12,
            ]);
            $this->connection->table('leaves')->insert([
                'id' => $companyId, 'company_id' => $companyId, 'employee_id' => $companyId,
                'leave_type_id' => $companyId, 'request_number' => "LV-{$companyId}",
                'start_date' => '2026-07-20', 'end_date' => '2026-07-20',
                'days' => 1, 'status' => 'PENDING',
            ]);
            $this->connection->table('leave_balances')->insert([
                'company_id' => $companyId, 'employee_id' => $companyId,
                'leave_type_id' => $companyId, 'period_year' => 2026,
                'allocated' => 12, 'used' => 0, 'remaining' => 12,
            ]);
        }
    }
}
