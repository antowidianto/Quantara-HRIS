<?php

namespace App\Controllers\Api\V1\Identity;

use App\Controllers\Api\V1\ApiController;
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
        if (!$this->tenant()->isSuperAdmin()) {
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
        $rows = (new \App\Repositories\Identity\RoleRepository())
            ->listForCompany($this->companyId());

        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('roles')->where('id', (int) $id)->get()->getRowArray();
        if (!$row) {
            return $this->fail('Not found', 404);
        }
        if ($row['company_id'] !== null && (int) $row['company_id'] !== $this->companyId() && !$this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $perms = $db->table('role_permissions rp')
            ->select('p.id, p.slug, p.name, p.group')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $id)
            ->get()->getResultArray();
        $row['permissions'] = $perms;

        return $this->ok($row);
    }

    /**
     * Daftar permintaan persetujuan RBAC untuk tenant aktif.
     */
    public function approvalRequests()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Company context tidak valid', 422);
        }

        $db = \Config\Database::connect();
        $rows = $db->table('privileged_action_requests par')
            ->select(
                'par.*, maker.name AS submitted_by_name, maker.email AS submitted_by_email,'
                . ' approver.name AS approved_by_name, rejector.name AS rejected_by_name,'
                . ' ar.id AS approval_request_id, ar.status AS workflow_status,'
                . ' ar.current_step_order, ar.current_step_id, ar.workflow_snapshot,'
                . ' cs.name AS current_step_name'
            )
            ->join('users maker', 'maker.id = par.submitted_by', 'left')
            ->join('users approver', 'approver.id = par.approved_by', 'left')
            ->join('users rejector', 'rejector.id = par.rejected_by', 'left')
            ->join(
                'approval_requests ar',
                "ar.entity_table = 'privileged_action_requests'"
                    . ' AND ar.entity_id = par.id'
                    . " AND ar.module = 'PRIVILEGED_ACTION'",
                'left'
            )
            ->join('approval_chain_steps cs', 'cs.id = ar.current_step_id', 'left')
            ->where('par.company_id', $companyId)
            ->where('par.action_group', 'RBAC')
            ->where('par.deleted_at IS NULL')
            ->orderBy('par.created_at', 'DESC')
            ->limit(100)
            ->get()->getResultArray();

        $userCache = [];
        foreach ($rows as &$row) {
            $snapshot = json_decode((string) ($row['workflow_snapshot'] ?? ''), true);
            $expectedIds = [];
            foreach ((array) ($snapshot['steps'] ?? []) as $step) {
                if ((int) ($step['id'] ?? 0) === (int) ($row['current_step_id'] ?? 0)) {
                    $expectedIds = array_values(array_unique(array_map(
                        'intval',
                        (array) ($step['expected_approver_user_ids'] ?? [])
                    )));
                    break;
                }
            }

            $approvers = [];
            foreach ($expectedIds as $userId) {
                if (!array_key_exists($userId, $userCache)) {
                    $userCache[$userId] = $db->table('users u')
                        ->select('u.id, u.name, u.email, r.name AS role_name')
                        ->join('roles r', 'r.id = u.role_id', 'left')
                        ->where('u.id', $userId)
                        ->where('u.company_id', $companyId)
                        ->where('u.is_active', 1)
                        ->get()->getRowArray();
                }
                if ($userCache[$userId]) {
                    $approvers[] = $userCache[$userId];
                }
            }

            $row['current_approvers'] = $approvers;
            $row['change_detail'] = $this->rbacChangeDetail(
                $db,
                (string) ($row['http_method'] ?? ''),
                (string) ($row['request_path'] ?? ''),
                $row['request_payload'] ?? null,
                $companyId
            );
            unset($row['workflow_snapshot'], $row['request_hash'], $row['request_payload']);
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * Bentuk detail perubahan role tanpa mengekspos payload mentah.
     *
     * @return array<string,mixed>
     */
    private function rbacChangeDetail(
        $db,
        string $method,
        string $path,
        ?string $rawPayload,
        int $companyId
    ): array {
        $snapshot = is_string($rawPayload) && $rawPayload !== ''
            ? json_decode($rawPayload, true)
            : null;
        if (!is_array($snapshot)) {
            return ['available' => false];
        }
        // schema_version=1 menyimpan state sebelum + payload usulan.
        // Fallback mendukung payload mentah dari versi transisi.
        $payload = isset($snapshot['proposed']) && is_array($snapshot['proposed'])
            ? $snapshot['proposed']
            : $snapshot;
        $beforeSnapshot = isset($snapshot['before']) && is_array($snapshot['before'])
            ? $snapshot['before']
            : null;

        $roleId = null;
        if (preg_match('#/roles/(\d+)$#', $path, $matches)) {
            $roleId = (int) $matches[1];
        }

        $existing = $beforeSnapshot;
        $oldPermissionIds = isset($beforeSnapshot['permissions'])
            ? array_values(array_unique(array_map('intval', (array) $beforeSnapshot['permissions'])))
            : [];
        if ($roleId !== null && $existing === null) {
            $existing = $db->table('roles')
                ->where('id', $roleId)
                ->groupStart()
                ->where('company_id', $companyId)
                ->orWhere('company_id IS NULL')
                ->groupEnd()
                ->get()->getRowArray();
            if ($existing) {
                $oldPermissionIds = array_map(
                    'intval',
                    array_column(
                        $db->table('role_permissions')
                            ->select('role_permissions.permission_id')
                            ->join('roles', 'roles.id = role_permissions.role_id')
                            ->where('role_id', $roleId)
                            ->groupStart()
                            ->where('roles.company_id', $companyId)
                            ->orWhere('roles.company_id IS NULL')
                            ->groupEnd()
                            ->get()->getResultArray(),
                        'permission_id'
                    )
                );
            }
        }

        $newPermissionIds = isset($payload['permissions']) && is_array($payload['permissions'])
            ? array_values(array_unique(array_filter(array_map('intval', $payload['permissions']))))
            : $oldPermissionIds;
        $addedIds = array_values(array_diff($newPermissionIds, $oldPermissionIds));
        $removedIds = array_values(array_diff($oldPermissionIds, $newPermissionIds));
        $keptIds = array_values(array_intersect($newPermissionIds, $oldPermissionIds));
        $allIds = array_values(array_unique(array_merge($addedIds, $removedIds, $keptIds)));

        $permissionMap = [];
        if ($allIds !== []) {
            foreach ($db->table('permissions')
                ->select('id, slug, name, `group`')
                ->whereIn('id', $allIds)
                ->orderBy('slug', 'ASC')
                ->get()->getResultArray() as $permission
            ) {
                $permissionMap[(int) $permission['id']] = $permission;
            }
        }
        $resolve = static fn (array $ids): array => array_values(array_filter(array_map(
            static fn (int $id): ?array => $permissionMap[$id] ?? null,
            $ids
        )));

        return [
            'available' => true,
            'action' => match (strtoupper($method)) {
                'POST' => 'CREATE',
                'DELETE' => 'DELETE',
                default => 'UPDATE',
            },
            'role_id' => $roleId,
            'role_slug' => $existing['slug'] ?? ($payload['slug'] ?? null),
            'role_name_before' => $existing['name'] ?? null,
            'role_name_after' => $payload['name'] ?? ($existing['name'] ?? null),
            'description_before' => $existing['description'] ?? null,
            'description_after' => array_key_exists('description', $payload)
                ? $payload['description']
                : ($existing['description'] ?? null),
            'permissions_added' => $resolve($addedIds),
            'permissions_removed' => $resolve($removedIds),
            'permissions_kept' => $resolve($keptIds),
            'permission_count_before' => count($oldPermissionIds),
            'permission_count_after' => count($newPermissionIds),
        ];
    }

    public function create()
    {
        if ($block = $this->ensureSuperAdmin()) {
            return $block;
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'slug' => 'required|alpha_dash|max_length[50]',
            'name' => 'required|max_length[100]',
        ]);
        if (!$valid) {
            return null;
        }

        // Cegah pembuatan role bernama `super_admin` lewat API (slug ini
        // dipakai sebagai bypass permission di TenantContext).
        if (strtolower($data['slug']) === 'super_admin') {
            return $this->fail(
                'Slug "super_admin" tidak diperbolehkan',
                422,
                'RESERVED_SLUG'
            );
        }

        $db = \Config\Database::connect();
        $db->table('roles')->insert([
            'company_id' => $this->companyId(),
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        if (!empty($data['permissions']) && is_array($data['permissions'])) {
            $this->syncPermissions($db, $id, $data['permissions']);
        }
        Services::audit()->forModule('SECURITY')->log(
            action: 'role.create',
            entity: 'roles',
            entityId: $id,
            after: ['slug' => $data['slug'], 'name' => $data['name']],
            description: "Membuat role: {$data['name']} ({$data['slug']})",
            severity: 'CRITICAL',
        );

        return $this->ok(['id' => $id], 'Role created', 201);
    }

    public function update($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) {
            return $block;
        }

        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if ($existing['company_id'] !== null && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Role bukan milik tenant target', 403);
        }

        // Super admin BOLEH edit role apa pun, termasuk yang `is_system=1`
        // (hr_admin, manager, finance, employee, bahkan super_admin sendiri).
        // Yang tidak boleh: ganti slug system role karena slug dipakai
        // referensi di kode (mis. `super_admin` di TenantContext::isSuperAdmin
        // tetap pakai role_id=1, tapi role-role lain dirujuk via slug
        // di seed/permission group).
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name', 'description']));
        $update['updated_at'] = date('Y-m-d H:i:s');

        // Super Admin adalah break-glass role dan bypass permission lookup.
        // Membiarkan checklist-nya diedit memberi kesan palsu bahwa role ini
        // dapat dibatasi lewat RBAC, padahal server tetap mengizinkannya.
        if ((int) $existing['id'] === 1 && isset($data['permissions']) && is_array($data['permissions'])) {
            $currentPermissionIds = array_map(
                'intval',
                array_column(
                    // @tenant-scope-reviewed role_id=1 is the immutable global break-glass role.
                    $db->table('role_permissions')->select('permission_id')
                        ->where('role_id', 1)->get()->getResultArray(),
                    'permission_id',
                ),
            );
            $requestedPermissionIds = array_values(array_unique(array_filter(array_map('intval', $data['permissions']))));
            sort($currentPermissionIds);
            sort($requestedPermissionIds);
            if ($currentPermissionIds !== $requestedPermissionIds) {
                return $this->fail(
                    'Permission Super Admin bersifat permanen karena merupakan role break-glass',
                    422,
                    'SUPER_ADMIN_PERMISSIONS_IMMUTABLE',
                );
            }
        }

        // Global system roles are templates shared by all tenants. On first
        // edit, create a tenant-local copy and move only this tenant's users
        // and approval-chain references to it. Never mutate the global map.
        if ($existing['company_id'] === null && (int) $existing['id'] !== 1) {
            $existing = $this->localizeGlobalRole($db, $existing, $this->companyId());
            $id = (int) $existing['id'];
        }

        $db->table('roles')->where('id', $id)->update($update);

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->syncPermissions($db, $id, $data['permissions']);
        }
        Services::audit()->forModule('SECURITY')->log(
            action: 'role.update',
            entity: 'roles',
            entityId: $id,
            before: $existing,
            after: array_merge($update, ['permissions' => $data['permissions'] ?? null]),
            description: "Mengubah role: {$existing['name']} ({$existing['slug']})"
                . ((int) $existing['is_system'] === 1 ? ' [SYSTEM ROLE]' : ''),
            severity: 'CRITICAL',
        );

        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) {
            return $block;
        }

        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if ($existing['company_id'] !== null && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Role bukan milik tenant target', 403);
        }
        if ((int) $existing['is_system'] === 1) {
            return $this->fail('Cannot delete system role', 403);
        }

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
            action: 'role.delete',
            entity: 'roles',
            entityId: $id,
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
        if ($rows) {
            // roleId originates from a role created/scoped above; this junction has no company_id.
            // @tenant-scope-reviewed
            $db->table('role_permissions')->insertBatch($rows);
        }
    }

    /**
     * Clone-on-write for a global role template.
     *
     * @param array<string,mixed> $globalRole
     * @return array<string,mixed>
     */
    private function localizeGlobalRole($db, array $globalRole, int $companyId): array
    {
        if ($companyId <= 0) {
            throw new \RuntimeException('Company target wajib dipilih sebelum mengubah role tenant');
        }

        $local = $db->table('roles')
            ->where('company_id', $companyId)
            ->where('slug', $globalRole['slug'])
            ->get()->getRowArray();
        if ($local) {
            $this->rebindTenantRoleReferences(
                $db,
                $companyId,
                (int) $globalRole['id'],
                (int) $local['id'],
                date('Y-m-d H:i:s'),
            );
            return $local;
        }

        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $db->table('roles')->insert([
                'company_id' => $companyId,
                'slug' => $globalRole['slug'],
                'name' => $globalRole['name'],
                'description' => $globalRole['description'] ?? null,
                'is_system' => (int) ($globalRole['is_system'] ?? 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $localId = (int) $db->insertID();
            $permissionIds = array_map(
                'intval',
                array_column(
                    $db->table('role_permissions')->select('permission_id')
                        ->where('role_id', (int) $globalRole['id'])->get()->getResultArray(),
                    'permission_id',
                ),
            );
            if ($permissionIds !== []) {
                $this->syncPermissions($db, $localId, $permissionIds);
            }

            $this->rebindTenantRoleReferences(
                $db,
                $companyId,
                (int) $globalRole['id'],
                $localId,
                $now,
            );

            if (! $db->transStatus()) {
                throw new \RuntimeException('Gagal membuat role khusus tenant');
            }
            $db->transCommit();

            return $db->table('roles')->where('id', $localId)->get()->getRowArray();
        } catch (\Throwable $error) {
            $db->transRollback();
            throw $error;
        }
    }

    private function rebindTenantRoleReferences(
        $db,
        int $companyId,
        int $globalRoleId,
        int $localRoleId,
        string $now,
    ): void {
        $db->table('users')
            ->where('company_id', $companyId)
            ->where('role_id', $globalRoleId)
            ->update(['role_id' => $localRoleId, 'updated_at' => $now]);

        $db->table('approval_chain_steps')
            ->where('company_id', $companyId)
            ->where('approver_role_id', $globalRoleId)
            ->update(['approver_role_id' => $localRoleId, 'updated_at' => $now]);
    }
}
