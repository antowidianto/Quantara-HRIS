<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #068 — Permissions baru untuk batch kedua bulk import.
 *
 * Setelah Migration #067 mengaktifkan import untuk 6 modul master inti,
 * batch ini menambahkan 8 modul lagi yang sering dibutuhkan HR untuk
 * onboarding awal / migrasi data:
 *
 *   - allowance_types        — kategori tunjangan
 *   - payroll_components     — komponen earning/deduction payroll
 *   - leave_types            — jenis cuti (cuti tahunan, sakit, melahirkan, dll)
 *   - assets                 — inventory IT, kendaraan, ID card
 *   - cost_centers           — dimensi accounting cost center
 *   - organization_structures— jabatan kunci untuk approval lookup
 *   - work_locations         — lokasi kerja granular (gedung, lantai, area)
 *   - shifts                 — shift kerja (pagi, sore, malam, dst.)
 *
 * Resource ini di-handle oleh `App\Libraries\Import\ImportRegistry` dan
 * dispatcher generic `ImportController` yang sudah dibuat di #067.
 *
 * Tabel `import_batches` dari #067 sudah cukup untuk menampung batch
 * tambahan ini — tidak ada perubahan schema.
 *
 * Idempotent: aman dijalankan dua kali.
 */
class AddImportPermissionsBatch2 extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions')) return;

        $newPermissions = [
            ['slug' => 'allowance_types.bulk_import',          'name' => 'Bulk Import Master Tunjangan dari Excel', 'group' => 'master'],
            ['slug' => 'payroll_components.bulk_import',       'name' => 'Bulk Import Komponen Payroll dari Excel', 'group' => 'master'],
            ['slug' => 'leave_types.bulk_import',              'name' => 'Bulk Import Jenis Cuti dari Excel',       'group' => 'master'],
            ['slug' => 'assets.bulk_import',                   'name' => 'Bulk Import Asset dari Excel',            'group' => 'asset'],
            ['slug' => 'cost_centers.bulk_import',             'name' => 'Bulk Import Cost Center dari Excel',      'group' => 'master'],
            ['slug' => 'organization_structures.bulk_import',  'name' => 'Bulk Import Master Jabatan Kunci dari Excel', 'group' => 'master'],
            ['slug' => 'work_locations.bulk_import',           'name' => 'Bulk Import Lokasi Kerja dari Excel',     'group' => 'master'],
            ['slug' => 'shifts.bulk_import',                   'name' => 'Bulk Import Shift Kerja dari Excel',      'group' => 'master'],
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

        $slugs = [
            'allowance_types.bulk_import',
            'payroll_components.bulk_import',
            'leave_types.bulk_import',
            'assets.bulk_import',
            'cost_centers.bulk_import',
            'organization_structures.bulk_import',
            'work_locations.bulk_import',
            'shifts.bulk_import',
        ];
        $perms = $db->table('permissions')->whereIn('slug', $slugs)->get()->getResultArray();
        $ids = array_column($perms, 'id');
        if (! empty($ids) && $db->tableExists('role_permissions')) {
            $db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        $db->table('permissions')->whereIn('slug', $slugs)->delete();
    }
}