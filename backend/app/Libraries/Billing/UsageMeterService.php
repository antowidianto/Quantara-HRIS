<?php

namespace App\Libraries\Billing;

use Config\Database;

/**
 * S1.4 — Usage Meter Service.
 *
 * Tanggung jawab:
 *   1. `increment($companyId, $key, $delta, $context)` — tambah counter
 *      ke usage_meters + log ke usage_events.
 *   2. `getUsage($companyId, $key)` — baca current_value + limit_value.
 *   3. `getAllUsage($companyId)` — list semua meter untuk dashboard.
 *   4. `resetMonthly()` — dipanggil cron 1st of month untuk meter dengan
 *      reset_period = MONTHLY (mis. whatsapp_sent, api_calls).
 *   5. `recomputeFromSource()` — utility untuk fix drift (mis. setelah
 *      tenant import 100 employees via xlsx — counter perlu re-sync).
 *
 * Pure helpers (`*Pure()`) testable tanpa DB.
 *
 * Meter convention:
 *   - 'employees'           — count active employees, lifetime (reset_period=NEVER)
 *   - 'storage_mb'          — total uploaded files (NEVER)
 *   - 'payslips_generated'  — payslips finalized lifetime (NEVER)
 *   - 'whatsapp_sent'       — pesan WA per bulan (MONTHLY)
 *   - 'api_calls'           — request /api/v1/* per bulan (MONTHLY)
 *   - 'data_exports'        — request export per bulan (MONTHLY)
 */
class UsageMeterService
{
    /**
     * Whitelist meter keys + reset_period + sumber recompute (kalau ada).
     *
     * Format: ['key' => ['period' => 'NEVER|MONTHLY', 'recompute_table' => string|null]]
     */
    public const METER_DEFS = [
        'employees' => [
            'period'          => 'NEVER',
            'recompute_table' => 'employees',
            'recompute_where' => ['deleted_at IS NULL', 'employment_status' => 'ACTIVE'],
            'limit_feature_key' => 'limit.employees',
        ],
        'storage_mb' => [
            'period'          => 'NEVER',
            'recompute_table' => null, // hitung manual dari file_uploads.size
            'limit_feature_key' => 'limit.storage_mb',
        ],
        'payslips_generated' => [
            'period'          => 'NEVER',
            'recompute_table' => 'payslips',
            'recompute_where' => ['status' => 'FINALIZED'],
            'limit_feature_key' => null, // tidak ada limit, hanya tracking
        ],
        'whatsapp_sent' => [
            'period'          => 'MONTHLY',
            'recompute_table' => null,
            'limit_feature_key' => 'limit.whatsapp_per_month',
        ],
        'api_calls' => [
            'period'          => 'MONTHLY',
            'recompute_table' => null,
            'limit_feature_key' => 'limit.api_calls_per_month',
        ],
        'data_exports' => [
            'period'          => 'MONTHLY',
            'recompute_table' => 'data_export_requests',
            'limit_feature_key' => 'limit.data_exports_per_month',
        ],
    ];

    /**
     * Pure helper: hitung period_start dan period_end dari reset_period.
     *
     * - NEVER   → period_start = company.created_at, period_end = NULL
     * - MONTHLY → period_start = 1st of current month, period_end = last day
     */
    public static function computePeriodPure(string $resetPeriod, string $now): array
    {
        $ts = strtotime($now) ?: time();
        if (strtoupper($resetPeriod) === 'MONTHLY') {
            $start = date('Y-m-01 00:00:00', $ts);
            $end   = date('Y-m-t 23:59:59', $ts);
            return ['period_start' => $start, 'period_end' => $end];
        }
        // NEVER
        return ['period_start' => null, 'period_end' => null];
    }

    /**
     * Pure helper: cek apakah penambahan delta akan exceed limit.
     */
    public static function wouldExceedPure(int $current, int $limit, int $delta): bool
    {
        if ($limit < 0) {
            return false; // -1 / negative → unlimited
        }
        return ($current + $delta) > $limit;
    }

