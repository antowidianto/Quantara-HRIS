<?php

namespace App\Controllers\Api\V1\Performance;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Performance\PerformanceGoalApprovalService;
use Config\Services;

/**
 * PerformanceController — Cycle, Goal, Check-in, Review.
 *
 * Endpoint:
 *   Cycles:
 *     GET    /performance-cycles                List
 *     POST   /performance-cycles                Create
 *     GET    /performance-cycles/(:num)         Detail + stats
 *     PUT    /performance-cycles/(:num)
 *     DELETE /performance-cycles/(:num)
 *     POST   /performance-cycles/(:num)/status  Change status (DRAFT→OPEN→GOAL_SETTING→IN_PROGRESS→REVIEW→CLOSED)
 *
 *   Goals:
 *     GET    /goals                             List dengan filter cycle/employee/status
 *     POST   /goals                             Create
 *     GET    /goals/(:num)                      Detail + check-ins history
 *     PUT    /goals/(:num)
 *     DELETE /goals/(:num)
 *     POST   /goals/(:num)/submit               Employee submit untuk approval manager
 *     POST   /goals/(:num)/check-in             Karyawan submit progress
 *     GET    /goals/weights/(:num)/(:num)       Validate cycle+employee weights
 *
 *   Reviews:
 *     GET    /performance-reviews               List dgn filter
 *     POST   /performance-reviews               Create review row
 *     GET    /performance-reviews/(:num)
 *     PUT    /performance-reviews/(:num)        Update draft
 *     POST   /performance-reviews/(:num)/submit
 *     POST   /performance-reviews/(:num)/acknowledge
 *     POST   /performance-reviews/(:num)/finalize
 *
 *   My:
 *     GET    /performance/my                    Goals + reviews milik saya
 *     GET    /performance/my-team               Reviews tim (manager)
 */
