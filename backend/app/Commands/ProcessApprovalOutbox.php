<?php

namespace App\Commands;

use App\Libraries\Approval\ApprovalOutcomeOutbox;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class ProcessApprovalOutbox extends BaseCommand
{
    protected $group = 'HRIS';
    protected $name = 'approval-outbox:process';
    protected $description = 'Publish committed approval outcome notifications and webhooks';
    protected $usage = 'approval-outbox:process [--batch=N]';
    protected $options = ['--batch' => 'Maximum events per run (default 50)'];

    public function run(array $params)
    {
        $batch = max(1, (int) (CLI::getOption('batch') ?? 50));
        $result = (new ApprovalOutcomeOutbox())->processBatch($batch);
        CLI::write(sprintf(
            'Approval outbox: published=%d failed=%d skipped=%d',
            $result['published'], $result['failed'], $result['skipped']
        ), $result['failed'] > 0 ? 'yellow' : 'green');
        return $result['failed'] > 0 ? 1 : 0;
    }
}
