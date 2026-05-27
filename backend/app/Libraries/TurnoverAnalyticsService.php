<?php

namespace App\Libraries;

/**
 * TurnoverAnalyticsService (Sprint 12 / H2.6)
 *
 * Hitung turnover & retention metrics berbasis schema actual:
 *   - employees.resign_date  → tanggal separation
 *   - resignation_requests   → voluntary separations (status=APPROVED)
 *   - termination_requests   → involuntary separations (status=EXECUTED)
 *
 * Snapshot disimpan di `turnover_metrics_snapshot` agar trend dashboard
 * tidak recompute setiap kali. Cron `php spark turnover:snapshot` jalan
 * tiap awal bulan untuk YTD periode.
 *
 * Formula:
 *   - turnover_rate    = (separations / avg_headcount) × 100
 *   - voluntary_rate   = (resignations / avg_headcount) × 100
 *   - involuntary_rate = (terminations / avg_headcount) × 100
 *   - retention_rate   = 100 − turnover_rate
 *   - early_attrition  = % separations dengan tenure < 12 bulan
 *   - regrettable_loss = % separations dengan last performance rating ≥ 4
 *
 * Period: MONTH (YYYY-MM) atau YEAR (YYYY).
 */
class TurnoverAnalyticsService
{
    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Hitung metric untuk satu period.
     *
     * @return array<string,mixed>
     */
    public function compute(int $companyId, string $periodType, string $periodKey): array
    {
        [$startDate, $endDate] = self::resolvePeriodRange($periodType, $periodKey);

        $hcStart = $this->headcountAt($companyId, $startDate);
        $hcEnd   = $this->headcountAt($companyId, $endDate);
        $avgHc   = ($hcStart + $hcEnd) / 2;

        $seps = $this->separationsBetween($companyId, $startDate, $endDate);
        $voluntary = 0;
        $involuntary = 0;
        $earlyAttrition = 0;
        $regrettable = 0;
        foreach ($seps as $s) {
            if ($s['type'] === 'VOLUNTARY') $voluntary++;
            else $involuntary++;

            if (! empty($s['join_date']) && ! empty($s['resign_date'])) {
                $tenureDays = (strtotime($s['resign_date']) - strtotime($s['join_date'])) / 86400;
                if ($tenureDays < 365) $earlyAttrition++;
            }
            if (! empty($s['last_rating']) && (float) $s['last_rating'] >= 4) {
                $regrettable++;
            }
        }
        $totalSep = count($seps);
        $hires = $this->hiresBetween($companyId, $startDate, $endDate);

        $turnoverRate    = self::pctRate($totalSep, $avgHc);
        $voluntaryRate   = self::pctRate($voluntary, $avgHc);
        $involuntaryRate = self::pctRate($involuntary, $avgHc);
        $retentionRate   = $avgHc > 0 ? round(100 - $turnoverRate, 2) : null;
        $earlyRate       = $totalSep > 0 ? round(($earlyAttrition / $totalSep) * 100, 2) : 0.0;
        $regrettableRate = $totalSep > 0 ? round(($regrettable / $totalSep) * 100, 2) : 0.0;

        return [
            'company_id'           => $companyId,
            'period_type'          => $periodType,
            'period_key'           => $periodKey,
            'period_start'         => $startDate,
            'period_end'           => $endDate,
            'headcount_start'      => $hcStart,
            'headcount_end'        => $hcEnd,
            'avg_headcount'        => round($avgHc, 2),
            'hires'                => $hires,
            'separations_total'    => $totalSep,
            'voluntary'            => $voluntary,
            'involuntary'          => $involuntary,
            'turnover_rate'        => $turnoverRate,
            'voluntary_rate'       => $voluntaryRate,
            'involuntary_rate'     => $involuntaryRate,
            'retention_rate'       => $retentionRate,
            'early_attrition_rate' => $earlyRate,
            'regrettable_rate'     => $regrettableRate,
        ];
    }

    /**
     * Persist hasil compute ke snapshot table. Idempotent.
     */
    public function snapshot(int $companyId, string $periodType, string $periodKey): array
    {
        $metrics = $this->compute($companyId, $periodType, $periodKey);
        $now = date('Y-m-d H:i:s');

        $existing = $this->db()->table('turnover_metrics_snapshot')
            ->where('company_id', $companyId)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->get()->getRowArray();

        $payload = $metrics + ['updated_at' => $now];
        if ($existing) {
            $this->db()->table('turnover_metrics_snapshot')
                ->where('id', (int) $existing['id'])->update($payload);
            $metrics['id'] = (int) $existing['id'];
        } else {
            $payload['created_at'] = $now;
            $this->db()->table('turnover_metrics_snapshot')->insert($payload);
            $metrics['id'] = (int) $this->db()->insertID();
        }
        return $metrics;
    }

    /**
     * Trend N period terakhir (default 12 bulan).
     *
     * @return array<int, array<string,mixed>>
     */
    public function trend(int $companyId, string $periodType = 'MONTH', int $limit = 12): array
    {
        return $this->db()->table('turnover_metrics_snapshot')
            ->where('company_id', $companyId)
            ->where('period_type', $periodType)
            ->orderBy('period_key', 'DESC')
            ->limit($limit)->get()->getResultArray();
    }

