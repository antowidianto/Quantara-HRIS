<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Seed permission slugs already enforced by runtime code but absent from RBAC master data. */
class SeedRuntimePermissionGaps extends Migration
{
    private const PERMISSIONS = [
        ['slug' => 'branding.manage', 'name' => 'Kelola Branding Perusahaan', 'group' => 'branding', 'roles' => ['super_admin', 'hr_admin']],
        ['slug' => 'candidate.manage', 'name' => 'Kelola Kandidat', 'group' => 'recruitment', 'roles' => ['super_admin', 'hr_admin', 'hr_director', 'recruiter']],
        ['slug' => 'data_export.manage', 'name' => 'Kelola Ekspor Data Tenant', 'group' => 'privacy', 'roles' => ['super_admin', 'hr_admin', 'hr_director', 'data_privacy_officer']],
        ['slug' => 'performance.nominate_peer', 'name' => 'Nominasikan Peer Review', 'group' => 'performance', 'roles' => ['super_admin', 'hr_admin', 'hr_director', 'manager', 'employee']],
        ['slug' => 'pulse_survey.manage', 'name' => 'Kelola Pulse Survey', 'group' => 'performance', 'roles' => ['super_admin', 'hr_admin', 'hr_director', 'hr_business_partner']],
        ['slug' => 'reimbursement.manage', 'name' => 'Kelola Reimbursement', 'group' => 'payroll', 'roles' => ['super_admin', 'hr_admin', 'finance', 'cfo']],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('permissions') || ! $this->db->tableExists('roles') || ! $this->db->tableExists('role_permissions')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::PERMISSIONS as $definition) {
            $slug = $definition['slug'];
            $name = $definition['name'];
            $group = $definition['group'];
            $roleSlugs = $definition['roles'];
            $permission = $this->db->table('permissions')->where('slug', $slug)->get()->getRowArray();
            if (! $permission) {
                $this->db->table('permissions')->insert([
                    'slug' => $slug,
                    'name' => $name,
                    'group' => $group,
                    'created_at' => $now,
                ]);
                $permissionId = (int) $this->db->insertID();
            } else {
                $permissionId = (int) $permission['id'];
            }

            $roles = $this->db->table('roles')->select('id')->whereIn('slug', $roleSlugs)->get()->getResultArray();
            foreach ($roles as $role) {
                $roleId = (int) $role['id'];
                $exists = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->countAllResults();
                if (! $exists) {
                    $this->db->table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('permissions') || ! $this->db->tableExists('role_permissions')) {
            return;
        }

        $permissions = $this->db->table('permissions')
            ->select('id')
            ->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))
            ->get()
            ->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $permissions);
        if ($ids !== []) {
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
    }
}
