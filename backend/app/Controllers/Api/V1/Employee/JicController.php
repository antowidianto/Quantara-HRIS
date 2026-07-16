<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Employee\JicService;
use App\Libraries\Employee\JicNotificationService;
use App\Libraries\Employee\JicWorkflowException;
use App\Libraries\Employee\JicWorkflowService;
use App\Repositories\Employee\JicQueryRepository;
use App\Repositories\Employee\JicWorkflowRepository;
use Config\Services;

/**
 * JicController — Job Information Change (Promosi/Demosi/Mutasi).
 *
 * Workflow:
 *   DRAFT → PENDING_SUPERVISOR → PENDING_DEPT_HEAD → PENDING_HR
 *         → [PENDING_BOD?] → [PENDING_EMPLOYEE_CONSENT?] → APPROVED
 *         → IMPLEMENTED (saat effective_date)
 *
 * Kewenangan:
 *   - create/draft     : `jic.create` (HR/manager)
 *   - submit           : initiator (creator)
 *   - approve sup      : supervisor langsung dari karyawan
 *   - approve dept     : manager_id department lama atau baru
 *   - approve hr       : `jic.approve` atau `jic.manage`
 *   - approve bod      : `jic.manage` (super admin / direksi)
 *   - implement        : `jic.implement` (manual override) — biasanya cron
 *   - cancel           : initiator selama belum APPROVED, HR kapan saja
 *   - view all         : `jic.view` / `jic.manage`
 *   - view own         : selalu boleh kalau employee_id = caller's employee
 *
 * Konteks Indonesia (lihat docs/JIC.md):
 *   - DEMOTION non-disipliner WAJIB consent karyawan (Pasal 31 UU 13/2003).
 *   - DEMOTION disipliner WAJIB link `disciplinary_record_id` (rujukan SP).
 *   - Upah baru WAJIB ≥ UMP/UMK kecuali HR set ump_check_status=OVERRIDE
 *     (mis. atas dasar PKB/PP yang sah).
 *   - SK auto-generate nomor saat APPROVED.
 */
class JicController extends ApiController
{
    private const CHANGE_TYPES = [
        'PROMOTION', 'DEMOTION', 'LATERAL', 'REORG', 'SALARY_ADJUSTMENT', 'STATUS_CHANGE',
    ];
    private const REASON_CATEGORIES = [
        'PERFORMANCE', 'MERIT', 'TENURE', 'COMPETENCY', 'RESTRUCTURE',
        'DISCIPLINARY', 'EMPLOYEE_REQUEST', 'PROBATION_END', 'CONTRACT_RENEWAL',
        'UMP_ADJUSTMENT', 'OTHER',
    ];

    private function svc(): JicService
    {
        return new JicService();
    }

    // ─── LISTING ────────────────────────────────────────────────────

    /**
     * GET /api/v1/jic
     *
     * Filter:
     *   ?status=DRAFT|PENDING_HR|...
     *   ?change_type=PROMOTION|DEMOTION|...
     *   ?employee_id=N
     *   ?effective_from=2026-01-01&effective_to=2026-12-31
     *   ?as=mine|inbox  (mine = karyawan lihat JIC mereka, inbox = approver)
     */
    public function index()
    {
        $companyId = $this->companyId();
        $userId    = $this->userId();
        $can       = $this->tenant();
        $hasViewAll = $can->isSuperAdmin()
            || $can->hasPermission('jic.view')
            || $can->hasPermission('jic.manage');

        $perPage = max(1, min((int) $this->request->getGet('per_page') ?: 20, 100));
        $page    = max(1, (int) $this->request->getGet('page') ?: 1);
        $result = (new JicQueryRepository())->paginate(
            $companyId,
            $userId,
            $can->isSuperAdmin(),
            $hasViewAll,
            $can->hasPermission('jic.approve'),
            $can->hasPermission('jic.manage'),
            [
                'as' => (string) $this->request->getGet('as'),
                'status' => (string) $this->request->getGet('status'),
                'change_type' => (string) $this->request->getGet('change_type'),
                'employee_id' => (int) $this->request->getGet('employee_id'),
                'effective_from' => (string) $this->request->getGet('effective_from'),
                'effective_to' => (string) $this->request->getGet('effective_to'),
                'per_page' => $perPage,
                'page' => $page,
            ],
        );

        return $this->paginated($result['rows'], $result['total'], $page, $perPage);
    }

