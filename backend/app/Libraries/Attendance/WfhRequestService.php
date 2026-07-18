<?php

namespace App\Libraries\Attendance;

use Config\Services;

/**
 * WfhRequestService (Sprint 8 / H1.5)
 *
 * Service untuk pengajuan WFH (Work From Home) yang harus di-approve atasan
 * sebelum karyawan boleh clock-in dalam mode WFH (geofence di-skip).
 *
 * Aturan default:
 *   - Pengajuan harus H-1 atau pagi sebelum jam mulai shift hari itu.
 *   - Cap maksimal hari WFH per bulan diambil dari settings perusahaan
 *     dengan key `wfh_max_days_per_month` (default: 12).
 *   - Approver = atasan langsung (employees.supervisor_id → user_id).
 *     Fallback ke HR (role 2) bila supervisor tidak punya akun.
 *
 * Caller utama:
 *   - WfhRequestController (CRUD endpoint)
 *   - AttendanceController (cek WFH approved saat clock-in untuk skip geofence)
 *
 * Lihat docs/QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md item H1.5.
 */
class WfhRequestService
{
    private const SETTING_MAX_PER_MONTH = 'wfh_max_days_per_month';
    private const DEFAULT_MAX_PER_MONTH = 12;

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Submit pengajuan WFH baru.
     *
     * @return array{id:int, status:string}
     * @throws \RuntimeException pada validasi gagal
     */
    public function submit(int $employeeId, string $workDate, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Alasan WFH wajib diisi');
        }

