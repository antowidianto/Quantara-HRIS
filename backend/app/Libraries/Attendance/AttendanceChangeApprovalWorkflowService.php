<?php

namespace App\Libraries\Attendance;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AttendanceChangeApprovalWorkflowService
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array<string,mixed> */
    public function approve(int $id, int $companyId, int $userId, string $note): array
    {
        $db = $this->db();
        $request = $this->pending($id, $companyId);
        $this->assertPayrollOpen($companyId, (string) $request['work_date']);
        $attendance = $db->table('attendances')->where('company_id', $companyId)
            ->where('employee_id', (int) $request['employee_id'])
            ->where('work_date', (string) $request['work_date'])->get()->getRowArray();
        $this->assertSnapshotUnchanged($request, $attendance);

        $now = date('Y-m-d H:i:s');
        $update = [
            'updated_at' => $now,
            'is_corrected' => 1,
            'corrected_by' => $userId,
            'correction_reason' => $request['reason'],
        ];
        if (! empty($request['requested_clock_in'])) $update['clock_in_at'] = $request['requested_clock_in'];
        if (! empty($request['requested_clock_out'])) $update['clock_out_at'] = $request['requested_clock_out'];
        if (! empty($request['requested_status'])) $update['status'] = $request['requested_status'];
        $finalIn = $update['clock_in_at'] ?? ($attendance['clock_in_at'] ?? null);
        $finalOut = $update['clock_out_at'] ?? ($attendance['clock_out_at'] ?? null);
        if ($finalIn && $finalOut) {
            $update['work_minutes'] = max(0, (int) round((strtotime((string) $finalOut) - strtotime((string) $finalIn)) / 60));
        }
        if ($finalIn && empty($update['status']) && empty($attendance['status'])) $update['status'] = 'PRESENT';

        if ($attendance) {
            $builder = $db->table('attendances')->where('id', (int) $attendance['id'])
                ->where('company_id', $companyId);
            $this->applySnapshotConditions($builder, $request);
            $builder->update($update);
            if ($db->affectedRows() !== 1) {
                throw new AttendanceChangeApprovalException(
                    'Data absensi berubah setelah pengajuan; buat pengajuan baru',
                    'STALE_ATTENDANCE_SNAPSHOT',
                    409
                );
            }
            $attendanceId = (int) $attendance['id'];
        } else {
            $db->table('attendances')->insert([
                'company_id' => $companyId,
                'employee_id' => (int) $request['employee_id'],
                'work_date' => $request['work_date'],
                'clock_in_at' => $request['requested_clock_in'],
                'clock_out_at' => $request['requested_clock_out'],
                'status' => $request['requested_status'] ?: 'PRESENT',
                'work_minutes' => $update['work_minutes'] ?? 0,
                'is_corrected' => 1,
                'corrected_by' => $userId,
                'correction_reason' => $request['reason'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $attendanceId = (int) $db->insertID();
        }

        $db->table('attendance_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'PENDING')->update([
                'status' => 'APPROVED',
                'reviewer_id' => $userId,
                'reviewed_at' => $now,
                'review_note' => $note,
                'attendance_id' => $attendanceId,
                'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new AttendanceChangeApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $request['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return ['request' => $request, 'employee' => $employee, 'attendance_id' => $attendanceId];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $note): array
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new AttendanceChangeApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED');
        }
        $db = $this->db();
        $request = $this->pending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $db->table('attendance_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'PENDING')->update([
                'status' => 'REJECTED', 'reviewer_id' => $userId,
                'reviewed_at' => $now, 'review_note' => $note, 'updated_at' => $now,
            ]);
        if ($db->affectedRows() !== 1) {
            throw new AttendanceChangeApprovalException('Request berubah; muat ulang data', 'APPROVAL_CONFLICT', 409);
        }
        $employee = $db->table('employees')->where('id', (int) $request['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        return ['request' => $request, 'employee' => $employee, 'reason' => $note];
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $row = $this->db()->table('attendance_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) throw new AttendanceChangeApprovalException('Pengajuan tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        if ($row['status'] !== 'PENDING') {
            throw new AttendanceChangeApprovalException('Pengajuan tidak PENDING', 'APPROVAL_INVALID_STATE');
        }
        return $row;
    }

    private function assertPayrollOpen(int $companyId, string $workDate): void
    {
        $db = $this->db();
        $locked = $db->table('payroll_periods')->where('company_id', $companyId)
            ->whereIn('status', ['FINALIZED', 'PAID', 'CLOSED'])
            ->where('start_date <=', $workDate)->where('end_date >=', $workDate)
            ->get()->getRowArray();
        if ($locked) {
            throw new AttendanceChangeApprovalException(
                'Periode payroll sudah dikunci; gunakan payroll adjustment, bukan mengubah histori absensi',
                'PAYROLL_PERIOD_LOCKED',
                409
            );
        }
    }

    private function assertSnapshotUnchanged(array $request, ?array $attendance): void
    {
        if (! $attendance && $request['attendance_id'] !== null) {
            throw new AttendanceChangeApprovalException('Attendance asal sudah dihapus', 'STALE_ATTENDANCE_SNAPSHOT', 409);
        }
        if ($attendance && $request['attendance_id'] === null) {
            throw new AttendanceChangeApprovalException('Attendance baru muncul setelah submit', 'STALE_ATTENDANCE_SNAPSHOT', 409);
        }
        if (! $attendance) return;
        foreach (['clock_in' => 'clock_in_at', 'clock_out' => 'clock_out_at', 'status' => 'status'] as $snapshot => $field) {
            if (($request['current_' . $snapshot] ?? null) !== ($attendance[$field] ?? null)) {
                throw new AttendanceChangeApprovalException(
                    'Data absensi berubah setelah pengajuan; buat pengajuan baru',
                    'STALE_ATTENDANCE_SNAPSHOT',
                    409
                );
            }
        }
    }

    private function applySnapshotConditions(BaseBuilder $builder, array $request): void
    {
        foreach (['current_clock_in' => 'clock_in_at', 'current_clock_out' => 'clock_out_at', 'current_status' => 'status'] as $snapshot => $field) {
            if ($request[$snapshot] === null) $builder->where($field . ' IS NULL');
            else $builder->where($field, $request[$snapshot]);
        }
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