    /**
     * GET /api/v1/jic/{id}
     *
     * Mengembalikan response yang ringkas namun lengkap dengan struktur:
     *   - jic              : field utama JIC + workflow + compliance
     *   - employee         : info dasar karyawan (NIK encrypted di-skip,
     *                        hanya employee_number, contact dasar, masa kerja)
     *   - position_old     : { id, name, tier, name_snapshot }
     *   - position_new     : idem
     *   - department_old   : { id, name }
     *   - department_new   : idem
     *   - branch_old/new   : { id, name }
     *   - grade_old/new    : { id, name }
     *   - supervisor_old   : { employee_id, employee_number, full_name, position }
     *   - supervisor_new   : idem
     *   - manager_old/new  : idem
     *   - work_location_old/new : { id, name }
     *
     * Frontend lama yang masih membaca flat field (mis. `new_position_name`,
     * `old_dept_name_join`) tetap kompatibel — kita merge nested + flat.
     */
    public function show($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $payload = $this->buildDetailPayload($row);
        return $this->ok($payload);
    }

    /** GET /api/v1/jic/options — daftar enum untuk dropdown UI. */
    public function options()
    {
        return $this->ok([
            'change_types' => array_map(fn ($v) => ['code' => $v, 'label' => $this->changeTypeLabel($v)], self::CHANGE_TYPES),
            'reason_categories' => array_map(fn ($v) => ['code' => $v, 'label' => $this->reasonLabel($v)], self::REASON_CATEGORIES),
        ]);
    }

    // ─── CRUD ───────────────────────────────────────────────────────

