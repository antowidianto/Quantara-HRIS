<?php

namespace App\Models\Attendance;

/**
 * OvertimeRequestModel — Sprint B+1 / A1 (lanjutan).
 *
 * Tabel `overtimes` (request lembur). Multi-tenant scoped via `company_id`.
 *
 * Computed amount mengikuti PP 35/2021:
 *   - WEEKDAY: jam ke-1 = 1.5×, jam ke-2+ = 2× hourly rate
 *   - WEEKEND/HOLIDAY: jam 1–7 = 2×, jam 8 = 3×, jam 9–10 = 4×
 *   - Hourly rate = (basic_salary × allowance_factor) / 173 (per Kepmen 102/2004)
 *
 * Kalkulasi konkret di `OvertimeService::calculateAmount()` — Model ini
 * hanya CRUD layer.
 *
 * Status sama dengan leave: PENDING → APPROVED/REJECTED/CANCELLED.
 *
 * Saat APPROVED & dimasukkan ke payroll period, kolom
 * `paid_in_payroll_period_id` di-set untuk anti double-pay.
 */
class OvertimeRequestModel extends BaseModel
{
    protected $table      = 'overtimes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** Tabel ini tidak punya kolom deleted_at. */
    protected $useSoftDeletes = false;
    protected $deletedField   = '';

    protected $allowedFields = [
        'company_id', 'employee_id',
        'request_number',
        'work_date', 'start_time', 'end_time',
        'hours', 'day_type',
        'reason', 'computed_amount',
        'status', 'approved_by', 'approved_at', 'rejection_note',
        'paid_in_payroll_period_id',
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
     * Lookup by request_number (unik global).
     */
    public function findByRequestNumber(string $requestNumber): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.request_number", $requestNumber)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Daftar overtime APPROVED yang BELUM masuk payroll period
     * (`paid_in_payroll_period_id IS NULL`) dengan `work_date` dalam range.
     * Berguna untuk picker "overtime yang akan di-include" di payroll calculate.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingPayoutInRange(string $startDate, string $endDate, int $employeeId = 0): array
    {
        $b = $this->scoped()
            ->where("{$this->table}.status", 'APPROVED')
            ->where("{$this->table}.paid_in_payroll_period_id IS NULL")
            ->where("{$this->table}.work_date >=", $startDate)
            ->where("{$this->table}.work_date <=", $endDate)
            ->orderBy("{$this->table}.work_date", 'ASC');
        if ($employeeId > 0) {
            $b->where("{$this->table}.employee_id", $employeeId);
        }
        return $b->get()->getResultArray();
    }

    /**
     * Mark batch overtime sebagai sudah masuk payroll period tertentu.
     * Idempotent — kalau sebagian sudah marked, sisanya tetap di-update.
     *
     * @param list<int> $overtimeIds
     */
    public function markPaidIn(array $overtimeIds, int $payrollPeriodId): int
    {
        if (empty($overtimeIds)) {
            return 0;
        }
        $tenant = \Config\Services::tenant();
        $b = $this->db->table($this->table)
            ->whereIn('id', $overtimeIds)
            ->where('paid_in_payroll_period_id IS NULL');
        if (! $tenant->isSuperAdmin() && $tenant->getCompanyId() > 0) {
            $b->where('company_id', $tenant->getCompanyId());
        }
        $b->update([
            'paid_in_payroll_period_id' => $payrollPeriodId,
            'updated_at'                => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->affectedRows();
    }

    /**
     * Hitung total computed_amount overtime APPROVED untuk employee dalam
     * range (sebelum payroll commit).
     */
    public function sumApprovedAmountInRange(int $employeeId, string $startDate, string $endDate): float
    {
        $row = $this->scoped()
            ->select('COALESCE(SUM(computed_amount), 0) AS total')
            ->where("{$this->table}.employee_id", $employeeId)
            ->where("{$this->table}.status", 'APPROVED')
            ->where("{$this->table}.work_date >=", $startDate)
            ->where("{$this->table}.work_date <=", $endDate)
            ->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }
}