<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Severance Calculations
 *
 * Snapshot perhitungan pesangon / UPMK / UPH berdasarkan UU 6/2023 (Cipta
 * Kerja) dan PP 35/2021. Setiap baris adalah perhitungan untuk satu
 * karyawan yang akan / sudah di-PHK, dengan formula yang sudah dibekukan
 * di waktu kalkulasi (tabel & multiplier disimpan as-is, bukan reference)
 * supaya audit trail tetap valid meski regulasi berubah.
 *
 * Status workflow:
 *   DRAFT       — preview, masih bisa diedit
 *   FINALIZED   — disetujui, masuk ke kewajiban payroll
 *   PAID        — sudah dibayarkan ke karyawan
 *   CANCELLED   — dibatalkan
 *
 * Idempotent: cek table sudah ada.
 */
class CreateSeveranceCalculations extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('severance_calculations')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'disciplinary_record_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Link ke disciplinary_records kalau PHK karena pelanggaran'],

                // Input perhitungan (di-freeze saat finalize)
                'termination_date'    => ['type' => 'DATE'],
                'termination_reason'  => [
                    'type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Slug alasan PHK (RESIGN, EFFICIENCY_LOSS, ...)'
                ],
                'reason_label'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'last_monthly_salary' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Upah bulanan dasar perhitungan: gaji pokok + tunjangan tetap'],
                'service_years'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => 'Masa kerja dalam tahun (decimal: 5.5 = 5 thn 6 bln)'],
                'service_months'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

                // Komponen Pesangon (Uang Pesangon — Pasal 40 PP 35/2021)
                'pesangon_multiplier' => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 0,
                    'comment' => '0.5, 1.0, 1.75, 2.0, dst sesuai alasan PHK'],
                'pesangon_months'     => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => '1-9 bulan sesuai masa kerja'],
                'pesangon_amount'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

                // UPMK (Uang Penghargaan Masa Kerja — Pasal 40 ayat 3)
                'upmk_multiplier'     => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 0],
                'upmk_months'         => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => '0/2/3/4/5/6/7/8/10 bulan tergantung masa kerja'],
                'upmk_amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

                // UPH (Uang Penggantian Hak — Pasal 40 ayat 4)
                'uph_unused_leave_amount'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Cuti tahunan belum diambil × upah harian'],
                'uph_homecoming_amount'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Biaya/ongkos pulang ke tempat penerimaan kerja'],
                'uph_other_amount'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Hak lain sesuai PKWT/PP/PKB'],
                'uph_total'                 => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

                // Uang pisah (Pasal 50, untuk yg mengundurkan diri sukarela atau PHK karena pelanggaran berat)
                'separation_pay'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

                // Total final
                'total_amount'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

                // Snapshot dokumen
                'breakdown_html'      => ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot PDF/HTML perhitungan saat finalize'],
                'notes'               => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan HR (kasus khusus, kesepakatan tambahan, dst)'],
                'unused_leave_days'   => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => 'Sisa hari cuti tahunan (input dari leave_balances atau manual)'],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','FINALIZED','PAID','CANCELLED'],
                    'default' => 'DRAFT',
                ],

                // Audit
                'calculated_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'finalized_at'        => ['type' => 'DATETIME', 'null' => true],
                'finalized_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'paid_at'             => ['type' => 'DATETIME', 'null' => true],
                'paid_by'             => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancelled_at'        => ['type' => 'DATETIME', 'null' => true],
                'cancellation_reason' => ['type' => 'TEXT', 'null' => true],

                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey('termination_date');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('disciplinary_record_id', 'disciplinary_records', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('severance_calculations');
        }

        // Permission baru
        $newPermissions = [
            ['slug' => 'severance.view',    'name' => 'View Severance Calculations', 'group' => 'hr'],
            ['slug' => 'severance.manage',  'name' => 'Calculate & Finalize Severance', 'group' => 'hr'],
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
    }

    public function down(): void
    {
        if ($this->db->tableExists('severance_calculations')) {
            $this->forge->dropTable('severance_calculations', true);
        }
    }
}