<?php

namespace App\Libraries;

/**
 * AllowanceService — hitung tunjangan berbasis jarak rumah karyawan ke
 * cabang tempat dia ditugaskan. Dipakai oleh:
 *
 *   - EmployeeAllowanceController saat HR memicu recompute manual.
 *   - PayrollService saat menghitung slip gaji per karyawan, agar nilai
 *     tunjangan ditambahkan ke earnings.
 *
 * Aturan dasar:
 *   - Karyawan harus punya `branch_id` (tempat tugas) dan koordinat
 *     `home_lat`/`home_lng`. Branch harus punya `lat`/`lng`.
 *   - Distance dihitung pakai Haversine.
 *   - Cari `allowance_rates` paling spesifik berdasarkan
 *     (allowance_type, branch_id, job_grade_id). Fallback: branch NULL +
 *     job_grade NULL = default per company.
 *   - `eligible = distance >= threshold_km`.
 *   - `amount = eligible ? rate_above : rate_below`.
 *
 * Snapshot disimpan di `employee_allowance_assignments` agar bisa
 * diaudit & di-override manual oleh HR (flag `manual_override`).
 */
class AllowanceService
{
    /**
     * Hitung jarak haversine dalam km.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0; // radius bumi km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Cari rate paling spesifik untuk kombinasi (company, allowance, branch, grade).
     *
     * Urutan prioritas:
     *   1. exact branch + exact grade
     *   2. exact branch + grade NULL (any-grade fallback)
     *   3. branch NULL  + exact grade
     *   4. branch NULL  + grade NULL (default company-wide)
     */
    public function findBestRate(int $companyId, int $allowanceTypeId, ?int $branchId, ?int $jobGradeId, ?string $atDate = null): ?array
    {
        $db = \Config\Database::connect();
        $atDate ??= date('Y-m-d');

        $candidates = $db->table('allowance_rates')
            ->where('company_id', $companyId)
            ->where('allowance_type_id', $allowanceTypeId)
            ->groupStart()
                ->where('effective_from IS NULL')
                ->orWhere('effective_from <=', $atDate)
            ->groupEnd()
            ->groupStart()
                ->where('effective_to IS NULL')
                ->orWhere('effective_to >=', $atDate)
            ->groupEnd()
            ->get()
            ->getResultArray();

        if (! $candidates) return null;

        $score = function (array $r) use ($branchId, $jobGradeId): int {
            $s = 0;
            if ($branchId !== null && (int) ($r['branch_id'] ?? 0) === $branchId) $s += 10;
            elseif ($r['branch_id'] === null) $s += 1;
            else $s -= 100; // mismatched branch — exclude
            if ($jobGradeId !== null && (int) ($r['job_grade_id'] ?? 0) === $jobGradeId) $s += 5;
            elseif ($r['job_grade_id'] === null) $s += 1;
            else $s -= 100;
            return $s;
        };

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $r) {
            $sc = $score($r);
            if ($sc < 0) continue;
            if ($sc > $bestScore) { $best = $r; $bestScore = $sc; }
        }
        return $best;
    }

    /**
     * Hitung satu allowance untuk satu karyawan. Return array hasil
     * (tanpa side-effect ke DB).
     *
     * @return array{distance_km: ?float, threshold_km: ?float, eligible: bool, amount: float, rate_id: ?int, note: ?string}
     */
    public function computeForEmployee(array $employee, array $allowanceType, ?array $branch = null): array
    {
        $companyId = (int) $employee['company_id'];
        $branchId  = isset($employee['branch_id']) ? (int) $employee['branch_id'] : null;
        $gradeId   = isset($employee['job_grade_id']) ? (int) $employee['job_grade_id'] : null;

        $rate = $this->findBestRate($companyId, (int) $allowanceType['id'], $branchId, $gradeId);
        if (! $rate) {
            return [
                'distance_km'   => null,
                'threshold_km'  => (float) ($allowanceType['default_threshold_km'] ?? 30),
                'eligible'      => false,
                'amount'        => 0.0,
                'rate_id'       => null,
                'note'          => 'Tarif belum dikonfigurasi untuk grade/cabang ini',
            ];
        }

        $threshold = (float) ($rate['threshold_km'] ?? $allowanceType['default_threshold_km'] ?? 30);

        // Kalau type bukan distance-based, langsung pakai rate_above sebagai
        // tarif flat per-grade.
        if (empty($allowanceType['is_distance_based'])) {
            return [
                'distance_km'   => null,
                'threshold_km'  => $threshold,
                'eligible'      => true,
                'amount'        => (float) $rate['rate_above'],
                'rate_id'       => (int) $rate['id'],
                'note'          => 'Tunjangan flat per-grade',
            ];
        }

        // Distance-based: butuh koordinat rumah & cabang.
        $homeLat = $employee['home_lat'] ?? null;
        $homeLng = $employee['home_lng'] ?? null;
        if ($homeLat === null || $homeLng === null) {
            return [
                'distance_km'   => null,
                'threshold_km'  => $threshold,
                'eligible'      => false,
                'amount'        => (float) $rate['rate_below'],
                'rate_id'       => (int) $rate['id'],
                'note'          => 'Koordinat rumah karyawan belum diisi',
            ];
        }

        if (! $branch) {
            if (! $branchId) {
                return [
                    'distance_km'   => null,
                    'threshold_km'  => $threshold,
                    'eligible'      => false,
                    'amount'        => (float) $rate['rate_below'],
                    'rate_id'       => (int) $rate['id'],
                    'note'          => 'Karyawan belum di-assign ke cabang',
                ];
            }
            $db = \Config\Database::connect();
            $branch = $db->table('branches')->where('id', $branchId)->get()->getRowArray();
        }

        $branchLat = $branch['lat'] ?? null;
        $branchLng = $branch['lng'] ?? null;
        if ($branchLat === null || $branchLng === null) {
            return [
                'distance_km'   => null,
                'threshold_km'  => $threshold,
                'eligible'      => false,
                'amount'        => (float) $rate['rate_below'],
                'rate_id'       => (int) $rate['id'],
                'note'          => 'Cabang belum punya koordinat',
            ];
        }

        $distance = self::haversineKm(
            (float) $homeLat,
            (float) $homeLng,
            (float) $branchLat,
            (float) $branchLng
        );
        $distance = round($distance, 2);
        $eligible = $distance >= $threshold;
        $amount = $eligible ? (float) $rate['rate_above'] : (float) $rate['rate_below'];

        return [
            'distance_km'   => $distance,
            'threshold_km'  => $threshold,
            'eligible'      => $eligible,
            'amount'        => $amount,
            'rate_id'       => (int) $rate['id'],
            'note'          => $eligible
                ? sprintf('Jarak %.2f km ≥ %.2f km, dapat tunjangan', $distance, $threshold)
                : sprintf('Jarak %.2f km < %.2f km, tidak dapat tunjangan', $distance, $threshold),
        ];
    }

    /**
     * Recompute semua assignment untuk satu karyawan (kecuali yang punya
     * `manual_override`). Mengembalikan jumlah row yang di-upsert.
     */
    public function recomputeEmployee(int $employeeId): int
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return 0;

        $branch = null;
        if (! empty($emp['branch_id'])) {
            $branch = $db->table('branches')->where('id', $emp['branch_id'])->get()->getRowArray();
        }

        $types = $db->table('allowance_types')
            ->where('company_id', $emp['company_id'])
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($types as $type) {
            // Skip kalau ada manual override
            $existing = $db->table('employee_allowance_assignments')
                ->where('employee_id', $employeeId)
                ->where('allowance_type_id', $type['id'])
                ->get()->getRowArray();
            if ($existing && (int) $existing['manual_override'] === 1) continue;

            $result = $this->computeForEmployee($emp, $type, $branch);

            $row = [
                'company_id'        => $emp['company_id'],
                'employee_id'       => $employeeId,
                'allowance_type_id' => (int) $type['id'],
                'allowance_rate_id' => $result['rate_id'],
                'branch_id'         => $emp['branch_id'] ?? null,
                'job_grade_id'      => $emp['job_grade_id'] ?? null,
                'distance_km'       => $result['distance_km'],
                'threshold_km'      => $result['threshold_km'],
                'eligible'          => $result['eligible'] ? 1 : 0,
                'amount'            => $result['amount'],
                'note'              => $result['note'],
                'computed_at'       => $now,
                'updated_at'        => $now,
            ];

            if ($existing) {
                $db->table('employee_allowance_assignments')
                    ->where('id', $existing['id'])
                    ->update($row);
            } else {
                $row['created_at'] = $now;
                $db->table('employee_allowance_assignments')->insert($row);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Recompute semua karyawan dalam satu company. Dipakai HR pusat atau
     * cron saat master rates berubah.
     */
    public function recomputeCompany(int $companyId): int
    {
        $db = \Config\Database::connect();
        $emps = $db->table('employees')
            ->select('id')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $total = 0;
        foreach ($emps as $e) {
            $total += $this->recomputeEmployee((int) $e['id']);
        }
        return $total;
    }

    /**
     * Ambil seluruh assignment yang aktif untuk seorang karyawan. Dipakai
     * PayrollService untuk menambahkan ke earnings.
     *
     * @return array<int, array{code:string,name:string,amount:float,is_taxable:int,category:string}>
     */
    public function earningsForPayroll(int $employeeId): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('employee_allowance_assignments a')
            ->select('a.amount, a.eligible, t.code, t.name, t.is_taxable, t.category')
            ->join('allowance_types t', 't.id = a.allowance_type_id')
            ->where('a.employee_id', $employeeId)
            ->where('t.is_active', 1)
            ->where('t.deleted_at IS NULL')
            ->where('a.amount >', 0)
            ->get()->getResultArray();

        $earnings = [];
        foreach ($rows as $r) {
            $earnings[] = [
                'code'       => $r['code'],
                'name'       => $r['name'],
                'amount'     => (float) $r['amount'],
                'is_taxable' => (int) $r['is_taxable'],
                'category'   => $r['category'] === 'TRANSPORT' || $r['category'] === 'MEAL' || $r['category'] === 'HOUSING'
                    ? 'ALLOWANCE'
                    : ($r['category'] ?: 'ALLOWANCE'),
            ];
        }
        return $earnings;
    }
}