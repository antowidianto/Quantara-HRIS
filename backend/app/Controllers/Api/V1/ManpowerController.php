<?php

namespace App\Controllers\Api\V1;

use App\Libraries\ManpowerService;
use Config\Services;

/**
 * ManpowerController — REST API untuk Manpower Planning (MPP).
 *
 * Authorization (lihat permission map di migration 31):
 *   - `mpp.view`    → list/show MPP
 *   - `mpp.submit`  → create + submit MPP (line manager / N+1)
 *   - `mpp.approve` → approve / reject step (HRD Manager / Director)
 *   - `mpp.fulfill` → buat job_postings dari MPP (Talent Acquisition)
 *
 * Endpoint:
 *   GET    /manpower-requests                       — list MPP
 *   POST   /manpower-requests                       — create draft
 *   GET    /manpower-requests/(:num)                — detail + approvals + postings
 *   PUT    /manpower-requests/(:num)                — update draft
 *   DELETE /manpower-requests/(:num)                — soft-delete (DRAFT only)
 *   POST   /manpower-requests/(:num)/submit         — DRAFT → SUBMITTED
 *   POST   /manpower-requests/(:num)/approve        — approve step saat ini
 *   POST   /manpower-requests/(:num)/reject         — reject + alasan
 *   POST   /manpower-requests/(:num)/cancel         — cancel (DRAFT/SUBMITTED)
 *   POST   /manpower-requests/(:num)/create-posting — buatkan job_postings (TA)
 *   POST   /manpower-requests/(:num)/close          — close manual (HIRING/FILLED)
 *   GET    /manpower-requests/stats                 — agregat per status
 *   GET    /manpower-requests/inbox                 — pengajuan yg menunggu approval saya
 */
class ManpowerController extends ApiController
{
    private const TYPES   = ManpowerService::TYPES;
    private const REASONS = ManpowerService::REASONS;
    private const EMPLOYMENT_TYPES = ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT'];

    // ────────────────────────────────────────────────────────────────
    // List / Detail
    // ────────────────────────────────────────────────────────────────

    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $q       = trim((string) ($this->request->getGet('q') ?? ''));
        $status  = strtoupper((string) ($this->request->getGet('status') ?? ''));
        $type    = strtoupper((string) ($this->request->getGet('request_type') ?? ''));
        $deptId  = (int) ($this->request->getGet('department_id') ?? 0);
        $mineOnly = (int) ($this->request->getGet('mine') ?? 0) === 1;

