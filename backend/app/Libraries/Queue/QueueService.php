<?php

namespace App\Libraries\Queue;

use App\Models\JobModel;

class QueueService
{
    protected JobModel $jobModel;

    public function __construct()
    {
        $this->jobModel = new JobModel();
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string $jobClass The fully qualified class name of the job
     * @param array $data The data to pass to the job
     * @param string $queue The queue name to push the job to
     * @param int $delay Delay in seconds
     * @return int|string The ID of the inserted job
     */
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0)
    {
        $payload = json_encode([
            'job'  => $jobClass,
            'data' => $data
        ]);

        $now = time();
        $availableAt = $now + $delay;

        return $this->jobModel->insert([
            'queue'        => $queue,
            'payload'      => $payload,
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => $availableAt,
            'created_at'   => $now,
        ]);
    }
}