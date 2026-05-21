<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Attendance change requests — richer than `attendance_corrections`.
 *
 * Karyawan dapat mengajukan perubahan terhadap baris absensi pada tanggal
 * tertentu (atau tanggal yang belum punya baris). Approver (HR/Manager)
 * meninjau dan menyetujui/menolak. Saat disetujui, perubahan diterapkan
 * ke tabel `attendances`.
 */
class CreateAttendanceChangeRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'unsigned' => true],
            'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'snapshot of employee branch at request time'],
            'employee_id'     => ['type' => 'INT', 'unsigned' => true],
            'attendance_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'comment' => 'NULL kalau tanggal belum punya baris absensi'],
            'work_date'       => ['type' => 'DATE'],
            'request_number'  => ['type' => 'VARCHAR', 'constraint' => 32],
            'change_type'     => ['type' => 'ENUM', 'constraint' => ['CLOCK_IN','CLOCK_OUT','BOTH','STATUS','MISSED'], 'default' => 'BOTH'],

            // Snapshot nilai saat ini (untuk audit visual)
            'current_clock_in'  => ['type' => 'DATETIME', 'null' => true],
            'current_clock_out' => ['type' => 'DATETIME', 'null' => true],
            'current_status'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],

            // Nilai yang diajukan
            'requested_clock_in'  => ['type' => 'DATETIME', 'null' => true],
            'requested_clock_out' => ['type' => 'DATETIME', 'null' => true],
            'requested_status'    => ['type' => 'ENUM', 'constraint' => ['PRESENT','LATE','ABSENT','LEAVE','HOLIDAY','SICK','HALF_DAY'], 'null' => true],

            'reason'          => ['type' => 'TEXT'],
            'attachment_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

            'status'          => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED','CANCELLED'], 'default' => 'PENDING'],
            'reviewer_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'user yg menyetujui/menolak'],
            'reviewed_at'     => ['type' => 'DATETIME', 'null' => true],
            'review_note'     => ['type' => 'TEXT', 'null' => true],

            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('request_number');
        $this->forge->addKey(['company_id', 'status']);
        $this->forge->addKey(['employee_id', 'work_date']);
        $this->forge->addKey('branch_id');
        $this->forge->addKey('attendance_id');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('attendance_id', 'attendances', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('attendance_change_requests');
    }

    public function down(): void
    {
        $this->forge->dropTable('attendance_change_requests', true);
    }
}