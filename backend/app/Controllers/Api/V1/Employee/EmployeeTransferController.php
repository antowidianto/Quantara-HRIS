<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use Config\Database;
use Config\Services;

/**
 * Employee Transfer (Mutasi) — REST endpoints.
 *
 * Endpoint:
 *   GET    /api/v1/employee-transfers          — list (filter status, type, employee, date range)
 *   GET    /api/v1/employee-transfers/(:num)   — detail
 *   POST   /api/v1/employee-transfers          — create draft (atau langsung submit kalau body.submit=1)
 *   PUT    /api/v1/employee-transfers/(:num)   — update draft (hanya status DRAFT)
 *   DELETE /api/v1/employee-transfers/(:num)   — soft delete draft
 *   POST   /api/v1/employee-transfers/(:num)/submit
 *   POST   /api/v1/employee-transfers/(:num)/approve
 *   POST   /api/v1/employee-transfers/(:num)/reject
 *   POST   /api/v1/employee-transfers/(:num)/cancel
 *   POST   /api/v1/employee-transfers/(:num)/implement
 *   POST   /api/v1/employee-transfers/(:num)/consent
 *   GET    /api/v1/employee-transfers/options  — daftar enum (transfer_type, reason)
 *
 * Permission:
 *   - employee_transfer.view        — list & detail
 *   - employee_transfer.create      — create draft + submit
 *   - employee_transfer.approve     — approve / reject
 *   - employee_transfer.implement   — apply ke records (instan)
 */
class EmployeeTransferController extends ApiController
{
    public function index()
    {
        if (! $this->tenant()->hasPermission('employee_transfer.view')) {
            return $this->fail('Forbidden', 403);
        }

        return $this->buildList(false);
    }

    /**
     * GET /api/v1/employee-transfers/my
     *
     * Endpoint khusus karyawan untuk melihat mutasi yang menyangkut dirinya
     * (sebagai subjek). Tidak perlu permission `employee_transfer.view` —
     * semua karyawan otomatis berhak lihat mutasi atas nama mereka sendiri.
     * Counterpart "/my" untuk modul lain (leaves/my, payslips/my, dst.).
     */
    public function my()
    {
        if ($this->tenant()->getEmployeeId() <= 0) {
            return $this->fail('Akun Anda tidak terhubung ke record karyawan', 422, 'NO_EMPLOYEE_LINK');
        }
        return $this->buildList(true);
    }

    /**
     * Internal helper: build paginated list dengan filter standar. Kalau
     * $myOnly=true, paksa filter ke employee_id = current user's employee.
     */
    private function buildList(bool $myOnly)
    {
        $db = Database::connect();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status  = (string) ($this->request->getGet('status') ?? '');
        $type    = (string) ($this->request->getGet('transfer_type') ?? '');
        $empId   = $myOnly
            ? $this->tenant()->getEmployeeId()
            : (int) ($this->request->getGet('employee_id') ?? 0);
        $from    = (string) ($this->request->getGet('effective_from') ?? '');
        $to      = (string) ($this->request->getGet('effective_to') ?? '');
        $q       = trim((string) ($this->request->getGet('q') ?? ''));

        $b = $db->table('employee_transfers t')
            ->select('t.*, e.full_name AS employee_name, e.employee_number, '
                . 'creator.name AS creator_name, '
                . 'approver.name AS approver_name')
            ->join('employees e',  'e.id = t.employee_id', 'left')
            ->join('users creator',   'creator.id = t.created_by',       'left')
            ->join('users approver',  'approver.id = t.approver_user_id','left')
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 't.company_id');

        if ($status) $b->where('t.status', $status);
        if ($type)   $b->where('t.transfer_type', $type);
        if ($empId)  $b->where('t.employee_id', $empId);
        if ($from)   $b->where('t.effective_date >=', $from);
        if ($to)     $b->where('t.effective_date <=', $to);
        if ($q !== '') {
            $b->groupStart()
                ->like('t.transfer_number', $q)
                ->orLike('e.full_name', $q)
                ->orLike('e.employee_number', $q)
            ->groupEnd();
        }

