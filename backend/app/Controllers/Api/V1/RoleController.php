<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * RoleController — kelola role + mapping ke permissions.
 *
 * Otorisasi (kebijakan keamanan):
 *   - index/show: dapat dipanggil semua user terautentikasi (dipakai
 *     untuk dropdown pemilihan role di form user, badge user, dst).
 *   - create/update/delete: HANYA super admin. Mengubah peta permission
 *     berarti mengubah skema otorisasi seluruh tenant; bila dibuka ke
 *     role admin biasa, satu admin yang lalai bisa eskalasi diri ke
 *     super_admin atau melepas guard di modul finance.
 *
 * Catatan: PermissionController sudah read-only (create/update/delete
 * return 405). Yang masih "live" untuk diubah adalah daftar role +
 * mapping role-permission, dan itu yang dikunci di sini.
 */
class RoleController extends ApiController
{
    /**
     * Tolak request kalau bukan super admin. Dipanggil di awal
     * create/update/delete sebelum DB call apa pun.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface|null  Null bila lolos.
     */
    private function ensureSuperAdmin()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail(
                'Forbidden — hanya Super Admin yang dapat mengubah role & permission',
                403,
                'SUPER_ADMIN_ONLY'
            );
        }
        return null;
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        $rows = $db->table('roles')
            ->groupStart()
                ->where('company_id', $companyId)
                ->orWhere('company_id IS NULL')
            ->groupEnd()
            ->orderBy('name')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('roles')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        $perms = $db->table('role_permissions rp')
            ->select('p.id, p.slug, p.name, p.group')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $id)
            ->get()->getResultArray();
        $row['permissions'] = $perms;
        return $this->ok($row);
    }

    public function create()
    {
        if ($block = $this->ensureSuperAdmin()) return $block;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'slug' => 'required|alpha_dash|max_length[50]',
            'name' => 'required|max_length[100]',
        ]);
        if (! $valid) return null;

        // Cegah pembuatan role bernama `super_admin` lewat API (slug ini
        // dipakai sebagai bypass permission di TenantContext).
        if (strtolower($data['slug']) === 'super_admin') {
            return $this->fail('Slug "super_admin" tidak diperbolehkan', 422,
                'RESERVED_SLUG');
        }

        $db = \Config\Database::connect();
        $db->table('roles')->insert([
            'company_id'  => $this->companyId(),
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system'   => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        if (! empty($data['permissions']) && is_array($data['permissions'])) {
            $this->syncPermissions($db, $id, $data['permissions']);
        }
        Services::audit()->forModule('SECURITY')->log(
            action: 'role.create', entity: 'roles', entityId: $id,
            after: ['slug' => $data['slug'], 'name' => $data['name']],
            description: "Membuat role: {$data['name']} ({$data['slug']})",
            severity: 'CRITICAL',
        );
        return $this->ok(['id' => $id], 'Role created', 201);
    }

    public function update($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;

        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);

        // Super admin BOLEH edit role apa pun, termasuk yang `is_system=1`
        // (hr_admin, manager, finance, employee, bahkan super_admin sendiri).
        // Yang tidak boleh: ganti slug system role karena slug dipakai
        // referensi di kode (mis. `super_admin` di TenantContext::isSuperAdmin
        // tetap pakai role_id=1, tapi role-role lain dirujuk via slug
        // di seed/permission group).
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name', 'description']));
        $update['updated_at'] = date('Y-m-d H:i:s');

        // Pengaman tambahan: jangan biarkan super admin tanpa sengaja
        // mengosongkan permission role super_admin sendiri. Walau bypass
        // di TenantContext via role_id=1 tetap aktif, mengosongkan list
        // ini bikin auditor bingung dan menutup jalur fallback kalau
        // suatu hari bypass itu dicabut.
        if ((int) $existing['id'] === 1 && isset($data['permissions'])
            && is_array($data['permissions']) && empty($data['permissions'])) {
            return $this->fail(
                'Tidak boleh mengosongkan permission role Super Admin',
                422, 'CANNOT_EMPTY_SUPER_ADMIN'
            );
        }

        $db->table('roles')->where('id', $id)->update($update);

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->syncPermissions($db, $id, $data['permissions']);
        }
        Services::audit()->forModule('SECURITY')->log(
            action: 'role.update', entity: 'roles', entityId: $id,
            before: $existing, after: array_merge($update, ['permissions' => $data['permissions'] ?? null]),
            description: "Mengubah role: {$existing['name']} ({$existing['slug']})"
                . ((int) $existing['is_system'] === 1 ? ' [SYSTEM ROLE]' : ''),
            severity: 'CRITICAL',
        );
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;

        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if ((int) $existing['is_system'] === 1) return $this->fail('Cannot delete system role', 403);

        // Cegah penghapusan role yang masih aktif dipakai user — kalau
        // tidak, user-user itu jadi tanpa role dan akses mereka rusak.
        $usedBy = (int) $db->table('users')->where('role_id', $id)->countAllResults();
        if ($usedBy > 0) {
            return $this->fail(
                "Tidak bisa menghapus — masih dipakai {$usedBy} user. Pindahkan user ke role lain dulu.",
                422,
                'ROLE_IN_USE'
            );
        }

        $db->table('role_permissions')->where('role_id', $id)->delete();
        $db->table('roles')->where('id', $id)->delete();
        Services::audit()->forModule('SECURITY')->log(
            action: 'role.delete', entity: 'roles', entityId: $id,
            before: $existing,
            description: "Menghapus role: {$existing['name']} ({$existing['slug']})",
            severity: 'CRITICAL',
        );
        return $this->ok(null, 'Deleted');
    }

    private function syncPermissions($db, int $roleId, array $permissionIds): void
    {
        $db->table('role_permissions')->where('role_id', $roleId)->delete();
        $rows = [];
        foreach (array_unique(array_map('intval', $permissionIds)) as $pid) {
            if ($pid > 0) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => date('Y-m-d H:i:s')];
            }
        }
        if ($rows) $db->table('role_permissions')->insertBatch($rows);
    }
}
