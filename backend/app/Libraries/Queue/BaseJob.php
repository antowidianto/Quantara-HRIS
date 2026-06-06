<?php

namespace App\Libraries\Queue;

abstract class BaseJob implements JobInterface
{
    /**
     * The data that is passed to the job.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Maximum number of attempts before job fails.
     *
     * @var int
     */
    public int $tries = 3;

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getTries(): int
    {
        return $this->tries;
    }
}