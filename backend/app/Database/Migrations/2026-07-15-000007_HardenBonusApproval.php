<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Make bonus approval an explicit, auditable workflow state. */
final class HardenBonusApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('bonus_runs')) return;

        if ($this->db->fieldExists('status', 'bonus_runs')) {
            $this->forge->modifyColumn('bonus_runs', [
                'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
            ]);
        }

        $fields = [];
        $definitions = [
            'submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ];
        foreach ($definitions as $name => $definition) {
            if (! $this->db->fieldExists($name, 'bonus_runs')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('bonus_runs', $fields);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('bonus_runs')) return;
        foreach (['submitted_by', 'submitted_at', 'rejected_by', 'rejected_at', 'rejection_reason'] as $column) {
            if ($this->db->fieldExists($column, 'bonus_runs')) $this->forge->dropColumn('bonus_runs', $column);
        }
        // VARCHAR is retained so rollback cannot truncate PENDING_APPROVAL or
        // REJECTED rows that may already exist.
    }
}
