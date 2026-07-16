<?php

namespace App\Libraries\Recruitment;

final class CandidateOfferApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'OFFER_APPROVAL_INVALID_STATE',
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}
