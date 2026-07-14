<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Makes automatically generated corrections traceable and idempotent. */
class AddPayrollAdjustmentSource extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_adjustments')) {
            return;
        }
        $fields = $db->getFieldNames('payroll_adjustments');
        $columns = [];
        if (! in_array('source_type', $fields, true)) {
            $columns['source_type'] = ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'target_period_id'];
        }
        if (! in_array('source_id', $fields, true)) {
            $columns['source_id'] = ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'source_type'];
        }
        if ($columns !== []) {
            \Config\Database::forge()->addColumn('payroll_adjustments', $columns);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_adjustments')) {
            return;
        }
        foreach (['source_id', 'source_type'] as $column) {
            if (in_array($column, $db->getFieldNames('payroll_adjustments'), true)) {
                \Config\Database::forge()->dropColumn('payroll_adjustments', $column);
            }
        }
    }
}
