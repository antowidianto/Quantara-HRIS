<?php

declare(strict_types=1);

namespace App\Libraries\Leave;

final class LeaveWorkflowException extends \DomainException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
