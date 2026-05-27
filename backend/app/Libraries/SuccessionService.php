<?php

namespace App\Libraries;

/**
 * SuccessionService (Sprint 14 / H3.2)
 *
 * Succession Planning — track posisi kunci dan calon penerus.
 *
 * Tanggung jawab:
 *   1. CRUD `critical_positions` + `succession_candidates`
 *   2. Coverage ratio: % critical positions yang punya cukup successor
 *      sesuai `min_successor_count`
 *   3. Auto-compute readiness via gap analysis dari CompetencyService
 *   4. Alert: incumbent resign / mendekati pensiun → flag urgency
 *
 * Pure helpers:
 *   - readinessFromGap(gapScore) → ENUM bucket
 *   - coverageRatio(criticalCount, coveredCount)
 */
class SuccessionService
{
    public const READINESS_LEVELS = [
        'READY_NOW'    => 1,  // gap < 10
        'READY_1Y'     => 2,  // gap 10-25
        'READY_2Y'     => 3,  // gap 25-45
        'READY_3Y_PLUS'=> 4,  // gap 45-70
        'NOT_READY'    => 5,  // gap > 70
    ];

    public const CRITICALITY = ['HIGH', 'MEDIUM', 'LOW'];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Map gap score 0-100 ke readiness bucket.
     * Pure function — testable.
     */
    public static function readinessFromGap(float $gapScore): string
    {
        if ($gapScore < 10)  return 'READY_NOW';
        if ($gapScore < 25)  return 'READY_1Y';
        if ($gapScore < 45)  return 'READY_2Y';
        if ($gapScore < 70)  return 'READY_3Y_PLUS';
        return 'NOT_READY';
    }

    /**
     * Coverage ratio per company.
     * "Covered" = jumlah successor aktif >= min_successor_count untuk
     * critical position itu.
     *
     * @return array{
     *   total: int,
     *   covered: int,
     *   coverage_pct: float,
     *   by_criticality: array<string, array{total:int,covered:int,pct:float}>,
     *   gaps: array<int, array<string,mixed>>  positions yang under-covered
     * }
     */
    public function coverageReport(int $companyId): array
    {
        $db = $this->db();
        $criticalRows = $db->table('critical_positions cp')
            ->select('cp.*, p.name AS position_name')
            ->join('positions p', 'p.id = cp.position_id', 'left')
            ->where('cp.company_id', $companyId)
            ->where('cp.is_active', 1)
            ->get()->getResultArray();

        if (empty($criticalRows)) {
            return [
                'total' => 0, 'covered' => 0, 'coverage_pct' => 0.0,
                'by_criticality' => [], 'gaps' => [],
            ];
        }

        $criticalIds = array_map(fn ($r) => (int) $r['id'], $criticalRows);
        $successorCount = [];
        $rows = $db->table('succession_candidates')
            ->select('critical_position_id, COUNT(*) AS cnt')
            ->whereIn('critical_position_id', $criticalIds)
            ->where('is_active', 1)
            ->whereIn('readiness', ['READY_NOW', 'READY_1Y']) // hanya yang reasonably ready
            ->groupBy('critical_position_id')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $successorCount[(int) $r['critical_position_id']] = (int) $r['cnt'];
        }

        $byCriticality = ['HIGH' => ['total' => 0, 'covered' => 0],
                          'MEDIUM' => ['total' => 0, 'covered' => 0],
                          'LOW' => ['total' => 0, 'covered' => 0]];
        $gaps = [];
        $covered = 0;

        foreach ($criticalRows as $cp) {
            $cnt = $successorCount[(int) $cp['id']] ?? 0;
            $needed = (int) $cp['min_successor_count'];
            $isCovered = $cnt >= $needed;
            if ($isCovered) $covered++;

            $crit = (string) $cp['criticality'];
            if (! isset($byCriticality[$crit])) $byCriticality[$crit] = ['total' => 0, 'covered' => 0];
            $byCriticality[$crit]['total']++;
            if ($isCovered) $byCriticality[$crit]['covered']++;

            if (! $isCovered) {
                $gaps[] = [
                    'critical_position_id' => (int) $cp['id'],
                    'position_id'          => (int) $cp['position_id'],
                    'position_name'        => $cp['position_name'] ?? null,
                    'criticality'          => $crit,
                    'needed'               => $needed,
                    'current_ready'        => $cnt,
                    'shortage'             => max(0, $needed - $cnt),
                ];
            }
        }
        $total = count($criticalRows);
        // Compute % per criticality
        foreach ($byCriticality as $key => &$bucket) {
            $bucket['pct'] = $bucket['total'] > 0
                ? round(($bucket['covered'] / $bucket['total']) * 100, 2)
                : 0.0;
        }
        unset($bucket);