    /**
     * Pure rate calculator — testable tanpa DB.
     */
    public static function pctRate(int $count, float $avgHeadcount): float
    {
        if ($avgHeadcount <= 0) return 0.0;
        return round(($count / $avgHeadcount) * 100, 2);
    }

    /**
     * Pure separation classifier.
     * Source: 'RESIGNATION' = voluntary, 'TERMINATION' = involuntary
     * (kecuali tipe RETIREMENT/CONTRACT_END yang sebenarnya voluntary).
     *
     * @param array<string,mixed> $row
     */
    public static function classifyType(array $row): string
    {
        $source = strtoupper((string) ($row['source'] ?? ''));
        if ($source === 'RESIGNATION') return 'VOLUNTARY';
        if ($source === 'TERMINATION') {
            $type = strtoupper((string) ($row['termination_type'] ?? ''));
            if (in_array($type, ['RETIREMENT', 'CONTRACT_END'], true)) {
                return 'VOLUNTARY';
            }
            return 'INVOLUNTARY';
        }
        // Default fallback (ada resign_date tapi tidak ada source)
        return 'VOLUNTARY';
    }

    /**
     * Resolve period_key ke [start, end] tanggal inklusif.
     * @return array{0: string, 1: string}
     */
    public static function resolvePeriodRange(string $type, string $key): array
    {
        if ($type === 'YEAR') {
            $year = (int) substr($key, 0, 4);
            return ["$year-01-01", "$year-12-31"];
        }
        $year = (int) substr($key, 0, 4);
        $month = (int) substr($key, 5, 2);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }

    /**
     * Headcount aktif pada tanggal `date`: hire_date <= date AND
     * (resign_date IS NULL OR resign_date > date).
     */
    private function headcountAt(int $companyId, string $date): int
    {
        return (int) $this->db()->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date <=', $date)
            ->groupStart()
                ->where('resign_date IS NULL', null, false)
                ->orWhere('resign_date >', $date)
            ->groupEnd()
            ->countAllResults();
    }

    private function hiresBetween(int $companyId, string $start, string $end): int
    {
        return (int) $this->db()->table('employees')
            ->where('company_id', $companyId)
            ->where('join_date >=', $start)
            ->where('join_date <=', $end)
            ->countAllResults();
    }

    /**
     * Separations: employees yang resign_date di range, di-enrich dengan
     * source dari resignation_requests / termination_requests (mana yang
     * lebih dekat ke resign_date) dan last performance rating.
     *
     * @return array<int, array<string,mixed>>
     */
    private function separationsBetween(int $companyId, string $start, string $end): array
    {
        $db = $this->db();
        $emps = $db->table('employees')
            ->select('id, join_date, resign_date')
            ->where('company_id', $companyId)
            ->where('resign_date >=', $start)
            ->where('resign_date <=', $end)
            ->get()->getResultArray();
        if (empty($emps)) return [];

        $empIds = array_map(fn ($e) => (int) $e['id'], $emps);

        // Sumber RESIGNATION (status APPROVED)
        $resigMap = [];
        if ($db->tableExists('resignation_requests')) {
            $rows = $db->table('resignation_requests')
                ->whereIn('employee_id', $empIds)
                ->where('status', 'APPROVED')
                ->orderBy('effective_date', 'DESC')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $eid = (int) $r['employee_id'];
                if (! isset($resigMap[$eid])) {
                    $resigMap[$eid] = $r;
                }
            }
        }

        // Sumber TERMINATION (status EXECUTED)
        $termMap = [];
        if ($db->tableExists('termination_requests')) {
            $rows = $db->table('termination_requests')
                ->whereIn('employee_id', $empIds)
                ->where('status', 'EXECUTED')
                ->orderBy('effective_date', 'DESC')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $eid = (int) $r['employee_id'];
                if (! isset($termMap[$eid])) {
                    $termMap[$eid] = $r;
                }
            }
        }

        // Last performance rating (final_score dari performance_reviews)
        $ratingMap = [];
        if ($db->tableExists('performance_reviews')) {
            $rows = $db->table('performance_reviews')
                ->select('employee_id, MAX(final_score) AS rating')
                ->whereIn('employee_id', $empIds)
                ->where('final_score IS NOT NULL', null, false)
                ->groupBy('employee_id')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $ratingMap[(int) $r['employee_id']] = (float) $r['rating'];
            }
        }

        $out = [];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $source = null;
            $termType = null;
            // Tentukan source: termination menang kalau kedua-duanya ada
            // dan termination effective_date >= resignation effective_date
            if (isset($termMap[$eid])) {
                $source = 'TERMINATION';
                $termType = $termMap[$eid]['termination_type'] ?? null;
            } elseif (isset($resigMap[$eid])) {
                $source = 'RESIGNATION';
            }

            $row = [
                'employee_id'      => $eid,
                'join_date'        => $e['join_date'],
                'resign_date'      => $e['resign_date'],
                'source'           => $source,
                'termination_type' => $termType,
                'last_rating'      => $ratingMap[$eid] ?? null,
            ];
            $row['type'] = self::classifyType($row);
            $out[] = $row;
        }
        return $out;
    }
}