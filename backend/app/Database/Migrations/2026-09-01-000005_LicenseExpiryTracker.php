<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Modul Baru — License & Certification Expiry Tracker.
 *
 * Membuka segmen healthcare (RS, klinik), EHS-heavy industry (manufaktur,
 * konstruksi), aviation, dan jasa profesional yang butuh tracking
 * sertifikat / lisensi karyawan dengan masa berlaku.
 *
 * Use cases:
 *   - Healthcare: STR, SIP dokter/perawat dengan expiry → blokir jadwal
 *     shift kalau STR expired.
 *   - EHS: BOSIET, K3 Umum, BNSP — wajib renewed sebelum tugas tertentu.
 *   - Aviation: pilot license, simulator currency, medical certificate.
 *   - Driver: SIM B1 Umum, SIM B2 Umum dengan recurrent training.
 *
 * Dua tabel:
 *   - license_types          : master jenis lisensi/sertifikat per company
 *   - employee_licenses      : lisensi yang dipegang karyawan
 *
 * Plus command `licenses:check-expiry` (cron harian) untuk:
 *   - Notifikasi H-30 / H-7 / H-1 ke karyawan & supervisor
 *   - Auto-update status menjadi EXPIRED setelah lewat tanggal
 *
 * Idempotent guard.
 *
 * Permission:
 *   - license_type.manage    : kelola master license type
 *   - employee_license.manage: input / update lisensi karyawan
 *   - employee_license.view  : lihat lisensi (HR / direct supervisor)
 */
class LicenseExpiryTracker extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── license_types ─────────────────────────────────────────
        if (! $db->tableExists('license_types')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'code'           => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Kode singkat (mis. STR, SIP, K3UMUM, BOSIET)'],
                'name'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'category'       => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'default'    => 'PROFESSIONAL',
                    'comment'    => 'PROFESSIONAL | SAFETY | TECHNICAL | MEDICAL | DRIVING | OTHER',
                ],
                'issuing_authority' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Lembaga yang menerbitkan (mis. KKI, KTKI, BNSP, Kemnaker)'],
                'validity_months'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Masa berlaku default (bulan). NULL = tidak fixed.'],
                'is_required_for_position_ids' => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'JSON array of position_id yang wajibkan license ini.'],
                'warn_before_days_json' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => '[30,7,1]',
                    'comment' => 'Hari sebelum expiry kapan harus notifikasi (default H-30, H-7, H-1)'],
                'block_assignment_on_expiry' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = blokir karyawan dari shift assignment kalau license expired (untuk healthcare/EHS)'],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'is_active']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('license_types');
        }

        // ─── employee_licenses ─────────────────────────────────────
        if (! $db->tableExists('employee_licenses')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'license_type_id' => ['type' => 'INT', 'unsigned' => true],
                'license_number'  => ['type' => 'VARCHAR', 'constraint' => 100,
                    'comment' => 'Nomor sertifikat / lisensi (mis. nomor STR)'],
                'issued_on'       => ['type' => 'DATE'],
                'expires_on'      => ['type' => 'DATE', 'null' => true,
                    'comment' => 'NULL = lifetime (mis. ijazah)'],
                'document_path'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'status'          => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'ACTIVE',
                    'comment'    => 'ACTIVE | EXPIRING_SOON | EXPIRED | RENEWED | REVOKED',
                ],
                'last_notified_threshold_day' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Threshold notif terakhir (anti duplicate). 30, 7, 1, atau 0 (expired notif).'],
                'last_notified_at' => ['type' => 'DATETIME', 'null' => true],
                'renewed_to_license_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Pointer ke license baru kalau status=RENEWED (audit trail)'],
                'note'            => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id', 'status']);
            $forge->addKey(['company_id', 'expires_on', 'status']);
            $forge->addKey(['license_type_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('license_type_id', 'license_types', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('employee_licenses');
        }

        // ─── Permissions ────────────────────────────────────────────
        if ($db->tableExists('permissions')) {
            $perms = [
                ['slug' => 'license_type.manage',     'name' => 'Manage License Types',     'group' => 'license', 'description' => 'Kelola master jenis lisensi/sertifikat'],
                ['slug' => 'employee_license.manage', 'name' => 'Manage Employee Licenses', 'group' => 'license', 'description' => 'Input / update lisensi karyawan'],
                ['slug' => 'employee_license.view',   'name' => 'View Employee Licenses',   'group' => 'license', 'description' => 'Lihat daftar lisensi karyawan'],
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($perms as $p) {
                $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
                if (! $exists) {
                    $db->table('permissions')->insert($p + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        foreach (['employee_licenses', 'license_types'] as $tbl) {
            if ($db->tableExists($tbl)) {
                $forge->dropTable($tbl, true);
            }
        }
        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'license_type.manage',
                'employee_license.manage',
                'employee_license.view',
            ])->delete();
        }
    }
}