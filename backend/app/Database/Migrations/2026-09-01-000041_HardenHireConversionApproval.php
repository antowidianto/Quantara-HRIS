<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenHireConversionApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('candidates')) return;
        $fields = [];
        $definitions = [
            'hire_approval_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'hire_payload_encrypted' => ['type' => 'TEXT', 'null' => true],
            'hire_payload_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'hire_submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'hire_submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'hire_approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'hire_approved_at' => ['type' => 'DATETIME', 'null' => true],
            'hire_rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'hire_rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'hire_rejection_reason' => ['type' => 'TEXT', 'null' => true],
        ];
        foreach ($definitions as $name => $definition) {
            if (! $this->db->fieldExists($name, 'candidates')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('candidates', $fields);
    }

    public function down(): void
    {
        // Encrypted request and approval evidence are intentionally retained.
    }
}
