<?php

namespace App\Libraries\Compensation;

final class CompensationReviewApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'APPROVAL_INVALID_STATE',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
