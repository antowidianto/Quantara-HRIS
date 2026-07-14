<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Keep the schema aligned with calculate → approve → finalize → pay. */
final class NormalizePayrollPeriodLifecycleStatus extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('payroll_periods') || $this->db->DBDriver === 'SQLite3') {
            return;
        }
        $this->db->query(
            "ALTER TABLE `payroll_periods` MODIFY `status` "
            . "ENUM('DRAFT','CALCULATED','APPROVED','FINALIZED','PAID','CLOSED') "
            . "NOT NULL DEFAULT 'DRAFT'"
        );
    }

    public function down(): void
    {
        // Roll-forward only: narrowing the enum could invalidate finalized rows.
    }
}
