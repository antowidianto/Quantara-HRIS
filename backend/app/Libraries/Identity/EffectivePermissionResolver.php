<?php

namespace App\Libraries\Identity;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Resolves the permissions that are actually available to an authenticated user.
 *
 * A user linked to an employee keeps the Employee self-service baseline even
 * when a functional role (developer, finance staff, recruiter, etc.) is
 * assigned. The functional role remains the source of additional privileges.
 */
final class EffectivePermissionResolver
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return string[]
     */
    public function resolve(int $roleId, int $employeeId, int $companyId): array
    {
        $roleIds = $roleId > 0 ? [$roleId] : [];

        if ($this->isEmployeeInCompany($employeeId, $companyId)) {
            $employeeRoleId = $this->findEmployeeBaselineRoleId($companyId);
            if ($employeeRoleId > 0) {
                $roleIds[] = $employeeRoleId;
            }
        }

        $roleIds = array_values(array_unique(array_filter($roleIds)));
        if ($roleIds === []) {
            return [];
        }

        $rows = $this->db->table('role_permissions rp')
            ->select('p.slug')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->whereIn('rp.role_id', $roleIds)
            ->where('p.slug IS NOT NULL')
            ->get()
            ->getResultArray();

        return self::merge(array_column($rows, 'slug'));
    }

    /**
     * @param string[] ...$permissionSets
     * @return string[]
     */
    public static function merge(array ...$permissionSets): array
    {
        $permissions = [];
        foreach ($permissionSets as $set) {
            foreach ($set as $permission) {
                $slug = trim((string) $permission);
                if ($slug !== '') {
                    $permissions[$slug] = true;
                }
            }
        }

        $result = array_keys($permissions);
        sort($result, SORT_STRING);
        return $result;
    }

    private function isEmployeeInCompany(int $employeeId, int $companyId): bool
    {
        if ($employeeId <= 0 || $companyId <= 0) {
            return false;
        }

        return $this->db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->countAllResults() > 0;
    }

    private function findEmployeeBaselineRoleId(int $companyId): int
    {
        // A tenant-specific Employee role is authoritative when configured.
        $local = $this->db->table('roles')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('slug', 'employee')
            ->get()
            ->getRowArray();
        if ($local) {
            return (int) $local['id'];
        }

        $global = $this->db->table('roles')
            ->select('id')
            ->where('company_id IS NULL')
            ->where('slug', 'employee')
            ->get()
            ->getRowArray();

        return (int) ($global['id'] ?? 0);
    }
}