    /**
     * Increment meter + log event. Return baris meter setelah update.
     *
     * @throws QuotaExceededException kalau enforce=true dan akan exceed.
     */
    public function increment(
        int $companyId,
        string $meterKey,
        int $delta = 1,
        array $context = [],
        bool $enforce = false,
        ?int $userId = null
    ): array {
        $this->assertKnownMeter($meterKey);
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $meter = $this->ensureMeter($db, $companyId, $meterKey);

        if ($enforce && $meter['limit_value'] !== null) {
            $limit = (int) $meter['limit_value'];
            $current = (int) $meter['current_value'];
            if (self::wouldExceedPure($current, $limit, $delta)) {
                throw new QuotaExceededException($meterKey, $current, $limit, $delta);
            }
        }

        $newValue = max(0, (int) $meter['current_value'] + $delta);
        $db->table('usage_meters')->where('id', $meter['id'])->update([
            'current_value' => $newValue,
            'updated_at'    => $now,
        ]);

        // Log event (best-effort)
        if ($db->tableExists('usage_events')) {
            $db->table('usage_events')->insert([
                'company_id'   => $companyId,
                'user_id'      => $userId,
                'meter_key'    => $meterKey,
                'delta'        => $delta,
                'source'       => (string) ($context['source'] ?? 'api:unknown'),
                'context_json' => json_encode($context),
                'occurred_at'  => $now,
            ]);
        }

        $meter['current_value'] = $newValue;
        return $meter;
    }

    /**
     * Cek quota tanpa increment. Return ['ok' => bool, 'current', 'limit', 'remaining'].
     */
    public function check(int $companyId, string $meterKey, int $required = 1): array
    {
        $this->assertKnownMeter($meterKey);
        $db = Database::connect();
        $meter = $this->ensureMeter($db, $companyId, $meterKey);

        $current = (int) ($meter['current_value'] ?? 0);
        $limit   = $meter['limit_value'] !== null ? (int) $meter['limit_value'] : -1;
        $ok      = $limit < 0 ? true : ! self::wouldExceedPure($current, $limit, $required);

        return [
            'ok'        => $ok,
            'current'   => $current,
            'limit'     => $limit,
            'remaining' => $limit < 0 ? -1 : max(0, $limit - $current),
            'meter_key' => $meterKey,
        ];
    }

    /**
     * Get usage record untuk satu meter (auto-create kalau belum ada).
     */
    public function getUsage(int $companyId, string $meterKey): array
    {
        $this->assertKnownMeter($meterKey);
        $db = Database::connect();
        return $this->ensureMeter($db, $companyId, $meterKey);
    }

