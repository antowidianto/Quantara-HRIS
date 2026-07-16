<?php

namespace App\Libraries\Approval;

/**
 * Canonical identifiers accepted by the generic approval engine.
 *
 * Keep the entity table mapping closed. `entity_table` is persisted and later
 * used by operational tooling, therefore accepting an arbitrary client value
 * would turn approval metadata into an unsafe indirect-object reference.
 */
final class ApprovalModuleCatalog
{
    /** @var array<string,string> */
    private const ENTITY_TABLES = [
        'LEAVE'             => 'leaves',
        'OVERTIME'          => 'overtimes',
        'ATTENDANCE_CHANGE' => 'attendance_change_requests',
        'REIMBURSEMENT'     => 'reimbursements',
        'LOAN'              => 'loans',
        'BUSINESS_TRIP'     => 'business_trips',
        'BONUS'             => 'bonus_runs',
        'PAYROLL_ADJUSTMENT'=> 'payroll_adjustments',
        'COMPENSATION_REVIEW'=> 'compensation_review_proposals',
        'TRAINING_REGISTRATION'=> 'training_registrations',
        'TIMESHEET'         => 'timesheet_entries',
        'CANDIDATE_OFFER'    => 'candidate_offers',
        'GENERATED_CONTRACT' => 'generated_contracts',
        'ASSET_TRANSACTION'   => 'assets',
        'GENERIC'           => '',
    ];

    /** @return list<string> */
    public static function modules(): array
    {
        return array_keys(self::ENTITY_TABLES);
    }

    public static function isSupported(string $module): bool
    {
        return array_key_exists(strtoupper(trim($module)), self::ENTITY_TABLES);
    }

    public static function assertEntityTable(string $module, string $entityTable): void
    {
        $module = strtoupper(trim($module));
        if (! self::isSupported($module)) {
            throw new \InvalidArgumentException("Modul approval tidak didukung: {$module}");
        }

        $expected = self::ENTITY_TABLES[$module];
        if ($module === 'GENERIC') {
            throw new \InvalidArgumentException(
                'Workflow GENERIC membutuhkan adapter entitas terdaftar dan tidak dapat dimulai langsung'
            );
        }

        if ($entityTable !== $expected) {
            throw new \InvalidArgumentException(
                "entity_table {$entityTable} tidak sesuai untuk modul {$module}"
            );
        }
    }
}
