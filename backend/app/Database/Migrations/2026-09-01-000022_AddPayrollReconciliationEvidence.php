<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPayrollReconciliationEvidence extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_periods')) return;
        $fields = $db->getFieldNames('payroll_periods');
        $columns = [];
        if (! in_array('reconciliation_json', $fields, true)) {
            $columns['reconciliation_json'] = ['type' => 'TEXT', 'null' => true];
        }
        if (! in_array('reconciliation_checked_at', $fields, true)) {
            $columns['reconciliation_checked_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if ($columns !== []) \Config\Database::forge()->addColumn('payroll_periods', $columns);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_periods')) return;
        foreach (['reconciliation_checked_at', 'reconciliation_json'] as $column) {
            if (in_array($column, $db->getFieldNames('payroll_periods'), true)) {
                \Config\Database::forge()->dropColumn('payroll_periods', $column);
            }
        }
    }
}
