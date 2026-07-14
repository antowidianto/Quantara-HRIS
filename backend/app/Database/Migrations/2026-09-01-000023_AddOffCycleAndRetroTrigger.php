<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOffCycleAndRetroTrigger extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        if ($db->tableExists('payroll_adjustments')
            && ! in_array('trigger_type', $db->getFieldNames('payroll_adjustments'), true)) {
            \Config\Database::forge()->addColumn('payroll_adjustments', [
                'trigger_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'MANUAL', 'after' => 'source_id'],
            ]);
        }
        if ($db->tableExists('payroll_periods') && $db->DBDriver !== 'SQLite3') {
            $db->query("ALTER TABLE `payroll_periods` MODIFY `type` "
                . "ENUM('MONTHLY','BI_WEEKLY','WEEKLY','THR','BONUS','OFF_CYCLE') NOT NULL DEFAULT 'MONTHLY'");
        }
    }

    public function down(): void
    {
        // Roll-forward only: narrowing an enum can invalidate off-cycle periods.
    }
}
