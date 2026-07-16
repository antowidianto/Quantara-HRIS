<?php

namespace App\Libraries\Payroll;

final class PayrollAdjustmentApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'APPROVAL_INVALID_STATE',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
