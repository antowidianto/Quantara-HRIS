<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNotificationBroadcastPermission extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $permission = $db->table('permissions')->where('slug', 'notifications.broadcast')->get()->getRowArray();
        if (! $permission) {
            $db->table('permissions')->insert([
                'slug' => 'notifications.broadcast',
                'name' => 'Kirim Broadcast Notifikasi',
                'group' => 'notification',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $permissionId = (int) $db->insertID();
        } else {
            $permissionId = (int) $permission['id'];
        }

        $roles = $db->table('roles')->select('id')->whereIn('slug', ['super_admin', 'hr_admin', 'hr_director'])->get()->getResultArray();
        foreach ($roles as $role) {
            $exists = $db->table('role_permissions')->where('role_id', (int) $role['id'])
                ->where('permission_id', $permissionId)->countAllResults();
            if (! $exists) $db->table('role_permissions')->insert(['role_id' => (int) $role['id'], 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permission = $this->db->table('permissions')->where('slug', 'notifications.broadcast')->get()->getRowArray();
        if (! $permission) return;
        $this->db->table('role_permissions')->where('permission_id', (int) $permission['id'])->delete();
        $this->db->table('permissions')->where('id', (int) $permission['id'])->delete();
    }
}
