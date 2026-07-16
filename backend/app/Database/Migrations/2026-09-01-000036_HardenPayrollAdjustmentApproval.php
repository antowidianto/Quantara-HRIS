<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenPayrollAdjustmentApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('payroll_adjustments')) return;
        $this->forge->modifyColumn('payroll_adjustments', [
            'status' => ['name' => 'status', 'type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DRAFT'],
        ]);
        $fields = [];
        foreach ([
            'submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'cancelled_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'cancelled_at' => ['type' => 'DATETIME', 'null' => true],
            'cancellation_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, 'payroll_adjustments')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('payroll_adjustments', $fields);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('payroll_adjustments')) return;
        foreach (['submitted_by', 'submitted_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason'] as $column) {
            if ($this->db->fieldExists($column, 'payroll_adjustments')) $this->forge->dropColumn('payroll_adjustments', $column);
        }
        // Keep VARCHAR to preserve workflow states already persisted.
    }
}
