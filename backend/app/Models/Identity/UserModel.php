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

    /** @return array<int,array<string,mixed>> */
    public function eligibleDelegates(int $companyId, int $excludeUserId): array
    {
        if ($companyId <= 0) return [];

        return $this->builder('u')
            ->select('u.id, u.name, u.email, e.employee_number, p.name AS position_name')
            ->join('employees e', "e.user_id = u.id AND e.company_id = {$companyId} AND e.deleted_at IS NULL", 'left')
            ->join('positions p', "p.id = e.position_id AND p.company_id = {$companyId}", 'left')
            ->where('u.company_id', $companyId)
            ->where('u.is_active', 1)
            ->where('u.id !=', $excludeUserId)
            ->orderBy('u.name', 'ASC')
            ->limit(500)
            ->get()->getResultArray();
    }
}
