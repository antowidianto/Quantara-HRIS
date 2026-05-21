<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * P0 Audit Hardening — kolom retention selfie + key_version untuk rotasi enkripsi.
 *
 * Lihat docs/AUDIT.md item B (key rotation) dan C (file storage retention).
 */
class AddP0HardeningFields extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── B.1 — kolom key_version untuk rotation ─────────────────
        // Default = 1 (key v1). Saat rotasi, command akan re-encrypt baris
        // dan bump nilai ke 2. Encryption library nanti baca kolom ini untuk
        // tahu key mana yang dipakai untuk decrypt.
        if ($db->tableExists('employees')) {
            $fields = $db->getFieldNames('employees');
            if (! in_array('key_version', $fields, true)) {
                $forge->addColumn('employees', [
                    'key_version' => [
                        'type'       => 'TINYINT',
                        'unsigned'   => true,
                        'default'    => 1,
                        'after'      => 'bank_account_encrypted',
                        'comment'    => 'Versi encryption key yang dipakai untuk *_encrypted kolom (lihat AUDIT.md B)',
                    ],
                ]);
            }
        }

        // ─── C.1 — retention selfie per company ─────────────────────
        // Setting per-company supaya bisa diatur kebijakan retensi yang
        // berbeda (mis. group A 90 hari, group B 365 hari kalau audit khusus).
        if ($db->tableExists('settings')) {
            // Ambil semua company yang aktif, insert default kalau belum ada
            $companies = $db->table('companies')->where('is_active', 1)
                ->select('id')->get()->getResultArray();
            $now = date('Y-m-d H:i:s');
            foreach ($companies as $c) {
                $exists = $db->table('settings')
                    ->where('company_id', (int) $c['id'])
                    ->where('key', 'selfie_retention_days')
                    ->countAllResults();
                if (! $exists) {
                    $db->table('settings')->insert([
                        'company_id' => (int) $c['id'],
                        'key'        => 'selfie_retention_days',
                        'value'      => '90',
                        'description'=> 'Berapa hari foto selfie absensi disimpan sebelum auto-cleanup (kecuali yang di-flag anomaly score >= 30)',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('employees')) {
            $fields = $db->getFieldNames('employees');
            if (in_array('key_version', $fields, true)) {
                $forge->dropColumn('employees', 'key_version');
            }
        }
        // Settings tidak di-rollback supaya manual cleanup tidak hilangkan
        // data history company.
    }
}