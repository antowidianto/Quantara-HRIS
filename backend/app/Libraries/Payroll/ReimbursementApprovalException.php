<?php

namespace App\Libraries\Payroll;

final class ReimbursementApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'REIMBURSEMENT_APPROVAL_ERROR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
