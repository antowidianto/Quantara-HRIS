<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add auditable maker-checker actors and an immutable payroll evidence snapshot. */
class AddPayrollFourEyesAndSnapshot extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_periods')) {
            return;
        }

        $columns = [
            'prepared_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'prepared_at' => ['type' => 'DATETIME', 'null' => true],
            'submitted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'snapshot_json' => ['type' => 'LONGTEXT', 'null' => true],
            'snapshot_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'snapshot_created_at' => ['type' => 'DATETIME', 'null' => true],
        ];

        $add = [];
        foreach ($columns as $name => $definition) {
            if (! $db->fieldExists($name, 'payroll_periods')) {
                $add[$name] = $definition;
            }
        }
        if ($add !== []) {
            \Config\Database::forge()->addColumn('payroll_periods', $add);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('payroll_periods')) {
            return;
        }
        $forge = \Config\Database::forge();
        foreach ([
            'prepared_by', 'prepared_at', 'submitted_by', 'submitted_at',
            'approved_by', 'approved_at', 'snapshot_json', 'snapshot_hash',
            'snapshot_created_at',
        ] as $column) {
            if ($db->fieldExists($column, 'payroll_periods')) {
                $forge->dropColumn('payroll_periods', $column);
            }
        }
    }
}
