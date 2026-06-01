<?php

namespace App\Libraries\Payroll;

/**
 * BonusCalculator — pure compute helpers untuk Bonus Engine (Sprint 16+).
 *
 * Pisahkan logic perhitungan dari DB access supaya:
 *   - Mudah unit-test tanpa DB stub
 *   - Reusable di service layer (BonusService::calculateRun) + di
 *     preview endpoint (admin coba-coba formula sebelum commit)
 *
 * Formula types yang didukung:
 *   FIXED_AMOUNT   → return formula_value (cap-aware)
 *   PCT_OF_BASIC   → basic_salary × (formula_value / 100)
 *   PCT_OF_GROSS   → gross_salary × (formula_value / 100); fallback basic
 *                    kalau gross tidak diketahui (mis. karyawan baru)
 *   TENURE_BASED   → tenure_years × formula_value (mis. 100k per tahun)
 *   KPI_SCORE      → basic × (formula_value / 100) × kpi_score (0..1)
 *   FORMULA_EXPR   → simple expression evaluator dengan variable:
 *                    basic, gross, tenure_months, tenure_years, kpi_score
 *                    Operator: + - * / ( ) literal angka.
 *                    No function call, no string, no comparison — pure math.
 *
 * Eligibility check:
 *   - Filter berdasarkan department_ids, branch_ids, grade_ids,
 *     position_ids, employment_status whitelist
 *   - min_tenure_months gate
 *   - exclude_resigned: skip karyawan yang resign_date <= reference_date
 *
 * Pure class — tidak ada I/O.
 */
class BonusCalculator
{
    public const FORMULA_TYPES = [
        'FIXED_AMOUNT',
        'PCT_OF_BASIC',
        'PCT_OF_GROSS',
        'TENURE_BASED',
        'KPI_SCORE',
        'FORMULA_EXPR',
    ];

    /**
     * Hitung nominal bonus untuk satu karyawan, return array breakdown.
     *
     * @param array $scheme  Row dari `bonus_schemes`
     * @param array $emp     Snapshot karyawan: id, basic_salary, fixed_allowance,
     *                       join_date, resign_date, kpi_score (opsional 0..1)
     * @param string $referenceDate  YYYY-MM-DD (untuk hitung tenure)
     * @return array{amount:float, capped:bool, breakdown:array}
     */
    public static function compute(array $scheme, array $emp, string $referenceDate): array
    {
        $type   = (string) ($scheme['formula_type'] ?? 'FIXED_AMOUNT');
        $value  = (float) ($scheme['formula_value'] ?? 0);
        $cap    = isset($scheme['cap_amount']) && $scheme['cap_amount'] !== null && $scheme['cap_amount'] !== ''
            ? (float) $scheme['cap_amount']
            : null;

        $basic = (float) ($emp['basic_salary'] ?? 0);
        $allowance = (float) ($emp['fixed_allowance'] ?? 0);
        $gross = $basic + $allowance;
        $tenureMonths = self::tenureMonths(
            $emp['join_date'] ?? null,
            $emp['resign_date'] ?? null,
            $referenceDate,
        );
        $tenureYears = $tenureMonths / 12.0;
        $kpiScore   = isset($emp['kpi_score']) ? max(0.0, min(2.0, (float) $emp['kpi_score'])) : 0.0;

        $amount = 0.0;
        $steps  = [];

        switch ($type) {
            case 'FIXED_AMOUNT':
                $amount = $value;
                $steps[] = "FIXED_AMOUNT = {$value}";
                break;

            case 'PCT_OF_BASIC':
                $amount = $basic * ($value / 100.0);
                $steps[] = "basic ({$basic}) × {$value}% = {$amount}";
                break;

            case 'PCT_OF_GROSS':
                $amount = $gross * ($value / 100.0);
                $steps[] = "gross ({$gross}) × {$value}% = {$amount}";
                break;

            case 'TENURE_BASED':
                $amount = $tenureYears * $value;
                $steps[] = "tenure_years (" . round($tenureYears, 2) . ") × {$value} = {$amount}";
                break;

            case 'KPI_SCORE':
                $amount = $basic * ($value / 100.0) * $kpiScore;
                $steps[] = "basic ({$basic}) × {$value}% × kpi_score ({$kpiScore}) = {$amount}";
                break;

            case 'FORMULA_EXPR':
                $expr = (string) ($scheme['formula_expr'] ?? '');
                $amount = self::evaluateExpression($expr, [
                    'basic'         => $basic,
                    'gross'         => $gross,
                    'tenure_months' => $tenureMonths,
                    'tenure_years'  => $tenureYears,
                    'kpi_score'     => $kpiScore,
                    'value'         => $value,
                ]);
                $steps[] = "expr: {$expr} = {$amount}";
                break;

            default:
                $amount = 0.0;
                $steps[] = "unknown formula_type: {$type}";
        }

        // Negative amount → 0 (defensive: bonus tidak pernah negatif)
        $amount = max(0.0, $amount);

        // Cap
        $capped = false;
        if ($cap !== null && $amount > $cap) {
            $steps[] = "capped at {$cap} (was {$amount})";
            $amount = $cap;
            $capped = true;
        }

        // Round ke integer rupiah (bonus tidak pakai sen)
        $amount = round($amount);

        return [
            'amount'    => $amount,
            'capped'    => $capped,
            'breakdown' => [
                'formula_type'  => $type,
                'formula_value' => $value,
                'cap_amount'    => $cap,
                'inputs'        => [
                    'basic'         => $basic,
                    'gross'         => $gross,
                    'tenure_months' => $tenureMonths,
                    'kpi_score'     => $kpiScore,
                ],
                'steps' => $steps,
            ],
        ];
    }

