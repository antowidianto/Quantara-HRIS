<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenIdpActivationApproval extends Migration
{
    public function up(): void
    {
        $table = 'individual_development_plans';
        if (! $this->db->tableExists($table)) return;
        $fields = [];
        foreach ([
            'activation_submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'activation_submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'activation_approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'activation_approved_at' => ['type' => 'DATETIME', 'null' => true],
            'activation_rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'activation_rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'activation_rejection_reason' => ['type' => 'TEXT', 'null' => true],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, $table)) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn($table, $fields);
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE individual_development_plans MODIFY status ENUM('DRAFT','PENDING_APPROVAL','APPROVAL_REJECTED','ACTIVE','COMPLETED','REVIEWED','CANCELLED') NOT NULL DEFAULT 'DRAFT'");
            return;
        }
        $this->forge->modifyColumn($table, [
            'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
        ]);
    }

    public function down(): void
    {
        // Retain approval history to avoid destructive rollback.
    }
}
