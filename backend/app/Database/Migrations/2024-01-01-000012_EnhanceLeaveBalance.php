<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Improvement set untuk leave balance:
 *
 *  1. Tambah konfigurasi carry-over & prorate di `leave_types`:
 *       - is_prorated:                 quota dihitung proporsional dari join_date
 *       - is_carry_over_allowed:       sisa boleh dipindah ke tahun depan
 *       - carry_over_max_days:         max hari yang boleh di-carry (UU 13/2003 = 6 bulan ekspirasi)
 *       - carry_over_expires_in_months: berapa bulan setelah carry-over kedaluwarsa
 *  2. Pastikan `leave_balances` punya `expires_at` (sudah ada) dan tambah
 *     metadata yang kita butuhkan supaya bisa di-trace.
 *
 * Dasar hukum: UU 13/2003 Pasal 79 ayat (5) — cuti tahunan yang tidak diambil
 * dalam waktu 6 bulan setelah masanya, dapat ditiadakan/diuangkan.
 *
 * Idempotent: aman dijalankan ulang.
 */
class EnhanceLeaveBalance extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. Extend `leave_types` ────────────────────────────────
        $additions = [
            'is_prorated' => [
                'type' => 'TINYINT', 'unsigned' => true, 'default' => 1,
                'comment' => '1 = quota proporsional sesuai bulan kerja di tahun pertama',
            ],
            'is_carry_over_allowed' => [
                'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                'comment' => '1 = sisa cuti boleh di-carry ke tahun depan',
            ],
            'carry_over_max_days' => [
                'type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true,
                'comment' => 'Max hari yg bisa di-carry (NULL = tanpa batas)',
            ],
            'carry_over_expires_in_months' => [
                'type' => 'INT', 'unsigned' => true, 'default' => 6,
                'comment' => 'Carry-over kedaluwarsa setelah X bulan (UU 13/2003: 6 bulan)',
            ],
        ];
        $existing = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_types'));
        $toAdd = [];
        foreach ($additions as $col => $def) {
            if (! in_array(strtolower($col), $existing, true)) {
                $toAdd[$col] = $def;
            }
        }
        if (! empty($toAdd)) {
            $forge->addColumn('leave_types', $toAdd);
        }

        // Sinkronkan default: kategori ANNUAL otomatis dapat carry-over.
        if (isset($toAdd['is_carry_over_allowed'])) {
            $db->table('leave_types')
                ->where('category', 'ANNUAL')
                ->update([
                    'is_carry_over_allowed'        => 1,
                    'carry_over_max_days'          => 6,
                    'carry_over_expires_in_months' => 6,
                ]);
        }

        // ─── 2. Permission baru (auto-grant ke role 1 & 2) ─────────
        $newPermissions = [
            ['slug' => 'leave_balance.view',   'name' => 'View Leave Balance Detail',  'group' => 'leave'],
            ['slug' => 'leave_balance.manage', 'name' => 'Manage Leave Balance',       'group' => 'leave'],
            ['slug' => 'leave.team_calendar',  'name' => 'View Team Leave Calendar',   'group' => 'leave'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        $allSlugs = array_column($newPermissions, 'slug');
        foreach ([1, 2] as $roleId) {
            foreach ($allSlugs as $slug) {
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

        $cols = ['is_prorated','is_carry_over_allowed','carry_over_max_days','carry_over_expires_in_months'];
        $existing = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_types'));
        foreach ($cols as $c) {
            if (in_array(strtolower($c), $existing, true)) {
                try { $forge->dropColumn('leave_types', $c); } catch (\Throwable $e) {}
            }
        }
    }
}