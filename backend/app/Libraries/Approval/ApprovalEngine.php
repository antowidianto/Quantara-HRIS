<?php

namespace App\Libraries\Approval;

use Config\Services;

/**
 * ApprovalEngine
 *
 * Mesin approval generic untuk modul LEAVE / OVERTIME / ATTENDANCE_CHANGE /
 * REIMBURSEMENT / LOAN / BUSINESS_TRIP / BONUS / PAYROLL_ADJUSTMENT /
 * COMPENSATION_REVIEW / TRAINING_REGISTRATION / TIMESHEET / CANDIDATE_OFFER /
 * GENERATED_CONTRACT / ASSET_TRANSACTION.
 * Modul caller cukup memanggil:
 *
 *   $eng = Services::approval();
 *   $req = $eng->start([
 *       'company_id'   => 1,
 *       'module'       => 'LEAVE',
 *       'entity_table' => 'leaves',
 *       'entity_id'    => $id,
 *       'employee_id'  => $employeeId,
 *       'meta'         => ['days' => 5, 'amount' => 0],
 *   ]);
 *
 * Lalu approver memanggil:
 *
 *   $eng->approve($requestId, $userId, 'OK');   // / reject
 *
 * Engine ini akan:
 *   1. Resolve chain (priority: employee.approval_chain_*_id → assignments
 *      by grade/dept/branch → company default) — lihat resolveChain().
 *   2. Skip step yang kondisinya tidak terpenuhi atau allow_skip_if_self.
 *   3. Pindah ke step berikutnya saat di-approve, atau mark final saat habis.
 *   4. Mengirim notifikasi ke approver step berikutnya.
 *
 * Final decision hanya berhasil bila modul memiliki ApprovalDomainAdapter.
 * Adapter dijalankan dalam transaksi yang sama dengan decision/final state,
 * sehingga status domain dan status approval tidak dapat berbeda.
 */
class ApprovalEngine
{
    private readonly ApprovalDomainAdapterRegistry $domainAdapters;
    private readonly ApprovalOutcomeOutbox $outbox;

    public function __construct(
        ?ApprovalDomainAdapterRegistry $domainAdapters = null,
        ?ApprovalOutcomeOutbox $outbox = null,
    )
    {
        $this->domainAdapters = $domainAdapters ?? new ApprovalDomainAdapterRegistry();
        $this->outbox = $outbox ?? new ApprovalOutcomeOutbox();
    }

