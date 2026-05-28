<?php

namespace App\Libraries;

/**
 * LeaveBalanceService
 *
 * Mengelola saldo cuti karyawan termasuk:
 *  - Prorate quota tahun pertama berdasarkan join_date
 *  - Carry-over sisa cuti ke tahun berikutnya dengan cap & expiry
 *  - Membuat baris balance otomatis kalau belum ada (idempotent)
 *  - Mengembalikan ringkasan saldo lengkap untuk UI
 *
 * Dasar hukum:
 *  - UU 13/2003 Pasal 79 ayat (5): cuti tahunan timbul setelah 12 bulan kerja,
 *    minimal 12 hari per tahun.
 *  - UU 13/2003 Pasal 84: cuti yang tidak terpakai dalam 6 bulan boleh
 *    dianggap gugur (perusahaan boleh membuat aturan internal).
 *
 * Caller utama:
 *  - `LeaveController::balance` (read)
 *  - `LeaveController::create` (ensure + remaining check)
 *  - `LeaveController::approve` (decrement)
 *  - `CheckLeaveYearEnd` command (carry-over di awal tahun)
 *  - Saat hire / promote karyawan (recalculate untuk tahun ybs)
 */
class LeaveBalanceService
{
    /**
     * Hitung quota proporsional untuk karyawan di tahun tertentu.
     * Kalau `is_prorated = 0` → return default_days penuh.
     * Kalau `is_prorated = 1` dan join_date di tahun ybs → quota dipotong
     * proporsional jumlah bulan kerja (rounded ke 1 desimal).
     *
     * Contoh: default 12 hari, join 1 Mei 2026 (tahun 2026) → 8/12 × 12 = 8 hari.
     *
     * @return array{quota: float, prorate_factor: float, months_worked: int, is_prorated: bool}
     */
    public function computeProratedQuota(array $employee, array $leaveType, int $year): array
    {
        $defaultDays = (float) ($leaveType['default_days'] ?? 0);
        $isProrated  = (int) ($leaveType['is_prorated'] ?? 1) === 1;
        $factor      = 1.0;
        $monthsWorked = 12;

        if (! $isProrated || empty($employee['join_date'])) {
            return [
                'quota' => $defaultDays,
                'prorate_factor' => $factor,
                'months_worked'  => $monthsWorked,
                'is_prorated'    => $isProrated,
            ];
        }

        $joinDate = new \DateTime($employee['join_date']);
        $yearStart = new \DateTime("{$year}-01-01");
        $yearEnd   = new \DateTime("{$year}-12-31");

        // Karyawan join setelah tahun yg dimaksud → 0 hari
        if ($joinDate > $yearEnd) {
            return ['quota' => 0.0, 'prorate_factor' => 0.0, 'months_worked' => 0, 'is_prorated' => true];
        }

        // Karyawan join sebelum tahun ini → full quota
        if ($joinDate <= $yearStart) {
            return [
                'quota' => $defaultDays,
                'prorate_factor' => 1.0,
                'months_worked'  => 12,
                'is_prorated'    => true,
            ];
        }

        // Join di tengah tahun: hitung berapa bulan tersisa
        $diff = $joinDate->diff($yearEnd);
        // Bulan tersisa = 12 - (bulan join - 1). Dibulatkan ke atas kalau hari
        // join ≤ 15 supaya bulan tsb tetap dihitung.
        $startMonth = (int) $joinDate->format('n');
        $startDay   = (int) $joinDate->format('j');
        $monthsWorked = 12 - $startMonth + 1;
        if ($startDay > 15) {
            // Kalau hire setelah pertengahan bulan, bulan itu tidak dihitung
            $monthsWorked--;
        }
        $monthsWorked = max(0, min(12, $monthsWorked));
        $factor = $monthsWorked / 12;
        $quota  = round($defaultDays * $factor, 1);

        return [
            'quota'          => $quota,
            'prorate_factor' => round($factor, 4),
            'months_worked'  => $monthsWorked,
            'is_prorated'    => true,
        ];
    }

