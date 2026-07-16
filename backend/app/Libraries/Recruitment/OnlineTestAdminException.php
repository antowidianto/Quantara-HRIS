<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

final class OnlineTestAdminException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
