<?php

namespace App\Libraries;

/**
 * WorkforceSimulationService (Sprint 19+ / H4.3)
 *
 * Workforce Planning & Scenario Modeling — Monte Carlo simulation untuk
 * forecasting headcount + cost over horizon 12-36 bulan.
 *
 * Use cases:
 *   1. CEO ingin tahu: "kalau growth 20%, hire plan 5 orang/bulan,
 *      attrition 1.5%/bulan, total cost di akhir tahun?"
 *   2. CFO ingin compare baseline vs aggressive vs conservative scenario
 *   3. CHRO planning headcount dengan confidence interval (p10/p50/p90)
 *
 * Pure simulation engine — no DB writes saat compute, hanya read
 * current headcount + salary baseline. Hasil di-persist ke
 * `workforce_scenario_runs` untuk reproducibility.
 *
 * Algoritma Monte Carlo:
 *   Per iteration (default 1000):
 *     starting_headcount = current company HC
 *     for each month in horizon:
 *       attrition = poisson(headcount × monthly_attrition_rate)
 *       hires = planned_hires_for_month + noise
 *       headcount += hires - attrition
 *       cost += headcount × avg_salary × (1 + inflation_pct)^months_elapsed
 *
 *   Setelah N iterasi, hitung percentile (p10, p50, p90) per metric.
 *
 * Pure helpers (testable tanpa DB):
 *   - simulateScenarioPure(currentHc, avgSalary, assumptions)
 *   - poissonRandom(lambda)
 *   - percentile(values[], p)
 */
class WorkforceSimulationService
{
    public const DEFAULT_ITERATIONS = 1000;
    public const MAX_ITERATIONS = 10000;
    public const MAX_HORIZON_MONTHS = 60;

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Pure Monte Carlo simulator. Tidak butuh DB, fully testable.
     *
     * @param int $startHeadcount       Headcount awal
     * @param float $avgSalary          Rata-rata gaji per karyawan
     * @param array $assumptions {
     *   horizon_months: int (1-60),
     *   monthly_attrition_rate: float (0..1, e.g. 0.015 = 1.5% per month),
     *   hiring_plan: array<int> (per-month hires; pad zero kalau kurang),
     *   salary_inflation_annual_pct: float (e.g. 0.08 = 8% per tahun),
     *   bpjs_load_factor: float (default 1.13 — employer cost),
     *   iterations: int (default 1000)
     * }
     * @param int $seed Optional seed untuk reproducibility (0 = random)
     *
     * @return array{
     *   p10_headcount_end: float, p50_headcount_end: float, p90_headcount_end: float,
     *   p10_total_cost: float, p50_total_cost: float, p90_total_cost: float,
     *   distribution: array<int, array{p10:float, p50:float, p90:float, headcount_p50:float}>,
     *   iterations_run: int
     * }
     */
    public static function simulateScenarioPure(
        int $startHeadcount,
        float $avgSalary,
        array $assumptions,
        int $seed = 0,
    ): array {
        $horizon = (int) ($assumptions['horizon_months'] ?? 12);
        $horizon = max(1, min($horizon, self::MAX_HORIZON_MONTHS));
        $attritionRate = (float) ($assumptions['monthly_attrition_rate'] ?? 0.01);
        $hiringPlan = $assumptions['hiring_plan'] ?? [];
        $inflationAnnual = (float) ($assumptions['salary_inflation_annual_pct'] ?? 0.05);
        $bpjsLoad = (float) ($assumptions['bpjs_load_factor'] ?? 1.13);
        $iterations = (int) ($assumptions['iterations'] ?? self::DEFAULT_ITERATIONS);
        $iterations = max(100, min($iterations, self::MAX_ITERATIONS));

        if ($seed > 0) {
            mt_srand($seed);
        }

        // Per-iteration result storage: headcount_end[i], total_cost[i]
        $endHeadcounts = [];
        $totalCosts = [];
        // Per-month: headcount tracker untuk percentile per bulan
        $monthlyHeadcount = array_fill(0, $horizon, []);

        for ($iter = 0; $iter < $iterations; $iter++) {
            $hc = $startHeadcount;
            $cost = 0.0;
            for ($m = 0; $m < $horizon; $m++) {
                // Hires this month + small variance (±20%)
                $plannedHires = (int) ($hiringPlan[$m] ?? 0);
                $hires = $plannedHires > 0
                    ? max(0, (int) round($plannedHires * (0.8 + (mt_rand() / mt_getrandmax()) * 0.4)))
                    : 0;

                // Attrition: Poisson distribution dengan lambda = hc * rate
                $attrition = self::poissonRandom($hc * $attritionRate);
                $attrition = min($attrition, $hc + $hires); // safety floor 0

                $hc = max(0, $hc + $hires - $attrition);
                $monthlyHeadcount[$m][] = $hc;

                // Cost untuk bulan ini = hc × salary × (1 + inflation/12)^m × bpjsLoad
                $monthlyInflation = pow(1 + $inflationAnnual, $m / 12);
                $cost += $hc * $avgSalary * $monthlyInflation * $bpjsLoad;
            }
            $endHeadcounts[] = $hc;
            $totalCosts[] = $cost;
        }

        // Compute percentiles
        sort($endHeadcounts);
        sort($totalCosts);
        $distribution = [];
        for ($m = 0; $m < $horizon; $m++) {
            $monthData = $monthlyHeadcount[$m];
            sort($monthData);
            $distribution[] = [
                'month'         => $m + 1,
                'headcount_p10' => self::percentile($monthData, 0.10),
                'headcount_p50' => self::percentile($monthData, 0.50),
                'headcount_p90' => self::percentile($monthData, 0.90),
            ];
        }

        return [
            'p10_headcount_end' => self::percentile($endHeadcounts, 0.10),
            'p50_headcount_end' => self::percentile($endHeadcounts, 0.50),
            'p90_headcount_end' => self::percentile($endHeadcounts, 0.90),
            'p10_total_cost'    => self::percentile($totalCosts, 0.10),
            'p50_total_cost'    => self::percentile($totalCosts, 0.50),
            'p90_total_cost'    => self::percentile($totalCosts, 0.90),
            'distribution'      => $distribution,
            'iterations_run'    => $iterations,
        ];
    }

