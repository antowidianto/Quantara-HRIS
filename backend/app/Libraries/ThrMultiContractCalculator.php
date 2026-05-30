<?php

namespace App\Libraries;

/**
 * ThrMultiContractCalculator — Sprint C+1 / C2.
 *
 * THR (Tunjangan Hari Raya) per Permenaker 6/2016 + UU Cipta Kerja.
 *
 * Rumus dasar:
 *   - Tenure ≥ 12 bulan: 1× upah pokok
 *   - Tenure ≥ 1 bulan tapi < 12 bulan: (tenure_bulan / 12) × upah pokok
 *   - Tenure < 1 bulan: tidak berhak THR
 *
 * Yang di-handle library ini: **multi-contract dalam tahun yang sama**.
 * Skenario:
 *   1. Karyawan resign Maret, dipekerjakan ulang September di company sama
 *      → tenure THR Lebaran Mei: dihitung dari Jan-Mar (3 bulan, partial)
 *      → tenure THR Natal Des: dihitung dari Sep-Des (4 bulan, partial)
 *      → bukan akumulasi 7 bulan = sebelum sistem lama yang salah
 *   2. Karyawan PKWT renewal: contract_a end → contract_b start hari yang sama
 *      → tenure dianggap kontinu (tanpa gap)
 *   3. Karyawan dimutasi antar entity legal (BUMN holding, dst.)
 *      → out of scope; THR per entity hukum berbeda, caller terapkan filter
 *
 * Pure-function calculator. Caller pass list contract dari `employee_contracts`
 * yang sudah filtered untuk employee + tahun tertentu.
 *
 * Tests: `tests/Unit/ThrMultiContractCalculatorTest.php`.
 */
class ThrMultiContractCalculator
{
    /**
     * Toleransi gap antar kontrak yang dianggap "renewal kontinyu" (bukan
     * resignasi). Default 1 hari — kontrak A end 2026-03-31, kontrak B
     * start 2026-04-01 = renewal. Kalau gap > 1 hari, dianggap resign-rehire.
     */
    public const RENEWAL_GAP_DAYS = 1;

