<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Disciplinary Records (Surat Peringatan & Pelanggaran)
 *
 * Modul untuk mencatat tindakan disipliner karyawan: SP1/SP2/SP3, teguran
 * lisan, hingga skorsing dan PHK. Mengikuti praktik HR Indonesia berdasarkan
 * UU 13/2003 Pasal 161 (yang sudah dicabut UU Cipta Kerja) dan PP 35/2021.
 *
 * Konvensi umum di Indonesia (boleh dikustom per perusahaan):
 *   - SP1 berlaku 6 bulan
 *   - SP2 berlaku 6 bulan (kalau pelanggaran berulang dalam masa SP1 aktif)
 *   - SP3 berlaku 6 bulan (kalau pelanggaran berulang dalam masa SP2 aktif)
 *   - Pelanggaran setelah SP3 aktif → trigger workflow PHK dengan pesangon
 *     sesuai PP 35/2021
 *
 * Idempotent: aman dijalankan ulang.
 */
class CreateDisciplinaryRecords extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('disciplinary_categories')) {
            // Master kategori pelanggaran (mis. "Terlambat berulang",
            // "Tidak masuk tanpa kabar", "Pelanggaran K3", dst.)
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 32],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'severity'     => [
                    'type' => 'ENUM',
                    'constraint' => ['MINOR','MODERATE','MAJOR','SEVERE'],
                    'default' => 'MINOR',
                    'comment' => 'MINOR=teguran lisan, MODERATE=SP1, MAJOR=SP2, SEVERE=SP3/PHK',
                ],
                'recommended_level' => [
                    'type' => 'ENUM',
                    'constraint' => ['VERBAL','SP1','SP2','SP3','SUSPEND','TERMINATION'],
                    'default' => 'VERBAL',
                ],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('disciplinary_categories');
        }

        if (! $db->tableExists('disciplinary_records')) {
            $forge->addField([
                'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'           => ['type' => 'INT', 'unsigned' => true],
                'employee_id'          => ['type' => 'INT', 'unsigned' => true],
                'category_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                'level' => [
                    'type' => 'ENUM',
                    'constraint' => ['VERBAL','SP1','SP2','SP3','SUSPEND','TERMINATION'],
                    'default' => 'SP1',
                    'comment' => 'Tingkat sanksi',
                ],
                'reference_number'     => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Mis. SP1/HR/2026/00012'],
                'subject'              => ['type' => 'VARCHAR', 'constraint' => 255,
                    'comment' => 'Judul singkat: "Keterlambatan Berulang"'],
                'description'          => ['type' => 'TEXT',
                    'comment' => 'Uraian pelanggaran lengkap'],

                'incident_date'        => ['type' => 'DATE',
                    'comment' => 'Tanggal kejadian/pelanggaran'],
                'effective_date'       => ['type' => 'DATE',
                    'comment' => 'Tanggal SP berlaku'],
                'expires_at'           => ['type' => 'DATE', 'null' => true,
                    'comment' => 'NULL = permanen; default 6 bulan setelah effective'],

                // Status SP
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','ACTIVE','EXPIRED','REVOKED'],
                    'default' => 'DRAFT',
                ],
                'acknowledged_at'      => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Saat karyawan menerima/tanda tangan SP'],
                'acknowledgment_note'  => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Tanggapan/pembelaan karyawan'],
                'acknowledged_signature' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                    'comment' => 'Path file tanda tangan / referensi e-sign'],

                // Sumber dokumen
                'letter_html'          => ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot HTML surat saat di-generate'],
                'template_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Reference ke contract_templates (contract_type=SP)'],
                'attachment_path'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                    'comment' => 'PDF/scan dokumen yang sudah ditandatangani'],

                // Audit
                'issued_by'            => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User HR/atasan yang menerbitkan'],
                'revoked_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'revoked_at'           => ['type' => 'DATETIME', 'null' => true],
                'revocation_reason'    => ['type' => 'TEXT', 'null' => true],

                'created_at'           => ['type' => 'DATETIME', 'null' => true],
                'updated_at'           => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'           => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['employee_id', 'level', 'status']);
            $forge->addKey(['company_id', 'status']);
            $forge->addUniqueKey('reference_number');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('category_id', 'disciplinary_categories', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('disciplinary_records');
        }

        // ─── Permission baru ────────────────────────────────────────
        $newPermissions = [
            ['slug' => 'disciplinary.view',   'name' => 'View Disciplinary Records', 'group' => 'hr'],
            ['slug' => 'disciplinary.manage', 'name' => 'Manage Disciplinary Records & Issue SP', 'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant ke role 1 (super admin) & 2 (HR admin) saja
        foreach ([1, 2] as $roleId) {
            foreach (array_column($newPermissions, 'slug') as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at' => $now,
                ]);
            }
        }

        // ─── Tambah enum value 'SP' & 'PHK' ke contract_templates ───
        // (SQLite: enum direpresentasikan sebagai CHECK constraint; di
        // MySQL kita perlu ALTER kolom. Untuk portabilitas, kita kerjakan
        // best-effort lewat raw SQL kalau driver mendukung.)
        try {
            $platform = strtolower($db->getPlatform());
            if (str_contains($platform, 'mysql')) {
                $db->query(
                    "ALTER TABLE contract_templates MODIFY contract_type ENUM("
                    . "'PKWT','PKWTT','PROBATION','PKHL','MAGANG','OUTSOURCING','GENERIC',"
                    . "'SP','PHK'"
                    . ") DEFAULT 'PKWTT'"
                );
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Gagal extend enum contract_type: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        if ($db->tableExists('disciplinary_records'))    $forge->dropTable('disciplinary_records', true);
        if ($db->tableExists('disciplinary_categories')) $forge->dropTable('disciplinary_categories', true);
    }
}