<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

class ProcessEmailQueue extends BaseCommand
{
    protected $group       = 'HRIS';
    protected $name        = 'email:process';
    protected $description = 'Process the email queue and send pending messages';

    public function run(array $params)
    {
        $batch = (int) ($params['batch'] ?? CLI::getOption('batch') ?? 20);
        $sent = Services::notification()->processEmailQueue($batch);
        CLI::write("Sent {$sent} email(s)", 'green');
    }
}