    /**
     * Get semua meter untuk satu company (untuk dashboard /billing/usage).
     */
    public function getAllUsage(int $companyId): array
    {
        $db = Database::connect();
        $existing = $db->table('usage_meters')
            ->where('company_id', $companyId)
            ->get()
            ->getResultArray();

        $byKey = [];
        foreach ($existing as $row) {
            $byKey[$row['meter_key']] = $row;
        }

        // Pastikan semua meter ter-defined ada di response (auto-create kalau perlu)
        $out = [];
        foreach (self::METER_DEFS as $key => $def) {
            if (! isset($byKey[$key])) {
                $byKey[$key] = $this->ensureMeter($db, $companyId, $key);
            }
            $row = $byKey[$key];
            $current = (int) ($row['current_value'] ?? 0);
            $limit = $row['limit_value'] !== null ? (int) $row['limit_value'] : -1;
            $out[] = [
                'meter_key'     => $key,
                'reset_period'  => $def['period'],
                'current_value' => $current,
                'limit_value'   => $limit,
                'remaining'     => $limit < 0 ? -1 : max(0, $limit - $current),
                'percent_used'  => ($limit > 0) ? round(($current / $limit) * 100, 2) : 0,
                'period_start'  => $row['period_start'] ?? null,
                'period_end'    => $row['period_end'] ?? null,
                'updated_at'    => $row['updated_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Reset meter dengan reset_period = MONTHLY ke 0 + roll period.
     * Dipanggil cron 1st of month 00:30 (BillingResetMonthlyMeters).
     *
     * Return jumlah meter yang di-reset.
     */
    public function resetMonthly(): int
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $monthlyKeys = [];
        foreach (self::METER_DEFS as $key => $def) {
            if (($def['period'] ?? 'NEVER') === 'MONTHLY') {
                $monthlyKeys[] = $key;
            }
        }
        if (empty($monthlyKeys)) {
            return 0;
        }

        $period = self::computePeriodPure('MONTHLY', $now);

        $count = $db->table('usage_meters')
            ->whereIn('meter_key', $monthlyKeys)
            ->update([
                'current_value' => 0,
                'period_start'  => $period['period_start'],
                'period_end'    => $period['period_end'],
                'last_reset_at' => $now,
                'updated_at'    => $now,
            ]);
        return (int) ($db->affectedRows() ?: $count);
    }

    /**
     * Re-sync counter dari source table (untuk fix drift setelah bulk import).
     * Currently support: employees + payslips_generated + data_exports.
     *
     * Return new value setelah recompute.
     */
    public function recomputeFromSource(int $companyId, string $meterKey): int
    {
        $this->assertKnownMeter($meterKey);
        $def = self::METER_DEFS[$meterKey];
        if (empty($def['recompute_table'])) {
            throw new \RuntimeException("Meter {$meterKey} doesn't support recompute from source");
        }

        $db = Database::connect();
        $table = $def['recompute_table'];
        if (! $db->tableExists($table)) {
            return 0;
        }

        $builder = $db->table($table);

        // Filter company_id (atau via join untuk meter khusus)
        if ($table === 'data_export_requests') {
            $period = self::computePeriodPure('MONTHLY', date('Y-m-d H:i:s'));
            $builder->where('company_id', $companyId);
            if ($period['period_start']) {
                $builder->where('created_at >=', $period['period_start']);
            }
        } else {
            $builder->where('company_id', $companyId);
        }

        // Apply where dari def
        foreach (($def['recompute_where'] ?? []) as $k => $v) {
            if (is_int($k) && is_string($v)) {
                // raw clause
                $builder->where($v, null, false);
            } else {
                $builder->where($k, $v);
            }
        }

        $count = (int) $builder->countAllResults();
        $meter = $this->ensureMeter($db, $companyId, $meterKey);
        $db->table('usage_meters')->where('id', $meter['id'])->update([
            'current_value' => $count,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return $count;
    }

    /**
     * Pastikan meter row ada untuk (company, key). Auto-create + sync limit
     * dari plan kalau belum ada.
     */
    private function ensureMeter($db, int $companyId, string $meterKey): array
    {
        $row = $db->table('usage_meters')
            ->where('company_id', $companyId)
            ->where('meter_key', $meterKey)
            ->get()->getRowArray();

        if ($row) {
            return $row;
        }

        $def = self::METER_DEFS[$meterKey];
        $period = self::computePeriodPure($def['period'] ?? 'NEVER', date('Y-m-d H:i:s'));
        $limit = $this->resolveLimitFromPlan($companyId, $def['limit_feature_key'] ?? null);

        $now = date('Y-m-d H:i:s');
        $db->table('usage_meters')->insert([
            'company_id'    => $companyId,
            'meter_key'     => $meterKey,
            'current_value' => 0,
            'limit_value'   => $limit,
            'period_start'  => $period['period_start'],
            'period_end'    => $period['period_end'],
            'last_reset_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return $db->table('usage_meters')
            ->where('company_id', $companyId)
            ->where('meter_key', $meterKey)
            ->get()->getRowArray();
    }

    /**
     * Resolve limit dari FeatureGateService.listForCompany() — quota_value
     * di feature flag dengan key seperti `limit.employees`.
     *
     * Return null kalau tidak ada limit_feature_key (tracking only) atau
     * fitur tidak ditemukan / tidak punya quota_value.
     */
    private function resolveLimitFromPlan(int $companyId, ?string $featureKey): ?int
    {
        if ($featureKey === null) {
            return null;
        }
        if (! class_exists('\\App\\Libraries\\Billing\\FeatureGateService')) {
            return null;
        }

        try {
            $gate = new \App\Libraries\Billing\FeatureGateService();
            $features = $gate->listForCompany($companyId);
            foreach ($features as $f) {
                if (($f['key'] ?? null) !== $featureKey) {
                    continue;
                }
                if (! ($f['enabled'] ?? false)) {
                    return 0; // disabled feature → quota 0 (efektif blokir)
                }
                $q = $f['quota_value'] ?? null;
                if ($q === null) {
                    return null;
                }
                // -1 / negative → unlimited (tetap return -1)
                return (int) $q;
            }
        } catch (\Throwable $e) {
            log_message('warning', '[UsageMeter] Resolve limit failed: ' . $e->getMessage());
        }
        return null;
    }

    private function assertKnownMeter(string $key): void
    {
        if (! isset(self::METER_DEFS[$key])) {
            throw new \InvalidArgumentException("Unknown meter key: {$key}");
        }
    }
}