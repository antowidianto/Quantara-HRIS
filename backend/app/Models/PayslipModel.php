<?php

namespace App\Models;

/**
 * PayslipModel — Sprint B+1 / A1 (lanjutan).
 *
 * Tabel `payslips` (slip gaji per period × employee). Multi-tenant scoped
 * via `company_id`. Tabel ini punya banyak field snapshot (employee_name,
 * department_name, ptkp_status, dst.) supaya slip historikal tetap akurat
 * walaupun master employee/department berubah.
 *
 * Tidak ada kolom `deleted_at` — payslip immutable setelah dibuat (audit
 * trail finansial). Kalau perlu void, set `is_paid=0` atau buat
 * adjustment payslip.
 *
 * Konvensi unique:
 *   - `slip_number` unik global (untuk URL & lookup)
 *   - `(payroll_period_id, employee_id)` unik komposit (anti double-issue)
 *
 * Hubungan:
 *   - belongs_to `payroll_periods`, `employees`
 *   - has_many `payslip_details` (line item earning/deduction)
 *
 * Lookup helpers berfokus untuk reporting & dashboard.
 */
class PayslipModel extends BaseModel
{
    protected $table      = 'payslips';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** Payslip immutable — tidak ada soft delete. */
    protected $useSoftDeletes = false;
    protected $deletedField   = '';

    protected $allowedFields = [
        'company_id', 'payroll_period_id', 'employee_id', 'slip_number',
        // Snapshot
        'employee_number', 'employee_name', 'department_name', 'position_name',
        'ptkp_status',
        // Attendance
        'work_days', 'present_days', 'absent_days', 'late_minutes', 'overtime_hours',
        // Earnings
        'basic_salary', 'gross_earning', 'overtime_amount', 'thr_amount',
        'bonus_amount', 'reimbursement_amount',
        // BPJS
        'bpjs_kes_employee', 'bpjs_kes_company',
        'bpjs_jht_employee', 'bpjs_jht_company',
        'bpjs_jp_employee', 'bpjs_jp_company',
        'bpjs_jkk_company', 'bpjs_jkm_company',
        // PPh21
        'pph21_amount', 'pph21_method', 'ter_rate', 'ter_category',
        // Deductions
        'loan_deduction', 'absent_deduction', 'late_deduction',
        // Totals
        'total_earning', 'total_deduction', 'take_home_pay',
        // Payment
        'is_paid', 'paid_at', 'payment_reference',
        // Misc
        'pdf_path', 'breakdown', 'notes',
    ];

    /**
     * Override findScoped tanpa filter deleted_at.
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.{$this->primaryKey}", $id)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Lookup by slip_number (unik global).
     */
    public function findBySlipNumber(string $slipNumber): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.slip_number", $slipNumber)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Lookup payslip 1 employee di period tertentu (idempotent — return
     * yang sudah ada).
     */
    public function findByPeriodEmployee(int $payrollPeriodId, int $employeeId): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.payroll_period_id", $payrollPeriodId)
            ->where("{$this->table}.employee_id", $employeeId)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Daftar payslip 1 period (semua karyawan).
     *
     * @return list<array<string,mixed>>
     */
    public function listForPeriod(int $payrollPeriodId): array
    {
        return $this->scoped()
            ->where("{$this->table}.payroll_period_id", $payrollPeriodId)
            ->orderBy("{$this->table}.employee_name", 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Daftar payslip 1 employee (untuk slip-history view di self-service).
     * Dilist oleh paid_at DESC supaya yang terbaru di atas.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEmployee(int $employeeId, int $limit = 24): array
    {
        return $this->scoped()
            ->where("{$this->table}.employee_id", $employeeId)
            ->orderBy("{$this->table}.payroll_period_id", 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Total take_home_pay untuk reporting period.
     * Berguna untuk "Total payroll bulan ini = Rp X" widget.
     */
    public function sumTakeHomePay(int $payrollPeriodId): float
    {
        $row = $this->scoped()
            ->select('COALESCE(SUM(take_home_pay), 0) AS total')
            ->where("{$this->table}.payroll_period_id", $payrollPeriodId)
            ->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }

    /**
     * Mark batch payslip sebagai paid. Idempotent — payslip yang sudah
     * `is_paid=1` di-skip via WHERE clause.
     *
     * @param list<int> $payslipIds
     */
    public function markPaid(array $payslipIds, string $paymentReference, ?string $paidAt = null): int
    {
        if (empty($payslipIds)) {
            return 0;
        }
        $tenant = \Config\Services::tenant();
        $b = $this->db->table($this->table)
            ->whereIn('id', $payslipIds)
            ->where('is_paid', 0);
        if (! $tenant->isSuperAdmin() && $tenant->getCompanyId() > 0) {
            $b->where('company_id', $tenant->getCompanyId());
        }
        $b->update([
            'is_paid'           => 1,
            'paid_at'           => $paidAt ?: date('Y-m-d H:i:s'),
            'payment_reference' => $paymentReference,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->affectedRows();
    }
}