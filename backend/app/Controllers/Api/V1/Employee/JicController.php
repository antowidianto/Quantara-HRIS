<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Employee\JicService;
use App\Repositories\Employee\JicQueryRepository;
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

        $db   = \Config\Database::connect();
        $svc  = $this->svc();
        $now  = date('Y-m-d H:i:s');
        $cid  = $this->companyId();

        // Cek employee ada & dalam company yg sama
        $emp = $db->table('employees')->where('id', (int) $valid['employee_id'])
            ->where('company_id', $cid)
            ->where('deleted_at IS NULL')->get()->getRowArray();
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
        $pendingExists = $db->table('job_information_changes')
            ->where('company_id', (int) ($cid ?: $emp['company_id']))
            ->where('employee_id', (int) $emp['id'])
            ->whereIn('status', [
                'DRAFT', 'PENDING_SUPERVISOR', 'PENDING_DEPT_HEAD',
                'PENDING_HR', 'PENDING_BOD', 'PENDING_EMPLOYEE_CONSENT', 'APPROVED',
            ])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($pendingExists > 0) {
            return $this->fail(
                'Sudah ada JIC aktif untuk karyawan ini. Selesaikan/batalkan dulu.',
                409, 'DUPLICATE_REQUEST'
            );
        }

        // Snapshot lama
        $snapshot = $svc->snapshotEmployee((int) $emp['id']);

        // Resolve new tier (kalau new_position_id ada)
        $newTier = $snapshot['old_position_tier']; // default sama
        if (! empty($data['new_position_id'])) {
            $row = $db->table('positions')->select('tier')
                ->where('company_id', (int) ($cid ?: $emp['company_id']))
                ->where('id', (int) $data['new_position_id'])->get()->getRowArray();
            if ($row) $newTier = $row['tier'] ?? 'STAFF';
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

        $db->table('job_information_changes')->insert($insert);
        $jicId = (int) $db->insertID();

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
        $db   = \Config\Database::connect();

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
            $db->table('job_information_changes')->where('id', (int) $row['id'])
                ->where('company_id', (int) $row['company_id'])->update($update);

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

        \Config\Database::connect()
            ->table('job_information_changes')->where('id', (int) $row['id'])
            ->where('company_id', (int) $row['company_id'])->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

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

        // Validasi compliance sebelum submit
        $svc    = $this->svc();
        $errors = $svc->validateSupportingDocuments($row);

        // UMP check
        if ($row['ump_check_status'] === 'FAIL' && empty($row['ump_override_reason'])) {
            $errors[] = sprintf(
                'Upah baru (%s) di bawah UMP rujukan (%s). Isi `ump_override_reason` atau naikkan upah baru.',
                number_format((float) $row['new_basic_salary'], 0, ',', '.'),
                number_format((float) $row['ump_reference'], 0, ',', '.'),
            );
        }
        if (! empty($errors)) {
            return $this->fail('Validasi gagal', 422, 'COMPLIANCE_FAILED', $errors);
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $jicNumber = $row['jic_number'] ?: $svc->generateJicNumber((int) $row['company_id']);
        $update = [
            'jic_number'   => $jicNumber,
            'status'       => 'PENDING_SUPERVISOR',
            'submitted_at' => $now,
            'updated_at'   => $now,
        ];
        // Auto-bypass kalau override UMP
        if ($row['ump_check_status'] === 'FAIL' && ! empty($row['ump_override_reason'])) {
            $update['ump_check_status'] = 'OVERRIDE';
        }

        $db->table('job_information_changes')->where('id', (int) $row['id'])
            ->where('company_id', (int) $row['company_id'])->update($update);

        // Notify supervisor
        $this->notifyApprover((int) $row['id'], 'PENDING_SUPERVISOR', (int) $row['company_id']);

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
        $now  = date('Y-m-d H:i:s');
        $db   = \Config\Database::connect();
        $userId = $this->userId();
        $svc  = $this->svc();

        switch ($row['status']) {
            case 'PENDING_SUPERVISOR':
                if (! $this->isSupervisorOf($userId, (int) $row['employee_id'])) {
                    return $this->fail('Hanya atasan langsung yang dapat menyetujui di tahap ini', 403);
                }
                $db->table('job_information_changes')->where('id', (int) $row['id'])
                    ->where('company_id', (int) $row['company_id'])->update([
                    'status'              => 'PENDING_DEPT_HEAD',
                    'supervisor_user_id'  => $userId,
                    'supervisor_action_at'=> $now,
                    'supervisor_decision' => 'APPROVED',
                    'supervisor_note'     => $note,
                    'updated_at'          => $now,
                ]);
                $this->notifyApprover((int) $row['id'], 'PENDING_DEPT_HEAD', (int) $row['company_id']);
                break;

            case 'PENDING_DEPT_HEAD':
                if (! $this->isDeptHeadOf($userId, $row)) {
                    return $this->fail('Hanya kepala departemen terkait yang dapat menyetujui di tahap ini', 403);
                }
                $db->table('job_information_changes')->where('id', (int) $row['id'])
                    ->where('company_id', (int) $row['company_id'])->update([
                    'status'              => 'PENDING_HR',
                    'dept_head_user_id'   => $userId,
                    'dept_head_action_at' => $now,
                    'dept_head_decision'  => 'APPROVED',
                    'dept_head_note'      => $note,
                    'updated_at'          => $now,
                ]);
                $this->notifyApprover((int) $row['id'], 'PENDING_HR', (int) $row['company_id']);
                break;

            case 'PENDING_HR':
                if (! $this->canActAsHr()) {
                    return $this->fail('Hanya HR yang dapat menyetujui di tahap ini', 403);
                }

                // Tentukan next stage berdasar requirement BOD
                $newTier = null;
                if (! empty($row['new_position_id'])) {
                    $r = $db->table('positions')->select('tier')
                        ->where('id', (int) $row['new_position_id'])->get()->getRowArray();
                    if ($r) $newTier = $r['tier'] ?? null;
                }
                $needsBod = $svc->requiresBodApproval(
                    $row['change_type'], $newTier, (float) ($row['salary_change_pct'] ?? 0),
                );
                $needsConsent = ((int) ($row['employee_consent_required'] ?? 0) === 1)
                    && ($row['employee_consent_status'] !== 'AGREED');

                $nextStatus = $needsBod
                    ? 'PENDING_BOD'
                    : ($needsConsent ? 'PENDING_EMPLOYEE_CONSENT' : 'APPROVED');

                $update = [
                    'status'       => $nextStatus,
                    'hr_user_id'   => $userId,
                    'hr_action_at' => $now,
                    'hr_decision'  => 'APPROVED',
                    'hr_note'      => $note,
                    'updated_at'   => $now,
                ];

                // Generate SK number kalau langsung APPROVED
                if ($nextStatus === 'APPROVED' && empty($row['sk_number'])) {
                    $update['sk_number']       = $this->generateSkNumber((int) $row['company_id']);
                    $update['sk_generated_at'] = $now;
                }

                $db->table('job_information_changes')->where('id', (int) $row['id'])
                    ->where('company_id', (int) $row['company_id'])->update($update);

                if ($nextStatus === 'PENDING_BOD') {
                    $this->notifyApprover((int) $row['id'], 'PENDING_BOD', (int) $row['company_id']);
                } elseif ($nextStatus === 'PENDING_EMPLOYEE_CONSENT') {
                    $this->notifyEmployeeConsent($row);
                } else {
                    $this->notifyEmployeeApproved((int) $row['id'], (int) $row['company_id']);
                }
                break;

            case 'PENDING_BOD':
                if (! $this->canActAsBod()) {
                    return $this->fail('Hanya direksi/super admin yang dapat menyetujui di tahap ini', 403);
                }
                $needsConsent = ((int) ($row['employee_consent_required'] ?? 0) === 1)
                    && ($row['employee_consent_status'] !== 'AGREED');
                $nextStatus = $needsConsent ? 'PENDING_EMPLOYEE_CONSENT' : 'APPROVED';

                $update = [
                    'status'        => $nextStatus,
                    'bod_user_id'   => $userId,
                    'bod_action_at' => $now,
                    'bod_decision'  => 'APPROVED',
                    'bod_note'      => $note,
                    'updated_at'    => $now,
                ];
                if ($nextStatus === 'APPROVED' && empty($row['sk_number'])) {
                    $update['sk_number']       = $this->generateSkNumber((int) $row['company_id']);
                    $update['sk_generated_at'] = $now;
                }
                $db->table('job_information_changes')->where('id', (int) $row['id'])
                    ->where('company_id', (int) $row['company_id'])->update($update);

                if ($nextStatus === 'PENDING_EMPLOYEE_CONSENT') {
                    $this->notifyEmployeeConsent($row);
                } else {
                    $this->notifyEmployeeApproved((int) $row['id'], (int) $row['company_id']);
                }
                break;

            default:
                return $this->fail('Status saat ini tidak bisa di-approve: ' . $row['status'], 409, 'INVALID_STATE');
        }

        Services::audit()->forModule('JIC')->log(
            action: 'jic.approve',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Approve JIC #{$row['id']} di tahap {$row['status']}",
            severity: 'NOTICE',
        );

        $newRow = $db->table('job_information_changes')->where('id', (int) $row['id'])
            ->where('company_id', (int) $row['company_id'])->get()->getRowArray();
        return $this->ok(['status' => $newRow['status'], 'sk_number' => $newRow['sk_number'] ?? null], 'Persetujuan tercatat');
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

        $now    = date('Y-m-d H:i:s');
        $userId = $this->userId();
        $db     = \Config\Database::connect();
        $stage  = $row['status'];

        $update = ['status' => 'REJECTED', 'updated_at' => $now];

        switch ($stage) {
            case 'PENDING_SUPERVISOR':
                if (! $this->isSupervisorOf($userId, (int) $row['employee_id'])) {
                    return $this->fail('Hanya atasan langsung yang bisa menolak', 403);
                }
                $update += [
                    'supervisor_user_id'  => $userId,
                    'supervisor_action_at'=> $now,
                    'supervisor_decision' => 'REJECTED',
                    'supervisor_note'     => $valid['note'],
                ];
                break;
            case 'PENDING_DEPT_HEAD':
                if (! $this->isDeptHeadOf($userId, $row)) {
                    return $this->fail('Hanya kepala departemen yang bisa menolak', 403);
                }
                $update += [
                    'dept_head_user_id'   => $userId,
                    'dept_head_action_at' => $now,
                    'dept_head_decision'  => 'REJECTED',
                    'dept_head_note'      => $valid['note'],
                ];
                break;
            case 'PENDING_HR':
                if (! $this->canActAsHr()) return $this->fail('Hanya HR yang bisa menolak', 403);
                $update += [
                    'hr_user_id'   => $userId,
                    'hr_action_at' => $now,
                    'hr_decision'  => 'REJECTED',
                    'hr_note'      => $valid['note'],
                ];
                break;
            case 'PENDING_BOD':
                if (! $this->canActAsBod()) return $this->fail('Hanya direksi yang bisa menolak', 403);
                $update += [
                    'bod_user_id'   => $userId,
                    'bod_action_at' => $now,
                    'bod_decision'  => 'REJECTED',
                    'bod_note'      => $valid['note'],
                ];
                break;
            default:
                return $this->fail('Status saat ini tidak bisa di-reject: ' . $stage, 409, 'INVALID_STATE');
        }

        $db->table('job_information_changes')->where('id', (int) $row['id'])
            ->where('company_id', (int) $row['company_id'])->update($update);

        // Notify initiator + employee
        $this->notifyRejected($row, $stage, $valid['note']);

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

        // Pastikan caller adalah karyawan ybs
        $db  = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if (! $emp || (int) ($emp['user_id'] ?? 0) !== $this->userId()) {
            return $this->fail('Hanya karyawan yang bersangkutan yang dapat memberi persetujuan', 403);
        }

        $data  = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'decision'    => 'required|in_list[AGREED,REJECTED]',
            'note'        => 'permit_empty|max_length[2000]',
            'signed_path' => 'permit_empty|max_length[500]',
        ]);
        if (! $valid) return null;

        $now = date('Y-m-d H:i:s');
        $update = [
            'employee_consent_status'      => $valid['decision'],
            'employee_consent_at'          => $now,
            'employee_consent_note'        => $valid['note'] ?? null,
            'employee_consent_signed_path' => $valid['signed_path'] ?? null,
            'updated_at'                   => $now,
        ];

        if ($valid['decision'] === 'AGREED') {
            // Move ke APPROVED
            $update['status'] = 'APPROVED';
            if (empty($row['sk_number'])) {
                $update['sk_number']       = $this->generateSkNumber((int) $row['company_id']);
                $update['sk_generated_at'] = $now;
            }
        } else {
            // Penolakan karyawan = JIC otomatis ditolak.
            // Sesuai asas konsensual, perusahaan tidak boleh paksakan demosi
            // tanpa consent (kecuali disipliner — yang reason-nya beda).
            $update['status'] = 'REJECTED';
            $update['hr_decision'] = 'REJECTED';
            $update['hr_note']     = ($update['hr_note'] ?? '') . "\n[Auto-reject] Karyawan menolak demosi: " . ($valid['note'] ?? '');
        }

        $db->table('job_information_changes')->where('id', (int) $row['id'])
            ->where('company_id', (int) $row['company_id'])->update($update);

        Services::audit()->forModule('JIC')->log(
            action: 'jic.consent',
            entity: 'job_information_changes',
            entityId: (int) $row['id'],
            description: "Karyawan " . ($valid['decision'] === 'AGREED' ? 'menyetujui' : 'menolak') . " JIC #{$row['id']}",
            severity: 'WARNING',
            metadata: ['decision' => $valid['decision']],
        );

        if ($valid['decision'] === 'AGREED') {
            $this->notifyEmployeeApproved((int) $row['id'], (int) $row['company_id']);
        } else {
            $this->notifyRejected($row, 'EMPLOYEE_CONSENT', $valid['note'] ?? 'Karyawan tidak setuju');
        }

        return $this->ok(['status' => $update['status']], 'Persetujuan tercatat');
    }

    /** POST /api/v1/jic/{id}/cancel — initiator atau HR. */
    public function cancel($id)
    {
        $row = $this->loadJicForUser((int) $id);
        if (! $row) return $this->fail('Tidak ditemukan atau akses ditolak', 404);

        if (in_array($row['status'], ['IMPLEMENTED', 'CANCELLED', 'REJECTED'], true)) {
            return $this->fail('Tidak bisa cancel di status ' . $row['status'], 409, 'INVALID_STATE');
        }

        $isHr = $this->canActAsHr();
        $isInitiator = (int) ($row['created_by'] ?? 0) === $this->userId();
        if (! ($isHr || $isInitiator)) {
            return $this->fail('Hanya pembuat atau HR yang dapat membatalkan', 403);
        }
        // Setelah APPROVED, hanya HR yang bisa cancel
        if ($row['status'] === 'APPROVED' && ! $isHr) {
            return $this->fail('Setelah APPROVED hanya HR yang dapat membatalkan', 403);
        }

        $data = $this->getJsonInput();
        $now  = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('job_information_changes')
            ->where('id', (int) $row['id'])->where('company_id', (int) $row['company_id'])->update([
                'status'              => 'CANCELLED',
                'cancelled_at'        => $now,
                'cancelled_by'        => $this->userId(),
                'cancellation_reason' => trim((string) ($data['note'] ?? '')) ?: null,
                'updated_at'          => $now,
            ]);

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

    private function generateSkNumber(int $companyId): string
    {
        $db    = \Config\Database::connect();
        $year  = date('Y');
        $month = date('m');
        $like  = "SK-JIC/{$year}/{$month}/%";
        $row   = $db->table('job_information_changes')
            ->select('sk_number')
            ->like('sk_number', $like, 'after')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->limit(1)->get()->getRowArray();
        $next = 1;
        if ($row && preg_match('#/(\d+)$#', $row['sk_number'] ?? '', $m)) {
            $next = (int) $m[1] + 1;
        }
        return sprintf('SK-JIC/%s/%s/%04d', $year, $month, $next);
    }

    private function notifyApprover(int $jicId, string $stage, int $companyId): void
    {
        $db  = \Config\Database::connect();
        $row = $db->table('job_information_changes jic')
            ->select('jic.*, e.full_name AS employee_name, e.supervisor_id, e.manager_id, e.department_id')
            ->join('employees e', 'e.id = jic.employee_id AND e.company_id = jic.company_id', 'left')
            ->where('jic.id', $jicId)->where('jic.company_id', $companyId)->get()->getRowArray();
        if (! $row) return;

        $title = 'Persetujuan JIC diperlukan';
        $body  = sprintf(
            'Pengajuan %s untuk %s, efektif %s.',
            $this->changeTypeLabel($row['change_type']),
            $row['employee_name'] ?? 'karyawan',
            $row['effective_date'],
        );
        $payload = [
            'jic_id'      => $jicId,
            'jic_number'  => $row['jic_number'],
            'change_type' => $row['change_type'],
            'stage'       => $stage,
        ];

        $targets = [];
        switch ($stage) {
            case 'PENDING_SUPERVISOR':
                $supEmpId = (int) ($row['supervisor_id'] ?? 0) ?: (int) ($row['manager_id'] ?? 0);
                if ($supEmpId > 0) {
                    $r = $db->table('employees')->select('user_id')
                        ->where('id', $supEmpId)->where('company_id', $companyId)->get()->getRowArray();
                    if ($r && ! empty($r['user_id'])) $targets[] = (int) $r['user_id'];
                }
                break;
            case 'PENDING_DEPT_HEAD':
                $depts = array_filter([
                    (int) ($row['old_department_id'] ?? 0),
                    (int) ($row['new_department_id'] ?? 0),
                ]);
                if (! empty($depts)) {
                    $managers = $db->table('departments d')
                        ->select('e.user_id')
                        ->join('employees e', 'e.id = d.manager_employee_id AND e.company_id = d.company_id', 'left')
                        ->whereIn('d.id', $depts)
                        ->where('d.company_id', $companyId)
                        ->get()->getResultArray();
                    foreach ($managers as $m) {
                        if (! empty($m['user_id'])) $targets[] = (int) $m['user_id'];
                    }
                }
                break;
            case 'PENDING_HR':
            case 'PENDING_BOD':
                $perm = $stage === 'PENDING_BOD' ? 'jic.manage' : 'jic.approve';
                $hrUsers = $db->table('users u')
                    ->select('u.id')
                    ->join('role_permissions rp', 'rp.role_id = u.role_id', 'left')
                    ->join('permissions p',       'p.id = rp.permission_id', 'left')
                    ->where('u.company_id', (int) $row['company_id'])
                    ->where('u.is_active', 1)
                    ->where('p.slug', $perm)
                    ->groupBy('u.id')->get()->getResultArray();
                foreach ($hrUsers as $u) $targets[] = (int) $u['id'];
                break;
        }

        $targets = array_unique(array_filter($targets));
        foreach ($targets as $uid) {
            Services::notification()->send(
                $uid, (int) $row['company_id'],
                'jic.pending', $title, $body, $payload,
                null, true,
            );
        }
    }

    private function notifyEmployeeConsent(array $row): void
    {
        $db  = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])
            ->where('company_id', (int) $row['company_id'])->get()->getRowArray();
        if (! $emp || empty($emp['user_id'])) return;

        Services::notification()->send(
            (int) $emp['user_id'], (int) $row['company_id'],
            'jic.consent_required',
            'Persetujuan Anda dibutuhkan',
            'HR mengajukan demosi yang menyangkut posisi/upah Anda. '
            . 'Silakan baca detail dan berikan persetujuan/penolakan.',
            ['jic_id' => (int) $row['id'], 'jic_number' => $row['jic_number']],
            null, true,
        );
    }

    private function notifyEmployeeApproved(int $jicId, int $companyId): void
    {
        $db  = \Config\Database::connect();
        $row = $db->table('job_information_changes')->where('id', $jicId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) return;
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $emp || empty($emp['user_id'])) return;

        Services::notification()->send(
            (int) $emp['user_id'], (int) $row['company_id'],
            'jic.approved',
            'JIC Anda disetujui',
            sprintf(
                'Pengajuan %s untuk Anda telah disetujui (SK %s). Berlaku efektif %s.',
                $this->changeTypeLabel($row['change_type']),
                $row['sk_number'] ?? '-',
                $row['effective_date'],
            ),
            [
                'jic_id'      => $jicId,
                'jic_number'  => $row['jic_number'],
                'sk_number'   => $row['sk_number'],
                'change_type' => $row['change_type'],
            ],
            null, true,
        );
    }

    private function notifyRejected(array $row, string $stage, string $note): void
    {
        $db  = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])
            ->where('company_id', (int) $row['company_id'])->get()->getRowArray();
        $targets = [];
        if ($emp && ! empty($emp['user_id'])) $targets[] = (int) $emp['user_id'];
        if (! empty($row['created_by'])) $targets[] = (int) $row['created_by'];

        $targets = array_unique(array_filter($targets));
        foreach ($targets as $uid) {
            Services::notification()->send(
                $uid, (int) $row['company_id'],
                'jic.rejected',
                'JIC ditolak',
                "Pengajuan JIC #{$row['id']} ditolak di tahap {$stage}. Alasan: " . mb_substr($note, 0, 200),
                ['jic_id' => (int) $row['id'], 'stage' => $stage],
                null, true,
            );
        }
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
        $db = \Config\Database::connect();

        // ─── Employee profile ringkas ──────────────────────────────
        // Hindari muat data sensitif (NIK ciphertext) ke FE; hanya
        // info kepegawaian dasar + masa kerja terhitung.
        $emp = $db->table('employees e')
            ->select(
                'e.id, e.employee_number, e.full_name, e.first_name, e.last_name, '
                . 'e.gender, e.email, e.phone, e.avatar_path, e.user_id, '
                . 'e.employment_status, e.employment_type, e.is_active, '
                . 'e.join_date, e.probation_end_date, e.contract_end_date, '
                . 'e.resign_date, e.basic_salary, e.tax_method, e.ptkp_status, '
                . 'e.education_level, e.marital_status, '
                . 'b.name AS branch_name, '
                . 'd.name AS department_name, '
                . 'p.name AS position_name, p.tier AS position_tier, '
                . 'g.name AS grade_name, '
                . 'wl.name AS work_location_name, wl.type AS work_location_type'
            )
            ->join('branches b',     'b.id = e.branch_id',          'left')
            ->join('departments d',  'd.id = e.department_id',      'left')
            ->join('positions p',    'p.id = e.position_id',        'left')
            ->join('job_grades g',   'g.id = e.job_grade_id',       'left')
            ->join('work_locations wl', 'wl.id = e.work_location_id', 'left')
            ->where('e.id', (int) $row['employee_id'])
            ->get()->getRowArray() ?: [];

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
        $deptHeadOld = $this->loadDeptHeadDetail($db, (int) ($row['old_department_id'] ?? 0));
        $deptHeadNew = $this->loadDeptHeadDetail($db, (int) ($row['new_department_id'] ?? 0));

        // ─── Supervisor & Manager detail ───────────────────────────
        $supervisorOld = $this->loadEmployeeDetail($db, (int) ($row['old_supervisor_id'] ?? 0));
        $supervisorNew = $this->loadEmployeeDetail($db, (int) ($row['new_supervisor_id'] ?? 0));
        $managerOld    = $this->loadEmployeeDetail($db, (int) ($row['old_manager_id'] ?? 0));
        $managerNew    = $this->loadEmployeeDetail($db, (int) ($row['new_manager_id'] ?? 0));

        // ─── Work location detail ──────────────────────────────────
        $workLocOld = null;
        if (! empty($row['old_work_location_id'])) {
            $workLocOld = $db->table('work_locations')->select('id, code, name, type, address, city, province')
                ->where('id', (int) $row['old_work_location_id'])->get()->getRowArray();
        }
        $workLocNew = null;
        if (! empty($row['new_work_location_id'])) {
            $workLocNew = $db->table('work_locations')->select('id, code, name, type, address, city, province')
                ->where('id', (int) $row['new_work_location_id'])->get()->getRowArray();
        }

        // ─── Initiator user ────────────────────────────────────────
        $initiator = null;
        if (! empty($row['created_by'])) {
            $u = $db->table('users u')
                ->select('u.id, u.name, u.email, e.full_name AS employee_name, e.employee_number')
                ->join('employees e', 'e.user_id = u.id', 'left')
                ->where('u.id', (int) $row['created_by'])
                ->get()->getRowArray();
            $initiator = $u ?: null;
        }

        // ─── Workflow trail terstruktur ────────────────────────────
        $workflow = [
            'supervisor' => [
                'user'        => $this->resolveActor($db, $row['supervisor_user_id'] ?? null),
                'decision'    => $row['supervisor_decision'] ?? null,
                'action_at'   => $row['supervisor_action_at'] ?? null,
                'note'        => $row['supervisor_note'] ?? null,
            ],
            'dept_head' => [
                'user'        => $this->resolveActor($db, $row['dept_head_user_id'] ?? null),
                'decision'    => $row['dept_head_decision'] ?? null,
                'action_at'   => $row['dept_head_action_at'] ?? null,
                'note'        => $row['dept_head_note'] ?? null,
            ],
            'hr' => [
                'user'        => $this->resolveActor($db, $row['hr_user_id'] ?? null),
                'decision'    => $row['hr_decision'] ?? null,
                'action_at'   => $row['hr_action_at'] ?? null,
                'note'        => $row['hr_note'] ?? null,
            ],
            'bod' => [
                'user'        => $this->resolveActor($db, $row['bod_user_id'] ?? null),
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
                'implemented_by'  => $this->resolveActor($db, $row['implemented_by'] ?? null),
                'note'            => $row['implementation_note'] ?? null,
            ],
            'cancellation' => $row['cancelled_at'] ? [
                'cancelled_at' => $row['cancelled_at'],
                'cancelled_by' => $this->resolveActor($db, $row['cancelled_by'] ?? null),
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

    /**
     * Lookup detail karyawan ringkas (untuk supervisor/manager/initiator).
     *
     * @return array{
     *   employee_id:int, employee_number:?string, full_name:?string,
     *   position:?string, department:?string, email:?string, phone:?string
     * }|null
     */
    private function loadEmployeeDetail(\CodeIgniter\Database\BaseConnection $db, int $employeeId): ?array
    {
        if ($employeeId <= 0) return null;
        $row = $db->table('employees e')
            ->select(
                'e.id, e.employee_number, e.full_name, e.email, e.phone, e.user_id, '
                . 'p.name AS position_name, p.tier AS position_tier, '
                . 'd.name AS department_name'
            )
            ->join('positions p',   'p.id = e.position_id',   'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.id', $employeeId)
            ->get()->getRowArray();
        if (! $row) return null;

        return [
            'employee_id'     => (int) $row['id'],
            'employee_number' => $row['employee_number'],
            'full_name'       => $row['full_name'],
            'email'           => $row['email'],
            'phone'           => $row['phone'],
            'position'        => $row['position_name'],
            'position_tier'   => $row['position_tier'],
            'department'      => $row['department_name'],
            'user_id'         => $row['user_id'] ? (int) $row['user_id'] : null,
        ];
    }

    /** Lookup kepala departemen (manager_employee_id) lengkap dengan jabatan. */
    private function loadDeptHeadDetail(\CodeIgniter\Database\BaseConnection $db, int $departmentId): ?array
    {
        if ($departmentId <= 0) return null;
        $dept = $db->table('departments')->select('manager_employee_id')
            ->where('id', $departmentId)->get()->getRowArray();
        if (! $dept || empty($dept['manager_employee_id'])) return null;
        return $this->loadEmployeeDetail($db, (int) $dept['manager_employee_id']);
    }

    /** Resolve user ID jadi { id, name, email, employee_number?, position? }. */
    private function resolveActor(\CodeIgniter\Database\BaseConnection $db, $userId): ?array
    {
        $userId = (int) $userId;
        if ($userId <= 0) return null;
        $row = $db->table('users u')
            ->select(
                'u.id, u.name, u.email, '
                . 'e.id AS employee_id, e.employee_number, e.full_name AS employee_name, '
                . 'p.name AS position_name'
            )
            ->join('employees e', 'e.user_id = u.id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('u.id', $userId)
            ->get()->getRowArray();
        if (! $row) return null;
        return [
            'user_id'         => (int) $row['id'],
            'name'            => $row['name'],
            'email'           => $row['email'],
            'employee_id'     => $row['employee_id'] ? (int) $row['employee_id'] : null,
            'employee_number' => $row['employee_number'],
            'employee_name'   => $row['employee_name'],
            'position'        => $row['position_name'],
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
