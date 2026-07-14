<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use App\Models\Employee\EmployeeModel;
use Config\Services;

class AttendanceController extends ApiController
{
    /**
     * List absensi (admin/HR/manager).
     *
     * Filter ketat:
     *  - Super admin: bebas filter perusahaan via company_id.
     *  - User dengan permission `attendance.view_all_branches` (HR pusat):
     *    bisa filter `branch_id`, default = semua branch dalam company.
     *  - User lain (HR cabang, manager): di-force ke branch user. Parameter
     *    `branch_id` di query string yang berbeda akan ditolak 403.
     */
    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 30)));

        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $branchId   = (int) ($this->request->getGet('branch_id') ?? 0);
        $deptId     = (int) ($this->request->getGet('department_id') ?? 0);
        $status     = trim((string) $this->request->getGet('status'));
        $startDate  = (string) ($this->request->getGet('start_date') ?? '');
        $endDate    = (string) ($this->request->getGet('end_date') ?? '');
        $q          = trim((string) $this->request->getGet('q'));

        // Cross-branch filter validation: kalau user tidak punya
        // permission lintas-cabang, mereka tidak boleh memaksa branch_id
        // yang berbeda dari branch-nya.
        if (! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('attendance.view_all_branches')
            && $branchId > 0
            && $branchId !== $tenant->getBranchId()
        ) {
            return $this->fail('Akses lintas cabang tidak diizinkan', 403, 'CROSS_BRANCH_DENIED');
        }

        $builder = $db->table('attendances a')
            ->select('a.*, e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id, b.name AS branch_name, d.name AS department_name')
            ->join('employees e', 'e.id = a.employee_id')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left');

        $tenant->applyAttendanceScope($builder, 'a.company_id', 'e.branch_id');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): tanpa
        // `attendance.view_all`/`attendance.approve`, paksa filter
        // employee_id ke milik user. Sebelumnya `?employee_id=X` mentah
        // bisa pakai siapa saja → bocor.
        $effectiveEmpId = $this->privacy()->sanitizeEmployeeFilter(
            $requestedEmpId ?: null, 'attendance'
        );
        if (! $this->privacy()->canViewAll('attendance')) {
            $builder->where('a.employee_id', $effectiveEmpId);
        } elseif ($effectiveEmpId > 0) {
            $builder->where('a.employee_id', $effectiveEmpId);
        }
        if ($branchId)   $builder->where('e.branch_id', $branchId);
        if ($deptId)     $builder->where('e.department_id', $deptId);
        if ($status)     $builder->where('a.status', $status);
        if ($startDate)  $builder->where('a.work_date >=', $startDate);
        if ($endDate)    $builder->where('a.work_date <=', $endDate);
        if ($q !== '') {
            $builder->groupStart()
                ->like('e.full_name', $q)
                ->orLike('e.employee_number', $q)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('a.work_date', 'DESC')
            ->orderBy('e.full_name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * Absensi karyawan yang sedang login (calendar view).
     *
     * Mengembalikan rentang tanggal start_date..end_date (default: bulan
     * berjalan) dengan satu entry per tanggal. Untuk hari yang belum lewat,
     * hanya menampilkan jadwal shift / hari libur / cuti yang sudah disetujui.
     */
    public function my()
    {
        $tenant = $this->tenant();
        $employeeId = $tenant->getEmployeeId();
        if (! $employeeId) return $this->fail('User tidak terhubung dengan karyawan', 400, 'NO_EMPLOYEE_LINK');
        $employee = (new EmployeeModel())->findScoped((int) $employeeId);
        if (! $employee) return $this->fail('Karyawan tidak tersedia pada tenant aktif', 403, 'EMPLOYEE_TENANT_MISMATCH');

        $today = date('Y-m-d');
        $start = (string) ($this->request->getGet('start_date') ?? date('Y-m-01'));
        $end   = (string) ($this->request->getGet('end_date') ?? date('Y-m-t'));
        // Backward compat: kalau klien lama hanya kirim ?month=YYYY-MM.
        $month = (string) $this->request->getGet('month');
        if ($month) {
            $start = $month . '-01';
            $end   = date('Y-m-t', strtotime($start));
        }
        if (strtotime($start) === false || strtotime($end) === false) {
            return $this->fail('Format tanggal tidak valid', 422);
        }
        if (strtotime($end) < strtotime($start)) {
            return $this->fail('end_date harus >= start_date', 422);
        }
        // Batasi rentang max 366 hari supaya tidak meledak.
        if ((strtotime($end) - strtotime($start)) / 86400 > 366) {
            return $this->fail('Rentang tanggal maksimal 366 hari', 422);
        }

        $rows = $this->buildCalendar((int) $employeeId, $start, $end);

        return $this->ok([
            'employee_id' => (int) $employeeId,
            'start_date'  => $start,
            'end_date'    => $end,
            'today'       => $today,
            'days'        => $rows,
            'summary'     => $this->summarize($rows),
        ]);
    }

    /**
     * Calendar view untuk karyawan tertentu (HR/manager).
     */
    public function calendar($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        if (! $employeeId) return $this->fail('employee_id wajib', 422);

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

        if (! $this->tenant()->canAccessBranch(
            (int) $emp['company_id'],
            isset($emp['branch_id']) ? (int) $emp['branch_id'] : null
        )) {
            return $this->fail('Akses ditolak: karyawan di luar scope Anda', 403, 'OUT_OF_SCOPE');
        }

        $start = (string) ($this->request->getGet('start_date') ?? date('Y-m-01'));
        $end   = (string) ($this->request->getGet('end_date') ?? date('Y-m-t'));
        if (strtotime($start) === false || strtotime($end) === false || strtotime($end) < strtotime($start)) {
            return $this->fail('Rentang tanggal tidak valid', 422);
        }
        if ((strtotime($end) - strtotime($start)) / 86400 > 366) {
            return $this->fail('Rentang tanggal maksimal 366 hari', 422);
        }

        $rows = $this->buildCalendar($employeeId, $start, $end);

        return $this->ok([
            'employee'   => [
                'id' => (int) $emp['id'],
                'employee_number' => $emp['employee_number'],
                'full_name' => $emp['full_name'],
                'branch_id' => isset($emp['branch_id']) ? (int) $emp['branch_id'] : null,
                'department_id' => isset($emp['department_id']) ? (int) $emp['department_id'] : null,
            ],
            'start_date' => $start,
            'end_date'   => $end,
            'today'      => date('Y-m-d'),
            'days'       => $rows,
            'summary'    => $this->summarize($rows),
        ]);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('attendances a')
            ->select('a.*, e.full_name AS employee_name, e.employee_number, e.branch_id, e.department_id')
            ->join('employees e', 'e.id = a.employee_id')
            ->where('a.id', (int) $id)
            ->where('a.company_id', $this->requireCompanyId())
            ->get()
            ->getRowArray();
        if (! $row) return $this->fail('Not found', 404);

        $allowOwn = $this->tenant()->getEmployeeId() === (int) $row['employee_id'];
        if (! $allowOwn && ! $this->tenant()->canAccessBranch(
            (int) $row['company_id'],
            isset($row['branch_id']) ? (int) $row['branch_id'] : null
        )) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function clockIn()
    {
        $employeeId = $this->tenant()->getEmployeeId();
        if (! $employeeId) return $this->fail('No employee linked', 400);

        $data = $this->getJsonInput();
        // Selfie via base64 data URL → simpan ke writable/uploads/selfies/.
        // Hasilnya dijadikan photo_path supaya pipeline anomaly bawah tetap jalan.
        if (! empty($data['photo_data_url'])) {
            $saved = $this->saveSelfieFromDataUrl(
                (string) $data['photo_data_url'],
                (int) $employeeId
            );
            if ($saved) $data['photo_path'] = $saved;
        }

        $db = \Config\Database::connect();
        $activeCompanyId = $this->requireCompanyId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $activeCompanyId)
            ->get()->getRowArray();
        if (! $emp || (int) $emp['is_active'] !== 1) return $this->fail('Inactive employee', 400);

        $companyId = (int) $emp['company_id'];
        $today = date('Y-m-d');
        $clockInTs = time();

        // Sprint 5-6 — resolve effective work date & shift dengan support
        // shift cross-midnight (overnight 22:00→06:00). Kalau shift normal,
        // workDate = today. Kalau overnight & clock-in dini hari, workDate
        // = kemarin (tanggal mulai shift).
        $shiftHelper = Services::shiftTime();

        // Determine shift via ShiftResolver — prioritas:
        // shift_schedules → employees.default_shift_id → pattern → assignment
        // → shifts.is_default → fallback shift aktif pertama.
        // Resolve untuk today dulu; kalau overnight dan clock-in dini hari,
        // re-resolve untuk yesterday (shift kemarin yang masih aktif).
        $resolved = Services::shiftResolver()->resolve((int) $employeeId, $today);
        $shift   = $resolved['shift'] ?? null;
        $workDate = $today;

        if ($shift && $shiftHelper->isOvernight($shift)) {
            $effective = $shiftHelper->effectiveWorkDate($shift, $clockInTs);
            if ($effective !== $today) {
                // Clock-in dini hari → shift mulai kemarin. Re-resolve
                // untuk pastikan kemarin memang assigned shift overnight.
                $resolvedYesterday = Services::shiftResolver()->resolve((int) $employeeId, $effective);
                if (! empty($resolvedYesterday['shift'])
                    && $shiftHelper->isOvernight($resolvedYesterday['shift'])
                ) {
                    $resolved = $resolvedYesterday;
                    $shift = $resolved['shift'];
                    $workDate = $effective;
                }
            }
        }
        $shiftId = $shift['id'] ?? null;

        // Cek duplicate clock-in dengan workDate yang sudah resolved
        $existing = $db->table('attendances')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->get()->getRowArray();
        if ($existing && $existing['clock_in_at']) {
            return $this->fail('Already clocked in today', 409, 'ALREADY_CLOCKED_IN');
        }

        // Kalau resolver bilang hari ini OFF / holiday tapi karyawan tetap
        // memutuskan clock-in (mis. lembur hari libur), kita izinkan tapi
        // catat agar muncul di laporan.
        if (! empty($resolved['is_off']) && empty($shift)) {
            // Tidak ada shift sama sekali → tetap proses clock-in tapi tanpa
            // perhitungan late.
        }

        // Late minutes — pakai ShiftTimeHelper agar overnight shift di-handle benar
        $lateMin = 0;
        $status = 'PRESENT';
        if ($shift) {
            $tolerance = (int) ($shift['late_tolerance_minutes'] ?? 0);
            $lateMin = $shiftHelper->lateMinutes($shift, $clockInTs, $tolerance);
            if ($lateMin > 0) $status = 'LATE';
        }

        // Sprint 8 / H1.5 — WFH mode resolution.
        // Karyawan dengan WFH approved untuk hari ini → skip geofence enforcement.
        // work_type otomatis = 'WFH'. Kalau client kirim work_type explicit
        // (mis. 'OFFSITE' untuk client visit), respect itu — TAPI hanya kalau
        // tidak conflict dengan WFH approved.
        $wfhService = new \App\Libraries\Attendance\WfhRequestService();
        $isWfhApproved = $wfhService->isApprovedFor($employeeId, $workDate);
        $clientWorkType = strtoupper((string) ($data['work_type'] ?? ''));
        $workType = 'ONSITE';
        if ($isWfhApproved) {
            $workType = 'WFH';
        } elseif (in_array($clientWorkType, ['OFFSITE'], true)) {
            // OFFSITE diperbolehkan tanpa pre-approval (mis. client visit
            // ad-hoc). WFH tanpa approval TIDAK diperbolehkan supaya karyawan
            // tidak bypass aturan dengan ngaku WFH.
            $workType = 'OFFSITE';
        }

        // Geofence (optional) — track distance untuk anti-cheat juga
        $distanceToBranch = null;
        $geofenceRadius = null;
        $branchId = $emp['branch_id'] ?? null;
        if ($branchId && isset($data['lat'], $data['lng'])) {
            $branch = $db->table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->get()->getRowArray();
            if ($branch && $branch['lat'] && $branch['lng'] && $branch['geofence_radius_m']) {
                $distanceToBranch = $this->haversine((float) $branch['lat'], (float) $branch['lng'], (float) $data['lat'], (float) $data['lng']);
                $geofenceRadius = (float) $branch['geofence_radius_m'];
                // Skip geofence enforcement untuk WFH/OFFSITE (jarak tetap
                // dicatat untuk anti-cheat scoring di bawah).
                if ($workType === 'ONSITE' && $distanceToBranch > $geofenceRadius) {
                    return $this->fail('Outside allowed clock-in radius (' . round($distanceToBranch) . 'm > ' . $branch['geofence_radius_m'] . 'm)', 422, 'OUTSIDE_GEOFENCE');
                }
            }
        }

        // ─── Anti-cheat: track device + compute anomaly ───
        $userId = (int) $this->tenant()->getUserId();
        $userAgent = (string) $this->request->getUserAgent();
        $clientIp = $this->request->getIPAddress();
        $svcAntiCheat = Services::antiCheat();

        $deviceId = (string) ($data['device_id'] ?? $svcAntiCheat->generateServerFingerprint($userAgent, (string) $this->request->getHeaderLine('Accept-Language')));
        $deviceTrack = $svcAntiCheat->trackDevice($companyId, $userId, [
            'device_id'   => $deviceId,
            'device_name' => $data['device_name'] ?? substr($userAgent, 0, 100),
            'user_agent'  => $userAgent,
            'ip'          => $clientIp,
        ]);

        $anomaly = $svcAntiCheat->computeAnomaly([
            'company_id'         => $companyId,
            'user_id'            => $userId,
            'accuracy'           => (float) ($data['accuracy'] ?? 0),
            'is_mock_gps'        => ! empty($data['is_mock_gps']),
            'distance_to_branch' => $distanceToBranch,
            'geofence_radius'    => $geofenceRadius,
            'has_selfie'         => ! empty($data['photo_path']),
            'device'             => $deviceTrack,
            'ip'                 => $clientIp,
        ]);

        if ($anomaly['should_block']) {
            try {
                Services::audit()->forModule('ATTENDANCE')->log(
                    action: 'attendance.cheat_blocked',
                    entity: 'attendances', entityId: 0,
                    description: "Clock-in di-block: {$anomaly['block_reason']} | flags=" . implode(',', $anomaly['flags']),
                    severity: 'WARNING',
                );
            } catch (\Throwable $e) {}
            return $this->fail($anomaly['block_reason'], 422, 'CHEAT_DETECTED', ['flags' => $anomaly['flags']]);
        }

        $row = [
            'company_id'   => $companyId,
            'employee_id'  => $employeeId,
            'shift_id'     => $shiftId,
            'work_type'    => $workType, // Sprint 8 / H1.5
            'work_date'    => $workDate,
            'clock_in_at'  => date('Y-m-d H:i:s'),
            'clock_in_lat' => $data['lat'] ?? null,
            'clock_in_lng' => $data['lng'] ?? null,
            'clock_in_photo' => $data['photo_path'] ?? null,
            'clock_in_address' => $data['address'] ?? null,
            'clock_in_notes' => $data['notes'] ?? null,
            'late_minutes' => $lateMin,
            'status'       => $status,
            // Anti-cheat fields
            'clock_in_accuracy'    => $data['accuracy'] ?? null,
            'clock_in_is_mock_gps' => ! empty($data['is_mock_gps']) ? 1 : 0,
            'clock_in_device_id'   => $deviceId,
            'clock_in_device_name' => $data['device_name'] ?? substr($userAgent, 0, 100),
            'clock_in_user_agent'  => substr($userAgent, 0, 500),
            'clock_in_ip'          => $clientIp,
            'clock_in_selfie_path' => $data['photo_path'] ?? null,
            'anomaly_score'        => $anomaly['score'],
            'anomaly_flags'        => json_encode($anomaly['flags']),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $db->table('attendances')
                ->where('id', $existing['id'])
                ->where('company_id', $companyId)
                ->update($row);
            $id = (int) $existing['id'];
        } else {
            $db->table('attendances')->insert($row);
            $id = (int) $db->insertID();
        }
        Services::audit()
            ->forModule('ATTENDANCE')
            ->log(
                action: 'attendance.clock_in',
                entity: 'attendance',
                entityId: $id,
                after: ['clock_in_at' => $row['clock_in_at'], 'late_minutes' => $lateMin, 'status' => $status],
                description: "Clock-in karyawan #{$employeeId} ({$status}, terlambat {$lateMin} menit)",
                metadata: ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]
            );
        return $this->ok(['id' => $id, 'late_minutes' => $lateMin, 'status' => $status], 'Clocked in');
    }

    public function clockOut()
    {
        $employeeId = $this->tenant()->getEmployeeId();
        if (! $employeeId) return $this->fail('No employee linked', 400);

        $data = $this->getJsonInput();
        if (! empty($data['photo_data_url'])) {
            $saved = $this->saveSelfieFromDataUrl(
                (string) $data['photo_data_url'],
                (int) $employeeId
            );
            if ($saved) $data['photo_path'] = $saved;
        }
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $today = date('Y-m-d');
        $now = time();

        // Sprint 5-6 — cari attendance row aktif: prioritas today (open
        // clock-in tanpa clock-out), lalu kemarin (untuk overnight shift
        // yang clock-out keesokan paginya).
        $row = $db->table('attendances')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date', $today)
            ->where('clock_in_at IS NOT NULL')
            ->get()->getRowArray();
        if (! $row || $row['clock_out_at']) {
            // Kemungkinan shift overnight → cari row kemarin yang masih open
            $yesterday = date('Y-m-d', $now - 86400);
            $candidate = $db->table('attendances')
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('work_date', $yesterday)
                ->where('clock_in_at IS NOT NULL')
                ->where('clock_out_at IS NULL')
                ->get()->getRowArray();
            if ($candidate) $row = $candidate;
        }
        if (! $row || ! $row['clock_in_at']) return $this->fail('No clock-in found for today', 400, 'NO_CLOCK_IN');
        if ($row['clock_out_at']) return $this->fail('Already clocked out', 409, 'ALREADY_CLOCKED_OUT');

        $workMinutes = (int) round(($now - strtotime($row['clock_in_at'])) / 60);

        // Early leave — pakai ShiftTimeHelper supaya overnight shift dihitung dari
        // shift end keesokan paginya, bukan today's date.
        $earlyLeave = 0;
        if ($row['shift_id']) {
            $shift = $db->table('shifts')
                ->where('id', $row['shift_id'])
                ->where('company_id', $companyId)
                ->get()->getRowArray();
            if ($shift) {
                $shiftHelper = Services::shiftTime();
                $shiftStart = strtotime($row['clock_in_at']);
                // Recompute shiftStart canonical dari work_date + start_time
                $canonicalStart = $shiftHelper->effectiveShiftStart($shift, $shiftStart);
                $tol = (int) ($shift['early_leave_tolerance_minutes'] ?? 0);
                $earlyLeave = $shiftHelper->earlyLeaveMinutes($shift, $now, $canonicalStart, $tol);
            }
        }

        $db->table('attendances')
            ->where('id', $row['id'])
            ->where('company_id', $companyId)
            ->update([
            'clock_out_at'  => date('Y-m-d H:i:s'),
            'clock_out_lat' => $data['lat'] ?? null,
            'clock_out_lng' => $data['lng'] ?? null,
            'clock_out_photo' => $data['photo_path'] ?? null,
            'clock_out_address' => $data['address'] ?? null,
            'clock_out_notes' => $data['notes'] ?? null,
            'work_minutes'  => $workMinutes,
            'early_leave_minutes' => $earlyLeave,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        Services::audit()
            ->forModule('ATTENDANCE')
            ->log(
                action: 'attendance.clock_out',
                entity: 'attendance',
                entityId: $row['id'],
                after: ['work_minutes' => $workMinutes, 'early_leave_minutes' => $earlyLeave],
                description: "Clock-out karyawan #{$employeeId} (kerja {$workMinutes} menit, pulang cepat {$earlyLeave} menit)",
                metadata: ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null]
            );
        return $this->ok(['work_minutes' => $workMinutes, 'early_leave_minutes' => $earlyLeave], 'Clocked out');
    }

    /**
     * Endpoint koreksi lama (kompatibilitas mundur).
     *
     * Untuk fitur baru lihat AttendanceChangeRequestController.
     */
    public function correction($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|max_length[1000]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $att = $db->table('attendances')->where('id', $id)->get()->getRowArray();
        if (! $att) return $this->fail('Attendance not found', 404);

        $db->table('attendance_corrections')->insert([
            'company_id'    => $att['company_id'],
            'attendance_id' => $id,
            'employee_id'   => $att['employee_id'],
            'requested_clock_in'  => $data['clock_in_at'] ?? null,
            'requested_clock_out' => $data['clock_out_at'] ?? null,
            'reason'        => $data['reason'],
            'attachment'    => $data['attachment'] ?? null,
            'status'        => 'PENDING',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $correctionId = (int) $db->insertID();

        // Notify HR
        $hrUsers = $db->table('users')->where('company_id', $att['company_id'])->whereIn('role_id', [2, 3])->get()->getResultArray();
        $notifier = Services::notification();
        foreach ($hrUsers as $u) {
            $notifier->send((int) $u['id'], (int) $att['company_id'], 'attendance.correction.requested',
                'Permintaan koreksi absen', 'Ada permintaan koreksi absen yang menunggu persetujuan',
                ['correction_id' => $correctionId], '/attendance/corrections');
        }
        return $this->ok(['id' => $correctionId], 'Correction requested', 201);
    }

    /**
     * Build per-day calendar entries between $start and $end for an employee.
     *
     * Setiap entry: {date, day_name, is_weekend, is_future, is_today,
     *               is_holiday, holiday_name, shift, attendance, leave,
     *               status, status_label, status_color, change_request}.
     */
    private function buildCalendar(int $employeeId, string $start, string $end): array
    {
        $db = \Config\Database::connect();

        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        $companyId = $emp ? (int) $emp['company_id'] : 0;
        // Sprint 23+ — tanggal masa kerja efektif. Cell di luar window
        // ini dilabeli BEFORE_JOIN / AFTER_RESIGN supaya UI bisa render
        // disabled state (tidak ada absensi lama, tidak ada jadwal masa
        // depan setelah resign).
        $joinDate   = $emp['join_date']   ?? null;
        $resignDate = $emp['resign_date'] ?? null;

        // Pre-fetch attendances dalam rentang
        $attRows = $db->table('attendances')
            ->where('employee_id', $employeeId)
            ->where('work_date >=', $start)
            ->where('work_date <=', $end)
            ->get()->getResultArray();
        $attByDate = [];
        foreach ($attRows as $r) $attByDate[$r['work_date']] = $r;

        // Shift schedules
        $schedRows = $db->table('shift_schedules ss')
            ->select('ss.*, s.code AS shift_code, s.name AS shift_name, s.start_time, s.end_time, s.late_tolerance_minutes')
            ->join('shifts s', 's.id = ss.shift_id', 'left')
            ->where('ss.employee_id', $employeeId)
            ->where('ss.work_date >=', $start)
            ->where('ss.work_date <=', $end)
            ->get()->getResultArray();
        $schedByDate = [];
        foreach ($schedRows as $r) $schedByDate[$r['work_date']] = $r;

        // Default shift untuk fallback
        $defaultShift = null;
        if ($companyId) {
            $defaultShift = $db->table('shifts')
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->orderBy('id')->limit(1)
                ->get()->getRowArray();
        }

        // Holidays (national + per-company)
        $holidayRows = [];
        if ($companyId) {
            $holidayRows = $db->table('holidays')
                ->groupStart()
                    ->where('company_id', $companyId)
                    ->orWhere('company_id IS NULL')
                ->groupEnd()
                ->where('date >=', $start)
                ->where('date <=', $end)
                ->get()->getResultArray();
        }
        $holidayByDate = [];
        foreach ($holidayRows as $r) $holidayByDate[$r['date']] = $r;

        // Approved leaves overlapping the range
        $leaveRows = $db->table('leaves l')
            ->select('l.*, lt.code AS leave_code, lt.name AS leave_name')
            ->join('leave_types lt', 'lt.id = l.leave_type_id')
            ->where('l.employee_id', $employeeId)
            ->whereIn('l.status', ['APPROVED', 'PENDING'])
            ->where('l.start_date <=', $end)
            ->where('l.end_date >=', $start)
            ->get()->getResultArray();

        // Pending change requests in range
        $crRows = $db->table('attendance_change_requests')
            ->where('employee_id', $employeeId)
            ->where('work_date >=', $start)
            ->where('work_date <=', $end)
            ->whereIn('status', ['PENDING', 'APPROVED', 'REJECTED'])
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
        $crByDate = [];
        foreach ($crRows as $r) {
            // Simpan yang terbaru per tanggal
            if (! isset($crByDate[$r['work_date']])) {
                $crByDate[$r['work_date']] = $r;
            }
        }

        $today = date('Y-m-d');
        $days = [];
        $cursor = strtotime($start);
        $endTs  = strtotime($end);
        while ($cursor <= $endTs) {
            $date = date('Y-m-d', $cursor);
            $dow  = (int) date('N', $cursor); // 1..7
            $isWeekend = $dow >= 6;
            $isFuture  = $date > $today;
            $isToday   = $date === $today;

            // Sprint 23+ — Mask hari sebelum join_date & setelah resign_date.
            // Karyawan tidak punya catatan absensi di luar masa kerja, jadi
            // sel-sel kalender ini ditampilkan disabled dengan label khusus
            // supaya tidak mengundang submit data yang salah.
            $isBeforeJoin = $joinDate   && $date < $joinDate;
            $isAfterResign = $resignDate && $date > $resignDate;

            if ($isBeforeJoin || $isAfterResign) {
                $reason = $isBeforeJoin ? 'BEFORE_JOIN' : 'AFTER_RESIGN';
                $label  = $isBeforeJoin
                    ? 'Belum Join'
                    : 'Sudah Resign';
                $note   = $isBeforeJoin
                    ? ('Anda baru bergabung pada ' . date('d M Y', strtotime((string) $joinDate)))
                    : ('Anda resign pada ' . date('d M Y', strtotime((string) $resignDate)));

                $days[] = [
                    'date'           => $date,
                    'day_name'       => $this->dayName($dow),
                    'is_weekend'     => $isWeekend,
                    'is_today'       => $isToday,
                    'is_future'      => $isFuture,
                    'is_past'        => $date < $today,
                    'is_holiday'     => false,
                    'holiday_name'   => null,
                    'shift'          => null,
                    'attendance'     => null,
                    'leave'          => null,
                    'status'         => $reason,
                    'status_label'   => $label,
                    'status_color'   => 'gray',
                    'note'           => $note,
                    'change_request' => null,
                    'is_before_join' => $isBeforeJoin,
                    'is_after_resign'=> $isAfterResign,
                    'is_locked'      => true,
                ];

                $cursor += 86400;
                continue;
            }

            $sched = $schedByDate[$date] ?? null;
            $shift = null;
            if ($sched && ! empty($sched['shift_id'])) {
                $shift = [
                    'id'         => (int) $sched['shift_id'],
                    'code'       => $sched['shift_code'],
                    'name'       => $sched['shift_name'],
                    'start_time' => $sched['start_time'],
                    'end_time'   => $sched['end_time'],
                    'is_off'     => (int) ($sched['is_off'] ?? 0) === 1,
                ];
            } elseif (! $isWeekend && $defaultShift) {
                $shift = [
                    'id'         => (int) $defaultShift['id'],
                    'code'       => $defaultShift['code'],
                    'name'       => $defaultShift['name'],
                    'start_time' => $defaultShift['start_time'],
                    'end_time'   => $defaultShift['end_time'],
                    'is_off'     => false,
                ];
            }

            $att = $attByDate[$date] ?? null;
            $isHoliday = isset($holidayByDate[$date]);

            // Cuti yang mencakup tanggal ini
            $leaveOnDay = null;
            foreach ($leaveRows as $lv) {
                if ($lv['start_date'] <= $date && $lv['end_date'] >= $date) {
                    $leaveOnDay = $lv;
                    break;
                }
            }

            // Tentukan status final + label
            [$status, $label, $color, $note] = $this->resolveDayStatus(
                $date,
                $isFuture,
                $isToday,
                $isWeekend,
                $isHoliday,
                $shift,
                $att,
                $leaveOnDay
            );

            $days[] = [
                'date'           => $date,
                'day_name'       => $this->dayName($dow),
                'is_weekend'     => $isWeekend,
                'is_today'       => $isToday,
                'is_future'      => $isFuture,
                'is_past'        => $date < $today,
                'is_holiday'     => $isHoliday,
                'holiday_name'   => $isHoliday ? $holidayByDate[$date]['name'] : null,
                'shift'          => $shift,
                'attendance'     => $att,
                'leave'          => $leaveOnDay,
                'status'         => $status,
                'status_label'   => $label,
                'status_color'   => $color,
                'note'           => $note,
                'change_request' => $crByDate[$date] ?? null,
                'is_before_join' => false,
                'is_after_resign'=> false,
                'is_locked'      => false,
            ];

            $cursor += 86400;
        }
        return $days;
    }

    private function resolveDayStatus(
        string $date,
        bool $isFuture,
        bool $isToday,
        bool $isWeekend,
        bool $isHoliday,
        ?array $shift,
        ?array $att,
        ?array $leave
    ): array {
        // 1) Hari libur nasional / weekend tanpa shift
        if ($isHoliday) {
            return ['HOLIDAY', 'Hari Libur', 'blue', 'Hari libur'];
        }
        if ($isWeekend && (! $shift || ! empty($shift['is_off']))) {
            return ['OFF', 'Libur Mingguan', 'gray', null];
        }

        // 2) Cuti yang sudah disetujui menutupi semua status lain
        if ($leave) {
            $st = $leave['status'] === 'APPROVED' ? 'LEAVE' : 'LEAVE_PENDING';
            $lbl = ($leave['status'] === 'APPROVED' ? 'Cuti' : 'Cuti (Menunggu)') . ' — ' . ($leave['leave_name'] ?? $leave['leave_code']);
            $color = $leave['status'] === 'APPROVED' ? 'blue' : 'amber';
            return [$st, $lbl, $color, $leave['reason'] ?? null];
        }

        // 3) Tanggal mendatang: tampilkan jadwal
        if ($isFuture) {
            if ($shift) {
                $lbl = 'Jadwal: ' . $shift['name'] . ' (' . substr((string) $shift['start_time'], 0, 5) . '–' . substr((string) $shift['end_time'], 0, 5) . ')';
                return ['SCHEDULED', $lbl, 'gray', null];
            }
            return ['SCHEDULED', 'Belum dijadwalkan', 'gray', null];
        }

        // 4) Ada baris attendance: pakai status-nya
        if ($att) {
            $status = $att['status'] ?? 'PRESENT';
            $note = null;
            if (! empty($att['late_minutes']) && (int) $att['late_minutes'] > 0) {
                $note = 'Terlambat ' . (int) $att['late_minutes'] . ' menit';
            }
            if (! empty($att['early_leave_minutes']) && (int) $att['early_leave_minutes'] > 0) {
                $note = ($note ? $note . ', ' : '') . 'Pulang cepat ' . (int) $att['early_leave_minutes'] . ' menit';
            }
            $map = [
                'PRESENT'  => ['Hadir',         'green'],
                'LATE'     => ['Terlambat',     'amber'],
                'ABSENT'   => ['Tanpa Kabar',   'red'],
                'LEAVE'    => ['Cuti',          'blue'],
                'HOLIDAY'  => ['Libur',         'blue'],
                'SICK'     => ['Sakit',         'amber'],
                'HALF_DAY' => ['Setengah Hari', 'amber'],
            ];
            [$lbl, $color] = $map[$status] ?? [$status, 'gray'];
            return [$status, $lbl, $color, $note];
        }

        // 5) Hari ini, belum clock-in
        if ($isToday) {
            return ['NOT_CLOCKED_IN', 'Belum clock-in', 'gray', null];
        }

        // 6) Hari kerja yang sudah lewat tanpa data → ABSENT
        if ($shift) {
            return ['ABSENT', 'Tanpa Kabar', 'red', 'Tidak ada data absensi'];
        }

        // 7) Hari kerja tanpa shift ditugaskan
        return ['NO_SCHEDULE', 'Tanpa Jadwal', 'gray', null];
    }

    private function summarize(array $days): array
    {
        $sum = [
            'total_days'   => count($days),
            'present'      => 0,
            'late'         => 0,
            'absent'       => 0,
            'leave'        => 0,
            'holiday'      => 0,
            'off'          => 0,
            'late_minutes' => 0,
            'work_minutes' => 0,
        ];
        foreach ($days as $d) {
            switch ($d['status']) {
                case 'PRESENT':  $sum['present']++; break;
                case 'LATE':     $sum['late']++; $sum['present']++; break;
                case 'ABSENT':   $sum['absent']++; break;
                case 'LEAVE':
                case 'LEAVE_PENDING':
                case 'SICK':     $sum['leave']++; break;
                case 'HOLIDAY':  $sum['holiday']++; break;
                case 'OFF':      $sum['off']++; break;
            }
            if (! empty($d['attendance'])) {
                $sum['late_minutes'] += (int) ($d['attendance']['late_minutes'] ?? 0);
                $sum['work_minutes'] += (int) ($d['attendance']['work_minutes'] ?? 0);
            }
        }
        return $sum;
    }

    private function dayName(int $dow): string
    {
        $names = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        return $names[$dow] ?? '';
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Decode `data:image/jpeg;base64,...` ke file di writable/uploads/selfies/.
     * Hanya menerima JPEG/PNG ≤ 1.5 MB. Return path relatif (uploads/selfies/...)
     * atau null jika gagal/tidak valid.
     */
    private function saveSelfieFromDataUrl(string $dataUrl, int $employeeId): ?string
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png);base64,(.+)$/i', $dataUrl, $m)) {
            return null;
        }
        $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 1.5 * 1024 * 1024) {
            return null;
        }
        $dir = WRITEPATH . 'uploads/selfies/' . $employeeId;
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
            return null;
        }
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($full, $bin) === false) {
            return null;
        }
        return 'uploads/selfies/' . $employeeId . '/' . $name;
    }
}
