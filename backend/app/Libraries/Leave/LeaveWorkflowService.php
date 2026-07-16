<?php

declare(strict_types=1);

namespace App\Libraries\Leave;

use App\Repositories\Leave\LeaveRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class LeaveWorkflowService
{
    private readonly BaseConnection $db;
    private readonly LeaveRepository $repository;
    private readonly LeavePolicyService $policy;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
        $this->repository = new LeaveRepository($this->db);
        $this->policy = new LeavePolicyService($this->db);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{id:int,request_number:string,days:float,leave:array<string,mixed>,employee:array<string,mixed>,leave_type:array<string,mixed>}
     */
    public function create(int $companyId, int $employeeId, array $input, bool $bypassWindow = false): array
    {
        $employee = $this->repository->employee($employeeId, $companyId);
        if (! $employee) throw new LeaveWorkflowException('Employee not found', 'RESOURCE_NOT_FOUND', 404);
        $type = $this->repository->leaveType((int) $input['leave_type_id'], $companyId);
        if (! $type) throw new LeaveWorkflowException('Tipe cuti tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        $start = (string) $input['start_date'];
        $end = (string) $input['end_date'];
        if (strtotime($end) < strtotime($start)) {
            throw new LeaveWorkflowException('Tanggal selesai harus setelah atau sama dengan tanggal mulai.', 'INVALID_DATE_RANGE');
        }
        if (! $bypassWindow) $this->assertSubmissionWindow($companyId, $type, $start, $end);
        $this->assertEligibility($employee, $type);

        $days = ! empty($input['is_half_day']) ? 0.5 : $this->policy->countWorkingDays($companyId, $start, $end);
        if ($days <= 0) {
            throw new LeaveWorkflowException(
                'Rentang tanggal yang dipilih tidak mengandung hari kerja (mungkin bertepatan dengan akhir pekan atau hari libur).',
                'NO_WORKING_DAYS',
            );
        }
        if (! empty($type['max_days']) && $days > (float) $type['max_days']) {
            throw new LeaveWorkflowException("Tipe cuti \"{$type['name']}\" maksimal {$type['max_days']} hari per pengajuan.", 'MAX_DAYS_EXCEEDED');
        }
        $this->assertBalance($employeeId, $companyId, $type, $start, $days);

        $halfPart = null;
        if (! empty($input['is_half_day'])) {
            $halfPart = strtoupper((string) ($input['half_day_part'] ?? ''));
            if (! in_array($halfPart, ['MORNING', 'AFTERNOON'], true)) {
                throw new LeaveWorkflowException('Pilih bagian setengah hari (Pagi atau Siang) terlebih dahulu.', 'HALF_DAY_PART_REQUIRED');
            }
        }
        if ((int) ($type['requires_doc'] ?? 0) === 1 && empty($input['attachment'])) {
            throw new LeaveWorkflowException(
                "Tipe cuti \"{$type['name']}\" memerlukan lampiran dokumen pendukung (mis. surat dokter).",
                'ATTACHMENT_REQUIRED',
            );
        }

        $number = 'LV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $now = date('Y-m-d H:i:s');
        $leave = [
            'company_id' => $companyId, 'employee_id' => $employeeId,
            'leave_type_id' => (int) $type['id'], 'request_number' => $number,
            'start_date' => $start, 'end_date' => $end, 'days' => $days,
            'is_half_day' => ! empty($input['is_half_day']) ? 1 : 0, 'half_day_part' => $halfPart,
            'reason' => $input['reason'], 'attachment' => $input['attachment'] ?? null,
            'contact_during_leave' => $input['contact_during_leave'] ?? null,
            'handover_to' => ! empty($input['handover_to']) ? (int) $input['handover_to'] : null,
            'status' => 'PENDING', 'created_at' => $now, 'updated_at' => $now,
        ];
        $id = 0;
        $this->transaction(function () use ($companyId, $employeeId, $start, $end, $leave, &$id): void {
            if ($this->repository->hasLeaveOverlap($employeeId, $companyId, $start, $end)) {
                throw new LeaveWorkflowException(
                    'Anda sudah memiliki pengajuan cuti pada periode tersebut. Cek di Daftar Cuti.',
                    'OVERLAPPING_LEAVE',
                );
            }
            $this->db->table('leaves')->insert($leave);
            $id = (int) $this->db->insertID();
        });
        return compact('id', 'days') + [
            'request_number' => $number, 'leave' => $leave, 'employee' => $employee, 'leave_type' => $type,
        ];
    }

    /** @return array{leave:array<string,mixed>,employee:array<string,mixed>|null,leave_type:array<string,mixed>|null} */
    public function approve(int $id, int $companyId, int $userId): array
    {
        $leave = $this->requirePending($id, $companyId);
        $type = $this->repository->leaveType((int) $leave['leave_type_id'], $companyId);
        $this->transaction(function () use ($leave, $type, $companyId, $userId): void {
            $now = date('Y-m-d H:i:s');
            $this->db->table('leaves')->where('id', (int) $leave['id'])->where('company_id', $companyId)
                ->where('status', 'PENDING')->update([
                    'status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => $now, 'updated_at' => $now,
                ]);
            if ($this->db->affectedRows() !== 1) {
                throw new LeaveWorkflowException('Leave not pending', 'APPROVAL_INVALID_STATE');
            }
            $this->decrementBalance($leave, $type, $companyId, $now);
            $this->markAttendance($leave, $companyId, $now);
        });
        return ['leave' => $leave, 'employee' => $this->repository->employee((int) $leave['employee_id'], $companyId), 'leave_type' => $type];
    }

    /** @return array{leave:array<string,mixed>,employee:array<string,mixed>|null} */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $leave = $this->requirePending($id, $companyId);
        $now = date('Y-m-d H:i:s');
        $this->db->table('leaves')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING')->update([
                'status' => 'REJECTED', 'approved_by' => $userId, 'approved_at' => $now,
                'rejection_note' => $reason, 'updated_at' => $now,
            ]);
        if ($this->db->affectedRows() !== 1) throw new LeaveWorkflowException('Leave not pending', 'APPROVAL_INVALID_STATE');
        return ['leave' => $leave, 'employee' => $this->repository->employee((int) $leave['employee_id'], $companyId)];
    }

    /** @return array{leave:array<string,mixed>,employee:array<string,mixed>|null,leave_type:array<string,mixed>|null,previous_status:string,cancelled_at:string} */
    public function cancel(
        int $id,
        int $companyId,
        int $userId,
        bool $isOwner,
        bool $canActAsHr,
        string $reason,
        ?string $today = null,
    ): array {
        $leave = $this->repository->leave($id, $companyId);
        if (! $leave) throw new LeaveWorkflowException('Not found', 'RESOURCE_NOT_FOUND', 404);
        if (! $isOwner && ! $canActAsHr) {
            throw new LeaveWorkflowException('Hanya pemilik cuti atau HR yang dapat membatalkan', 'PERMISSION_DENIED', 403);
        }
        if (! in_array($leave['status'], ['PENDING', 'APPROVED'], true)) {
            throw new LeaveWorkflowException(
                'Hanya cuti PENDING/APPROVED yang bisa dibatalkan; status saat ini: ' . $leave['status'],
                'APPROVAL_INVALID_STATE',
            );
        }
        $today ??= date('Y-m-d');
        $daysSinceStart = (int) round((strtotime($today) - strtotime((string) $leave['start_date'])) / 86400);
        if (! $canActAsHr && $daysSinceStart > 2) {
            throw new LeaveWorkflowException('Cuti sudah berjalan lebih dari 2 hari; hubungi HR untuk koreksi.', 'APPROVAL_INVALID_STATE');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new LeaveWorkflowException('Alasan pembatalan minimal 5 karakter', 'VALIDATION_FAILED');
        $previous = (string) $leave['status'];
        $now = date('Y-m-d H:i:s');
        $type = $this->repository->leaveType((int) $leave['leave_type_id'], $companyId);
        $this->transaction(function () use ($leave, $companyId, $userId, $reason, $previous, $now, $today, $type): void {
            $this->db->table('leaves')->where('id', (int) $leave['id'])->where('company_id', $companyId)
                ->whereIn('status', ['PENDING', 'APPROVED'])->update([
                    'status' => 'CANCELLED', 'cancellation_reason' => $reason,
                    'cancelled_by' => $userId, 'cancelled_at' => $now, 'updated_at' => $now,
                ]);
            if ($this->db->affectedRows() !== 1) throw new LeaveWorkflowException('Leave state changed; reload and retry', 'APPROVAL_INVALID_STATE');
            if ($previous === 'APPROVED') {
                $this->restoreBalance($leave, $type, $companyId, $now);
                $this->db->table('attendances')->where('company_id', $companyId)
                    ->where('employee_id', $leave['employee_id'])->where('status', 'LEAVE')
                    ->where('work_date >=', $today)->where('work_date <=', $leave['end_date'])
                    ->where('clock_in_at IS NULL')->delete();
            }
        });
        return [
            'leave' => $leave, 'employee' => $this->repository->employee((int) $leave['employee_id'], $companyId),
            'leave_type' => $type, 'previous_status' => $previous, 'cancelled_at' => $now,
        ];
    }

    /** @param array<string,mixed> $type */
    private function assertSubmissionWindow(int $companyId, array $type, string $start, string $end): void
    {
        $category = strtoupper((string) ($type['category'] ?? 'ANNUAL'));
        $policy = $this->policy->policy($companyId);
        $window = $policy['per_category'][$category] ?? [
            'min_start_date' => $policy['default_min_start_date'],
            'max_start_date' => $policy['default_max_start_date'],
        ];
        if ($start < $window['min_start_date']) throw new LeaveWorkflowException('Tanggal mulai berada sebelum window pengajuan.', 'BACKDATE_NOT_ALLOWED');
        if ($start > $window['max_start_date']) throw new LeaveWorkflowException('Tanggal mulai terlalu jauh ke depan.', 'TOO_FAR_AHEAD');
        $locked = $this->repository->lockedPayrollOverlap($companyId, $start, $end);
        if ($locked) {
            throw new LeaveWorkflowException(
                "Periode payroll {$locked['name']} ({$locked['start_date']} s/d {$locked['end_date']}) sudah dikunci/dibayar. Pengajuan cuti pada rentang ini tidak diperbolehkan.",
                'PERIOD_LOCKED',
            );
        }
    }

    /** @param array<string,mixed> $employee @param array<string,mixed> $type */
    private function assertEligibility(array $employee, array $type): void
    {
        $restriction = strtoupper((string) ($type['gender_restriction'] ?? 'ALL'));
        $gender = strtoupper((string) ($employee['gender'] ?? ''));
        if ($restriction !== 'ALL' && $restriction !== $gender) {
            throw new LeaveWorkflowException("Tipe cuti \"{$type['name']}\" tidak berlaku untuk gender karyawan.", 'GENDER_RESTRICTED');
        }
        $minimum = (int) ($type['min_tenure_months'] ?? 0);
        $tenure = ! empty($employee['join_date'])
            ? (int) ((time() - strtotime((string) $employee['join_date'])) / 86400 / 30) : 0;
        if ($minimum > 0 && $tenure < $minimum) {
            throw new LeaveWorkflowException("Belum memenuhi masa kerja minimal {$minimum} bulan.", 'INSUFFICIENT_TENURE');
        }
        $eligibility = $this->policy->categoryEligibility((string) ($type['category'] ?? 'ANNUAL'), $employee);
        if (! $eligibility['ok']) throw new LeaveWorkflowException($eligibility['reason'], 'CATEGORY_NOT_ELIGIBLE');
    }

    /** @param array<string,mixed> $type */
    private function assertBalance(int $employeeId, int $companyId, array $type, string $start, float $days): void
    {
        if ((int) ($type['is_paid'] ?? 0) !== 1 || (int) ($type['reset_yearly'] ?? 0) !== 1 || (float) ($type['default_days'] ?? 0) <= 0) return;
        $balance = $this->repository->balance($employeeId, (int) $type['id'], $companyId, (int) date('Y', strtotime($start)));
        $remaining = $balance ? (float) $balance['remaining'] : (float) $type['default_days'];
        if ($days > $remaining) throw new LeaveWorkflowException("Saldo cuti tidak cukup (sisa: {$remaining}, diminta: {$days})", 'INSUFFICIENT_BALANCE');
    }

    /** @return array<string,mixed> */
    private function requirePending(int $id, int $companyId): array
    {
        $leave = $this->repository->leave($id, $companyId);
        if (! $leave) throw new LeaveWorkflowException('Not found', 'RESOURCE_NOT_FOUND', 404);
        if ($leave['status'] !== 'PENDING') throw new LeaveWorkflowException('Leave not pending', 'APPROVAL_INVALID_STATE');
        return $leave;
    }

    /** @param array<string,mixed> $leave @param array<string,mixed>|null $type */
    private function decrementBalance(array $leave, ?array $type, int $companyId, string $now): void
    {
        if (! $type || (int) $type['reset_yearly'] !== 1 || (float) $type['default_days'] <= 0) return;
        $year = (int) date('Y', strtotime((string) $leave['start_date']));
        $balance = $this->repository->balance((int) $leave['employee_id'], (int) $leave['leave_type_id'], $companyId, $year);
        $remaining = $balance ? (float) $balance['remaining'] : (float) $type['default_days'];
        if ((float) $leave['days'] > $remaining) throw new LeaveWorkflowException('Saldo cuti tidak cukup saat approval', 'INSUFFICIENT_BALANCE');
        if (! $balance) {
            $this->db->table('leave_balances')->insert([
                'company_id' => $companyId, 'employee_id' => $leave['employee_id'],
                'leave_type_id' => $leave['leave_type_id'], 'period_year' => $year,
                'allocated' => $type['default_days'], 'used' => $leave['days'],
                'remaining' => $remaining - (float) $leave['days'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }
        $this->db->table('leave_balances')->where('id', $balance['id'])->where('company_id', $companyId)->update([
            'used' => (float) $balance['used'] + (float) $leave['days'],
            'remaining' => $remaining - (float) $leave['days'], 'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $leave */
    private function markAttendance(array $leave, int $companyId, string $now): void
    {
        $period = new \DatePeriod(new \DateTime((string) $leave['start_date']), new \DateInterval('P1D'), (new \DateTime((string) $leave['end_date']))->modify('+1 day'));
        foreach ($period as $date) {
            $day = $date->format('Y-m-d');
            $existing = $this->db->table('attendances')->where('company_id', $companyId)
                ->where('employee_id', $leave['employee_id'])->where('work_date', $day)->get()->getRowArray();
            if ($existing) {
                $this->db->table('attendances')->where('id', $existing['id'])->where('company_id', $companyId)->update(['status' => 'LEAVE']);
            } else {
                $this->db->table('attendances')->insert([
                    'company_id' => $companyId, 'employee_id' => $leave['employee_id'],
                    'work_date' => $day, 'status' => 'LEAVE', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $leave @param array<string,mixed>|null $type */
    private function restoreBalance(array $leave, ?array $type, int $companyId, string $now): void
    {
        if (! $type || (int) $type['reset_yearly'] !== 1 || (float) $type['default_days'] <= 0) return;
        $balance = $this->repository->balance(
            (int) $leave['employee_id'], (int) $leave['leave_type_id'], $companyId,
            (int) date('Y', strtotime((string) $leave['start_date'])),
        );
        if (! $balance) return;
        $this->db->table('leave_balances')->where('id', $balance['id'])->where('company_id', $companyId)->update([
            'used' => max(0, (float) $balance['used'] - (float) $leave['days']),
            'remaining' => (float) $balance['remaining'] + (float) $leave['days'], 'updated_at' => $now,
        ]);
    }

    private function transaction(callable $callback): void
    {
        $this->db->transBegin();
        try {
            $callback();
            if ($this->db->transStatus() === false) throw new \RuntimeException('Leave transaction failed');
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