        $total = (clone $b)->countAllResults(false);
        $rows = $b->orderBy('t.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);

        // Karyawan ybs (subjek mutasi) selalu boleh lihat detail mutasinya
        // sendiri walau tanpa permission `employee_transfer.view`. HR/manager
        // tetap perlu permission untuk lihat mutasi karyawan lain.
        $isOwner = $this->tenant()->getEmployeeId() === (int) $row['employee_id'];
        if (! $isOwner && ! $this->tenant()->hasPermission('employee_transfer.view')) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->tenant()->hasPermission('employee_transfer.create')) {
            return $this->fail('Forbidden', 403);
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        try {
            $row = Services::employeeTransfer()->create($companyId, $this->userId(), $data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            return $this->fail('Gagal membuat mutasi: ' . $e->getMessage(), 500);
        }
        return $this->ok($row, 'Mutasi dibuat', 201);
    }

    public function update($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.create')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya draft yang bisa diupdate', 422, 'INVALID_STATE');
        }
        $data = $this->getJsonInput();
        $allowed = ['transfer_type', 'reason', 'reason_text', 'effective_date',
            'new_branch_id', 'new_department_id', 'new_position_id', 'new_work_location_id',
            'requires_consent'];
        $update = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }
        if (empty($update)) return $this->fail('Tidak ada field yang diubah', 422);

        $update['updated_at'] = date('Y-m-d H:i:s');
        $db = Database::connect();
        $db->table('employee_transfers')->where('id', $row['id'])->update($update);

        return $this->ok($this->loadRow((int) $row['id']), 'Draft mutasi diperbarui');
    }

    public function delete($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.create')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya draft yang bisa dihapus. Untuk yang sudah submit gunakan cancel.', 422, 'INVALID_STATE');
        }
        Database::connect()->table('employee_transfers')
            ->where('id', $row['id'])
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Draft mutasi dihapus');
    }

    public function submit($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.create')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        try {
            $r = Services::employeeTransfer()->submit((int) $row['id'], $this->userId());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($r, 'Mutasi disubmit ke HR untuk approval');
    }

    public function approve($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.approve')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        $note = (string) ($this->getJsonInput()['note'] ?? '');
        try {
            $r = Services::employeeTransfer()->approve((int) $row['id'], $this->userId(), $note ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($r, 'Mutasi di-approve');
    }

    public function reject($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.approve')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        try {
            $r = Services::employeeTransfer()->reject((int) $row['id'], $this->userId(), $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($r, 'Mutasi di-reject');
    }

    public function cancel($id = null)
    {
        // Boleh: creator (employee_transfer.create) atau approver / HR
        $canCreate  = $this->tenant()->hasPermission('employee_transfer.create');
        $canApprove = $this->tenant()->hasPermission('employee_transfer.approve');
        if (! $canCreate && ! $canApprove) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        try {
            $r = Services::employeeTransfer()->cancel((int) $row['id'], $this->userId(), $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($r, 'Mutasi dibatalkan');
    }

    public function implement($id = null)
    {
        if (! $this->tenant()->hasPermission('employee_transfer.implement')) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);
        $note = (string) ($this->getJsonInput()['note'] ?? '');
        try {
            $r = Services::employeeTransfer()->implement((int) $row['id'], $this->userId(), $note ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Gagal apply mutasi: ' . $e->getMessage(), 500);
        }
        return $this->ok($r, 'Mutasi di-apply ke records karyawan');
    }

    /**
     * Tandai consent oleh karyawan ybs (atau HR yang upload file
     * surat persetujuan/penolakan).
     */
    public function consent($id = null)
    {
        $row = $this->loadRow((int) $id);
        if (! $row) return $this->fail('Mutasi tidak ditemukan', 404);

        // Boleh: karyawan ybs (via user_id linked employee) atau HR (manage)
        $isOwner = false;
        $empId = (int) $row['employee_id'];
        if ($this->tenant()->getEmployeeId() === $empId) $isOwner = true;
        $canManage = $this->tenant()->hasPermission('employee_transfer.approve')
            || $this->tenant()->hasPermission('employee_transfer.create');
        if (! $isOwner && ! $canManage) {
            return $this->fail('Forbidden', 403);
        }

        $body = $this->getJsonInput();
        $decision   = (string) ($body['decision'] ?? '');
        $signedPath = $body['signed_path'] ?? null;
        try {
            $r = Services::employeeTransfer()->markConsent((int) $row['id'], $decision, $signedPath);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($r, "Consent karyawan dicatat: $decision");
    }

    public function options()
    {
        return $this->ok([
            'transfer_types' => [
                ['code' => 'BRANCH',        'label' => 'Pindah Cabang'],
                ['code' => 'DEPARTMENT',    'label' => 'Pindah Department'],
                ['code' => 'POSITION',      'label' => 'Pindah Posisi (Lateral)'],
                ['code' => 'WORK_LOCATION', 'label' => 'Pindah Lokasi Kerja'],
                ['code' => 'COMBINED',      'label' => 'Kombinasi'],
            ],
            'reasons' => [
                ['code' => 'OPERATIONAL',      'label' => 'Kebutuhan Operasional'],
                ['code' => 'EMPLOYEE_REQUEST', 'label' => 'Permintaan Karyawan'],
                ['code' => 'ROTATION',         'label' => 'Rotasi Rutin'],
                ['code' => 'REORGANIZATION',   'label' => 'Restrukturisasi'],
                ['code' => 'PROMOTION_PREP',   'label' => 'Persiapan Promosi'],
                ['code' => 'PERSONAL',         'label' => 'Alasan Pribadi'],
                ['code' => 'OTHER',            'label' => 'Lainnya'],
            ],
            'statuses' => [
                ['code' => 'DRAFT',       'label' => 'Draft'],
                ['code' => 'SUBMITTED',   'label' => 'Menunggu Approval'],
                ['code' => 'APPROVED',    'label' => 'Disetujui'],
                ['code' => 'IMPLEMENTED', 'label' => 'Diberlakukan'],
                ['code' => 'REJECTED',    'label' => 'Ditolak'],
                ['code' => 'CANCELLED',   'label' => 'Dibatalkan'],
            ],
        ]);
    }

    /**
     * Load row + tenant scope check + enrich dengan nama referensi tujuan.
     */
    private function loadRow(int $id): ?array
    {
        if ($id <= 0) return null;
        $db = Database::connect();
        $b = $db->table('employee_transfers t')
            ->select('t.*, e.full_name AS employee_name, e.employee_number, '
                . 'newB.name AS new_branch_name, newD.name AS new_department_name, '
                . 'newP.name AS new_position_name, newWL.name AS new_work_location_name, '
                . 'creator.name AS creator_name, approver.name AS approver_name')
            ->join('employees e',           'e.id = t.employee_id',           'left')
            ->join('branches newB',         'newB.id = t.new_branch_id',      'left')
            ->join('departments newD',      'newD.id = t.new_department_id',  'left')
            ->join('positions newP',        'newP.id = t.new_position_id',    'left')
            ->join('work_locations newWL',  'newWL.id = t.new_work_location_id','left')
            ->join('users creator',         'creator.id = t.created_by',       'left')
            ->join('users approver',        'approver.id = t.approver_user_id','left')
            ->where('t.id', $id)
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 't.company_id');
        $row = $b->get()->getRowArray();
        return $row ?: null;
    }
}