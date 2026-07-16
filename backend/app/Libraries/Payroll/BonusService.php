<?php

namespace App\Libraries\Payroll;

use Config\Database;

/**
 * BonusService — orchestrator Bonus Engine (Sprint 16+).
 *
 * Tanggung jawab:
 *   - Resolve daftar karyawan eligible berdasar `bonus_schemes.eligibility_json`
 *   - Hitung amount per karyawan via `BonusCalculator::compute()`
 *   - INSERT/UPSERT ke `bonus_recipients`
 *   - Update aggregate (total_amount, recipient_count) di `bonus_runs`
 *
 * Workflow status `bonus_runs`:
 *   DRAFT → CALCULATED → PENDING_APPROVAL → APPROVED
 *         → REJECTED → CALCULATED (koreksi dan submit ulang)
 *         → PAID       (final; cancellable hanya oleh super_admin)
 *         OR CANCELLED (terminal dari mana saja kecuali PAID)
 *
 * Pure-ish — pakai DB connection di constructor untuk mudah di-mock di
 * integration test.
 */
class BonusService
{
    /** @var \CodeIgniter\Database\BaseConnection */
    private $db;

    public function __construct(?object $db = null)
    {
        $this->db = $db ?: Database::connect();
    }

    /**
     * Hitung run: resolve eligibility + compute amount per karyawan,
     * INSERT/UPSERT ke `bonus_recipients`, update aggregate.
     *
     * Idempotent — boleh dipanggil ulang selama run masih DRAFT/CALCULATED.
     *
     * @param int $runId
     * @return array{recipient_count:int, total_amount:float, skipped:int}
     */
    public function calculateRun(int $runId, int $companyId): array
    {
        if ($companyId <= 0) throw new \InvalidArgumentException('Active company context is required');
        $run = $this->db->table('bonus_runs')->where('id', $runId)
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $run) {
            throw new \InvalidArgumentException("Bonus run #{$runId} tidak ditemukan");
        }
        if (! in_array($run['status'], ['DRAFT', 'CALCULATED', 'REJECTED'], true)) {
            throw new \InvalidArgumentException(
                "Run sudah {$run['status']}, tidak bisa di-recalculate"
            );
        }

