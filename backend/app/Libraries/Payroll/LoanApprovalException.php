<?php

namespace App\Libraries\Payroll;

final class LoanApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'LOAN_APPROVAL_ERROR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
