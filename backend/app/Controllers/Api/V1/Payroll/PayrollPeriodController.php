<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class PayrollPeriodController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $builder = $db->table('payroll_periods');
        $this->tenant()->applyScope($builder);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('start_date', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['approval_action'] = [
                'request_id' => null,
                'current_step' => null,
                'can_approve' => false,
            ];
            if ($row['status'] !== 'CALCULATED' || empty($row['submitted_at'])) {
                continue;
            }
            $request = $db->table('approval_requests')->where('company_id', (int) $row['company_id'])
                ->where('module', 'PAYROLL')->where('entity_table', 'payroll_periods')
                ->where('entity_id', (int) $row['id'])->where('status', 'PENDING')
                ->orderBy('id', 'DESC')->get()->getRowArray();
            if (!$request) {
                continue;
            }
            $approvers = Services::approval()->currentApprovers((int) $request['id'], (int) $row['company_id']);
            $step = !empty($request['current_step_id'])
                ? $db->table('approval_chain_steps')->select('name, step_order')
                    ->where('id', (int) $request['current_step_id'])
                    ->where('company_id', (int) $row['company_id'])->get()->getRowArray()
                : null;
            $row['approval_action'] = [
                'request_id' => (int) $request['id'],
                'current_step' => (int) ($request['current_step_order'] ?? 0),
                'current_step_name' => $step['name'] ?? null,
                'can_approve' => in_array($this->userId(), $approvers, true),
            ];
        }
        unset($row);

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $this->findPeriodForTenant($db, (int) $id);
        if (!$row) {
            return $this->fail('Not found', 404);
        }

        $row['approval_action'] = [
            'request_id' => null,
            'current_step' => null,
            'current_step_name' => null,
            'can_approve' => false,
        ];
        $makerId = (int) ($row['prepared_by'] ?? $row['created_by'] ?? 0);
        $row['can_submit'] = $row['status'] === 'CALCULATED'
            && empty($row['submitted_at'])
            && $makerId > 0
            && $makerId === $this->userId();
        if ($row['status'] === 'CALCULATED' && !empty($row['submitted_at'])) {
            $request = $db->table('approval_requests')
                ->where('company_id', (int) $row['company_id'])
                ->where('module', 'PAYROLL')
                ->where('entity_table', 'payroll_periods')
                ->where('entity_id', (int) $row['id'])
                ->where('status', 'PENDING')
                ->orderBy('id', 'DESC')
                ->get()->getRowArray();
            if ($request) {
                $approvers = Services::approval()->currentApprovers(
                    (int) $request['id'],
                    (int) $row['company_id'],
                );
                $step = !empty($request['current_step_id'])
                    ? $db->table('approval_chain_steps')->select('name, step_order')
                        ->where('id', (int) $request['current_step_id'])
                        ->where('company_id', (int) $row['company_id'])->get()->getRowArray()
                    : null;
                $row['approval_action'] = [
                    'request_id' => (int) $request['id'],
                    'current_step' => (int) ($request['current_step_order'] ?? 0),
                    'current_step_name' => $step['name'] ?? null,
                    'can_approve' => in_array($this->userId(), $approvers, true),
                ];
            }
        }

        return $this->ok($row);
    }

    public function approvalDetail($id = null)
    {
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, (int) $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }

        $companyId = (int) $period['company_id'];
        $periodId = (int) $period['id'];
        $preparedBy = (int) ($period['submitted_by'] ?? $period['prepared_by'] ?? 0);

        $preparer = null;
        if ($preparedBy > 0) {
            $preparer = $db->table('users u')
                ->select('u.id, u.name, u.email, r.name AS role_name')
                ->join('roles r', 'r.id = u.role_id', 'left')
                ->where('u.company_id', $companyId)
                ->where('u.id', $preparedBy)
                ->get()->getRowArray();
        }

        $request = $db->table('approval_requests')->where('company_id', $companyId)
            ->where('module', 'PAYROLL')->where('entity_table', 'payroll_periods')
            ->where('entity_id', $periodId)->orderBy('id', 'DESC')->get()->getRowArray();
        $chain = $request && !empty($request['chain_id'])
            ? $db->table('approval_chains')->where('id', (int) $request['chain_id'])
                ->where('company_id', $companyId)->get()->getRowArray()
            : $db->table('approval_chains')->where('company_id', $companyId)
                ->where('module', 'PAYROLL')->where('is_default', 1)->where('is_active', 1)
                ->where('deleted_at IS NULL')->get()->getRowArray();

        $snapshot = $request ? json_decode((string) ($request['workflow_snapshot'] ?? ''), true) : null;
        $steps = is_array($snapshot) && isset($snapshot['steps'])
            ? $snapshot['steps']
            : ($chain ? $db->table('approval_chain_steps')->where('company_id', $companyId)
                ->where('chain_id', (int) $chain['id'])->orderBy('step_order', 'ASC')->get()->getResultArray() : []);

        $decisions = [];
        if ($request) {
            $decisionRows = $db->table('approval_decisions d')
                ->select('d.*, u.name AS approver_name')
                ->join('users u', 'u.id = d.approver_user_id', 'left')
                ->where('d.approval_request_id', (int) $request['id'])
                ->orderBy('d.id', 'ASC')->get()->getResultArray();
            foreach ($decisionRows as $decision) {
                $decisions[(int) $decision['step_order']] = $decision;
            }
        }

        $notificationByUser = [];
        if ($request && $db->tableExists('notifications')) {
            $notifications = $db->table('notifications')
                ->select('user_id, sent_email, created_at')
                ->where('company_id', $companyId)
                ->where('type', 'approval.request')
                ->groupStart()
                ->where('link_url', '/payroll/periods')
                ->orWhere('link_url', '/approvals/inbox?request_id=' . (int) $request['id'])
                ->groupEnd()
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
            foreach ($notifications as $notification) {
                $userId = (int) $notification['user_id'];
                $notificationByUser[$userId] ??= $notification;
            }
        }

        $checkers = [];
        $missingSteps = 0;
        foreach ($steps as $step) {
            if (array_key_exists('condition_matched', $step) && !$step['condition_matched']) {
                continue;
            }
            $stepOrder = (int) $step['step_order'];
            $role = !empty($step['approver_role_id'])
                ? $db->table('roles')->where('id', (int) $step['approver_role_id'])->get()->getRowArray()
                : null;
            $candidateIds = isset($step['expected_approver_user_ids'])
                ? array_values(array_map('intval', (array) $step['expected_approver_user_ids']))
                : [];
            if ($candidateIds === [] && ($step['approver_type'] ?? '') === 'ROLE' && $role) {
                $candidateIds = array_map(
                    static fn (array $user): int => (int) $user['id'],
                    $db->table('users')->select('id')->where('company_id', $companyId)
                        ->where('role_id', (int) $role['id'])->where('is_active', 1)->get()->getResultArray(),
                );
            }
            $decision = $decisions[$stepOrder] ?? null;
            if ($candidateIds === []) {
                $missingSteps++;
                $checkers[] = [
                    'id' => null,
                    'name' => null,
                    'email' => null,
                    'role_name' => $role['name'] ?? ($step['approver_type'] ?? '-'),
                    'step_order' => $stepOrder,
                    'step_name' => $step['name'],
                    'is_current_step' => $request && (int) ($request['current_step_order'] ?? 0) === $stepOrder,
                    'decision' => $decision['decision'] ?? 'PENDING',
                    'approved_at' => $decision['decided_at'] ?? null,
                    'notified_in_app' => false,
                    'email_queued' => false,
                    'notified_at' => null,
                ];
                continue;
            }
            $users = $db->table('users u')->select('u.id, u.name, u.email, r.name AS role_name')
                ->join('roles r', 'r.id = u.role_id', 'left')->where('u.company_id', $companyId)
                ->whereIn('u.id', $candidateIds)->where('u.is_active', 1)->get()->getResultArray();
            foreach ($users as $user) {
                $userId = (int) $user['id'];
                $notification = $notificationByUser[$userId] ?? null;
                $checkers[] = array_merge($user, [
                    'step_order' => $stepOrder,
                    'step_name' => $step['name'],
                    'is_current_step' => $request && (int) ($request['current_step_order'] ?? 0) === $stepOrder,
                    'decision' => $decision && (int) ($decision['approver_user_id'] ?? 0) === $userId
                        ? $decision['decision'] : 'PENDING',
                    'approved_at' => $decision && (int) ($decision['approver_user_id'] ?? 0) === $userId
                        ? $decision['decided_at'] : null,
                    'notified_in_app' => $notification !== null,
                    'email_queued' => (int) ($notification['sent_email'] ?? 0) === 1,
                    'notified_at' => $notification['created_at'] ?? null,
                ]);
            }
        }

        $approvedCount = count(array_filter(
            $decisions,
            static fn (array $decision): bool => $decision['decision'] === 'APPROVED',
        ));
        $payrollRows = $db->table('payslips')->select([
            'id', 'slip_number', 'employee_number', 'employee_name', 'basic_salary',
            'gross_earning', 'total_deduction', 'pph21_amount', 'bpjs_kes_employee',
            'bpjs_jht_employee', 'bpjs_jp_employee', 'take_home_pay',
        ])->where('company_id', $companyId)->where('payroll_period_id', $periodId)
            ->orderBy('employee_name', 'ASC')->get()->getResultArray();
        $currentUserCanApprove = count(array_filter(
            $checkers,
            fn (array $checker): bool => (int) ($checker['id'] ?? 0) === $this->userId()
                && !empty($checker['is_current_step']),
        )) > 0;

        return $this->ok([
            'period' => [
                'id' => $periodId,
                'code' => $period['code'],
                'name' => $period['name'],
                'status' => $period['status'],
                'submitted_at' => $period['submitted_at'] ?? null,
            ],
            'preparer' => $preparer,
            'chain' => $chain ? [
                'id' => (int) $chain['id'],
                'code' => $chain['code'],
                'name' => $chain['name'],
            ] : null,
            'approval_request_id' => $request ? (int) $request['id'] : null,
            'approval_status' => $request['status'] ?? 'NOT_SUBMITTED',
            'current_step' => $request ? (int) ($request['current_step_order'] ?? 0) : null,
            'required_approvals' => count($steps),
            'approved_count' => $approvedCount,
            'checkers' => $checkers,
            'insufficient_checkers' => $chain === null || $missingSteps > 0,
            'can_approve' => $currentUserCanApprove,
            'payroll_summary' => [
                'employee_count' => count($payrollRows),
                'total_gross' => (float) ($period['total_gross'] ?? 0),
                'total_deduction' => (float) ($period['total_deduction'] ?? 0),
                'total_net' => (float) ($period['total_net'] ?? 0),
            ],
            'payroll_rows' => $payrollRows,
        ]);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'required|max_length[100]',
            'period_year' => 'required|integer',
            'start_date' => 'required|valid_date',
            'end_date' => 'required|valid_date',
            'pay_date' => 'required|valid_date',
            'type' => 'permit_empty|in_list[MONTHLY,BI_WEEKLY,WEEKLY,THR,BONUS,OFF_CYCLE]',
        ]);
        if (!$valid) {
            return null;
        }

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }

        $type = $data['type'] ?? 'MONTHLY';

        // Auto-generate code yang aman dari collision lintas tipe.
        // Format: {PREFIX}-{YEAR}-{MM}[-N] — N hanya muncul kalau ada konflik
        // (mis. dua BONUS di bulan sama). Kalau user kirim `code` eksplisit,
        // kita pakai apa adanya tanpa suffix otomatis.
        if (!empty($data['code'])) {
            $code = (string) $data['code'];
        } else {
            $code = $this->generateUniquePeriodCode($db, $companyId, $type, (int) $data['period_year'], (int) ($data['period_month'] ?? 0));
        }

        // Pre-check duplicate supaya error message ramah (bukan 500 SQL exception).
        $exists = $db->table('payroll_periods')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->countAllResults() > 0;
        if ($exists) {
            return $this->fail(
                "Kode periode '{$code}' sudah ada. Hapus atau ubah periode lama, atau tentukan kode unik.",
                422,
                'PERIOD_DUPLICATE',
                ['code' => 'duplicate']
            );
        }

        $insert = [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $data['name'],
            'type' => $type,
            'period_year' => $data['period_year'],
            'period_month' => $data['period_month'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'pay_date' => $data['pay_date'],
            'cutoff_attendance_date' => $data['cutoff_attendance_date'] ?? $data['end_date'],
            'is_december_correction' => !empty($data['is_december_correction']) ? 1 : 0,
            'status' => 'DRAFT',
            'created_by' => $this->userId(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $db->table('payroll_periods')->insert($insert);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // Last-line defense kalau ada race condition di antara pre-check & insert.
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->fail(
                    "Periode dengan kode '{$code}' sudah ada untuk perusahaan ini.",
                    422,
                    'PERIOD_DUPLICATE',
                    ['code' => 'duplicate']
                );
            }
            log_message('error', 'Period create failed: ' . $e->getMessage());

            return $this->fail('Gagal membuat periode: ' . $e->getMessage(), 500);
        }

        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('PAYROLL')
            ->logCreate(
                'payroll_period',
                $id,
                $insert,
                "Membuat periode payroll: {$insert['name']} ({$insert['start_date']} s/d {$insert['end_date']})"
            );

        return $this->ok(['id' => $id, 'code' => $code], 'Period created', 201);
    }

    /**
     * Generate kode periode unik per (company, type, year, month).
     *
     * Prefix per tipe:
     *   MONTHLY    → P
     *   BI_WEEKLY  → BW
     *   WEEKLY     → WK
     *   THR        → THR
     *   BONUS      → BNS
     *
     * Bila ada konflik (mis. multiple BONUS di bulan yang sama), tambahkan
     * suffix `-2`, `-3`, dst. sampai ketemu yang free. Maksimal 99 percobaan
     * untuk safety; di atas itu controller akan biarkan caller ngirim `code`
     * eksplisit.
     */
    private function generateUniquePeriodCode(
        \CodeIgniter\Database\BaseConnection $db,
        int $companyId,
        string $type,
        int $year,
        int $month
    ): string {
        $prefix = match ($type) {
            'BI_WEEKLY' => 'BW',
            'WEEKLY' => 'WK',
            'THR' => 'THR',
            'BONUS' => 'BNS',
            'OFF_CYCLE' => 'OC',
            default => 'P',
        };
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $base = sprintf('%s-%d-%s', $prefix, $year, $monthStr);

        for ($i = 1; $i <= 99; $i++) {
            $candidate = $i === 1 ? $base : ($base . '-' . $i);
            $exists = $db->table('payroll_periods')
                ->where('company_id', $companyId)
                ->where('code', $candidate)
                ->countAllResults() === 0;
            if ($exists) {
                return $candidate;
            }
        }

        // Fallback: include timestamp untuk jamin unique.
        return $base . '-' . substr((string) time(), -5);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findPeriodForTenant($db, $id);
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if ($existing['status'] !== 'DRAFT') {
            return $this->fail('Cannot update non-draft period', 422);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name', 'start_date', 'end_date', 'pay_date', 'cutoff_attendance_date']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('payroll_periods')->where('id', $id)->where('company_id', (int) $existing['company_id'])->update($update);

        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $this->findPeriodForTenant($db, $id);
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if (!in_array($existing['status'], ['DRAFT', 'CALCULATED'], true)) {
            return $this->fail('Cannot delete finalized period', 422);
        }
        $db->table('payslip_details')->whereIn('payslip_id', function ($q) use ($id) {
            $q->select('id')->from('payslips')->where('payroll_period_id', $id);
        })->delete();
        $db->table('payslips')->where('payroll_period_id', $id)->where('company_id', (int) $existing['company_id'])->delete();
        $db->table('payroll_periods')->where('id', $id)->where('company_id', (int) $existing['company_id'])->delete();
        Services::audit()
            ->forModule('PAYROLL')
            ->logDelete(
                'payroll_period',
                $id,
                $existing,
                "Menghapus periode payroll: {$existing['name']} ({$existing['code']})"
            );

        return $this->ok(null, 'Deleted');
    }

    public function calculate($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $initialPeriod = $this->findPeriodForTenant($db, $id);
        if (!$initialPeriod) {
            return $this->fail('Period not found', 404);
        }
        $monitor = new \App\Libraries\System\BusinessJobMonitor($db);
        $runId = $monitor->start('payroll.calculate', (int) $initialPeriod['company_id'], (string) $id);
        $audit = Services::audit()->forModule('PAYROLL')->start();

        try {
            $summary = Services::payroll()->calculatePeriod($id);
            $period = $this->findPeriodForTenant($db, $id);
            $summary['variance'] = (new \App\Libraries\Payroll\PayrollVarianceService())
                ->analyzeAndStore((int) $period['company_id'], $id);

            // Audit description berbeda kalau ada pending approvals,
            // supaya audit trail jelas: hitung dilakukan tetap, tapi ada
            // transaksi yang diabaikan & menunggu approval.
            $hasWarnings = !empty($summary['warnings']);
            $description = $hasWarnings
                ? sprintf(
                    'Menghitung payroll periode #%d dengan %d warning pending approval (%s)',
                    $id,
                    count($summary['warnings']),
                    implode(', ', array_map(
                        static fn ($w) => "{$w['type']}={$w['count']}",
                        $summary['warnings']
                    )),
                )
                : 'Menghitung payroll periode #' . $id;

            $audit->log(
                action: 'payroll_period.calculate',
                entity: 'payroll_period',
                entityId: $id,
                after: $summary,
                description: $description,
                severity: $hasWarnings ? 'WARNING' : 'NOTICE'
            );

            $message = $hasWarnings
                ? 'Period calculated dengan warning — ada pengajuan yang masih PENDING approval'
                : 'Period calculated';
            $monitor->succeed($runId, (int) ($summary['employee_count'] ?? $summary['count'] ?? 0), [
                'period_id' => $id, 'warning_count' => count($summary['warnings'] ?? []),
            ]);

            return $this->ok($summary, $message);
        } catch (\Throwable $e) {
            $monitor->fail($runId, 'PAYROLL_CALCULATE_FAILED', 1, ['period_id' => $id]);
            log_message('error', $e->getMessage());
            $audit->logFailure(
                'payroll_period.calculate',
                'payroll_period',
                $id,
                'CALCULATE_FAILED',
                'Gagal menghitung payroll periode #' . $id . ': ' . $e->getMessage()
            );

            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * GET /payroll-periods/(:num)/pending-approvals
     *
     * Pre-check sebelum HR meng-klik "Hitung". Return daftar transaksi
     * yang memengaruhi payroll & masih PENDING approval di window
     * periode ini, beserta pesan warning Bahasa Indonesia per kategori.
     *
     * Tidak memodifikasi state — purely read-only.
     */
    public function pendingApprovals($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }

        try {
            $pending = Services::payroll()->pendingApprovalsForPeriod($id, $period);
        } catch (\Throwable $e) {
            log_message('error', 'pendingApprovalsForPeriod failed: ' . $e->getMessage());

            return $this->fail('Gagal memuat pending approvals: ' . $e->getMessage(), 500);
        }

        // Bentuk warning string yang sama dengan calculate() supaya UI
        // bisa render array sederhana tanpa duplikasi logic.
        $labels = [
            'overtime' => 'pengajuan lembur',
            'attendance_change' => 'koreksi absensi',
            'reimbursement' => 'pengajuan reimbursement',
            'loan' => 'pengajuan kasbon',
            'bonus_run' => 'bonus run',
        ];
        $warnings = [];
        foreach ($pending['counts'] as $type => $count) {
            if ($count <= 0) {
                continue;
            }
            $warnings[] = [
                'type' => $type,
                'count' => $count,
                'message' => sprintf(
                    'Ada %d %s yang masih menunggu approval — perhitungan periode ini akan mengabaikan transaksi tersebut sampai di-approve & re-calculate.',
                    $count,
                    $labels[$type] ?? $type,
                ),
            ];
        }

        return $this->ok([
            'period' => [
                'id' => (int) $period['id'],
                'code' => $period['code'],
                'name' => $period['name'],
                'start_date' => $period['start_date'],
                'end_date' => $period['end_date'],
                'status' => $period['status'],
            ],
            'total_count' => $pending['total_count'],
            'counts' => $pending['counts'],
            'items' => $pending['items'],
            'warnings' => $warnings,
            'has_blocker' => false, // info-only; calculate tidak di-block
        ]);
    }

    public function finalize($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }
        $audit = Services::audit()->forModule('PAYROLL')->start();

        try {
            (new \App\Libraries\Payroll\PayrollControlService())->assertCanFinalize(
                (int) $period['company_id'],
                $id,
                $this->userId(),
            );
            Services::payroll()->finalize($id);
            $audit->log(
                action: 'payroll_period.finalize',
                entity: 'payroll_period',
                entityId: $id,
                description: 'Finalisasi payroll periode #' . $id . ' dan siap untuk disbursement',
                severity: 'CRITICAL'
            );

            // Sprint 14+ — webhook outbound `payroll.period_finalized`.
            // Lookup periode untuk kirim metadata dasar (no PII slip).
            $period = $this->findPeriodForTenant($db, $id);
            if ($period) {
                // Hitung ringkasan: total payslip + grand total net pay.
                // NOTE: kolom di tabel `payslips` adalah `take_home_pay`
                // (lihat migration 2024-01-01-000005_CreatePayrollTables).
                // Sebelumnya pakai `net_pay` yang menyebabkan SQL error
                // "Unknown column 'net_pay' in 'field list'" saat finalize.
                $sum = $db->table('payslips')
                    ->selectSum('take_home_pay', 'total_net')
                    ->selectCount('id', 'count')
                    ->where('payroll_period_id', $id)
                    ->where('company_id', (int) $period['company_id'])
                    ->get()->getRowArray();

                $this->dispatchWebhook(
                    \App\Constants\WebhookEvent::PAYROLL_PERIOD_FINALIZED,
                    (int) $period['company_id'],
                    [
                        'payroll_period_id' => $id,
                        'name' => $period['name'] ?? null,
                        'period_year' => isset($period['period_year']) ? (int) $period['period_year'] : null,
                        'start_date' => $period['start_date'] ?? null,
                        'end_date' => $period['end_date'] ?? null,
                        'pay_date' => $period['pay_date'] ?? null,
                        'status' => $period['status'] ?? 'PAID',
                        'payslip_count' => (int) ($sum['count'] ?? 0),
                        'total_net_pay' => (float) ($sum['total_net'] ?? 0),
                        'finalized_by' => $this->userId(),
                        'finalized_at' => date('c'),
                    ],
                );
            }

            return $this->ok(null, 'Period finalized and ready for disbursement');
        } catch (\Throwable $e) {
            $audit->logFailure(
                'payroll_period.finalize',
                'payroll_period',
                $id,
                'FINALIZE_FAILED',
                'Gagal finalisasi payroll periode #' . $id . ': ' . $e->getMessage()
            );

            return $this->fail($e->getMessage(), 500);
        }
    }

    public function submit($id = null)
    {
        $period = $this->findPeriodForCurrentTenant((int) $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }
        $makerId = (int) ($period['prepared_by'] ?? $period['created_by'] ?? 0);
        if ($makerId > 0 && $makerId !== $this->userId()) {
            return $this->fail(
                'Hanya pembuat payroll yang dapat mengajukan ke approval chain',
                403,
                'PAYROLL_MAKER_ONLY',
            );
        }

        try {
            $result = (new \App\Libraries\Payroll\PayrollApprovalWorkflowService())->submit(
                (int) $period['id'],
                (int) $period['company_id'],
                $this->userId()
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_period.submit',
                'payroll_period',
                (int) $period['id'],
                description: 'Payroll submitted for independent approval',
                severity: 'NOTICE'
            );

            return $this->ok($result, 'Payroll submitted to approval chain');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_SUBMIT_BLOCKED');
        }
    }

    public function reviewVariance($id = null)
    {
        $period = $this->findPeriodForCurrentTenant((int) $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }
        $data = $this->getJsonInput();

        try {
            $updated = (new \App\Libraries\Payroll\PayrollVarianceService())->review(
                (int) $period['company_id'],
                (int) $period['id'],
                $this->userId(),
                (string) ($data['note'] ?? ''),
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_period.variance_review',
                'payroll_period',
                (int) $period['id'],
                description: 'Payroll variance reviewed before submission',
                severity: 'NOTICE',
                metadata: ['note' => $data['note'] ?? null],
            );

            return $this->ok($updated, 'Payroll variance reviewed');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_VARIANCE_REVIEW_BLOCKED');
        }
    }

    public function approve($id = null)
    {
        $period = $this->findPeriodForCurrentTenant((int) $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }

        try {
            $data = $this->getJsonInput();
            if (($data['review_confirmed'] ?? false) !== true) {
                return $this->fail(
                    'Konfirmasi review detail payroll wajib dicentang',
                    422,
                    'PAYROLL_REVIEW_CONFIRMATION_REQUIRED',
                );
            }
            if (!hash_equals((string) $period['code'], trim((string) ($data['confirmation_code'] ?? '')))) {
                return $this->fail(
                    'Kode periode konfirmasi tidak sesuai',
                    422,
                    'PAYROLL_CONFIRMATION_CODE_MISMATCH',
                );
            }
            $result = (new \App\Libraries\Payroll\PayrollApprovalWorkflowService())->approve(
                (int) $period['id'],
                (int) $period['company_id'],
                $this->userId(),
                trim((string) ($data['note'] ?? '')),
            );
            Services::audit()->forModule('PAYROLL')->log(
                'payroll_period.approve',
                'payroll_period',
                (int) $period['id'],
                description: 'Payroll independently approved with immutable snapshot',
                severity: 'CRITICAL'
            );
            $message = !empty($result['approval']['final'])
                ? 'Payroll approval chain completed'
                : 'Approval recorded; forwarded to the next payroll approver';

            return $this->ok($result, $message);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), 403, 'SEGREGATION_OF_DUTIES');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'PAYROLL_APPROVAL_BLOCKED');
        }
    }

    public function breakGlassFinalize($id = null)
    {
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, (int) $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }
        $reason = trim((string) ($this->getJsonInput()['reason'] ?? ''));

        try {
            if ($period['status'] === 'CALCULATED') {
                (new \App\Libraries\Payroll\PayrollControlService())->breakGlassApprove(
                    (int) $period['company_id'],
                    (int) $period['id'],
                    $this->userId(),
                    $reason,
                    $this->request->getIPAddress(),
                    $this->request->getUserAgent()->getAgentString(),
                );
            } elseif ($period['status'] === 'APPROVED') {
                $evidence = $db->table('payroll_period_approvals')
                    ->where('company_id', (int) $period['company_id'])
                    ->where('payroll_period_id', (int) $period['id'])
                    ->where('approval_type', 'BREAK_GLASS')
                    ->countAllResults();
                if ($evidence === 0) {
                    throw new \RuntimeException('Approved payroll has no break-glass evidence');
                }
            } else {
                throw new \RuntimeException('Payroll is not eligible for break-glass finalization');
            }

            Services::audit()->forModule('PAYROLL')->log(
                action: 'payroll_period.break_glass_finalize',
                entity: 'payroll_period',
                entityId: (int) $period['id'],
                after: ['reason' => $reason, 'actor_user_id' => $this->userId()],
                description: 'EMERGENCY payroll finalization: ' . $reason,
                severity: 'CRITICAL',
            );
            $this->notifyPayrollBreakGlass((int) $period['company_id'], (int) $period['id'], $reason);
            Services::payroll()->finalize((int) $period['id']);

            return $this->ok(null, 'Payroll finalized through break-glass workflow');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'BREAK_GLASS_REASON_REQUIRED');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'BREAK_GLASS_BLOCKED');
        }
    }

    private function notifyPayrollBreakGlass(int $companyId, int $periodId, string $reason): void
    {
        try {
            $db = \Config\Database::connect();
            $users = $db->table('users u')
                ->select('DISTINCT u.id')
                ->join('role_permissions rp', 'rp.role_id = u.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('u.company_id', $companyId)
                ->where('u.is_active', 1)
                ->whereIn('p.slug', ['payroll.finalize', 'payroll.break_glass'])
                ->get()->getResultArray();
            foreach ($users as $user) {
                Services::notification()->send(
                    (int) $user['id'],
                    $companyId,
                    'payroll.break_glass',
                    'Payroll break-glass digunakan',
                    "Periode #{$periodId} difinalisasi darurat. Alasan: {$reason}",
                    ['payroll_period_id' => $periodId, 'severity' => 'CRITICAL'],
                    "/payroll/periods/{$periodId}",
                    true
                );
            }
        } catch (\Throwable $e) {
            log_message('error', 'Break-glass notification failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /payroll-periods/(:num)/bank-export?bank=BCA
     * Download CSV batch transfer bank.
     */
    public function bankExport($id = null)
    {
        $id = (int) $id;
        $bank = strtoupper((string) ($this->request->getGet('bank') ?? 'BCA'));

        try {
            $file = Services::payrollExporter()->bankTransfer($id, $bank);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.bank',
            entity: 'payroll_period',
            entityId: $id,
            description: "Download bank transfer ({$bank}) periode #{$id}",
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/pph21-export?final=1
     * Download CSV e-SPT PPh21 untuk masa.
     */
    public function pph21Export($id = null)
    {
        $id = (int) $id;
        $final = (int) ($this->request->getGet('final') ?? 0) === 1;

        try {
            $file = Services::payrollExporter()->esptPph21($id, $final);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.pph21',
            entity: 'payroll_period',
            entityId: $id,
            description: 'Download e-SPT PPh21 periode #' . $id . ($final ? ' (FINAL)' : ''),
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/bpjs-export?type=TK|KES
     * Download CSV iuran BPJS Ketenagakerjaan (SiPP) atau Kesehatan (e-Dabu).
     */
    public function bpjsExport($id = null)
    {
        $id = (int) $id;
        $type = strtoupper((string) ($this->request->getGet('type') ?? 'TK'));
        if (!in_array($type, ['TK', 'KES'], true)) {
            return $this->fail('type harus TK atau KES', 422);
        }

        try {
            $file = $type === 'TK'
                ? Services::payrollExporter()->bpjsKetenagakerjaan($id)
                : Services::payrollExporter()->bpjsKesehatan($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.bpjs_' . strtolower($type),
            entity: 'payroll_period',
            entityId: $id,
            description: "Download BPJS {$type} periode #{$id}",
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/export-summary
     * Ringkasan ekspor: jumlah karyawan, total amount, daftar bank yg dipakai,
     * apakah ada karyawan tanpa rekening / NPWP / BPJS.
     */
    public function exportSummary($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }

        $payslips = $db->table('payslips p')
            ->select('p.id, p.take_home_pay, p.gross_earning, p.pph21_amount,'
                . ' e.full_name, e.employee_number, e.bank_name, e.bank_account_encrypted,'
                . ' e.npwp_encrypted, e.bpjs_kesehatan_no, e.bpjs_tk_no')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $id)
            ->where('p.company_id', (int) $period['company_id'])
            ->get()->getResultArray();

        $bankBuckets = [];
        $missingAccount = [];
        $missingNpwp = [];
        $missingBpjsKes = [];
        $missingBpjsTk = [];
        $totalNet = 0;
        $totalPph = 0;

        foreach ($payslips as $p) {
            $totalNet += (float) $p['take_home_pay'];
            $totalPph += (float) $p['pph21_amount'];

            $bank = strtoupper((string) ($p['bank_name'] ?? ''));
            if ($bank !== '') {
                $bankBuckets[$bank] = ($bankBuckets[$bank] ?? 0) + 1;
            }

            $miss = ['id' => $p['id'], 'name' => $p['full_name'], 'no' => $p['employee_number']];
            if (empty($p['bank_account_encrypted'])) {
                $missingAccount[] = $miss;
            }
            if (empty($p['npwp_encrypted'])) {
                $missingNpwp[] = $miss;
            }
            if (empty($p['bpjs_kesehatan_no'])) {
                $missingBpjsKes[] = $miss;
            }
            if (empty($p['bpjs_tk_no'])) {
                $missingBpjsTk[] = $miss;
            }
        }

        return $this->ok([
            'period' => $period,
            'employees' => count($payslips),
            'total_net' => $totalNet,
            'total_pph21' => $totalPph,
            'bank_breakdown' => $bankBuckets,
            'missing' => [
                'bank_account' => $missingAccount,
                'npwp' => $missingNpwp,
                'bpjs_kes' => $missingBpjsKes,
                'bpjs_tk' => $missingBpjsTk,
            ],
            'supported_banks' => \App\Libraries\Payroll\PayrollExporter::supportedBanks(),
        ]);
    }

    /**
     * POST /payroll-periods/(:num)/ebupot-sync
     * Mengirim data PPh21 ke API PJAP.
     */
    public function syncEbupot($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $period = $this->findPeriodForTenant($db, $id);
        if (!$period) {
            return $this->fail('Period not found', 404);
        }
        if ($period['status'] !== 'PAID') {
            return $this->fail('Periode harus dalam status PAID sebelum melapor e-Bupot', 422);
        }

        $payslips = $db->table('payslips p')
            ->select('p.*, e.npwp_encrypted, e.full_name')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $id)
            ->where('p.company_id', (int) $period['company_id'])
            ->where('p.pph21_amount >', 0)
            ->get()->getResultArray();

        if (empty($payslips)) {
            return $this->fail('Tidak ada potongan PPh 21 pada periode ini', 422);
        }

        try {
            $ebupotService = new \App\Libraries\Tax\Providers\MockEbupotService();
            $batchId = $ebupotService->uploadBuktiPotong($payslips);

            $db->table('payroll_periods')->where('id', $id)->where('company_id', (int) $period['company_id'])->update([
                'ebupot_status' => 'IN_PROGRESS',
                'ebupot_reference' => $batchId,
            ]);

            // Pura-puranya langsung ngecek status
            $status = $ebupotService->getUploadStatus($batchId);
            if ($status['status'] === 'COMPLETED') {
                $db->table('payroll_periods')->where('id', $id)->where('company_id', (int) $period['company_id'])->update(['ebupot_status' => 'COMPLETED']);
                $db->table('payslips')->where('payroll_period_id', $id)->where('company_id', (int) $period['company_id'])->update(['ebupot_status' => 'COMPLETED']);
            }

            Services::audit()->forModule('PAYROLL')->log(
                action: 'payroll.ebupot_sync',
                entity: 'payroll_period',
                entityId: $id,
                description: "Sync e-Bupot PPh21 ke PJAP (Batch: {$batchId})"
            );

            return $this->ok(['batch_id' => $batchId, 'status' => $status['status']], 'Sinkronisasi e-Bupot dimulai');
        } catch (\Throwable $e) {
            $db->table('payroll_periods')->where('id', $id)->where('company_id', (int) $period['company_id'])->update([
                'ebupot_status' => 'FAILED',
                'ebupot_error' => $e->getMessage(),
            ]);

            return $this->fail('Gagal sinkronisasi e-Bupot: ' . $e->getMessage(), 500);
        }
    }

    /** Resolve a payroll period only inside the active/selected tenant. */
    private function findPeriodForCurrentTenant(int $id): ?array
    {
        return $this->findPeriodForTenant(\Config\Database::connect(), $id);
    }

    private function findPeriodForTenant(\CodeIgniter\Database\BaseConnection $db, int $id): ?array
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return null;
        }

        return $db->table('payroll_periods')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();
    }
}
