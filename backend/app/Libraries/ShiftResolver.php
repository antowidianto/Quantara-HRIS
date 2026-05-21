<?php

namespace App\Libraries;

/**
 * ShiftResolver
 *
 * Menentukan shift yang berlaku untuk seorang karyawan pada tanggal tertentu
 * dengan urutan prioritas:
 *
 *   1. `shift_schedules` (override eksplisit per-hari)             → terkuat
 *   2. `employees.default_shift_id`                                  ↓
 *   3. `shift_pattern_assignments` paling spesifik (employee → grade
 *      → department → branch → work_location), pilih `priority` ASC
 *      kemudian gunakan `pattern_data[(date - anchor) % cycle_days]`
 *   4. `shift_assignments` paling spesifik (priority ASC) — scope yg
 *      sama dengan #3 minus employee_id
 *   5. `shifts.is_default = 1` di branch / company
 *   6. Shift aktif pertama di company (fallback paling lemah)
 *
 * Setelah shift ditemukan, resolver juga mempertimbangkan:
 *   - `shifts.working_days` (CSV ISO weekday). Kalau hari ybs tidak masuk
 *     working_days → return is_off=true.
 *   - Holiday di tabel `holidays` → return is_holiday=true (tidak override
 *     shift, hanya menandai).
 *
 * Output: array
 *   [
 *     'shift'         => array|null,   // row dari shifts (lengkap)
 *     'source'        => string,       // 'schedule' | 'employee' | 'pattern'
 *                                      //  | 'assignment' | 'shift_default'
 *                                      //  | 'fallback' | 'none'
 *     'is_off'        => bool,
 *     'is_holiday'    => bool,
 *     'holiday_name'  => string|null,
 *     'pattern_id'    => int|null,
 *     'reason'        => string|null,  // human-readable trace
 *   ]
 */
class ShiftResolver
{
    /** @var array<int,array> Cache per employee untuk satu request */
    private array $employeeCache = [];
    /** @var array<int,array> Cache shift by id */
    private array $shiftCache = [];

    public function resolve(int $employeeId, string $date): array
    {
        $emp = $this->loadEmployee($employeeId);
        if (! $emp) {
            return $this->emptyResult('Karyawan tidak ditemukan');
        }
        $companyId = (int) $emp['company_id'];
        $db = \Config\Database::connect();

        // 0) Holiday (hanya tanda, tidak menggantikan shift)
        $holiday = $db->table('holidays')
            ->groupStart()
                ->where('company_id', $companyId)
                ->orWhere('company_id IS NULL')
            ->groupEnd()
            ->where('date', $date)
            ->get()->getRowArray();

        // 1) shift_schedules (override eksplisit)
        $sched = $db->table('shift_schedules')
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->get()->getRowArray();
        if ($sched) {
            $isOff     = (int) ($sched['is_off'] ?? 0) === 1;
            $isHoliday = (int) ($sched['is_holiday'] ?? 0) === 1 || $holiday !== null;
            $shift     = $sched['shift_id'] ? $this->loadShift((int) $sched['shift_id']) : null;
            return [
                'shift'        => $shift,
                'source'       => 'schedule',
                'is_off'       => $isOff,
                'is_holiday'   => $isHoliday,
                'holiday_name' => $holiday['name'] ?? null,
                'pattern_id'   => null,
                'reason'       => 'Override jadwal harian (shift_schedules)',
            ];
        }

        // 2) employee.default_shift_id (override per-orang)
        if (! empty($emp['default_shift_id'])) {
            $shift = $this->loadShift((int) $emp['default_shift_id']);
            if ($shift) {
                $isOff = $this->isOffByWorkingDays($shift, $date);
                return [
                    'shift'        => $shift,
                    'source'       => 'employee',
                    'is_off'       => $isOff,
                    'is_holiday'   => $holiday !== null,
                    'holiday_name' => $holiday['name'] ?? null,
                    'pattern_id'   => null,
                    'reason'       => 'Default shift karyawan',
                ];
            }
        }

        // 3) shift_pattern_assignments aktif untuk employee/dept/branch/...
        $patternResult = $this->resolveFromPattern($emp, $date);
        if ($patternResult !== null) {
            $patternResult['is_holiday']   = $patternResult['is_holiday'] || $holiday !== null;
            $patternResult['holiday_name'] = $holiday['name'] ?? $patternResult['holiday_name'] ?? null;
            return $patternResult;
        }

        // 4) shift_assignments by scope
        $assignedShiftId = $this->resolveFromAssignments($emp, $date);
        if ($assignedShiftId) {
            $shift = $this->loadShift($assignedShiftId);
            if ($shift) {
                $isOff = $this->isOffByWorkingDays($shift, $date);
                return [
                    'shift'        => $shift,
                    'source'       => 'assignment',
                    'is_off'       => $isOff,
                    'is_holiday'   => $holiday !== null,
                    'holiday_name' => $holiday['name'] ?? null,
                    'pattern_id'   => null,
                    'reason'       => 'Shift sesuai aturan scope (shift_assignments)',
                ];
            }
        }

        // 5) shifts.is_default = 1 untuk branch atau company
        $defaultShift = $this->resolveDefaultShift($emp);
        if ($defaultShift) {
            $isOff = $this->isOffByWorkingDays($defaultShift, $date);
            return [
                'shift'        => $defaultShift,
                'source'       => 'shift_default',
                'is_off'       => $isOff,
                'is_holiday'   => $holiday !== null,
                'holiday_name' => $holiday['name'] ?? null,
                'pattern_id'   => null,
                'reason'       => 'Shift default scope/company',
            ];
        }

        // 6) Fallback: shift aktif pertama di company
        $fallback = $db->table('shifts')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        if ($fallback) {
            $isOff = $this->isOffByWorkingDays($fallback, $date);
            return [
                'shift'        => $fallback,
                'source'       => 'fallback',
                'is_off'       => $isOff,
                'is_holiday'   => $holiday !== null,
                'holiday_name' => $holiday['name'] ?? null,
                'pattern_id'   => null,
                'reason'       => 'Fallback: shift aktif pertama di company',
            ];
        }

        return $this->emptyResult('Tidak ada shift terdaftar di company');
    }

