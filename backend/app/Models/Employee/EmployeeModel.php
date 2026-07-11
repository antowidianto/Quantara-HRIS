<?php

namespace App\Models\Employee;

use App\Models\BaseModel;

/**
 * EmployeeModel — Sprint B / A1.
 *
 * Tabel `employees` adalah core tabel HRIS. Punya:
 *   - 3 field PII encrypted: `nik`, `npwp`, `bank_account` (auto via BaseModel)
 *   - `nik_hash` (sha256 plaintext NIK untuk lookup) — perlu manual update
 *     karena hash bukan reversible cipher
 *   - Multi-tenant scoped via `company_id`
 *
 * Convention pakai `findScoped(id)` / `createScoped(data)` / `updateScoped(id, data)`
 * untuk operasi standar. Controller existing yang masih pakai
 * `Database::connect()->table('employees')` tidak break — Model hanya
 * supplemental layer.
 */
class EmployeeModel extends BaseModel
{
    protected $table         = 'employees';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';

    /**
     * Field plaintext yang otomatis di-encrypt ke `<name>_encrypted`
     * (lihat BaseModel::encryptIncoming + afterFindHook).
     */
    protected array $encryptedFields = ['nik', 'npwp', 'bank_account'];

    protected $allowedFields = [
        'company_id', 'branch_id', 'department_id', 'position_id',
        'job_grade_id', 'job_title_id', 'manager_id', 'supervisor_id',
        'work_location_id', 'cost_center_id', 'user_id',

        'employee_number', 'first_name', 'last_name', 'full_name',
        'gender', 'religion', 'marital_status',
        'place_of_birth', 'date_of_birth', 'blood_type',
        'nationality',

        // Encrypted columns (BaseModel handles plaintext → _encrypted)
        'nik_encrypted', 'nik_hash',
        'npwp_encrypted',
        'bank_name', 'bank_account_encrypted', 'bank_account_holder',

        'bpjs_kesehatan_no', 'bpjs_tk_no', 'bpjs_kes_class',

        // Contact
        'email', 'phone', 'address',
        'province', 'city', 'postal_code',
        'ktp_postal_code',
        'kk_number', 'mother_name', 'father_name',
        'citizenship', 'ethnicity', 'education_level',
        'marital_certificate_no',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_rel',

        // Employment
        'employment_status', 'employment_type',
        'join_date', 'probation_end_date',
        'contract_end_date', 'resign_date', 'resign_reason',
        'is_active',

        // Salary / tax
        'basic_salary', 'tax_method', 'ptkp_status', 'pph21_method',
        'fixed_allowance',

        // Misc
        'avatar_path', 'custom_fields',
    ];

    /**
     * Lookup employee by employee_number dalam tenant scope.
     * NULL kalau tidak ditemukan atau bukan milik tenant.
     */
    public function findByNumber(string $number): ?array
    {
        $row = $this->scoped()
            ->where("{$this->table}.employee_number", $number)
            ->where("{$this->table}.{$this->deletedField} IS NULL")
            ->get()->getRowArray();
        return $row ? $this->afterFindHook($row) : null;
    }

    /**
     * Lookup by NIK plaintext via hash. Lebih efisien daripada decrypt-all.
     */
    public function findByNik(string $nik): ?array
    {
        $hash = hash('sha256', trim($nik));
        $row = $this->scoped()
            ->where("{$this->table}.nik_hash", $hash)
            ->where("{$this->table}.{$this->deletedField} IS NULL")
            ->get()->getRowArray();
        return $row ? $this->afterFindHook($row) : null;
    }

    /**
     * Hooks tambahan untuk encrypt: set `nik_hash` saat NIK plaintext
     * di-passing. Tidak override `encryptIncoming` parent — kita extend.
     */
    protected function encryptIncoming(array $data): array
    {
        // Capture NIK sebelum encryption (plaintext dihapus oleh parent)
        $nikHashSeed = $data['nik'] ?? null;

        $data = parent::encryptIncoming($data);

        // Set nik_hash kalau ada NIK incoming
        if ($nikHashSeed !== null && $nikHashSeed !== '') {
            $data['nik_hash'] = hash('sha256', trim((string) $nikHashSeed));
        } elseif (array_key_exists('nik_encrypted', $data) && $data['nik_encrypted'] === null) {
            // Caller men-clear NIK → clear hash juga
            $data['nik_hash'] = null;
        }
        return $data;
    }
}