    /**
     * Cek karyawan eligible terhadap eligibility filter di scheme.
     *
     * @param array $eligibility Decoded JSON dari `bonus_schemes.eligibility_json`
     * @param array $emp         Row employee + tenure_months precomputed
     * @return array{eligible:bool, reason:string}
     */
    public static function checkEligibility(array $eligibility, array $emp, string $referenceDate): array
    {
        // Resigned check
        $excludeResigned = ! empty($eligibility['exclude_resigned']);
        if ($excludeResigned && ! empty($emp['resign_date'])) {
            $resignTs = strtotime((string) $emp['resign_date']);
            $refTs    = strtotime($referenceDate);
            if ($resignTs !== false && $refTs !== false && $resignTs <= $refTs) {
                return ['eligible' => false, 'reason' => 'Sudah resign per ' . $emp['resign_date']];
            }
        }

        // Tenure
        $minMonths = (int) ($eligibility['min_tenure_months'] ?? 0);
        if ($minMonths > 0) {
            $tenure = self::tenureMonths(
                $emp['join_date'] ?? null,
                $emp['resign_date'] ?? null,
                $referenceDate,
            );
            if ($tenure < $minMonths) {
                return [
                    'eligible' => false,
                    'reason'   => "Tenure {$tenure} bulan, dibutuhkan minimum {$minMonths} bulan",
                ];
            }
        }

        // Whitelist filters — kalau array kosong / null, allow all
        $checks = [
            ['key' => 'department_ids', 'field' => 'department_id', 'label' => 'departemen'],
            ['key' => 'branch_ids',     'field' => 'branch_id',     'label' => 'cabang'],
            ['key' => 'grade_ids',      'field' => 'job_grade_id',  'label' => 'grade'],
            ['key' => 'position_ids',   'field' => 'position_id',   'label' => 'posisi'],
        ];
        foreach ($checks as $c) {
            $list = $eligibility[$c['key']] ?? null;
            if (! is_array($list) || empty($list)) continue;
            $val = (int) ($emp[$c['field']] ?? 0);
            $allowed = array_map('intval', $list);
            if (! in_array($val, $allowed, true)) {
                return [
                    'eligible' => false,
                    'reason'   => "Tidak termasuk filter {$c['label']}",
                ];
            }
        }

        // Employment status whitelist
        $statuses = $eligibility['employment_status'] ?? null;
        if (is_array($statuses) && ! empty($statuses)) {
            $empStatus = strtoupper((string) ($emp['employment_status'] ?? ''));
            $allowedStatuses = array_map('strtoupper', $statuses);
            if (! in_array($empStatus, $allowedStatuses, true)) {
                return [
                    'eligible' => false,
                    'reason'   => "Status karyawan {$empStatus} tidak termasuk filter",
                ];
            }
        }

        return ['eligible' => true, 'reason' => ''];
    }

