<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * SeveranceController
 *
 * REST API untuk Severance / Pesangon (PHK).
 *
 * Workflow:
 *   1. HR membuat preview perhitungan (POST /severance/calculate) — tidak menyimpan ke DB.
 *   2. HR simpan perhitungan sebagai DRAFT (POST /severance) untuk dibahas/dieskalasi.
 *   3. Setelah disetujui, HR finalize (POST /severance/(:num)/finalize) — status FINALIZED, snapshot HTML disimpan.
 *   4. Saat dibayarkan (mis. lewat payroll khusus), HR tandai PAID (POST /severance/(:num)/mark-paid).
 *   5. Kalau gagal/keliru, HR cancel (POST /severance/(:num)/cancel).
 *
 * Authorization:
 *   - `severance.view` → list/show
 *   - `severance.manage` → create/finalize/mark-paid/cancel
 */
class SeveranceController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = (string) ($this->request->getGet('status') ?? '');

        $builder = $db->table('severance_calculations sc')
            ->select('sc.*, e.full_name AS employee_name, e.employee_number, e.join_date,'
                . ' p.name AS position_name, d.name AS department_name')
            ->join('employees e', 'e.id = sc.employee_id')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('sc.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): pesangon =
        // sangat sensitif. Tanpa `severance.view`/`manage`, paksa filter
        // ke milik sendiri. Sebelumnya `?employee_id=X` mentah → bocor.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'severance',
            companyColumn: 'sc.company_id',
            employeeColumn: 'sc.employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($status !== '' && in_array($status, ['DRAFT','FINALIZED','PAID','CANCELLED'], true)) {
            $builder->where('sc.status', $status);
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('sc.termination_date', 'DESC')
            ->orderBy('sc.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        return $this->ok($row);
    }

    /**
     * GET /severance/reasons
     * Daftar alasan PHK yang available (untuk dropdown UI).
     */
    public function reasons()
    {
        return $this->ok(\App\Libraries\Lifecycle\SeveranceCalculator::reasonOptions());
    }

    /**
     * POST /severance/calculate
     * Preview perhitungan TANPA menyimpan ke DB. Pakai untuk kalkulator
     * cepat sebelum HR commit ke DRAFT.
     */
    public function preview()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id'        => 'required|integer',
            'termination_date'   => 'required|valid_date',
            'termination_reason' => 'required',
            'last_monthly_salary'=> 'permit_empty|numeric',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $emp = $db->table('employees e')
            ->select('e.*, p.name AS position_name, d.name AS department_name, c.city AS company_city, c.name AS company_name')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('companies c', 'c.id = e.company_id', 'left')
            ->where('e.id', (int) $data['employee_id'])
            ->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $calc = Services::severance();
        $serviceYears = (float) ($data['service_years']
            ?? $calc->computeServiceYears($emp['join_date'], $data['termination_date']));
        $salary = (float) ($data['last_monthly_salary']
            ?? $emp['basic_salary'] + ($emp['fixed_allowance'] ?? 0));

        // Default unused leave dari leave_balances kalau tidak diberikan.
        // Schema kolomnya `period_year` (bukan `year`) — lihat migrasi
        // 000004_CreateLeaveOvertimeTables. Filter category=ANNUAL bukan
        // code agar tidak bergantung kode lokal mis. 'ANN', 'CT', dst.
        $unusedLeave = $data['unused_leave_days'] ?? null;
        if ($unusedLeave === null) {
            $year = (int) date('Y', strtotime($data['termination_date']));
            $row = $db->table('leave_balances lb')
                ->select('lb.remaining')
                ->join('leave_types lt', 'lt.id = lb.leave_type_id')
                ->where('lb.employee_id', (int) $emp['id'])
                ->where('lb.period_year', $year)
                ->where('lt.category', 'ANNUAL')
                ->orderBy('lb.id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            $unusedLeave = $row ? (float) $row['remaining'] : 0;
        }

        try {
            $result = $calc->calculate([
                'employee_id'         => (int) $emp['id'],
                'last_monthly_salary' => $salary,
                'service_years'       => $serviceYears,
                'termination_reason'  => $data['termination_reason'],
                'termination_date'    => $data['termination_date'],
                'unused_leave_days'   => (float) $unusedLeave,
                'uph_homecoming_amount'=> (float) ($data['uph_homecoming_amount'] ?? 0),
                'uph_other_amount'    => (float) ($data['uph_other_amount'] ?? 0),
                'separation_pay'      => (float) ($data['separation_pay'] ?? 0),
                'work_days_per_month' => (int) ($data['work_days_per_month'] ?? 22),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // Sertakan render HTML preview untuk UI
        $result['termination_date'] = $data['termination_date'];
        $html = $calc->renderBreakdown($result, $emp, ['city' => $emp['company_city']]);

        return $this->ok([
            'employee'  => [
                'id'              => (int) $emp['id'],
                'full_name'       => $emp['full_name'],
                'employee_number' => $emp['employee_number'],
                'join_date'       => $emp['join_date'],
                'position_name'   => $emp['position_name'],
                'department_name' => $emp['department_name'],
                'basic_salary'    => (float) $emp['basic_salary'],
            ],
            'result'    => $result,
            'breakdown' => $html,
        ]);
    }

    /**
     * POST /severance
     * Simpan perhitungan sebagai DRAFT.
     */
    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id'        => 'required|integer',
            'termination_date'   => 'required|valid_date',
            'termination_reason' => 'required',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $emp = $db->table('employees')->where('id', (int) $data['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $companyId) {
            return $this->fail('Karyawan dari perusahaan lain', 422);
        }

        // Lakukan kalkulasi → simpan snapshot komponen
        $calc = Services::severance();
        $serviceYears = (float) ($data['service_years']
            ?? $calc->computeServiceYears($emp['join_date'], $data['termination_date']));
        $salary = (float) ($data['last_monthly_salary']
            ?? $emp['basic_salary'] + ($emp['fixed_allowance'] ?? 0));

        try {
            $result = $calc->calculate([
                'employee_id'         => (int) $emp['id'],
                'last_monthly_salary' => $salary,
                'service_years'       => $serviceYears,
                'termination_reason'  => $data['termination_reason'],
                'termination_date'    => $data['termination_date'],
                'unused_leave_days'   => (float) ($data['unused_leave_days'] ?? 0),
                'uph_homecoming_amount'=> (float) ($data['uph_homecoming_amount'] ?? 0),
                'uph_other_amount'    => (float) ($data['uph_other_amount'] ?? 0),
                'separation_pay'      => (float) ($data['separation_pay'] ?? 0),
                'work_days_per_month' => (int) ($data['work_days_per_month'] ?? 22),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => $companyId,
            'employee_id'       => (int) $emp['id'],
            'disciplinary_record_id' => ! empty($data['disciplinary_record_id'])
                ? (int) $data['disciplinary_record_id'] : null,
            'termination_date'  => $data['termination_date'],
            'termination_reason'=> $result['termination_reason'],
            'reason_label'      => $result['reason_label'],
            'last_monthly_salary'=> $result['last_monthly_salary'],
            'service_years'     => $result['service_years'],
            'service_months'    => $result['service_months'],
            'pesangon_multiplier'=> $result['pesangon_multiplier'],
            'pesangon_months'   => $result['pesangon_months'],
            'pesangon_amount'   => $result['pesangon_amount'],
            'upmk_multiplier'   => $result['upmk_multiplier'],
            'upmk_months'       => $result['upmk_months'],
            'upmk_amount'       => $result['upmk_amount'],
            'uph_unused_leave_amount'=> $result['uph_unused_leave_amount'],
            'uph_homecoming_amount'  => $result['uph_homecoming_amount'],
            'uph_other_amount'  => $result['uph_other_amount'],
            'uph_total'         => $result['uph_total'],
            'separation_pay'    => $result['separation_pay'],
            'unused_leave_days' => $result['unused_leave_days'],
            'total_amount'      => $result['total_amount'],
            'notes'             => $data['notes'] ?? null,
            'status'            => 'DRAFT',
            'calculated_by'     => $this->userId(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        // Sprint 6 — Cost Center: tag pesangon ke cost center karyawan
        // sehingga muncul di laporan budget vs actual kategori SEVERANCE.
        // Resolve chain: override → employee → dept → branch → company root.
        if (in_array('cost_center_id', $db->getFieldNames('severance_calculations'), true)) {
            try {
                $insert['cost_center_id'] = Services::costCenter()->resolveForEmployee(
                    (int) $emp['id'],
                    ['cost_center_id' => $data['cost_center_id'] ?? null],
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Severance cost_center resolve failed: ' . $e->getMessage());
            }
        }

        $db->table('severance_calculations')->insert($insert);
        $id = (int) $db->insertID();

        // logCreate signature: (entity, entityId, after, description, metadata)
        // Severity ditentukan internal oleh AuditLogger; tidak ada named param.
        // Semua method dengan severity custom pakai `log(action: ..., severity: ...)`.
        Services::audit()->forModule('HR')->logCreate(
            'severance_calculation',
            $id,
            $insert,
            "Hitung severance untuk {$emp['full_name']} ({$result['reason_label']}, total: "
                . number_format($result['total_amount'], 0, ',', '.') . ')',
        );
        return $this->ok(['id' => $id, 'result' => $result], 'Perhitungan disimpan sebagai DRAFT', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa diedit', 422);
        }

        $data = $this->getJsonInput();

        // Re-kalkulasi penuh kalau parameter penting berubah
        $calc = Services::severance();
        $params = [
            'employee_id'         => (int) $row['employee_id'],
            'last_monthly_salary' => (float) ($data['last_monthly_salary'] ?? $row['last_monthly_salary']),
            'service_years'       => (float) ($data['service_years'] ?? $row['service_years']),
            'termination_reason'  => (string) ($data['termination_reason'] ?? $row['termination_reason']),
            'termination_date'    => (string) ($data['termination_date'] ?? $row['termination_date']),
            'unused_leave_days'   => (float) ($data['unused_leave_days'] ?? $row['unused_leave_days']),
            'uph_homecoming_amount'=> (float) ($data['uph_homecoming_amount'] ?? $row['uph_homecoming_amount']),
            'uph_other_amount'    => (float) ($data['uph_other_amount'] ?? $row['uph_other_amount']),
            'separation_pay'      => (float) ($data['separation_pay'] ?? $row['separation_pay']),
        ];
        try {
            $result = $calc->calculate($params);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $update = [
            'termination_date'  => $params['termination_date'],
            'termination_reason'=> $result['termination_reason'],
            'reason_label'      => $result['reason_label'],
            'last_monthly_salary'=> $result['last_monthly_salary'],
            'service_years'     => $result['service_years'],
            'service_months'    => $result['service_months'],
            'pesangon_multiplier'=> $result['pesangon_multiplier'],
            'pesangon_months'   => $result['pesangon_months'],
            'pesangon_amount'   => $result['pesangon_amount'],
            'upmk_multiplier'   => $result['upmk_multiplier'],
            'upmk_months'       => $result['upmk_months'],
            'upmk_amount'       => $result['upmk_amount'],
            'uph_unused_leave_amount'=> $result['uph_unused_leave_amount'],
            'uph_homecoming_amount'  => $result['uph_homecoming_amount'],
            'uph_other_amount'  => $result['uph_other_amount'],
            'uph_total'         => $result['uph_total'],
            'separation_pay'    => $result['separation_pay'],
            'unused_leave_days' => $result['unused_leave_days'],
            'total_amount'      => $result['total_amount'],
            'notes'             => $data['notes'] ?? $row['notes'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        \Config\Database::connect()->table('severance_calculations')->where('id', (int) $id)->update($update);

        Services::audit()->forModule('HR')->logUpdate(
            'severance_calculation', (int) $id, $row, $update,
            "Update severance #{$id}"
        );
        return $this->ok(null, 'Updated');
    }

    /**
     * POST /severance/(:num)/finalize
     * Status DRAFT → FINALIZED. Snapshot HTML disimpan.
     */
    public function finalize($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') return $this->fail('Hanya DRAFT yang bisa di-finalize', 422);

        $db = \Config\Database::connect();
        $emp = $db->table('employees e')
            ->select('e.*, p.name AS position_name, d.name AS department_name, c.city AS company_city')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('companies c', 'c.id = e.company_id', 'left')
            ->where('e.id', (int) $row['employee_id'])
            ->get()->getRowArray();

        $html = Services::severance()->renderBreakdown($row, $emp ?: [], ['city' => $emp['company_city'] ?? '']);
        $now = date('Y-m-d H:i:s');
        $db->table('severance_calculations')->where('id', (int) $id)->update([
            'status'         => 'FINALIZED',
            'breakdown_html' => $html,
            'finalized_at'   => $now,
            'finalized_by'   => $this->userId(),
            'updated_at'     => $now,
        ]);

        Services::audit()->forModule('HR')->log(
            action: 'severance.finalize',
            entity: 'severance_calculation', entityId: (int) $id,
            description: "Finalize severance #{$id} (total: " . number_format((float) $row['total_amount'], 0, ',', '.') . ')',
            severity: 'CRITICAL'
        );

        // Sprint 14+ — webhook outbound `severance.finalized`
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::SEVERANCE_FINALIZED,
            (int) $row['company_id'],
            [
                'severance_id'  => (int) $id,
                'employee'      => $emp ? [
                    'id'              => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                    'name'            => $emp['full_name'] ?? null,
                ] : null,
                'reason_code'   => $row['reason_code'] ?? null,
                'total_amount'  => (float) ($row['total_amount'] ?? 0),
                'effective_date' => $row['effective_date'] ?? null,
                'finalized_by'  => $this->userId(),
                'finalized_at'  => $now,
            ],
        );

        return $this->ok(null, 'Severance difinalisasi');
    }

    /**
     * POST /severance/(:num)/mark-paid
     * Status FINALIZED → PAID. Catatan pembayaran (mis. ke akun tertentu).
     */
    public function markPaid($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'FINALIZED') return $this->fail('Hanya FINALIZED yang bisa di-mark PAID', 422);

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('severance_calculations')->where('id', (int) $id)->update([
            'status'   => 'PAID',
            'paid_at'  => $now,
            'paid_by'  => $this->userId(),
            'updated_at' => $now,
        ]);
        Services::audit()->forModule('HR')->log(
            action: 'severance.mark_paid',
            entity: 'severance_calculation', entityId: (int) $id,
            description: "Tandai severance #{$id} sebagai PAID",
            severity: 'CRITICAL'
        );

        // Sprint 14+ — webhook outbound `severance.paid`
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::SEVERANCE_PAID,
            (int) $row['company_id'],
            [
                'severance_id' => (int) $id,
                'employee'     => $emp ? [
                    'id'              => (int) $emp['id'],
                    'employee_number' => $emp['employee_number'] ?? null,
                    'name'            => $emp['full_name'] ?? null,
                ] : null,
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'paid_by'      => $this->userId(),
                'paid_at'      => $now,
            ],
        );

        return $this->ok(null, 'Severance ditandai sudah dibayar');
    }

    public function cancel($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] === 'PAID') return $this->fail('Tidak bisa cancel yang sudah PAID', 422);
        if ($row['status'] === 'CANCELLED') return $this->fail('Sudah dibatalkan', 422);

        $data = $this->getJsonInput();
        if (empty($data['reason']) || strlen(trim($data['reason'])) < 10) {
            return $this->fail('Alasan minimal 10 karakter', 422);
        }
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('severance_calculations')->where('id', (int) $id)->update([
            'status'              => 'CANCELLED',
            'cancelled_at'        => $now,
            'cancellation_reason' => trim($data['reason']),
            'updated_at'          => $now,
        ]);
        Services::audit()->forModule('HR')->log(
            action: 'severance.cancel',
            entity: 'severance_calculation', entityId: (int) $id,
            description: "Cancel severance #{$id}",
            severity: 'WARNING'
        );
        return $this->ok(null, 'Dibatalkan');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa dihapus permanen', 422);
        }
        \Config\Database::connect()->table('severance_calculations')->where('id', (int) $id)->delete();
        Services::audit()->forModule('HR')->logDelete(
            'severance_calculation', (int) $id, $row,
            "Hapus severance draft #{$id}"
        );
        return $this->ok(null, 'Deleted');
    }

    /**
     * GET /severance/(:num)/breakdown?format=pdf|html
     * Render dokumen rincian.
     */
    public function breakdown($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        $emp = $db->table('employees e')
            ->select('e.*, p.name AS position_name, d.name AS department_name, c.city AS company_city, c.name AS company_name')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('companies c', 'c.id = e.company_id', 'left')
            ->where('e.id', (int) $row['employee_id'])
            ->get()->getRowArray();

        $html = $row['breakdown_html'] ?? '';
        if ($html === '') {
            $html = Services::severance()->renderBreakdown($row, $emp ?: [], ['city' => $emp['company_city'] ?? '']);
        }

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        if ($format === 'html') {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody($html);
        }

        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        $filename = 'Severance_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $emp['employee_number']) . '_' . $row['termination_date'] . '.pdf';
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($output);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('severance.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('severance_calculations sc')
            ->select('sc.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = sc.employee_id')
            ->where('sc.id', $id)
            ->where('sc.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('severance.view')
            || $tenant->hasPermission('severance.manage');
        if (! $canViewAll) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}