    /**
     * Pastikan baris `leave_balances` ada untuk (employee, leave_type, year).
     * Kalau belum ada → buat baru dengan quota prorated. Kalau sudah ada →
     * jangan diutak-atik (mungkin sudah dimodifikasi HR).
     *
     * Return: row leave_balances (ada `__created` flag kalau baru dibuat).
     */
    public function ensureBalance(int $employeeId, int $leaveTypeId, int $year): ?array
    {
        $db = \Config\Database::connect();
        $existing = $db->table('leave_balances')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('period_year', $year)
            ->get()->getRowArray();
        if ($existing) return $existing;

        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        $type = $db->table('leave_types')->where('id', $leaveTypeId)->get()->getRowArray();
        if (! $emp || ! $type) return null;

        // Kalau tipe ini bukan reset_yearly atau tidak punya default_days,
        // tidak perlu balance entry.
        if ((int) ($type['reset_yearly'] ?? 1) !== 1) return null;
        if ((float) ($type['default_days'] ?? 0) <= 0) return null;

        $info = $this->computeProratedQuota($emp, $type, $year);
        $now  = date('Y-m-d H:i:s');
        $insert = [
            'company_id'    => (int) $emp['company_id'],
            'employee_id'   => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'period_year'   => $year,
            'allocated'     => $info['quota'],
            'carried_over'  => 0,
            'used'          => 0,
            'remaining'     => $info['quota'],
            'expires_at'    => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        $db->table('leave_balances')->insert($insert);
        $insert['id'] = (int) $db->insertID();
        $insert['__created'] = true;
        $insert['__prorate'] = $info;
        return $insert;
    }

    /**
     * Lakukan year-end carry-over: untuk setiap karyawan aktif × leave_type
     * dengan `is_carry_over_allowed = 1`, pindahkan sisa cuti tahun lalu
     * ke balance tahun ini sebagai `carried_over`, capped ke `carry_over_max_days`,
     * dan set `expires_at` = +N bulan dari awal tahun.
     *
     * Mengembalikan ringkasan: jumlah balance baru, jumlah hari di-carry,
     * jumlah hari hangus.
     *
     * @return array{carried: int, total_days: float, expired_days: float, balances: array<int,array>}
     */
    public function runYearEndCarryOver(int $companyId, int $fromYear, int $toYear, bool $dryRun = false): array
    {
        $db = \Config\Database::connect();

        $employees = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $types = $db->table('leave_types')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('is_carry_over_allowed', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $now = date('Y-m-d H:i:s');
        $totalCarried = 0;
        $totalCarriedDays = 0.0;
        $totalExpiredDays = 0.0;
        $details = [];

        foreach ($employees as $emp) {
            foreach ($types as $type) {
                $oldBal = $db->table('leave_balances')
                    ->where('employee_id', $emp['id'])
                    ->where('leave_type_id', $type['id'])
                    ->where('period_year', $fromYear)
                    ->get()->getRowArray();
                $oldRemaining = $oldBal ? (float) $oldBal['remaining'] : 0.0;
                if ($oldRemaining <= 0) continue;

                $cap = $type['carry_over_max_days'] !== null
                    ? (float) $type['carry_over_max_days']
                    : $oldRemaining;
                $carry = min($oldRemaining, $cap);
                $expired = $oldRemaining - $carry;
                if ($expired < 0) $expired = 0;

                $expiresAt = null;
                $expMonths = (int) ($type['carry_over_expires_in_months'] ?? 6);
                if ($expMonths > 0) {
                    $expiresAt = (new \DateTime("{$toYear}-01-01"))
                        ->modify("+{$expMonths} months")
                        ->format('Y-m-d');
                }

                $newQuotaInfo = $this->computeProratedQuota($emp, $type, $toYear);
                $newAllocated = $newQuotaInfo['quota'];
                $newRemaining = $newAllocated + $carry;

                $existing = $db->table('leave_balances')
                    ->where('employee_id', $emp['id'])
                    ->where('leave_type_id', $type['id'])
                    ->where('period_year', $toYear)
                    ->get()->getRowArray();

                $row = [
                    'company_id'    => $companyId,
                    'employee_id'   => (int) $emp['id'],
                    'leave_type_id' => (int) $type['id'],
                    'period_year'   => $toYear,
                    'allocated'     => $newAllocated,
                    'carried_over'  => $carry,
                    'used'          => 0,
                    'remaining'     => $newRemaining,
                    'expires_at'    => $expiresAt,
                    'updated_at'    => $now,
                ];

                if (! $dryRun) {
                    if ($existing) {
                        $db->table('leave_balances')->where('id', $existing['id'])->update($row);
                    } else {
                        $row['created_at'] = $now;
                        $db->table('leave_balances')->insert($row);
                    }
                }

                $totalCarried++;
                $totalCarriedDays += $carry;
                $totalExpiredDays += $expired;
                $details[] = [
                    'employee_id'  => (int) $emp['id'],
                    'employee'     => $emp['full_name'] ?? '',
                    'leave_type'   => $type['name'],
                    'old_remaining'=> $oldRemaining,
                    'carried'      => $carry,
                    'expired'      => $expired,
                    'expires_at'   => $expiresAt,
                ];
            }
        }

        return [
            'carried'      => $totalCarried,
            'total_days'   => round($totalCarriedDays, 2),
            'expired_days' => round($totalExpiredDays, 2),
            'balances'     => $details,
            'dry_run'      => $dryRun,
        ];
    }

    /**
     * Anniversary carry-over — varian carry-over yang bekerja per-karyawan
     * berdasarkan join_date, bukan tahun kalender.
     *
     * Skema:
     *   - Setiap leave_type yang `carry_over_basis = ANNIVERSARY` punya
     *     "tahun kerja" yang dimulai pada tanggal join karyawan dan
     *     berulang setiap 12 bulan.
     *   - Saat tanggal anniversary tercapai (mis. join 2023-05-15 →
     *     anniversary 2024-05-15, 2025-05-15, dst), saldo direset:
     *       sisa cuti yang lama → carried_over (capped)
     *       allocated baru     → default_days penuh
     *       expires_at         → +carry_over_expires_in_months bulan
     *   - Sebelum hari anniversary tercapai, saldo tahun kerja sebelumnya
     *     tetap berlaku.
     *
     * Sesuai UU 13/2003 Pasal 79 ayat (5): cuti tahunan timbul setelah 12
     * bulan kerja terus-menerus → mode ANNIVERSARY menerapkan strict.
     *
     * Cara kerja `period_year` saat mode ANNIVERSARY:
     *   period_year = tahun anniversary yg sedang berlaku, mis. karyawan
     *   join 2023-05-15, hari ini 2026-08-01 → period_year = 2026
     *   (karena anniversary 2026-05-15 sudah lewat). Setiap eksekusi
     *   anniversary set `anniversary_processed_at = today`.
     *
     * @param  int  $companyId
     * @param  string|null $today  override untuk testing (Y-m-d)
     * @param  bool $dryRun
     * @return array{processed:int, balances:array, carried_total_days:float, expired_total_days:float, dry_run:bool}
     */
    public function runAnniversaryCarryOver(int $companyId, ?string $today = null, bool $dryRun = false): array
    {
        $db = \Config\Database::connect();
        $today = $today ?: date('Y-m-d');
        $todayMd = substr($today, 5, 5); // 'MM-DD'
        $now = date('Y-m-d H:i:s');

        // Hanya proses leave_types dengan basis = ANNIVERSARY (kalau kolom
        // belum ada karena migration belum jalan, fallback ke kosong supaya
        // tidak error).
        $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_types'));
        if (! in_array('carry_over_basis', $cols, true)) {
            return [
                'processed' => 0, 'balances' => [],
                'carried_total_days' => 0.0, 'expired_total_days' => 0.0,
                'dry_run' => $dryRun, 'note' => 'Migration #064 belum dijalankan; kolom carry_over_basis tidak ada.',
            ];
        }

        $types = $db->table('leave_types')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('carry_over_basis', 'ANNIVERSARY')
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        if (empty($types)) {
            return [
                'processed' => 0, 'balances' => [],
                'carried_total_days' => 0.0, 'expired_total_days' => 0.0,
                'dry_run' => $dryRun,
            ];
        }

        // Ambil karyawan yang anniversary-nya hari ini (MM-DD == today).
        // Edge case: leap-year (29 Feb) — kalau today = 28 Feb di tahun
        // non-leap, kita ikut sertakan yang join_date 2/29 supaya tidak
        // skipped selamanya.
        //
        // Catatan: pakai $db->query() langsung — CI4 query builder
        // suka mangle ekspresi `DATE_FORMAT()` di clause where.
        $isLeapToday = (int) date('m', strtotime($today)) === 2 && (int) date('d', strtotime($today)) === 28
            && ! (bool) date('L', strtotime($today)); // non-leap year

        $sql = "SELECT * FROM employees
                WHERE company_id = ?
                  AND is_active = 1
                  AND deleted_at IS NULL
                  AND join_date IS NOT NULL
                  AND DATE_FORMAT(join_date, '%m-%d') = ?";
        $employees = $db->query($sql, [$companyId, $todayMd])->getResultArray();

        if ($isLeapToday) {
            $extra = $db->query($sql, [$companyId, '02-29'])->getResultArray();
            $employees = array_merge($employees, $extra);
        }

        // Skip karyawan yang join_date-nya hari ini juga (baru hire) —
        // belum capai 12 bulan kerja. UU 13/2003 mensyaratkan minimal
        // 12 bulan terus-menerus.
        $employees = array_values(array_filter($employees, function ($e) use ($today) {
            return $e['join_date'] !== $today;
        }));

        $details = [];
        $totalProcessed = 0;
        $totalCarriedDays = 0.0;
        $totalExpiredDays = 0.0;

        foreach ($employees as $emp) {
            // Hitung tahun anniversary saat ini (anniversary year):
            //   karyawan join 2023-05-15, today 2026-05-15 → anniv year 2026
            $joinYear = (int) date('Y', strtotime($emp['join_date']));
            $todayYear = (int) date('Y', strtotime($today));
            // Yang baru saja terjadi adalah anniversary tahun-todayYear
            $anniversaryYear = $todayYear;
            $tenureYears = $anniversaryYear - $joinYear;
            if ($tenureYears < 1) continue; // karyawan belum 12 bulan kerja

            foreach ($types as $type) {
                // Idempotent: kalau sudah diproses anniversary tahun ini,
                // skip supaya tidak double-carry.
                $current = $db->table('leave_balances')
                    ->where('employee_id', (int) $emp['id'])
                    ->where('leave_type_id', (int) $type['id'])
                    ->where('period_year', $anniversaryYear)
                    ->get()->getRowArray();

                if ($current && ! empty($current['anniversary_processed_at'])
                    && (string) $current['anniversary_processed_at'] === $today) {
                    continue;
                }

                // Saldo lama = balance period_year sebelumnya (tahun anniversary lalu)
                $oldBal = $db->table('leave_balances')
                    ->where('employee_id', (int) $emp['id'])
                    ->where('leave_type_id', (int) $type['id'])
                    ->where('period_year', $anniversaryYear - 1)
                    ->get()->getRowArray();

                $oldRemaining = $oldBal ? (float) $oldBal['remaining'] : 0.0;

                // Carry-over hanya jika tipe mengizinkan. Kalau tidak,
                // sisa lama hangus penuh.
                $isCarryAllowed = (int) ($type['is_carry_over_allowed'] ?? 0) === 1;
                $cap = $type['carry_over_max_days'] !== null
                    ? (float) $type['carry_over_max_days']
                    : $oldRemaining;

                $carry = $isCarryAllowed ? min($oldRemaining, $cap) : 0.0;
                $expired = max(0.0, $oldRemaining - $carry);

                $expMonths = (int) ($type['carry_over_expires_in_months'] ?? 6);
                $expiresAt = $expMonths > 0 && $carry > 0
                    ? (new \DateTime($today))->modify("+{$expMonths} months")->format('Y-m-d')
                    : null;

                // Allocated baru: untuk mode anniversary, karyawan sudah
                // ≥ 12 bulan kerja → quota penuh (tidak prorate lagi).
                // Prorate hanya berlaku di tahun pertama; setelah itu
                // selalu full quota di setiap anniversary.
                $newAllocated = (float) ($type['default_days'] ?? 0);
                $newRemaining = $newAllocated + $carry;

                $row = [
                    'company_id'                => $companyId,
                    'employee_id'               => (int) $emp['id'],
                    'leave_type_id'             => (int) $type['id'],
                    'period_year'               => $anniversaryYear,
                    'allocated'                 => $newAllocated,
                    'carried_over'              => $carry,
                    'used'                      => $current ? (float) $current['used'] : 0.0,
                    'remaining'                 => $newRemaining - ($current ? (float) $current['used'] : 0.0),
                    'expires_at'                => $expiresAt,
                    'basis'                     => 'ANNIVERSARY',
                    'anniversary_processed_at'  => $today,
                    'updated_at'                => $now,
                ];

                if (! $dryRun) {
                    if ($current) {
                        $db->table('leave_balances')->where('id', $current['id'])->update($row);
                    } else {
                        $row['created_at'] = $now;
                        $db->table('leave_balances')->insert($row);
                    }
                }

                $totalProcessed++;
                $totalCarriedDays += $carry;
                $totalExpiredDays += $expired;
                $details[] = [
                    'employee_id'      => (int) $emp['id'],
                    'employee'         => $emp['full_name'] ?? '',
                    'tenure_years'     => $tenureYears,
                    'leave_type'       => $type['name'],
                    'old_remaining'    => round($oldRemaining, 2),
                    'allocated'        => round($newAllocated, 2),
                    'carried'          => round($carry, 2),
                    'expired'          => round($expired, 2),
                    'expires_at'       => $expiresAt,
                    'anniversary_year' => $anniversaryYear,
                ];
            }
        }

        return [
            'processed'          => $totalProcessed,
            'balances'           => $details,
            'carried_total_days' => round($totalCarriedDays, 2),
            'expired_total_days' => round($totalExpiredDays, 2),
            'dry_run'            => $dryRun,
            'today'              => $today,
            'employees_with_anniversary_today' => count($employees),
        ];
    }

    /**
     * Recalculate balance untuk satu karyawan pada satu tahun.
     * Berguna kalau HR mengubah default_days di leave_types atau menggeser
     * join_date — supaya allocated re-prorate dan remaining dihitung ulang.
     *
     * Tidak menghapus baris yang sudah ada; hanya update `allocated` dan
     * `remaining = allocated + carried_over - used`.
     */
    public function recalculate(int $employeeId, int $year): int
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return 0;

        $balances = $db->table('leave_balances')
            ->where('employee_id', $employeeId)
            ->where('period_year', $year)
            ->get()->getResultArray();

        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($balances as $b) {
            $type = $db->table('leave_types')->where('id', $b['leave_type_id'])->get()->getRowArray();
            if (! $type) continue;
            $info = $this->computeProratedQuota($emp, $type, $year);
            $newAllocated = $info['quota'];
            $remaining = $newAllocated + (float) $b['carried_over'] - (float) $b['used'];

            $db->table('leave_balances')->where('id', $b['id'])->update([
                'allocated'  => $newAllocated,
                'remaining'  => $remaining,
                'updated_at' => $now,
            ]);
            $updated++;
        }
        return $updated;
    }

    /**
     * Ambil ringkasan saldo lengkap untuk satu karyawan di satu tahun,
     * disusun untuk konsumsi UI. Auto-create entry yang hilang.
     *
     * @return array<int,array>  [{leave_type, balance, prorate_info, ...}]
     */
    public function summary(int $employeeId, int $year): array
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return [];

        $types = $db->table('leave_types')
            ->where('company_id', (int) $emp['company_id'])
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->orderBy('category', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($types as $type) {
            // Skip type yg tidak reset yearly atau tidak punya default_days
            if ((int) ($type['reset_yearly'] ?? 1) !== 1) continue;
            if ((float) ($type['default_days'] ?? 0) <= 0) continue;

            $bal = $this->ensureBalance($employeeId, (int) $type['id'], $year);
            if (! $bal) continue;

            $info = $this->computeProratedQuota($emp, $type, $year);
            $today = date('Y-m-d');
            $isExpired = $bal['expires_at'] && $bal['expires_at'] < $today;

            $out[] = [
                'leave_type_id' => (int) $type['id'],
                'code'          => $type['code'],
                'name'          => $type['name'],
                'category'      => $type['category'],
                'allocated'     => (float) $bal['allocated'],
                'carried_over'  => (float) $bal['carried_over'],
                'used'          => (float) $bal['used'],
                'remaining'     => (float) $bal['remaining'],
                'expires_at'    => $bal['expires_at'],
                'is_expired'    => $isExpired,
                'is_prorated'   => $info['is_prorated'],
                'prorate_factor'=> $info['prorate_factor'],
                'months_worked' => $info['months_worked'],
                'config'        => [
                    'default_days'                  => (float) $type['default_days'],
                    'is_prorated'                   => (int) ($type['is_prorated'] ?? 1) === 1,
                    'is_carry_over_allowed'         => (int) ($type['is_carry_over_allowed'] ?? 0) === 1,
                    'carry_over_max_days'           => $type['carry_over_max_days'] !== null
                        ? (float) $type['carry_over_max_days'] : null,
                    'carry_over_expires_in_months'  => (int) ($type['carry_over_expires_in_months'] ?? 6),
                ],
            ];
        }
        return $out;
    }
}