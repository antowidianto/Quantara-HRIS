<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAttendanceTables extends Migration
{
    public function up(): void
    {
        // ─── SHIFTS ────────────────────────────────────────────────────
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'    => ['type' => 'INT', 'unsigned' => true],
            'code'          => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'start_time'    => ['type' => 'TIME'],
            'end_time'      => ['type' => 'TIME'],
            'break_minutes' => ['type' => 'INT', 'unsigned' => true, 'default' => 60],
            'crosses_midnight' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'late_tolerance_minutes' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'early_leave_tolerance_minutes' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'is_active'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('shifts');

        // ─── SHIFT SCHEDULES (per employee per day) ───────────────────
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'    => ['type' => 'INT', 'unsigned' => true],
            'employee_id'   => ['type' => 'INT', 'unsigned' => true],
            'shift_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'work_date'     => ['type' => 'DATE'],
            'is_off'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'is_holiday'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['employee_id', 'work_date']);
        $this->forge->addKey(['company_id', 'work_date']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('shift_id', 'shifts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('shift_schedules');

        // ─── HOLIDAYS ──────────────────────────────────────────────────
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL = nationwide'],
            'date'       => ['type' => 'DATE'],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'is_national'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'is_religious'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'date']);
        $this->forge->createTable('holidays');

        // ─── ATTENDANCES ───────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'        => ['type' => 'INT', 'unsigned' => true],
            'employee_id'       => ['type' => 'INT', 'unsigned' => true],
            'shift_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'work_date'         => ['type' => 'DATE'],
            'clock_in_at'       => ['type' => 'DATETIME', 'null' => true],
            'clock_in_lat'      => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'clock_in_lng'      => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'clock_in_photo'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'clock_in_address'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'clock_in_notes'    => ['type' => 'TEXT', 'null' => true],
            'clock_out_at'      => ['type' => 'DATETIME', 'null' => true],
            'clock_out_lat'     => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'clock_out_lng'     => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'clock_out_photo'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'clock_out_address' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'clock_out_notes'   => ['type' => 'TEXT', 'null' => true],
            'work_minutes'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'late_minutes'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'early_leave_minutes'=> ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'overtime_minutes'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['PRESENT','LATE','ABSENT','LEAVE','HOLIDAY','SICK','HALF_DAY'], 'default' => 'PRESENT'],
            'is_corrected'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'corrected_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'correction_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['employee_id', 'work_date']);
        $this->forge->addKey(['company_id', 'work_date']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('attendances');

        // ─── ATTENDANCE CORRECTIONS ───────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'attendance_id'  => ['type' => 'BIGINT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'requested_clock_in'  => ['type' => 'DATETIME', 'null' => true],
            'requested_clock_out' => ['type' => 'DATETIME', 'null' => true],
            'reason'         => ['type' => 'TEXT'],
            'attachment'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED'], 'default' => 'PENDING'],
            'approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at'    => ['type' => 'DATETIME', 'null' => true],
            'rejection_note' => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('attendance_id');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('attendance_id', 'attendances', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('attendance_corrections');
    }

    public function down(): void
    {
        $this->forge->dropTable('attendance_corrections', true);
        $this->forge->dropTable('attendances', true);
        $this->forge->dropTable('holidays', true);
        $this->forge->dropTable('shift_schedules', true);
        $this->forge->dropTable('shifts', true);
    }
}