<?php

namespace App\Libraries\Billing;

use RuntimeException;

/**
 * Thrown saat fitur tidak include di plan tenant atau di-disable
 * via override. Controller layer akan map ke HTTP 403 dengan code
 * `FEATURE_NOT_AVAILABLE`.
 */
class FeatureNotAvailableException extends RuntimeException
{
    public function __construct(public readonly string $featureKey, ?string $message = null)
    {
        parent::__construct(
            $message ?? "Fitur '{$featureKey}' tidak tersedia di plan Anda. Silakan upgrade.",
        );
    }
}