class PerformanceController extends ApiController
{
    private function db() { return \Config\Database::connect(); }
    private function svc() { return Services::performance(); }

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
        $companyId = (int) $this->tenant()->getCompanyId();
        $emp = $this->db()->table('employees')->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
        return $emp ? (int) $emp['id'] : null;
    }

    // ─── Cycles ─────────────────────────────────────────────────────

    public function listCycles()
    {
        if (! $this->can('performance.view')) return $this->fail('Forbidden', 403);
        $b = $this->db()->table('performance_cycles')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'performance_cycles.company_id');
        $status = trim((string) $this->request->getGet('status'));
        if ($status) $b->where('status', $status);
        return $this->ok($b->orderBy('start_date', 'DESC')->get()->getResultArray());
    }

    /**
     * Normalize a nullable date input. MySQL DATE/DATETIME tidak terima
     * empty string `''` — harus betul-betul `null`. Frontend HTML `<input
     * type="date">` yang dikosongkan kirim `''`, jadi kita rapikan di sini.
     *
     * Sekalian validasi format YYYY-MM-DD dan kewajaran tanggal (mis.
     * '2026-13-40' lolos strtotime tapi salah). Mengembalikan:
     *   - null  bila input null/empty/whitespace
     *   - string YYYY-MM-DD bila valid
     *   - throws \InvalidArgumentException dengan pesan ber-konteks bila
     *     input ada tapi format-nya rusak (caller convert ke 422).
     */
    private function nullableDate($value, string $fieldLabel): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        if ($v === '') return null;
        // Format wajib YYYY-MM-DD dgn checkdate untuk reject 2026-02-31 dst
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            throw new \InvalidArgumentException(
                "Format {$fieldLabel} harus YYYY-MM-DD (contoh 2026-12-31). Diterima: '{$v}'."
            );
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException(
                "Tanggal {$fieldLabel} tidak valid: '{$v}'."
            );
        }
        return $v;
    }

    public function createCycle()
    {
        if (! $this->can('performance.manage')) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'code' => 'required|max_length[50]',
            'name' => 'required|max_length[200]',
            'cycle_type' => 'required',
            'start_date' => 'required|valid_date',
            'end_date' => 'required|valid_date',
        ]);
        if ($data === null) return $this->response;

        if (strtotime($data['end_date']) <= strtotime($data['start_date'])) {
            return $this->fail('Tanggal selesai harus lebih besar dari tanggal mulai cycle.', 422);
        }

        // Normalisasi tanggal opsional: empty string → NULL, format
        // dicek sekalian. Surface error sebagai 422 dengan pesan yang
        // jelas (bukan biarkan MySQL throw "Incorrect date value: ''").
        try {
            $goalDeadline   = $this->nullableDate($data['goal_setting_deadline'] ?? null, 'Deadline Set Goal');
            $reviewStart    = $this->nullableDate($data['review_start_date']     ?? null, 'Periode Review (Mulai)');
            $reviewEnd      = $this->nullableDate($data['review_end_date']       ?? null, 'Periode Review (Selesai)');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_DATE');
        }

        // Cross-field consistency: review_end >= review_start kalau dua-duanya
        // diisi. goal_setting_deadline tidak boleh setelah end_date.
        if ($reviewStart && $reviewEnd && strtotime($reviewEnd) < strtotime($reviewStart)) {
            return $this->fail(
                'Tanggal akhir periode review tidak boleh lebih kecil dari tanggal mulai review.',
                422
            );
        }
        if ($goalDeadline && strtotime($goalDeadline) > strtotime($data['end_date'])) {
            return $this->fail(
                'Deadline penetapan goal tidak boleh setelah tanggal selesai cycle.',
                422
            );
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;

        // Cegah duplikasi `code` per company supaya error UNIQUE key
        // tidak muncul sebagai 500 generic.
        $existing = $this->db()->table('performance_cycles')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($existing > 0) {
            return $this->fail("Kode cycle '{$data['code']}' sudah dipakai.", 422, 'DUPLICATE_CODE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'code' => $data['code'],
            'name' => $data['name'],
            'cycle_type' => strtoupper($data['cycle_type']),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'goal_setting_deadline' => $goalDeadline,
            'review_start_date' => $reviewStart,
            'review_end_date' => $reviewEnd,
            'description' => $data['description'] ?? null,
            'status' => 'DRAFT',
            'created_at' => $now, 'updated_at' => $now,
        ];

        try {
            $this->db()->table('performance_cycles')->insert($insert);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // Last-resort safety net: kalau ada DB error yang lolos
            // validasi (mis. constraint baru), kasih pesan yang dipakai
            // user, bukan stack trace SQL.
            log_message('error', 'createCycle DB error: ' . $e->getMessage());
            return $this->fail(
                'Gagal menyimpan cycle. Periksa kembali data tanggal & kode cycle.',
                422,
                'DB_ERROR'
            );
        }
        $id = (int) $this->db()->insertID();

        try {
            Services::audit()->forModule('PERFORMANCE')->log(
                action: 'cycle.create', entity: 'performance_cycles', entityId: $id, after: $insert,
                description: "Performance cycle: {$data['code']}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id, 'code' => $data['code']], 'OK', 201);
    }

    public function showCycle($id = null)
    {
        if (! $this->can('performance.view')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $row = $this->db()->table('performance_cycles')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        $row['stats'] = $this->svc()->cycleStats($id);
        return $this->ok($row);
    }

    public function updateCycle($id = null)
    {
        if (! $this->can('performance.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();

        // Field tanggal opsional — empty string harus jadi NULL supaya
        // MySQL tidak reject. Field tanggal wajib (start_date/end_date)
        // tidak boleh dikosongkan saat update.
        $nullableDateFields = [
            'goal_setting_deadline' => 'Deadline Set Goal',
            'review_start_date'     => 'Periode Review (Mulai)',
            'review_end_date'       => 'Periode Review (Selesai)',
        ];
        try {
            foreach ($nullableDateFields as $field => $label) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = $this->nullableDate($data[$field], $label);
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_DATE');
        }
        // start_date/end_date wajib non-null kalau dikirim
        foreach (['start_date', 'end_date'] as $reqDate) {
            if (array_key_exists($reqDate, $data)) {
                $v = trim((string) ($data[$reqDate] ?? ''));
                if ($v === '') {
                    return $this->fail("Tanggal {$reqDate} tidak boleh dikosongkan.", 422);
                }
                $data[$reqDate] = $v;
            }
        }

        $update = [];
        foreach (['code','name','cycle_type','start_date','end_date','goal_setting_deadline',
                 'review_start_date','review_end_date','description','status'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->db()->table('performance_cycles')->where('id', $id)->update($update);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            log_message('error', 'updateCycle DB error: ' . $e->getMessage());
            return $this->fail(
                'Gagal mengupdate cycle. Periksa kembali data tanggal.',
                422,
                'DB_ERROR'
            );
        }
        return $this->ok(['id' => $id]);
    }

    public function deleteCycle($id = null)
    {
        if (! $this->can('performance.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $this->db()->table('performance_cycles')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => $id]);
    }

    public function changeCycleStatus($id = null)
    {
        if (! $this->can('performance.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $newStatus = strtoupper((string) ($data['status'] ?? ''));
        $allowed = ['DRAFT','OPEN','GOAL_SETTING','IN_PROGRESS','REVIEW','CLOSED','CANCELLED'];
        if (! in_array($newStatus, $allowed, true)) {
            return $this->fail('Status tidak valid', 422);
        }
        $this->db()->table('performance_cycles')->where('id', $id)->update([
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            Services::audit()->forModule('PERFORMANCE')->log(
                action: 'cycle.status_change', entity: 'performance_cycles', entityId: $id,
                description: "Cycle #{$id} status → {$newStatus}",
            );
        } catch (\Throwable $e) {}
        return $this->ok(['id' => $id, 'status' => $newStatus]);
    }

    // ─── Goals ──────────────────────────────────────────────────────

    public function listGoals()
    {
        $perPage = max(1, min(200, (int) ($this->request->getGet('per_page') ?? 50)));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $cycle = (int) ($this->request->getGet('cycle_id') ?? 0);
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));
        $mine = (int) ($this->request->getGet('mine') ?? 0);

        $b = $this->db()->table('goals g')
            ->select('g.*, e.full_name AS employee_name, e.employee_number, c.name AS cycle_name')
            ->join('employees e', 'e.id = g.employee_id')
            ->join('performance_cycles c', 'c.id = g.cycle_id')
            ->where('g.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): tanpa
        // `performance.view`/`manage`/`review`, karyawan hanya boleh lihat
        // goal miliknya sendiri. Sebelumnya `?employee_id=X` mentah → bocor.
        $effective = $this->privacy()->applyListScope(
            $b, module: 'performance',
            companyColumn: 'g.company_id',
            employeeColumn: 'g.employee_id',
            requestedEmployeeId: $mine
                ? $this->getMyEmployeeId()
                : ($requestedEmpId ?: null),
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($cycle) $b->where('g.cycle_id', $cycle);
        if ($status) $b->where('g.status', $status);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('g.cycle_id', 'DESC')->orderBy('g.weight', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function createGoal()
    {
        // Karyawan boleh create goal sendiri; HR/manager juga bisa create utk orang lain
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'cycle_id' => 'required|integer',
            'title' => 'required|min_length[3]',
            'weight' => 'required',
        ]);
        if ($data === null) return $this->response;

        $myEmpId = $this->getMyEmployeeId();
        $employeeId = (int) ($data['employee_id'] ?? $myEmpId);
        if (! $employeeId) return $this->fail('Employee tidak ditemukan', 400);

        // Authorization: kalau bukan diri sendiri, butuh goal.manage
        if ($employeeId !== $myEmpId && ! $this->can('goal.manage')) {
            return $this->fail('Forbidden — hanya boleh untuk diri sendiri', 403);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $employee = $this->db()->table('employees')->where('id', $employeeId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) return $this->fail('Employee tidak ditemukan di tenant aktif', 404);
        $cycle = $this->db()->table('performance_cycles')->where('id', (int) $data['cycle_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $cycle) return $this->fail('Performance cycle tidak ditemukan di tenant aktif', 404);
        if (! empty($data['parent_id'])) {
            $parent = $this->db()->table('goals')->where('id', (int) $data['parent_id'])
                ->where('company_id', $companyId)->where('cycle_id', (int) $data['cycle_id'])
                ->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $parent) return $this->fail('Parent goal tidak valid untuk cycle dan tenant ini', 422);
        }
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'cycle_id' => (int) $data['cycle_id'],
            'employee_id' => $employeeId,
            'parent_id' => ! empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'goal_type' => strtoupper($data['goal_type'] ?? 'KPI'),
            'category' => $data['category'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'measurement_type' => strtoupper($data['measurement_type'] ?? 'PERCENT'),
            'target_value' => $data['target_value'] ?? null,
            'current_value' => 0,
            'unit' => $data['unit'] ?? null,
            'weight' => (float) $data['weight'],
            'due_date' => $data['due_date'] ?? null,
            'status' => 'DRAFT',
            'progress_percent' => 0,
            'created_by' => (int) $this->tenant()->getUserId(),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db()->table('goals')->insert($insert);
        $id = (int) $this->db()->insertID();
        return $this->ok(['id' => $id], 'OK', 201);
    }

    public function showGoal($id = null)
    {
        if (! $this->can('goal.view')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $g = $this->db()->table('goals')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $g) return $this->fail('Not found', 404);
        if ((int) $g['employee_id'] !== (int) $this->getMyEmployeeId()
            && ! $this->can('performance.view') && ! $this->can('performance.manage')) {
            return $this->fail('Forbidden', 403);
        }

        $g['check_ins'] = $this->db()->table('goal_check_ins')
            ->where('goal_id', $id)->where('company_id', $companyId)
            ->orderBy('check_in_date', 'DESC')->get()->getResultArray();

        return $this->ok($g);
    }

    public function updateGoal($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $g = $this->db()->table('goals')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $g) return $this->fail('Not found', 404);

        $myEmpId = $this->getMyEmployeeId();
        $isOwner = $myEmpId && (int) $g['employee_id'] === $myEmpId;
        if (! $isOwner && ! $this->can('goal.manage')) return $this->fail('Forbidden', 403);

        // Saat status APPROVED, owner hanya boleh update current_value (yang dilakukan via check-in)
        if (in_array($g['status'], ['APPROVED','IN_PROGRESS','ACHIEVED'], true) && $isOwner && ! $this->can('goal.manage')) {
            return $this->fail('Goal sudah disetujui — pakai check-in untuk update progress', 422);
        }

        if (! in_array((string) $g['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            return $this->fail('Goal hanya dapat diedit saat DRAFT atau setelah approval ditolak', 409);
        }

        $data = $this->getJsonInput();
        if (array_key_exists('parent_id', $data) && ! empty($data['parent_id'])) {
            $parent = $this->db()->table('goals')->where('id', (int) $data['parent_id'])
                ->where('company_id', $companyId)->where('cycle_id', (int) $g['cycle_id'])
                ->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $parent || (int) $parent['id'] === $id) {
                return $this->fail('Parent goal tidak valid untuk cycle dan tenant ini', 422);
            }
        }
        $update = [];
        foreach (['title','description','goal_type','category','measurement_type','target_value',
                 'unit','weight','due_date','parent_id'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('goals')->where('id', $id)->where('company_id', $companyId)
            ->whereIn('status', ['DRAFT', 'APPROVAL_REJECTED'])->update($update);

        try { $this->svc()->recomputeGoalProgress($id); } catch (\Throwable $e) {}
        return $this->ok(['id' => $id]);
    }

    public function deleteGoal($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $g = $this->db()->table('goals')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $g) return $this->fail('Not found', 404);

        $myEmpId = $this->getMyEmployeeId();
        $isOwner = $myEmpId && (int) $g['employee_id'] === $myEmpId;
        if (! $isOwner && ! $this->can('goal.manage')) return $this->fail('Forbidden', 403);

        if (! in_array($g['status'], ['DRAFT'], true) && ! $this->can('goal.manage')) {
            return $this->fail('Goal sudah lewat draft, tidak bisa dihapus', 422);
        }

        if (! in_array((string) $g['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            return $this->fail('Goal pending atau sudah disetujui tidak dapat dihapus', 409);
        }

        $this->db()->table('goals')->where('id', $id)->where('company_id', $companyId)
            ->whereIn('status', ['DRAFT', 'APPROVAL_REJECTED'])->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => $id]);
    }

    public function submitGoal($id = null)
    {
        try {
            $companyId = $this->requireCompanyId();
            if ($companyId === null) return $this->response;
            $employeeId = (int) $this->getMyEmployeeId();
            if ($employeeId <= 0) return $this->fail('Employee tidak ditemukan', 422);
            $userId = (int) $this->tenant()->getUserId();
            $result = (new PerformanceGoalApprovalService())->submit(
                (int) $id, $companyId, $employeeId, $userId
            );
            Services::audit()->forModule('PERFORMANCE')->log(
                action: 'goal.submit', entity: 'goals', entityId: (int) $id,
                description: 'Goal diajukan untuk approval atasan',
            );
            return $this->ok($result, 'Goal diajukan untuk approval', 202);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function approveGoal($id = null)
    {
        return $this->fail(
            'Direct approval dinonaktifkan. Pemilik goal harus submit dan manager memutuskan melalui Approval Inbox.',
            409,
            'APPROVAL_ENGINE_REQUIRED'
        );
    }

    public function checkInGoal($id = null)
    {
        $id = (int) $id;
        $myEmpId = $this->getMyEmployeeId();
        if (! $myEmpId) return $this->fail('Employee tidak ditemukan', 400);
        $data = $this->getJsonInput();
        try {
            $r = $this->svc()->submitCheckIn($id, $myEmpId, $data);
            return $this->ok($r);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function validateWeights($cycleId = null, $employeeId = null)
    {
        if (! $this->can('goal.view')) return $this->fail('Forbidden', 403);
        $r = $this->svc()->validateWeights((int) $cycleId, (int) $employeeId);
        return $this->ok($r);
    }

    // ─── Reviews ────────────────────────────────────────────────────

    public function listReviews()
    {
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 30)));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $cycle = (int) ($this->request->getGet('cycle_id') ?? 0);
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));
        $mine = (int) ($this->request->getGet('mine') ?? 0);
        $myTeam = (int) ($this->request->getGet('my_team') ?? 0);

        $b = $this->db()->table('performance_reviews r')
            ->select('r.*, e.full_name AS employee_name, e.employee_number, c.name AS cycle_name, ru.name AS reviewer_name')
            ->join('employees e', 'e.id = r.employee_id')
            ->join('performance_cycles c', 'c.id = r.cycle_id')
            ->join('users ru', 'ru.id = r.reviewer_id', 'left')
            ->where('r.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): performance review =
        // sangat sensitif (skor, feedback). Karyawan tanpa `performance.view`
        // hanya boleh lihat reviewnya sendiri ATAU yang dia jadi reviewer.
        $tenant = $this->tenant();
        $tenant->applyScope($b, 'r.company_id');
        $selfConductOnly = $tenant->hasPermission('review.conduct_self')
            && ! $tenant->hasPermission('review.view_team')
            && ! $tenant->hasPermission('performance.manage');
        $canViewAll = ! $selfConductOnly && $this->privacy()->canViewAll('performance');
        if ($selfConductOnly) {
            $b->where('r.reviewer_id', (int) $tenant->getUserId());
        }
        if (! $canViewAll) {
            if (! $selfConductOnly) {
                $myEmpId = $this->getMyEmployeeId();
                $myUserId = (int) $tenant->getUserId();
                $b->groupStart()
                    ->where('r.employee_id', $myEmpId ?: 0)
                    ->orWhere('r.reviewer_id', $myUserId)
                    ->groupEnd();
            }
        } elseif ($mine) {
            $myEmpId = $this->getMyEmployeeId();
            if ($myEmpId) $b->where('r.employee_id', $myEmpId);
        } elseif ($myTeam) {
            $b->where('r.reviewer_id', (int) $tenant->getUserId());
        } elseif ($requestedEmpId) {
            $b->where('r.employee_id', $requestedEmpId);
        }
        if ($cycle) $b->where('r.cycle_id', $cycle);
        if ($status) $b->where('r.status', $status);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('r.cycle_id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function createReview()
    {
        if (! $this->can('review.conduct')) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $data = $this->validateOrFail($data, [
            'cycle_id' => 'required|integer',
            'employee_id' => 'required|integer',
        ]);
        if ($data === null) return $this->response;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return $this->response;
        $reviewerId = (int) ($data['reviewer_id'] ?? $this->tenant()->getUserId());
        $reviewType = strtoupper($data['review_type'] ?? 'MANAGER');

        // Cek dup
        $exists = $this->db()->table('performance_reviews')
            ->where('cycle_id', (int) $data['cycle_id'])
            ->where('employee_id', (int) $data['employee_id'])
            ->where('reviewer_id', $reviewerId)
            ->where('review_type', $reviewType)->countAllResults();
        if ($exists > 0) return $this->fail('Review sudah ada untuk kombinasi ini', 422);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'cycle_id' => (int) $data['cycle_id'],
            'employee_id' => (int) $data['employee_id'],
            'reviewer_id' => $reviewerId,
            'review_type' => $reviewType,
            'status' => 'DRAFT',
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db()->table('performance_reviews')->insert($insert);
        $id = (int) $this->db()->insertID();
        return $this->ok(['id' => $id], 'OK', 201);
    }

    public function showReview($id = null)
    {
        if (! $this->can('performance.view')
            && ! $this->can('review.conduct')
            && ! $this->can('review.conduct_self')
        ) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $r = $this->db()->table('performance_reviews')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $r) return $this->fail('Not found', 404);

        // Authorization: yang bersangkutan, reviewer, atau HR
        $myEmpId = $this->getMyEmployeeId();
        $myUserId = (int) $this->tenant()->getUserId();
        $isOwner = $myEmpId && (int) $r['employee_id'] === $myEmpId;
        $isReviewer = $myUserId && (int) $r['reviewer_id'] === $myUserId;
        if (! $isOwner && ! $isReviewer && ! $this->can('review.view_team') && ! $this->can('performance.manage')) {
            return $this->fail('Forbidden', 403);
        }

        $r['goal_scores'] = json_decode($r['goal_scores'] ?? '[]', true) ?: [];
        $r['competency_scores'] = json_decode($r['competency_scores'] ?? '[]', true) ?: [];

        // Include goals untuk easy display
        $r['goals'] = $this->db()->table('goals')
            ->where('cycle_id', (int) $r['cycle_id'])
            ->where('employee_id', (int) $r['employee_id'])
            ->where('deleted_at IS NULL')
            ->whereNotIn('status', ['CANCELLED'])
            ->get()->getResultArray();

        return $this->ok($r);
    }

    public function updateReview($id = null)
    {
        $id = (int) $id;
        $r = $this->db()->table('performance_reviews')->where('id', $id)->get()->getRowArray();
        if (! $r) return $this->fail('Not found', 404);
        if ($r['status'] !== 'DRAFT') return $this->fail('Hanya draft yang bisa diedit', 422);

        $myUserId = (int) $this->tenant()->getUserId();
        if ((int) $r['reviewer_id'] !== $myUserId && ! $this->can('performance.manage')) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $update = [];
        foreach (['strengths','improvements','development_plan','reviewer_comment',
                 'recommendation','recommended_increase_percent'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (isset($data['goal_scores'])) $update['goal_scores'] = json_encode($data['goal_scores']);
        if (isset($data['competency_scores'])) $update['competency_scores'] = json_encode($data['competency_scores']);

        if (! $update) return $this->fail('Tidak ada perubahan', 400);
        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('performance_reviews')->where('id', $id)->update($update);
        return $this->ok(['id' => $id]);
    }

    public function submitReview($id = null)
    {
        $id = (int) $id;
        $review = $this->db()->table('performance_reviews')->select('reviewer_id')
            ->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $review) return $this->fail('Not found', 404);
        if ((int) $review['reviewer_id'] !== (int) $this->tenant()->getUserId()
            && ! $this->can('performance.manage')
        ) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        try {
            $r = $this->svc()->submitReview($id, $data);
            try {
                Services::audit()->forModule('PERFORMANCE')->log(
                    action: 'review.submit', entity: 'performance_reviews', entityId: $id,
                    description: "Review submitted: score {$r['overall_score']} ({$r['rating']})",
                );
            } catch (\Throwable $e) {}
            return $this->ok($r);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function acknowledgeReview($id = null)
    {
        $id = (int) $id;
        $myEmpId = $this->getMyEmployeeId();
        if (! $myEmpId) return $this->fail('Employee tidak ditemukan', 400);
        $data = $this->getJsonInput();
        try {
            $this->svc()->acknowledgeReview($id, $myEmpId, $data);
            return $this->ok(['id' => $id, 'status' => 'ACKNOWLEDGED']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function finalizeReview($id = null)
    {
        if (! $this->can('performance.manage')) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $this->svc()->finalizeReview($id);
            try {
                Services::audit()->forModule('PERFORMANCE')->log(
                    action: 'review.finalize', entity: 'performance_reviews', entityId: $id,
                    description: "Review finalized #{$id}", severity: 'NOTICE',
                );
            } catch (\Throwable $e) {}
            return $this->ok(['id' => $id, 'status' => 'FINALIZED']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── My / Team ──────────────────────────────────────────────────

    public function my()
    {
        $myEmpId = $this->getMyEmployeeId();
        if (! $myEmpId) return $this->fail('Employee tidak ditemukan', 400);

        $cycleId = (int) ($this->request->getGet('cycle_id') ?? 0);
        $b = $this->db()->table('goals g')
            ->select('g.*, c.name AS cycle_name, c.status AS cycle_status')
            ->join('performance_cycles c', 'c.id = g.cycle_id')
            ->where('g.employee_id', $myEmpId)->where('g.deleted_at IS NULL');
        if ($cycleId) $b->where('g.cycle_id', $cycleId);
        $goals = $b->orderBy('g.cycle_id', 'DESC')->get()->getResultArray();

        $b2 = $this->db()->table('performance_reviews r')
            ->select('r.*, c.name AS cycle_name')
            ->join('performance_cycles c', 'c.id = r.cycle_id')
            ->where('r.employee_id', $myEmpId)->where('r.deleted_at IS NULL');
        if ($cycleId) $b2->where('r.cycle_id', $cycleId);
        $reviews = $b2->orderBy('r.cycle_id', 'DESC')->get()->getResultArray();

        return $this->ok([
            'goals' => $goals,
            'reviews' => $reviews,
        ]);
    }
}
