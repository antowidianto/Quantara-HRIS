<?php

namespace App\Controllers\Api\V1\Training;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * TrainingController — REST API untuk modul Training & Sertifikasi.
 *
 * Endpoint:
 *   Trainings:
 *     GET    /trainings                              List dgn filter
 *     POST   /trainings                              Create
 *     GET    /trainings/(:num)                       Detail + ringkasan registrations
 *     PUT    /trainings/(:num)                       Update
 *     DELETE /trainings/(:num)                       Soft-delete
 *     POST   /trainings/(:num)/publish               DRAFT → PUBLISHED
 *
 *   Registrations:
 *     GET    /trainings/(:num)/registrations         List peserta
 *     POST   /trainings/(:num)/register              Karyawan daftar
 *     POST   /training-registrations/(:num)/approve  HR approve
 *     POST   /training-registrations/(:num)/reject   HR reject
 *     POST   /training-registrations/(:num)/complete COMPLETED + auto-issue cert
 *     POST   /training-registrations/(:num)/cancel
 *
 *   Certificates:
 *     GET    /certificates                List sertifikat
 *     POST   /certificates                Upload manual (eksternal)
 *     GET    /certificates/(:num)
 *     PUT    /certificates/(:num)
 *     DELETE /certificates/(:num)
 *     GET    /certificates/expiring       List yg akan expire dalam X hari
 *
 *   Budgets:
 *     GET    /training-budgets            List per dept per tahun
 *     POST   /training-budgets            Set alokasi
 *     PUT    /training-budgets/(:num)     Update alokasi
 *
 *   Stats:
 *     GET    /trainings-stats             Dashboard counter
 */
class TrainingController extends ApiController
{
    private function db() { return \Config\Database::connect(); }
    private function svc() { return Services::training(); }

