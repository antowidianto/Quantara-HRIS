<?php

namespace App\Libraries\Master;

use Config\Database;
use Config\Services;

/**
 * CostCenterService
 *
 * Domain service untuk dimensi accounting "cost center". Menyatukan
 * pola pakai di seluruh modul (payroll, reimbursement, loan, business
 * trip, bonus, severance, asset, MPP, employee profile).
 *
 * Tanggung jawab:
 *   1. CRUD cost center master (hirarki, budget, manager, default link)
 *   2. Resolve cost_center_id efektif untuk transaksi:
 *        override → employee → department → branch → company root
 *   3. Validasi: cost center harus aktif & milik company yang sama
 *   4. Aktualisasi (actuals) — hitung total biaya per cost center per
 *      periode dengan agregasi multi-tabel
 *   5. Budget vs actual reporting
 *
 * Pure helpers (testable tanpa DB) di-expose sebagai static method:
 *   - resolveFromChainPure($overrides, $employee, $department, $branch, $companyRoot)
 *   - validateCostCenterCodePure($code)
 *
 * Method DB-aware membutuhkan koneksi CodeIgniter Database.
 *
 * Lihat docs/COST_CENTER.md.
 */
class CostCenterService
{
    /** Kategori budget yang dikenal (untuk breakdown di cost_center_budgets.category). */
    public const BUDGET_CATEGORIES = [
        'SALARY',      // gaji + fixed allowance
        'ALLOWANCE',   // variable allowance (transport, makan, dll)
        'OVERTIME',    // lembur
        'BONUS',       // bonus + THR
        'BENEFIT',     // BPJS contribution employer side
        'TRAINING',    // training & sertifikasi
        'TRAVEL',      // business trip
        'SEVERANCE',   // pesangon, UPMK, UPH
        'REIMBURSE',   // reimbursement
        'ASSET',       // asset depreciation / cost
        'OTHER',
    ];

    public const PERIOD_TYPES = ['ANNUAL', 'QUARTERLY', 'MONTHLY'];

    // ─── Pure helpers (no DB, easy to test) ────────────────────────

    /**
     * Resolve cost center id berdasarkan chain of responsibility.
     * Pure function — tidak butuh DB connection. Caller harus pre-fetch
     * row employee/department/branch dan supply ke method ini.
     *
     * Aturan prioritas (paling tinggi → paling rendah):
     *   1. $overrides['cost_center_id']  (kalau eksplisit di-set)
     *   2. $employee['cost_center_id']    (kalau profile karyawan punya)
     *   3. $department['cost_center_id']  (default per dept)
     *   4. $branch['cost_center_id']      (default per branch)
     *   5. $companyRootId                 (cost center root company)
     *   6. null                           (no cost center → caller decide)
     *
     * NULL atau 0 di tiap layer di-skip (treated as not-set).
     *
     * @param array $overrides     biasanya request payload
     * @param array $employee      row dari employees (boleh kosong [])
     * @param array $department    row dari departments (boleh kosong [])
     * @param array $branch        row dari branches (boleh kosong [])
     * @param int|null $companyRootId fallback root cost center company
     * @return int|null
     */
    public static function resolveFromChainPure(
        array $overrides,
        array $employee = [],
        array $department = [],
        array $branch = [],
        ?int $companyRootId = null,
    ): ?int {
        $candidates = [
            $overrides['cost_center_id']  ?? null,
            $employee['cost_center_id']   ?? null,
            $department['cost_center_id'] ?? null,
            $branch['cost_center_id']     ?? null,
            $companyRootId,
        ];
        foreach ($candidates as $c) {
            if ($c === null || $c === '' || $c === 0) continue;
            $id = (int) $c;
            if ($id > 0) return $id;
        }
        return null;
    }

