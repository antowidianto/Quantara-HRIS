<?php

namespace App\Libraries\Recruitment;

final class HireApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'HIRE_APPROVAL_CONFLICT',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
