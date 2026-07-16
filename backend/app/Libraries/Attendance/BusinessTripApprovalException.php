<?php

namespace App\Libraries\Attendance;

final class BusinessTripApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_TRIP_APPROVAL_ERROR',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
