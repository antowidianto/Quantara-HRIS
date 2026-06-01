<?php

namespace App\Models\Master;

/**
 * PositionModel — Sprint B+1 / A1 (lanjutan).
 *
 * Tabel `positions` (struktur jabatan per company). Multi-tenant scoped via
 * `company_id`. Tidak ada field PII jadi tidak perlu encryption.
 *
 * Hubungan:
 *   - belongs_to `companies`
 *   - belongs_to `departments` (nullable — posisi bisa lintas department)
 *   - belongs_to `job_grades` (nullable — grade penentu salary band)
 *   - has_many `employees`
 *
 * Convention sama seperti BaseModel: pakai `findScoped()`, `createScoped()`,
 * `updateScoped()`, `deleteScoped()` untuk operasi standar dengan tenant guard.
 *
 * Lookup helper:
 *   - `findByCode(code)` — unik per company
 *   - `listActive()` — semua position aktif di tenant scope
 */
class PositionModel extends BaseModel
{
    protected $table      = 'positions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'company_id', 'department_id', 'job_grade_id',
        'code', 'name', 'description',
        'tier', // dari migration #029
        'is_active',
    ];

    /**
     * Lookup posisi by `code` (case-sensitive) dalam tenant scope.
     * NULL kalau tidak ditemukan atau bukan milik tenant.
     */
    public function findByCode(string $code): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.code", $code)
            ->where("{$this->table}.{$this->deletedField} IS NULL")
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Daftar position aktif (is_active=1) di tenant scope, ordered by name.
     * Berguna untuk dropdown UI.
     *
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        $rows = $this->scoped()
            ->where("{$this->table}.is_active", 1)
            ->where("{$this->table}.{$this->deletedField} IS NULL")
            ->orderBy("{$this->table}.name", 'ASC')
            ->get()->getResultArray();
        return $rows;
    }

    /**
     * Hitung employee aktif yang menempati position ini.
     * Berguna untuk UI delete-confirm ("Position ini punya N karyawan, yakin?").
     */
    public function countEmployees(int $positionId): int
    {
        $tenant = \Config\Services::tenant();
        $b = $this->db->table('employees')
            ->where('position_id', $positionId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL');
        if (! $tenant->isSuperAdmin() && $tenant->getCompanyId() > 0) {
            $b->where('company_id', $tenant->getCompanyId());
        }
        return (int) $b->countAllResults();
    }
}