    /**
     * Hitung tenure dalam bulan (1 bulan = 30 hari approximation, sama
     * dengan PayrollController::computeTenureMonths).
     */
    public static function tenureMonths(?string $joinDate, ?string $resignDate, string $referenceDate): int
    {
        if (! $joinDate) return 0;
        $joinTs = strtotime($joinDate);
        $refTs  = strtotime($referenceDate);
        if ($joinTs === false || $refTs === false) return 0;
        if ($joinTs > $refTs) return 0;

        $endTs = $refTs;
        if ($resignDate) {
            $resignTs = strtotime($resignDate);
            if ($resignTs !== false) {
                $endTs = min($refTs, $resignTs);
            }
        }
        if ($endTs < $joinTs) return 0;

        return max(0, (int) floor(($endTs - $joinTs) / 86400 / 30));
    }

    /**
     * Pure simple-expression evaluator. Hanya support:
     *   - Variable lookup: $vars['basic'], dst
     *   - Operator: + - * /  dengan grouping ( )
     *   - Literal angka (integer / float positif)
     *
     * Tidak support: function call, string, comparison, ternary, modulo,
     * assignment. Defensive: kalau parse gagal → return 0 + log via
     * caller (karena pure class, tidak log internal).
     *
     * Implementation: regex-tokenize → shunting-yard → RPN evaluate.
     *
     * @param array<string, float|int> $vars
     */
    public static function evaluateExpression(string $expr, array $vars): float
    {
        $expr = trim($expr);
        if ($expr === '') return 0.0;

        // Substitusi variable → string angka
        // Replace identifier paling panjang dulu supaya `tenure_years` tidak
        // ke-tengkep oleh `tenure` (kalau ada tabrakan nama).
        $names = array_keys($vars);
        usort($names, fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($names as $name) {
            // Word-boundary match (alphanumeric + underscore)
            $pattern = '/\b' . preg_quote($name, '/') . '\b/';
            $expr = preg_replace($pattern, (string) $vars[$name], $expr) ?? $expr;
        }

        // Tokenize
        $tokens = self::tokenize($expr);
        if ($tokens === null) return 0.0;

        // Shunting-yard → RPN
        $rpn = self::shuntingYard($tokens);
        if ($rpn === null) return 0.0;

        // Evaluate RPN
        return self::evalRpn($rpn);
    }

    /** @return array<int, array{type:string, value:string|float}>|null */
    private static function tokenize(string $expr): ?array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (ctype_digit($c) || $c === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                if (! is_numeric($num)) return null;
                $tokens[] = ['type' => 'num', 'value' => (float) $num];
                continue;
            }
            if (in_array($c, ['+', '-', '*', '/'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $c];
                $i++;
                continue;
            }
            if ($c === '(' || $c === ')') {
                $tokens[] = ['type' => $c === '(' ? 'lparen' : 'rparen', 'value' => $c];
                $i++;
                continue;
            }
            // Unknown char — fail
            return null;
        }

        return $tokens;
    }

