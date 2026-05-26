<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * ExitInterviewService — wawancara terstruktur saat karyawan resign / PHK
 * (Sprint 4 / OFF-1).
 *
 * Tanggung jawab:
 *   1. Create — link ke resignation_requests ATAU termination_requests
 *   2. Update DRAFT — fill data sebelum dikunci
 *   3. Complete — DRAFT → COMPLETED, set completed_at + would_rehire
 *   4. Skip — DRAFT → SKIPPED (kalau karyawan tidak hadir)
 *   5. Analytics — aggregate untuk HR insights:
 *      - Top primary reasons (turnover root causes)
 *      - Avg satisfaction & recommend score (eNPS)
 *      - Department breakdown
 *      - Tenure-bucketed analysis
 *
 * Use case:
 *   - HR conduct sesi setelah HR approve resignation → POST /exit-interviews
 *     dengan resignation_id, mode=IN_PERSON
 *   - Karyawan diminta self-fill via link → mode=SELF_FILL, status DRAFT
 *     sampai karyawan submit
 *   - Setelah COMPLETED, data masuk analytics dashboard
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-1.
 */
class ExitInterviewService
{
    public const MODES = ['IN_PERSON', 'VIDEO', 'PHONE', 'WRITTEN', 'SELF_FILL'];
    public const STATUSES = ['DRAFT', 'COMPLETED', 'SKIPPED'];
    public const REHIRE_OPTIONS = ['YES', 'NO', 'CONDITIONAL', 'UNKNOWN'];

    /**
     * Common primary_reason codes — frontend bisa display sebagai dropdown.
     * Sumber: Gallup / SHRM exit interview research benchmarks.
     */
    public const PRIMARY_REASON_OPTIONS = [
        'BETTER_OFFER'        => 'Tawaran kerja lebih baik (gaji/benefit)',
        'CAREER_GROWTH'       => 'Tidak ada peluang pengembangan karir',
        'WORK_LIFE_BALANCE'   => 'Work-life balance tidak seimbang',
        'MANAGEMENT_ISSUE'    => 'Masalah dengan manajemen / atasan',
        'COMPENSATION'        => 'Kompensasi kurang kompetitif',
        'WORK_ENVIRONMENT'    => 'Budaya / lingkungan kerja tidak cocok',
        'RELOCATION'          => 'Pindah domisili / keluarga',
        'EDUCATION'           => 'Lanjut pendidikan',
        'HEALTH'              => 'Alasan kesehatan',
        'STARTUP'             => 'Membuka usaha sendiri',
        'RETIREMENT'          => 'Pensiun',
        'PERSONAL'            => 'Alasan pribadi (tidak spesifik)',
        'OTHER'               => 'Lainnya',
    ];

    /**
     * Buat exit interview baru. Wajib resignation_id ATAU termination_id
     * (mutually exclusive). Idempotent: kalau sudah ada untuk pasangan
     * (employee_id + resignation/termination_id), return existing.
     */
    public function create(array $data, int $userId): array
    {
        $db = Database::connect();

        $employeeId = (int) ($data['employee_id'] ?? 0);
        $resignationId = (int) ($data['resignation_id'] ?? 0) ?: null;
        $terminationId = (int) ($data['termination_id'] ?? 0) ?: null;

        if (! $resignationId && ! $terminationId) {
            throw new \InvalidArgumentException(
                'Wajib link ke resignation_id ATAU termination_id'
            );
        }
        if ($resignationId && $terminationId) {
            throw new \InvalidArgumentException(
                'resignation_id dan termination_id tidak boleh dua-duanya'
            );
        }

        // Resolve employee_id dari resignation/termination kalau tidak di-pass
        if ($employeeId <= 0 && $resignationId) {
            $r = $db->table('resignation_requests')->where('id', $resignationId)
                ->get()->getRowArray();
            if ($r) $employeeId = (int) $r['employee_id'];
        }
        if ($employeeId <= 0 && $terminationId) {
            $r = $db->table('termination_requests')->where('id', $terminationId)
                ->get()->getRowArray();
            if ($r) $employeeId = (int) $r['employee_id'];
        }

        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }

        $mode = (string) ($data['mode'] ?? 'IN_PERSON');
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(
                'mode tidak valid. Pilihan: ' . implode(', ', self::MODES)
            );
        }

        // Idempotent
        $existing = $db->table('exit_interviews')
            ->where('employee_id', $employeeId)
            ->where('deleted_at IS NULL');
        if ($resignationId) $existing->where('resignation_id', $resignationId);
        if ($terminationId) $existing->where('termination_id', $terminationId);
        $existingRow = $existing->get()->getRowArray();
        if ($existingRow) {
            return $this->load((int) $existingRow['id']);
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => (int) $emp['company_id'],
            'employee_id'     => $employeeId,
            'resignation_id'  => $resignationId,
            'termination_id'  => $terminationId,
            'mode'            => $mode,
            'conducted_by'    => $mode === 'SELF_FILL' ? null : $userId,
            'status'          => 'DRAFT',
            'would_rehire'    => 'UNKNOWN',
            'created_by'      => $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('exit_interviews')->insert($insert);
        $id = (int) $db->insertID();

        try {
            Services::audit()->forModule('OFFBOARDING')->logCreate(
                'exit_interview', $id, $insert,
                "Buat exit interview untuk {$emp['full_name']} (mode: {$mode})"
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Update field exit interview (DRAFT). Validasi range untuk score (1-5/1-10).
     */
    public function update(int $id, array $data, int $userId): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException(
                "Hanya DRAFT yang bisa di-edit. Current: {$row['status']}"
            );
        }

        $update = [];
        $allowed = [
            'mode', 'would_rehire', 'primary_reason', 'satisfaction_score',
            'recommend_score', 'score_compensation', 'score_management',
            'score_work_environment', 'score_career_growth',
            'score_work_life_balance', 'score_team_culture',
            'most_liked', 'most_disliked', 'suggestions', 'additional_notes',
        ];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }

        // Validasi enums & ranges
        if (isset($update['mode']) && ! in_array($update['mode'], self::MODES, true)) {
            throw new \InvalidArgumentException('mode tidak valid');
        }
        if (isset($update['would_rehire']) && ! in_array($update['would_rehire'], self::REHIRE_OPTIONS, true)) {
            throw new \InvalidArgumentException(
                'would_rehire tidak valid. Pilihan: ' . implode(', ', self::REHIRE_OPTIONS)
            );
        }

        // Score 1-10 untuk satisfaction & recommend
        foreach (['satisfaction_score', 'recommend_score'] as $f) {
            if (isset($update[$f]) && $update[$f] !== null) {
                $val = (int) $update[$f];
                if ($val < 1 || $val > 10) {
                    throw new \InvalidArgumentException("{$f} harus 1-10");
                }
                $update[$f] = $val;
            }
        }
        // Score 1-5 untuk Likert detail
        $likertFields = [
            'score_compensation', 'score_management', 'score_work_environment',
            'score_career_growth', 'score_work_life_balance', 'score_team_culture',
        ];
        foreach ($likertFields as $f) {
            if (isset($update[$f]) && $update[$f] !== null) {
                $val = (int) $update[$f];
                if ($val < 1 || $val > 5) {
                    throw new \InvalidArgumentException("{$f} harus 1-5 (Likert)");
                }
                $update[$f] = $val;
            }
        }

        // Secondary reasons array → JSON
        if (array_key_exists('secondary_reasons', $data) && is_array($data['secondary_reasons'])) {
            $update['secondary_reasons_json'] = json_encode(
                array_values($data['secondary_reasons']),
                JSON_UNESCAPED_UNICODE
            );
        }

        if (empty($update)) {
            throw new \InvalidArgumentException('Tidak ada field yang di-update');
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('exit_interviews')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('OFFBOARDING')->logUpdate(
                'exit_interview', $id, $row, $update
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Lock interview as COMPLETED. Wajib ada minimum: would_rehire & primary_reason.
     */
    public function complete(int $id, int $userId): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException(
                "Hanya DRAFT yang bisa di-complete. Current: {$row['status']}"
            );
        }
        if (empty($row['would_rehire']) || $row['would_rehire'] === 'UNKNOWN') {
            throw new \RuntimeException(
                'would_rehire wajib diisi sebelum complete (YES/NO/CONDITIONAL)'
            );
        }
        if (empty($row['primary_reason'])) {
            throw new \RuntimeException('primary_reason wajib diisi sebelum complete');
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'       => 'COMPLETED',
            'completed_at' => $now,
            'updated_at'   => $now,
        ];
        // Set conducted_at kalau belum
        if (empty($row['conducted_at'])) {
            $update['conducted_at'] = $now;
        }
        $db->table('exit_interviews')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'exit_interview.complete',
                entity: 'exit_interview',
                entityId: $id,
                description: "Exit interview #{$id} completed (would_rehire: {$row['would_rehire']}, "
                    . "primary: {$row['primary_reason']})",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Skip interview (karyawan tidak hadir / refuse). Status DRAFT → SKIPPED.
     */
    public function skip(int $id, int $userId, string $reason): array
    {
        if (strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Alasan skip minimum 5 karakter');
        }
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya DRAFT yang bisa di-skip. Current: {$row['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('exit_interviews')->where('id', $id)->update([
            'status'           => 'SKIPPED',
            'additional_notes' => '[SKIPPED] ' . $reason
                . (! empty($row['additional_notes']) ? "\n\n--- Notes lama ---\n" . $row['additional_notes'] : ''),
            'updated_at'       => $now,
        ]);

        try {
            Services::audit()->forModule('OFFBOARDING')->log(
                action: 'exit_interview.skip',
                entity: 'exit_interview',
                entityId: $id,
                description: "Exit interview #{$id} di-skip: " . mb_substr($reason, 0, 200),
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * List exit interviews dengan filter.
     */
    public function listInterviews(array $filters = []): array
    {
        $db = Database::connect();
        $b = $db->table('exit_interviews ei')
            ->select('ei.*, e.full_name AS employee_name, e.employee_number, '
                . 'e.join_date, e.resign_date, '
                . 'd.name AS department_name, p.name AS position_name')
            ->join('employees e', 'e.id = ei.employee_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('ei.deleted_at IS NULL');

        if (! empty($filters['company_id'])) {
            $b->where('ei.company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['employee_id'])) {
            $b->where('ei.employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $b->where('ei.status', $filters['status']);
        }
        if (! empty($filters['would_rehire'])) {
            $b->where('ei.would_rehire', $filters['would_rehire']);
        }
        if (! empty($filters['primary_reason'])) {
            $b->where('ei.primary_reason', $filters['primary_reason']);
        }
        if (! empty($filters['date_from'])) {
            $b->where('ei.completed_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $b->where('ei.completed_at <=', $filters['date_to'] . ' 23:59:59');
        }

        $b->orderBy('ei.created_at', 'DESC');

        $perPage = (int) ($filters['per_page'] ?? 0);
        $page    = (int) ($filters['page'] ?? 1);
        if ($perPage > 0) {
            $total = (clone $b)->countAllResults(false);
            $rows = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
            return [
                'rows' => array_map([$this, 'decodeRow'], $rows),
                'total' => $total,
                'page' => $page, 'per_page' => $perPage,
            ];
        }
        return ['rows' => array_map([$this, 'decodeRow'], $b->get()->getResultArray())];
    }

    /**
     * Analytics aggregator — return summary untuk HR dashboard.
     *
     * @return array{
     *   total_completed: int, would_rehire_breakdown: array,
     *   primary_reasons: array, avg_satisfaction: float, avg_recommend: float,
     *   avg_likerts: array, by_department: array,
     * }
     */
    public function analytics(int $companyId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $db = Database::connect();
        $b = $db->table('exit_interviews ei')
            ->where('ei.company_id', $companyId)
            ->where('ei.status', 'COMPLETED')
            ->where('ei.deleted_at IS NULL');
        if ($dateFrom) $b->where('ei.completed_at >=', $dateFrom . ' 00:00:00');
        if ($dateTo)   $b->where('ei.completed_at <=', $dateTo   . ' 23:59:59');

        $rows = $b->get()->getResultArray();
        $total = count($rows);
        if ($total === 0) {
            return [
                'total_completed'        => 0,
                'would_rehire_breakdown' => [],
                'primary_reasons'        => [],
                'avg_satisfaction'       => 0.0,
                'avg_recommend'          => 0.0,
                'avg_likerts'            => [],
                'by_department'          => [],
            ];
        }

        // Would rehire breakdown
        $rehire = ['YES' => 0, 'NO' => 0, 'CONDITIONAL' => 0, 'UNKNOWN' => 0];
        foreach ($rows as $r) {
            $key = (string) ($r['would_rehire'] ?? 'UNKNOWN');
            $rehire[$key] = ($rehire[$key] ?? 0) + 1;
        }

        // Primary reasons (sorted desc by count)
        $reasons = [];
        foreach ($rows as $r) {
            $key = (string) ($r['primary_reason'] ?? 'OTHER');
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
        arsort($reasons);

        // Average scores
        $sumSat = $sumRec = $cntSat = $cntRec = 0;
        foreach ($rows as $r) {
            if ($r['satisfaction_score'] !== null) {
                $sumSat += (int) $r['satisfaction_score']; $cntSat++;
            }
            if ($r['recommend_score'] !== null) {
                $sumRec += (int) $r['recommend_score']; $cntRec++;
            }
        }
        $avgSat = $cntSat > 0 ? round($sumSat / $cntSat, 2) : 0.0;
        $avgRec = $cntRec > 0 ? round($sumRec / $cntRec, 2) : 0.0;

        // Avg per Likert dimension
        $likertFields = [
            'score_compensation', 'score_management', 'score_work_environment',
            'score_career_growth', 'score_work_life_balance', 'score_team_culture',
        ];
        $avgLikerts = [];
        foreach ($likertFields as $f) {
            $sum = $cnt = 0;
            foreach ($rows as $r) {
                if ($r[$f] !== null) { $sum += (int) $r[$f]; $cnt++; }
            }
            $avgLikerts[$f] = $cnt > 0 ? round($sum / $cnt, 2) : null;
        }

        // Breakdown per department (employees joined for dept)
        $employeeIds = array_map(static fn ($r) => (int) $r['employee_id'], $rows);
        $deptMap = [];
        if (! empty($employeeIds)) {
            $deptRows = $db->table('employees e')
                ->select('e.id, d.name AS department_name')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->whereIn('e.id', $employeeIds)
                ->get()->getResultArray();
            foreach ($deptRows as $dr) {
                $deptMap[(int) $dr['id']] = (string) ($dr['department_name'] ?? 'Unassigned');
            }
        }
        $byDept = [];
        foreach ($rows as $r) {
            $dept = $deptMap[(int) $r['employee_id']] ?? 'Unassigned';
            $byDept[$dept] = ($byDept[$dept] ?? 0) + 1;
        }
        arsort($byDept);

        return [
            'total_completed'        => $total,
            'would_rehire_breakdown' => $rehire,
            'primary_reasons'        => $reasons,
            'avg_satisfaction'       => $avgSat,
            'avg_recommend'          => $avgRec,
            'avg_likerts'            => $avgLikerts,
            'by_department'          => $byDept,
        ];
    }

    /**
     * Compute eNPS dari recommend_score. NPS = %promoter (9-10) - %detractor (0-6).
     */
    public function computeNps(array $scores): ?float
    {
        $valid = array_filter($scores, static fn ($s) => $s !== null && is_numeric($s));
        $count = count($valid);
        if ($count === 0) return null;

        $promoters  = 0;
        $detractors = 0;
        foreach ($valid as $s) {
            $v = (int) $s;
            if ($v >= 9) $promoters++;
            elseif ($v <= 6) $detractors++;
        }
        return round((($promoters - $detractors) / $count) * 100, 1);
    }

    public function load(int $id): array
    {
        $db = Database::connect();
        $row = $db->table('exit_interviews ei')
            ->select('ei.*, e.full_name AS employee_name, e.employee_number, '
                . 'e.join_date, e.resign_date, '
                . 'd.name AS department_name, p.name AS position_name, '
                . 'cb.name AS conducted_by_name')
            ->join('employees e', 'e.id = ei.employee_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->join('users cb', 'cb.id = ei.conducted_by', 'left')
            ->where('ei.id', $id)
            ->where('ei.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Exit interview #{$id} tidak ditemukan");
        }
        return $this->decodeRow($row);
    }

    private function loadRaw(int $id): array
    {
        $db = Database::connect();
        $row = $db->table('exit_interviews')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Exit interview #{$id} tidak ditemukan");
        }
        return $row;
    }

    private function decodeRow(array $row): array
    {
        $row['secondary_reasons'] = ! empty($row['secondary_reasons_json'])
            ? (json_decode((string) $row['secondary_reasons_json'], true) ?: [])
            : [];
        return $row;
    }
}