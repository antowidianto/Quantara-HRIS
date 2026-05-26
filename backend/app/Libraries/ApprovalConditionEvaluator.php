<?php

namespace App\Libraries;

/**
 * ApprovalConditionEvaluator (Sprint 9 / H1.3)
 *
 * Evaluasi kondisi step approval. Dipakai oleh ApprovalEngine untuk
 * menentukan apakah suatu step di-include atau di-skip berdasarkan
 * konteks request (nominal, level karyawan, departemen, dst).
 *
 * Format kondisi (di kolom approval_chain_steps.condition_type +
 * condition_value_json):
 *
 *   condition_type = 'amount_gt'
 *   condition_value_json = {"value": 5000000}
 *   → step aktif kalau meta.amount > 5000000
 *
 *   condition_type = 'amount_lte'
 *   condition_value_json = {"value": 1000000}
 *   → step aktif kalau meta.amount ≤ 1000000
 *
 *   condition_type = 'employee_level_gte'
 *   condition_value_json = {"value": 5}
 *   → step aktif kalau employee.job_grade_level ≥ 5
 *
 *   condition_type = 'department_in'
 *   condition_value_json = {"in": [1, 2, 3]}
 *   → step aktif kalau employee.department_id ∈ [1, 2, 3]
 *
 *   condition_type = 'days_gt' / 'days_lte'
 *   condition_value_json = {"value": 5}
 *   → khusus modul LEAVE, evaluasi meta.days
 *
 *   condition_type = NULL (atau condition_type belum di-set)
 *   → step selalu aktif (backward compat dengan chain lama yg tidak
 *     pakai condition).
 *
 * Catatan: ada juga field lama `condition_field/op/value` (numeric)
 * yang sudah didukung di ApprovalEngine::stepConditionMet. Evaluator
 * baru ini melengkapi dengan condition_type yang lebih ekspresif untuk
 * department/level checks.
 */
class ApprovalConditionEvaluator
{
    public const TYPE_AMOUNT_GT  = 'amount_gt';
    public const TYPE_AMOUNT_LTE = 'amount_lte';
    public const TYPE_AMOUNT_GTE = 'amount_gte';
    public const TYPE_AMOUNT_LT  = 'amount_lt';
    public const TYPE_DAYS_GT    = 'days_gt';
    public const TYPE_DAYS_LTE   = 'days_lte';
    public const TYPE_LEVEL_GTE  = 'employee_level_gte';
    public const TYPE_LEVEL_LT   = 'employee_level_lt';
    public const TYPE_DEPT_IN    = 'department_in';
    public const TYPE_DEPT_NOT_IN = 'department_not_in';
    public const TYPE_BRANCH_IN  = 'branch_in';

    public const SUPPORTED_TYPES = [
        self::TYPE_AMOUNT_GT,
        self::TYPE_AMOUNT_LTE,
        self::TYPE_AMOUNT_GTE,
        self::TYPE_AMOUNT_LT,
        self::TYPE_DAYS_GT,
        self::TYPE_DAYS_LTE,
        self::TYPE_LEVEL_GTE,
        self::TYPE_LEVEL_LT,
        self::TYPE_DEPT_IN,
        self::TYPE_DEPT_NOT_IN,
        self::TYPE_BRANCH_IN,
    ];

    /**
     * Evaluate kondisi.
     *
     * @param string|null $type   Salah satu konstanta TYPE_*. NULL → true.
     * @param mixed       $config Payload kondisi (decoded JSON atau array).
     * @param array       $context Context dari ApprovalEngine:
     *                              - meta: array (amount, days, dst.)
     *                              - employee: array (department_id, branch_id, job_grade_level)
     *
     * @return bool true kalau step aktif, false kalau di-skip.
     */
    public function evaluate(?string $type, mixed $config, array $context): bool
    {
        // Tidak ada kondisi → step selalu aktif (backward compat).
        if ($type === null || $type === '') return true;
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            // Tipe tidak dikenal — fail-open (jangan block step) tapi log.
            // Guard log_message: pure unit test mungkin tidak load CI helper.
            if (function_exists('log_message')) {
                log_message('warning', "[ApprovalConditionEvaluator] unknown type: {$type}");
            }
            return true;
        }

        $cfg = $this->normalizeConfig($config);

        $meta = (array) ($context['meta'] ?? []);
        $employee = (array) ($context['employee'] ?? []);

        $amount = (float) ($meta['amount'] ?? 0);
        $days   = (float) ($meta['days'] ?? 0);
        $level  = (int) ($employee['job_grade_level'] ?? 0);
        $deptId = (int) ($employee['department_id'] ?? 0);
        $branchId = (int) ($employee['branch_id'] ?? 0);

        $value = $cfg['value'] ?? null;
        $list  = $cfg['in'] ?? [];

        return match ($type) {
            self::TYPE_AMOUNT_GT  => $value !== null && $amount >  (float) $value,
            self::TYPE_AMOUNT_GTE => $value !== null && $amount >= (float) $value,
            self::TYPE_AMOUNT_LT  => $value !== null && $amount <  (float) $value,
            self::TYPE_AMOUNT_LTE => $value !== null && $amount <= (float) $value,
            self::TYPE_DAYS_GT    => $value !== null && $days   >  (float) $value,
            self::TYPE_DAYS_LTE   => $value !== null && $days   <= (float) $value,
            self::TYPE_LEVEL_GTE  => $value !== null && $level  >= (int) $value,
            self::TYPE_LEVEL_LT   => $value !== null && $level  <  (int) $value,
            self::TYPE_DEPT_IN    => is_array($list) && in_array($deptId, array_map('intval', $list), true),
            self::TYPE_DEPT_NOT_IN=> is_array($list) && ! in_array($deptId, array_map('intval', $list), true),
            self::TYPE_BRANCH_IN  => is_array($list) && in_array($branchId, array_map('intval', $list), true),
            default               => true,
        };
    }

    /**
     * Validasi config kondisi sebelum disimpan ke DB.
     * Return null kalau valid, string error kalau tidak.
     */
    public function validateConfig(?string $type, mixed $config): ?string
    {
        if ($type === null || $type === '') return null; // tidak pakai kondisi
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            return "condition_type tidak dikenal: {$type}";
        }
        $cfg = $this->normalizeConfig($config);
        $needsValue = in_array($type, [
            self::TYPE_AMOUNT_GT, self::TYPE_AMOUNT_LTE, self::TYPE_AMOUNT_GTE, self::TYPE_AMOUNT_LT,
            self::TYPE_DAYS_GT, self::TYPE_DAYS_LTE,
            self::TYPE_LEVEL_GTE, self::TYPE_LEVEL_LT,
        ], true);
        $needsList = in_array($type, [
            self::TYPE_DEPT_IN, self::TYPE_DEPT_NOT_IN, self::TYPE_BRANCH_IN,
        ], true);

        if ($needsValue) {
            if (! isset($cfg['value']) || ! is_numeric($cfg['value'])) {
                return "Field `value` (numeric) wajib untuk type {$type}";
            }
        }
        if ($needsList) {
            if (! isset($cfg['in']) || ! is_array($cfg['in']) || empty($cfg['in'])) {
                return "Field `in` (array of ID) wajib untuk type {$type}";
            }
        }
        return null;
    }

    private function normalizeConfig(mixed $config): array
    {
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($config)) return $config;
        return [];
    }
}