        return [
            'total'          => $total,
            'covered'        => $covered,
            'coverage_pct'   => round(($covered / $total) * 100, 2),
            'by_criticality' => $byCriticality,
            'gaps'           => $gaps,
        ];
    }

    /**
     * Recompute readiness untuk satu succession candidate berdasarkan
     * gap analysis terhadap profile target position.
     */
    public function recomputeReadiness(int $candidateId): array
    {
        $db = $this->db();
        $cand = $db->table('succession_candidates')->where('id', $candidateId)->get()->getRowArray();
        if (! $cand) throw new \RuntimeException('Succession candidate tidak ditemukan');

        $cp = $db->table('critical_positions')->where('id', (int) $cand['critical_position_id'])->get()->getRowArray();
        if (! $cp) throw new \RuntimeException('Critical position tidak ditemukan');

        $compService = new CompetencyService();
        $analysis = $compService->gapAnalysis((int) $cand['employee_id'], (int) $cp['position_id']);
        $gapScore = (float) ($analysis['gap_score'] ?? 0);
        $readiness = self::readinessFromGap($gapScore);

        $db->table('succession_candidates')->where('id', $candidateId)->update([
            'gap_score'       => $gapScore,
            'readiness'       => $readiness,
            'last_reviewed_at'=> date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        return [
            'id'        => $candidateId,
            'gap_score' => $gapScore,
            'readiness' => $readiness,
        ];
    }

    /**
     * Nominate karyawan jadi successor untuk critical position.
     * Idempotent — re-nominate update timestamp + readiness.
     */
    public function nominate(
        int $criticalPositionId,
        int $employeeId,
        int $byUserId,
        ?string $notes = null,
    ): array {
        $db = $this->db();
        $cp = $db->table('critical_positions')->where('id', $criticalPositionId)->get()->getRowArray();
        if (! $cp) throw new \RuntimeException('Critical position tidak ditemukan');
        $emp = $db->table('employees')->where('id', $employeeId)->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak ditemukan');
        if ((int) $cp['company_id'] !== (int) $emp['company_id']) {
            throw new \RuntimeException('Cross-tenant nomination tidak diperbolehkan');
        }
        if (! empty($cp['incumbent_employee_id']) && (int) $cp['incumbent_employee_id'] === $employeeId) {
            throw new \RuntimeException('Incumbent tidak bisa dijadikan successor untuk posisi sendiri');
        }

        $now = date('Y-m-d H:i:s');
        $existing = $db->table('succession_candidates')
            ->where('critical_position_id', $criticalPositionId)
            ->where('employee_id', $employeeId)
            ->get()->getRowArray();
        if ($existing) {
            $db->table('succession_candidates')->where('id', (int) $existing['id'])->update([
                'is_active'        => 1,
                'last_reviewed_at' => $now,
                'notes'            => $notes ?? $existing['notes'],
                'updated_at'       => $now,
            ]);
            $candidateId = (int) $existing['id'];
        } else {
            $db->table('succession_candidates')->insert([
                'company_id'           => (int) $cp['company_id'],
                'critical_position_id' => $criticalPositionId,
                'employee_id'          => $employeeId,
                'readiness'            => 'READY_2Y',  // default sebelum di-recompute
                'gap_score'            => null,
                'nominated_by_user_id' => $byUserId,
                'nominated_at'         => $now,
                'last_reviewed_at'     => $now,
                'notes'                => $notes,
                'is_active'            => 1,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $candidateId = (int) $db->insertID();
        }

        // Auto-compute readiness berdasarkan gap analysis
        try {
            return $this->recomputeReadiness($candidateId);
        } catch (\Throwable $e) {
            // Position belum punya competency profile → tetap return
            return ['id' => $candidateId, 'gap_score' => null, 'readiness' => 'READY_2Y'];
        }
    }

    /**
     * List successor pool untuk satu critical position (sorted by readiness).
     */
    public function listSuccessors(int $criticalPositionId): array
    {
        $db = $this->db();
        $rows = $db->table('succession_candidates sc')
            ->select('sc.*, e.full_name AS employee_name, e.employee_number, '
                . 'e.position_id AS current_position_id, p.name AS current_position_name')
            ->join('employees e', 'e.id = sc.employee_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('sc.critical_position_id', $criticalPositionId)
            ->where('sc.is_active', 1)
            ->orderBy("FIELD(sc.readiness, 'READY_NOW', 'READY_1Y', 'READY_2Y', 'READY_3Y_PLUS', 'NOT_READY')",
                '', false)
            ->get()->getResultArray();
        return $rows;
    }
}