    /**
     * Poisson random sampler via Knuth's method. Pure function.
     * For lambda < 30, exact; for lambda >= 30, normal approximation.
     */
    public static function poissonRandom(float $lambda): int
    {
        if ($lambda <= 0) return 0;

        if ($lambda < 30) {
            $L = exp(-$lambda);
            $k = 0;
            $p = 1.0;
            do {
                $k++;
                $p *= mt_rand() / mt_getrandmax();
            } while ($p > $L && $k < 200);
            return $k - 1;
        }

        // Normal approximation: N(lambda, sqrt(lambda))
        $u = mt_rand() / mt_getrandmax();
        $v = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log(max($u, 1e-10))) * cos(2 * M_PI * $v);
        return max(0, (int) round($lambda + $z * sqrt($lambda)));
    }

    /**
     * Percentile dari sorted array.
     * @param array<int, float|int> $sortedValues  Must already be sorted ASC
     */
    public static function percentile(array $sortedValues, float $p): float
    {
        $n = count($sortedValues);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $sortedValues[0];
        $idx = $p * ($n - 1);
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        if ($lo === $hi) return (float) $sortedValues[$lo];
        $frac = $idx - $lo;
        return (float) $sortedValues[$lo] + $frac * ((float) $sortedValues[$hi] - (float) $sortedValues[$lo]);
    }

    /**
     * Run scenario tersimpan + persist hasil ke `workforce_scenario_runs`.
     */
    public function runScenario(int $scenarioId, ?int $byUserId = null): array
    {
        $db = $this->db();
        $scenario = $db->table('workforce_scenarios')->where('id', $scenarioId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $scenario) throw new \RuntimeException('Scenario tidak ditemukan');

        $assumptions = json_decode((string) $scenario['assumptions_json'], true) ?: [];
        $assumptions['horizon_months'] = (int) $scenario['horizon_months'];

        // Current headcount + avg salary dari company
        $companyId = (int) $scenario['company_id'];
        $startHc = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        $salaryRow = $db->table('employees')
            ->selectAvg('basic_salary', 'avg_salary')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        $avgSalary = (float) ($salaryRow['avg_salary'] ?? 0);

        if ($startHc === 0 || $avgSalary <= 0) {
            throw new \RuntimeException(
                'Insufficient data: company harus punya minimal 1 karyawan aktif dengan basic_salary > 0'
            );
        }

        $result = self::simulateScenarioPure($startHc, $avgSalary, $assumptions);

        $now = date('Y-m-d H:i:s');
        $db->table('workforce_scenario_runs')->insert([
            'scenario_id'        => $scenarioId,
            'iterations'         => $result['iterations_run'],
            'p10_headcount_end'  => $result['p10_headcount_end'],
            'p50_headcount_end'  => $result['p50_headcount_end'],
            'p90_headcount_end'  => $result['p90_headcount_end'],
            'p10_total_cost'     => $result['p10_total_cost'],
            'p50_total_cost'     => $result['p50_total_cost'],
            'p90_total_cost'     => $result['p90_total_cost'],
            'distribution_json'  => json_encode($result['distribution']),
            'run_at'             => $now,
            'run_by_user_id'     => $byUserId,
            'created_at'         => $now,
        ]);
        $result['run_id']        = (int) $db->insertID();
        $result['scenario_id']   = $scenarioId;
        $result['start_headcount'] = $startHc;
        $result['avg_salary']    = $avgSalary;
        return $result;
    }

    /**
     * Compare 2-3 scenarios berdampingan (baseline vs aggressive vs conservative).
     */
    public function compareScenarios(array $scenarioIds, ?int $byUserId = null): array
    {
        $results = [];
        foreach ($scenarioIds as $sid) {
            $results[(int) $sid] = $this->runScenario((int) $sid, $byUserId);
        }
        return $results;
    }
}