    /**
     * @return array<string,mixed>  ['request_id' => int, 'status' => string,
     *                              'next_approvers' => int[], 'final' => bool]
     */
    public function start(array $params): array
    {
        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $result = $this->startWithinTransaction($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat transaksi approval');
            $db->transCommit();
            return $result;
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function startWithinTransaction(array $params): array
    {
        $companyId   = (int) ($params['company_id'] ?? 0);
        $module      = strtoupper(trim((string) ($params['module'] ?? 'GENERIC')));
        $entityTable = (string) ($params['entity_table'] ?? '');
        $entityId    = (int) ($params['entity_id'] ?? 0);
        $employeeId  = (int) ($params['employee_id'] ?? 0);
        $meta        = $params['meta'] ?? [];

        if (! $companyId || ! $entityTable || ! $entityId || ! $employeeId) {
            throw new \InvalidArgumentException('ApprovalEngine.start: missing required parameters');
        }

        ApprovalModuleCatalog::assertEntityTable($module, $entityTable);

        $db = \Config\Database::connect();

        // Tenant integrity: requester and governed entity must belong to the
        // same company as the approval request. Never trust caller IDs alone.
        $employee = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $employee) {
            throw new \InvalidArgumentException('Employee tidak ditemukan di tenant aktif');
        }
        $entity = $db->table($entityTable)
            ->where('id', $entityId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $entity) {
            throw new \InvalidArgumentException('Entitas approval tidak ditemukan di tenant aktif');
        }

        // Idempotency: retries must not create two live workflows for one
        // transaction. Return the existing request instead.
        $existing = $db->table('approval_requests')
            ->where('company_id', $companyId)
            ->where('module', $module)
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->where('status', 'PENDING')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        if ($existing) {
            return [
                'request_id' => (int) $existing['id'],
                'chain_id' => $existing['chain_id'] !== null ? (int) $existing['chain_id'] : null,
                'status' => 'PENDING',
                'current_step' => (int) ($existing['current_step_order'] ?? 1),
                'next_approvers' => $this->resolveCurrentApprovers($existing),
                'final' => false,
                'idempotent_replay' => true,
            ];
        }

        $chain = $this->resolveChain($companyId, $module, $employeeId);
        $now   = date('Y-m-d H:i:s');
        $workflowSnapshot = $this->buildWorkflowSnapshot($chain, [
            'company_id' => $companyId,
            'module' => $module,
            'employee_id' => $employeeId,
        ], is_array($meta) ? $meta : []);
        $snapshotJson = json_encode($workflowSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $versionPayload = $workflowSnapshot;
        unset($versionPayload['snapshotted_at']);
        $versionJson = json_encode($versionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $insert = [
            'company_id'         => $companyId,
            'module'             => $module,
            'entity_table'       => $entityTable,
            'entity_id'          => $entityId,
            'employee_id'        => $employeeId,
            'chain_id'           => $chain ? (int) $chain['id'] : null,
            'current_step_id'    => null,
            'current_step_order' => 1,
            'status'             => 'PENDING',
            'meta'               => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'workflow_snapshot'  => $snapshotJson,
            'chain_version_hash' => hash('sha256', (string) $versionJson),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('approval_requests')->insert($insert);
        $reqId = (int) $db->insertID();

        // Tidak ada chain → langsung auto-approve (legacy behavior, biar tidak
        // memblokir). Caller bebas memutuskan apakah ini diperlakukan sebagai
        // PENDING atau APPROVED untuk tabel asalnya.
        if (! $chain) {
            $db->table('approval_requests')->where('id', $reqId)->update([
                'status' => 'PENDING',
                'updated_at' => $now,
            ]);
            $db->table('approval_decisions')->insert([
                'company_id' => $companyId,
                'approval_request_id' => $reqId,
                'chain_step_id' => null,
                'step_order' => 0,
                'decision' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [
                'request_id'    => $reqId,
                'chain_id'      => null,
                'status'        => 'PENDING',
                'next_approvers'=> $this->fallbackHrApprovers($companyId),
                'final'         => false,
                'note'          => 'Tidak ada chain terdaftar; pakai fallback HR.',
            ];
        }

        $advance = $this->advance($reqId, 0); // mulai dari step 0 → cari step berikutnya
        return $advance;
    }

    /**
     * Approval keputusan oleh user.
     *
     * @return array<string,mixed>
     */
    public function approve(int $requestId, int $userId, string $note = ''): array
    {
        return $this->decide($requestId, $userId, 'APPROVED', $note);
    }

    public function reject(int $requestId, int $userId, string $note = ''): array
    {
        return $this->decide($requestId, $userId, 'REJECTED', $note);
    }

    /** @return array<string,mixed> */
    public function approveEntity(string $module, string $entityTable, int $entityId, int $companyId, int $userId, string $note = ''): array
    {
        return $this->decideEntity($module, $entityTable, $entityId, $companyId, $userId, 'APPROVED', $note);
    }

    /** @return array<string,mixed> */
    public function rejectEntity(string $module, string $entityTable, int $entityId, int $companyId, int $userId, string $note): array
    {
        return $this->decideEntity($module, $entityTable, $entityId, $companyId, $userId, 'REJECTED', $note);
    }

    public function cancel(int $requestId): void
    {
        $db = \Config\Database::connect();
        $builder = $db->table('approval_requests')->where('id', $requestId)->where('status', 'PENDING');
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId > 0) $builder->where('company_id', $companyId);
        $builder->update([
            'status'           => 'CANCELLED',
            'final_decided_at' => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        if ($db->affectedRows() !== 1) {
            throw new \RuntimeException('Approval request PENDING tidak ditemukan atau sudah berubah');
        }
        $db->table('approval_decisions')->where('approval_request_id', $requestId)
            ->where('decision', 'PENDING')->update([
                'decision' => 'SKIPPED',
                'decided_at' => date('Y-m-d H:i:s'),
                'note' => 'Request dibatalkan oleh pemohon',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function cancelEntity(string $module, string $entityTable, int $entityId, int $companyId): void
    {
        $module = strtoupper(trim($module));
        ApprovalModuleCatalog::assertEntityTable($module, $entityTable);
        $request = \Config\Database::connect()->table('approval_requests')
            ->where('company_id', $companyId)->where('module', $module)
            ->where('entity_table', $entityTable)->where('entity_id', $entityId)
            ->where('status', 'PENDING')->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $request) throw new \RuntimeException('Approval request PENDING tidak ditemukan');
        $this->cancel((int) $request['id']);
    }

    /**
     * Apakah $userId boleh menyetujui $requestId pada step saat ini?
     * Berguna untuk authorization sebelum approve/reject.
     */
    public function canApprove(int $requestId, int $userId): bool
    {
        $db = \Config\Database::connect();
        $builder = $db->table('approval_requests')->where('id', $requestId);
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId > 0) $builder->where('company_id', $companyId);
        $req = $builder->get()->getRowArray();
        if (! $req || $req['status'] !== 'PENDING') return false;

        // Super admin / HR fallback selalu bisa approve.
        $tenant = Services::tenant();
        if ($tenant->isSuperAdmin() && (int) $tenant->getUserId() === $userId) return true;

        $approvers = $this->resolveCurrentApprovers($req);
        return in_array($userId, $approvers, true);
    }

    /**
     * Daftar request yang menunggu approval dari $userId.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingForUser(int $userId, ?string $module = null, int $limit = 50): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('approval_requests ar')
            ->select('ar.*, e.full_name AS employee_name, e.employee_number, c.name AS chain_name')
            ->join('employees e', 'e.id = ar.employee_id', 'left')
            ->join('approval_chains c', 'c.id = ar.chain_id', 'left')
            ->where('ar.status', 'PENDING')
            ->orderBy('ar.created_at', 'DESC')
            ->limit($limit);
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId > 0) $builder->where('ar.company_id', $companyId);
        if ($module) $builder->where('ar.module', $module);

        $rows = $builder->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $approvers = $this->resolveCurrentApprovers($r);
            if (in_array($userId, $approvers, true)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────────
    // Private
    // ────────────────────────────────────────────────────────────────

    /**
     * Pilih chain yang berlaku, dengan prioritas:
     *   1. employee.approval_chain_<module>_id (kalau cocok modul)
     *   2. approval_assignments paling spesifik (priority ASC) untuk
     *      job_grade/department/branch karyawan
     *   3. approval_chains where is_default=1 untuk modul + company
     *
     * @return array<string,mixed>|null
     */
    private function resolveChain(int $companyId, string $module, int $employeeId): ?array
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->get()->getRowArray();
        if (! $emp) return null;

        // 1. Per-employee override
        $col = match ($module) {
            'LEAVE'             => 'approval_chain_leave_id',
            'OVERTIME'          => 'approval_chain_overtime_id',
            'ATTENDANCE_CHANGE' => 'approval_chain_attendance_id',
            default             => null,
        };
        if ($col && ! empty($emp[$col])) {
            $chain = $db->table('approval_chains')
                ->where('id', (int) $emp[$col])
                ->where('company_id', $companyId)
                ->where('module', $module)
                ->where('is_active', 1)
                ->get()->getRowArray();
            if ($chain) return $chain;
        }

        // 2. Assignment rules — pilih row paling spesifik & priority terkecil
        $rules = $db->table('approval_assignments ar')
            ->select('ar.*, c.is_active AS chain_active')
            ->join('approval_chains c', 'c.id = ar.chain_id')
            ->where('ar.company_id', $companyId)
            ->where('ar.module', $module)
            ->where('ar.is_active', 1)
            ->where('c.is_active', 1)
            ->orderBy('ar.priority', 'ASC')
            ->get()->getResultArray();
        foreach ($rules as $rule) {
            $matchGrade = ($rule['job_grade_id'] === null || (int) $rule['job_grade_id'] === (int) $emp['job_grade_id']);
            $matchDept  = ($rule['department_id'] === null || (int) $rule['department_id'] === (int) $emp['department_id']);
            $matchBranch= ($rule['branch_id']    === null || (int) $rule['branch_id']    === (int) $emp['branch_id']);
            if ($matchGrade && $matchDept && $matchBranch) {
                return $db->table('approval_chains')->where('id', (int) $rule['chain_id'])->where('company_id', $companyId)->get()->getRowArray();
            }
        }

        // 3. Default chain untuk modul + company
        return $db->table('approval_chains')
            ->where('company_id', $companyId)
            ->where('module', $module)
            ->where('is_default', 1)
            ->where('is_active', 1)
            ->get()->getRowArray() ?: null;
    }

    /**
     * Freeze policy, conditions, and expected approvers when the request is
     * submitted. Org/role/chain changes must never rewrite a running request.
     *
     * @param array<string,mixed>|null $chain
     * @param array<string,mixed> $request
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function buildWorkflowSnapshot(?array $chain, array $request, array $meta): array
    {
        $companyId = (int) $request['company_id'];
        $request['meta'] = $meta;
        if (! $chain) {
            return [
                'schema_version' => 1,
                'chain_id' => null,
                'fallback_strategy' => 'HR_FALLBACK',
                'fallback_approver_user_ids' => $this->fallbackHrApprovers($companyId),
                'steps' => [],
                'snapshotted_at' => date('c'),
            ];
        }

        $steps = \Config\Database::connect()->table('approval_chain_steps')
            ->where('chain_id', (int) $chain['id'])
            ->where('company_id', $companyId)
            ->orderBy('step_order', 'ASC')->get()->getResultArray();
        $strategy = (string) ($chain['fallback_strategy'] ?? 'HR_FALLBACK');
        $snapshots = [];
        foreach ($steps as $step) {
            $step['__request_employee_id'] = (int) $request['employee_id'];
            $matched = $this->stepConditionMet($step, $meta);
            // Snapshot approver asli, bukan delegate temporer. Delegasi selalu
            // dievaluasi dinamis beserta effective date saat keputusan dibuat.
            $expected = $matched ? $this->resolveApproversForStep($step, $request, false) : [];
            if ($matched && $expected === [] && $strategy === 'HR_FALLBACK') {
                $expected = $this->fallbackHrApprovers($companyId);
            }
            $step['condition_matched'] = $matched;
            $step['expected_approver_user_ids'] = array_values(array_unique(array_map('intval', $expected)));
            unset($step['__request_employee_id']);
            $snapshots[] = $step;
        }
        return [
            'schema_version' => 1,
            'chain_id' => (int) $chain['id'],
            'chain_code' => (string) ($chain['code'] ?? ''),
            'chain_name' => (string) ($chain['name'] ?? ''),
            'fallback_strategy' => $strategy,
            'steps' => $snapshots,
            'snapshotted_at' => date('c'),
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function workflowSnapshot(array $request): array
    {
        $raw = $request['workflow_snapshot'] ?? null;
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pindah ke step berikutnya setelah lastStepOrder. Kalau tidak ada step
     * tersisa, request difinalisasi APPROVED.
     */
    private function advance(int $requestId, int $lastStepOrder, int $actorUserId = 0, string $note = ''): array
    {
        $db = \Config\Database::connect();
        $req = $db->table('approval_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $req) {
            return ['request_id' => $requestId, 'status' => 'NOT_FOUND', 'final' => true, 'next_approvers' => []];
        }
        $meta = is_string($req['meta']) ? (json_decode($req['meta'], true) ?: []) : ($req['meta'] ?: []);

        $snapshot = $this->workflowSnapshot($req);
        $steps = $snapshot['steps'] ?? $db->table('approval_chain_steps')
            ->where('chain_id', (int) $req['chain_id'])
            ->orderBy('step_order', 'ASC')->get()->getResultArray();

        // Cari step pertama yang stepOrder > lastStepOrder dan kondisinya
        // terpenuhi serta tidak di-skip karena allow_skip_if_self.
        foreach ($steps as $step) {
            if ((int) $step['step_order'] <= $lastStepOrder) continue;
            // Sprint 9 / H1.3 — sisipkan employee_id ke step row supaya
            // ApprovalConditionEvaluator bisa lookup department/branch/level.
            $step['__request_employee_id'] = (int) $req['employee_id'];
            $conditionMatched = array_key_exists('condition_matched', $step)
                ? (bool) $step['condition_matched']
                : $this->stepConditionMet($step, $meta);
            if (! $conditionMatched) {
                $this->logSkip($requestId, $step, 'Condition not met');
                continue;
            }
            $approvers = isset($step['expected_approver_user_ids'])
                ? array_values(array_map('intval', (array) $step['expected_approver_user_ids']))
                : $this->resolveApproversForStep($step, $req);
            if (! empty($step['allow_skip_if_self'])
                && count($approvers) === 1
                && $this->isSelfApproval($approvers[0], (int) $req['employee_id'])
            ) {
                $this->logSkip($requestId, $step, 'Skipped: requester equals approver');
                continue;
            }
            // Kalau approver kosong, terapkan fallback strategy chain.
            if (empty($approvers)) {
                $chain = $db->table('approval_chains')->where('id', (int) $req['chain_id'])->get()->getRowArray();
                $strategy = $snapshot['fallback_strategy'] ?? $chain['fallback_strategy'] ?? 'HR_FALLBACK';
                if ($strategy === 'SKIP') {
                    $this->logSkip($requestId, $step, 'No approver found; skipped');
                    continue;
                } elseif ($strategy === 'HR_FALLBACK') {
                    $approvers = $this->fallbackHrApprovers((int) $req['company_id']);
                }
                // BLOCK → biarkan approvers kosong, status tetap PENDING tanpa progress
            }

            // Set step ini sebagai current
            $now = date('Y-m-d H:i:s');
            $db->table('approval_requests')->where('id', $requestId)->update([
                'current_step_id'    => (int) $step['id'],
                'current_step_order' => (int) $step['step_order'],
                'updated_at'         => $now,
            ]);
            $db->table('approval_decisions')->insert([
                'company_id'          => (int) $req['company_id'],
                'approval_request_id' => $requestId,
                'chain_step_id'       => (int) $step['id'],
                'step_order'          => (int) $step['step_order'],
                'decision'            => 'PENDING',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $this->notifyApprovers($req, $step, $approvers);

            return [
                'request_id'      => $requestId,
                'chain_id'        => (int) $req['chain_id'],
                'status'          => 'PENDING',
                'current_step'    => (int) $step['step_order'],
                'next_approvers'  => $approvers,
                'final'           => false,
            ];
        }

        // Tidak ada step lagi → finalisasi APPROVED
        if ($actorUserId <= 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('approval_requests')->where('id', $requestId)->update([
                'current_step_id' => null,
                'current_step_order' => 0,
                'updated_at' => $now,
            ]);
            $db->table('approval_decisions')->insert([
                'company_id' => (int) $req['company_id'],
                'approval_request_id' => $requestId,
                'chain_step_id' => null,
                'step_order' => 0,
                'decision' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [
                'request_id' => $requestId,
                'chain_id' => $req['chain_id'] !== null ? (int) $req['chain_id'] : null,
                'status' => 'PENDING',
                'current_step' => 0,
                'next_approvers' => $this->fallbackHrApprovers((int) $req['company_id']),
                'final' => false,
                'note' => 'Tidak ada step applicable; fallback HR wajib memutuskan.',
            ];
        }

        return $this->finalize($requestId, 'APPROVED', $actorUserId, $note);
    }

    private function decide(int $requestId, int $userId, string $decision, string $note): array
    {
        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $result = $this->decideWithinTransaction($requestId, $userId, $decision, $note);
            if (! $db->transStatus()) {
                throw new \RuntimeException('Transaksi approval gagal');
            }
            $db->transCommit();
            return $result;
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function decideWithinTransaction(int $requestId, int $userId, string $decision, string $note): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('approval_requests')->where('id', $requestId);
        $companyId = Services::tenant()->getCompanyId();
        if ($companyId > 0) $builder->where('company_id', $companyId);
        $req = $builder->get()->getRowArray();
        if (! $req) {
            throw new \RuntimeException('Approval request not found');
        }
        if ($req['status'] !== 'PENDING') {
            throw new \RuntimeException('Request bukan dalam status PENDING');
        }
        if ($decision === 'REJECTED' && mb_strlen(trim($note)) < 5) {
            throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');
        }

        // Four-eyes principle: requester may never approve/reject their own
        // transaction, including through super-admin fallback.
        if ($this->isSelfApproval($userId, (int) $req['employee_id'], $req)) {
            throw new \RuntimeException('Self-approval tidak diizinkan');
        }

        // Verifikasi approver
        $approvers = $this->resolveCurrentApprovers($req);
        $tenant = Services::tenant();
        $isSuperAdmin = $tenant->isSuperAdmin() && (int) $tenant->getUserId() === $userId;
        if (! $isSuperAdmin && ! in_array($userId, $approvers, true)) {
            throw new \RuntimeException('User bukan approver untuk step ini');
        }

        // Cek apakah user yang approve ini sebenarnya adalah delegate dari
        // approver asli. Kalau iya, sisipkan info ke note supaya audit jelas.
        $approverRoleResolved = null;
        $originalApprover = $this->findOriginalForDelegate(
            $userId,
            $approvers,
            (int) $req['company_id'],
            (string) $req['module']
        );
        if ($originalApprover !== null) {
            $origUser = \Config\Database::connect()
                ->table('users')->where('id', $originalApprover)->get()->getRowArray();
            $origLabel = $origUser['name'] ?? "user #{$originalApprover}";
            $note = trim($note);
            $prefix = "[Mendelegasi mewakili {$origLabel}]";
            $note = $note === '' ? $prefix : ($prefix . ' ' . $note);
            $approverRoleResolved = "DELEGATE_OF_{$originalApprover}";
        }

        $now = date('Y-m-d H:i:s');
        // Update keputusan untuk step saat ini (cari row PENDING terakhir untuk step ini)
        $decisionBuilder = $db->table('approval_decisions')
            ->where('approval_request_id', $requestId)
            ->where('decision', 'PENDING')
            ->orderBy('id', 'DESC')
            ->limit(1);
        if ($req['current_step_id'] === null) {
            $decisionBuilder->where('chain_step_id IS NULL');
        } else {
            $decisionBuilder->where('chain_step_id', (int) $req['current_step_id']);
        }
        $existing = $decisionBuilder->get()->getRowArray();
        $decisionRow = [
            'approver_user_id' => $userId,
            'decision'         => $decision,
            'decided_at'       => $now,
            'note'             => $note,
            'updated_at'       => $now,
        ];
        if ($approverRoleResolved !== null) {
            $decisionRow['approver_role'] = $approverRoleResolved;
        }
        if ($existing) {
            $db->table('approval_decisions')
                ->where('id', $existing['id'])
                ->where('decision', 'PENDING')
                ->update($decisionRow);
            if ($db->affectedRows() !== 1) {
                throw new \RuntimeException('Step sudah diputuskan oleh approver lain; muat ulang data');
            }
        } else {
            throw new \RuntimeException('Pending decision row tidak ditemukan; workflow perlu diperbaiki');
        }

        if ($decision === 'REJECTED') {
            return $this->finalize($requestId, 'REJECTED', $userId, $note);
        }

        // APPROVED → maju ke step berikutnya
        return $this->advance($requestId, (int) ($req['current_step_order'] ?? 0), $userId, $note);
    }

    private function finalize(int $requestId, string $status, int $actorUserId, string $note): array
    {
        $db = \Config\Database::connect();
        $request = $db->table('approval_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $request || $request['status'] !== 'PENDING') {
            throw new \RuntimeException('Approval request berubah; muat ulang data');
        }
        $domainResult = $this->domainAdapters
            ->forModule((string) $request['module'])
            ->apply($request, $status, $actorUserId, $note);
        $outboxId = $this->outbox->record($request, $status, $actorUserId, $note, $domainResult);
        $now = date('Y-m-d H:i:s');
        $db->table('approval_requests')->where('id', $requestId)->where('status', 'PENDING')->update([
            'status'           => $status,
            'final_decided_at' => $now,
            'current_step_id'  => null,
            'updated_at'       => $now,
        ]);
        return [
            'request_id'     => $requestId,
            'status'         => $status,
            'final'          => true,
            'next_approvers' => [],
            'domain_result'  => $domainResult,
            'outbox_event_id' => $outboxId,
        ];
    }

    /** @return array<string,mixed> */
    private function decideEntity(
        string $module,
        string $entityTable,
        int $entityId,
        int $companyId,
        int $userId,
        string $decision,
        string $note
    ): array {
        $module = strtoupper(trim($module));
        ApprovalModuleCatalog::assertEntityTable($module, $entityTable);
        $request = \Config\Database::connect()->table('approval_requests')
            ->where('company_id', $companyId)
            ->where('module', $module)
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->where('status', 'PENDING')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        if (! $request) {
            throw new \RuntimeException('Approval workflow PENDING tidak ditemukan untuk transaksi ini');
        }

        return $this->decide((int) $request['id'], $userId, $decision, $note);
    }

    private function logSkip(int $requestId, array $step, string $reason): void
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('approval_decisions')->insert([
            'company_id'          => (int) $step['company_id'],
            'approval_request_id' => $requestId,
            'chain_step_id'       => (int) $step['id'],
            'step_order'          => (int) $step['step_order'],
            'decision'            => 'SKIPPED',
            'decided_at'          => $now,
            'note'                => $reason,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    private function stepConditionMet(array $step, array $meta): bool
    {
        // Sprint 9 / H1.3 — kondisi tipe baru via condition_type +
        // condition_value_json. Kalau di-set, evaluasi pakai
        // ApprovalConditionEvaluator. Hasil di-AND dengan kondisi legacy
        // di bawah supaya backward compatible.
        if (! empty($step['condition_type'])) {
            $evaluator = new \App\Libraries\Approval\ApprovalConditionEvaluator();
            $employee = [];
            $empId = (int) ($step['__request_employee_id'] ?? 0);
            if ($empId > 0) {
                $emp = \Config\Database::connect()
                    ->table('employees e')
                    ->select('e.department_id, e.branch_id, e.job_grade_id, g.level AS job_grade_level')
                    ->join('job_grades g', 'g.id = e.job_grade_id', 'left')
                    ->where('e.id', $empId)
                    ->get()->getRowArray();
                if ($emp) $employee = $emp;
            }
            $rawConfig = $step['condition_value_json'] ?? null;
            $contextOk = $evaluator->evaluate(
                (string) $step['condition_type'],
                $rawConfig,
                ['meta' => $meta, 'employee' => $employee],
            );
            if (! $contextOk) return false;
        }

        // Legacy condition: condition_field/op/value (numeric only).
        if (empty($step['condition_field'])) return true;
        $field = $step['condition_field'];
        $op    = $step['condition_op'];
        $value = (float) $step['condition_value'];
        if (! array_key_exists($field, $meta)) return true; // tidak bisa evaluasi → ambil aman, jalankan step
        $actual = (float) $meta[$field];
        return match ($op) {
            '>'  => $actual >  $value,
            '>=' => $actual >= $value,
            '<'  => $actual <  $value,
            '<=' => $actual <= $value,
            '='  => abs($actual - $value) < 0.0001,
            '!=' => abs($actual - $value) >= 0.0001,
            default => true,
        };
    }

    /**
     * @return int[] user_id daftar approver
     */
    private function resolveApproversForStep(array $step, array $request, bool $expandDelegates = true): array
    {
        $db = \Config\Database::connect();
        $employeeId = (int) $request['employee_id'];
        $companyId  = (int) $request['company_id'];

        $emp = $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->get()->getRowArray();
        if (! $emp) return [];

        $type = $step['approver_type'];
        $candidates = [];
        $meta = $request['meta'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];

        if ($type === 'SUPERVISOR' && ! empty($emp['supervisor_id'])) {
            $sup = $db->table('employees')->where('id', (int) $emp['supervisor_id'])->where('company_id', $companyId)->get()->getRowArray();
            if ($sup && ! empty($sup['user_id'])) $candidates[] = (int) $sup['user_id'];
        } elseif ($type === 'MANAGER' && ! empty($emp['manager_id'])) {
            $mgr = $db->table('employees')->where('id', (int) $emp['manager_id'])->where('company_id', $companyId)->get()->getRowArray();
            if ($mgr && ! empty($mgr['user_id'])) $candidates[] = (int) $mgr['user_id'];
        } elseif ($type === 'DEPARTMENT_HEAD' && ! empty($emp['department_id'])) {
            $dept = $db->table('departments')->where('id', (int) $emp['department_id'])->where('company_id', $companyId)->get()->getRowArray();
            if ($dept && ! empty($dept['manager_employee_id'])) {
                $head = $db->table('employees')->where('id', (int) $dept['manager_employee_id'])->where('company_id', $companyId)->get()->getRowArray();
                if ($head && ! empty($head['user_id'])) $candidates[] = (int) $head['user_id'];
            }
        } elseif ($type === 'BRANCH_HEAD') {
            // Coba work_location dulu, fallback ke branch
            if (! empty($emp['work_location_id'])) {
                $loc = $db->table('work_locations')->where('id', (int) $emp['work_location_id'])->where('company_id', $companyId)->get()->getRowArray();
                if ($loc && ! empty($loc['manager_employee_id'])) {
                    $head = $db->table('employees')->where('id', (int) $loc['manager_employee_id'])->where('company_id', $companyId)->get()->getRowArray();
                    if ($head && ! empty($head['user_id'])) $candidates[] = (int) $head['user_id'];
                }
            }
            if (empty($candidates) && ! empty($emp['branch_id'])) {
                // branches tidak punya manager_employee_id by default; cari user di branch dengan role manager
                $rows = $db->table('users u')
                    ->select('u.id')
                    ->join('employees e', 'e.id = u.employee_id', 'left')
                    ->where('u.is_active', 1)
                    ->where('u.company_id', $companyId)
                    ->where('e.branch_id', (int) $emp['branch_id'])
                    ->where('u.role_id', 3) // manager
                    ->get()->getResultArray();
                foreach ($rows as $r) $candidates[] = (int) $r['id'];
            }
        } elseif ($type === 'HR') {
            $rows = $db->table('users')
                ->where('is_active', 1)
                ->where('company_id', $companyId)
                ->where('role_id', 2) // hr_admin
                ->get()->getResultArray();
            foreach ($rows as $r) $candidates[] = (int) $r['id'];
        } elseif ($type === 'FINANCE') {
            $rows = $db->table('users')
                ->where('is_active', 1)
                ->where('company_id', $companyId)
                ->where('role_id', 5) // finance
                ->get()->getResultArray();
            foreach ($rows as $r) $candidates[] = (int) $r['id'];
        } elseif ($type === 'ROLE' && ! empty($step['approver_role_id'])) {
            $rows = $db->table('users')
                ->where('is_active', 1)
                ->where('company_id', $companyId)
                ->where('role_id', (int) $step['approver_role_id'])
                ->get()->getResultArray();
            foreach ($rows as $r) $candidates[] = (int) $r['id'];
        } elseif ($type === 'SPECIFIC_USER' && ! empty($step['approver_user_id'])) {
            $target = $db->table('users')->where('id', (int) $step['approver_user_id'])
                ->where('company_id', $companyId)->where('is_active', 1)->get()->getRowArray();
            if ($target) $candidates[] = (int) $target['id'];
        } elseif ($type === 'SPECIFIC_EMPLOYEE' && ! empty($step['approver_employee_id'])) {
            $tgt = $db->table('employees')->where('id', (int) $step['approver_employee_id'])->where('company_id', $companyId)->get()->getRowArray();
            if ($tgt && ! empty($tgt['user_id'])) $candidates[] = (int) $tgt['user_id'];
        } elseif ($type === 'HIRING_MANAGER' && is_array($meta) && ! empty($meta['job_posting_id'])) {
            $posting = $db->table('job_postings')->where('id', (int) $meta['job_posting_id'])
                ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
            if ($posting && ! empty($posting['hiring_manager_id'])) {
                $manager = $db->table('users')->where('id', (int) $posting['hiring_manager_id'])
                    ->where('company_id', $companyId)->where('is_active', 1)->get()->getRowArray();
                if ($manager) $candidates[] = (int) $manager['id'];
            }
        }

        $candidates = array_values(array_unique($candidates));
        return $expandDelegates
            ? $this->expandWithDelegates(
                $candidates,
                (int) $request['company_id'],
                (string) $request['module']
            )
            : $candidates;
    }

    /**
     * Expand daftar approver dengan delegate yang sedang aktif.
     * Untuk setiap user di $userIds, cek `approval_delegations`:
     *   - is_active = 1
     *   - module = NULL atau = $module
     *   - hari ini ∈ [date_from, date_to]
     * Tambahkan `delegate_to_user_id` ke daftar approver. User asli TETAP
     * bertahan supaya kalau dia kembali dari cuti & login, dia bisa approve.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function expandWithDelegates(array $userIds, int $companyId, string $module): array
    {
        if (empty($userIds)) return $userIds;
        $today = date('Y-m-d');
        $db = \Config\Database::connect();
        $rows = $db->table('approval_delegations')
            ->whereIn('user_id', $userIds)
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('date_from <=', $today)
            ->where('date_to >=', $today)
            ->groupStart()
                ->where('module IS NULL')
                ->orWhere('module', $module)
            ->groupEnd()
            ->get()->getResultArray();
        $extra = [];
        foreach ($rows as $r) {
            $extra[] = (int) $r['delegate_to_user_id'];
        }
        return array_values(array_unique(array_merge($userIds, $extra)));
    }

    /**
     * Cek apakah $userId saat ini boleh approve mewakili salah satu approver
     * resmi $approverIds (lewat approval_delegations aktif).
     *
     * @param int[] $approverIds
     * @return int|null  user_id dari approver asli yang sedang didelegasikan,
     *                   atau null jika $userId bukan delegate.
     */
    private function findOriginalForDelegate(int $userId, array $approverIds, int $companyId, string $module): ?int
    {
        if (empty($approverIds)) return null;
        $today = date('Y-m-d');
        $db = \Config\Database::connect();
        $row = $db->table('approval_delegations')
            ->where('delegate_to_user_id', $userId)
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('date_from <=', $today)
            ->where('date_to >=', $today)
            ->groupStart()
                ->where('module IS NULL')
                ->orWhere('module', $module)
            ->groupEnd()
            ->whereIn('user_id', $approverIds)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        return $row ? (int) $row['user_id'] : null;
    }

    private function resolveCurrentApprovers(array $request): array
    {
        $companyId = (int) $request['company_id'];
        $module    = (string) $request['module'];
        $snapshot = $this->workflowSnapshot($request);

        if (empty($request['current_step_id'])) {
            $expected = array_values(array_map(
                'intval',
                (array) ($snapshot['fallback_approver_user_ids'] ?? [])
            ));
            return $this->expandWithDelegates(
                $expected !== [] ? $expected : $this->fallbackHrApprovers($companyId),
                $companyId, $module
            );
        }
        foreach ((array) ($snapshot['steps'] ?? []) as $stepSnapshot) {
            if ((int) ($stepSnapshot['id'] ?? 0) !== (int) $request['current_step_id']) continue;
            $expected = array_values(array_map(
                'intval',
                (array) ($stepSnapshot['expected_approver_user_ids'] ?? [])
            ));
            return $this->expandWithDelegates($expected, $companyId, $module);
        }
        $db = \Config\Database::connect();
        $step = $db->table('approval_chain_steps')->where('id', (int) $request['current_step_id'])->get()->getRowArray();
        if (! $step) return [];
        // resolveApproversForStep sudah expand internal, tapi fallback HR di
        // bawah belum — jadi kita selalu expand sekali lagi di sini supaya
        // konsisten.
        $approvers = $this->resolveApproversForStep($step, $request);
        if (empty($approvers)) {
            $chain = $db->table('approval_chains')->where('id', (int) $request['chain_id'])->get()->getRowArray();
            if (($chain['fallback_strategy'] ?? 'HR_FALLBACK') === 'HR_FALLBACK') {
                $approvers = $this->fallbackHrApprovers($companyId);
            }
        }
        return $this->expandWithDelegates($approvers, $companyId, $module);
    }

    /** @param array<string,mixed> $request */
    private function isSelfApproval(int $userId, int $employeeId, array $request = []): bool
    {
        $db = \Config\Database::connect();
        $u = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if ($u && (int) ($u['employee_id'] ?? 0) === $employeeId) return true;
        $meta = $request['meta'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        return is_array($meta)
            && (int) ($meta['submitted_by'] ?? $meta['created_by'] ?? 0) === $userId;
    }

    private function fallbackHrApprovers(int $companyId): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('users')
            ->where('is_active', 1)
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->get()->getResultArray();
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }

    private function notifyApprovers(array $request, array $step, array $approvers): void
    {
        if (empty($approvers)) return;
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', (int) $request['employee_id'])->get()->getRowArray();
        $title = "Persetujuan dibutuhkan: {$request['module']} step {$step['step_order']}";
        $msg   = ($emp['full_name'] ?? 'Seseorang') . " menunggu persetujuan Anda ({$step['name']})";
        $notifier = Services::notification();
        foreach ($approvers as $uid) {
            $notifier->send(
                (int) $uid,
                (int) $request['company_id'],
                'approval.request',
                $title,
                $msg,
                ['request_id' => (int) $request['id'], 'module' => $request['module']],
                $this->moduleLink($request['module'], (int) $request['entity_id']),
                true
            );
        }
    }

    private function moduleLink(string $module, int $entityId): string
    {
        return match ($module) {
            'LEAVE' => "/leaves/{$entityId}",
            'OVERTIME' => "/overtimes/{$entityId}",
            'ATTENDANCE_CHANGE' => "/attendance/change-requests/approvals",
            'REIMBURSEMENT' => "/reimbursements",
            'LOAN' => "/loans",
            default => "/notifications",
        };
    }
}
