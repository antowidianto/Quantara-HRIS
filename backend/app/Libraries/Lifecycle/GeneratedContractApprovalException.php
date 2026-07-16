<?php

namespace App\Libraries\Lifecycle;

final class GeneratedContractApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'CONTRACT_APPROVAL_CONFLICT',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
