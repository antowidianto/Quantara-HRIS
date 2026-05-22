<?php

namespace App\Controllers\Api\V1;

use Config\Database;
use Config\Services;

/**
 * OrganizationRoleController — CRUD untuk tabel `organization_structures`.
 *
 * Tabel ini menyimpan jabatan kunci (key role) seperti "HR Manager",
 * "Direktur HR", "Finance Manager", dst. HR / OD lead pakai halaman
 * frontend Master/OrganizationRoles untuk menetapkan siapa karyawan
 * yang menempati tiap jabatan.
 *
 * Hasil dari sini dipakai sebagai single source of truth untuk dropdown
 * approver di MPP, routing approval, dan organogram strategis.
 *
 * Authorization:
 *   - `org_structure.view`   → list/show/dropdown picker
 *   - `org_structure.manage` → create/update/delete + assign penjabat
 *
 * Endpoint:
 *   GET    /organization-roles                  — list semua role
 *   GET    /organization-roles/picker           — dropdown ringkas
 *           ?has_scope=mpp.budget&assigned_only=1
 *   GET    /organization-roles/organogram       — tree organogram strategis
 *   POST   /organization-roles                  — create role baru
 *   GET    /organization-roles/(:num)           — detail
 *   PUT    /organization-roles/(:num)           — update meta + assign
 *   DELETE /organization-roles/(:num)           — soft-delete
 *   POST   /organization-roles/(:num)/assign    — set/clear employee
 */
