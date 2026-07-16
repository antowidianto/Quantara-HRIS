<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenPerformanceGoalApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('goals')) return;

        $fields = [];
        foreach ([
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'approval_rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approval_rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'approval_rejection_reason' => ['type' => 'TEXT', 'null' => true],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, 'goals')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('goals', $fields);

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE goals MODIFY status ENUM('DRAFT','PENDING_APPROVAL','APPROVAL_REJECTED','APPROVED','IN_PROGRESS','ACHIEVED','PARTIAL','MISSED','CANCELLED') NOT NULL DEFAULT 'DRAFT'");
            return;
        }
        $this->forge->modifyColumn('goals', [
            'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
        ]);
    }

    public function down(): void
    {
        // Approval history/status intentionally retained to avoid data loss.
    }
}
