<?php

namespace App\Libraries\Payroll;

final class RetroTriggerPolicy
{
    public const TRIGGERS = [
        'SALARY', 'ALLOWANCE', 'ATTENDANCE', 'TAX_STATUS',
        'BPJS', 'TRANSFER', 'CONTRACT', 'MANUAL',
    ];

    public static function normalize(?string $trigger): string
    {
        $trigger = strtoupper(trim((string) $trigger));
        if ($trigger === '') return 'MANUAL';
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException('Unsupported retro payroll trigger');
        }
        return $trigger;
    }
}
