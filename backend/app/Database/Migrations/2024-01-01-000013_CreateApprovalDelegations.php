<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Approval Delegation
 *
 * Tabel `approval_delegations` mengizinkan user (mis. manager yg cuti) untuk
 * mendelegasikan kewenangan approval-nya ke user lain selama rentang tanggal
 * tertentu, opsional dibatasi per modul.
 *
 * Resolver di `ApprovalEngine` akan, saat menentukan approver step, meng-expand
 * setiap user_id yg seharusnya jadi approver dengan daftar delegate aktif.
 * User asli TETAP boleh approve (delegasi tidak menghilangkan kewenangan), tapi
 * delegate juga boleh approve mewakili.
 *
 * Idempotent: aman dijalankan ulang.
 */
class CreateApprovalDelegations extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('approval_delegations')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'user_id'             => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'User yang mendelegasikan'],
                'delegate_to_user_id' => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'User pengganti'],
                'module'              => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'NULL = semua modul. Atau LEAVE/OVERTIME/...'],
                'date_from'           => ['type' => 'DATE'],
                'date_to'             => ['type' => 'DATE'],
                'reason'              => ['type' => 'TEXT', 'null' => true],
                'is_active'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'user_id']);
            $forge->addKey(['delegate_to_user_id']);
            $forge->addKey(['date_from', 'date_to']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('delegate_to_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_delegations');
        }

        // Permission baru: setiap user boleh menulis delegasinya sendiri,
        // tapi admin/HR boleh manage milik orang lain.
        $newPermissions = [
            ['slug' => 'delegation.view',        'name' => 'View Approval Delegations',     'group' => 'admin'],
            ['slug' => 'delegation.manage',      'name' => 'Manage Approval Delegations',   'group' => 'admin'],
            ['slug' => 'delegation.manage_self', 'name' => 'Manage Own Approval Delegation','group' => 'employee'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        $allSlugs = array_column($newPermissions, 'slug');
        // Auto-grant: admin (1) & HR admin (2) semua, manager (3) & finance (5)
        // & employee (4) hanya manage_self.
        $grants = [
            1 => $allSlugs,
            2 => $allSlugs,
            3 => ['delegation.manage_self'],
            4 => ['delegation.manage_self'],
            5 => ['delegation.manage_self'],
        ];
        foreach ($grants as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        if ($db->tableExists('approval_delegations')) {
            $forge->dropTable('approval_delegations', true);
        }
    }
}