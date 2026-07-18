<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * WfhRequestController (Sprint 8 / H1.5)
 *
 * Endpoint untuk pengajuan WFH (Work From Home).
 *
 * Permissions:
 *   - employee submit & cancel pengajuan WFH-nya sendiri
 *   - approver / HR approve & reject (cek lewat supervisor relationship)
 *
 * Routes:
 *   GET    /wfh-requests                — list (auto scope: own / inbox / all)
 *   POST   /wfh-requests                — submit { work_date, reason }
 *   GET    /wfh-requests/(:num)         — detail
 *   POST   /wfh-requests/(:num)/approve — approve { note? }
 *   POST   /wfh-requests/(:num)/reject  — reject  { note }
 *   POST   /wfh-requests/(:num)/cancel  — cancel by requester (PENDING only)
 */
class WfhRequestController extends ApiController
{
    private function db()
    {
        return \Config\Database::connect();
    }

    private function service(): \App\Libraries\Attendance\WfhRequestService
    {
        return new \App\Libraries\Attendance\WfhRequestService();
    }

    private function getOwnEmployeeId(): ?int
    {
        $empId = (int) $this->tenant()->getEmployeeId();
        if ($empId > 0) return $empId;
        $userId = (int) $this->tenant()->getUserId();
        if (! $userId) return null;
        $emp = $this->db()->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
        return $emp ? (int) $emp['id'] : null;
    }

    /**
     * GET /wfh-requests
     * Query: scope=mine|inbox|processed|all (default: mine)
     *        status, month=YYYY-MM, employee_id (HR only)
     */
    public function index()
    {
        $scope = (string) ($this->request->getGet('scope') ?? 'mine');
        $status = (string) ($this->request->getGet('status') ?? '');
        $month = (string) ($this->request->getGet('month') ?? '');
        $employeeFilter = (int) ($this->request->getGet('employee_id') ?? 0);
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        $b = $this->db()->table('wfh_requests w')
            ->select('w.*, e.full_name AS employee_name, e.employee_number,'
                . ' au.name AS approver_user_name')
            ->join('employees e', 'e.id = w.employee_id', 'left')
            ->join('users au', 'au.id = w.approver_user_id', 'left');
        $this->tenant()->applyScope($b, 'w.company_id');

        $myEmpId = $this->getOwnEmployeeId();

        if ($scope === 'mine') {
            if (! $myEmpId) return $this->paginated([], 0, $page, $perPage);
            $b->where('w.employee_id', $myEmpId);
        } elseif ($scope === 'inbox') {
            // Pending request yang harus saya approve sebagai supervisor
            $b->where('w.status', 'PENDING')
              ->where('e.supervisor_id IN (SELECT id FROM employees WHERE user_id = ' . (int) $this->userId() . ')', null, false);
        } elseif ($scope === 'processed') {
            // History keputusan yang benar-benar dibuat user saat ini.
            $b->whereIn('w.status', ['APPROVED', 'REJECTED'])
              ->where('w.approver_user_id', (int) $this->userId());
        } elseif ($scope === 'all') {
            // HR view — boleh kalau punya permission attendance.view atau super admin
            if (! $this->tenant()->isSuperAdmin()
                && ! $this->tenant()->hasPermission('attendance.view')
                && ! $this->tenant()->hasPermission('hr.manage')
            ) {
                return $this->fail('Forbidden', 403);
            }
            if ($employeeFilter > 0) {
                $b->where('w.employee_id', $employeeFilter);
            }
        }

        if ($status !== '' && in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            $b->where('w.status', $status);
        }
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $b->where('w.work_date >=', $month . '-01');
            $b->where('w.work_date <', date('Y-m-d', strtotime($month . '-01 +1 month')));
        }

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('w.work_date', 'DESC')->orderBy('w.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * POST /wfh-requests
     * Body: { work_date, reason, employee_id? (HR only) }
     */
    public function create()
    {
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'work_date' => 'required|valid_date[Y-m-d]',
            'reason'    => 'required|min_length[10]',
        ]);
        if ($data === null) return $this->response;

        $myEmpId = $this->getOwnEmployeeId();
        $employeeId = $myEmpId;

        // HR boleh submit atas nama orang lain
        if (! empty($data['employee_id']) && (int) $data['employee_id'] !== $myEmpId) {
            if (! $this->tenant()->isSuperAdmin() && ! $this->tenant()->hasPermission('hr.manage')) {
                return $this->fail('Hanya HR yang boleh submit WFH atas nama orang lain', 403);
            }
            $employeeId = (int) $data['employee_id'];
        }
        if (! $employeeId) {
            return $this->fail('Employee profile tidak ditemukan untuk user ini', 400);
        }

        try {
            $res = $this->service()->submit(
                employeeId: $employeeId,
                workDate: $data['work_date'],
                reason: $data['reason'],
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($res, 'Pengajuan WFH dibuat', 201);
    }

    public function show($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! is_array($row)) return $this->response;
        return $this->ok($row);
    }

    public function approve($id = null)
    {
        return $this->decide((int) $id, 'APPROVED');
    }

    public function reject($id = null)
    {
        return $this->decide((int) $id, 'REJECTED');
    }

    private function decide(int $id, string $decision)
    {
        $row = $this->loadOrFail($id);
        if (! is_array($row)) return $this->response;

        // Authorization: hanya supervisor langsung atau HR
        $emp = $this->db()->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        $iAmSupervisor = false;
        if ($emp && ! empty($emp['supervisor_id'])) {
            $sup = $this->db()->table('employees')->where('id', (int) $emp['supervisor_id'])->get()->getRowArray();
            if ($sup && (int) ($sup['user_id'] ?? 0) === (int) $this->userId()) {
                $iAmSupervisor = true;
            }
        }
        $isHr = $this->tenant()->isSuperAdmin() || $this->tenant()->hasPermission('hr.manage');
        if (! $iAmSupervisor && ! $isHr) {
            return $this->fail('Anda bukan approver untuk pengajuan ini', 403);
        }

        $note = (string) ($this->getJsonInput()['note'] ?? '');
        try {
            $res = $this->service()->decide($id, (int) $this->userId(), $decision, $note);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($res, $decision === 'APPROVED' ? 'Disetujui' : 'Ditolak');
    }

    public function cancel($id = null)
    {
        $id = (int) $id;
        $row = $this->loadOrFail($id);
        if (! is_array($row)) return $this->response;

        $myEmpId = $this->getOwnEmployeeId();
        if ((int) $row['employee_id'] !== $myEmpId
            && ! $this->tenant()->isSuperAdmin()
            && ! $this->tenant()->hasPermission('hr.manage')
        ) {
            return $this->fail('Hanya pemohon atau HR yang boleh batalkan', 403);
        }

        try {
            $this->service()->cancel($id, (int) $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['id' => $id], 'Dibatalkan');
    }

    private function loadOrFail(int $id)
    {
        $row = $this->db()->table('wfh_requests w')
            ->select('w.*, e.full_name AS employee_name, e.employee_number, e.supervisor_id')
            ->join('employees e', 'e.id = w.employee_id', 'left')
            ->where('w.id', $id)->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Pengajuan tidak ditemukan', 404);
            return false;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Akses ditolak (multi-tenant)', 403);
            return false;
        }
        return $row;
    }
}