    /**
     * @param array<int, array{type:string, value:string|float}> $tokens
     * @return array<int, array{type:string, value:string|float}>|null
     */
    private static function shuntingYard(array $tokens): ?array
    {
        $output = [];
        $ops    = [];
        $prec   = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        foreach ($tokens as $t) {
            if ($t['type'] === 'num') {
                $output[] = $t;
            } elseif ($t['type'] === 'op') {
                while (! empty($ops)) {
                    $top = $ops[count($ops) - 1];
                    if ($top['type'] === 'op' && $prec[$top['value']] >= $prec[$t['value']]) {
                        $output[] = array_pop($ops);
                    } else {
                        break;
                    }
                }
                $ops[] = $t;
            } elseif ($t['type'] === 'lparen') {
                $ops[] = $t;
            } elseif ($t['type'] === 'rparen') {
                $matched = false;
                while (! empty($ops)) {
                    $top = array_pop($ops);
                    if ($top['type'] === 'lparen') {
                        $matched = true;
                        break;
                    }
                    $output[] = $top;
                }
                if (! $matched) return null;
            }
        }
        while (! empty($ops)) {
            $top = array_pop($ops);
            if ($top['type'] === 'lparen' || $top['type'] === 'rparen') return null;
            $output[] = $top;
        }

        return $output;
    }

    /**
     * @param array<int, array{type:string, value:string|float}> $rpn
     */
    private static function evalRpn(array $rpn): float
    {
        $stack = [];
        foreach ($rpn as $t) {
            if ($t['type'] === 'num') {
                $stack[] = (float) $t['value'];
            } elseif ($t['type'] === 'op') {
                if (count($stack) < 2) return 0.0;
                $b = array_pop($stack);
                $a = array_pop($stack);
                $stack[] = match ($t['value']) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $b == 0.0 ? 0.0 : $a / $b,
                    default => 0.0,
                };
            }
        }

