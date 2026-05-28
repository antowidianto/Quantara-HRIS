<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #071 — Permission baru untuk batch kelima bulk import.
 *
 * Sprint 5 menambahkan 2 modul import baru:
 *
 *   - loans — migrasi data pinjaman/kasbon aktif dari sistem lama
 *   - goals — cascade goal tahunan / KPI per karyawan
 *
 * Catatan: `assets` sudah punya permission dari Migration #068.
 * Sprint 5 hanya menambahkan tombol Import di view Assets.vue.
 *
 * Idempotent: aman dijalankan dua kali.
 */
class AddImportPermissionsBatch5 extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions')) return;

        $newPermissions = [
            ['slug' => 'loans.bulk_import', 'name' => 'Bulk Import Pinjaman/Kasbon dari Excel', 'group' => 'finance'],
            ['slug' => 'goals.bulk_import', 'name' => 'Bulk Import Performance Goals/KPI dari Excel', 'group' => 'performance'],
        ];

        $permCols = $db->getFieldNames('permissions');
        $now = date('Y-m-d H:i:s');

        foreach ($newPermissions as $p) {
            $existing = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
            if ($existing) continue;
            $insert = $p;
            if (in_array('created_at', $permCols, true)) $insert['created_at'] = $now;
            if (in_array('updated_at', $permCols, true)) $insert['updated_at'] = $now;
            $insert = array_intersect_key($insert, array_flip($permCols));
            $db->table('permissions')->insert($insert);
        }

        // Auto-grant ke role admin standar
        if ($db->tableExists('roles') && $db->tableExists('role_permissions')) {
            $rpCols = $db->getFieldNames('role_permissions');
            $roles = $db->table('roles')
                ->whereIn('slug', ['super_admin', 'hr_admin'])
                ->get()->getResultArray();
            foreach ($roles as $r) {
                foreach ($newPermissions as $p) {
                    $perm = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
                    if (! $perm) continue;
                    $exists = $db->table('role_permissions')
                        ->where('role_id', $r['id'])
                        ->where('permission_id', $perm['id'])
                        ->countAllResults();
                    if ($exists > 0) continue;
                    $rpInsert = ['role_id' => $r['id'], 'permission_id' => $perm['id']];
                    if (in_array('created_at', $rpCols, true)) $rpInsert['created_at'] = $now;
                    $rpInsert = array_intersect_key($rpInsert, array_flip($rpCols));
                    $db->table('role_permissions')->insert($rpInsert);
                }
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions')) return;

        $slugs = ['loans.bulk_import', 'goals.bulk_import'];
        $perms = $db->table('permissions')->whereIn('slug', $slugs)->get()->getResultArray();
        $ids = array_column($perms, 'id');
        if (! empty($ids) && $db->tableExists('role_permissions')) {
            $db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        $db->table('permissions')->whereIn('slug', $slugs)->delete();
    }
}