    /**
     * Validasi format code cost center: alphanumeric + hyphen + underscore,
     * 2-32 karakter, harus mengandung minimal 1 huruf.
     *
     * @return string[] empty = valid
     */
    public static function validateCostCenterCodePure(string $code): array
    {
        $errors = [];
        $code = trim($code);
        $len = function_exists('mb_strlen') ? mb_strlen($code) : strlen($code);
        if ($len < 2) {
            $errors[] = 'code minimal 2 karakter';
        } elseif ($len > 32) {
            $errors[] = 'code maksimal 32 karakter';
        }
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
            $errors[] = 'code hanya boleh huruf, angka, hyphen, underscore';
        }
        if (! preg_match('/[A-Za-z]/', $code)) {
            $errors[] = 'code harus mengandung minimal 1 huruf';
        }
        return $errors;
    }

    /**
     * Determine apakah cost center "child" adalah descendant dari "ancestor".
     * Pure: caller supply array of nodes [{id, parent_id}, ...].
     */
    public static function isDescendantPure(int $childId, int $ancestorId, array $allNodes): bool
    {
        if ($childId === $ancestorId) return true;
        $byId = [];
        foreach ($allNodes as $n) $byId[(int) $n['id']] = $n;
        $current = $byId[$childId] ?? null;
        $depth = 0; // guard against cycle
        while ($current && $depth < 50) {
            $parent = isset($current['parent_id']) ? (int) $current['parent_id'] : 0;
            if ($parent === 0) return false;
            if ($parent === $ancestorId) return true;
            $current = $byId[$parent] ?? null;
            $depth++;
        }
        return false;
    }

    // ─── DB-aware methods ──────────────────────────────────────────

    /**
     * Resolve cost center efektif untuk satu transaksi yang melibatkan
     * employee. Tarik row dept/branch dari DB berdasarkan employee record,
     * lalu delegasi ke resolveFromChainPure.
     *
     * Dipakai dari tempat-tempat yang punya employee_id tapi belum punya
     * department/branch row in-memory (mis. payroll calculate, reimbursement
     * create, dll).
     */
    public function resolveForEmployee(
        int $employeeId,
        array $overrides = [],
    ): ?int {
        $db = Database::connect();
        $emp = $db->table('employees')
            ->select('id, company_id, department_id, branch_id, cost_center_id')
            ->where('id', $employeeId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $emp) return null;

        $cols = $db->getFieldNames('employees');
        $hasCcCol = in_array('cost_center_id', $cols, true);
        if (! $hasCcCol) {
            // Schema belum migrate — short-circuit, biar caller tetap bisa lanjut.
            return $overrides['cost_center_id'] ?? null;
        }

        $department = [];
        if (! empty($emp['department_id'])) {
            $department = $db->table('departments')
                ->select('id, cost_center_id')
                ->where('id', (int) $emp['department_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray() ?: [];
        }

        $branch = [];
        if (! empty($emp['branch_id'])) {
            $branch = $db->table('branches')
                ->select('id, cost_center_id')
                ->where('id', (int) $emp['branch_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray() ?: [];
        }

        $rootId = $this->getCompanyRootId((int) $emp['company_id']);

        return self::resolveFromChainPure(
            $overrides, $emp, $department, $branch, $rootId
        );
    }

    /**
     * Resolve dari row data yang sudah ada (mis. saat MPP submit, kita
     * sudah punya department_id & branch_id di payload — tidak perlu
     * lookup employee dulu).
     */
    public function resolveForRequestData(
        int $companyId,
        array $payload,
    ): ?int {
        $db = Database::connect();
        $department = [];
        if (! empty($payload['department_id'])) {
            $department = $db->table('departments')
                ->select('id, cost_center_id')
                ->where('id', (int) $payload['department_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray() ?: [];
        }
        $branch = [];
        if (! empty($payload['branch_id'])) {
            $branch = $db->table('branches')
                ->select('id, cost_center_id')
                ->where('id', (int) $payload['branch_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray() ?: [];
        }
        $rootId = $this->getCompanyRootId($companyId);
        return self::resolveFromChainPure($payload, [], $department, $branch, $rootId);
    }

    /**
     * Get root cost center id untuk company. Default code = 'DEFAULT'.
     * Cached in-process untuk avoid repeated query.
     */
    private array $rootCache = [];
    public function getCompanyRootId(int $companyId): ?int
    {
        if (isset($this->rootCache[$companyId])) {
            return $this->rootCache[$companyId];
        }
        $db = Database::connect();
        if (! $db->tableExists('cost_centers')) {
            return $this->rootCache[$companyId] = null;
        }
        $row = $db->table('cost_centers')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('code', 'DEFAULT')
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        return $this->rootCache[$companyId] = $row ? (int) $row['id'] : null;
    }

    /**
     * Validate bahwa cost center id valid untuk company tertentu &
     * masih aktif. Throw kalau tidak.
     */
    public function assertValidForCompany(int $costCenterId, int $companyId): array
    {
        $db = Database::connect();
        $row = $db->table('cost_centers')
            ->where('id', $costCenterId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Cost center #{$costCenterId} tidak ditemukan");
        }
        if ((int) $row['company_id'] !== $companyId) {
            throw new \RuntimeException("Cost center #{$costCenterId} bukan milik company ini");
        }
        if ((int) $row['is_active'] !== 1) {
            throw new \RuntimeException("Cost center {$row['code']} sudah tidak aktif");
        }
        return $row;
    }

    /**
     * Build hirarki tree untuk satu company.
     *
     * @return array<int, array> rows dengan tambahan field `children`
     */
    public function tree(int $companyId, bool $includeInactive = false): array
    {
        $db = Database::connect();
        $b = $db->table('cost_centers')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if (! $includeInactive) $b->where('is_active', 1);
        $rows = $b->orderBy('sort_order', 'ASC')
            ->orderBy('code', 'ASC')
            ->get()->getResultArray();

        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[(int) $r['id']] = $r;
        }

        $tree = [];
        foreach ($byId as $id => &$node) {
            $pid = isset($node['parent_id']) ? (int) $node['parent_id'] : 0;
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);
        return $tree;
    }

    /**
     * Compute actual spending untuk satu cost center dalam satu periode.
     * Mengembalikan breakdown per kategori (SALARY/BONUS/TRAINING/dll).
     *
     * Strategy: union all dari tabel-tabel yang punya cost_center_id,
     * filter by date range. Kalau tabel tidak ada cost_center_id (schema
     * belum migrate), skip silent.
     *
     * @param int $costCenterId
     * @param string $fromDate Y-m-d
     * @param string $toDate   Y-m-d
     * @return array{total: float, by_category: array<string, float>, breakdown: array}
     */
    public function actualsBreakdown(int $costCenterId, string $fromDate, string $toDate): array
    {
        $db = Database::connect();
        $byCategory = array_fill_keys(self::BUDGET_CATEGORIES, 0.0);
        $breakdown = [];

        // Helper — cek tabel + kolom sebelum query
        $sumIfTable = function (
            string $table,
            array $sumColumns,    // ['gross_salary', 'bpjs_employer_total']
            string $dateColumn,
            string $category
        ) use ($db, $costCenterId, $fromDate, $toDate, &$byCategory, &$breakdown) {
            if (! $db->tableExists($table)) return;
            $cols = $db->getFieldNames($table);
            if (! in_array('cost_center_id', $cols, true)) return;
            // Filter sum_columns yang exist saja
            $existing = array_filter($sumColumns, fn ($c) => in_array($c, $cols, true));
            if (empty($existing) || ! in_array($dateColumn, $cols, true)) return;

            $sumExpr = implode(' + ', array_map(fn ($c) => "COALESCE({$c}, 0)", $existing));
            $row = $db->table($table)
                ->select("SUM({$sumExpr}) AS total")
                ->where('cost_center_id', $costCenterId)
                ->where("{$dateColumn} >=", $fromDate)
                ->where("{$dateColumn} <=", $toDate)
                ->get()->getRowArray();
            $total = (float) ($row['total'] ?? 0);
            $byCategory[$category] += $total;
            $breakdown[] = [
                'table'    => $table,
                'category' => $category,
                'amount'   => $total,
            ];
        };

        // Payroll → SALARY + OVERTIME + BENEFIT (combined)
        // Tabel `payslips` adalah primary payroll record per
        // employee per period. Filter via period join (payslips.payroll_period_id
        // → payroll_periods.start_date) lebih akurat daripada by created_at.
        // Untuk simplicity di phase awal, pakai created_at (best-effort).
        $sumIfTable('payslips',
            ['basic_salary', 'gross_earning'],
            'created_at', 'SALARY',
        );
        $sumIfTable('payslips',
            ['overtime_amount'],
            'created_at', 'OVERTIME',
        );
        $sumIfTable('payslips',
            ['bpjs_kes_company', 'bpjs_jht_company', 'bpjs_jp_company',
                'bpjs_jkk_company', 'bpjs_jkm_company'],
            'created_at', 'BENEFIT',
        );
        $sumIfTable('payslips',
            ['bonus_amount'],
            'created_at', 'BONUS',
        );

        // Legacy alias (kalau schema lama pakai payroll_entries)
        $sumIfTable('payroll_entries',
            ['gross_salary', 'basic_salary', 'total_allowance', 'allowance_amount'],
            'period_year', 'SALARY',
        );
        $sumIfTable('payroll_entries',
            ['total_overtime', 'overtime_amount'],
            'period_year', 'OVERTIME'
        );
        $sumIfTable('payroll_entries',
            ['bpjs_employer', 'bpjs_employer_total'],
            'period_year', 'BENEFIT'
        );

        // Reimbursement
        $sumIfTable('reimbursements', ['amount'], 'reimbursement_date', 'REIMBURSE');

        // Loans → bukan biaya tetapi exposure (informational)
        // Skip dari budget actual.

        // Business trips total
        $sumIfTable('business_trips',           ['total_amount'], 'start_date', 'TRAVEL');
        $sumIfTable('business_trip_expenses',   ['amount'],       'expense_date', 'TRAVEL');

        // Bonus runs (header level)
        $sumIfTable('bonus_runs',               ['total_amount'], 'paid_at', 'BONUS');

        // Training budget realisasi
        $sumIfTable('training_budgets',         ['actual_amount', 'utilized_amount'],
            'period_year', 'TRAINING');

        // Severance
        $sumIfTable('severance_calculations',   ['total_amount'], 'termination_date', 'SEVERANCE');

        // Asset assignment depresiasi (kalau ada field cost di asset)
        // Biasanya ini di-handle di GL eksternal, jadi hanya tracking.

        $total = array_sum($byCategory);

        return [
            'total'       => $total,
            'by_category' => $byCategory,
            'breakdown'   => $breakdown,
        ];
    }

    /**
     * Compute "committed spending" — biaya yang sudah di-pesan tapi
     * belum direalisasi sebagai aktual:
     *
     *   1. MPP APPROVED yang belum FILLED — perkiraan gaji bulanan ×
     *      bulan tersisa di tahun × (1 + BPJS employer ~13%)
     *      Contoh: MPP 2 orang APPROVED Maret 2026, salary_max=Rp10jt,
     *      target Mei 2026 → committed = 2 × 10jt × 8 bulan × 1.13 ≈ 181jt
     *
     *   2. Karyawan baru yang sudah join (employees.join_date dalam tahun
     *      ini) tapi payslip-nya belum ada — sisa bulan × salary × 1.13.
     *      Ini menutup gap antara hire & payroll cycle pertama.
     *
     *   3. Bonus runs CALCULATED tapi belum APPROVED — total_amount-nya.
     *      Sudah diestimasi tapi belum dibayar.
     *
     *   4. Reimbursement APPROVED tapi belum PAID — jumlahnya.
     *
     * Tujuannya: Finance bisa lihat "committed vs budget" yang lebih
     * akurat di tengah tahun, bukan baru sadar over-budget di akhir.
     *
     * @return array{
     *   total: float,
     *   by_category: array<string, float>,
     *   breakdown: list<array{source:string, count:int, amount:float, note?:string}>
     * }
     */
    public function committedSpending(int $costCenterId, int $year): array
    {
        $db = Database::connect();
        $byCategory = array_fill_keys(self::BUDGET_CATEGORIES, 0.0);
        $breakdown = [];

        // Asumsi BPJS employer-side load ~13% (JHT 3.7% + JP 2% + JKK 0.24-1.74%
        // + JKM 0.3% + Kesehatan 4%). Number ini estimasi konservatif untuk
        // committed cost — bukan untuk slip aktual (yang dihitung bpjsCalc).
        $bpjsLoadFactor = 1.13;

        $today = new \DateTimeImmutable();
        $currentYear = (int) $today->format('Y');
        $monthsRemainingThisYear = $year === $currentYear
            ? max(0, 12 - (int) $today->format('n') + 1)
            : ($year > $currentYear ? 12 : 0);

        // ─── 1. MPP APPROVED yang belum FILLED ────────────────────────
        if ($db->tableExists('manpower_requests')) {
            $cols = $db->getFieldNames('manpower_requests');
            if (in_array('cost_center_id', $cols, true)) {
                $rows = $db->table('manpower_requests')
                    ->where('cost_center_id', $costCenterId)
                    ->whereIn('status', ['APPROVED', 'HIRING'])
                    ->where('deleted_at IS NULL')
                    ->get()->getResultArray();

                $mppCount = 0;
                $mppCommitted = 0.0;
                foreach ($rows as $r) {
                    $remaining = max(0, (int) $r['headcount'] - (int) ($r['hired_count'] ?? 0));
                    if ($remaining <= 0) continue;
                    // Pakai midpoint salary range (median). Kalau hanya
                    // salah satu yang ada, pakai itu.
                    $sMin = (float) ($r['salary_min'] ?? 0);
                    $sMax = (float) ($r['salary_max'] ?? 0);
                    $monthly = $sMax > 0 && $sMin > 0
                        ? ($sMin + $sMax) / 2
                        : ($sMax > 0 ? $sMax : $sMin);
                    if ($monthly <= 0) continue;

                    // Hitung bulan tersisa dari target_start_date / now.
                    $startStr = $r['target_start_date'] ?? null;
                    $start = $startStr ? new \DateTimeImmutable($startStr) : $today;
                    if ($start < $today) $start = $today;
                    $startYear = (int) $start->format('Y');
                    if ($startYear > $year) continue; // start di tahun depan, tidak hit budget tahun ini
                    $startMonth = $startYear === $year ? (int) $start->format('n') : 1;
                    $monthsToYearEnd = max(0, 12 - $startMonth + 1);

                    $cost = $remaining * $monthly * $monthsToYearEnd * $bpjsLoadFactor;
                    $mppCommitted += $cost;
                    $mppCount += $remaining;
                }
                if ($mppCommitted > 0) {
                    $byCategory['SALARY'] += $mppCommitted;
                    $breakdown[] = [
                        'source' => 'manpower_requests',
                        'count'  => $mppCount,
                        'amount' => $mppCommitted,
                        'note'   => "MPP APPROVED/HIRING × bulan tersisa × " . round(($bpjsLoadFactor - 1) * 100) . "% BPJS load",
                    ];
                }
            }
        }

        // ─── 2. Karyawan baru join tapi belum payroll ─────────────────
        if ($db->tableExists('employees')) {
            $cols = $db->getFieldNames('employees');
            if (in_array('cost_center_id', $cols, true) && in_array('join_date', $cols, true)) {
                $yearStart = sprintf('%04d-01-01', $year);
                $yearEnd   = sprintf('%04d-12-31', $year);

                // Karyawan dengan cost_center_id ini, join_date dalam tahun.
                // Filter yang BELUM punya payslip di tahun tsb (committed
                // tapi belum aktual).
                $rows = $db->table('employees e')
                    ->select('e.id, e.full_name, e.basic_salary, e.join_date')
                    ->where('e.cost_center_id', $costCenterId)
                    ->where('e.is_active', 1)
                    ->where('e.deleted_at IS NULL')
                    ->where('e.join_date >=', $yearStart)
                    ->where('e.join_date <=', $yearEnd)
                    ->get()->getResultArray();

                $newHireCount = 0;
                $newHireCommitted = 0.0;
                foreach ($rows as $emp) {
                    // Skip kalau sudah ada payslip di tahun ini — sudah
                    // ke-cover di actual (bukan committed lagi).
                    $hasPayslip = false;
                    if ($db->tableExists('payslips') && $db->tableExists('payroll_periods')) {
                        $cnt = $db->table('payslips ps')
                            ->join('payroll_periods pp', 'pp.id = ps.payroll_period_id')
                            ->where('ps.employee_id', (int) $emp['id'])
                            ->where('pp.period_year', $year)
                            ->countAllResults();
                        $hasPayslip = $cnt > 0;
                    }
                    if ($hasPayslip) continue;

                    $monthly = (float) ($emp['basic_salary'] ?? 0);
                    if ($monthly <= 0) continue;
                    $joinMonth = (int) date('n', strtotime($emp['join_date']));
                    $startMonth = max($joinMonth, $year === $currentYear ? (int) $today->format('n') : 1);
                    $monthsToYearEnd = max(0, 12 - $startMonth + 1);
                    $cost = $monthly * $monthsToYearEnd * $bpjsLoadFactor;
                    $newHireCommitted += $cost;
                    $newHireCount++;
                }
                if ($newHireCommitted > 0) {
                    $byCategory['SALARY'] += $newHireCommitted;
                    $breakdown[] = [
                        'source' => 'employees',
                        'count'  => $newHireCount,
                        'amount' => $newHireCommitted,
                        'note'   => "Karyawan baru join tahun ini, belum ada payslip × bulan tersisa",
                    ];
                }
            }
        }

        // ─── 3. Bonus run CALCULATED (belum APPROVED) ────────────────
        if ($db->tableExists('bonus_runs')) {
            $cols = $db->getFieldNames('bonus_runs');
            if (in_array('cost_center_id', $cols, true)) {
                $rows = $db->table('bonus_runs')
                    ->select('id, total_amount')
                    ->where('cost_center_id', $costCenterId)
                    ->whereIn('status', ['DRAFT', 'CALCULATED'])
                    ->where('deleted_at IS NULL')
                    ->where('YEAR(pay_date)', $year, false)
                    ->get()->getResultArray();
                $cnt = count($rows);
                $sum = array_sum(array_column($rows, 'total_amount'));
                if ($sum > 0) {
                    $byCategory['BONUS'] += $sum;
                    $breakdown[] = [
                        'source' => 'bonus_runs',
                        'count'  => $cnt,
                        'amount' => (float) $sum,
                        'note'   => 'Bonus run DRAFT/CALCULATED — belum APPROVED, belum di-merge',
                    ];
                }
            }
        }

        // ─── 4. Reimbursement APPROVED belum PAID ────────────────────
        if ($db->tableExists('reimbursements')) {
            $cols = $db->getFieldNames('reimbursements');
            if (in_array('cost_center_id', $cols, true)) {
                $b = $db->table('reimbursements')
                    ->select('SUM(amount) AS total, COUNT(*) AS cnt')
                    ->where('cost_center_id', $costCenterId)
                    ->where('status', 'APPROVED')
                    ->where('paid_in_payroll_period_id IS NULL');
                if (in_array('expense_date', $cols, true)) {
                    $b->where('YEAR(expense_date)', $year, false);
                }
                $row = $b->get()->getRowArray();
                $sum = (float) ($row['total'] ?? 0);
                if ($sum > 0) {
                    $byCategory['REIMBURSE'] += $sum;
                    $breakdown[] = [
                        'source' => 'reimbursements',
                        'count'  => (int) ($row['cnt'] ?? 0),
                        'amount' => $sum,
                        'note'   => 'Reimbursement APPROVED tapi belum di-PAY via payroll',
                    ];
                }
            }
        }

        $total = array_sum($byCategory);
        return [
            'total'       => $total,
            'by_category' => $byCategory,
            'breakdown'   => $breakdown,
        ];
    }

    /**
     * Total budget untuk cost center di satu periode tahun.
     * Mengakomodasi mix periode (ANNUAL + QUARTERLY + MONTHLY) → sum.
     */
    public function totalBudget(int $costCenterId, int $year, ?string $category = null): float
    {
        $db = Database::connect();
        if (! $db->tableExists('cost_center_budgets')) return 0.0;

        $b = $db->table('cost_center_budgets')
            ->select('SUM(amount) AS total')
            ->where('cost_center_id', $costCenterId)
            ->where('period_year', $year);
        if ($category !== null) $b->where('category', $category);
        $row = $b->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }

    /**
     * Build budget vs actual report untuk satu cost center.
     *
     * @return array{
     *   cost_center: array,
     *   year: int,
     *   budget: float,
     *   actual: float,
     *   variance: float,
     *   variance_pct: float,
     *   utilization_pct: float,
     *   by_category: array
     * }
     */
    public function budgetVsActual(int $costCenterId, int $year): array
    {
        $db = Database::connect();
        $cc = $db->table('cost_centers')
            ->where('id', $costCenterId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $cc) {
            throw new \InvalidArgumentException("Cost center #{$costCenterId} tidak ditemukan");
        }

        $fromDate = sprintf('%04d-01-01', $year);
        $toDate   = sprintf('%04d-12-31', $year);

        $budget    = $this->totalBudget($costCenterId, $year);
        $actual    = $this->actualsBreakdown($costCenterId, $fromDate, $toDate);
        $committed = $this->committedSpending($costCenterId, $year);

        // Forecast = actual + committed. Ini estimasi total realisasi
        // tahun ini kalau semua MPP/hire/bonus berjalan sesuai rencana.
        $forecast    = $actual['total'] + $committed['total'];
        $variance    = $budget - $actual['total'];
        $variancePct = $budget > 0 ? round(($variance / $budget) * 100, 2) : 0.0;
        $utilization = $budget > 0 ? round(($actual['total'] / $budget) * 100, 2) : 0.0;
        $forecastPct = $budget > 0 ? round(($forecast / $budget) * 100, 2) : 0.0;

        $byCategoryEnriched = [];
        foreach ($actual['by_category'] as $cat => $amt) {
            $catBudget    = $this->totalBudget($costCenterId, $year, $cat);
            $catCommitted = (float) ($committed['by_category'][$cat] ?? 0);
            $catForecast  = $amt + $catCommitted;
            $byCategoryEnriched[$cat] = [
                'budget'           => $catBudget,
                'actual'           => $amt,
                'committed'        => $catCommitted,
                'forecast'         => $catForecast,
                'variance'         => $catBudget - $amt,
                'forecast_variance'=> $catBudget - $catForecast,
                'utilization_pct'  => $catBudget > 0 ? round(($amt / $catBudget) * 100, 2) : null,
                'forecast_pct'     => $catBudget > 0 ? round(($catForecast / $catBudget) * 100, 2) : null,
            ];
        }

        return [
            'cost_center'      => $cc,
            'year'             => $year,
            'budget'           => $budget,
            'actual'           => $actual['total'],
            'committed'        => $committed['total'],
            'forecast'         => $forecast,
            'variance'         => $variance,
            'forecast_variance'=> $budget - $forecast,
            'variance_pct'     => $variancePct,
            'utilization_pct'  => $utilization,
            'forecast_pct'     => $forecastPct,
            'by_category'      => $byCategoryEnriched,
            'committed_breakdown' => $committed['breakdown'],
        ];
    }

    /**
     * Bulk apply default cost_center_id ke semua karyawan yang belum
     * di-tag, sesuai chain dept → branch → root. Idempotent.
     *
     * Dipakai HR sekali setelah cost center master di-setup, supaya
     * profile karyawan tidak null.
     *
     * @return array{updated: int, skipped: int}
     */
    public function backfillEmployees(int $companyId): array
    {
        $db = Database::connect();
        if (! $db->tableExists('employees')) return ['updated' => 0, 'skipped' => 0];
        $cols = $db->getFieldNames('employees');
        if (! in_array('cost_center_id', $cols, true)) {
            return ['updated' => 0, 'skipped' => 0, 'note' => 'migration_not_applied'];
        }

        $rows = $db->table('employees')
            ->select('id, company_id, department_id, branch_id')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->where('cost_center_id IS NULL')
            ->get()->getResultArray();

        $updated = 0;
        $skipped = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $resolved = $this->resolveForEmployee((int) $r['id']);
            if (! $resolved) {
                $skipped++;
                continue;
            }
            $db->table('employees')->where('id', (int) $r['id'])->update([
                'cost_center_id' => $resolved,
                'updated_at'     => $now,
            ]);
            $updated++;
        }
        return ['updated' => $updated, 'skipped' => $skipped];
    }
}