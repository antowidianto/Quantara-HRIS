<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * Attendance Change Request Controller
 *
 * Alur:
 *  1. Karyawan membuat pengajuan untuk tanggal lampau / tanggal mendatang.
 *     - Tidak boleh untuk tanggal yang sudah punya pengajuan PENDING.
 *     - Tanggal harus dalam rentang 90 hari ke belakang sampai 30 hari ke
 *       depan (configurable).
 *  2. HR / Manager dengan permission `attendance.approve_change` melihat
 *     pengajuan untuk karyawan dalam scope branch / company mereka.
 *  3. Pada APPROVE: nilai diterapkan ke `attendances` (insert/update),
 *     status hari diset sesuai requested_status.
 *  4. Pada REJECT: hanya ubah status pengajuan + kirim notifikasi.
 *
 * Scope filter:
 *  - Super admin: lihat semua.
 *  - `attendance.view_all_branches`: lihat lintas branch dalam company.
 *  - Lainnya: hanya branch sendiri.
 *  - Karyawan biasa (?mine=1): hanya pengajuan miliknya.
 */
class AttendanceChangeRequestController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    /** Berapa hari ke belakang yang masih boleh diajukan koreksinya. */
    private const MAX_BACKDATE_DAYS = 90;
    /** Berapa hari ke depan yang masih boleh diajukan (mis. izin terjadwal). */
    private const MAX_FORWARD_DAYS = 30;

    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();

        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status  = trim((string) $this->request->getGet('status'));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $branchId   = (int) ($this->request->getGet('branch_id') ?? 0);
        $startDate  = (string) ($this->request->getGet('start_date') ?? '');
        $endDate    = (string) ($this->request->getGet('end_date') ?? '');
        $mine       = (bool) $this->request->getGet('mine');

        // Privacy scope: tanpa `attendance.approve_change`/view_all, paksa
        // ke milik sendiri walau user pass `?employee_id=X`. Lihat
        // docs/DATA_PRIVACY.md.
        $employeeId = $this->privacy()->sanitizeEmployeeFilter(
            $requestedEmpId ?: null, 'attendance_change'
        );
        // Kalau user tidak punya canViewAll attendance_change, dan tidak
        // punya akses self pun (mis. tidak ada employee link), tolak.
        if (! $this->privacy()->canViewOwn('attendance_change')) {
            return $this->fail('Forbidden', 403);
        }
        // Force `mine` mode kalau user cuma punya view.own
        if (! $this->privacy()->canViewAll('attendance_change')) {
            $mine = true;
        }

        // Cross-branch: sama dengan AttendanceController.
        if (! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('attendance.view_all_branches')
            && $branchId > 0
            && $branchId !== $tenant->getBranchId()
        ) {
            return $this->fail('Akses lintas cabang tidak diizinkan', 403, 'CROSS_BRANCH_DENIED');
        }

        $builder = $db->table('attendance_change_requests cr')
            ->select('cr.*, e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id, b.name AS branch_name, u.name AS reviewer_name')
            ->join('employees e', 'e.id = cr.employee_id')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('users u', 'u.id = cr.reviewer_id', 'left');

        $tenant->applyAttendanceScope($builder, 'cr.company_id', 'e.branch_id');

        if ($mine) {
            // Mode "milik saya" — abaikan branch scope, paksa employee_id sendiri.
            $myEmpId = $tenant->getEmployeeId();
            if (! $myEmpId) return $this->fail('User tidak terhubung dengan karyawan', 400, 'NO_EMPLOYEE_LINK');
            $builder = $db->table('attendance_change_requests cr')
                ->select('cr.*, e.full_name AS employee_name, e.employee_number, u.name AS reviewer_name')
                ->join('employees e', 'e.id = cr.employee_id')
                ->join('users u', 'u.id = cr.reviewer_id', 'left')
                ->where('cr.employee_id', $myEmpId);
        }
        if (! $mine && $employeeId) $builder->where('cr.employee_id', $employeeId);
        if ($branchId)              $builder->where('e.branch_id', $branchId);
        if ($status)                $builder->where('cr.status', $status);
        if ($startDate)             $builder->where('cr.work_date >=', $startDate);
        if ($endDate)               $builder->where('cr.work_date <=', $endDate);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('cr.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('attendance_change_requests cr')
            ->select('cr.*, e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id, e.company_id AS emp_company_id, b.name AS branch_name, u.name AS reviewer_name')
            ->join('employees e', 'e.id = cr.employee_id')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('users u', 'u.id = cr.reviewer_id', 'left')
            ->where('cr.id', (int) $id)
            ->get()
            ->getRowArray();
        if (! $row) return $this->fail('Pengajuan tidak ditemukan', 404);

        $isOwner = $this->tenant()->getEmployeeId() === (int) $row['employee_id'];
        if (! $isOwner && ! $this->tenant()->canAccessBranch(
            (int) $row['company_id'],
            isset($row['branch_id']) ? (int) $row['branch_id'] : null
        )) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $tenant = $this->tenant();
        $employeeId = $tenant->getEmployeeId();
        if (! $employeeId) {
            return $this->fail('User tidak terhubung dengan karyawan', 400, 'NO_EMPLOYEE_LINK');
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'work_date'           => 'required|valid_date',
            'change_type'         => 'required|in_list[CLOCK_IN,CLOCK_OUT,BOTH,STATUS,MISSED]',
            'reason'              => 'required|min_length[10]|max_length[1000]',
            'requested_clock_in'  => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'requested_clock_out' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'requested_status'    => 'permit_empty|in_list[PRESENT,LATE,ABSENT,LEAVE,HOLIDAY,SICK,HALF_DAY]',
            'attachment_path'     => 'permit_empty|max_length[255]',
        ]);
        if (! $valid) return null;

        $workDate = $data['work_date'];
        $today    = date('Y-m-d');
        $minDate  = date('Y-m-d', strtotime("-" . self::MAX_BACKDATE_DAYS . " days"));
        $maxDate  = date('Y-m-d', strtotime("+" . self::MAX_FORWARD_DAYS . " days"));
        if ($workDate < $minDate || $workDate > $maxDate) {
            return $this->fail(
                'Tanggal di luar rentang yang diizinkan ('
                . self::MAX_BACKDATE_DAYS . ' hari ke belakang sampai '
                . self::MAX_FORWARD_DAYS . ' hari ke depan)',
                422,
                'OUT_OF_RANGE'
            );
        }

        // Validasi minimal: sesuai change_type, field tertentu wajib diisi.
        $type = $data['change_type'];
        $reqIn  = $data['requested_clock_in']  ?? null;
        $reqOut = $data['requested_clock_out'] ?? null;
        $reqStatus = $data['requested_status'] ?? null;

        $this->validateChangeTypePayload($type, $reqIn, $reqOut, $reqStatus, $workDate);

        $db = \Config\Database::connect();
        $activeCompanyId = $this->requireCompanyId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $activeCompanyId)
            ->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        $companyId = (int) $emp['company_id'];
        $branchId  = isset($emp['branch_id']) ? (int) $emp['branch_id'] : null;

        // Cek pengajuan PENDING duplikat untuk tanggal yang sama
        $duplicate = $db->table('attendance_change_requests')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->where('status', 'PENDING')
            ->countAllResults();
        if ($duplicate > 0) {
            return $this->fail(
                'Sudah ada pengajuan PENDING untuk tanggal ini. Batalkan atau tunggu reviewer.',
                409,
                'DUPLICATE_PENDING'
            );
        }

        // Snapshot baris attendance saat ini (jika ada)
        $att = $db->table('attendances')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->get()->getRowArray();

        $reqNumber = 'ACR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $insert = [
            'company_id'    => $companyId,
            'branch_id'     => $branchId,
            'employee_id'   => $employeeId,
            'attendance_id' => $att ? (int) $att['id'] : null,
            'work_date'     => $workDate,
            'request_number'=> $reqNumber,
            'change_type'   => $type,
            'current_clock_in'  => $att['clock_in_at']  ?? null,
            'current_clock_out' => $att['clock_out_at'] ?? null,
            'current_status'    => $att['status']       ?? null,
            'requested_clock_in'  => $reqIn,
            'requested_clock_out' => $reqOut,
            'requested_status'    => $reqStatus,
            'reason'        => $data['reason'],
            'attachment_path' => $data['attachment_path'] ?? null,
            'status'        => 'PENDING',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        $db->table('attendance_change_requests')->insert($insert);
        $id = (int) $db->insertID();

        // Notifikasi ke approver di company + branch yang sama (atau HR pusat)
        $this->notifyApprovers($companyId, $branchId, $id, $emp['full_name'], $workDate, $type);

        Services::audit()
            ->forModule('ATTENDANCE')
            ->logCreate(
                'attendance_change_request',
                $id,
                $insert,
                "Pengajuan perubahan absen {$reqNumber} untuk {$emp['full_name']} ({$workDate}, {$type})"
            );
        return $this->ok([
            'id'             => $id,
            'request_number' => $reqNumber,
            'status'         => 'PENDING',
        ], 'Pengajuan dibuat', 201);
    }

    public function approve($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $cr = $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $cr) return $this->fail('Pengajuan tidak ditemukan', 404);
        if ($cr['status'] !== 'PENDING') {
            return $this->fail('Pengajuan tidak dalam status PENDING', 422, 'NOT_PENDING');
        }
        if (! $this->tenant()->canAccessBranch(
            (int) $cr['company_id'],
            isset($cr['branch_id']) ? (int) $cr['branch_id'] : null
        )) {
            return $this->fail('Forbidden: pengajuan di luar scope Anda', 403, 'OUT_OF_SCOPE');
        }
        if (! $this->tenant()->hasPermission('attendance.approve_change')) {
            return $this->fail('Anda tidak punya hak menyetujui pengajuan absensi', 403, 'NO_APPROVE_PERMISSION');
        }
        // Defensive: tidak boleh approve pengajuan sendiri.
        if ((int) $cr['employee_id'] === (int) $this->tenant()->getEmployeeId()) {
            return $this->fail('Tidak boleh menyetujui pengajuan sendiri', 422, 'SELF_APPROVAL');
        }

        $data = $this->getJsonInput();
        $note = (string) ($data['review_note'] ?? '');

        $db->transStart();

        // Terapkan perubahan ke attendances
        $att = $db->table('attendances')
            ->where('company_id', $companyId)
            ->where('employee_id', $cr['employee_id'])
            ->where('work_date', $cr['work_date'])
            ->get()->getRowArray();

        $update = [
            'updated_at'        => date('Y-m-d H:i:s'),
            'is_corrected'      => 1,
            'corrected_by'      => $this->userId(),
            'correction_reason' => $cr['reason'],
        ];
        if (! empty($cr['requested_clock_in']))  $update['clock_in_at']  = $cr['requested_clock_in'];
        if (! empty($cr['requested_clock_out'])) $update['clock_out_at'] = $cr['requested_clock_out'];
        if (! empty($cr['requested_status']))    $update['status']       = $cr['requested_status'];

        // Recompute work_minutes / late_minutes kalau in/out berubah.
        $finalIn  = $update['clock_in_at']  ?? ($att['clock_in_at']  ?? null);
        $finalOut = $update['clock_out_at'] ?? ($att['clock_out_at'] ?? null);
        if ($finalIn && $finalOut) {
            $update['work_minutes'] = max(0, (int) round((strtotime($finalOut) - strtotime($finalIn)) / 60));
        }
        if ($finalIn && empty($update['status']) && empty($att['status'])) {
            $update['status'] = 'PRESENT';
        }

        if ($att) {
            $db->table('attendances')->where('id', $att['id'])->where('company_id', $companyId)->update($update);
            $attId = (int) $att['id'];
        } else {
            $insert = array_merge([
                'company_id'   => $cr['company_id'],
                'employee_id'  => $cr['employee_id'],
                'work_date'    => $cr['work_date'],
                'clock_in_at'  => $cr['requested_clock_in'],
                'clock_out_at' => $cr['requested_clock_out'],
                'status'       => $cr['requested_status'] ?: 'PRESENT',
                'is_corrected' => 1,
                'corrected_by' => $this->userId(),
                'correction_reason' => $cr['reason'],
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            if ($insert['clock_in_at'] && $insert['clock_out_at']) {
                $insert['work_minutes'] = max(0, (int) round(
                    (strtotime($insert['clock_out_at']) - strtotime($insert['clock_in_at'])) / 60
                ));
            }
            $db->table('attendances')->insert($insert);
            $attId = (int) $db->insertID();
        }

        $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->update([
            'status'        => 'APPROVED',
            'reviewer_id'   => $this->userId(),
            'reviewed_at'   => date('Y-m-d H:i:s'),
            'review_note'   => $note,
            'attendance_id' => $attId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->fail('Gagal menyimpan persetujuan', 500, 'TX_FAILED');
        }

        // Notifikasi ke karyawan
        $emp = $db->table('employees')->where('id', $cr['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $cr['company_id'],
                'attendance.change.approved',
                'Pengajuan perubahan absen disetujui',
                "Pengajuan {$cr['request_number']} untuk tanggal {$cr['work_date']} telah disetujui",
                ['change_request_id' => $id, 'attendance_id' => $attId],
                '/attendance/my-changes',
                true
            );
        }

        Services::audit()
            ->forModule('ATTENDANCE')
            ->logApprove(
                'attendance_change_request',
                $id,
                $cr,
                "Menyetujui pengajuan {$cr['request_number']}: {$note}"
            );

        // Sprint 15+ — webhook outbound `attendance.change_approved`
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::ATTENDANCE_CHANGE_APPROVED,
            (int) $cr['company_id'],
            [
                'change_request_id' => $id,
                'request_number'    => $cr['request_number'] ?? null,
                'attendance_id'     => $attId,
                'employee_id'       => (int) $cr['employee_id'],
                'work_date'         => $cr['work_date'],
                'requested_clock_in'  => $cr['requested_clock_in'] ?? null,
                'requested_clock_out' => $cr['requested_clock_out'] ?? null,
                'requested_status'  => $cr['requested_status'] ?? null,
                'approved_by'       => $this->userId(),
                'approved_at'       => date('c'),
            ],
        );

        return $this->ok(['attendance_id' => $attId], 'Pengajuan disetujui & absensi diperbarui');
    }

    public function reject($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $cr = $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $cr) return $this->fail('Pengajuan tidak ditemukan', 404);
        if ($cr['status'] !== 'PENDING') {
            return $this->fail('Pengajuan tidak dalam status PENDING', 422, 'NOT_PENDING');
        }
        if (! $this->tenant()->canAccessBranch(
            (int) $cr['company_id'],
            isset($cr['branch_id']) ? (int) $cr['branch_id'] : null
        )) {
            return $this->fail('Forbidden: pengajuan di luar scope Anda', 403, 'OUT_OF_SCOPE');
        }
        if (! $this->tenant()->hasPermission('attendance.approve_change')) {
            return $this->fail('Anda tidak punya hak menolak pengajuan absensi', 403, 'NO_APPROVE_PERMISSION');
        }

        $data = $this->getJsonInput();
        $note = trim((string) ($data['review_note'] ?? ''));
        if ($note === '') {
            return $this->fail('Alasan penolakan wajib diisi', 422, 'REASON_REQUIRED');
        }

        $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->update([
            'status'      => 'REJECTED',
            'reviewer_id' => $this->userId(),
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_note' => $note,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Notifikasi ke karyawan
        $emp = $db->table('employees')->where('id', $cr['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if ($emp && ! empty($emp['user_id'])) {
            Services::notification()->send(
                (int) $emp['user_id'],
                (int) $cr['company_id'],
                'attendance.change.rejected',
                'Pengajuan perubahan absen ditolak',
                "Pengajuan {$cr['request_number']} ditolak: {$note}",
                ['change_request_id' => $id],
                '/attendance/my-changes',
                true
            );
        }

        Services::audit()
            ->forModule('ATTENDANCE')
            ->logReject('attendance_change_request', $id, $cr, $note);

        return $this->ok(null, 'Pengajuan ditolak');
    }

    /**
     * Pemilik pengajuan bisa membatalkan pengajuannya selama masih PENDING.
     */
    public function cancel($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $cr = $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $cr) return $this->fail('Pengajuan tidak ditemukan', 404);
        if ((int) $cr['employee_id'] !== (int) $this->tenant()->getEmployeeId()) {
            return $this->fail('Hanya pemilik pengajuan yang bisa membatalkan', 403);
        }
        if ($cr['status'] !== 'PENDING') {
            return $this->fail('Pengajuan tidak dalam status PENDING', 422, 'NOT_PENDING');
        }
        $db->table('attendance_change_requests')->where('id', $id)->where('company_id', $companyId)->update([
            'status'     => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()
            ->forModule('ATTENDANCE')
            ->log(
                action: 'attendance_change_request.cancel',
                entity: 'attendance_change_request',
                entityId: $id,
                description: "Pengajuan {$cr['request_number']} dibatalkan oleh pemilik"
            );
        return $this->ok(null, 'Pengajuan dibatalkan');
    }

    private function validateChangeTypePayload(
        string $type,
        ?string $reqIn,
        ?string $reqOut,
        ?string $reqStatus,
        string $workDate
    ): void {
        $minDt = $workDate . ' 00:00:00';
        $maxDt = $workDate . ' 23:59:59';
        $checkRange = function (?string $dt, string $field) use ($minDt, $maxDt) {
            if (! $dt) return;
            if ($dt < $minDt || $dt > $maxDt) {
                $this->fail("{$field} harus berada di tanggal {$minDt} s/d {$maxDt}", 422, 'OUT_OF_DAY')->send();
                exit;
            }
        };
        $checkRange($reqIn, 'requested_clock_in');
        $checkRange($reqOut, 'requested_clock_out');

        if ($reqIn && $reqOut && $reqOut < $reqIn) {
            $this->fail('requested_clock_out harus lebih besar dari requested_clock_in', 422, 'INVALID_RANGE')->send();
            exit;
        }

        switch ($type) {
            case 'CLOCK_IN':
                if (! $reqIn) {
                    $this->fail('requested_clock_in wajib untuk tipe CLOCK_IN', 422, 'MISSING_FIELD')->send();
                    exit;
                }
                break;
            case 'CLOCK_OUT':
                if (! $reqOut) {
                    $this->fail('requested_clock_out wajib untuk tipe CLOCK_OUT', 422, 'MISSING_FIELD')->send();
                    exit;
                }
                break;
            case 'BOTH':
                if (! $reqIn || ! $reqOut) {
                    $this->fail('requested_clock_in dan requested_clock_out wajib untuk tipe BOTH', 422, 'MISSING_FIELD')->send();
                    exit;
                }
                break;
            case 'STATUS':
                if (! $reqStatus) {
                    $this->fail('requested_status wajib untuk tipe STATUS', 422, 'MISSING_FIELD')->send();
                    exit;
                }
                break;
            case 'MISSED':
                // Tipe untuk hari yang lupa absen — minimal salah satu in/out
                if (! $reqIn && ! $reqOut) {
                    $this->fail('Setidaknya requested_clock_in atau requested_clock_out harus diisi', 422, 'MISSING_FIELD')->send();
                    exit;
                }
                break;
        }
    }

    /**
     * Kirim notifikasi ke approver. Strategi:
     *  - Semua user di company yang sama dengan permission approve_change
     *    → kalau punya `attendance.view_all_branches` (HR pusat) terima.
     *  - Selain itu, hanya yang branch_id-nya match dengan branch karyawan.
     */
    private function notifyApprovers(int $companyId, ?int $branchId, int $crId, string $employeeName, string $workDate, string $type): void
    {
        // CI4 Query Builder akan escape `DISTINCT` sebagai nama kolom kalau
        // dipakai di select() — pakai distinct() builder method.
        $db = \Config\Database::connect();
        $approverRoles = $db->table('role_permissions rp')
            ->select('rp.role_id')
            ->distinct()
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('p.slug', 'attendance.approve_change')
            ->get()->getResultArray();
        if (! $approverRoles) return;
        $roleIds = array_map(fn($r) => (int) $r['role_id'], $approverRoles);

        $candidateBuilder = $db->table('users u')
            ->select('u.id, u.role_id, e.branch_id AS user_branch_id')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('u.is_active', 1)
            ->where('u.company_id', $companyId)
            ->whereIn('u.role_id', $roleIds);
        $candidates = $candidateBuilder->get()->getResultArray();

        // Permission lintas branch per role
        $crossBranchRoles = $db->table('role_permissions rp')
            ->select('rp.role_id')
            ->distinct()
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('p.slug', 'attendance.view_all_branches')
            ->get()->getResultArray();
        $crossBranchRoleIds = array_map(fn($r) => (int) $r['role_id'], $crossBranchRoles);

        $notifier = Services::notification();
        $message = "{$employeeName} mengajukan perubahan absen tanggal {$workDate} ({$type})";
        foreach ($candidates as $u) {
            $isCross = in_array((int) $u['role_id'], $crossBranchRoleIds, true);
            if (! $isCross && $branchId !== null && (int) ($u['user_branch_id'] ?? 0) !== $branchId) {
                continue;
            }
            $notifier->send(
                (int) $u['id'],
                $companyId,
                'attendance.change.requested',
                'Pengajuan perubahan absen baru',
                $message,
                ['change_request_id' => $crId],
                '/attendance/change-requests',
                true
            );
        }
    }
}
