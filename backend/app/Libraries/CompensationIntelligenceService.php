<?php

namespace App\Libraries;

/**
 * CompensationIntelligenceService (Sprint 19+ / H4.5)
 *
 * Compensation analysis untuk benchmarking + pay equity:
 *
 *   1. Compa-Ratio: rasio gaji aktual vs midpoint market (atau internal band)
 *      compa = current_salary / market_p50_salary
 *      Interpretation:
 *        < 0.80 → underpaid (retention risk)
 *        0.80-0.95 → low band (developing)
 *        0.95-1.05 → ON BAND (typical)
 *        1.05-1.20 → upper band (high performer / experienced)
 *        > 1.20 → overpaid (limit further increase)
 *
 *   2. Pay Equity Analysis:
 *      - Gender pay gap: avg(male) − avg(female) per grade/posisi
 *      - Coefficient of variation per grade (consistency check)
 *      - Outlier detection: > 1.5 IQR dari median per grade
 *
 *   3. Salary Band Recommendation: dari benchmark + internal distribution,
 *      generate rekomendasi band (min, mid, max) per job grade.
 *
 * Pure helpers:
 *   - calcCompaRatio(actual, market_p50)
 *   - compaBand(ratio) → ENUM
 *   - calcGenderGap(maleAvg, femaleAvg, baseline?)
 *   - detectOutliers(salaries[]) via IQR method
 */
class CompensationIntelligenceService
{
    public const ANALYSIS_TYPES = [
        'COMPA_RATIO', 'PAY_EQUITY_GENDER', 'PAY_EQUITY_GRADE', 'BAND_RECOMMENDATION',
    ];

    public const COMPA_BANDS = [
        'UNDERPAID'   => [0,    0.80],
        'DEVELOPING'  => [0.80, 0.95],
        'ON_BAND'     => [0.95, 1.05],
        'EXCEEDS'     => [1.05, 1.20],
        'OVERPAID'    => [1.20, INF],
    ];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Pure compa-ratio calculator.
     */
    public static function calcCompaRatio(float $actualSalary, float $marketP50): float
    {
        if ($marketP50 <= 0) return 0.0;
        return round($actualSalary / $marketP50, 4);
    }

    /**
     * Pure compa-ratio bucket mapper.
     */
    public static function compaBand(float $ratio): string
    {
        foreach (self::COMPA_BANDS as $label => [$lo, $hi]) {
            if ($ratio >= $lo && $ratio < $hi) return $label;
        }
        return $ratio >= 1.20 ? 'OVERPAID' : 'UNDERPAID';
    }

    /**
     * Pure gender pay gap calculator.
     * Return positive = male > female (potensi gap), negative = sebaliknya.
     * Pakai male sebagai baseline (industry standard).
     */
    public static function calcGenderGap(float $maleAvg, float $femaleAvg): array
    {
        if ($maleAvg <= 0) {
            return ['gap_amount' => 0.0, 'gap_pct' => 0.0, 'baseline' => 'NONE'];
        }
        $gap = $maleAvg - $femaleAvg;
        $pct = round(($gap / $maleAvg) * 100, 2);
        return [
            'gap_amount' => round($gap, 2),
            'gap_pct'    => $pct,
            'baseline'   => 'MALE',
            'male_avg'   => $maleAvg,
            'female_avg' => $femaleAvg,
        ];
    }

    /**
     * Pure outlier detection via IQR (Interquartile Range) method.
     * Outlier: x < Q1 - 1.5×IQR atau x > Q3 + 1.5×IQR.
     *
     * @param array<int, float> $salaries
     * @return array{lower_bound: float, upper_bound: float, outliers_low: array, outliers_high: array}
     */
    public static function detectOutliers(array $salaries): array
    {
        if (count($salaries) < 4) {
            return ['lower_bound' => 0.0, 'upper_bound' => INF,
                'outliers_low' => [], 'outliers_high' => []];
        }
        sort($salaries);
        $n = count($salaries);
        $q1 = WorkforceSimulationService::percentile($salaries, 0.25);
        $q3 = WorkforceSimulationService::percentile($salaries, 0.75);
        $iqr = $q3 - $q1;
        $lowerBound = $q1 - 1.5 * $iqr;
        $upperBound = $q3 + 1.5 * $iqr;
        $low = array_values(array_filter($salaries, fn ($s) => $s < $lowerBound));
        $high = array_values(array_filter($salaries, fn ($s) => $s > $upperBound));
        return [
            'q1' => round($q1, 2),
            'q3' => round($q3, 2),
            'iqr' => round($iqr, 2),
            'lower_bound' => round($lowerBound, 2),
            'upper_bound' => round($upperBound, 2),
            'outliers_low'  => $low,
            'outliers_high' => $high,
        ];
    }

