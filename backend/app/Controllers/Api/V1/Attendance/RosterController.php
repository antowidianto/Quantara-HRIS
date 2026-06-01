<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * RosterController
 *
 * Endpoint untuk melihat & mengelola jadwal shift karyawan dalam bentuk
 * matriks (Employee × Date), serta generator pola siklus → shift_schedules.
 *
 * Endpoints:
 *   GET  /roster?branch_id=&department_id=&start_date=&end_date=&q=
 *        Matrix view: list employee + map { date: resolved_shift }.
 *   POST /roster/generate
 *        Body: { pattern_id, scope: {employee_ids?, branch_id?, ...},
 *                start_date, end_date, anchor_date?, overwrite?, dry_run? }
 *        Apply pattern ke shift_schedules dalam rentang tanggal.
 *   POST /roster/bulk-update
 *        Body: { entries: [{employee_id, work_date, shift_id?, is_off?, is_holiday?}] }
 *        Multi-row upsert; cocok untuk drag-drop di kalender.
 *   DELETE /roster/clear
 *        Body: { employee_ids[], start_date, end_date }
 *        Hapus shift_schedules dalam rentang.
 */
class RosterController extends ApiController
{
    public function index()
    {
        $startDate = (string) ($this->request->getGet('start_date') ?? date('Y-m-01'));
        $endDate   = (string) ($this->request->getGet('end_date')   ?? date('Y-m-t'));
        if (! $this->validRange($startDate, $endDate)) {
            return $this->fail('Rentang tanggal tidak valid (max 92 hari)', 422);
        }

        $branchId = (int) ($this->request->getGet('branch_id') ?? 0);
        $deptId   = (int) ($this->request->getGet('department_id') ?? 0);
        $wlId     = (int) ($this->request->getGet('work_location_id') ?? 0);
        $q        = trim((string) $this->request->getGet('q'));
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = min(200, max(10, (int) ($this->request->getGet('per_page') ?? 50)));

        $db = \Config\Database::connect();
        $tenant = $this->tenant();

        // Branch isolation: HR cabang hanya bisa lihat cabangnya, kecuali
        // punya permission `attendance.view_all_branches` atau super admin.
        if (! $tenant->isSuperAdmin()
            && ! $tenant->hasPermission('attendance.view_all_branches')
            && $branchId > 0
            && $branchId !== $tenant->getBranchId()
        ) {
            return $this->fail('Akses lintas cabang tidak diizinkan', 403, 'CROSS_BRANCH_DENIED');
        }

        // Build employee list dengan filter scope
        $empBuilder = $db->table('employees e')
            ->select('e.id, e.employee_number, e.full_name, e.branch_id, e.department_id, e.work_location_id, e.job_grade_id, e.default_shift_id, b.name AS branch_name, d.name AS department_name')
            ->join('branches b', 'b.id = e.branch_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL');
        $tenant->applyAttendanceScope($empBuilder, 'e.company_id', 'e.branch_id');
        if ($branchId) $empBuilder->where('e.branch_id', $branchId);
        if ($deptId)   $empBuilder->where('e.department_id', $deptId);
        if ($wlId)     $empBuilder->where('e.work_location_id', $wlId);
        if ($q !== '') {
            $empBuilder->groupStart()
                ->like('e.full_name', $q)
                ->orLike('e.employee_number', $q)
                ->groupEnd();
        }
        $total = $empBuilder->countAllResults(false);
        $employees = $empBuilder->orderBy('e.full_name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Generate list tanggal
        $dates = [];
        $cursor = strtotime($startDate);
        $endTs  = strtotime($endDate);
        while ($cursor <= $endTs) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor += 86400;
        }

        // Resolve shift untuk semua employee × date
        $resolver = Services::shiftResolver(false);
        $matrix = [];
        foreach ($employees as $e) {
            $row = ['employee' => $e, 'days' => []];
            foreach ($dates as $d) {
                $r = $resolver->resolve((int) $e['id'], $d);
                $row['days'][$d] = $this->compactCell($r);
            }
            $matrix[] = $row;
        }

        return $this->ok([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'dates'      => $dates,
            'rows'       => $matrix,
            'meta'       => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    /**
     * Apply pattern ke `shift_schedules` dalam rentang tanggal.
     * Scope:
     *   - employee_ids: array spesifik
     *   - branch_id / department_id / work_location_id / job_grade_id
     *   - kalau semua kosong → tolak (defensive)
     *
     * Param penting:
     *   overwrite=true  → replace shift_schedules yang sudah ada
     *   overwrite=false → skip yang sudah ada (default)
     *   dry_run=true    → tidak menulis, hanya kembalikan preview hitungan
     */
    public function generate()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'pattern_id' => 'required|integer',
            'start_date' => 'required|valid_date',
            'end_date'   => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $startDate = $data['start_date'];
        $endDate   = $data['end_date'];
        if (! $this->validRange($startDate, $endDate, 366)) {
            return $this->fail('Rentang tanggal tidak valid (max 366 hari)', 422);
        }

        $db = \Config\Database::connect();
        $pattern = $db->table('shift_patterns')
            ->where('id', (int) $data['pattern_id'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $pattern) return $this->fail('Pola tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $pattern['company_id'] !== $companyId) {
            return $this->fail('Pola di luar perusahaan Anda', 403);
        }

        $employees = $this->resolveScopedEmployees($companyId, $data);
        if (empty($employees)) {
            return $this->fail('Tidak ada karyawan yang cocok dengan scope yang diberikan', 422, 'NO_TARGET_EMPLOYEES');
        }

        $cycle = max(1, (int) $pattern['cycle_days']);
        $patternData = json_decode((string) $pattern['pattern_data'], true) ?: [];
        $anchor = $data['anchor_date'] ?? $startDate;
        $overwrite = ! empty($data['overwrite']);
        $dryRun    = ! empty($data['dry_run']);

        $now = date('Y-m-d H:i:s');
        $dates = [];
        $cursor = strtotime($startDate);
        $endTs  = strtotime($endDate);
        while ($cursor <= $endTs) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor += 86400;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $offDays = 0;

        if (! $dryRun) {
            $db->transStart();
        }

        foreach ($employees as $emp) {
            foreach ($dates as $d) {
                $diff = (int) floor((strtotime($d) - strtotime($anchor)) / 86400);
                $idx  = (($diff % $cycle) + $cycle) % $cycle;
                $slot = $patternData[$idx] ?? null;

                $shiftId   = is_array($slot) && ! empty($slot['shift_id']) ? (int) $slot['shift_id'] : null;
                $isOff     = is_array($slot) && ! empty($slot['is_off']) ? 1 : 0;
                $isHoliday = is_array($slot) && ! empty($slot['is_holiday']) ? 1 : 0;
                if ($isOff) $offDays++;

                $existing = $db->table('shift_schedules')
                    ->where('employee_id', (int) $emp['id'])
                    ->where('work_date', $d)
                    ->get()->getRowArray();

                if ($existing && ! $overwrite) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $existing ? $updated++ : $created++;
                    continue;
                }

                $row = [
                    'company_id'  => (int) $emp['company_id'],
                    'employee_id' => (int) $emp['id'],
                    'shift_id'    => $shiftId,
                    'work_date'   => $d,
                    'is_off'      => $isOff,
                    'is_holiday'  => $isHoliday,
                    'updated_at'  => $now,
                ];
                if ($existing) {
                    $db->table('shift_schedules')->where('id', $existing['id'])->update($row);
                    $updated++;
                } else {
                    $row['created_at'] = $now;
                    $db->table('shift_schedules')->insert($row);
                    $created++;
                }
            }
        }

        if (! $dryRun) {
            $db->transComplete();
        }

        $summary = [
            'pattern_id'   => (int) $pattern['id'],
            'pattern_name' => $pattern['name'],
            'employees'    => count($employees),
            'days'         => count($dates),
            'created'      => $created,
            'updated'      => $updated,
            'skipped'      => $skipped,
            'off_days'     => $offDays,
            'overwrite'    => $overwrite,
            'dry_run'      => $dryRun,
        ];

        if (! $dryRun) {
            Services::audit()
                ->forModule('ROSTER')
                ->log(
                    action: 'roster.generate',
                    entity: 'shift_pattern',
                    entityId: (int) $pattern['id'],
                    after: $summary,
                    description: "Generate roster dari pola '{$pattern['name']}' "
                        . count($employees) . ' karyawan × ' . count($dates) . ' hari',
                    severity: 'NOTICE'
                );
        }

        return $this->ok($summary, $dryRun ? 'Preview generate (dry run)' : 'Roster berhasil di-generate');
    }

    /**
     * Bulk upsert shift_schedules dari editor matrix.
     * Body: { entries: [{employee_id, work_date, shift_id?, is_off?, is_holiday?}, ...] }
     */
    public function bulkUpdate()
    {
        $data = $this->getJsonInput();
        $entries = $data['entries'] ?? [];
        if (! is_array($entries) || empty($entries)) {
            return $this->fail('entries kosong', 422);
        }
        if (count($entries) > 5000) {
            return $this->fail('Maksimal 5000 entry per request', 422);
        }

        $db = \Config\Database::connect();
        $tenant = $this->tenant();
        $now = date('Y-m-d H:i:s');
        $touched = 0;

        $db->transStart();
        foreach ($entries as $e) {
            if (empty($e['employee_id']) || empty($e['work_date'])) continue;
            $emp = $db->table('employees')->where('id', (int) $e['employee_id'])->get()->getRowArray();
            if (! $emp) continue;
            if (! $tenant->canAccessBranch(
                (int) $emp['company_id'],
                isset($emp['branch_id']) ? (int) $emp['branch_id'] : null
            )) {
                continue;
            }

            $existing = $db->table('shift_schedules')
                ->where('employee_id', (int) $emp['id'])
                ->where('work_date', $e['work_date'])
                ->get()->getRowArray();

            $row = [
                'company_id'  => (int) $emp['company_id'],
                'employee_id' => (int) $emp['id'],
                'shift_id'    => isset($e['shift_id']) && $e['shift_id'] !== '' ? (int) $e['shift_id'] : null,
                'work_date'   => $e['work_date'],
                'is_off'      => ! empty($e['is_off']) ? 1 : 0,
                'is_holiday'  => ! empty($e['is_holiday']) ? 1 : 0,
                'updated_at'  => $now,
            ];
            if ($existing) {
                $db->table('shift_schedules')->where('id', $existing['id'])->update($row);
            } else {
                $row['created_at'] = $now;
                $db->table('shift_schedules')->insert($row);
            }
            $touched++;
        }
        $db->transComplete();

        Services::audit()
            ->forModule('ROSTER')
            ->log(
                action: 'roster.bulk_update',
                entity: 'shift_schedule',
                entityId: 0,
                after: ['count' => $touched],
                description: "Bulk update roster: {$touched} entries"
            );
        return $this->ok(['count' => $touched], 'Roster diperbarui');
    }

    /**
     * Hapus jadwal di rentang tanggal (mis. mau ulang generate).
     */
    public function clear()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'start_date'   => 'required|valid_date',
            'end_date'     => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $employeeIds = array_filter(array_map('intval', (array) ($data['employee_ids'] ?? [])));
        if (empty($employeeIds)) {
            return $this->fail('employee_ids wajib diisi', 422);
        }
        if (! $this->validRange($data['start_date'], $data['end_date'], 366)) {
            return $this->fail('Rentang tanggal tidak valid', 422);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $deleted = $db->table('shift_schedules')
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date >=', $data['start_date'])
            ->where('work_date <=', $data['end_date'])
            ->delete();

        Services::audit()
            ->forModule('ROSTER')
            ->log(
                action: 'roster.clear',
                entity: 'shift_schedule',
                entityId: 0,
                after: ['affected' => $db->affectedRows(), 'employees' => count($employeeIds)],
                description: 'Clear roster ' . $data['start_date'] . ' s/d ' . $data['end_date'],
                severity: 'WARNING'
            );

        return $this->ok([
            'deleted' => $db->affectedRows(),
        ], 'Roster dihapus');
    }

    // ────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────

    private function validRange(string $start, string $end, int $maxDays = 92): bool
    {
        if (strtotime($start) === false || strtotime($end) === false) return false;
        if (strtotime($end) < strtotime($start)) return false;
        $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
        return $days <= $maxDays;
    }

    /**
     * Ambil daftar employee dari scope yang dikirim klien.
     * Prioritas: kalau employee_ids dikirim → pakai itu. Kalau tidak,
     * gabungkan filter branch/dept/wl/grade.
     */
    private function resolveScopedEmployees(int $companyId, array $scope): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL');

        $explicit = array_filter(array_map('intval', (array) ($scope['employee_ids'] ?? [])));
        if (! empty($explicit)) {
            $builder->whereIn('id', $explicit);
        } else {
            if (! empty($scope['branch_id']))        $builder->where('branch_id', (int) $scope['branch_id']);
            if (! empty($scope['department_id']))    $builder->where('department_id', (int) $scope['department_id']);
            if (! empty($scope['work_location_id']))$builder->where('work_location_id', (int) $scope['work_location_id']);
            if (! empty($scope['job_grade_id']))    $builder->where('job_grade_id', (int) $scope['job_grade_id']);
        }
        return $builder->get()->getResultArray();
    }

    /**
     * Ringkas hasil resolver jadi cell ringan untuk JSON ke FE.
     */
    private function compactCell(array $r): array
    {
        return [
            'shift_id'   => $r['shift']['id']   ?? null,
            'shift_code' => $r['shift']['code'] ?? null,
            'shift_name' => $r['shift']['name'] ?? null,
            'start_time' => $r['shift']['start_time'] ?? null,
            'end_time'   => $r['shift']['end_time']   ?? null,
            'color'      => $r['shift']['color'] ?? null,
            'source'     => $r['source'],
            'is_off'     => (bool) $r['is_off'],
            'is_holiday' => (bool) $r['is_holiday'],
            'holiday_name' => $r['holiday_name'] ?? null,
            'pattern_id' => $r['pattern_id'] ?? null,
            'reason'     => $r['reason'] ?? null,
        ];
    }
}