        return count($stack) === 1 ? (float) $stack[0] : 0.0;
    }

    /**
     * Sprint 17+ — Konversi bonus run breakdown menjadi entry earnings
     * yang siap di-append ke payslip. Pure function, mudah di-unit test.
     *
     * Tax treatment mapping:
     *   - NORMAL / GROSS / null   → taxable=1, code=BONUS, name=Bonus
     *   - NON_TAXABLE             → taxable=0, code=BONUS_NT, name=Bonus (Non-Pajak)
     *   - GROSS_UP / NET          → taxable=1, code=BONUS (Sprint 18+: caller boleh
     *                                 panggil grossUpEntries() dulu untuk hitung
     *                                 actual gross sebelum panggil method ini)
     *
     * Multiple breakdown entries dengan tax_treatment sama akan
     * di-aggregate jadi satu earning entry.
     *
     * @param array{amount?:float|int|string, breakdown?:array<int, array{tax_treatment?:string, amount?:float|int|string}>}|null $bonusInfo
     * @return array<int, array{code:string, name:string, category:string, amount:float, is_taxable:int}>
     */
    public static function bonusEarningEntries(?array $bonusInfo): array
    {
        if (! is_array($bonusInfo) || empty($bonusInfo['amount']) || (float) $bonusInfo['amount'] <= 0) {
            return [];
        }

        $totalAmount = (float) $bonusInfo['amount'];

        // Group amount per tax_treatment
        $byTreatment = [];
        foreach (($bonusInfo['breakdown'] ?? []) as $b) {
            $tt = strtoupper((string) ($b['tax_treatment'] ?? 'NORMAL'));
            // Normalisasi alias — GROSS_UP & NET di Sprint 17+ behave as NORMAL
            // (taxable). Sprint 18+ caller pre-process via grossUpEntries() supaya
            // amount sudah jadi effective gross sebelum dipanggil di sini.
            if ($tt === 'GROSS' || $tt === '' || $tt === 'GROSS_UP' || $tt === 'NET') {
                $tt = 'NORMAL';
            }
            if (! isset($byTreatment[$tt])) $byTreatment[$tt] = 0.0;
            $byTreatment[$tt] += (float) ($b['amount'] ?? 0);
        }
        // Default kalau breakdown tidak ada → asumsi NORMAL (semuanya taxable)
        if (empty($byTreatment)) {
            $byTreatment['NORMAL'] = $totalAmount;
        }

        $entries = [];
        foreach ($byTreatment as $treatment => $amt) {
            if ($amt <= 0) continue;
            $isTaxable = ($treatment !== 'NON_TAXABLE') ? 1 : 0;
            $code = $treatment === 'NON_TAXABLE' ? 'BONUS_NT' : 'BONUS';
            $name = $treatment === 'NON_TAXABLE' ? 'Bonus (Non-Pajak)' : 'Bonus';
            $entries[] = [
                'code'       => $code,
                'name'       => $name,
                'category'   => 'BONUS',
                'amount'     => round($amt, 2),
                'is_taxable' => $isTaxable,
            ];
        }

        return $entries;
    }

    /**
     * Sprint 18+ — Pre-process bonus breakdown untuk apply gross-up
     * iteration di entry yang punya tax_treatment GROSS_UP atau NET.
     *
     * Untuk entry NET / GROSS_UP, `amount` di breakdown di-interpret sebagai
     * net target. Method ini call `iterateGrossUp` dengan `taxFunc` yang
     * di-inject (biasanya TER monthly), lalu rewrite amount jadi gross-up
     * value. Total amount aggregat juga di-sesuaikan.
     *
     * Output bisa langsung dilewat ke `bonusEarningEntries()` untuk konversi
     * ke payslip line items.
     *
     * Tipe lain (NORMAL/GROSS/NON_TAXABLE) dilewat tanpa modifikasi.
     *
     * @param array{amount?:float, breakdown?:array<int, array{tax_treatment?:string, amount?:float|int|string}>}|null $bonusInfo
     * @param callable $taxFunc      (float $gross) => float $tax. Caller inject
     *                                 yang sesuai (TER monthly, progressive yearly, dll)
     * @param float    $baseGross    Penghasilan dasar (basic + allowance + overtime + dst)
     *                                 untuk hitung marginal tax dari iterateGrossUp.
     *
     * @return array{amount:float, breakdown:array<int, array<string, mixed>>, gross_up_total:float}
     */
    public static function applyGrossUp(?array $bonusInfo, callable $taxFunc, float $baseGross = 0.0): array
    {
        if (! is_array($bonusInfo) || empty($bonusInfo['breakdown'])) {
            return [
                'amount'         => is_array($bonusInfo) ? (float) ($bonusInfo['amount'] ?? 0) : 0.0,
                'breakdown'      => is_array($bonusInfo) ? ($bonusInfo['breakdown'] ?? []) : [],
                'gross_up_total' => 0.0,
            ];
        }

        $newBreakdown = [];
        $totalAmount = 0.0;
        $grossUpTotal = 0.0;

        foreach ($bonusInfo['breakdown'] as $entry) {
            $tt = strtoupper((string) ($entry['tax_treatment'] ?? 'NORMAL'));
            $amt = (float) ($entry['amount'] ?? 0);

            if (($tt === 'GROSS_UP' || $tt === 'NET') && $amt > 0) {
                // Iterate: cari gross G yang setelah pajak ≈ targetNet (= amt)
                $result = Pph21TerCalculator::iterateGrossUp($amt, $taxFunc, $baseGross);
                $effectiveGross = (float) $result['gross_up_amount'];
                $taxAdded = (float) $result['tax_added'];

                $newEntry = $entry;
                $newEntry['original_amount']  = $amt;
                $newEntry['amount']           = $effectiveGross;
                $newEntry['tax_added']        = $taxAdded;
                $newEntry['converged']        = $result['converged'];
                $newEntry['iterations']       = $result['iterations'];
                // Tetap pakai treatment label asli untuk audit, tapi mark
                // bahwa amount sudah jadi gross
                $newEntry['effective_treatment'] = 'NORMAL';
                $newBreakdown[] = $newEntry;
                $totalAmount += $effectiveGross;
                $grossUpTotal += ($effectiveGross - $amt);
            } else {
                $newBreakdown[] = $entry;
                $totalAmount += $amt;
            }
        }

        return [
            'amount'         => $totalAmount,
            'breakdown'      => $newBreakdown,
            'gross_up_total' => round($grossUpTotal, 2),
        ];
    }
}
