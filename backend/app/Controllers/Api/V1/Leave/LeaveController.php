<?php

namespace App\Controllers\Api\V1\Leave;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Leave\LeavePolicyService;
use App\Libraries\Leave\LeaveWorkflowException;
use App\Libraries\Leave\LeaveWorkflowService;
use App\Repositories\Leave\LeaveRepository;
use Config\Services;

class LeaveController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    /**
     * Aturan eligibilitas per kategori leave_types berdasarkan UU 13/2003,
     * UU 6/2023 (Cipta Kerja), PP 35/2021, dan praktik Kemenaker.
     *
     * Setiap entry mengembalikan:
     *   ['ok' => bool, 'reason' => string, 'default_days' => int|null]
     *
     * default_days hanya untuk informasi UI; perhitungan saldo tetap merujuk
     * ke leave_types.default_days yang dapat di-override per perusahaan.
     */

    /**
     * Tentukan window tanggal pengajuan berdasarkan kebijakan payroll +
     * kategori cuti. Mengembalikan:
     *   ['policy' => ..., 'per_category' => ['SICK' => ['min','max'], ...]]
     *
     * Aturan default:
     *  - Window pengajuan umum dimulai HARI INI (tidak boleh backdate),
     *    karena cuti yang sudah lewat membuat absensi historis kacau.
     *  - SICK boleh backdate maksimal ke awal periode payroll yang sedang
     *    AKTIF (status DRAFT) atau 14 hari ke belakang — mana yang lebih
     *    awal — supaya surat dokter yang baru diserahkan masih bisa dicatat.
     *  - Window berakhir di pay_date periode berikutnya (atau end_date
     *    periode aktif + 90 hari jika tidak ada periode berikutnya).
     *  - MATERNITY/PATERNITY/HAJJ punya look-ahead lebih panjang (180 hari)
     *    karena biasanya direncanakan jauh-jauh hari.
     *  - Kalau periode aktif sudah FINALIZED, semua tanggal di rentang itu
     *    di-block — pengajuan baru hanya untuk periode berikutnya.
     */

    /**
     * Endpoint pembantu untuk frontend:
     *   GET /api/v1/leave-types/for-me
     * Mengembalikan tipe cuti yang berlaku untuk user saat ini, dengan
     * flag `eligible` dan alasan ketidaklayakan kalau ada, plus window
     * tanggal yang dibolehkan per kategori (lihat getLeavePolicy()).
     */
    public function eligibleTypes()
    {
        $employeeId = $this->tenant()->getEmployeeId();
        if (! $employeeId) return $this->fail('User tidak terhubung dengan karyawan', 400, 'NO_EMPLOYEE_LINK');

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $emp = $this->leaveRepository()->employee($employeeId, $companyId);
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

        $policy = $this->leavePolicy()->policy($companyId);
        $types = $this->leaveRepository()->activeTypes($companyId);

        $year = (int) date('Y');
        $balances = $this->leaveRepository()->balances($employeeId, $companyId, $year);
        $balByType = [];
        foreach ($balances as $b) $balByType[(int) $b['leave_type_id']] = $b;

        $out = [];
        foreach ($types as $t) {
            $reasons = [];
            // 1) Filter gender (kolom resmi di leave_types).
            $gr = strtoupper((string) ($t['gender_restriction'] ?? 'ALL'));
            $empGender = strtoupper((string) ($emp['gender'] ?? ''));
            if ($gr !== 'ALL' && $gr !== $empGender) {
                $reasons[] = $gr === 'F'
                    ? 'Hanya untuk karyawan perempuan'
                    : 'Hanya untuk karyawan laki-laki';
            }
            // 2) Filter berdasarkan kategori (gender turunan + marital status + tenure).
            $cat = strtoupper((string) ($t['category'] ?? 'ANNUAL'));
            $catCheck = $this->leavePolicy()->categoryEligibility($cat, $emp);
            if (! $catCheck['ok']) $reasons[] = $catCheck['reason'];

            // 3) Tenure khusus per leave_types
            $minMonths = (int) ($t['min_tenure_months'] ?? 0);
            if ($minMonths > 0 && ! empty($emp['join_date'])) {
                $tenureMonths = (int) ((time() - strtotime($emp['join_date'])) / 86400 / 30);
                if ($tenureMonths < $minMonths) {
                    $reasons[] = "Belum memenuhi masa kerja minimal {$minMonths} bulan (saat ini {$tenureMonths} bulan)";
                }
            }

            // 4) Window tanggal per kategori (override default).
            $win = $policy['per_category'][$cat] ?? [
                'min_start_date' => $policy['default_min_start_date'],
                'max_start_date' => $policy['default_max_start_date'],
            ];

            $bal = $balByType[(int) $t['id']] ?? null;
            $out[] = [
                'id'                 => (int) $t['id'],
                'code'               => $t['code'],
                'name'               => $t['name'],
                'category'           => $cat,
                'is_paid'            => (int) $t['is_paid'] === 1,
                'requires_doc'       => (int) $t['requires_doc'] === 1,
                'gender_restriction' => $gr,
                'default_days'       => (int) ($t['default_days'] ?? 0),
                'min_tenure_months'  => $minMonths,
                'reset_yearly'       => (int) $t['reset_yearly'] === 1,
                'description'        => $t['description'],
                'eligible'           => empty($reasons),
                'reasons'            => $reasons,
                'balance'            => $bal ? [
                    'allocated' => (float) $bal['allocated'],
                    'used'      => (float) $bal['used'],
                    'remaining' => (float) $bal['remaining'],
                ] : null,
                'min_start_date'     => $win['min_start_date'],
                'max_start_date'     => $win['max_start_date'],
            ];
        }
        return $this->ok([
            'types'  => $out,
            'policy' => $policy,
        ]);
    }

    public function index()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = trim((string) $this->request->getGet('status'));
        // employee_id dari query param di-sanitasi via PrivacyScope:
        //   - canViewAll: pakai apa adanya
        //   - hanya view.own: di-paksa ke employee_id user, abaikan input
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $mine = $this->request->getGet('mine');

        // Privacy scope: tenant + (kalau view.own only) paksa employee_id sendiri.
        // `mine=1` query param adalah shortcut yang di-honor oleh applyListScope
        // saat user punya canViewAll dan ingin filter eksplisit ke diri sendiri.
        if (! $this->privacy()->canViewOwn('leave') && ! $this->privacy()->canViewAll('leave')) {
            return $this->fail('Anda tidak memiliki akses ke modul cuti.', 403);
        }
        $effectiveEmpId = $this->privacy()->sanitizeEmployeeFilter(
            $mine ? $this->tenant()->getEmployeeId() : ($requestedEmpId ?: null),
            'leave',
        );

        // Filter status di-allow untuk semua role (tidak ada PII di status).
        $result = $this->leaveRepository()->paginate(
            $companyId, $effectiveEmpId > 0 ? $effectiveEmpId : null, $status ?: null, $page, $perPage,
        );
        return $this->paginated($result['rows'], $result['total'], $page, $perPage);
    }

    public function show($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->leaveRepository()->leave((int) $id, $companyId);
        if (! $row) return $this->fail('Not found', 404);
        $isOwn = (int) $row['employee_id'] === $this->tenant()->getEmployeeId();
        if (! $isOwn && ! $this->privacy()->canViewAll('leave')) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'leave_type_id' => 'required|integer',
            'start_date' => 'required|valid_date',
            'end_date' => 'required|valid_date',
            'reason' => 'required|max_length[1000]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $employeeId = (int) ($data['employee_id'] ?? $this->tenant()->getEmployeeId());
        if ($employeeId <= 0) return $this->fail('Employee not specified', 400);

        $submittedForOther = isset($data['employee_id'])
            && $employeeId !== $this->tenant()->getEmployeeId();
        $bypassWindow = $submittedForOther
            && ($this->tenant()->isSuperAdmin() || $this->tenant()->hasPermission('leave.approve'));

        try {
            $result = $this->leaveWorkflow()->create(
                $companyId,
                $employeeId,
                $data,
                $bypassWindow,
            );
        } catch (LeaveWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        }

        $id = $result['id'];
        $days = $result['days'];
        $employee = $result['employee'];
        $leaveType = $result['leave_type'];
        try {
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'LEAVE',
                'entity_table' => 'leaves',
                'entity_id' => $id,
                'employee_id' => $employeeId,
                'meta' => ['days' => $days, 'leave_type_id' => (int) $leaveType['id']],
            ]);
        } catch (\Throwable $exception) {
            log_message('warning', 'ApprovalEngine.start failed: ' . $exception->getMessage());
            $approval = null;
        }

        if (! $approval || empty($approval['next_approvers'])) {
            foreach ($this->leaveRepository()->fallbackApprovers($companyId) as $approver) {
                Services::notification()->send(
                    (int) $approver['id'],
                    $companyId,
                    'leave.requested',
                    'Pengajuan cuti baru',
                    "{$employee['full_name']} mengajukan {$leaveType['name']} dari {$data['start_date']} sampai {$data['end_date']} ({$days} hari)",
                    ['leave_id' => $id],
                    '/leaves/' . $id,
                    true,
                );
            }
        }

        Services::audit()->forModule('LEAVE')->logCreate(
            'leave',
            $id,
            $result['leave'],
            "Pengajuan cuti {$leaveType['name']}: {$employee['full_name']} ({$data['start_date']} s/d {$data['end_date']}, {$days} hari)",
        );
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_REQUESTED,
            $companyId,
            [
                'leave_id' => $id,
                'request_number' => $result['request_number'],
                'employee' => [
                    'id' => (int) $employee['id'],
                    'employee_number' => $employee['employee_number'] ?? null,
                    'name' => $employee['full_name'] ?? null,
                ],
                'leave_type' => [
                    'id' => (int) $leaveType['id'],
                    'code' => $leaveType['code'] ?? null,
                    'name' => $leaveType['name'] ?? null,
                ],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $days,
                'is_half_day' => ! empty($data['is_half_day']),
                'status' => 'PENDING',
                'requested_at' => date('c'),
            ],
        );

        return $this->ok([
            'id' => $id,
            'request_number' => $result['request_number'],
            'days' => $days,
            'approval' => $approval,
        ], 'Leave requested', 201);
    }

    public function approve($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $note = trim((string) ($this->getJsonInput()['note'] ?? ''));
        try {
            $approvalResult = Services::approval()->approveEntity(
                'LEAVE',
                'leaves',
                (int) $id,
                $companyId,
                $this->userId(),
                $note,
            );
        } catch (LeaveWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
        }

        if (! ($approvalResult['final'] ?? false)) {
            Services::audit()->forModule('LEAVE')->logApprove(
                'approval_request',
                (int) $approvalResult['request_id'],
                $approvalResult,
                'Menyetujui satu step approval cuti',
            );
            return $this->ok($approvalResult, 'Approval step recorded');
        }

        $result = $approvalResult['domain_result'];
        $leave = $result['leave'];
        Services::audit()->forModule('LEAVE')->logApprove(
            'leave',
            (int) $id,
            $leave,
            "Menyetujui cuti #{$leave['request_number']} ({$leave['days']} hari)",
        );
        return $this->ok(['approval' => $approvalResult], 'Leave approved');
    }

    public function reject($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        try {
            $approvalResult = Services::approval()->rejectEntity(
                'LEAVE',
                'leaves',
                (int) $id,
                $companyId,
                $this->userId(),
                $reason,
            );
        } catch (LeaveWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
        }

        $result = $approvalResult['domain_result'];
        $leave = $result['leave'];
        Services::audit()->forModule('LEAVE')->logReject(
            'leave',
            (int) $id,
            $leave,
            $reason ?: '(tanpa alasan)',
        );
        return $this->ok(['approval' => $approvalResult], 'Leave rejected');
    }

    /**
     * POST /api/v1/leaves/(:num)/cancel
     *
     * Karyawan self-cancel cuti sebelum/saat mulai. Aturan (Sprint 17+):
     *   - Pemilik cuti = pemanggil (atau super_admin/HR dengan
     *     `leave.approve` boleh cancel atas nama)
     *   - Status awal harus PENDING atau APPROVED
     *   - Kalau APPROVED dan start_date sudah ≥ 2 hari kerja lampau, tolak —
     *     karyawan tidak bisa cancel cuti yang sudah lebih dari 2 hari
     *     berjalan; minta hubungi HR untuk koreksi attendance
     *   - Wajib alasan minimal 5 karakter
     *
     * Side effects saat cancel APPROVED:
     *   - Restore saldo cuti (used -= days, remaining += days)
     *   - Hapus mark `attendances.status = LEAVE` untuk hari yang BELUM
     *     terjadi (≥ today). Hari yang sudah lewat dipertahankan supaya
     *     audit attendance historis tidak rusak — HR yang putus apakah
     *     mau koreksi attendance lewat AttendanceChangeRequest.
     */
    public function cancel($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $leave = $this->leaveRepository()->leave((int) $id, $companyId);
        if (! $leave) return $this->fail('Not found', 404);

        $isOwner = (int) $leave['employee_id'] === $this->tenant()->getEmployeeId();
        $canActAsHr = $this->tenant()->isSuperAdmin()
            || $this->tenant()->hasPermission('leave.approve');
        $reason = trim((string) ($this->getJsonInput()['reason'] ?? ''));
        try {
            $result = $this->leaveWorkflow()->cancel(
                (int) $id,
                $companyId,
                $this->userId(),
                $isOwner,
                $canActAsHr,
                $reason,
            );
        } catch (LeaveWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        }

        $employee = $result['employee'];
        $leaveType = $result['leave_type'];
        $previousStatus = $result['previous_status'];
        if (! $isOwner && $employee && ! empty($employee['user_id'])) {
            Services::notification()->send(
                (int) $employee['user_id'],
                $companyId,
                'leave.canceled',
                'Cuti dibatalkan',
                "Cuti #{$leave['request_number']} dibatalkan oleh HR. Alasan: {$reason}",
                ['leave_id' => (int) $id],
                '/leaves/' . (int) $id,
                true,
            );
        }
        if ($isOwner && $previousStatus === 'APPROVED' && ! empty($leave['approved_by'])) {
            $employeeName = $employee['full_name'] ?? 'Karyawan';
            Services::notification()->send(
                (int) $leave['approved_by'],
                $companyId,
                'leave.canceled',
                'Cuti yang Anda setujui dibatalkan',
                "{$employeeName} membatalkan cuti #{$leave['request_number']}. Alasan: {$reason}",
                ['leave_id' => (int) $id],
                '/leaves/' . (int) $id,
                true,
            );
        }

        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::LEAVE_CANCELED,
            $companyId,
            [
                'leave_id' => (int) $id,
                'request_number' => $leave['request_number'] ?? null,
                'previous_status' => $previousStatus,
                'employee' => $employee ? [
                    'id' => (int) $employee['id'],
                    'employee_number' => $employee['employee_number'] ?? null,
                    'name' => $employee['full_name'] ?? null,
                ] : null,
                'leave_type' => $leaveType ? ($leaveType['code'] ?? $leaveType['name'] ?? null) : null,
                'start_date' => $leave['start_date'],
                'end_date' => $leave['end_date'],
                'days' => (float) $leave['days'],
                'reason' => $reason,
                'cancelled_by' => $this->userId(),
                'cancelled_by_hr' => $canActAsHr && ! $isOwner,
                'cancelled_at' => $result['cancelled_at'],
            ],
        );
        Services::audit()->forModule('LEAVE')->log(
            action: 'leave.cancel',
            entity: 'leave',
            entityId: (int) $id,
            before: ['status' => $previousStatus],
            after: ['status' => 'CANCELLED', 'reason' => $reason],
            description: ($isOwner ? 'Karyawan' : 'HR')
                . " membatalkan cuti #{$leave['request_number']} ({$leave['days']} hari): {$reason}",
            severity: $previousStatus === 'APPROVED' ? 'WARNING' : 'NOTICE',
        );
        return $this->ok(null, 'Cuti dibatalkan');
    }

    /**
     * Saldo cuti karyawan untuk satu tahun, dengan info prorate &
     * carry-over. Auto-create entry yg hilang via LeaveBalanceService.
     *
     * GET /api/v1/leave-balances/(:num)?year=YYYY
     */
    public function balance($id = null)
    {
        $id   = (int) $id;
        $year = (int) ($this->request->getGet('year') ?? date('Y'));

        // Authorization: karyawan boleh lihat saldonya sendiri; HR/manager
        // butuh permission `leave_balance.view` atau `leave.view`.
        $tenant = $this->tenant();
        $isOwn  = (int) $tenant->getEmployeeId() === $id;
        if (! $isOwn
            && ! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('leave_balance.view')
            && ! $tenant->hasPermission('leave.view')
        ) {
            return $this->fail('Forbidden', 403);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $summary = Services::leaveBalance()->summary($id, $year, $companyId);
        return $this->ok([
            'employee_id' => $id,
            'year'        => $year,
            'balances'    => $summary,
            'today'       => date('Y-m-d'),
        ]);
    }

    /**
     * Kalender cuti tim — siapa cuti di rentang tanggal (membantu manager
     * menghindari approve cuti yang bentrok).
     *
     * GET /api/v1/leaves/team-calendar?branch_id=&department_id=&start_date=&end_date=
     *
     * Default rentang: 30 hari ke depan dari hari ini.
     */
    public function teamCalendar()
    {
        $tenant = $this->tenant();
        $start = (string) ($this->request->getGet('start_date') ?? date('Y-m-d'));
        $end   = (string) ($this->request->getGet('end_date')   ?? date('Y-m-d', strtotime('+30 days')));
        if (strtotime($start) === false || strtotime($end) === false) {
            return $this->fail('Format tanggal tidak valid', 422);
        }
        if (strtotime($end) < strtotime($start)) {
            return $this->fail('end_date harus >= start_date', 422);
        }
        if ((strtotime($end) - strtotime($start)) / 86400 > 180) {
            return $this->fail('Rentang maksimal 180 hari', 422);
        }

        $branchId = (int) ($this->request->getGet('branch_id') ?? 0);
        $deptId   = (int) ($this->request->getGet('department_id') ?? 0);

        // Branch isolation: HR cabang dipaksa ke cabangnya
        if (! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('attendance.view_all_branches')
            && $branchId > 0
            && $branchId !== $tenant->getBranchId()
        ) {
            return $this->fail('Akses lintas cabang tidak diizinkan', 403, 'CROSS_BRANCH_DENIED');
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $canViewAllBranches = $tenant->isSuperAdmin()
            || $tenant->hasPermission('attendance.view_all_branches');
        $effectiveBranch = $canViewAllBranches
            ? ($branchId > 0 ? $branchId : null)
            : $tenant->getBranchId();
        $rows = $this->leaveRepository()->teamCalendar(
            $companyId,
            $start,
            $end,
            $effectiveBranch,
            $deptId > 0 ? $deptId : null,
        );

        return $this->ok([
            'start_date' => $start,
            'end_date'   => $end,
            'leaves'     => $rows,
            'count'      => count($rows),
        ]);
    }

    private function leaveRepository(): LeaveRepository
    {
        return new LeaveRepository();
    }

    private function leavePolicy(): LeavePolicyService
    {
        return new LeavePolicyService();
    }

    private function leaveWorkflow(): LeaveWorkflowService
    {
        return new LeaveWorkflowService();
    }
}
