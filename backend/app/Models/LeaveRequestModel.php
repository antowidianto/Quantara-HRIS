<?php

namespace App\Models;

/**
 * LeaveRequestModel — Sprint B+1 / A1 (lanjutan).
 *
 * Tabel `leaves` (request cuti karyawan). Multi-tenant scoped via `company_id`.
 * Tidak pakai soft-delete — leave history tetap di-keep untuk audit / report.
 *
 * Status workflow:
 *   PENDING → APPROVED → (settled, ke saldo)
 *   PENDING → REJECTED
 *   PENDING/APPROVED → CANCELLED (oleh employee atau HR)
 *
 * Half-day support: `is_half_day=1` + `half_day_part='MORNING'|'AFTERNOON'`,
 * `days=0.5`. Wiring ke attendance + payroll deduction belum complete (lihat
 * audit C10 — carry-over Sprint C+2).
 *
 * Lookup helpers:
 *   - `findByRequestNumber()` — unik global (bukan per company)
 *   - `pendingForApprover(approverEmployeeId)` — list pending milik subordinate
 *   - `overlappingFor(employeeId, start, end)` — cek konflik tanggal
 */
class LeaveRequestModel extends BaseModel
{
    protected $table      = 'leaves';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** Tabel ini tidak punya kolom deleted_at — disable soft delete. */
    protected $useSoftDeletes = false;

    /** Tabel ini hanya created_at + updated_at (tidak ada deleted_at). */
    protected $deletedField = '';

    protected $allowedFields = [
        'company_id', 'employee_id', 'leave_type_id',
        'request_number',
        'start_date', 'end_date', 'days',
        'is_half_day', 'half_day_part',
        'reason', 'attachment',
        'contact_during_leave', 'handover_to',
        'status', 'approved_by', 'approved_at', 'rejection_note',
    ];

    /**
     * Lookup by request_number (unik global). Tidak pakai tenant scope karena
     * URL slug pakai number ini; kalau salah company, return null tetap aman.
     */
    public function findByRequestNumber(string $requestNumber): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.request_number", $requestNumber)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Override findScoped tanpa filter deleted_at (table tidak punya kolom).
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.{$this->primaryKey}", $id)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Daftar leave PENDING. Caller bisa filter lanjut by approver kalau
     * pakai approval matrix.
     *
     * @return list<array<string,mixed>>
     */
    public function listPending(int $employeeId = 0): array
    {
        $b = $this->scoped()
            ->where("{$this->table}.status", 'PENDING')
            ->orderBy("{$this->table}.created_at", 'DESC');
        if ($employeeId > 0) {
            $b->where("{$this->table}.employee_id", $employeeId);
        }
        return $b->get()->getResultArray();
    }

    /**
     * Cari leave APPROVED/PENDING yang overlap dengan range tanggal yang
     * di-request. Penting untuk validasi anti-double-booking.
     *
     * Overlap formula: (existing.start <= new.end) AND (existing.end >= new.start)
     *
     * @param int    $employeeId
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d
     * @param int    $excludeId Skip request ini (saat update existing)
     * @return list<array<string,mixed>>
     */
    public function overlappingFor(int $employeeId, string $startDate, string $endDate, int $excludeId = 0): array
    {
        $b = $this->scoped()
            ->where("{$this->table}.employee_id", $employeeId)
            ->whereIn("{$this->table}.status", ['PENDING', 'APPROVED'])
            ->where("{$this->table}.start_date <=", $endDate)
            ->where("{$this->table}.end_date >=", $startDate);
        if ($excludeId > 0) {
            $b->where("{$this->table}.id !=", $excludeId);
        }
        return $b->get()->getResultArray();
    }

    /**
     * Hitung total hari leave APPROVED dari `leave_type_id` tertentu untuk
     * employee dalam range tahun. Berguna untuk reconcile saldo `leave_balances`.
     *
     * Counter pakai `days` yang sudah include half-day (0.5).
     */
    public function sumApprovedDaysByType(int $employeeId, int $leaveTypeId, int $year): float
    {
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);
        $row = $this->scoped()
            ->select('COALESCE(SUM(days), 0) AS total')
            ->where("{$this->table}.employee_id", $employeeId)
            ->where("{$this->table}.leave_type_id", $leaveTypeId)
            ->where("{$this->table}.status", 'APPROVED')
            ->where("{$this->table}.start_date >=", $start)
            ->where("{$this->table}.start_date <=", $end)
            ->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }
}