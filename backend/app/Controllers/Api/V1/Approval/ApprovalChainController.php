<?php

namespace App\Controllers\Api\V1\Approval;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Approval\ApprovalModuleCatalog;
use Config\Services;

/**
 * ApprovalChainController
 *
 * CRUD untuk approval_chains + approval_chain_steps + approval_assignments.
 * Dipakai HR / Super Admin untuk mendefinisikan pola persetujuan per modul,
 * setiap step bisa berupa SUPERVISOR / MANAGER / DEPARTMENT_HEAD / BRANCH_HEAD
 * / HR / FINANCE / ROLE / SPECIFIC_USER / SPECIFIC_EMPLOYEE.
 */
class ApprovalChainController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $module = trim((string) $this->request->getGet('module'));
        $builder = $db->table('approval_chains c')
            ->select('c.*, (SELECT COUNT(*) FROM approval_chain_steps s WHERE s.chain_id = c.id) AS step_count')
            ->where('c.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'c.company_id');
        if ($module !== '') {
            $builder->where('c.module', $module);
        }
        $rows = $builder->orderBy('c.module', 'ASC')->orderBy('c.name', 'ASC')->get()->getResultArray();

        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $chain = $this->findChainForTenant($db, (int) $id);
        if (!$chain) {
            return $this->fail('Not found', 404);
        }
        if (!$this->tenant()->isSuperAdmin() && (int) $chain['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $steps = $db->table('approval_chain_steps')
            ->where('chain_id', (int) $id)
            ->where('company_id', (int) $chain['company_id'])
            ->orderBy('step_order', 'ASC')
            ->get()->getResultArray();
        // Resolve nama referensi untuk display di UI.
        foreach ($steps as &$s) {
            if (!empty($s['approver_role_id'])) {
                $r = $db->table('roles')->where('id', (int) $s['approver_role_id'])->get()->getRowArray();
                $s['approver_role_name'] = $r['name'] ?? null;
            }
            if (!empty($s['approver_user_id'])) {
                $u = $db->table('users')->where('id', (int) $s['approver_user_id'])->where('company_id', (int) $chain['company_id'])->get()->getRowArray();
                $s['approver_user_name'] = $u['name'] ?? null;
            }
            if (!empty($s['approver_employee_id'])) {
                $e = $db->table('employees')->where('id', (int) $s['approver_employee_id'])->where('company_id', (int) $chain['company_id'])->get()->getRowArray();
                $s['approver_employee_name'] = $e['full_name'] ?? null;
            }
        }
        unset($s);
        $assignments = $db->table('approval_assignments ar')
            ->select('ar.*, d.name AS department_name, b.name AS branch_name, g.name AS grade_name')
            ->join('departments d', 'd.id = ar.department_id', 'left')
            ->join('branches b', 'b.id = ar.branch_id', 'left')
            ->join('job_grades g', 'g.id = ar.job_grade_id', 'left')
            ->where('ar.chain_id', (int) $id)
            ->where('ar.company_id', (int) $chain['company_id'])
            ->orderBy('ar.priority', 'ASC')
            ->get()->getResultArray();
        $chain['steps'] = $steps;
        $chain['assignments'] = $assignments;

        return $this->ok($chain);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|max_length[64]',
            'name' => 'required|max_length[200]',
            'module' => 'required|max_length[40]',
            'fallback_strategy' => 'permit_empty|in_list[SKIP,BLOCK,HR_FALLBACK]',
            'is_default' => 'permit_empty|in_list[0,1]',
            'is_active' => 'permit_empty|in_list[0,1]',
        ]);
        if (!$valid) {
            return null;
        }
        $data['module'] = strtoupper(trim((string) $data['module']));
        if (!ApprovalModuleCatalog::isSupported($data['module'])) {
            return $this->fail('Modul approval tidak didukung', 422, 'UNSUPPORTED_APPROVAL_MODULE');
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }

        $db = \Config\Database::connect();
        $exists = $db->table('approval_chains')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail('Kode chain sudah ada', 422, 'DUPLICATE_CODE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id' => $companyId,
            'code' => $data['code'],
            'name' => $data['name'],
            'module' => $data['module'],
            'description' => $data['description'] ?? null,
            'is_default' => (int) ($data['is_default'] ?? 0),
            'fallback_strategy' => $data['fallback_strategy'] ?? 'HR_FALLBACK',
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Hanya boleh ada 1 default per modul; matikan default lain bila perlu.
        if ($insert['is_default'] === 1) {
            $db->table('approval_chains')
                ->where('company_id', $companyId)
                ->where('module', $insert['module'])
                ->update(['is_default' => 0, 'updated_at' => $now]);
        }

        $db->table('approval_chains')->insert($insert);
        $id = (int) $db->insertID();

        // Buat step awal kalau dikirim
        if (!empty($data['steps']) && is_array($data['steps'])) {
            $this->replaceSteps($id, $companyId, $data['steps']);
        }

        Services::audit()
            ->forModule('MASTER')
            ->logCreate('approval_chain', $id, $insert, "Membuat chain approval: {$insert['name']} ({$insert['module']})");

        return $this->ok(['id' => $id], 'Chain dibuat', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findChainForTenant($db, $id);
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if (!$this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        if (array_key_exists('module', $data)) {
            $data['module'] = strtoupper(trim((string) $data['module']));
            if (!ApprovalModuleCatalog::isSupported($data['module'])) {
                return $this->fail('Modul approval tidak didukung', 422, 'UNSUPPORTED_APPROVAL_MODULE');
            }
        }

        $update = array_intersect_key($data, array_flip(['name', 'description', 'module', 'is_default', 'fallback_strategy', 'is_active']));
        if ((array_key_exists('steps', $data)
                || array_key_exists('module', $update)
                || array_key_exists('fallback_strategy', $update))
            && $this->hasPendingRequests($db, $id, (int) $existing['company_id'])) {
            return $this->fail(
                'Chain masih dipakai request PENDING. Buat versi chain baru untuk mengubah modul/step.',
                409,
                'CHAIN_HAS_PENDING_REQUESTS'
            );
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        if (isset($update['is_default']) && (int) $update['is_default'] === 1) {
            $db->table('approval_chains')
                ->where('company_id', (int) $existing['company_id'])
                ->where('module', $update['module'] ?? $existing['module'])
                ->where('id !=', $id)
                ->update(['is_default' => 0, 'updated_at' => $update['updated_at']]);
        }
        $db->table('approval_chains')->where('id', $id)->where('company_id', (int) $existing['company_id'])->update($update);

        if (array_key_exists('steps', $data) && is_array($data['steps'])) {
            $this->replaceSteps($id, (int) $existing['company_id'], $data['steps']);
        }

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                'approval_chain',
                $id,
                array_intersect_key($existing, $update),
                $update,
                "Mengubah chain approval: {$existing['name']}"
            );

        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findChainForTenant($db, $id);
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if (!$this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        if ($this->hasPendingRequests($db, $id, (int) $existing['company_id'])) {
            return $this->fail(
                'Chain masih dipakai request PENDING dan tidak boleh dihapus',
                409,
                'CHAIN_HAS_PENDING_REQUESTS'
            );
        }
        $db->table('approval_chains')->where('id', $id)->where('company_id', (int) $existing['company_id'])->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active' => 0,
        ]);
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('approval_chain', $id, $existing, "Menghapus chain: {$existing['name']}");

        return $this->ok(null, 'Deleted');
    }

    /**
     * Daftar assignment rules — chain mana berlaku untuk siapa.
     */
    public function listAssignments()
    {
        $db = \Config\Database::connect();
        $module = trim((string) $this->request->getGet('module'));
        $builder = $db->table('approval_assignments ar')
            ->select('ar.*, c.name AS chain_name, d.name AS department_name, b.name AS branch_name, g.name AS grade_name')
            ->join('approval_chains c', 'c.id = ar.chain_id', 'left')
            ->join('departments d', 'd.id = ar.department_id', 'left')
            ->join('branches b', 'b.id = ar.branch_id', 'left')
            ->join('job_grades g', 'g.id = ar.job_grade_id', 'left');
        $this->tenant()->applyScope($builder, 'ar.company_id');
        if ($module !== '') {
            $builder->where('ar.module', $module);
        }
        $rows = $builder->orderBy('ar.priority', 'ASC')->get()->getResultArray();

        return $this->ok($rows);
    }

    public function createAssignment()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'chain_id' => 'required|integer',
            'module' => 'required|max_length[40]',
            'priority' => 'permit_empty|integer',
        ]);
        if (!$valid) {
            return null;
        }
        $data['module'] = strtoupper(trim((string) $data['module']));
        if (!ApprovalModuleCatalog::isSupported($data['module'])) {
            return $this->fail('Modul approval tidak didukung', 422, 'UNSUPPORTED_APPROVAL_MODULE');
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $chain = $this->findChainForTenant($db, (int) $data['chain_id']);
        if (!$chain || (int) $chain['company_id'] !== $companyId) {
            return $this->fail('Approval chain tidak ditemukan di perusahaan aktif', 422, 'CROSS_TENANT_CHAIN');
        }
        if ((string) $chain['module'] !== $data['module']) {
            return $this->fail(
                'Modul assignment harus sama dengan modul chain',
                422,
                'CHAIN_MODULE_MISMATCH'
            );
        }
        $insert = [
            'company_id' => $companyId,
            'chain_id' => (int) $data['chain_id'],
            'module' => $data['module'],
            'department_id' => $data['department_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'job_grade_id' => $data['job_grade_id'] ?? null,
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $db->table('approval_assignments')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('MASTER')
            ->logCreate('approval_assignment', $id, $insert, "Memetakan chain ke modul {$insert['module']}");

        return $this->ok(['id' => $id], 'Assignment created', 201);
    }

    public function deleteAssignment($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('approval_assignments')->where('id', $id)->where('company_id', $this->companyId())->get()->getRowArray();
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if (!$this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('approval_assignments')->where('id', $id)->where('company_id', (int) $existing['company_id'])->delete();
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('approval_assignment', $id, $existing, 'Menghapus assignment chain');

        return $this->ok(null, 'Deleted');
    }

    /**
     * Daftar request approval yang menunggu user saat ini (inbox).
     */
    public function inbox()
    {
        $module = trim((string) $this->request->getGet('module'));
        $rows = Services::approval()->pendingForUser((int) $this->userId(), $module ?: null, 100);

        return $this->ok($rows);
    }

    /**
     * Daftar approval yang sudah diproses user saat ini.
     */
    public function history()
    {
        $module = trim((string) $this->request->getGet('module'));
        $rows = Services::approval()->processedForUser((int) $this->userId(), $module ?: null, 100);

        return $this->ok($rows);
    }

    /**
     * Detail satu request beserta histori keputusan.
     */
    public function showRequest($id = null)
    {
        $db = \Config\Database::connect();
        $req = $db->table('approval_requests ar')
            ->select('ar.*, e.full_name AS employee_name, c.name AS chain_name')
            ->join('employees e', 'e.id = ar.employee_id', 'left')
            ->join('approval_chains c', 'c.id = ar.chain_id', 'left')
            ->where('ar.id', (int) $id)
            ->where('ar.company_id', $this->companyId())
            ->get()->getRowArray();
        if (!$req) {
            return $this->fail('Not found', 404);
        }
        $decisions = $db->table('approval_decisions ad')
            ->select('ad.*, u.name AS approver_user_name, s.name AS step_name, s.approver_type')
            ->join('users u', 'u.id = ad.approver_user_id', 'left')
            ->join('approval_chain_steps s', 's.id = ad.chain_step_id', 'left')
            ->where('ad.approval_request_id', (int) $id)
            ->orderBy('ad.step_order', 'ASC')
            ->orderBy('ad.id', 'ASC')
            ->get()->getResultArray();
        $req['decisions'] = $decisions;
        $req['meta'] = $req['meta'] ? json_decode($req['meta'], true) : null;

        // Detail domain memakai allowlist per modul agar Inbox informatif tanpa
        // mengekspos seluruh kolom tabel sumber yang mungkin sensitif.
        $source = $this->approvalSourceDetail($db, $req);
        $req['request_detail'] = $source['detail'];
        $req['submitter_note'] = $source['submitter_note'];
        $req['attachment'] = $source['attachment'];
        $req['timeline'] = $this->approvalTimeline($req, $decisions);

        return $this->ok($req);
    }

    /**
     * @return array{detail:array<string,mixed>,submitter_note:?string,attachment:?array<string,string>}
     */
    private function approvalSourceDetail($db, array $request): array
    {
        $config = [
            'LEAVE' => [
                'table' => 'leaves',
                'fields' => [
                    'request_number', 'start_date', 'end_date', 'days',
                    'is_half_day', 'half_day_part', 'contact_during_leave', 'status',
                ],
                'note' => 'reason',
                'attachment' => 'attachment',
            ],
            'OVERTIME' => [
                'table' => 'overtimes',
                'fields' => [
                    'request_number', 'work_date', 'start_time', 'end_time',
                    'hours', 'day_type', 'computed_amount', 'status',
                ],
                'note' => 'reason',
                'attachment' => null,
            ],
            'ATTENDANCE_CHANGE' => [
                'table' => 'attendance_change_requests',
                'fields' => [
                    'request_number', 'work_date', 'change_type',
                    'current_clock_in', 'requested_clock_in',
                    'current_clock_out', 'requested_clock_out',
                    'current_status', 'requested_status', 'status',
                ],
                'note' => 'reason',
                'attachment' => 'attachment_path',
            ],
        ];

        $module = strtoupper((string) ($request['module'] ?? ''));
        $moduleConfig = $config[$module] ?? null;
        if ($moduleConfig === null
            || (string) ($request['entity_table'] ?? '') !== $moduleConfig['table']
            || !$db->tableExists($moduleConfig['table'])
        ) {
            return [
                'detail' => is_array($request['meta']) ? $request['meta'] : [],
                'submitter_note' => null,
                'attachment' => null,
            ];
        }

        $columns = array_values(array_unique(array_filter(array_merge(
            ['id', 'company_id'],
            $moduleConfig['fields'],
            [$moduleConfig['note'], $moduleConfig['attachment']]
        ))));
        $row = $db->table($moduleConfig['table'])
            ->select(implode(', ', $columns))
            ->where('id', (int) $request['entity_id'])
            ->where('company_id', (int) $request['company_id'])
            ->get()->getRowArray();

        if (!$row) {
            return ['detail' => [], 'submitter_note' => null, 'attachment' => null];
        }

        $detail = [];
        foreach ($moduleConfig['fields'] as $field) {
            if (array_key_exists($field, $row)) {
                $detail[$field] = $row[$field];
            }
        }

        $attachment = null;
        $attachmentField = $moduleConfig['attachment'];
        $path = $attachmentField ? trim((string) ($row[$attachmentField] ?? '')) : '';
        if ($path !== '') {
            $attachment = [
                'name' => basename(str_replace('\\', '/', $path)),
                'url' => Services::fileStorage()->signedUrl($path, 300),
            ];
        }

        return [
            'detail' => $detail,
            'submitter_note' => trim((string) ($row[$moduleConfig['note']] ?? '')) ?: null,
            'attachment' => $attachment,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function approvalTimeline(array $request, array $decisions): array
    {
        $snapshot = $request['workflow_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        $steps = is_array($snapshot) ? (array) ($snapshot['steps'] ?? []) : [];
        $decisionByStep = [];
        foreach ($decisions as $decision) {
            $decisionByStep[(int) ($decision['step_order'] ?? 0)] = $decision;
        }

        $timeline = [];
        foreach ($steps as $step) {
            $order = (int) ($step['step_order'] ?? 0);
            $decision = $decisionByStep[$order] ?? [];
            $timeline[] = [
                'step_order' => $order,
                'step_name' => (string) ($step['name'] ?? "Step {$order}"),
                'approver_type' => $step['approver_type'] ?? null,
                'status' => $decision['decision']
                    ?? (!empty($step['condition_matched']) ? 'WAITING_APPROVAL' : 'SKIPPED'),
                'approver_user_name' => $decision['approver_user_name'] ?? null,
                'decided_at' => $decision['decided_at'] ?? null,
                'note' => $decision['note'] ?? null,
                'is_current' => $request['status'] === 'PENDING'
                    && $order === (int) $request['current_step_order'],
            ];
        }

        // Workflow fallback tanpa chain.
        if ($timeline === [] && $decisions !== []) {
            foreach ($decisions as $decision) {
                $timeline[] = [
                    'step_order' => (int) ($decision['step_order'] ?? 0),
                    'step_name' => $decision['step_name'] ?: 'Fallback HR',
                    'approver_type' => $decision['approver_type'] ?: 'HR',
                    'status' => $decision['decision'],
                    'approver_user_name' => $decision['approver_user_name'] ?? null,
                    'decided_at' => $decision['decided_at'] ?? null,
                    'note' => $decision['note'] ?? null,
                    'is_current' => $request['status'] === 'PENDING'
                        && $decision['decision'] === 'PENDING',
                ];
            }
        }

        return $timeline;
    }

    public function approveRequest($id = null)
    {
        $id = (int) $id;
        if (!$this->approvalRequestBelongsToTenant($id)) {
            return $this->fail('Not found', 404);
        }
        if ($this->isPayrollApprovalRequest($id)) {
            return $this->fail(
                'Approval payroll wajib dilakukan dari tabel Periode Payroll dengan validasi ganda.',
                422,
                'PAYROLL_TABLE_APPROVAL_REQUIRED'
            );
        }
        $note = (string) ($this->getJsonInput()['note'] ?? '');

        try {
            $res = Services::approval()->approve($id, (int) $this->userId(), $note);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        // Setelah approval engine mengupdate state, controller asal punya
        // tanggung jawab finalisasi entitas (mis. leaves.status). Hook itu
        // dilakukan di LeaveController/OvertimeController setelah panggil
        // approval. Di sini kita kembalikan state engine.
        Services::audit()
            ->forModule('APPROVAL')
            ->logApprove('approval_request', $id, $res, "Approve approval_request #{$id}");

        return $this->ok($res, 'Approved');
    }

    public function rejectRequest($id = null)
    {
        $id = (int) $id;
        if (!$this->approvalRequestBelongsToTenant($id)) {
            return $this->fail('Not found', 404);
        }
        $note = (string) ($this->getJsonInput()['note'] ?? '');
        if ($note === '') {
            return $this->fail('Alasan penolakan wajib diisi', 422, 'REASON_REQUIRED');
        }

        try {
            $res = Services::approval()->reject($id, (int) $this->userId(), $note);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()
            ->forModule('APPROVAL')
            ->logReject('approval_request', $id, $res, $note);

        return $this->ok($res, 'Rejected');
    }

    /**
     * Bulk approve (Sprint 7 / H1.1).
     *
     * Body:
     *   { "approval_ids": [1, 2, 3, ...], "note": "optional" }
     *
     * Aturan:
     *   - Max 50 ID per request.
     *   - Setiap item diproses individual; gagal di 1 ID tidak rollback yang lain.
     *   - Audit log per-item TETAP individual (lewat ApprovalEngine.approve →
     *     decide → finalize). Plus 1 audit log batch untuk traceability.
     *
     * Response: { success: int[], failed: [{id, reason}] }
     */
    public function bulkApprove()
    {
        return $this->bulkDecide('APPROVED');
    }

    /**
     * Bulk reject. Sama dengan bulkApprove tapi note WAJIB diisi.
     */
    public function bulkReject()
    {
        return $this->bulkDecide('REJECTED');
    }

    private function bulkDecide(string $decision)
    {
        $data = $this->getJsonInput();
        $ids = $data['approval_ids'] ?? [];
        $note = trim((string) ($data['note'] ?? ''));

        if (!is_array($ids) || empty($ids)) {
            return $this->fail('approval_ids wajib berupa array tidak kosong', 422, 'INVALID_INPUT');
        }
        if (count($ids) > 50) {
            return $this->fail('Maksimal 50 approval per request', 422, 'TOO_MANY_ITEMS');
        }
        if ($decision === 'REJECTED' && $note === '') {
            return $this->fail('Alasan penolakan wajib diisi untuk bulk reject', 422, 'REASON_REQUIRED');
        }

        // Normalisasi & dedup
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));

        $userId = (int) $this->userId();
        $service = Services::approval();
        $success = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                if (!$this->approvalRequestBelongsToTenant($id)) {
                    throw new \RuntimeException('Approval request not found');
                }
                if ($decision === 'APPROVED' && $this->isPayrollApprovalRequest($id)) {
                    throw new \RuntimeException(
                        'Approval payroll wajib dilakukan dari tabel Periode Payroll dengan validasi ganda.'
                    );
                }
                if ($decision === 'APPROVED') {
                    $res = $service->approve($id, $userId, $note);
                } else {
                    $res = $service->reject($id, $userId, $note);
                }
                $success[] = ['id' => $id, 'status' => $res['status'] ?? 'PENDING', 'final' => (bool) ($res['final'] ?? false)];
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        // Audit log batch — per-item log sudah dibuat oleh ApprovalEngine.
        Services::audit()
            ->forModule('APPROVAL')
            ->log(
                action: $decision === 'APPROVED' ? 'approval.bulk_approve' : 'approval.bulk_reject',
                entity: 'approval_request',
                entityId: 0,
                after: [
                    'requested' => count($ids),
                    'success' => count($success),
                    'failed' => count($failed),
                    'note' => $note,
                    'success_ids' => array_column($success, 'id'),
                    'failed_ids' => array_column($failed, 'id'),
                ],
                description: sprintf(
                    'Bulk %s: %d/%d berhasil',
                    $decision === 'APPROVED' ? 'approve' : 'reject',
                    count($success),
                    count($ids)
                ),
                severity: 'NOTICE',
            );

        return $this->ok([
            'success' => $success,
            'failed' => $failed,
            'summary' => [
                'requested' => count($ids),
                'success' => count($success),
                'failed' => count($failed),
            ],
        ], sprintf('%d berhasil, %d gagal', count($success), count($failed)));
    }

    /**
     * Replace semua step di sebuah chain dengan input baru.
     * Format input:
     *   [
     *     ['name' => 'Atasan', 'approver_type' => 'SUPERVISOR', 'is_required' => 1, ...],
     *     ['name' => 'HR',     'approver_type' => 'HR'],
     *   ]
     */
    private function replaceSteps(int $chainId, int $companyId, array $steps): void
    {
        $db = \Config\Database::connect();
        if ($this->hasPendingRequests($db, $chainId, $companyId)) {
            throw new \RuntimeException(
                'Step chain tidak boleh diubah selama masih ada request PENDING. '
                . 'Nonaktifkan chain dan buat versi baru.'
            );
        }
        $db->table('approval_chain_steps')->where('chain_id', $chainId)->where('company_id', $companyId)->delete();
        $now = date('Y-m-d H:i:s');
        // Sprint 9 / H1.3 — terima condition_type + condition_value_json baru.
        // Validasi config kalau di-set, supaya tidak masuk DB dengan format ngaco.
        $evaluator = new \App\Libraries\Approval\ApprovalConditionEvaluator();
        foreach (array_values($steps) as $i => $s) {
            $conditionType = $s['condition_type'] ?? null;
            $conditionConfig = $s['condition_value_json'] ?? null;
            if ($conditionType !== null && $conditionType !== '') {
                $err = $evaluator->validateConfig($conditionType, $conditionConfig);
                if ($err !== null) {
                    throw new \RuntimeException("Step #{$i}: {$err}");
                }
            }
            $db->table('approval_chain_steps')->insert([
                'company_id' => $companyId,
                'chain_id' => $chainId,
                'step_order' => (int) ($s['step_order'] ?? ($i + 1)),
                'name' => (string) ($s['name'] ?? ('Step ' . ($i + 1))),
                'approver_type' => $s['approver_type'] ?? 'SUPERVISOR',
                'approver_role_id' => $s['approver_role_id'] ?? null,
                'approver_user_id' => $s['approver_user_id'] ?? null,
                'approver_employee_id' => $s['approver_employee_id'] ?? null,
                'condition_field' => $s['condition_field'] ?? null,
                'condition_op' => $s['condition_op'] ?? null,
                'condition_value' => isset($s['condition_value']) ? (float) $s['condition_value'] : null,
                // Kondisi baru (H1.3)
                'condition_type' => $conditionType ?: null,
                'condition_value_json' => $conditionConfig !== null
                    ? (is_string($conditionConfig) ? $conditionConfig : json_encode($conditionConfig))
                    : null,
                'is_required' => (int) ($s['is_required'] ?? 1),
                'allow_skip_if_self' => (int) ($s['allow_skip_if_self'] ?? 1),
                'sla_hours' => isset($s['sla_hours']) ? (int) $s['sla_hours'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function findChainForTenant(\CodeIgniter\Database\BaseConnection $db, int $id): ?array
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return null;
        }

        return $db->table('approval_chains')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()
            ->getRowArray();
    }

    private function approvalRequestBelongsToTenant(int $id): bool
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return false;
        }

        return $this->approvalRequestExists($id);
    }

    private function isPayrollApprovalRequest(int $id): bool
    {
        return $this->approvalRequestExists($id, 'PAYROLL');
    }

    private function approvalRequestExists(int $id, ?string $module = null): bool
    {
        $builder = \Config\Database::connect()->table('approval_requests')
            ->where('id', $id)
            ->where('company_id', $this->companyId());
        if ($module !== null) {
            $builder->where('module', $module);
        }

        return $builder->countAllResults() === 1;
    }

    private function hasPendingRequests(
        \CodeIgniter\Database\BaseConnection $db,
        int $chainId,
        int $companyId
    ): bool {
        return $db->table('approval_requests')
            ->where('chain_id', $chainId)
            ->where('company_id', $companyId)
            ->where('status', 'PENDING')
            ->countAllResults() > 0;
    }
}
