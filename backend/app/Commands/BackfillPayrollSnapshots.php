<?php

namespace App\Commands;

use App\Libraries\Payroll\PayrollSnapshotService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class BackfillPayrollSnapshots extends BaseCommand
{
    protected $group = 'Payroll';
    protected $name = 'payroll:snapshot-backfill';
    protected $description = 'Create immutable baseline snapshots for legacy locked payroll periods.';
    protected $usage = 'payroll:snapshot-backfill [--company=N]';
    protected $options = [
        '--company' => 'Limit the backfill to one company id.',
    ];

    public function run(array $params)
    {
        $companyId = (int) ($params['company'] ?? CLI::getOption('company') ?? 0);
        $service = new PayrollSnapshotService();
        $scope = $companyId > 0 ? $companyId : null;
        $result = $service->backfillMissing($scope);
        $verification = $service->verifyLocked($scope);

        CLI::write(sprintf(
            'scanned=%d backfilled=%d failed=%d',
            $result['scanned'],
            $result['backfilled'],
            $result['failed'],
        ));
        foreach ($result['errors'] as $periodId => $message) {
            CLI::error(sprintf('period #%d: %s', $periodId, $message));
        }
        CLI::write(sprintf(
            'verified=%d valid=%d invalid=%d',
            $verification['checked'],
            $verification['valid'],
            $verification['invalid'],
        ));
        foreach ($verification['errors'] as $periodId => $message) {
            CLI::error(sprintf('invalid period #%d: %s', $periodId, $message));
        }

        return $result['failed'] > 0 || $verification['invalid'] > 0 ? 1 : 0;
    }
}
