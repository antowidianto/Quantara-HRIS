<?php

namespace App\Libraries\Approval;

use Config\Services;

/**
 * ApprovalEngine
 *
 * Mesin approval generic untuk modul LEAVE / OVERTIME / ATTENDANCE_CHANGE /
 * REIMBURSEMENT / LOAN. Modul caller cukup memanggil:
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
 * Modul caller bertanggung jawab mengupdate status di tabel asalnya
 * (mis. leaves.status) saat onApproved / onRejected — engine mengembalikan
 * informasi cukup agar caller bisa melakukan side-effect (decrement saldo, dll).
 */
class ApprovalEngine
{
    /**
     * @return array<string,mixed>  ['request_id' => int, 'status' => string,
     *                              'next_approvers' => int[], 'final' => bool]
     */
    public function start(array $params): array
    {
        $companyId   = (int) ($params['company_id'] ?? 0);
        $module      = (string) ($params['module'] ?? 'GENERIC');
        $entityTable = (string) ($params['entity_table'] ?? '');
        $entityId    = (int) ($params['entity_id'] ?? 0);
        $employeeId  = (int) ($params['employee_id'] ?? 0);
        $meta        = $params['meta'] ?? [];

        if (! $companyId || ! $entityTable || ! $entityId || ! $employeeId) {
            throw new \InvalidArgumentException('ApprovalEngine.start: missing required parameters');
        }

        $db = \Config\Database::connect();

        $chain = $this->resolveChain($companyId, $module, $employeeId);
        $now   = date('Y-m-d H:i:s');

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

    public function cancel(int $requestId): void
    {
        $db = \Config\Database::connect();
        $db->table('approval_requests')->where('id', $requestId)->update([
            'status'           => 'CANCELLED',
            'final_decided_at' => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
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
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
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
                return $db->table('approval_chains')->where('id', (int) $rule['chain_id'])->get()->getRowArray();
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
     * Pindah ke step berikutnya setelah lastStepOrder. Kalau tidak ada step
     * tersisa, request difinalisasi APPROVED.
     */
    private function advance(int $requestId, int $lastStepOrder): array
    {
        $db = \Config\Database::connect();
        $req = $db->table('approval_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $req) {
            return ['request_id' => $requestId, 'status' => 'NOT_FOUND', 'final' => true, 'next_approvers' => []];
        }
        $meta = is_string($req['meta']) ? (json_decode($req['meta'], true) ?: []) : ($req['meta'] ?: []);

        $steps = $db->table('approval_chain_steps')
            ->where('chain_id', (int) $req['chain_id'])
            ->orderBy('step_order', 'ASC')
            ->get()->getResultArray();

        // Cari step pertama yang stepOrder > lastStepOrder dan kondisinya
        // terpenuhi serta tidak di-skip karena allow_skip_if_self.
        foreach ($steps as $step) {
            if ((int) $step['step_order'] <= $lastStepOrder) continue;
            // Sprint 9 / H1.3 — sisipkan employee_id ke step row supaya
            // ApprovalConditionEvaluator bisa lookup department/branch/level.
            $step['__request_employee_id'] = (int) $req['employee_id'];
            if (! $this->stepConditionMet($step, $meta)) {
                $this->logSkip($requestId, $step, 'Condition not met');
                continue;
            }
            $approvers = $this->resolveApproversForStep($step, $req);
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
                $strategy = $chain['fallback_strategy'] ?? 'HR_FALLBACK';
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
        return $this->finalize($requestId, 'APPROVED');
    }

    private function decide(int $requestId, int $userId, string $decision, string $note): array
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
        $existing = $db->table('approval_decisions')
            ->where('approval_request_id', $requestId)
            ->where('chain_step_id', (int) $req['current_step_id'])
            ->where('decision', 'PENDING')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
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
            $db->table('approval_decisions')->where('id', $existing['id'])->update($decisionRow);
        } else {
            $db->table('approval_decisions')->insert(array_merge([
                'company_id'          => (int) $req['company_id'],
                'approval_request_id' => $requestId,
                'chain_step_id'       => (int) ($req['current_step_id'] ?? 0),
                'step_order'          => (int) ($req['current_step_order'] ?? 0),
                'created_at'          => $now,
            ], $decisionRow));
        }

        if ($decision === 'REJECTED') {
            return $this->finalize($requestId, 'REJECTED');
        }

        // APPROVED → maju ke step berikutnya
        return $this->advance($requestId, (int) ($req['current_step_order'] ?? 0));
    }

    private function finalize(int $requestId, string $status): array
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('approval_requests')->where('id', $requestId)->update([
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
        ];
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
    private function resolveApproversForStep(array $step, array $request): array
    {
        $db = \Config\Database::connect();
        $employeeId = (int) $request['employee_id'];
        $companyId  = (int) $request['company_id'];

        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return [];

        $type = $step['approver_type'];
        $candidates = [];

        if ($type === 'SUPERVISOR' && ! empty($emp['supervisor_id'])) {
            $sup = $db->table('employees')->where('id', (int) $emp['supervisor_id'])->get()->getRowArray();
            if ($sup && ! empty($sup['user_id'])) $candidates[] = (int) $sup['user_id'];
        } elseif ($type === 'MANAGER' && ! empty($emp['manager_id'])) {
            $mgr = $db->table('employees')->where('id', (int) $emp['manager_id'])->get()->getRowArray();
            if ($mgr && ! empty($mgr['user_id'])) $candidates[] = (int) $mgr['user_id'];
        } elseif ($type === 'DEPARTMENT_HEAD' && ! empty($emp['department_id'])) {
            $dept = $db->table('departments')->where('id', (int) $emp['department_id'])->get()->getRowArray();
            if ($dept && ! empty($dept['manager_employee_id'])) {
                $head = $db->table('employees')->where('id', (int) $dept['manager_employee_id'])->get()->getRowArray();
                if ($head && ! empty($head['user_id'])) $candidates[] = (int) $head['user_id'];
            }
        } elseif ($type === 'BRANCH_HEAD') {
            // Coba work_location dulu, fallback ke branch
            if (! empty($emp['work_location_id'])) {
                $loc = $db->table('work_locations')->where('id', (int) $emp['work_location_id'])->get()->getRowArray();
                if ($loc && ! empty($loc['manager_employee_id'])) {
                    $head = $db->table('employees')->where('id', (int) $loc['manager_employee_id'])->get()->getRowArray();
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
            $candidates[] = (int) $step['approver_user_id'];
        } elseif ($type === 'SPECIFIC_EMPLOYEE' && ! empty($step['approver_employee_id'])) {
            $tgt = $db->table('employees')->where('id', (int) $step['approver_employee_id'])->get()->getRowArray();
            if ($tgt && ! empty($tgt['user_id'])) $candidates[] = (int) $tgt['user_id'];
        }

        return $this->expandWithDelegates(
            array_values(array_unique($candidates)),
            (int) $request['company_id'],
            (string) $request['module']
        );
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

        if (empty($request['current_step_id'])) {
            return $this->expandWithDelegates(
                $this->fallbackHrApprovers($companyId),
                $companyId, $module
            );
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

    private function isSelfApproval(int $userId, int $employeeId): bool
    {
        $db = \Config\Database::connect();
        $u = $db->table('users')->where('id', $userId)->get()->getRowArray();
        return $u && (int) ($u['employee_id'] ?? 0) === $employeeId;
    }

    private function fallbackHrApprovers(int $companyId): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('users')
            ->where('is_active', 1)
            ->where('company_id', $companyId)
            ->whereIn('role_id', [2, 3])
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