    /**
     * POST /api/v1/jic
     *
     * Body:
     *   employee_id, reason_category, reason_text, effective_date,
     *   new_position_id?, new_department_id?, new_branch_id?, new_work_location_id?,
     *   new_job_grade_id?, new_employment_status?, new_basic_salary?,
     *   new_supervisor_id?, new_manager_id?,
     *   disciplinary_record_id?, performance_review_id?, mpp_id?, training_certificate_id?,
     *   ump_override_reason?
     *
     * change_type di-classify otomatis berdasar tier + delta salary.
     * Caller boleh pass `change_type_override` kalau ingin force tipe.
     */
    public function create()
    {
        $can = $this->tenant();
        if (! ($can->isSuperAdmin() || $can->hasPermission('jic.create') || $can->hasPermission('jic.manage'))) {
            return $this->fail('Tidak punya akses membuat JIC', 403);
        }

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id'      => 'required|is_natural_no_zero',
            'reason_category'  => 'required|in_list[' . implode(',', self::REASON_CATEGORIES) . ']',
            'effective_date'   => 'required|valid_date[Y-m-d]',
            'reason_text'      => 'permit_empty|max_length[2000]',
            'new_basic_salary' => 'permit_empty|decimal',
        ]);
        if (! $valid) return null;

        $svc  = $this->svc();
        $repository = new JicWorkflowRepository();
        $now  = date('Y-m-d H:i:s');
        $cid  = $this->companyId();

        // Cek employee ada & dalam company yg sama
        $emp = $repository->employee((int) $valid['employee_id'], $cid);
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if ($cid > 0 && (int) $emp['company_id'] !== $cid) {
            return $this->fail('Karyawan tidak ada di company Anda', 403);
        }
        if ((int) $emp['is_active'] !== 1) {
            return $this->fail('Karyawan tidak aktif', 422, 'INACTIVE_EMPLOYEE');
        }
        if (! empty($emp['resign_date']) && $emp['resign_date'] <= date('Y-m-d')) {
            return $this->fail('Karyawan sudah resign per ' . $emp['resign_date'], 422, 'EMPLOYEE_RESIGNED');
        }

        // Cek tidak ada JIC pending lain untuk karyawan ini
        if ($repository->hasActiveRequest((int) $emp['id'], $cid)) {
            return $this->fail(
                'Sudah ada JIC aktif untuk karyawan ini. Selesaikan/batalkan dulu.',
                409, 'DUPLICATE_REQUEST'
            );
        }

        // Snapshot lama
        $snapshot = $svc->snapshotEmployee((int) $emp['id'], $cid);

        // Resolve new tier (kalau new_position_id ada)
        $newTier = $snapshot['old_position_tier']; // default sama
        if (! empty($data['new_position_id'])) {
            $newTier = $repository->positionTier((int) $data['new_position_id'], $cid) ?? $newTier;
        }

        // Klasifikasi change_type
        $cls = $svc->classify($snapshot, [
            'new_position_id'        => $data['new_position_id']        ?? null,
            'new_department_id'      => $data['new_department_id']      ?? null,
            'new_branch_id'          => $data['new_branch_id']          ?? null,
            'new_work_location_id'   => $data['new_work_location_id']   ?? null,
            'new_basic_salary'       => $data['new_basic_salary']       ?? null,
            'new_employment_status'  => $data['new_employment_status']  ?? null,
            'new_position_tier'      => $newTier,
        ]);
        $changeType = $data['change_type_override'] ?? $cls['change_type'];
        if (! in_array($changeType, self::CHANGE_TYPES, true)) {
            return $this->fail('change_type tidak valid: ' . $changeType, 422);
        }

        // Cek UMP
        $ump = $svc->checkUmp(
            (int) ($cid ?: $emp['company_id']),
            isset($data['new_basic_salary']) ? (float) $data['new_basic_salary'] : null,
            $data['new_branch_id'] ?? $emp['branch_id'] ?? null,
        );

        // Apakah perlu consent karyawan
        $consentRequired = $svc->requiresEmployeeConsent($changeType, $valid['reason_category']);

        // Build insert payload — drop kolom virtual `old_position_tier`
        unset($snapshot['old_position_tier']);

        $insert = array_merge($snapshot, [
            'company_id'               => (int) ($cid ?: $emp['company_id']),
            'employee_id'              => (int) $emp['id'],
            'change_type'              => $changeType,
            'reason_category'          => $valid['reason_category'],
            'reason_text'              => $valid['reason_text'] ?? null,
            'proposed_at'              => $now,
            'effective_date'           => $valid['effective_date'],
            'notice_date'              => $data['notice_date'] ?? null,

            'new_position_id'          => $data['new_position_id']         ?? null,
            'new_department_id'        => $data['new_department_id']       ?? null,
            'new_branch_id'            => $data['new_branch_id']           ?? null,
            'new_work_location_id'     => $data['new_work_location_id']    ?? null,
            'new_job_grade_id'         => $data['new_job_grade_id']        ?? null,
            'new_employment_status'    => $data['new_employment_status']   ?? null,
            'new_basic_salary'         => isset($data['new_basic_salary'])
                ? (float) $data['new_basic_salary'] : null,
            'new_supervisor_id'        => $data['new_supervisor_id']       ?? null,
            'new_manager_id'           => $data['new_manager_id']          ?? null,

            'salary_change_pct'        => $cls['salary_change_pct'],
            'ump_reference'            => $ump['reference'],
            'ump_check_status'         => $ump['status'],
            'ump_override_reason'      => $data['ump_override_reason']     ?? null,

            'employee_consent_required'=> $consentRequired ? 1 : 0,
            'employee_consent_status'  => $consentRequired ? 'PENDING' : 'NOT_REQUIRED',

            'disciplinary_record_id'   => $data['disciplinary_record_id']  ?? null,
            'performance_review_id'    => $data['performance_review_id']   ?? null,
            'mpp_id'                   => $data['mpp_id']                  ?? null,
            'training_certificate_id'  => $data['training_certificate_id'] ?? null,

            'status'                   => 'DRAFT',
            'created_by'               => $this->userId(),
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        $jicId = $repository->create($insert, $cid);

        Services::audit()->forModule('JIC')->logCreate(
            'job_information_changes', $jicId, $insert,
            sprintf(
                'Buat draft JIC untuk %s — change_type: %s, reason: %s, effective: %s',
                $emp['full_name'], $changeType, $valid['reason_category'], $valid['effective_date'],
            ),
        );

        return $this->ok(
            ['id' => $jicId, 'change_type' => $changeType, 'classification' => $cls, 'ump_check' => $ump],
            'Draft JIC berhasil dibuat. Submit untuk mulai approval.',
            201,
        );
    }

    /** PUT /api/v1/jic/{id} — edit draft saja. */
    public function update($id)
    {
        $row = $this->loadJicForEdit((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau tidak bisa diedit', 404);

        $data = $this->getJsonInput();
        $repository = new JicWorkflowRepository();

        // Hanya boleh edit field tertentu
        $editable = [
            'reason_category', 'reason_text', 'effective_date', 'notice_date',
            'new_position_id', 'new_department_id', 'new_branch_id',
            'new_work_location_id', 'new_job_grade_id', 'new_employment_status',
            'new_basic_salary', 'new_supervisor_id', 'new_manager_id',
            'disciplinary_record_id', 'performance_review_id',
            'mpp_id', 'training_certificate_id', 'ump_override_reason',
        ];
        $update = array_intersect_key($data, array_flip($editable));

        if (! empty($update)) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            $repository->update((int) $row['id'], (int) $row['company_id'], $update);

            Services::audit()->forModule('JIC')->logUpdate(
                'job_information_changes', (int) $row['id'], $row, $update,
                "Edit draft JIC #{$row['id']}",
            );
        }

        return $this->ok(null, 'JIC diperbarui');
    }

    /** DELETE /api/v1/jic/{id} — soft-delete draft saja. */
    public function delete($id)
    {
        $row = $this->loadJicForEdit((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau tidak bisa dihapus', 404);

        (new JicWorkflowRepository())->softDelete((int) $row['id'], (int) $row['company_id']);

        Services::audit()->forModule('JIC')->logDelete(
            'job_information_changes', (int) $row['id'], $row,
            "Hapus draft JIC #{$row['id']}",
        );

        return $this->ok(null, 'JIC dihapus');
    }

    // ─── WORKFLOW ACTIONS ──────────────────────────────────────────

    /** POST /api/v1/jic/{id}/submit — DRAFT → PENDING_SUPERVISOR. */
    public function submit($id)
    {
        $row = $this->loadJicForEdit((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau tidak bisa di-submit', 404);

        try {
            $result = (new JicWorkflowService())->submit($row);
        } catch (JicWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode, $exception->details);
        }
        $jicNumber = $result['jic_number'];

        // Notify supervisor
        (new JicNotificationService())->notifyApprover((int) $row['id'], 'PENDING_SUPERVISOR', (int) $row['company_id']);

        Services::audit()->forModule('JIC')->log(
            action: 'jic.submit',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Submit JIC #{$jicNumber} ke approval",
            severity: 'NOTICE',
        );

        return $this->ok(['jic_number' => $jicNumber, 'status' => 'PENDING_SUPERVISOR'], 'JIC disubmit');
    }

    /**
     * POST /api/v1/jic/{id}/approve
     * Body: { note?: string }
     * Stage di-determine dari status saat ini.
     */
    public function approve($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $data = $this->getJsonInput();
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $userId = $this->userId();
        try {
            $result = (new JicWorkflowService())->approve(
                $row, $userId, $note,
                $this->isSupervisorOf($userId, (int) $row['employee_id']),
                $this->isDeptHeadOf($userId, $row),
                $this->canActAsHr(),
                $this->canActAsBod(),
            );
        } catch (JicWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode, $exception->details);
        }
        if (str_starts_with($result['notification'], 'PENDING_') && $result['notification'] !== 'PENDING_EMPLOYEE_CONSENT') {
            (new JicNotificationService())->notifyApprover((int) $row['id'], $result['notification'], (int) $row['company_id']);
        } elseif ($result['notification'] === 'PENDING_EMPLOYEE_CONSENT') {
            (new JicNotificationService())->notifyEmployeeConsent($row);
        } elseif ($result['notification'] === 'APPROVED') {
            (new JicNotificationService())->notifyEmployeeApproved((int) $row['id'], (int) $row['company_id']);
        }

        Services::audit()->forModule('JIC')->log(
            action: 'jic.approve',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Approve JIC #{$row['id']} di tahap {$row['status']}",
            severity: 'NOTICE',
        );

        return $this->ok(['status' => $result['status'], 'sk_number' => $result['sk_number']], 'Persetujuan tercatat');
    }

    /** POST /api/v1/jic/{id}/reject */
    public function reject($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'note' => 'required|min_length[10]|max_length[2000]',
        ]);
        if (! $valid) return null;

        $userId = $this->userId();
        $stage  = $row['status'];
        try {
            (new JicWorkflowService())->reject(
                $row, $userId, $valid['note'],
                $this->isSupervisorOf($userId, (int) $row['employee_id']),
                $this->isDeptHeadOf($userId, $row),
                $this->canActAsHr(),
                $this->canActAsBod(),
            );
        } catch (JicWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode, $exception->details);
        }

        // Notify initiator + employee
        (new JicNotificationService())->notifyRejected($row, $stage, $valid['note']);

        Services::audit()->forModule('JIC')->log(
            action: 'jic.reject',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Reject JIC #{$row['id']} di tahap {$stage}",
            severity: 'WARNING',
        );

        return $this->ok(['status' => 'REJECTED'], 'JIC ditolak');
    }

    /**
     * POST /api/v1/jic/{id}/consent
     * Body: { decision: AGREED|REJECTED, note?: string, signed_path?: string }
     * Hanya karyawan ybs yang boleh — Pasal 31 UU 13/2003.
     */
    public function consent($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);
        if ($row['status'] !== 'PENDING_EMPLOYEE_CONSENT') {
            return $this->fail('Status saat ini tidak menunggu consent: ' . $row['status'], 409, 'INVALID_STATE');
        }

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'decision'    => 'required|in_list[AGREED,REJECTED]',
            'note'        => 'permit_empty|max_length[2000]',
            'signed_path' => 'permit_empty|max_length[500]',
        ]);
        if (! $valid) return null;

        try {
            $status = (new JicWorkflowService())->consent($row, $this->userId(), $valid);
        } catch (JicWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode, $exception->details);
        }

        Services::audit()->forModule('JIC')->log(
            action: 'jic.consent',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Karyawan " . ($valid['decision'] === 'AGREED' ? 'menyetujui' : 'menolak') . " JIC #{$row['id']}",
            severity: 'WARNING',
            metadata: ['decision' => $valid['decision']],
        );

        if ($valid['decision'] === 'AGREED') {
            (new JicNotificationService())->notifyEmployeeApproved((int) $row['id'], (int) $row['company_id']);
        } else {
            (new JicNotificationService())->notifyRejected($row, 'EMPLOYEE_CONSENT', $valid['note'] ?? 'Karyawan tidak setuju');
        }

        return $this->ok(['status' => $status], 'Persetujuan tercatat');
    }

    /** POST /api/v1/jic/{id}/cancel — initiator atau HR. */
    public function cancel($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        $isHr = $this->canActAsHr();
        $data = $this->getJsonInput();
        try {
            (new JicWorkflowService())->cancel(
                $row, $this->userId(), $isHr, trim((string) ($data['note'] ?? '')) ?: null,
            );
        } catch (JicWorkflowException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode, $exception->details);
        }

        Services::audit()->forModule('JIC')->log(
            action: 'jic.cancel',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Cancel JIC #{$row['id']} oleh " . ($isHr ? 'HR' : 'initiator'),
            severity: 'WARNING',
        );

        return $this->ok(['status' => 'CANCELLED'], 'JIC dibatalkan');
    }

    /**
     * POST /api/v1/jic/{id}/implement — manual apply.
     * Normal-nya cron yang panggil; endpoint ini untuk kasus HR ingin apply
     * lebih awal (mis. effective_date hari ini, ingin segera berlaku).
     */
    public function implement($id)
    {
        $can = $this->tenant();
        if (! ($can->isSuperAdmin() || $can->hasPermission('jic.implement') || $can->hasPermission('jic.manage'))) {
            return $this->fail('Tidak punya akses implement JIC', 403);
        }

        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        // Cek effective_date
        if ($row['effective_date'] > date('Y-m-d')) {
            return $this->fail(
                'Belum jatuh tempo. Effective date: ' . $row['effective_date'],
                422, 'NOT_DUE_YET',
            );
        }

        $result = $this->svc()->apply((int) $row['id'], $this->userId());
        if (! $result['success']) {
            return $this->fail($result['message'], 422);
        }

        return $this->ok($result, $result['message']);
    }

    // ─── HELPERS ───────────────────────────────────────────────────

    private function loadJicForUser(int $id): ?array
    {
        $can = $this->tenant();
        $row = (new JicQueryRepository())->findDetail($id, $this->companyId(), $can->isSuperAdmin());
        if (! $row) return null;

        $userId = $this->userId();

        // Super admin / HR view
        if ($can->isSuperAdmin()) return $row;
        if ($can->hasPermission('jic.view') || $can->hasPermission('jic.manage') || $can->hasPermission('jic.approve')) {
            if ($this->companyId() === (int) $row['company_id']) return $row;
        }
        // Owner (employee atau initiator)
        if ((int) ($row['employee_user_id'] ?? 0) === $userId) return $row;
        if ((int) ($row['created_by'] ?? 0) === $userId) return $row;
        // Approver di salah satu stage
        if ($this->isSupervisorOf($userId, (int) $row['employee_id'])) return $row;
        if ($this->isDeptHeadOf($userId, $row)) return $row;

        return null;
    }

    /** Untuk edit/delete: hanya draft, dan caller adalah initiator atau HR. */
    private function loadJicForEdit(int $id): ?array
    {
        $row = $this->loadJicForUser($id);
        if (! $row) return null;
        if ($row['status'] !== 'DRAFT') return null;

        $isHr = $this->canActAsHr();
        $isInitiator = (int) ($row['created_by'] ?? 0) === $this->userId();
        if (! ($isHr || $isInitiator)) return null;

        return $row;
    }

    private function isSupervisorOf(int $callerUserId, int $employeeId): bool
    {
        return (new JicQueryRepository())->isSupervisor($this->companyId(), $callerUserId, $employeeId);
    }

    /**
     * Cek caller adalah dept head dari old/new department.
     * `departments.manager_employee_id` adalah employees.id (bukan user_id).
     */
    private function isDeptHeadOf(int $callerUserId, array $jic): bool
    {
        return (new JicQueryRepository())->isDepartmentHead($this->companyId(), $callerUserId, $jic);
    }

    private function canActAsHr(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('jic.approve')
            || $t->hasPermission('jic.manage');
    }

    private function canActAsBod(): bool
    {
        $t = $this->tenant();
        // BOD = super admin atau HR manage. Customer bisa pisahkan via
        // permission `jic.manage` yang dialokasikan khusus ke role direksi.
        return $t->isSuperAdmin() || $t->hasPermission('jic.manage');
    }

    /**
     * Build response payload terstruktur untuk detail JIC.
     * Mengubah hasil flat join menjadi nested object yang rapi:
     *   {
     *     ...field JIC,
     *     employee: { ...info dasar + masa kerja },
     *     position_old/new, department_old/new, branch_old/new,
     *     grade_old/new, work_location_old/new,
     *     supervisor_old/new, manager_old/new,
     *     dept_head_old/new, employee_consent: {...},
     *     workflow: { supervisor: {...}, dept_head: {...}, hr: {...},
     *                 bod: {...}, employee_consent: {...}, implementation: {...} }
     *   }
     *
     * Field flat lama (`new_position_name`, `old_dept_name_join`, dst.)
     * tetap disertakan agar frontend lama backward-compatible.
     */
    private function buildDetailPayload(array $row): array
    {
        $companyId = (int) $row['company_id'];
        $repository = new JicQueryRepository();

        // ─── Employee profile ringkas ──────────────────────────────
        // Hindari muat data sensitif (NIK ciphertext) ke FE; hanya
        // info kepegawaian dasar + masa kerja terhitung.
        $emp = $repository->employeeProfile((int) $row['employee_id'], $companyId) ?? [];

        // Hitung masa kerja (years.months) dari join_date sampai sekarang
        // atau resign_date kalau sudah resign.
        $tenure = null;
        if (! empty($emp['join_date'])) {
            $start = new \DateTime($emp['join_date']);
            $end   = ! empty($emp['resign_date'])
                ? new \DateTime($emp['resign_date'])
                : new \DateTime();
            $diff  = $start->diff($end);
            $tenure = [
                'years'           => $diff->y,
                'months'          => $diff->m,
                'days'            => $diff->d,
                'human'           => trim(
                    ($diff->y ? "{$diff->y} thn " : '')
                    . ($diff->m ? "{$diff->m} bln" : '')
                ) ?: 'kurang dari 1 bulan',
            ];
        }

        // ─── Dept head (dari dept lama / baru) ─────────────────────
        $deptHeadOld = $repository->departmentHead((int) ($row['old_department_id'] ?? 0), $companyId);
        $deptHeadNew = $repository->departmentHead((int) ($row['new_department_id'] ?? 0), $companyId);

        // ─── Supervisor & Manager detail ───────────────────────────
        $supervisorOld = $repository->employeeDetail((int) ($row['old_supervisor_id'] ?? 0), $companyId);
        $supervisorNew = $repository->employeeDetail((int) ($row['new_supervisor_id'] ?? 0), $companyId);
        $managerOld    = $repository->employeeDetail((int) ($row['old_manager_id'] ?? 0), $companyId);
        $managerNew    = $repository->employeeDetail((int) ($row['new_manager_id'] ?? 0), $companyId);

        // ─── Work location detail ──────────────────────────────────
        $workLocOld = $repository->workLocation((int) ($row['old_work_location_id'] ?? 0), $companyId);
        $workLocNew = $repository->workLocation((int) ($row['new_work_location_id'] ?? 0), $companyId);

        // ─── Initiator user ────────────────────────────────────────
        $initiator = $repository->initiator((int) ($row['created_by'] ?? 0), $companyId);

        // ─── Workflow trail terstruktur ────────────────────────────
        $workflow = [
            'supervisor' => [
                'user'        => $repository->actor((int) ($row['supervisor_user_id'] ?? 0), $companyId),
                'decision'    => $row['supervisor_decision'] ?? null,
                'action_at'   => $row['supervisor_action_at'] ?? null,
                'note'        => $row['supervisor_note'] ?? null,
            ],
            'dept_head' => [
                'user'        => $repository->actor((int) ($row['dept_head_user_id'] ?? 0), $companyId),
                'decision'    => $row['dept_head_decision'] ?? null,
                'action_at'   => $row['dept_head_action_at'] ?? null,
                'note'        => $row['dept_head_note'] ?? null,
            ],
            'hr' => [
                'user'        => $repository->actor((int) ($row['hr_user_id'] ?? 0), $companyId),
                'decision'    => $row['hr_decision'] ?? null,
                'action_at'   => $row['hr_action_at'] ?? null,
                'note'        => $row['hr_note'] ?? null,
            ],
            'bod' => [
                'user'        => $repository->actor((int) ($row['bod_user_id'] ?? 0), $companyId),
                'decision'    => $row['bod_decision'] ?? null,
                'action_at'   => $row['bod_action_at'] ?? null,
                'note'        => $row['bod_note'] ?? null,
            ],
            'employee_consent' => [
                'required'    => (int) ($row['employee_consent_required'] ?? 0) === 1,
                'status'      => $row['employee_consent_status'] ?? 'NOT_REQUIRED',
                'action_at'   => $row['employee_consent_at'] ?? null,
                'note'        => $row['employee_consent_note'] ?? null,
                'signed_path' => $row['employee_consent_signed_path'] ?? null,
            ],
            'implementation' => [
                'implemented_at'  => $row['implemented_at'] ?? null,
                'implemented_by'  => $repository->actor((int) ($row['implemented_by'] ?? 0), $companyId),
                'note'            => $row['implementation_note'] ?? null,
            ],
            'cancellation' => $row['cancelled_at'] ? [
                'cancelled_at' => $row['cancelled_at'],
                'cancelled_by' => $repository->actor((int) ($row['cancelled_by'] ?? 0), $companyId),
                'reason'       => $row['cancellation_reason'] ?? null,
            ] : null,
        ];

        // ─── Compose final payload ─────────────────────────────────
        return [
            // Field flat dari $row tetap disertakan agar BC.
            ...$row,

            // Nested employee profile
            'employee' => $emp ? [
                'id'                => (int) $emp['id'],
                'employee_number'   => $emp['employee_number'],
                'full_name'         => $emp['full_name'],
                'gender'            => $emp['gender'],
                'email'             => $emp['email'],
                'phone'             => $emp['phone'],
                'avatar_path'       => $emp['avatar_path'],
                'is_active'         => (int) $emp['is_active'] === 1,
                'join_date'         => $emp['join_date'],
                'tenure'            => $tenure,
                'probation_end_date'=> $emp['probation_end_date'],
                'contract_end_date' => $emp['contract_end_date'],
                'resign_date'       => $emp['resign_date'],
                'employment_status' => $emp['employment_status'],
                'employment_type'   => $emp['employment_type'],
                'education_level'   => $emp['education_level'],
                'marital_status'    => $emp['marital_status'],
                'tax_method'        => $emp['tax_method'],
                'ptkp_status'       => $emp['ptkp_status'],
                'current'           => [
                    'branch'        => $emp['branch_name'],
                    'department'    => $emp['department_name'],
                    'position'      => $emp['position_name'],
                    'position_tier' => $emp['position_tier'],
                    'grade'         => $emp['grade_name'],
                    'work_location' => $emp['work_location_name'],
                    'basic_salary'  => $emp['basic_salary'] !== null ? (float) $emp['basic_salary'] : null,
                ],
            ] : null,

            // Side-by-side blocks
            'old' => [
                'position'      => [
                    'id'              => $row['old_position_id'] ? (int) $row['old_position_id'] : null,
                    'name'            => $row['old_position_name_join'] ?? $row['old_position_name'] ?? null,
                    'name_snapshot'   => $row['old_position_name'] ?? null,
                ],
                'department'    => [
                    'id'   => $row['old_department_id'] ? (int) $row['old_department_id'] : null,
                    'name' => $row['old_dept_name_join'] ?? $row['old_department_name'] ?? null,
                    'name_snapshot' => $row['old_department_name'] ?? null,
                ],
                'branch'        => [
                    'id'   => $row['old_branch_id'] ? (int) $row['old_branch_id'] : null,
                    'name' => $row['old_branch_name'] ?? null,
                ],
                'job_grade'     => [
                    'id'   => $row['old_job_grade_id'] ? (int) $row['old_job_grade_id'] : null,
                    'name' => $row['old_grade_name_join'] ?? $row['old_job_grade_name'] ?? null,
                    'name_snapshot' => $row['old_job_grade_name'] ?? null,
                ],
                'work_location' => $workLocOld,
                'employment_status' => $row['old_employment_status'] ?? null,
                'basic_salary'      => $row['old_basic_salary'] !== null ? (float) $row['old_basic_salary'] : null,
                'supervisor'        => $supervisorOld,
                'manager'           => $managerOld,
                'department_head'   => $deptHeadOld,
            ],
            'new' => [
                'position'      => [
                    'id'   => $row['new_position_id'] ? (int) $row['new_position_id'] : null,
                    'name' => $row['new_position_name'] ?? null,
                    'tier' => $row['new_position_tier'] ?? null,
                ],
                'department'    => [
                    'id'   => $row['new_department_id'] ? (int) $row['new_department_id'] : null,
                    'name' => $row['new_dept_name'] ?? null,
                ],
                'branch'        => [
                    'id'   => $row['new_branch_id'] ? (int) $row['new_branch_id'] : null,
                    'name' => $row['new_branch_name'] ?? null,
                ],
                'job_grade'     => [
                    'id'   => $row['new_job_grade_id'] ? (int) $row['new_job_grade_id'] : null,
                    'name' => $row['new_grade_name'] ?? null,
                ],
                'work_location' => $workLocNew,
                'employment_status' => $row['new_employment_status'] ?? null,
                'basic_salary'      => $row['new_basic_salary'] !== null ? (float) $row['new_basic_salary'] : null,
                'supervisor'        => $supervisorNew,
                'manager'           => $managerNew,
                'department_head'   => $deptHeadNew,
            ],

            'compliance' => [
                'salary_change_pct' => $row['salary_change_pct'] !== null ? (float) $row['salary_change_pct'] : null,
                'ump_check_status'  => $row['ump_check_status'] ?? null,
                'ump_reference'     => $row['ump_reference'] !== null ? (float) $row['ump_reference'] : null,
                'ump_override_reason' => $row['ump_override_reason'] ?? null,
                'employee_consent_required' => (int) ($row['employee_consent_required'] ?? 0) === 1,
                'employee_consent_status'   => $row['employee_consent_status'] ?? 'NOT_REQUIRED',
            ],

            'workflow'  => $workflow,
            'initiator' => $initiator,
        ];
    }

    private function changeTypeLabel(string $code): string
    {
        return match ($code) {
            'PROMOTION'         => 'Promosi',
            'DEMOTION'          => 'Demosi',
            'LATERAL'           => 'Mutasi (Lateral)',
            'REORG'             => 'Reorganisasi',
            'SALARY_ADJUSTMENT' => 'Penyesuaian Upah',
            'STATUS_CHANGE'     => 'Perubahan Status Kepegawaian',
            default             => $code,
        };
    }

    private function reasonLabel(string $code): string
    {
        return match ($code) {
            'PERFORMANCE'      => 'Hasil performance review',
            'MERIT'            => 'Penghargaan kinerja (merit)',
            'TENURE'           => 'Masa kerja / senioritas',
            'COMPETENCY'       => 'Kompetensi / sertifikasi',
            'RESTRUCTURE'      => 'Restrukturisasi organisasi',
            'DISCIPLINARY'     => 'Konsekuensi disipliner (SP)',
            'EMPLOYEE_REQUEST' => 'Permintaan karyawan',
            'PROBATION_END'    => 'Lulus masa percobaan',
            'CONTRACT_RENEWAL' => 'Perpanjangan PKWT',
            'UMP_ADJUSTMENT'   => 'Penyesuaian UMP/UMK',
            'OTHER'            => 'Lainnya',
            default            => $code,
        };
    }
}
