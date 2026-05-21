<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class RoleController extends ApiController
{
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
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'slug' => 'required|alpha_dash|max_length[50]',
            'name' => 'required|max_length[100]',
        ]);
        if (! $valid) return null;

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
        Services::audit()->log('role.create', 'role', $id);
        return $this->ok(['id' => $id], 'Role created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if ((int) $existing['is_system'] === 1) return $this->fail('Cannot modify system role', 403);

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name', 'description']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('roles')->where('id', $id)->update($update);

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->syncPermissions($db, $id, $data['permissions']);
        }
        Services::audit()->log('role.update', 'role', $id);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('roles')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if ((int) $existing['is_system'] === 1) return $this->fail('Cannot delete system role', 403);
        $db->table('role_permissions')->where('role_id', $id)->delete();
        $db->table('roles')->where('id', $id)->delete();
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