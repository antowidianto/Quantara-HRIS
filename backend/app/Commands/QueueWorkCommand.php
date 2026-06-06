<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\JobModel;
use App\Libraries\Queue\JobInterface;

class QueueWorkCommand extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:work';
    protected $description = 'Start processing jobs on the queue as a daemon';
    protected $usage       = 'queue:work [queue_name]';
    protected $arguments   = [
        'queue_name' => 'The name of the queue to work (default: "default")',
    ];
    
    // Check sleep duration
    protected int $sleep = 3;

    public function run(array $params)
    {
        $queueName = $params[0] ?? 'default';
        $jobModel = new JobModel();
        
        CLI::write("Queue Worker started. Listening on queue: {$queueName}", 'green');
        CLI::write('Press Ctrl+C to stop the worker.', 'yellow');

        // Infinite loop to act as daemon
        while (true) {
            $jobRecord = $jobModel->pop($queueName);

            if ($jobRecord) {
                $this->processJob($jobRecord, $jobModel);
            } else {
                // No jobs available, sleep to prevent high CPU usage
                sleep($this->sleep);
            }
        }
    }

    protected function processJob(array $jobRecord, JobModel $jobModel)
    {
        CLI::write('[' . date('Y-m-d H:i:s') . '] Processing Job ID: ' . $jobRecord['id'], 'cyan');

        $payload = json_decode($jobRecord['payload'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jobModel->failJob($jobRecord['id'], new \Exception("Invalid JSON payload."));
            CLI::write('Failed to parse payload for Job ID: ' . $jobRecord['id'], 'red');
            return;
        }

        $jobClass = $payload['job'];
        $data = $payload['data'] ?? [];

        if (! class_exists($jobClass)) {
            $jobModel->failJob($jobRecord['id'], new \Exception("Job class {$jobClass} not found."));
            CLI::write("Class {$jobClass} not found for Job ID: " . $jobRecord['id'], 'red');
            return;
        }

        try {
            /** @var JobInterface $jobInstance */
            $jobInstance = new $jobClass();
            $jobInstance->setData($data);

            // Execute the job
            $jobInstance->handle();

            // Job successful, remove from queue
            $jobModel->delete($jobRecord['id']);
            
            CLI::write('[' . date('Y-m-d H:i:s') . '] Processed Job ID: ' . $jobRecord['id'] . ' successfully.', 'green');
        } catch (\Throwable $e) {
            // Handle failure
            $tries = isset($jobInstance) ? $jobInstance->getTries() : 3;
            
            CLI::write('[' . date('Y-m-d H:i:s') . '] Job ID: ' . $jobRecord['id'] . ' failed. Attempt: ' . $jobRecord['attempts'] . '/' . $tries, 'yellow');
            CLI::error($e->getMessage());

            if ($jobRecord['attempts'] >= $tries) {
                $jobModel->failJob($jobRecord['id'], $e);
                CLI::write('[' . date('Y-m-d H:i:s') . '] Job ID: ' . $jobRecord['id'] . ' has exceeded max attempts and moved to failed_jobs.', 'red');
            } else {
                // Release the job back to the queue (un-reserve it so it can be retried)
                $jobModel->update($jobRecord['id'], [
                    'reserved_at' => null,
                ]);
            }
        }
    }
}