<?php

namespace App\Libraries\Queue;

interface JobInterface
{
    /**
     * Execute the job logic.
     * 
     * @return void
     * @throws \Exception
     */
    public function handle(): void;

    /**
     * Get the number of maximum attempts allowed for this job.
     *
     * @return int
     */
    public function getTries(): int;

    /**
     * Set the data passed to the job.
     *
     * @param array $data
     * @return void
     */
    public function setData(array $data): void;
}