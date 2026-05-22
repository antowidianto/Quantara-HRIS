<?php

namespace App\Libraries;

use Config\Database;

/**
 * OrganizationStructureService — lookup peran kunci & approver routing.
 *
 * Tabel `organization_structures` menyimpan jabatan strategis (HRD
 * Manager, Direktur HR, Finance Manager, dst) per company. Service ini
 * jadi single source of truth untuk:
 *
 *  1. Resolve siapa user yang harus approve / handle aksi tertentu.
 *     Mis. `resolveApproverUser($companyId, 'HRD_MANAGER')` mengembalikan
 *     user_id penjabat HR Manager saat ini.
 *  2. Listing peran kunci untuk dropdown UI (mis. picker approver di MPP).
 *  3. Cek scope: apakah role X boleh approve modul Y (`hasScope`).
 *
 * Fallback strategy bila role belum di-assign:
 *  - Coba `employee_id` utama
 *  - Coba `backup_employee_ids` (acting / PJS)
 *  - Return null — caller harus fallback ke permission-based routing
 *    (mis. semua user dengan permission `mpp.approve`).
 */
class OrganizationStructureService
{
    /**
     * Konstanta role_key bawaan. Dipakai sebagai referensi di kode
     * (ManpowerService, ResignationController, dst) supaya kalau role_key
     * di-rename di DB, semua call site ketahuan via static analysis.
     */
    public const ROLE_CEO              = 'CEO';
    public const ROLE_DIRECTOR_HR      = 'DIRECTOR_HR';
    public const ROLE_DIRECTOR_FINANCE = 'DIRECTOR_FINANCE';
    public const ROLE_HRD_MANAGER      = 'HRD_MANAGER';
    public const ROLE_TALENT_ACQ_LEAD  = 'TALENT_ACQ_LEAD';
    public const ROLE_FINANCE_MANAGER  = 'FINANCE_MANAGER';

    public const LEVELS = [
        'BOD', 'EXECUTIVE', 'GENERAL_MANAGER', 'MANAGER',
        'ASST_MANAGER', 'SUPERVISOR', 'OFFICER', 'STAFF',
    ];

    /**
     * Resolve user_id penjabat sebuah role.
     *
     * Lookup chain:
     *   1. organization_structures.employee_id → employees.user_id
     *   2. backup_employee_ids[0] → employees.user_id (jika utama vacant)
     *   3. null
     *
     * @param int    $companyId
     * @param string $roleKey
     * @return int|null  user_id atau null
     */
    public function resolveApproverUser(int $companyId, string $roleKey): ?int
    {
        $row = $this->findByRoleKey($companyId, $roleKey);
        if (! $row || ! (int) $row['is_active']) return null;

        $db = Database::connect();

        // Coba primary employee
        if (! empty($row['employee_id'])) {
            $emp = $db->table('employees')
                ->select('user_id, deleted_at, resign_date, is_active')
                ->where('id', (int) $row['employee_id'])
                ->get()->getRowArray();
            if ($this->isEmployeeUsable($emp) && ! empty($emp['user_id'])) {
                return (int) $emp['user_id'];
            }
        }

        // Coba backup
        $backups = $this->parseBackupIds($row['backup_employee_ids'] ?? null);
        foreach ($backups as $empId) {
            $emp = $db->table('employees')
                ->select('user_id, deleted_at, resign_date, is_active')
                ->where('id', $empId)
                ->get()->getRowArray();
            if ($this->isEmployeeUsable($emp) && ! empty($emp['user_id'])) {
                return (int) $emp['user_id'];
            }
        }
        return null;
    }

    /**
     * Resolve employee_id penjabat (untuk display di UI).
     */
    public function resolveApproverEmployee(int $companyId, string $roleKey): ?int
    {
        $row = $this->findByRoleKey($companyId, $roleKey);
        if (! $row || ! (int) $row['is_active']) return null;

        if (! empty($row['employee_id'])) {
            return (int) $row['employee_id'];
        }
        $backups = $this->parseBackupIds($row['backup_employee_ids'] ?? null);
        return $backups[0] ?? null;
    }