class OrganizationRoleController extends ApiController
{
    private const LEVELS = [
        'BOD', 'EXECUTIVE', 'GENERAL_MANAGER', 'MANAGER',
        'ASST_MANAGER', 'SUPERVISOR', 'OFFICER', 'STAFF',
    ];

    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);

        $filter = [
            'level'         => $this->request->getGet('level'),
            'search'        => $this->request->getGet('q'),
            'assigned_only' => (int) ($this->request->getGet('assigned_only') ?? 0),
            'has_scope'     => $this->request->getGet('has_scope'),
        ];
        $rows = Services::orgStructure()->listKeyRoles($companyId, $filter);
        return $this->ok($rows);
    }

    /**
     * Versi ringkas untuk dropdown (id, role_key, title, employee_name).
     */
    public function picker()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);

        $rows = Services::orgStructure()->listKeyRoles($companyId, [
            'has_scope'     => $this->request->getGet('has_scope'),
            'assigned_only' => (int) ($this->request->getGet('assigned_only') ?? 0),
        ]);

        // Bentuk minimal untuk performa dropdown
        $out = array_map(function ($r) {
            return [
                'id'             => (int) $r['id'],
                'role_key'       => $r['role_key'],
                'title'          => $r['title'],
                'level'          => $r['level'],
                'employee_id'    => $r['employee_id'] !== null ? (int) $r['employee_id'] : null,
                'employee_name'  => $r['employee_name'],
                'employee_number'=> $r['employee_number'],
                'user_id'        => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'is_vacant'      => $r['is_vacant'],
            ];
        }, $rows);
        return $this->ok($out);
    }

    public function organogram()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $tree = Services::orgStructure()->buildOrganogram($companyId);
        return $this->ok($tree);
    }

    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $row['approval_scopes']     = $row['approval_scopes'] ? (json_decode($row['approval_scopes'], true) ?: []) : [];
        $row['backup_employee_ids'] = $row['backup_employee_ids'] ? (json_decode($row['backup_employee_ids'], true) ?: []) : [];
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden: butuh permission org_structure.manage', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'role_key' => 'required|max_length[64]|regex_match[/^[A-Z][A-Z0-9_]*$/]',
            'title'    => 'required|max_length[200]',
            'level'    => 'required',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $level = strtoupper((string) $valid['level']);
        if (! in_array($level, self::LEVELS, true)) {
            return $this->fail('Level tidak valid. Pilihan: ' . implode(', ', self::LEVELS), 422);
        }

        $db = Database::connect();
        $exists = $db->table('organization_structures')
            ->where('company_id', $companyId)
            ->where('role_key', $valid['role_key'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail("role_key '{$valid['role_key']}' sudah ada di company ini", 422, 'DUPLICATE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'role_key'        => $valid['role_key'],
            'title'           => $valid['title'],
            'level'           => $level,
            'description'     => $data['description'] ?? null,
            'department_id'   => ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            'branch_id'       => ! empty($data['branch_id'])     ? (int) $data['branch_id']     : null,
            'parent_role_id'  => ! empty($data['parent_role_id'])? (int) $data['parent_role_id']: null,
            'employee_id'     => ! empty($data['employee_id'])   ? (int) $data['employee_id']   : null,
            'backup_employee_ids' => $this->normalizeBackupIds($data['backup_employee_ids'] ?? null),
            'approval_scopes'    => $this->normalizeScopes($data['approval_scopes'] ?? null),
            'is_key_role'     => (int) (bool) ($data['is_key_role'] ?? 1),
            'is_active'       => (int) (bool) ($data['is_active']   ?? 1),
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
            'created_by'      => $this->userId(),
            'updated_by'      => $this->userId(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('organization_structures')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()->forModule('MASTER')->logCreate('organization_structure', $id, $insert,
            "Role kunci baru: {$insert['role_key']} ({$insert['title']})");
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();

        $update = array_intersect_key($data, array_flip([
            'title','level','description','department_id','branch_id',
            'parent_role_id','employee_id','is_key_role','is_active','sort_order',
        ]));
        if (isset($update['level'])) {
            $update['level'] = strtoupper((string) $update['level']);
            if (! in_array($update['level'], self::LEVELS, true)) {
                return $this->fail('Level tidak valid', 422);
            }
        }
        foreach (['department_id','branch_id','parent_role_id','employee_id'] as $f) {
            if (array_key_exists($f, $update)) {
                $update[$f] = $update[$f] !== null && $update[$f] !== '' ? (int) $update[$f] : null;
            }
        }
        if (isset($update['is_key_role'])) $update['is_key_role'] = (int) (bool) $update['is_key_role'];
        if (isset($update['is_active']))   $update['is_active']   = (int) (bool) $update['is_active'];
        if (array_key_exists('backup_employee_ids', $data)) {
            $update['backup_employee_ids'] = $this->normalizeBackupIds($data['backup_employee_ids']);
        }
        if (array_key_exists('approval_scopes', $data)) {
            $update['approval_scopes'] = $this->normalizeScopes($data['approval_scopes']);
        }
        // role_key tidak bisa diubah — itu identifier stabil yang dipakai
        // call site di kode lain. Untuk rename, hapus & buat baru.

        // Cycle check kalau parent_role_id berubah
        if (isset($update['parent_role_id']) && $update['parent_role_id'] !== null) {
            if ((int) $update['parent_role_id'] === (int) $row['id']) {
                return $this->fail('Role tidak bisa jadi parent dari dirinya sendiri', 422, 'INVALID_PARENT');
            }
            if ($this->isDescendant((int) $row['id'], (int) $update['parent_role_id'], (int) $row['company_id'])) {
                return $this->fail('Tidak bisa pindah ke descendant sendiri (akan bikin siklus)', 422, 'CYCLE');
            }
        }

        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::connect()->table('organization_structures')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('MASTER')->logUpdate('organization_structure', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        Database::connect()->table('organization_structures')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('MASTER')->logDelete('organization_structure', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    /**
     * Quick-action: assign / clear penjabat sebuah role.
     * Body: { "employee_id": 42 | null, "backup_employee_ids": [12, 5] }
     */
    public function assign($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();

        $empId = isset($data['employee_id']) && $data['employee_id'] !== null && $data['employee_id'] !== ''
            ? (int) $data['employee_id'] : null;

        // Validasi employee belongs to company
        if ($empId !== null) {
            $db = Database::connect();
            $emp = $db->table('employees')->where('id', $empId)->get()->getRowArray();
            if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
            if ((int) $emp['company_id'] !== (int) $row['company_id']) {
                return $this->fail('Karyawan harus dalam perusahaan yang sama', 422, 'CROSS_TENANT');
            }
        }

        $update = [
            'employee_id'         => $empId,
            'backup_employee_ids' => array_key_exists('backup_employee_ids', $data)
                ? $this->normalizeBackupIds($data['backup_employee_ids'])
                : $row['backup_employee_ids'],
            'updated_by' => $this->userId(),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        Database::connect()->table('organization_structures')->where('id', (int) $id)->update($update);

        Services::audit()->forModule('MASTER')->log(
            action: 'org_role.assign', entity: 'organization_structure', entityId: (int) $id,
            before: ['employee_id' => $row['employee_id']],
            after: ['employee_id' => $empId],
            description: $empId
                ? "Assign employee #{$empId} ke role '{$row['title']}'"
                : "Clear penjabat role '{$row['title']}'",
            severity: 'NOTICE',
        );

        return $this->ok([
            'id' => (int) $id,
            'employee_id' => $empId,
            'is_vacant'   => $empId === null,
        ], $empId ? 'Assigned' : 'Cleared');
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('org_structure.view') || $t->hasPermission('org_structure.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('org_structure.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = Database::connect();
        $row = $db->table('organization_structures')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Role tidak ditemukan', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    private function normalizeBackupIds($input): ?string
    {
        if ($input === null || $input === '' || $input === '[]') return null;
        $arr = is_array($input) ? $input : (json_decode((string) $input, true) ?: []);
        $clean = array_values(array_unique(array_filter(array_map('intval', $arr), fn($i) => $i > 0)));
        return empty($clean) ? null : json_encode($clean);
    }

    private function normalizeScopes($input): ?string
    {
        if ($input === null || $input === '') return null;
        $arr = is_array($input) ? $input : (json_decode((string) $input, true) ?: []);
        $clean = array_values(array_unique(array_filter(array_map('strval', $arr), fn($s) => $s !== '')));
        return empty($clean) ? null : json_encode($clean);
    }

    private function isDescendant(int $ancestor, int $candidate, int $companyId): bool
    {
        $db = Database::connect();
        $visited = [];
        $stack = [$candidate];
        while (! empty($stack)) {
            $cur = array_pop($stack);
            if (in_array($cur, $visited, true)) continue;
            $visited[] = $cur;
            if ($cur === $ancestor) return true;
            $row = $db->table('organization_structures')
                ->select('parent_role_id')
                ->where('id', $cur)
                ->where('company_id', $companyId)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if ($row && $row['parent_role_id'] !== null) {
                $stack[] = (int) $row['parent_role_id'];
            }
        }
        return false;
    }
}