        $emp = $this->db()->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) {
            throw new \RuntimeException('Karyawan tidak ditemukan');
        }
        $companyId = (int) $emp['company_id'];

        // Validasi tanggal: tidak boleh masa lalu, tidak boleh > 30 hari ke depan.
        $today = new \DateTimeImmutable('today');
        $target = new \DateTimeImmutable($workDate);
        if ($target < $today) {
            throw new \RuntimeException('Tanggal WFH tidak boleh masa lalu');
        }
        $maxFuture = $today->modify('+30 days');
        if ($target > $maxFuture) {
            throw new \RuntimeException('Tanggal WFH maksimal 30 hari ke depan');
        }

        // Aturan H-1 atau pagi sebelum jam mulai shift.
        if ($target->format('Y-m-d') === $today->format('Y-m-d')) {
            $shiftStart = $this->resolveShiftStart($employeeId, $target->format('Y-m-d'));
            if ($shiftStart !== null) {
                $now = new \DateTimeImmutable('now');
                $startDt = new \DateTimeImmutable($target->format('Y-m-d') . ' ' . $shiftStart);
                if ($now >= $startDt) {
                    throw new \RuntimeException(
                        'Pengajuan WFH untuk hari ini harus dilakukan SEBELUM jam mulai shift ' . $shiftStart
                    );
                }
            }
        }

        // Tidak boleh duplikat (employee + date).
        $existing = $this->db()->table('wfh_requests')
            ->where('employee_id', $employeeId)
            ->where('work_date', $target->format('Y-m-d'))
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->countAllResults();
        if ($existing > 0) {
            throw new \RuntimeException('Sudah ada pengajuan WFH aktif untuk tanggal ini');
        }

        // Cek cap bulanan.
        $cap = $this->getMaxPerMonth($companyId);
        $usedThisMonth = $this->countUsedInMonth($employeeId, $target->format('Y'), $target->format('n'));
        if ($cap > 0 && $usedThisMonth >= $cap) {
            throw new \RuntimeException(
                sprintf('Kuota WFH bulan %s sudah penuh (%d/%d hari)',
                    $target->format('M Y'), $usedThisMonth, $cap)
            );
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'  => $companyId,
            'employee_id' => $employeeId,
            'work_date'   => $target->format('Y-m-d'),
            'reason'      => $reason,
            'status'      => 'PENDING',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $this->db()->table('wfh_requests')->insert($insert);
        $id = (int) $this->db()->insertID();

        // Notifikasi approver
        $approverUserId = $this->resolveApproverUserId($emp);
        if ($approverUserId !== null) {
            try {
                Services::notification()->send(
                    $approverUserId, $companyId,
                    'wfh.request',
                    'Pengajuan WFH menunggu approval',
                    sprintf('%s mengajukan WFH pada %s. Alasan: %s',
                        $emp['full_name'] ?? '?',
                        $target->format('d M Y'),
                        substr($reason, 0, 120),
                    ),
                    ['wfh_request_id' => $id, 'employee_id' => $employeeId],
                    '/approvals/inbox?wfh_request_id=' . $id, true
                );
            } catch (\Throwable $e) { /* skip */ }
        }

        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: 'wfh.submit',
                entity: 'wfh_requests',
                entityId: $id,
                after: $insert,
                description: sprintf('Pengajuan WFH untuk %s (%s)', $target->format('Y-m-d'), $emp['full_name'] ?? '?'),
            );
        } catch (\Throwable $e) {}

        return ['id' => $id, 'status' => 'PENDING'];
    }

    /**
     * Approve / reject pengajuan WFH.
     *
     * @return array{id:int, status:string}
     */
    public function decide(int $requestId, int $approverUserId, string $decision, string $note = ''): array
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw new \RuntimeException('Decision harus APPROVED atau REJECTED');
        }
        if ($decision === 'REJECTED' && trim($note) === '') {
            throw new \RuntimeException('Alasan penolakan wajib diisi');
        }

        $req = $this->db()->table('wfh_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $req) throw new \RuntimeException('Pengajuan tidak ditemukan');
        if ($req['status'] !== 'PENDING') {
            throw new \RuntimeException('Pengajuan sudah final, tidak bisa diputuskan ulang');
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'           => $decision,
            'approver_user_id' => $approverUserId,
            'approved_at'      => $now,
            'rejection_note'   => $decision === 'REJECTED' ? trim($note) : null,
            'updated_at'       => $now,
        ];
        $this->db()->table('wfh_requests')->where('id', $requestId)->update($update);

        // Notifikasi requester
        $emp = $this->db()->table('employees')->where('id', (int) $req['employee_id'])->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'], (int) $req['company_id'],
                    'wfh.decided',
                    'WFH Anda ' . ($decision === 'APPROVED' ? 'disetujui' : 'ditolak'),
                    sprintf('Pengajuan WFH untuk %s telah %s.%s',
                        $req['work_date'],
                        $decision === 'APPROVED' ? 'disetujui' : 'ditolak',
                        $decision === 'REJECTED' ? ' Alasan: ' . trim($note) : ''
                    ),
                    ['wfh_request_id' => $requestId],
                    '/attendance/wfh?scope=mine&request_id=' . $requestId, true
                );
            } catch (\Throwable $e) {}
        }

        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: $decision === 'APPROVED' ? 'wfh.approve' : 'wfh.reject',
                entity: 'wfh_requests',
                entityId: $requestId,
                before: $req,
                after: array_merge($req, $update),
                description: sprintf('WFH %s pada %s — %s',
                    $req['work_date'], strtolower($decision),
                    $emp['full_name'] ?? '?'
                ),
            );
        } catch (\Throwable $e) {}

        return ['id' => $requestId, 'status' => $decision];
    }

    /**
     * Cek apakah karyawan punya WFH approved untuk tanggal kerja tertentu.
     * Dipakai AttendanceController untuk skip geofence saat clock-in.
     */
    public function isApprovedFor(int $employeeId, string $workDate): bool
    {
        $row = $this->db()->table('wfh_requests')
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->where('status', 'APPROVED')
            ->countAllResults();
        return $row > 0;
    }

    /**
     * Cancel oleh requester (selama masih PENDING).
     */
    public function cancel(int $requestId, int $byUserId): void
    {
        $req = $this->db()->table('wfh_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $req) throw new \RuntimeException('Pengajuan tidak ditemukan');
        if ($req['status'] !== 'PENDING') {
            throw new \RuntimeException('Hanya pengajuan PENDING yang bisa dibatalkan');
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('wfh_requests')->where('id', $requestId)->update([
            'status'     => 'CANCELLED',
            'updated_at' => $now,
        ]);
        try {
            Services::audit()->forModule('ATTENDANCE')->log(
                action: 'wfh.cancel',
                entity: 'wfh_requests',
                entityId: $requestId,
                before: $req,
                description: "WFH dibatalkan oleh user #{$byUserId}",
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Counter WFH yang sudah disetujui di bulan tertentu (untuk cap check).
     */
    public function countUsedInMonth(int $employeeId, int|string $year, int|string $month): int
    {
        $start = sprintf('%04d-%02d-01', (int) $year, (int) $month);
        $end = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        return $this->db()->table('wfh_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'APPROVED')
            ->where('work_date >=', $start)
            ->where('work_date <', $end)
            ->countAllResults();
    }

    public function getMaxPerMonth(int $companyId): int
    {
        if (! $this->db()->tableExists('settings')) return self::DEFAULT_MAX_PER_MONTH;

        $row = $this->db()->table('settings')
            ->where('company_id', $companyId)
            ->where('key', self::SETTING_MAX_PER_MONTH)
            ->get()->getRowArray();
        if (! $row) return self::DEFAULT_MAX_PER_MONTH;
        $val = (int) ($row['value'] ?? 0);
        return $val > 0 ? $val : self::DEFAULT_MAX_PER_MONTH;
    }

    /**
     * Resolve approver user_id: supervisor langsung → fallback HR (role 2).
     */
    private function resolveApproverUserId(array $employee): ?int
    {
        if (! empty($employee['supervisor_id'])) {
            $sup = $this->db()->table('employees')
                ->where('id', (int) $employee['supervisor_id'])->get()->getRowArray();
            if ($sup && ! empty($sup['user_id'])) {
                return (int) $sup['user_id'];
            }
        }
        // Fallback HR
        $hr = $this->db()->table('users')
            ->where('company_id', (int) $employee['company_id'])
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->limit(1)->get()->getRowArray();
        return $hr ? (int) $hr['id'] : null;
    }

    /**
     * Ambil jam mulai shift yang berlaku untuk karyawan pada tanggal tertentu.
     * Kalau tidak ada (mis. karyawan tanpa shift assignment), return null →
     * aturan H-1 di-skip.
     */
    private function resolveShiftStart(int $employeeId, string $workDate): ?string
    {
        // Coba shift_schedules dulu (assignment harian eksplisit)
        $sched = $this->db()->table('shift_schedules s')
            ->select('sh.start_time')
            ->join('shifts sh', 'sh.id = s.shift_id')
            ->where('s.employee_id', $employeeId)
            ->where('s.work_date', $workDate)
            ->where('s.is_off', 0)
            ->get()->getRowArray();
        if ($sched && ! empty($sched['start_time'])) {
            return substr((string) $sched['start_time'], 0, 5);
        }

        // Fallback: shift default karyawan via employees.shift_id
        $emp = $this->db()->table('employees e')
            ->select('sh.start_time')
            ->join('shifts sh', 'sh.id = e.shift_id', 'left')
            ->where('e.id', $employeeId)
            ->get()->getRowArray();
        if ($emp && ! empty($emp['start_time'])) {
            return substr((string) $emp['start_time'], 0, 5);
        }
        return null;
    }
}
