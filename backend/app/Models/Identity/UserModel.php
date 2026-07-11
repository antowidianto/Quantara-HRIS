<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

/**
 * UserModel — Sprint B / A1.
 *
 * `users` adalah tabel global cross-tenant (super admin punya company_id NULL),
 * jadi `multiTenant = false` — controller harus filter manual kalau perlu.
 *
 * Field rahasia (`password_hash`, `twofa_secret`) tidak masuk
 * `$allowedFields` standar — perubahan password lewat helper khusus
 * di AuthController supaya audit log konsisten.
 */
class UserModel extends BaseModel
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected bool $multiTenant = false;

    protected $allowedFields = [
        'company_id', 'role_id', 'employee_id',
        'email', 'name', 'avatar_path',
        'is_active', 'must_change_password',
        'failed_attempts', 'locked_until',
        'last_login_at', 'last_login_ip',
        'password_changed_at',
        // password_hash, twofa_* di-update via flow khusus, tidak via $allowedFields
    ];

    /**
     * Lookup user by email (case-insensitive).
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', strtolower(trim($email)))
            ->first();
        return $row ?: null;
    }
}