    /**
     * Cari row organization_structures by role_key.
     */
    public function findByRoleKey(int $companyId, string $roleKey): ?array
    {
        $db = Database::connect();
        $row = $db->table('organization_structures')
            ->where('company_id', $companyId)
            ->where('role_key', $roleKey)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * List semua role kunci aktif di company. Dipakai untuk dropdown UI.
     *
     * @param int $companyId
     * @param array $filter Opsi: level, has_scope (mis. 'mpp.budget'),
     *                      assigned_only, search.
     * @return array<int, array>
     */
    public function listKeyRoles(int $companyId, array $filter = []): array
    {
        $db = Database::connect();
        $b = $db->table('organization_structures os')
            ->select('os.id, os.role_key, os.title, os.level, os.department_id, os.branch_id, '
                . 'os.employee_id, os.backup_employee_ids, os.approval_scopes, os.is_active, os.sort_order, '
                . 'e.full_name AS employee_name, e.employee_number, '
                . 'd.name AS department_name, b.name AS branch_name, '
                . 'u.id AS user_id, u.email AS user_email')
            ->join('employees e',   'e.id = os.employee_id', 'left')
            ->join('users u',       'u.id = e.user_id',      'left')
            ->join('departments d', 'd.id = os.department_id', 'left')
            ->join('branches b',    'b.id = os.branch_id',     'left')
            ->where('os.company_id', $companyId)
            ->where('os.deleted_at IS NULL')
            ->where('os.is_key_role', 1);

        if (! empty($filter['level'])) {
            $b->where('os.level', $filter['level']);
        }
        if (! empty($filter['assigned_only'])) {
            $b->where('os.employee_id IS NOT NULL');
        }
        if (! empty($filter['search'])) {
            $q = trim((string) $filter['search']);
            $b->groupStart()
                ->like('os.title', $q)
                ->orLike('os.role_key', $q)
                ->groupEnd();
        }

        // Order: BOD dulu, lalu turun ke STAFF, kemudian sort_order
        $levelOrder = "CASE level "
            . "WHEN 'BOD' THEN 1 "
            . "WHEN 'EXECUTIVE' THEN 2 "
            . "WHEN 'GENERAL_MANAGER' THEN 3 "
            . "WHEN 'MANAGER' THEN 4 "
            . "WHEN 'ASST_MANAGER' THEN 5 "
            . "WHEN 'SUPERVISOR' THEN 6 "
            . "WHEN 'OFFICER' THEN 7 "
            . "ELSE 8 END";

        $rows = $b->orderBy($levelOrder, '', false)
            ->orderBy('os.sort_order', 'ASC')
            ->orderBy('os.title', 'ASC')
            ->get()->getResultArray();

        // Filter has_scope di PHP karena scopes JSON
        if (! empty($filter['has_scope'])) {
            $needle = (string) $filter['has_scope'];
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $scopes = $r['approval_scopes'] ? (json_decode($r['approval_scopes'], true) ?: []) : [];
                return in_array($needle, $scopes, true);
            }));
        }

        // Decode JSON columns supaya frontend tidak perlu parse
        foreach ($rows as &$r) {
            $r['approval_scopes']     = $r['approval_scopes'] ? (json_decode($r['approval_scopes'], true) ?: []) : [];
            $r['backup_employee_ids'] = $r['backup_employee_ids'] ? (json_decode($r['backup_employee_ids'], true) ?: []) : [];
            $r['is_vacant']           = empty($r['employee_id']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Build organogram tree dari parent_role_id untuk visualisasi
     * struktur jabatan strategis (terpisah dari department tree).
     */
    public function buildOrganogram(int $companyId): array
    {
        $rows = $this->listKeyRoles($companyId);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = array_merge($r, ['children' => []]);
        }
        $roots = [];
        foreach ($byId as $id => &$node) {
            // parent_role_id ada di base record tapi tidak di-select listKeyRoles;
            // ambil ulang dari DB hanya untuk parent map.
        }
        unset($node);

        // Single query untuk parent_role_id, supaya tidak per-row.
        $db = Database::connect();
        $parentRows = $db->table('organization_structures')
            ->select('id, parent_role_id')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $parentMap = [];
        foreach ($parentRows as $p) {
            $parentMap[(int) $p['id']] = $p['parent_role_id'] !== null ? (int) $p['parent_role_id'] : null;
        }

        foreach ($byId as $id => &$node) {
            $pid = $parentMap[$id] ?? null;
            $node['parent_role_id'] = $pid;
            if ($pid !== null && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    }

    /**
     * Cek apakah sebuah role punya scope tertentu.
     */
    public function hasScope(int $companyId, string $roleKey, string $scope): bool
    {
        $row = $this->findByRoleKey($companyId, $roleKey);
        if (! $row) return false;
        $scopes = $row['approval_scopes'] ? (json_decode($row['approval_scopes'], true) ?: []) : [];
        return in_array($scope, $scopes, true);
    }

    /**
     * Cek apakah seorang user adalah penjabat (atau backup) sebuah role.
     */
    public function userHasRole(int $companyId, int $userId, string $roleKey): bool
    {
        $approverUserId = $this->resolveApproverUser($companyId, $roleKey);
        return $approverUserId !== null && $approverUserId === $userId;
    }

    // ────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────

    private function isEmployeeUsable(?array $emp): bool
    {
        if (! $emp) return false;
        if (! empty($emp['deleted_at'])) return false;
        if ((int) ($emp['is_active'] ?? 1) !== 1) return false;
        if (! empty($emp['resign_date']) && strtotime($emp['resign_date']) <= time()) {
            return false;
        }
        return true;
    }

    private function parseBackupIds($json): array
    {
        if (empty($json)) return [];
        $arr = is_array($json) ? $json : (json_decode((string) $json, true) ?: []);
        return array_values(array_filter(array_map('intval', $arr), fn($i) => $i > 0));
    }
}