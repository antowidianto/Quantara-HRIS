<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEmployeeSensitiveChangeApprovals extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('employee_sensitive_change_requests')) return;
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'employee_id' => ['type' => 'INT', 'unsigned' => true],
            'request_number' => ['type' => 'VARCHAR', 'constraint' => 64],
            'status' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'PENDING_APPROVAL'],
            'changed_fields' => ['type' => 'TEXT'],
            'payload_encrypted' => ['type' => 'TEXT'],
            'payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'employee_snapshot_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'submitted_by' => ['type' => 'INT', 'unsigned' => true],
            'submitted_at' => ['type' => 'DATETIME'],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'request_number']);
        $this->forge->addKey(['company_id', 'employee_id', 'status']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_sensitive_change_requests');
    }

    public function down(): void
    {
        // Retain PII change audit evidence on rollback.
    }
}
