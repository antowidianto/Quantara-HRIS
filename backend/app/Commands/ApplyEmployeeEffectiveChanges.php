<?php

namespace App\Commands;

use App\Libraries\Employee\EmployeeEffectiveHistoryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class ApplyEmployeeEffectiveChanges extends BaseCommand
{
    protected $group = 'HR';
    protected $name = 'hr:apply-effective-changes';
    protected $description = 'Apply approved employee master changes that have reached valid_from.';
    protected $usage = 'hr:apply-effective-changes [--company=N] [--through=YYYY-MM-DD]';
    protected $options = [
        '--company' => 'Limit to one company id.',
        '--through' => 'Apply through this date (default today).',
    ];

    public function run(array $params)
    {
        $companyId = (int) ($params['company'] ?? CLI::getOption('company') ?? 0);
        $through = (string) ($params['through'] ?? CLI::getOption('through') ?? date('Y-m-d'));
        try {
            $result = (new EmployeeEffectiveHistoryService())->applyDue($companyId > 0 ? $companyId : null, $through);
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
        CLI::write(sprintf('processed=%d succeeded=%d failed=%d', $result['processed'], $result['succeeded'], $result['failed']));
        foreach ($result['errors'] as $error) CLI::error(sprintf('#%d %s', $error['id'], $error['message']));
        return $result['failed'] > 0 ? 1 : 0;
    }
}
