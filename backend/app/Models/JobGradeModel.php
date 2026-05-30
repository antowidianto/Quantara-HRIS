<?php

namespace App\Models;

/**
 * JobGradeModel — Sprint B+1 / A1 (lanjutan).
 *
 * Tabel `job_grades` (pangkat/grade dengan salary band per company).
 * Multi-tenant scoped via `company_id`.
 *
 * Hubungan:
 *   - belongs_to `companies`
 *   - has_many `positions`
 *   - has_many `employees`
 *
 * Salary band (`min_salary`, `max_salary`) dipakai oleh kalkulator payroll
 * dan UI offer letter untuk validasi salary proposal masuk band yang sesuai
 * grade.
 */
class JobGradeModel extends BaseModel
{
    protected $table      = 'job_grades';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'company_id', 'code', 'name', 'level',
        'min_salary', 'max_salary',
        'is_active',
    ];

    /**
     * Lookup grade by `code` dalam tenant scope.
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
     * Daftar grade aktif, ordered by `level` ASC (level 1 paling rendah).
     *
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        $rows = $this->scoped()
            ->where("{$this->table}.is_active", 1)
            ->where("{$this->table}.{$this->deletedField} IS NULL")
            ->orderBy("{$this->table}.level", 'ASC')
            ->orderBy("{$this->table}.name", 'ASC')
            ->get()->getResultArray();
        return $rows;
    }

    /**
     * Cek apakah `salary` berada dalam band grade `gradeId`.
     * Return tuple [inBand:bool, min:float, max:float].
     *
     * Convention: kalau min_salary=0 dan max_salary=0, grade dianggap
     * tidak punya band → selalu in-band (legacy data).
     *
     * @return array{in_band:bool, min:float, max:float}
     */
    public function checkSalaryBand(int $gradeId, float $salary): array
    {
        $grade = $this->findScoped($gradeId);
        if (! $grade) {
            return ['in_band' => false, 'min' => 0.0, 'max' => 0.0];
        }
        $min = (float) ($grade['min_salary'] ?? 0);
        $max = (float) ($grade['max_salary'] ?? 0);
        if ($min <= 0 && $max <= 0) {
            // No band defined — pass through (legacy)
            return ['in_band' => true, 'min' => 0.0, 'max' => 0.0];
        }
        $inBand = ($min <= 0 || $salary >= $min) && ($max <= 0 || $salary <= $max);
        return ['in_band' => $inBand, 'min' => $min, 'max' => $max];
    }
}