    /**
     * Resolve banyak employee × tanggal sekaligus untuk roster matrix.
     * Lebih efisien daripada memanggil resolve() satu-satu karena kita
     * bisa share cache employee/shift/pattern.
     *
     * @param int[]    $employeeIds
     * @param string[] $dates       (Y-m-d)
     * @return array<int,array<string,array>>  [employeeId][date] = result
     */
    public function resolveMany(array $employeeIds, array $dates): array
    {
        $out = [];
        foreach ($employeeIds as $eid) {
            $eid = (int) $eid;
            $out[$eid] = [];
            foreach ($dates as $d) {
                $out[$eid][$d] = $this->resolve($eid, $d);
            }
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────

    private function loadEmployee(int $id): ?array
    {
        if (isset($this->employeeCache[$id])) return $this->employeeCache[$id];
        $row = \Config\Database::connect()
            ->table('employees')->where('id', $id)->get()->getRowArray();
        $this->employeeCache[$id] = $row ?: null;
        return $this->employeeCache[$id];
    }

    private function loadShift(int $id): ?array
    {
        if (isset($this->shiftCache[$id])) return $this->shiftCache[$id];
        $row = \Config\Database::connect()
            ->table('shifts')
            ->where('id', $id)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        $this->shiftCache[$id] = $row ?: null;
        return $this->shiftCache[$id];
    }

    private function emptyResult(string $reason): array
    {
        return [
            'shift'        => null,
            'source'       => 'none',
            'is_off'       => false,
            'is_holiday'   => false,
            'holiday_name' => null,
            'pattern_id'   => null,
            'reason'       => $reason,
        ];
    }

    /**
     * Cek apakah `shifts.working_days` (CSV "1,2,3,4,5") menutup hari ybs.
     * Kalau working_days kosong → tidak ada batasan (semua hari).
     */
    private function isOffByWorkingDays(array $shift, string $date): bool
    {
        $wd = trim((string) ($shift['working_days'] ?? ''));
        if ($wd === '') return false;
        $allowed = array_filter(array_map('intval', explode(',', $wd)));
        if (empty($allowed)) return false;
        $dow = (int) date('N', strtotime($date)); // 1..7
        return ! in_array($dow, $allowed, true);
    }

    /**
     * Cari pattern assignment paling spesifik yang aktif untuk employee+date.
     * Spesifisitas (kecil = lebih spesifik):
     *   - employee_id terisi → 1
     *   - job_grade_id terisi → 2
     *   - department_id terisi → 3
     *   - branch_id terisi → 4
     *   - work_location_id terisi → 5
     *   - tidak ada scope (company-wide) → 6
     * Kalau seri pakai `priority` ASC.
     */
    private function resolveFromPattern(array $emp, string $date): ?array
    {
        $db = \Config\Database::connect();
        $companyId = (int) $emp['company_id'];

        $builder = $db->table('shift_pattern_assignments spa')
            ->select('spa.*')
            ->where('spa.company_id', $companyId)
            ->where('spa.is_active', 1)
            ->where('spa.date_from <=', $date)
            ->groupStart()
                ->where('spa.date_to IS NULL')
                ->orWhere('spa.date_to >=', $date)
            ->groupEnd()
            ->groupStart()
                ->where('spa.employee_id', (int) $emp['id'])
                ->orWhere('spa.employee_id IS NULL')
            ->groupEnd();

        $rows = $builder->orderBy('spa.priority', 'ASC')->get()->getResultArray();
        if (empty($rows)) return null;

        $best = null;
        $bestScore = 999;
        foreach ($rows as $r) {
            // Filter scope yang tidak match (selain employee_id yg sudah di-OR di atas)
            if (! empty($r['job_grade_id'])    && (int) $r['job_grade_id']    !== (int) ($emp['job_grade_id']    ?? 0)) continue;
            if (! empty($r['department_id'])   && (int) $r['department_id']   !== (int) ($emp['department_id']   ?? 0)) continue;
            if (! empty($r['branch_id'])       && (int) $r['branch_id']       !== (int) ($emp['branch_id']       ?? 0)) continue;
            if (! empty($r['work_location_id'])&& (int) $r['work_location_id']!== (int) ($emp['work_location_id']?? 0)) continue;

            $score = 6;
            if (! empty($r['employee_id']))     $score = min($score, 1);
            if (! empty($r['job_grade_id']))    $score = min($score, 2);
            if (! empty($r['department_id']))   $score = min($score, 3);
            if (! empty($r['branch_id']))       $score = min($score, 4);
            if (! empty($r['work_location_id']))$score = min($score, 5);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        if (! $best) return null;

        $pattern = $db->table('shift_patterns')
            ->where('id', (int) $best['pattern_id'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $pattern) return null;

        $cycle = max(1, (int) $pattern['cycle_days']);
        $data  = json_decode((string) $pattern['pattern_data'], true) ?: [];

        $anchor = $best['anchor_date'] ?: $pattern['created_at'] ?? $date;
        $dayDiff = (int) floor((strtotime($date) - strtotime((string) $anchor)) / 86400);
        // PHP modulo bisa negatif untuk angka negatif; normalize.
        $idx = (($dayDiff % $cycle) + $cycle) % $cycle;
        $slot = $data[$idx] ?? null;

        $isOff     = false;
        $isHoliday = false;
        $shift     = null;
        if (is_array($slot)) {
            $isOff     = ! empty($slot['is_off']);
            $isHoliday = ! empty($slot['is_holiday']);
            if (! empty($slot['shift_id'])) {
                $shift = $this->loadShift((int) $slot['shift_id']);
            }
        }

        return [
            'shift'        => $shift,
            'source'       => 'pattern',
            'is_off'       => $isOff,
            'is_holiday'   => $isHoliday,
            'holiday_name' => null,
            'pattern_id'   => (int) $pattern['id'],
            'reason'       => "Pola {$pattern['name']} (hari ke-" . ($idx + 1) . " dari siklus {$cycle})",
        ];
    }

    /**
     * Pilih shift_id paling spesifik dari shift_assignments aktif untuk employee.
     */
    private function resolveFromAssignments(array $emp, string $date): ?int
    {
        $db = \Config\Database::connect();
        $rows = $db->table('shift_assignments')
            ->where('company_id', (int) $emp['company_id'])
            ->where('is_active', 1)
            ->groupStart()
                ->where('effective_from IS NULL')
                ->orWhere('effective_from <=', $date)
            ->groupEnd()
            ->groupStart()
                ->where('effective_to IS NULL')
                ->orWhere('effective_to >=', $date)
            ->groupEnd()
            ->orderBy('priority', 'ASC')
            ->get()->getResultArray();

        if (empty($rows)) return null;

        $best = null;
        $bestScore = 999;
        foreach ($rows as $r) {
            if (! empty($r['job_grade_id'])     && (int) $r['job_grade_id']     !== (int) ($emp['job_grade_id']     ?? 0)) continue;
            if (! empty($r['department_id'])    && (int) $r['department_id']    !== (int) ($emp['department_id']    ?? 0)) continue;
            if (! empty($r['branch_id'])        && (int) $r['branch_id']        !== (int) ($emp['branch_id']        ?? 0)) continue;
            if (! empty($r['work_location_id']) && (int) $r['work_location_id'] !== (int) ($emp['work_location_id'] ?? 0)) continue;

            $score = 5;
            if (! empty($r['job_grade_id']))     $score = min($score, 1);
            if (! empty($r['department_id']))    $score = min($score, 2);
            if (! empty($r['branch_id']))        $score = min($score, 3);
            if (! empty($r['work_location_id'])) $score = min($score, 4);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        return $best ? (int) $best['shift_id'] : null;
    }

    /**
     * Pilih shifts.is_default=1 dengan prioritas branch/work_location → company.
     */
    private function resolveDefaultShift(array $emp): ?array
    {
        $db = \Config\Database::connect();
        $candidates = $db->table('shifts')
            ->where('company_id', (int) $emp['company_id'])
            ->where('is_active', 1)
            ->where('is_default', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        if (empty($candidates)) return null;

        // Ranking: scope yg cocok dapat skor lebih kecil = lebih disukai.
        $best = null;
        $bestScore = 999;
        foreach ($candidates as $c) {
            $score = 4;
            if (! empty($c['work_location_id'])) {
                if ((int) $c['work_location_id'] !== (int) ($emp['work_location_id'] ?? 0)) continue;
                $score = 1;
            }
            if (! empty($c['branch_id'])) {
                if ((int) $c['branch_id'] !== (int) ($emp['branch_id'] ?? 0)) continue;
                $score = min($score, 2);
            }
            if (! empty($c['department_id'])) {
                if ((int) $c['department_id'] !== (int) ($emp['department_id'] ?? 0)) continue;
                $score = min($score, 3);
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        return $best;
    }
}