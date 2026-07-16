<?php

namespace Tests\Unit;

use App\Libraries\Approval\Adapters\AttendanceChangeApprovalDomainAdapter;
use App\Libraries\Attendance\AttendanceChangeApprovalException;
use CodeIgniter\Test\CIUnitTestCase;

final class AttendanceChangeApprovalDomainAdapterIntegrationTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tables = ['payroll_periods', 'attendance_change_requests', 'attendances', 'employees'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        $db->query('CREATE TABLE db_employees (id INTEGER PRIMARY KEY, company_id INTEGER, full_name TEXT, user_id INTEGER)');
        $db->query('CREATE TABLE db_attendances (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, employee_id INTEGER, work_date TEXT, clock_in_at TEXT, clock_out_at TEXT, status TEXT, work_minutes INTEGER, is_corrected INTEGER, corrected_by INTEGER, correction_reason TEXT, created_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_attendance_change_requests (id INTEGER PRIMARY KEY, company_id INTEGER, employee_id INTEGER, attendance_id INTEGER, request_number TEXT, work_date TEXT, current_clock_in TEXT, current_clock_out TEXT, current_status TEXT, requested_clock_in TEXT, requested_clock_out TEXT, requested_status TEXT, reason TEXT, status TEXT, reviewer_id INTEGER, reviewed_at TEXT, review_note TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE db_payroll_periods (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, start_date TEXT, end_date TEXT, status TEXT)');
        $db->table('employees')->insert(['id' => 601, 'company_id' => 601, 'full_name' => 'Attendance Test', 'user_id' => 9601]);
    }

    public function testApprovalUpdatesSnapshotExactlyOnce(): void
    {
        $db = \Config\Database::connect();
        $db->table('attendances')->insert([
            'id' => 601, 'company_id' => 601, 'employee_id' => 601,
            'work_date' => '2026-07-10', 'clock_in_at' => '2026-07-10 09:00:00',
            'clock_out_at' => '2026-07-10 17:00:00', 'status' => 'LATE',
        ]);
        $db->table('attendance_change_requests')->insert([
            'id' => 601, 'company_id' => 601, 'employee_id' => 601, 'attendance_id' => 601,
            'request_number' => 'ACR-601', 'work_date' => '2026-07-10',
            'current_clock_in' => '2026-07-10 09:00:00', 'current_clock_out' => '2026-07-10 17:00:00',
            'current_status' => 'LATE', 'requested_clock_in' => '2026-07-10 08:00:00',
            'requested_clock_out' => '2026-07-10 17:00:00', 'requested_status' => 'PRESENT',
            'reason' => 'Mesin fingerprint terlambat sinkron', 'status' => 'PENDING',
        ]);
        $adapter = new AttendanceChangeApprovalDomainAdapter();
        $request = ['entity_id' => 601, 'company_id' => 601];
        $adapter->apply($request, 'APPROVED', 7601, 'verified');
        $attendance = $db->table('attendances')->where('id', 601)->get()->getRowArray();
        $change = $db->table('attendance_change_requests')->where('id', 601)->get()->getRowArray();
        $this->assertSame('APPROVED', $change['status']);
        $this->assertSame('2026-07-10 08:00:00', $attendance['clock_in_at']);
        $this->assertSame('PRESENT', $attendance['status']);
        $this->assertSame(540, (int) $attendance['work_minutes']);

        $this->expectException(AttendanceChangeApprovalException::class);
        $adapter->apply($request, 'APPROVED', 7601, 'duplicate');
    }

    public function testLockedPayrollPeriodBlocksHistoricalOverwrite(): void
    {
        $db = \Config\Database::connect();
        $db->table('attendance_change_requests')->insert([
            'id' => 602, 'company_id' => 601, 'employee_id' => 601, 'attendance_id' => null,
            'request_number' => 'ACR-602', 'work_date' => '2026-06-10',
            'requested_clock_in' => '2026-06-10 08:00:00', 'requested_clock_out' => '2026-06-10 17:00:00',
            'requested_status' => 'PRESENT', 'reason' => 'Koreksi periode lama', 'status' => 'PENDING',
        ]);
        $db->table('payroll_periods')->insert([
            'id' => 602, 'company_id' => 601, 'name' => 'Juni 2026',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'PAID',
        ]);
        try {
            (new AttendanceChangeApprovalDomainAdapter())->apply(
                ['entity_id' => 602, 'company_id' => 601], 'APPROVED', 7601, 'verified'
            );
            $this->fail('Locked payroll period must reject correction');
        } catch (AttendanceChangeApprovalException $exception) {
            $this->assertSame('PAYROLL_PERIOD_LOCKED', $exception->errorCode);
            $this->assertSame(0, $db->table('attendances')->where('employee_id', 601)->countAllResults());
            $change = $db->table('attendance_change_requests')->where('id', 602)->get()->getRowArray();
            $this->assertSame('PENDING', $change['status']);
        }
    }

    protected function tearDown(): void
    {
        $db = \Config\Database::connect();
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS db_' . $table);
        parent::tearDown();
    }
}
