<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allowance mapping berbasis cabang & grade dengan threshold jarak.
 *
 * Konteks bisnis:
 *  - Karyawan yang tinggal jauh (≥ threshold km) dari cabang/kantor tempat
 *    dia ditugaskan berhak atas tunjangan jarak (mis. transport).
 *  - Besaran tunjangan tergantung **job_grade_id**: setiap grade punya
 *    tarifnya sendiri.
 *  - `allowance_types` mendefinisikan jenis tunjangan per perusahaan
 *    (mis. "Tunjangan Transport Jarak"). Tabel ini terhubung opsional
 *    ke `payroll_components.id` agar saat payroll dihitung, nilainya
 *    tampil sebagai komponen earning.
 *  - `allowance_rates` memetakan grade → tarif. Threshold default 30 km
 *    sesuai requirement. Boleh berbeda per company/per allowance.
 *  - `employee_allowance_assignments` adalah snapshot per karyawan
 *    setelah perhitungan jarak: distance_km, eligible flag, amount
 *    final. Snapshot diregenerasi saat data master berubah.
 *
 * Selain itu kita tambah dua kolom kecil di `employees` untuk koordinat
 * rumah karyawan, dipakai untuk perhitungan jarak Haversine.
 */
class CreateAllowanceMapping extends Migration
{
    public function up(): void
    {
        // ─── Tambah koordinat rumah di employees ────────────────────
        $fields = [
            'home_lat' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,7',
                'null'       => true,
                'after'      => 'postal_code',
            ],
            'home_lng' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,7',
                'null'       => true,
                'after'      => 'home_lat',
            ],
        ];
        // SQLite tidak support `AFTER`; cek dulu agar safe lintas DB.
        $db = \Config\Database::connect();
        if (! $db->fieldExists('home_lat', 'employees')) {
            $this->forge->addColumn('employees', ['home_lat' => $fields['home_lat']]);
        }
        if (! $db->fieldExists('home_lng', 'employees')) {
            $this->forge->addColumn('employees', ['home_lng' => $fields['home_lng']]);
        }

        // ─── ALLOWANCE TYPES ────────────────────────────────────────
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'unsigned' => true],
            'code'            => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'     => ['type' => 'TEXT', 'null' => true],
            'category'        => [
                'type' => 'ENUM',
                'constraint' => ['ALLOWANCE','TRANSPORT','MEAL','HOUSING','OTHER'],
                'default' => 'TRANSPORT',
            ],
            'is_taxable'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'is_distance_based'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => '1 = pakai threshold jarak; 0 = flat per-grade'],
            'default_threshold_km' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 30.00],
            // Optional link ke payroll_components agar tampil otomatis di slip.
            'payroll_component_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addKey('company_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('payroll_component_id', 'payroll_components', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('allowance_types');

        // ─── ALLOWANCE RATES (per grade, per branch optional) ───────
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'         => ['type' => 'INT', 'unsigned' => true],
            'allowance_type_id'  => ['type' => 'INT', 'unsigned' => true],
            'job_grade_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL = berlaku untuk semua grade (fallback)'],
            'branch_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL = semua cabang dalam company'],
            'threshold_km'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 30.00],
            // Tarif saat jarak < threshold_km (umumnya 0 untuk transport).
            'rate_below'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            // Tarif saat jarak >= threshold_km.
            'rate_above'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'effective_from'     => ['type' => 'DATE', 'null' => true],
            'effective_to'       => ['type' => 'DATE', 'null' => true],
            'notes'              => ['type' => 'TEXT', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'allowance_type_id']);
        $this->forge->addKey('job_grade_id');
        $this->forge->addKey('branch_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('allowance_type_id', 'allowance_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('job_grade_id', 'job_grades', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('branch_id', 'branches', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('allowance_rates');

        // ─── EMPLOYEE ALLOWANCE ASSIGNMENTS (snapshot per employee) ─
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'         => ['type' => 'INT', 'unsigned' => true],
            'employee_id'        => ['type' => 'INT', 'unsigned' => true],
            'allowance_type_id'  => ['type' => 'INT', 'unsigned' => true],
            'allowance_rate_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'rate yang dipakai'],
            'branch_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'job_grade_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'distance_km'        => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'threshold_km'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'eligible'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'comment' => '1 = jarak >= threshold (dapat tunjangan)'],
            'amount'             => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'note'               => ['type' => 'TEXT', 'null' => true],
            'manual_override'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'comment' => '1 = HR override; jangan auto-recompute'],
            'computed_at'        => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['employee_id', 'allowance_type_id']);
        $this->forge->addKey(['company_id', 'allowance_type_id']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('allowance_type_id', 'allowance_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('allowance_rate_id', 'allowance_rates', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('employee_allowance_assignments');
    }

    public function down(): void
    {
        $this->forge->dropTable('employee_allowance_assignments', true);
        $this->forge->dropTable('allowance_rates', true);
        $this->forge->dropTable('allowance_types', true);

        $db = \Config\Database::connect();
        if ($db->fieldExists('home_lat', 'employees')) {
            $this->forge->dropColumn('employees', 'home_lat');
        }
        if ($db->fieldExists('home_lng', 'employees')) {
            $this->forge->dropColumn('employees', 'home_lng');
        }
    }
}