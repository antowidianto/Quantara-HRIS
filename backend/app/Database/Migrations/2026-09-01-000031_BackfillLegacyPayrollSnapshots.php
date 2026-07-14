<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\Payroll\PayrollSnapshotService;
use CodeIgniter\Database\Migration;

/** Baseline payrolls locked before immutable snapshot support was deployed. */
final class BackfillLegacyPayrollSnapshots extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('payroll_periods')
            || ! $this->db->fieldExists('snapshot_json', 'payroll_periods')
            || ! $this->db->fieldExists('snapshot_hash', 'payroll_periods')
        ) {
            return;
        }

        $service = new PayrollSnapshotService($this->db);
        $result = $service->backfillMissing();
        if ($result['failed'] > 0) {
            throw new \RuntimeException(sprintf(
                'Failed to backfill %d legacy payroll snapshot(s): %s',
                $result['failed'],
                implode('; ', $result['errors']),
            ));
        }
        $verification = $service->verifyLocked();
        if ($verification['invalid'] > 0) {
            throw new \RuntimeException(sprintf(
                'Detected %d invalid locked payroll snapshot(s): %s',
                $verification['invalid'],
                implode('; ', $verification['errors']),
            ));
        }
    }

    public function down(): void
    {
        // Snapshots are audit evidence and must not be erased on rollback.
    }
}
