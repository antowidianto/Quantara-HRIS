<?php

namespace App\Libraries\Asset;

final class AssetApprovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ASSET_APPROVAL_CONFLICT',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
