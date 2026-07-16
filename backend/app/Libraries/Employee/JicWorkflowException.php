<?php

declare(strict_types=1);

namespace App\Libraries\Employee;

final class JicWorkflowException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly string $errorCode = 'JIC_WORKFLOW_ERROR',
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
