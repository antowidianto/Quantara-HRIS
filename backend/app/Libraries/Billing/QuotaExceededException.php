<?php

namespace App\Libraries\Billing;

/**
 * S1.4 — Throw saat kuota meter terlampaui (mis. tambah employee #51 di
 * plan max 50). Caller controller catch ini → return 422 dengan code
 * `QUOTA_EXCEEDED`.
 *
 * Pakai HTTP 422 (bukan 402 Payment Required) supaya konsisten dengan
 * pola validasi lain. Frontend bisa tampilkan modal "Limit reached → upgrade".
 */
class QuotaExceededException extends \RuntimeException
{
    public string $meterKey;
    public int $currentValue;
    public int $limitValue;
    public int $requested;

    public function __construct(string $meterKey, int $currentValue, int $limitValue, int $requested = 1, string $message = '')
    {
        $this->meterKey     = $meterKey;
        $this->currentValue = $currentValue;
        $this->limitValue   = $limitValue;
        $this->requested    = $requested;

        if ($message === '') {
            $message = sprintf(
                'Quota exceeded for %s: current=%d + requested=%d > limit=%d',
                $meterKey, $currentValue, $requested, $limitValue
            );
        }
        parent::__construct($message);
    }

    /**
     * Format ke array untuk JSON response.
     */
    public function toArray(): array
    {
        return [
            'meter_key'     => $this->meterKey,
            'current_value' => $this->currentValue,
            'limit_value'   => $this->limitValue,
            'requested'     => $this->requested,
            'remaining'     => max(0, $this->limitValue - $this->currentValue),
            'message'       => $this->getMessage(),
        ];
    }
}