    private function can(string $slug): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission($slug);
    }

    private function getMyEmployeeId(): ?int
    {
        $eid = (int) $this->tenant()->getEmployeeId();
        if ($eid > 0) return $eid;
        $userId = (int) $this->tenant()->getUserId();
        if (! $userId) return null;
        $emp = $this->db()->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
        return $emp ? (int) $emp['id'] : null;
    }

    // ─── Trainings ──────────────────────────────────────────────────

    public function index()
    {
        if (! $this->can('training.view')) return $this->fail('Forbidden', 403);
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $q       = trim((string) $this->request->getGet('q'));
        $status  = trim((string) $this->request->getGet('status'));
        $cat     = trim((string) $this->request->getGet('category'));
        $employeeId = $this->getMyEmployeeId();

        $b = $this->db()->table('trainings t')
            ->select('t.*, d.name AS department_name, '
                . '(SELECT COUNT(*) FROM training_registrations r WHERE r.training_id = t.id AND r.status NOT IN ("CANCELLED","REJECTED")) AS participant_count')
            ->join('departments d', 'd.id = t.department_id', 'left')
            ->where('t.deleted_at IS NULL')
            ->where('t.company_id', $this->tenant()->getCompanyId());
        $this->tenant()->applyScope($b, 't.company_id');

        if ($q !== '') $b->groupStart()->like('t.title', $q)->orLike('t.code', $q)->orLike('t.provider', $q)->groupEnd();
        if ($status !== '') $b->where('t.status', $status);
        if ($cat !== '') $b->where('t.category', $cat);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('t.start_date', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function create()
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'title'      => 'required|min_length[3]|max_length[200]',
            'category'   => 'required',
            'start_date' => 'required|valid_date',
            'end_date'   => 'required|valid_date',
        ]);
        if ($data === null) return $this->response;

        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            return $this->fail('end_date harus >= start_date', 422);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'code'       => $this->svc()->nextTrainingCode($companyId),
            'title'      => $data['title'],
            'description'=> $data['description'] ?? null,
            'category'   => strtoupper($data['category']),
            'training_type' => strtoupper($data['training_type'] ?? 'INTERNAL'),
            'provider'   => $data['provider'] ?? null,
            'instructor' => $data['instructor'] ?? null,
            'location'   => $data['location'] ?? null,
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
            'duration_hours' => (float) ($data['duration_hours'] ?? 0),
            'max_participants' => (int) ($data['max_participants'] ?? 0),
            'min_participants' => (int) ($data['min_participants'] ?? 0),
            'cost_per_participant' => (float) ($data['cost_per_participant'] ?? 0),
            'department_id' => $data['department_id'] ?? null,
            'issues_certificate' => ! empty($data['issues_certificate']) ? 1 : 0,
            'certificate_validity_months' => $data['certificate_validity_months'] ?? null,
            'passing_score' => $data['passing_score'] ?? null,
            'status' => 'DRAFT',
            'notes' => $data['notes'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db()->table('trainings')->insert($insert);
        $id = (int) $this->db()->insertID();

        try {
            Services::audit()->forModule('TRAINING')->log(
                action: 'training.create', entity: 'trainings', entityId: $id, after: $insert,
                description: "Training created: {$insert['code']} — {$insert['title']}",
            );
        } catch (\Throwable $e) {}

        return $this->ok(['id' => $id, 'code' => $insert['code']], 'OK', 201);
    }

    public function show($id = null)
    {
        if (! $this->can('training.view')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->loadTrainingOrFail($id);
        if (! is_array($row)) return $this->response;

        // Add registrations summary
        $regs = $this->db()->table('training_registrations r')
            ->select('r.*, e.full_name AS employee_name, e.employee_number, d.name AS department_name')
            ->join('employees e', 'e.id = r.employee_id')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('r.training_id', $id)
            ->orderBy('r.registered_at', 'ASC')
            ->get()->getResultArray();
        $row['registrations'] = $regs;
        return $this->ok($row);
    }

    public function update($id = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->loadTrainingOrFail($id);
        if (! is_array($row)) return $this->response;

        $data = $this->getJsonInput();
        $update = [];
        foreach (['title','description','category','training_type','provider','instructor','location',
                 'start_date','end_date','duration_hours','max_participants','min_participants',
                 'cost_per_participant','department_id','issues_certificate','certificate_validity_months',
                 'passing_score','status','notes'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('trainings')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('TRAINING')->log(
                action: 'training.update', entity: 'trainings', entityId: $id,
                before: $row, after: array_merge($row, $update),
                description: "Update training {$row['code']}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    public function delete($id = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->loadTrainingOrFail($id);
        if (! is_array($row)) return $this->response;
        $this->db()->table('trainings')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => $id]);
    }

    public function publish($id = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->loadTrainingOrFail($id);
        if (! is_array($row)) return $this->response;
        if ($row['status'] !== 'DRAFT') return $this->fail('Hanya status DRAFT yg bisa di-publish', 422);

        $this->db()->table('trainings')->where('id', $id)->update([
            'status' => 'PUBLISHED', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            Services::audit()->forModule('TRAINING')->log(
                action: 'training.publish', entity: 'trainings', entityId: $id,
                description: "Training {$row['code']} dipublish",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    // ─── Registrations ──────────────────────────────────────────────

    public function listRegistrations($trainingId = null)
    {
        if (! $this->can('training.view')) return $this->fail('Forbidden', 403);
        $trainingId = (int) $trainingId;
        $rows = $this->db()->table('training_registrations r')
            ->select('r.*, e.full_name AS employee_name, e.employee_number, d.name AS department_name')
            ->join('employees e', 'e.id = r.employee_id')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('r.training_id', $trainingId)
            ->orderBy('r.registered_at', 'DESC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function register($trainingId = null)
    {
        if (! $this->can('training.register')) return $this->fail('Forbidden', 403);
        $trainingId = (int) $trainingId;
        $companyId = (int) $this->tenant()->getCompanyId();
        $userId = (int) $this->tenant()->getUserId();
        $myEmpId = $this->getMyEmployeeId();
        $data = $this->getJsonInput();
        // HR bisa daftarkan karyawan lain
        $employeeId = (int) ($data['employee_id'] ?? $myEmpId);
        if (! $employeeId) return $this->fail('Employee tidak ditemukan', 400);
        try {
            $result = $this->svc()->register(
                $companyId, $trainingId, $employeeId, $userId,
                isset($data['notes']) ? (string) $data['notes'] : null
            );
            try {
                Services::audit()->forModule('TRAINING')->log(
                    action: 'training.register', entity: 'training_registrations', entityId: (int) $result['id'],
                    after: ['status' => 'REGISTERED'],
                    description: "Register karyawan #{$employeeId} ke training #{$trainingId}",
                );
            } catch (\Throwable $e) {}
            return $this->ok($result, 'OK', 201);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function approveRegistration($regId = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $regId = (int) $regId;
        $userId = (int) $this->tenant()->getUserId();
        $companyId = (int) $this->tenant()->getCompanyId();
        try {
            $result = Services::approval()->approveEntity(
                'TRAINING_REGISTRATION', 'training_registrations', $regId, $companyId, $userId,
                trim((string) ($this->getJsonInput()['note'] ?? ''))
            );
            try {
                Services::audit()->forModule('TRAINING')->log(
                    action: 'training.reg.approve', entity: 'training_registrations', entityId: $regId,
                    description: "Approve registration #{$regId}",
                );
            } catch (\Throwable $e) {}
            return $this->ok($result);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function rejectRegistration($regId = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $regId = (int) $regId;
        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) return $this->fail('Alasan min 5 karakter', 400);
        try {
            return $this->ok(Services::approval()->rejectEntity(
                'TRAINING_REGISTRATION', 'training_registrations', $regId,
                (int) $this->tenant()->getCompanyId(), (int) $this->tenant()->getUserId(), $reason
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function completeRegistration($regId = null)
    {
        if (! $this->can('training.manage')) return $this->fail('Forbidden', 403);
        $regId = (int) $regId;
        $data = $this->getJsonInput();
        try {
            $result = $this->svc()->completeRegistration($regId, (int) $this->tenant()->getCompanyId(), [
                'final_score' => $data['final_score'] ?? null,
                'attendance_percent' => $data['attendance_percent'] ?? null,
            ]);
            try {
                Services::audit()->forModule('TRAINING')->log(
                    action: 'training.reg.complete', entity: 'training_registrations', entityId: $regId,
                    description: "Complete registration #{$regId} → {$result['status']}",
                );
            } catch (\Throwable $e) {}
            return $this->ok($result);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function cancelRegistration($regId = null)
    {
        $regId = (int) $regId;
        $companyId = (int) $this->tenant()->getCompanyId();
        $reg = $this->db()->table('training_registrations')->where('id', $regId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $reg) return $this->fail('Not found', 404);

        // Karyawan bisa cancel sendiri
        $myEmpId = $this->getMyEmployeeId();
        $isOwner = $myEmpId && (int) $reg['employee_id'] === $myEmpId;
        if (! $isOwner && ! $this->can('training.manage')) return $this->fail('Forbidden', 403);

        try {
            return $this->ok($this->svc()->cancelRegistration($regId, $companyId));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── Certificates ────────────────────────────────────────────────

    public function listCertificates()
    {
        // Permission cek lewat PrivacyScope (canViewOwn untuk training =
        // include certificate juga). User tanpa cert.view tetap bisa lihat
        // sertifikatnya sendiri.
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));
        $mine = (int) ($this->request->getGet('mine') ?? 0);

        $b = $this->db()->table('certificates c')
            ->select('c.*, e.full_name AS employee_name, e.employee_number, t.title AS training_title')
            ->join('employees e', 'e.id = c.employee_id')
            ->join('trainings t', 't.id = c.training_id', 'left')
            ->where('c.deleted_at IS NULL');

        // Bug fix: sebelumnya ada hard-coded filter
        // `->where('c.employee_id', $this->getMyEmployeeId())` yang membuat
        // HR/superadmin TIDAK pernah lihat sertifikat karyawan lain meski
        // punya permission `certificate.view`/`training.view`. Hapus hard-
        // filter tersebut — privacy scope di bawah yang handle: kalau user
        // tidak punya permission HR, applyListScope() akan otomatis paksa
        // employeeColumn = current user's employee_id.
        //
        // Privacy scope (lihat docs/DATA_PRIVACY.md): tanpa permission HR
        // (`training.view`/`manage`/`certificate.view`), karyawan hanya boleh
        // lihat sertifikatnya sendiri. Sebelumnya `?employee_id=X` mentah
        // → bocor.
        $effective = $this->privacy()->applyListScope(
            $b, module: 'training',
            companyColumn: 'c.company_id',
            employeeColumn: 'c.employee_id',
            requestedEmployeeId: $mine
                ? $this->getMyEmployeeId()
                : ($requestedEmpId ?: null),
        );

        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($status) $b->where('c.status', $status);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('c.expires_date', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function createCertificate()
    {
        if (! $this->can('certificate.manage')) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'employee_id' => 'required|integer',
            'title'       => 'required|min_length[3]',
            'issued_date' => 'required|valid_date',
        ]);
        if ($data === null) return $this->response;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => $companyId,
            'employee_id'        => (int) $data['employee_id'],
            'training_id'        => $data['training_id'] ?? null,
            'certificate_number' => $data['certificate_number'] ?: $this->svc()->nextCertNumber($companyId),
            'title'              => $data['title'],
            'category'           => strtoupper($data['category'] ?? 'PROFESSIONAL'),
            'issuer'             => $data['issuer'] ?? null,
            'issued_date'        => $data['issued_date'],
            'expires_date'       => $data['expires_date'] ?? null,
            'score'              => $data['score'] ?? null,
            'file_path'          => $data['file_path'] ?? null,
            'status'             => 'ACTIVE',
            'notes'              => $data['notes'] ?? null,
            'created_by'         => (int) $this->tenant()->getUserId(),
            'created_at'         => $now, 'updated_at' => $now,
        ];
        $this->db()->table('certificates')->insert($insert);
        $id = (int) $this->db()->insertID();
        return $this->ok(['id' => $id], 'OK', 201);
    }

    public function showCertificate($id = null)
    {
        if (! $this->can('certificate.view')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->db()->table('certificates')->where('id', $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function updateCertificate($id = null)
    {
        if (! $this->can('certificate.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $update = [];
        foreach (['title','category','issuer','issued_date','expires_date','score','file_path','status','notes'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('certificates')->where('id', $id)->update($update);
        return $this->ok(['id' => $id]);
    }

    public function deleteCertificate($id = null)
    {
        if (! $this->can('certificate.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $this->db()->table('certificates')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => $id]);
    }

    public function expiringCertificates()
    {
        if (! $this->can('certificate.view')) return $this->fail('Forbidden', 403);
        $days = max(1, min(365, (int) ($this->request->getGet('days') ?? 30)));
        $companyId = $this->companyId();
        $rows = $this->svc()->findExpiringCertificates($days, $companyId ?: null);
        return $this->ok($rows);
    }

    // ─── Budgets ────────────────────────────────────────────────────

    public function listBudgets()
    {
        if (! $this->can('training_budget.manage') && ! $this->can('training.view')) {
            return $this->fail('Forbidden', 403);
        }
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        $b = $this->db()->table('training_budgets b')
            ->select('b.*, d.name AS department_name')
            ->join('departments d', 'd.id = b.department_id', 'left')
            ->where('b.year', $year);
        $this->tenant()->applyScope($b, 'b.company_id');
        return $this->ok($b->orderBy('b.department_id', 'ASC')->get()->getResultArray());
    }

    public function createBudget()
    {
        if (! $this->can('training_budget.manage')) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'year' => 'required|integer',
            'allocated_amount' => 'required',
        ]);
        if ($data === null) return $this->response;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;

        $deptId = ! empty($data['department_id']) ? (int) $data['department_id'] : null;

        // Cek existing
        $b = $this->db()->table('training_budgets')
            ->where('company_id', $companyId)->where('year', (int) $data['year']);
        if ($deptId === null) $b->where('department_id IS NULL');
        else                  $b->where('department_id', $deptId);
        $exists = $b->get()->getRowArray();

        $now = date('Y-m-d H:i:s');
        if ($exists) {
            $this->db()->table('training_budgets')->where('id', $exists['id'])->update([
                'allocated_amount' => (float) $data['allocated_amount'],
                'notes' => $data['notes'] ?? $exists['notes'],
                'updated_at' => $now,
            ]);
            return $this->ok(['id' => (int) $exists['id'], 'updated' => true]);
        }
        $this->db()->table('training_budgets')->insert([
            'company_id' => $companyId,
            'department_id' => $deptId,
            'year' => (int) $data['year'],
            'allocated_amount' => (float) $data['allocated_amount'],
            'used_amount' => 0,
            'reserved_amount' => 0,
            'notes' => $data['notes'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->ok(['id' => (int) $this->db()->insertID()], 'OK', 201);
    }

    public function updateBudget($id = null)
    {
        if (! $this->can('training_budget.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $update = [];
        foreach (['allocated_amount','notes'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('training_budgets')->where('id', $id)->update($update);
        return $this->ok(['id' => $id]);
    }

    // ─── Stats ──────────────────────────────────────────────────────

    public function stats()
    {
        if (! $this->can('training.view')) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        return $this->ok($this->svc()->stats($companyId, $year));
    }

    private function loadTrainingOrFail(int $id)
    {
        $row = $this->db()->table('trainings')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Training tidak ditemukan', 404);
            return false;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Akses ditolak', 403);
            return false;
        }
        return $row;
    }
}