        $builder = $db->table('manpower_requests mr')
            ->select('mr.*, d.name AS department_name, p.name AS position_name, b.name AS branch_name,'
                . ' u.name AS requested_by_name')
            ->join('departments d', 'd.id = mr.department_id', 'left')
            ->join('positions p',   'p.id = mr.position_id',   'left')
            ->join('branches b',    'b.id = mr.branch_id',     'left')
            ->join('users u',       'u.id = mr.requested_by_user_id', 'left')
            ->where('mr.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'mr.company_id');

        if ($q !== '') {
            $builder->groupStart()
                ->like('mr.proposed_title', $q)
                ->orLike('mr.reference_number', $q)
                ->orLike('mr.justification', $q)
                ->groupEnd();
        }
        if ($status !== '')           $builder->where('mr.status', $status);
        if ($type   !== '')           $builder->where('mr.request_type', $type);
        if ($deptId > 0)              $builder->where('mr.department_id', $deptId);
        if ($mineOnly)                $builder->where('mr.requested_by_user_id', $this->userId());

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('mr.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        // Approvals — left-join ke users untuk approver yang sudah memutuskan,
        // dan ke organization_structures + employees untuk expected approver
        // (penjabat yang seharusnya memutuskan, dari role_key).
        $approvalsBuilder = $db->table('manpower_request_approvals mra')
            ->select('mra.*, u.name AS approver_name')
            ->join('users u', 'u.id = mra.approver_user_id', 'left')
            ->where('mra.manpower_request_id', (int) $id)
            ->orderBy('mra.step', 'ASC');
        $approvals = $approvalsBuilder->get()->getResultArray();

        // Enrich dengan nama penjabat saat ini (resolver realtime, mengakomodasi
        // kasus role_key di-reassign ke employee lain setelah submit).
        $orgSvc = Services::orgStructure();
        $companyId = (int) $row['company_id'];
        foreach ($approvals as &$a) {
            $a['expected_approver_name'] = null;
            $a['expected_approver_title'] = null;
            if (! empty($a['expected_approver_employee_id'])) {
                $emp = $db->table('employees')->select('full_name, employee_number')
                    ->where('id', (int) $a['expected_approver_employee_id'])->get()->getRowArray();
                if ($emp) $a['expected_approver_name'] = $emp['full_name'];
            }
            // Kalau expected_user belum di-set saat submit (mis. role baru
            // di-assign setelahnya), coba resolve sekarang sebagai fallback.
            if (empty($a['expected_approver_user_id']) && ! empty($a['approver_role'])) {
                $resolvedUid = $orgSvc->resolveApproverUser($companyId, $a['approver_role']);
                $resolvedEmp = $orgSvc->resolveApproverEmployee($companyId, $a['approver_role']);
                if ($resolvedUid) {
                    $a['expected_approver_user_id_runtime'] = $resolvedUid;
                }
                if ($resolvedEmp) {
                    $emp = $db->table('employees')->select('full_name')->where('id', $resolvedEmp)->get()->getRowArray();
                    $a['expected_approver_name'] = $emp['full_name'] ?? null;
                }
            }
            // Lookup title dari organization_structures supaya UI bisa
            // tampilkan "HR Manager — Budi Santoso" alih-alih "HRD_MANAGER".
            if (! empty($a['approver_role'])) {
                $os = $orgSvc->findByRoleKey($companyId, (string) $a['approver_role']);
                $a['expected_approver_title'] = $os['title'] ?? $a['approver_role'];
            }
        }
        unset($a);

        // Linked postings + summary kandidat
        $postings = $db->table('manpower_request_postings mrp')
            ->select('jp.id, jp.reference_number, jp.title, jp.status, jp.headcount, jp.opened_at, jp.closed_at,'
                . ' (SELECT COUNT(*) FROM candidates c WHERE c.job_posting_id = jp.id AND c.deleted_at IS NULL) AS candidate_count,'
                . ' (SELECT COUNT(*) FROM candidates c WHERE c.job_posting_id = jp.id AND c.current_stage = "HIRED" AND c.converted_employee_id IS NOT NULL) AS hired_count')
            ->join('job_postings jp', 'jp.id = mrp.job_posting_id')
            ->where('mrp.manpower_request_id', (int) $id)
            ->where('jp.deleted_at IS NULL')
            ->orderBy('jp.created_at', 'DESC')
            ->get()->getResultArray();

        // Sisa headcount yang belum dialokasikan ke posting
        $usedHc = array_sum(array_map(fn($r) => (int) $r['headcount'], $postings));
        $remainingHc = max(0, (int) $row['headcount'] - $usedHc);

        $row['approvals']         = $approvals;
        $row['postings']          = $postings;
        $row['allocated_headcount'] = $usedHc;
        $row['remaining_headcount'] = $remainingHc;

        return $this->ok($row);
    }

    // ────────────────────────────────────────────────────────────────
    // Create / Update / Delete
    // ────────────────────────────────────────────────────────────────

    public function create()
    {
        if (! $this->canSubmit()) return $this->fail('Forbidden: butuh permission mpp.submit', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'proposed_title' => 'required|max_length[200]',
            'headcount'      => 'required|integer|greater_than[0]',
            'request_type'   => 'required',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $type = strtoupper((string) ($data['request_type'] ?? 'BUDGET'));
        if (! in_array($type, self::TYPES, true)) {
            return $this->fail('request_type harus BUDGET atau UNBUDGET', 422);
        }
        $reason = strtoupper((string) ($data['reason'] ?? 'REPLACEMENT'));
        if (! in_array($reason, self::REASONS, true)) {
            return $this->fail('reason tidak valid', 422);
        }
        $employmentType = strtoupper((string) ($data['employment_type'] ?? 'PKWTT'));
        if (! in_array($employmentType, self::EMPLOYMENT_TYPES, true)) {
            $employmentType = 'PKWTT';
        }

        // Auto-fill department/branch dari profile pengaju kalau tidak diisi
        $myDeptId = null;
        $myBranchId = null;
        $myEmployeeId = null;
        $db = \Config\Database::connect();
        $me = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        if ($me && ! empty($me['employee_id'])) {
            $myEmployeeId = (int) $me['employee_id'];
            $emp = $db->table('employees')->where('id', $myEmployeeId)->get()->getRowArray();
            if ($emp) {
                $myDeptId   = $emp['department_id']   !== null ? (int) $emp['department_id']   : null;
                $myBranchId = $emp['branch_id']       !== null ? (int) $emp['branch_id']       : null;
            }
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'                => $companyId,
            'reference_number'          => Services::manpower()->nextReferenceNumber($companyId),
            'requested_by_user_id'      => $this->userId(),
            'requested_by_employee_id'  => $myEmployeeId,
            'department_id'             => ! empty($data['department_id']) ? (int) $data['department_id'] : $myDeptId,
            'position_id'               => ! empty($data['position_id'])   ? (int) $data['position_id']   : null,
            'branch_id'                 => ! empty($data['branch_id'])     ? (int) $data['branch_id']     : $myBranchId,
            'request_type'              => $type,
            'reason'                    => $reason,
            'replacement_for_employee_id' => ! empty($data['replacement_for_employee_id']) ? (int) $data['replacement_for_employee_id'] : null,
            'proposed_title'            => trim((string) $data['proposed_title']),
            'headcount'                 => max(1, (int) $data['headcount']),
            'employment_type'           => $employmentType,
            'salary_min'                => isset($data['salary_min']) ? (float) $data['salary_min'] : null,
            'salary_max'                => isset($data['salary_max']) ? (float) $data['salary_max'] : null,
            'target_start_date'         => $data['target_start_date'] ?? null,
            'justification'             => $data['justification'] ?? null,
            'job_description'           => $data['job_description'] ?? null,
            'requirements'              => $data['requirements'] ?? null,
            'notes'                     => $data['notes'] ?? null,
            'status'                    => 'DRAFT',
            'created_by'                => $this->userId(),
            'updated_by'                => $this->userId(),
            'created_at'                => $now,
            'updated_at'                => $now,
        ];

        // Sprint 5 — onboarding planning fields. Hanya disimpan kalau
        // schema sudah migrate (cek getFieldNames). Mempertahankan
        // backward-compat dengan instance yang belum jalan migration 56.
        $mppCols = $db->getFieldNames('manpower_requests');
        if (in_array('job_grade_id', $mppCols, true)) {
            $insert['job_grade_id'] = ! empty($data['job_grade_id']) ? (int) $data['job_grade_id'] : null;
        }
        if (in_array('proposed_supervisor_employee_id', $mppCols, true)) {
            $insert['proposed_supervisor_employee_id'] =
                ! empty($data['proposed_supervisor_employee_id'])
                    ? (int) $data['proposed_supervisor_employee_id'] : null;
        }
        if (in_array('proposed_manager_employee_id', $mppCols, true)) {
            $insert['proposed_manager_employee_id'] =
                ! empty($data['proposed_manager_employee_id'])
                    ? (int) $data['proposed_manager_employee_id'] : null;
        }

        // Sprint 6 — Cost Center: di-set explisit oleh requestor atau
        // resolve otomatis dari department.default_cost_center_id atau
        // branch.default_cost_center_id. Validate kalau eksplisit di-set.
        if (in_array('cost_center_id', $mppCols, true)) {
            $explicitCc = ! empty($data['cost_center_id']) ? (int) $data['cost_center_id'] : null;
            if ($explicitCc) {
                try {
                    Services::costCenter()->assertValidForCompany($explicitCc, $companyId);
                    $insert['cost_center_id'] = $explicitCc;
                } catch (\Throwable $e) {
                    return $this->fail($e->getMessage(), 422);
                }
            } else {
                // Auto-resolve dari dept/branch saat create — tidak fatal
                // kalau gagal (cost center bisa di-set HR via edit).
                try {
                    $insert['cost_center_id'] = Services::costCenter()
                        ->resolveForRequestData($companyId, $insert);
                } catch (\Throwable $e) {
                    log_message('warning', 'MPP cost_center auto-resolve failed: ' . $e->getMessage());
                }
            }
        }

        $db->table('manpower_requests')->insert($insert);
        $id = (int) $db->insertID();

        // Sprint 5 — sync planned assets list dari payload (best-effort,
        // jangan break create kalau planning service punya error)
        $plannedAssets = isset($data['planned_assets']) && is_array($data['planned_assets'])
            ? $data['planned_assets'] : [];
        if (! empty($plannedAssets)) {
            try {
                $syncResult = Services::onboardingPlan()->syncMppPlannedAssets(
                    $id, $plannedAssets, $this->userId()
                );
                if (! empty($syncResult['errors'])) {
                    log_message('warning', 'MPP planned assets validation errors: '
                        . json_encode($syncResult['errors']));
                }
            } catch (\Throwable $e) {
                log_message('warning', "MPP planned assets sync failed: " . $e->getMessage());
            }
        }

        Services::audit()->forModule('RECRUITMENT')->logCreate('manpower_request', $id, $insert,
            "MPP baru: {$insert['reference_number']} - {$insert['proposed_title']} ({$type}, qty {$insert['headcount']})");

        // Kalau caller minta langsung submit (tombol "Buat & Ajukan" di UI),
        // panggil submit() sekalian supaya tidak perlu round-trip kedua.
        $autoSubmit = (bool) ($data['submit_now'] ?? false);
        if ($autoSubmit) {
            try {
                Services::manpower()->submit($id, $this->userId());
                Services::audit()->forModule('RECRUITMENT')->log(
                    action: 'mpp.submit', entity: 'manpower_request', entityId: $id,
                    description: "Submit MPP {$insert['reference_number']}", severity: 'NOTICE'
                );
            } catch (\Throwable $e) {
                // Insert tetap sukses walau submit gagal — MPP tetap di DRAFT
                log_message('warning', "MPP create+submit failed: " . $e->getMessage());
            }
        }

        return $this->ok([
            'id' => $id,
            'reference_number' => $insert['reference_number'],
            'submitted'        => $autoSubmit,
        ], 'Created', 201);
    }

    public function update($id = null)
    {
        if (! $this->canSubmit()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Hanya pengaju yang bisa edit, dan hanya saat status DRAFT
        if ((int) $row['requested_by_user_id'] !== $this->userId() && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Hanya pengaju yang bisa edit MPP ini', 403);
        }
        if ($row['status'] !== 'DRAFT') {
            return $this->fail("MPP tidak bisa di-edit di status {$row['status']}, hanya DRAFT", 422);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'proposed_title','department_id','position_id','branch_id',
            'request_type','reason','replacement_for_employee_id',
            'headcount','employment_type','salary_min','salary_max',
            'target_start_date','justification','job_description','requirements','notes',
            // Sprint 5 — planning fields (whitelisted; null-able)
            'job_grade_id','proposed_supervisor_employee_id','proposed_manager_employee_id',
            // Sprint 6 — cost center
            'cost_center_id',
        ]));
        // Coerce planning IDs to int|null untuk konsistensi DB.
        foreach (['job_grade_id','proposed_supervisor_employee_id','proposed_manager_employee_id','cost_center_id'] as $k) {
            if (array_key_exists($k, $update)) {
                $update[$k] = ! empty($update[$k]) ? (int) $update[$k] : null;
            }
        }
        // Validasi cost_center_id kalau di-set
        if (! empty($update['cost_center_id'])) {
            try {
                Services::costCenter()->assertValidForCompany(
                    (int) $update['cost_center_id'], (int) $row['company_id']
                );
            } catch (\Throwable $e) {
                return $this->fail($e->getMessage(), 422);
            }
        }

        // Sanitize enum-like fields
        if (isset($update['request_type'])) {
            $update['request_type'] = strtoupper((string) $update['request_type']);
            if (! in_array($update['request_type'], self::TYPES, true)) {
                return $this->fail('request_type tidak valid', 422);
            }
        }
        if (isset($update['reason'])) {
            $update['reason'] = strtoupper((string) $update['reason']);
            if (! in_array($update['reason'], self::REASONS, true)) {
                return $this->fail('reason tidak valid', 422);
            }
        }
        if (isset($update['employment_type'])) {
            $update['employment_type'] = strtoupper((string) $update['employment_type']);
            if (! in_array($update['employment_type'], self::EMPLOYMENT_TYPES, true)) {
                unset($update['employment_type']);
            }
        }
        if (isset($update['headcount'])) $update['headcount'] = max(1, (int) $update['headcount']);
        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');

        \Config\Database::connect()->table('manpower_requests')->where('id', (int) $id)->update($update);

        // Sprint 5 — kalau payload punya planned_assets, sync ulang.
        if (isset($data['planned_assets']) && is_array($data['planned_assets'])) {
            try {
                $syncResult = Services::onboardingPlan()->syncMppPlannedAssets(
                    (int) $id, $data['planned_assets'], $this->userId()
                );
                if (! empty($syncResult['errors'])) {
                    return $this->fail([
                        'message' => 'Beberapa baris asset tidak valid',
                        'errors'  => $syncResult['errors'],
                    ], 422);
                }
            } catch (\Throwable $e) {
                log_message('warning', "MPP planned assets sync failed: " . $e->getMessage());
            }
        }

        Services::audit()->forModule('RECRUITMENT')->logUpdate('manpower_request', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    /**
     * Sprint 5 — list planned assets untuk satu MPP.
     * Endpoint khusus supaya UI bisa lazy-load saat membuka detail.
     */
    public function plannedAssets($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        if (! $db->tableExists('manpower_request_assets')) {
            return $this->ok([]);
        }
        $rows = $db->table('manpower_request_assets mra')
            ->select('mra.*, a.asset_number, a.name AS asset_name, a.status AS asset_status, a.condition AS asset_condition')
            ->join('assets a', 'a.id = mra.asset_id', 'left')
            ->where('mra.manpower_request_id', (int) $id)
            ->orderBy('mra.id', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    /**
     * Sprint 5 — replace daftar planned asset untuk satu MPP.
     * Body: { plans: [{category, name, brand_model_pref?, asset_id?, is_required?, notes?}, ...] }
     * Hanya boleh saat MPP DRAFT atau SUBMITTED.
     */
    public function syncPlannedAssets($id = null)
    {
        if (! $this->canSubmit()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ((int) $row['requested_by_user_id'] !== $this->userId() && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Hanya pengaju yang bisa mengubah planned assets MPP ini', 403);
        }

        $data  = $this->getJsonInput();
        $plans = isset($data['plans']) && is_array($data['plans']) ? $data['plans'] : [];
        try {
            $result = Services::onboardingPlan()->syncMppPlannedAssets(
                (int) $id, $plans, $this->userId()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        if (! empty($result['errors'])) {
            return $this->fail([
                'message' => 'Beberapa baris asset tidak valid',
                'errors'  => $result['errors'],
            ], 422);
        }

        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.sync_planned_assets', entity: 'manpower_request', entityId: (int) $id,
            after: ['inserted' => $result['inserted']],
            description: "Sync planned assets MPP {$row['reference_number']}: {$result['inserted']} item",
            severity: 'NOTICE'
        );
        return $this->ok($result, 'Planned assets updated');
    }

    public function delete($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ((int) $row['requested_by_user_id'] !== $this->userId() && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Hanya pengaju yang bisa hapus MPP ini', 403);
        }
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya MPP DRAFT yang bisa dihapus, gunakan Cancel untuk status lain', 422);
        }
        \Config\Database::connect()->table('manpower_requests')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('RECRUITMENT')->logDelete('manpower_request', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Lifecycle actions
    // ────────────────────────────────────────────────────────────────

    public function submit($id = null)
    {
        if (! $this->canSubmit()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ((int) $row['requested_by_user_id'] !== $this->userId() && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Hanya pengaju yang bisa submit MPP ini', 403);
        }
        try {
            Services::manpower()->submit((int) $id, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.submit', entity: 'manpower_request', entityId: (int) $id,
            description: "Submit MPP {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok(null, 'Submitted');
    }

    public function approve($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden: butuh permission mpp.approve', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        try {
            $result = Services::manpower()->approveStep((int) $id, $this->userId(), $data['comment'] ?? null);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $sev = $result['final_approved'] ? 'CRITICAL' : 'NOTICE';
        $desc = $result['final_approved']
            ? "MPP {$row['reference_number']} APPROVED (semua langkah selesai)"
            : "MPP {$row['reference_number']} approved step {$result['step']}, sisa {$result['remaining_steps']} step";
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.approve', entity: 'manpower_request', entityId: (int) $id,
            after: $result, description: $desc, severity: $sev
        );
        return $this->ok($result, 'Approved');
    }

    public function reject($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) return $this->fail('Alasan minimal 5 karakter', 422);
        try {
            Services::manpower()->rejectStep((int) $id, $this->userId(), $reason);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.reject', entity: 'manpower_request', entityId: (int) $id,
            after: ['reason' => $reason],
            description: "Reject MPP {$row['reference_number']}: {$reason}",
            severity: 'WARNING'
        );
        return $this->ok(null, 'Rejected');
    }

    public function cancel($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ((int) $row['requested_by_user_id'] !== $this->userId() && ! $this->tenant()->isSuperAdmin() && ! $this->canApprove()) {
            return $this->fail('Hanya pengaju atau approver yang bisa cancel MPP', 403);
        }
        $data = $this->getJsonInput();
        try {
            Services::manpower()->cancel((int) $id, $this->userId(), $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.cancel', entity: 'manpower_request', entityId: (int) $id,
            description: "Cancel MPP {$row['reference_number']}", severity: 'WARNING'
        );
        return $this->ok(null, 'Cancelled');
    }

    /**
     * Talent Acquisition: convert MPP APPROVED ke job_postings.
     * Bisa dipanggil berkali-kali sampai semua headcount teralokasi.
     */
    public function createPosting($id = null)
    {
        if (! $this->canFulfill()) return $this->fail('Forbidden: butuh permission mpp.fulfill', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $overrides = array_intersect_key($data, array_flip([
            'title','department_id','position_id','branch_id','employment_type',
            'description','requirements','salary_min','salary_max','salary_visible',
            'headcount','opened_at','closed_at','hiring_manager_id','notes',
        ]));
        try {
            $result = Services::manpower()->createJobPostingFromMpp((int) $id, $this->userId(), $overrides);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.create_posting', entity: 'manpower_request', entityId: (int) $id,
            after: $result,
            description: "MPP {$row['reference_number']} → job posting {$result['reference_number']}",
            severity: 'NOTICE'
        );
        return $this->ok($result, 'Job posting created from MPP', 201);
    }

    public function close($id = null)
    {
        if (! $this->canApprove() && ! $this->canFulfill()) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        try {
            Services::manpower()->close((int) $id, $this->userId(), $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'mpp.close', entity: 'manpower_request', entityId: (int) $id,
            description: "Close MPP {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok(null, 'Closed');
    }

    // ────────────────────────────────────────────────────────────────
    // Stats & Inbox
    // ────────────────────────────────────────────────────────────────

    public function stats()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $stats = Services::manpower()->stats($companyId);
        return $this->ok($stats);
    }

    /**
     * Inbox approval untuk user dengan permission mpp.approve.
     * Mengembalikan semua MPP berstatus SUBMITTED yang punya step PENDING.
     */
    public function inbox()
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $builder = $db->table('manpower_requests mr')
            ->select('mr.*, d.name AS department_name, p.name AS position_name, u.name AS requested_by_name,'
                . ' (SELECT MIN(step) FROM manpower_request_approvals mra WHERE mra.manpower_request_id = mr.id AND mra.decision = "PENDING") AS current_step,'
                . ' (SELECT approver_role FROM manpower_request_approvals mra WHERE mra.manpower_request_id = mr.id AND mra.decision = "PENDING" ORDER BY step ASC LIMIT 1) AS current_role')
            ->join('departments d', 'd.id = mr.department_id', 'left')
            ->join('positions p',   'p.id = mr.position_id',   'left')
            ->join('users u',       'u.id = mr.requested_by_user_id', 'left')
            ->where('mr.status', 'SUBMITTED')
            ->where('mr.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'mr.company_id');
        $rows = $builder->orderBy('mr.submitted_at', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('mpp.view') || $t->hasPermission('recruitment.view');
    }

    private function canSubmit(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('mpp.submit');
    }

    private function canApprove(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('mpp.approve');
    }

    private function canFulfill(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('mpp.fulfill') || $t->hasPermission('recruitment.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('manpower_requests')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('MPP not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}