<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Training\TrainingService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class TrainingApprovalBusinessFlowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'foreignKeys' => false, 'DBDebug' => true,
        ], false);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, is_active INTEGER)',
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, department_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE trainings (id INTEGER PRIMARY KEY, company_id INTEGER, code TEXT, title TEXT, training_type TEXT, start_date TEXT, status TEXT, max_participants INTEGER, cost_per_participant REAL, department_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE training_registrations (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, training_id INTEGER, employee_id INTEGER, status TEXT, registered_at TEXT, approved_at TEXT, approved_by INTEGER, cost REAL, notes TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT, UNIQUE(training_id, employee_id))',
            'CREATE TABLE training_budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, department_id INTEGER, year INTEGER, allocated_amount REAL, used_amount REAL, reserved_amount REAL, created_at TEXT, updated_at TEXT)',
        ] as $sql) $this->db->query($sql);
        $this->db->table('employees')->insert(['id' => 10, 'company_id' => 1, 'department_id' => 7]);
        $this->db->table('employees')->insert(['id' => 20, 'company_id' => 2, 'department_id' => 8]);
        $this->db->table('users')->insert(['id' => 90, 'company_id' => 1, 'employee_id' => 10, 'is_active' => 1]);
        $this->db->table('trainings')->insert([
            'id' => 5, 'company_id' => 1, 'code' => 'TRN-1', 'title' => 'External Selling',
            'training_type' => 'EXTERNAL', 'start_date' => '2099-04-01', 'status' => 'PUBLISHED',
            'max_participants' => 2, 'cost_per_participant' => 3000000, 'department_id' => 7,
        ]);
        $this->db->table('training_budgets')->insert([
            'company_id' => 1, 'department_id' => 7, 'year' => 2099,
            'allocated_amount' => 4000000, 'used_amount' => 0, 'reserved_amount' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testRegistrationStartsGovernedApprovalAndReservesBudgetOnlyAtFinalApproval(): void
    {
        $captured = [];
        $service = new TrainingService($this->db, static function (array $params) use (&$captured): array {
            $captured = $params;
            return ['request_id' => 7002, 'status' => 'PENDING'];
        });
        $result = $service->register(1, 5, 10, 90, 'Need certification');

        self::assertSame('TRAINING_REGISTRATION', $captured['module']);
        self::assertSame('training_registrations', $captured['entity_table']);
        self::assertSame(3000000.0, $captured['meta']['amount']);
        self::assertTrue($captured['meta']['is_external']);
        self::assertSame(0.0, $this->reserved());

        try {
            $service->register(1, 5, 10, 90);
            self::fail('Active registration was overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('aktif', $exception->getMessage());
        }

        $service->approveRegistration((int) $result['id'], 1, 91);
        self::assertSame('APPROVED', $this->registrationStatus((int) $result['id']));
        self::assertSame(3000000.0, $this->reserved());
        try {
            $service->approveRegistration((int) $result['id'], 1, 92);
            self::fail('Duplicate approval was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('REGISTERED', $exception->getMessage());
        }
        $service->cancelRegistration((int) $result['id'], 1);
        self::assertSame('CANCELLED', $this->registrationStatus((int) $result['id']));
        self::assertSame(0.0, $this->reserved());
    }

    public function testBudgetCostTenantRejectAndResubmissionControlsFailClosed(): void
    {
        $service = new TrainingService($this->db, static fn (array $params): array => [
            'request_id' => 7003, 'status' => 'PENDING', 'params' => $params,
        ]);
        $result = $service->register(1, 5, 10, 90);
        $this->db->table('trainings')->where('id', 5)->update(['cost_per_participant' => 3500000]);
        try {
            $service->approveRegistration((int) $result['id'], 1, 91);
            self::fail('Changed training cost was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('berubah', $exception->getMessage());
        }
        self::assertSame(0.0, $this->reserved());
        try {
            $service->rejectRegistration((int) $result['id'], 2, 91, 'Wrong tenant');
            self::fail('Cross-tenant decision was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('tidak ditemukan', $exception->getMessage());
        }
        try {
            $service->rejectRegistration((int) $result['id'], 1, 91, 'no');
            self::fail('Short reason was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('minimal', $exception->getMessage());
        }
        $service->rejectRegistration((int) $result['id'], 1, 91, 'Cost snapshot changed');
        self::assertSame('REJECTED', $this->registrationStatus((int) $result['id']));
        $resubmitted = $service->register(1, 5, 10, 90, 'Updated cost accepted');
        self::assertSame((int) $result['id'], (int) $resubmitted['id']);
        self::assertSame('REGISTERED', $this->registrationStatus((int) $result['id']));
        self::assertSame(3500000.0, (float) $this->db->table('training_registrations')
            ->where('id', $result['id'])->get()->getRowArray()['cost']);
    }

    private function registrationStatus(int $id): string
    {
        return (string) $this->db->table('training_registrations')->where('id', $id)->get()->getRowArray()['status'];
    }

    private function reserved(): float
    {
        return (float) $this->db->table('training_budgets')->where('company_id', 1)->get()->getRowArray()['reserved_amount'];
    }
}