    /**
     * Pure coefficient of variation: stddev / mean.
     * Indikator dispersi gaji dalam grade — kalau > 0.30 berarti spread
     * terlalu besar untuk grade yang sama.
     */
    public static function coefficientOfVariation(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        if ($mean <= 0) return 0.0;
        $variance = 0.0;
        foreach ($values as $v) $variance += pow($v - $mean, 2);
        $variance /= ($n - 1); // sample variance (Bessel correction)
        $stddev = sqrt($variance);
        return round($stddev / $mean, 4);
    }

    /**
     * Compute compa-ratio untuk semua karyawan di company, dibandingkan
     * dengan benchmark per posisi/grade.
     *
     * @return array{summary: array, by_employee: array, by_band: array, computed_at: string}
     */
    public function computeCompaRatio(int $companyId): array
    {
        $db = $this->db();
        $emps = $db->table('employees e')
            ->select('e.id, e.full_name, e.employee_number, e.position_id, e.job_grade_id, '
                . 'e.basic_salary, e.gender, p.name AS position_name, jg.name AS grade_name')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('job_grades jg', 'jg.id = e.job_grade_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->get()->getResultArray();

        // Index latest benchmark per (position_id) atau (job_grade_id)
        $today = date('Y-m-d');
        $benchmarks = $db->table('salary_benchmarks')
            ->where('company_id', $companyId)
            ->where('effective_date <=', $today)
            ->groupStart()
                ->where('expires_at IS NULL', null, false)
                ->orWhere('expires_at >=', $today)
            ->groupEnd()
            ->orderBy('effective_date', 'DESC')
            ->get()->getResultArray();
        $byPosition = [];
        $byGrade = [];
        foreach ($benchmarks as $b) {
            if (! empty($b['position_id'])) {
                $byPosition[(int) $b['position_id']] ??= $b;
            }
            if (! empty($b['job_grade_id'])) {
                $byGrade[(int) $b['job_grade_id']] ??= $b;
            }
        }

        $byEmployee = [];
        $bandCounts = ['UNDERPAID' => 0, 'DEVELOPING' => 0,
            'ON_BAND' => 0, 'EXCEEDS' => 0, 'OVERPAID' => 0, 'NO_BENCHMARK' => 0];
        $compaRatios = [];

        foreach ($emps as $emp) {
            $bench = $byPosition[(int) ($emp['position_id'] ?? 0)]
                ?? $byGrade[(int) ($emp['job_grade_id'] ?? 0)]
                ?? null;
            if (! $bench || empty($bench['p50_salary'])) {
                $bandCounts['NO_BENCHMARK']++;
                $byEmployee[] = $emp + [
                    'compa_ratio' => null,
                    'compa_band'  => 'NO_BENCHMARK',
                    'market_p50'  => null,
                ];
                continue;
            }
            $ratio = self::calcCompaRatio((float) $emp['basic_salary'], (float) $bench['p50_salary']);
            $band = self::compaBand($ratio);
            $bandCounts[$band]++;
            $compaRatios[] = $ratio;
            $byEmployee[] = $emp + [
                'compa_ratio' => $ratio,
                'compa_band'  => $band,
                'market_p50'  => (float) $bench['p50_salary'],
            ];
        }

        $avgCompa = ! empty($compaRatios)
            ? round(array_sum($compaRatios) / count($compaRatios), 4) : null;

        $result = [
            'summary' => [
                'total_employees'      => count($emps),
                'with_benchmark'       => count($emps) - $bandCounts['NO_BENCHMARK'],
                'avg_compa_ratio'      => $avgCompa,
                'health_indicator'     => $avgCompa !== null
                    ? ($avgCompa >= 0.95 && $avgCompa <= 1.05 ? 'HEALTHY' :
                        ($avgCompa < 0.95 ? 'UNDER_MARKET' : 'OVER_MARKET'))
                    : 'NO_DATA',
            ],
            'by_band'      => $bandCounts,
            'by_employee'  => $byEmployee,
            'computed_at'  => date('Y-m-d H:i:s'),
        ];

        $this->persistAnalysis($companyId, 'COMPA_RATIO', $avgCompa ?? 0, $result);
        return $result;
    }

    /**
     * Compute pay equity analysis by gender (per grade).
     */
    public function computeGenderEquity(int $companyId): array
    {
        $db = $this->db();
        $rows = $db->table('employees e')
            ->select('e.gender, e.basic_salary, e.job_grade_id, jg.name AS grade_name')
            ->join('job_grades jg', 'jg.id = e.job_grade_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->whereIn('e.gender', ['M', 'F'])
            ->get()->getResultArray();

        $byGrade = [];
        foreach ($rows as $r) {
            $gid = (int) ($r['job_grade_id'] ?? 0);
            $key = $gid > 0 ? (string) $gid : 'NO_GRADE';
            if (! isset($byGrade[$key])) {
                $byGrade[$key] = ['name' => $r['grade_name'] ?? 'No Grade',
                    'M' => [], 'F' => []];
            }
            if ($r['gender'] === 'M') $byGrade[$key]['M'][] = (float) $r['basic_salary'];
            elseif ($r['gender'] === 'F') $byGrade[$key]['F'][] = (float) $r['basic_salary'];
        }

        $analysis = [];
        $weightedGapSum = 0.0;
        $totalSamples = 0;
        foreach ($byGrade as $gid => $bucket) {
            $maleSalaries = array_values(array_filter(
                $bucket['M'] ?? [], fn ($x) => $x > 0
            ));
            $femaleSalaries = array_values(array_filter(
                $bucket['F'] ?? [], fn ($x) => $x > 0
            ));
            $maleAvg = ! empty($maleSalaries) ? array_sum($maleSalaries) / count($maleSalaries) : 0;
            $femaleAvg = ! empty($femaleSalaries) ? array_sum($femaleSalaries) / count($femaleSalaries) : 0;

            // Skip kalau salah satu gender < 3 sample (privacy)
            if (count($maleSalaries) < 3 || count($femaleSalaries) < 3) {
                $analysis[] = [
                    'grade_id'       => $gid,
                    'grade_name'     => $bucket['name'] ?? null,
                    'male_count'     => count($maleSalaries),
                    'female_count'   => count($femaleSalaries),
                    'suppressed'     => true,
                    'reason'         => 'Sample size < 3 untuk salah satu gender',
                ];
                continue;
            }
            $gap = self::calcGenderGap($maleAvg, $femaleAvg);
            $analysis[] = [
                'grade_id'       => $gid,
                'grade_name'     => $bucket['name'] ?? null,
                'male_count'     => count($maleSalaries),
                'female_count'   => count($femaleSalaries),
                'gap_amount'     => $gap['gap_amount'],
                'gap_pct'        => $gap['gap_pct'],
                'male_avg'       => round($maleAvg, 2),
                'female_avg'     => round($femaleAvg, 2),
                'flag'           => abs($gap['gap_pct']) > 10 ? 'REVIEW_NEEDED' : 'OK',
            ];
            $weighted = $gap['gap_pct'] * (count($maleSalaries) + count($femaleSalaries));
            $weightedGapSum += $weighted;
            $totalSamples += count($maleSalaries) + count($femaleSalaries);
        }

        $companyGapPct = $totalSamples > 0
            ? round($weightedGapSum / $totalSamples, 2) : 0.0;

        $result = [
            'company_gender_gap_pct' => $companyGapPct,
            'health_indicator'       => abs($companyGapPct) <= 5 ? 'HEALTHY'
                : (abs($companyGapPct) <= 10 ? 'WATCH' : 'CONCERN'),
            'by_grade'   => $analysis,
            'computed_at' => date('Y-m-d H:i:s'),
        ];

        $this->persistAnalysis($companyId, 'PAY_EQUITY_GENDER', $companyGapPct, $result);
        return $result;
    }

    /**
     * Generate band recommendation per grade berdasarkan benchmark + dispersi internal.
     */
    public function recommendSalaryBands(int $companyId): array
    {
        $db = $this->db();
        $today = date('Y-m-d');

        // Group karyawan per grade dengan basic_salary distribution
        $rows = $db->table('employees e')
            ->select('e.job_grade_id, e.basic_salary, jg.name AS grade_name')
            ->join('job_grades jg', 'jg.id = e.job_grade_id', 'left')
            ->where('e.company_id', $companyId)
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')
            ->where('e.job_grade_id IS NOT NULL', null, false)
            ->get()->getResultArray();

        $byGrade = [];
        foreach ($rows as $r) {
            $gid = (int) $r['job_grade_id'];
            $byGrade[$gid]['name'] = $r['grade_name'];
            $byGrade[$gid]['salaries'][] = (float) $r['basic_salary'];
        }

        $recommendations = [];
        foreach ($byGrade as $gid => $b) {
            $salaries = $b['salaries'] ?? [];
            if (count($salaries) < 3) continue;
            sort($salaries);

            // Internal stats
            $internalMin = min($salaries);
            $internalMid = WorkforceSimulationService::percentile($salaries, 0.50);
            $internalMax = max($salaries);
            $cv = self::coefficientOfVariation($salaries);

            // Market benchmark untuk grade ini
            $bench = $db->table('salary_benchmarks')
                ->where('company_id', $companyId)
                ->where('job_grade_id', $gid)
                ->where('effective_date <=', $today)
                ->orderBy('effective_date', 'DESC')
                ->limit(1)->get()->getRowArray();

            // Recommended band: blend internal + market (60/40)
            if ($bench && ! empty($bench['p50_salary'])) {
                $marketMin = (float) ($bench['p25_salary'] ?? $bench['p50_salary'] * 0.85);
                $marketMid = (float) $bench['p50_salary'];
                $marketMax = (float) ($bench['p75_salary'] ?? $bench['p50_salary'] * 1.15);
                $recMin = round(($internalMin * 0.4) + ($marketMin * 0.6));
                $recMid = round(($internalMid * 0.4) + ($marketMid * 0.6));
                $recMax = round(($internalMax * 0.4) + ($marketMax * 0.6));
            } else {
                // No benchmark — pakai internal saja dengan ±15% spread
                $recMin = round($internalMid * 0.85);
                $recMid = round($internalMid);
                $recMax = round($internalMid * 1.15);
            }

            $outliers = self::detectOutliers($salaries);
            $recommendations[] = [
                'grade_id'           => $gid,
                'grade_name'         => $b['name'] ?? null,
                'sample_size'        => count($salaries),
                'internal_min'       => round($internalMin, 2),
                'internal_mid'       => round($internalMid, 2),
                'internal_max'       => round($internalMax, 2),
                'coefficient_variation' => $cv,
                'has_benchmark'      => $bench !== null,
                'recommended_min'    => $recMin,
                'recommended_mid'    => $recMid,
                'recommended_max'    => $recMax,
                'outliers_low_count' => count($outliers['outliers_low']),
                'outliers_high_count'=> count($outliers['outliers_high']),
                'flag'               => $cv > 0.30 ? 'HIGH_DISPERSION' : 'OK',
            ];
        }

        $result = [
            'recommendations' => $recommendations,
            'computed_at'     => date('Y-m-d H:i:s'),
        ];
        $this->persistAnalysis($companyId, 'BAND_RECOMMENDATION', count($recommendations), $result);
        return $result;
    }

    private function persistAnalysis(
        int $companyId, string $type, float $summaryMetric, array $result,
    ): void {
        $period = date('Y-m');
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('compensation_analyses')
            ->where('company_id', $companyId)
            ->where('analysis_type', $type)
            ->where('period_label', $period)
            ->get()->getRowArray();
        $payload = [
            'company_id'     => $companyId,
            'analysis_type'  => $type,
            'period_label'   => $period,
            'result_json'    => json_encode($result, JSON_UNESCAPED_UNICODE),
            'summary_metric' => $summaryMetric,
            'computed_at'    => $now,
        ];
        if ($existing) {
            $db->table('compensation_analyses')->where('id', (int) $existing['id'])->update($payload);
        } else {
            $payload['created_at'] = $now;
            $db->table('compensation_analyses')->insert($payload);
        }
    }
}