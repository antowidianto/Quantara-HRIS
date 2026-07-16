<?php

namespace App\Libraries\Attendance;

final class AttendanceChangeApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ATTENDANCE_CHANGE_APPROVAL_ERROR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
