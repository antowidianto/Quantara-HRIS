<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLeaveOvertimeTables extends Migration
{
    public function up(): void
    {
        // ─── LEAVE TYPES ──────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'category'       => ['type' => 'ENUM', 'constraint' => ['ANNUAL','SICK','MATERNITY','PATERNITY','MENSTRUAL','MARRIAGE','BEREAVEMENT','HAJJ','UNPAID','SPECIAL'], 'default' => 'ANNUAL', 'comment' => 'Mengikuti UU Cipta Kerja & PP 35/2021'],
            'default_days'   => ['type' => 'INT', 'unsigned' => true, 'default' => 12, 'comment' => 'Cuti tahunan default 12 hari per UU 13/2003'],
            'max_days'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_paid'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'requires_doc'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'comment' => 'e.g. surat dokter for SICK'],
            'min_tenure_months' => ['type' => 'INT', 'unsigned' => true, 'default' => 12, 'comment' => 'Cuti tahunan: 12 bulan kerja'],
            'reset_yearly'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'gender_restriction' => ['type' => 'ENUM', 'constraint' => ['ALL','M','F'], 'default' => 'ALL'],
            'description'    => ['type' => 'TEXT', 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('leave_types');

        // ─── LEAVE BALANCES (per employee per year per type) ──────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'leave_type_id'  => ['type' => 'INT', 'unsigned' => true],
            'period_year'    => ['type' => 'YEAR'],
            'allocated'      => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'carried_over'   => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'used'           => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'remaining'      => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'expires_at'     => ['type' => 'DATE', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['employee_id', 'leave_type_id', 'period_year']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('leave_type_id', 'leave_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('leave_balances');

        // ─── LEAVES (requests) ────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'leave_type_id'  => ['type' => 'INT', 'unsigned' => true],
            'request_number' => ['type' => 'VARCHAR', 'constraint' => 32],
            'start_date'     => ['type' => 'DATE'],
            'end_date'       => ['type' => 'DATE'],
            'days'           => ['type' => 'DECIMAL', 'constraint' => '6,2'],
            'is_half_day'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'half_day_part'  => ['type' => 'ENUM', 'constraint' => ['MORNING','AFTERNOON'], 'null' => true],
            'reason'         => ['type' => 'TEXT'],
            'attachment'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'contact_during_leave' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'handover_to'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED','CANCELLED'], 'default' => 'PENDING'],
            'approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at'    => ['type' => 'DATETIME', 'null' => true],
            'rejection_note' => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('request_number');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addKey('status');
        $this->forge->addKey(['start_date', 'end_date']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('leave_type_id', 'leave_types', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('leaves');

        // ─── OVERTIMES ────────────────────────────────────────────────
        // Mengikuti PP 35/2021: jam pertama 1.5x, jam selanjutnya 2x.
        // Hari libur: 7 jam pertama 2x, jam ke-8 3x, jam ke-9-10 4x.
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'unsigned' => true],
            'employee_id'     => ['type' => 'INT', 'unsigned' => true],
            'request_number'  => ['type' => 'VARCHAR', 'constraint' => 32],
            'work_date'       => ['type' => 'DATE'],
            'start_time'      => ['type' => 'DATETIME'],
            'end_time'        => ['type' => 'DATETIME'],
            'hours'           => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'day_type'        => ['type' => 'ENUM', 'constraint' => ['WEEKDAY','WEEKEND','HOLIDAY'], 'default' => 'WEEKDAY'],
            'reason'          => ['type' => 'TEXT'],
            'computed_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => 'Calculated per PP 35/2021'],
            'status'          => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED','CANCELLED'], 'default' => 'PENDING'],
            'approved_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at'     => ['type' => 'DATETIME', 'null' => true],
            'rejection_note'  => ['type' => 'TEXT', 'null' => true],
            'paid_in_payroll_period_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('request_number');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addKey('status');
        $this->forge->addKey('work_date');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('overtimes');
    }

    public function down(): void
    {
        $this->forge->dropTable('overtimes', true);
        $this->forge->dropTable('leaves', true);
        $this->forge->dropTable('leave_balances', true);
        $this->forge->dropTable('leave_types', true);
    }
}