        $scheme = $this->db->table('bonus_schemes')->where('id', (int) $run['bonus_scheme_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (! $scheme) {
            throw new \InvalidArgumentException("Bonus scheme #{$run['bonus_scheme_id']} tidak ditemukan");
        }

        $eligibility = $this->decodeJson($scheme['eligibility_json'] ?? null);
        $referenceDate = (string) $run['reference_date'];
        // Hapus recipient lama supaya re-calc clean (preserve manual override
        // belum di-support di Sprint 16+ — kalau HR sudah edit recipient
        // dan calculate ulang, override hilang. Reasonable trade-off untuk
        // first iteration; bisa enhance nanti).
        $this->db->table('bonus_recipients')->where('bonus_run_id', $runId)
            ->where('company_id', $companyId)->delete();

        // Ambil karyawan eligible. Apply hard-filter di SQL untuk efisien
        // (department/branch/grade/position whitelist + employment_status),
        // sisanya (tenure, exclude_resigned) di-cek per-row di calculator.
        //
        // SELECT * defensif — tidak hard-code daftar kolom karena schema
        // bisa beda antar tier (mis. `fixed_allowance` ditambahkan via
        // migration 000046; deployment lama belum punya). Department &
        // position name di-lookup terpisah supaya query tidak pecah karena
        // missing column.
        $b = $this->db->table('employees e')
            ->where('e.company_id', $companyId)
            ->where('e.deleted_at IS NULL');

        $this->applyEligibilitySqlFilters($b, $eligibility);

        $employees = $b->get()->getResultArray();

        // Lookup departemen & posisi untuk display name (digunakan di
        // bonus_recipients.department_name / position_name).
        $deptIds = array_unique(array_filter(array_column($employees, 'department_id')));
        $posIds  = array_unique(array_filter(array_column($employees, 'position_id')));
        $deptNames = [];
        $posNames  = [];
        if (! empty($deptIds)) {
            $rows = $this->db->table('departments')
                ->select('id, name')->where('company_id', $companyId)
                ->whereIn('id', $deptIds)->get()->getResultArray();
            foreach ($rows as $r) $deptNames[(int) $r['id']] = $r['name'];
        }
        if (! empty($posIds)) {
            $rows = $this->db->table('positions')
                ->select('id, name')->where('company_id', $companyId)
                ->whereIn('id', $posIds)->get()->getResultArray();
            foreach ($rows as $r) $posNames[(int) $r['id']] = $r['name'];
        }

        // Normalisasi: tambahkan department_name, position_name + default
        // fixed_allowance kalau kolom belum ada di schema lama.
        foreach ($employees as &$emp) {
            $emp['department_name'] = $deptNames[(int) ($emp['department_id'] ?? 0)] ?? null;
            $emp['position_name']   = $posNames[(int) ($emp['position_id'] ?? 0)] ?? null;
            if (! array_key_exists('fixed_allowance', $emp) || $emp['fixed_allowance'] === null) {
                $emp['fixed_allowance'] = 0;
            }
        }
        unset($emp);

        $now = date('Y-m-d H:i:s');
        $totalAmount = 0.0;
        $recipientCount = 0;
        $skipped = 0;
        $rows = [];

        foreach ($employees as $emp) {
            // Eligibility check (tenure + exclude_resigned + status)
            $check = BonusCalculator::checkEligibility($eligibility, $emp, $referenceDate);
            if (! $check['eligible']) {
                $skipped++;
                continue;
            }

            $compute = BonusCalculator::compute($scheme, $emp, $referenceDate);
            $amount  = (float) $compute['amount'];
            if ($amount <= 0) {
                $skipped++;
                continue;
            }

            $tenureMonths = BonusCalculator::tenureMonths(
                $emp['join_date'] ?? null,
                $emp['resign_date'] ?? null,
                $referenceDate,
            );

            $rows[] = [
                'bonus_run_id'      => $runId,
                'company_id'        => $companyId,
                'employee_id'       => (int) $emp['id'],
                'employee_number'   => (string) ($emp['employee_number'] ?? ''),
                'employee_name'     => (string) ($emp['full_name'] ?? ''),
                'department_name'   => $emp['department_name'] ?? null,
                'position_name'     => $emp['position_name'] ?? null,
                'tenure_months'     => $tenureMonths,
                'monthly_salary'    => (float) ($emp['basic_salary'] ?? 0),
                'amount'            => $amount,
                'amount_capped'     => $compute['capped'] ? 1 : 0,
                'amount_overridden' => 0,
                'breakdown_json'    => json_encode($compute['breakdown'], JSON_UNESCAPED_UNICODE),
                'is_excluded'       => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
            $totalAmount += $amount;
            $recipientCount++;
        }

        if (! empty($rows)) {
            // Batch insert untuk efisien
            $this->db->table('bonus_recipients')->insertBatch($rows);
        }

        // Update aggregate di run
        $this->db->table('bonus_runs')->where('id', $runId)->where('company_id', $companyId)->update([
            'status'          => 'CALCULATED',
            'total_amount'    => $totalAmount,
            'recipient_count' => $recipientCount,
            'calculated_by'   => $run['calculated_by'] ?? null,
            'calculated_at'   => $now,
            'updated_at'      => $now,
        ]);

        return [
            'recipient_count' => $recipientCount,
            'total_amount'    => $totalAmount,
            'skipped'         => $skipped,
        ];
    }

    /**
     * Apply hard-filter eligibility ke query builder untuk efisiensi.
     * Soft filter (tenure, status) tetap di-cek per-row di
     * BonusCalculator::checkEligibility.
     *
     * @param object $b
     */
    private function applyEligibilitySqlFilters($b, array $eligibility): void
    {
        $intMap = [
            'department_ids' => 'e.department_id',
            'branch_ids'     => 'e.branch_id',
            'grade_ids'      => 'e.job_grade_id',
            'position_ids'   => 'e.position_id',
        ];
        foreach ($intMap as $key => $col) {
            $list = $eligibility[$key] ?? null;
            if (is_array($list) && ! empty($list)) {
                $b->whereIn($col, array_values(array_map('intval', $list)));
            }
        }
        // Employment status whitelist
        $statuses = $eligibility['employment_status'] ?? null;
        if (is_array($statuses) && ! empty($statuses)) {
            $b->whereIn('e.employment_status', array_map('strtoupper', $statuses));
        }
    }

    /**
     * Sprint 20+ — Lookup riwayat bonus seorang karyawan lintas-run.
     *
     * Berguna untuk:
     *   - Tab "Bonus History" di profile karyawan (transparansi karyawan)
     *   - HR analytics — review apa saja bonus yang sudah pernah diterima
     *     karyawan tertentu sebagai konteks merit review / promosi
     *   - Auditor — verifikasi compliance pembayaran bonus
     *
     * Default hanya return run yang sudah APPROVED/PAID — DRAFT/CALCULATED
     * masih bisa berubah, tidak relevan untuk history.
     *
     * @param int    $employeeId
     * @param int|null $companyId  Opsional, untuk multi-tenant scope
     * @param array  $opts        ['limit' => 50, 'include_pending' => false]
     *
     * @return array<int, array<string, mixed>> List bonus diterima karyawan
     *         dengan metadata run + scheme. Sorted by paid_at DESC.
     */
    public function bonusHistoryForEmployee(int $employeeId, int $companyId, array $opts = []): array
    {
        if ($companyId <= 0) throw new \InvalidArgumentException('Active company context is required');
        $limit = (int) ($opts['limit'] ?? 50);
        $includePending = (bool) ($opts['include_pending'] ?? false);

        $statuses = $includePending
            ? ['DRAFT', 'CALCULATED', 'APPROVED', 'PAID']
            : ['APPROVED', 'PAID'];

        $b = $this->db->table('bonus_recipients r')
            ->select(
                'r.id, r.bonus_run_id, r.amount, r.amount_capped, r.amount_overridden, '
                . 'r.is_excluded, r.tenure_months, r.monthly_salary, r.breakdown_json, '
                . 'r.created_at AS recipient_at, '
                . 'run.name AS run_name, run.status AS run_status, '
                . 'run.reference_date, run.pay_date, run.period_year, '
                . 'run.calculated_at, run.approved_at, run.paid_at, '
                . 'run.payroll_period_id, '
                . 'sch.code AS scheme_code, sch.name AS scheme_name, '
                . 'sch.kind AS scheme_kind, sch.tax_treatment'
            )
            ->join('bonus_runs run', 'run.id = r.bonus_run_id AND run.company_id = r.company_id')
            ->join('bonus_schemes sch', 'sch.id = run.bonus_scheme_id AND sch.company_id = run.company_id')
            ->where('r.employee_id', $employeeId)
            ->where('r.company_id', $companyId)
            ->where('r.is_excluded', 0)
            ->whereIn('run.status', $statuses)
            ->where('run.deleted_at IS NULL');

        // Order: PAID first by paid_at DESC, then CALCULATED/APPROVED by reference_date DESC
        $rows = $b->orderBy('run.pay_date', 'DESC')
            ->orderBy('run.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        // Decode breakdown supaya client tidak perlu parse JSON
        foreach ($rows as &$r) {
            $r['breakdown'] = $this->decodeJson($r['breakdown_json'] ?? null);
            unset($r['breakdown_json']);
            $r['amount'] = (float) $r['amount'];
            $r['monthly_salary'] = (float) $r['monthly_salary'];
            $r['tenure_months'] = (int) $r['tenure_months'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Sprint 17+ — Lookup bonus per employee untuk satu payroll period.
     *
     * Dipanggil oleh PayrollService::calculatePeriod kalau auto-merge
     * bonus → payslip diaktifkan. Hanya consider run dengan status
     * APPROVED atau PAID — DRAFT/CALCULATED belum di-lock, jangan
     * masukkan ke payslip yang akan difinalize.
     *
     * Multiple bonus run untuk period yang sama akan di-sum (mis. annual
     * bonus + KPI bonus + retention bonus dalam payslip Januari).
     *
     * @return array<int, array{amount:float, breakdown:array}>
     *         Map employee_id → {amount, breakdown[]}
     */
    public function bonusesForPayrollPeriod(int $payrollPeriodId, int $companyId): array
    {
        if ($companyId <= 0) throw new \InvalidArgumentException('Active company context is required');
        $rows = $this->db->table('bonus_recipients r')
            ->select('r.employee_id, r.amount, r.breakdown_json, r.is_excluded,
                      run.id AS run_id, run.name AS run_name,
                      sch.code AS scheme_code, sch.kind AS scheme_kind, sch.tax_treatment')
            ->join('bonus_runs run', 'run.id = r.bonus_run_id AND run.company_id = r.company_id')
            ->join('bonus_schemes sch', 'sch.id = run.bonus_scheme_id AND sch.company_id = run.company_id')
            ->where('run.payroll_period_id', $payrollPeriodId)
            ->where('run.company_id', $companyId)
            ->whereIn('run.status', ['APPROVED', 'PAID'])
            ->where('run.deleted_at IS NULL')
            ->where('r.is_excluded', 0)
            ->get()->getResultArray();

        $byEmp = [];
        foreach ($rows as $row) {
            $empId = (int) $row['employee_id'];
            if (! isset($byEmp[$empId])) {
                $byEmp[$empId] = ['amount' => 0.0, 'breakdown' => []];
            }
            $byEmp[$empId]['amount'] += (float) $row['amount'];
            $byEmp[$empId]['breakdown'][] = [
                'bonus_run_id'  => (int) $row['run_id'],
                'run_name'      => $row['run_name'],
                'scheme_code'   => $row['scheme_code'],
                'scheme_kind'   => $row['scheme_kind'],
                'tax_treatment' => $row['tax_treatment'],
                'amount'        => (float) $row['amount'],
            ];
        }

        return $byEmp;
    }

    /**
     * Decode JSON string ke array. Return [] kalau invalid (defensive).
     *
     * @return array<string, mixed>
     */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