    /**
     * Hitung THR untuk holiday tertentu di tahun X.
     *
     * @param list<array{
     *   start_date:string,                  // Y-m-d
     *   end_date?:string|null,              // Y-m-d, null = ongoing
     *   basic_salary:float,
     * }> $contracts        Sorted ascending by start_date (caller responsibility)
     * @param string $holidayDate Y-m-d (Lebaran / Natal / dst.)
     *
     * @return array{
     *   eligible:bool,
     *   tenure_months_in_year:float,
     *   thr_amount:float,
     *   basis_salary:float,
     *   contract_segments:list<array{start:string,end:string,months:float}>,
     *   reason?:string,
     * }
     */
    public function compute(array $contracts, string $holidayDate): array
    {
        $emptyResult = [
            'eligible'              => false,
            'tenure_months_in_year' => 0.0,
            'thr_amount'            => 0.0,
            'basis_salary'          => 0.0,
            'contract_segments'     => [],
        ];

        if (empty($contracts)) {
            return $emptyResult + ['reason' => 'no_contract'];
        }

        $year = (int) substr($holidayDate, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd   = $holidayDate; // Tenure dihitung sampai tanggal holiday

        // 1. Filter kontrak yang overlap dengan periode [Jan 1, holidayDate]
        $segments = [];
        $latestSalary = 0.0;
        foreach ($contracts as $c) {
            $start = (string) $c['start_date'];
            $end   = (string) ($c['end_date'] ?? $holidayDate); // ongoing → cap di holiday
            if ($end === '' || $end === '0000-00-00') {
                $end = $holidayDate;
            }

            // Skip kontrak yang full di luar window
            if ($end < $yearStart || $start > $yearEnd) {
                continue;
            }

            // Clamp ke window [yearStart, yearEnd]
            $segStart = max($start, $yearStart);
            $segEnd   = min($end, $yearEnd);

            if ($segStart > $segEnd) {
                continue;
            }

            $months = $this->monthsBetween($segStart, $segEnd);
            $segments[] = [
                'start'        => $segStart,
                'end'          => $segEnd,
                'months'       => $months,
                'basic_salary' => (float) $c['basic_salary'],
            ];
            $latestSalary = (float) $c['basic_salary'];
        }

        if (empty($segments)) {
            return $emptyResult + ['reason' => 'no_overlap_with_year'];
        }

        // 2. Merge segment yang renewal (gap ≤ RENEWAL_GAP_DAYS)
        $merged = $this->mergeRenewalSegments($segments);

        // 3. Total tenure = akumulasi dari segmen aktif terakhir saja
        //    (per Permenaker 6/2016: kalau resign-rehire tahun sama, tenure
        //    yang dipakai adalah masa kerja CURRENT contract, bukan akumulasi)
        $activeSegment = end($merged);
        if ($activeSegment === false) {
            return $emptyResult + ['reason' => 'no_active_contract'];
        }
        $tenureMonths = (float) $activeSegment['months'];

        // 4. Hitung THR
        if ($tenureMonths < 1.0) {
            return $emptyResult + [
                'tenure_months_in_year' => $tenureMonths,
                'reason'                => 'tenure_below_minimum',
                'contract_segments'     => $this->trimMergedForOutput($merged),
            ];
        }

        $basisSalary = $latestSalary;
        $thr = $tenureMonths >= 12.0
            ? $basisSalary
            : round(($tenureMonths / 12.0) * $basisSalary, 2);

        return [
            'eligible'              => true,
            'tenure_months_in_year' => $tenureMonths,
            'thr_amount'            => $thr,
            'basis_salary'          => $basisSalary,
            'contract_segments'     => $this->trimMergedForOutput($merged),
        ];
    }

    /**
     * Merge segmen yang gap ≤ RENEWAL_GAP_DAYS — anggap kontrak kontinyu.
     *
     * @param list<array{start:string,end:string,months:float,basic_salary:float}> $segments
     * @return list<array{start:string,end:string,months:float,basic_salary:float}>
     */
    private function mergeRenewalSegments(array $segments): array
    {
        if (count($segments) <= 1) {
            return $segments;
        }

        // Pastikan sorted ascending by start
        usort($segments, fn(array $a, array $b) => strcmp($a['start'], $b['start']));

        $merged = [$segments[0]];
        for ($i = 1; $i < count($segments); $i++) {
            $current = $segments[$i];
            $last    = &$merged[count($merged) - 1];

            $gapDays = $this->dayDiff($last['end'], $current['start']);
            if ($gapDays <= self::RENEWAL_GAP_DAYS) {
                // Renewal kontinyu — extend segment terakhir
                $last['end']    = $current['end'];
                $last['months'] = $this->monthsBetween($last['start'], $last['end']);
                // Pakai salary kontrak terbaru (biasanya naik di renewal)
                $last['basic_salary'] = $current['basic_salary'];
            } else {
                // Gap > toleransi → resign-rehire, segment baru
                $merged[] = $current;
            }
        }

        return $merged;
    }

    /**
     * Bulan antara start dan end (inclusive). 30 hari = 1 bulan.
     * Dipakai konsisten dengan calculator lain di codebase.
     */
    private function monthsBetween(string $start, string $end): float
    {
        $days = $this->dayDiff($start, $end) + 1; // +1 inclusive
        return round($days / 30.0, 4);
    }

    /**
     * Selisih hari antara dua tanggal Y-m-d. Negatif kalau end < start.
     */
    private function dayDiff(string $start, string $end): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new \DateTimeImmutable($start);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $end)   ?: new \DateTimeImmutable($end);
        $diff = $a->diff($b);
        $signed = $diff->invert ? -$diff->days : $diff->days;
        return (int) $signed;
    }

    /**
     * @param list<array{start:string,end:string,months:float,basic_salary:float}> $merged
     * @return list<array{start:string,end:string,months:float}>
     */
    private function trimMergedForOutput(array $merged): array
    {
        return array_values(array_map(
            static fn(array $s) => [
                'start'  => $s['start'],
                'end'    => $s['end'],
                'months' => $s['months'],
            ],
            $merged,
        ));
    }
}