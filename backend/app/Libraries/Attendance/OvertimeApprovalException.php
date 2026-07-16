<?php

namespace App\Libraries\Attendance;

final class OvertimeApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'OVERTIME_APPROVAL_ERROR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
