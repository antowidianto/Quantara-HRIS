<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Timesheet\TimesheetService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class TimesheetApprovalBusinessFlowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'foreignKeys' => false, 'DBDebug' => true,
        ], false);
        foreach ([
            'CREATE TABLE employees (id INTEGER PRIMARY KEY, company_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, company_id INTEGER, starts_on TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE project_assignments (id INTEGER PRIMARY KEY, project_id INTEGER, employee_id INTEGER, starts_on TEXT, ends_on TEXT, is_active INTEGER)',
            'CREATE TABLE timesheet_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, project_id INTEGER, work_date TEXT, hours REAL, task_description TEXT, is_billable INTEGER, is_overtime INTEGER, status TEXT, submitted_at TEXT, approver_user_id INTEGER, approved_at TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT)',
        ] as $sql) $this->db->query($sql);
        $this->db->table('employees')->insert(['id' => 10, 'company_id' => 1]);
        $this->db->table('projects')->insert([
            'id' => 5, 'company_id' => 1, 'starts_on' => '2020-01-01',
            'ends_on' => '2099-12-31', 'status' => 'ACTIVE',
        ]);
        $this->db->table('project_assignments')->insert([
            'id' => 7, 'project_id' => 5, 'employee_id' => 10,
            'starts_on' => '2020-01-01', 'ends_on' => '2099-12-31', 'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testSubmitStartsApprovalAndFinalDecisionRechecksAssignment(): void
    {
        $captured = [];
        $service = new TimesheetService($this->db, static function (array $params) use (&$captured): array {
            $captured = $params;
            return ['request_id' => 8001, 'status' => 'PENDING'];
        });
        $id = $service->upsertEntry(1, 10, [
            'project_id' => 5, 'work_date' => date('Y-m-d'), 'hours' => 8,
            'task_description' => 'Route market visit', 'is_billable' => true, 'is_overtime' => false,
        ]);
        $result = $service->submit($id, 1, 10, 90);
        self::assertSame('TIMESHEET', $captured['module']);
        self::assertSame('timesheet_entries', $captured['entity_table']);
        self::assertSame(8.0, $captured['meta']['hours']);
        self::assertSame('SUBMITTED', $result['status']);

        $this->db->table('project_assignments')->where('id', 7)->update(['is_active' => 0]);
        try {
            $service->approve($id, 1, 91);
            self::fail('Inactive assignment was approved.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('tidak lagi aktif', $exception->getMessage());
        }
        self::assertSame('SUBMITTED', $this->entryStatus($id));
        $this->db->table('project_assignments')->where('id', 7)->update(['is_active' => 1]);
        $service->approve($id, 1, 91);
        self::assertSame('APPROVED', $this->entryStatus($id));
        try {
            $service->approve($id, 1, 92);
            self::fail('Duplicate approval was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('SUBMITTED', $exception->getMessage());
        }
    }

    public function testRejectReasonTenantAndResubmissionAreGuarded(): void
    {
        $service = new TimesheetService($this->db, static fn (array $params): array => [
            'request_id' => 8002, 'status' => 'PENDING', 'params' => $params,
        ]);
        $id = $service->upsertEntry(1, 10, [
            'project_id' => 5, 'work_date' => date('Y-m-d'), 'hours' => 2,
            'task_description' => 'Inventory analysis', 'is_billable' => false, 'is_overtime' => true,
        ]);
        $service->submit($id, 1, 10, 90);
        try {
            $service->reject($id, 2, 91, 'Wrong tenant');
            self::fail('Cross-tenant reject was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('tidak ditemukan', $exception->getMessage());
        }
        try {
            $service->reject($id, 1, 91, 'no');
            self::fail('Short reason was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('minimal', $exception->getMessage());
        }
        $service->reject($id, 1, 91, 'Task evidence incomplete');
        self::assertSame('REJECTED', $this->entryStatus($id));
        $service->upsertEntry(1, 10, [
            'id' => $id, 'project_id' => 5, 'work_date' => date('Y-m-d'), 'hours' => 2.5,
            'task_description' => 'Inventory analysis with evidence', 'is_billable' => false, 'is_overtime' => true,
        ]);
        $resubmitted = $service->submit($id, 1, 10, 90);
        self::assertSame('SUBMITTED', $resubmitted['status']);
    }

    private function entryStatus(int $id): string
    {
        return (string) $this->db->table('timesheet_entries')->where('id', $id)->get()->getRowArray()['status'];
    }
}
