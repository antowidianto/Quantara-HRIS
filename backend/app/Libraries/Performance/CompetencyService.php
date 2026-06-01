<?php

namespace App\Libraries\Performance;

/**
 * CompetencyService (Sprint 14 / H3.1)
 *
 * Library Jabatan Berbasis Kompetensi.
 *
 * Tanggung jawab:
 *   1. Master taxonomy (`competencies` + `competency_levels`)
 *   2. Profile per posisi (`position_competency_profiles`)
 *   3. Penilaian aktual karyawan (`employee_competency_assessments`)
 *   4. Gap analysis: bandingkan current vs target per competency
 *   5. Trigger rekomendasi training berdasarkan gap
 *
 * Pure helpers (testable tanpa DB):
 *   - calcGapScore(profileRows, currentLevelMap)
 *   - normalizeSlugCode(name)
 */
class CompetencyService
{
    public const CATEGORIES = ['CORE', 'LEADERSHIP', 'FUNCTIONAL', 'TECHNICAL', 'BEHAVIORAL'];
    public const ASSESSOR_TYPES = ['SELF', 'SUPERVISOR', 'PEER', 'HR', 'CERTIFICATION'];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Hitung gap score 0-100 antara profile target posisi dan
     * current assessment karyawan. 0 = fully ready (no gap).
     *
     * Formula: weighted sum of (target - current) gap, di-normalize ke 0-100.
     *
     * @param array<int, array<string,mixed>> $profileRows
     *   Rows dari `position_competency_profiles` dengan kolom
     *   competency_id, target_level, weight, is_critical.
     * @param array<int, int> $currentLevelMap
     *   Map competency_id => current_level (1-5).
     *
     * @return array{
     *   gap_score: float,
     *   total_weight: float,
     *   readiness_pct: float,
     *   missing_critical: int,
     *   per_competency: array<int, array{competency_id:int, target:int, current:int, gap:int, weight:float, critical:bool}>
     * }
     */
    public static function calcGapScore(array $profileRows, array $currentLevelMap): array
    {
        if (empty($profileRows)) {
            return [
                'gap_score' => 0.0,
                'total_weight' => 0.0,
                'readiness_pct' => 100.0,
                'missing_critical' => 0,
                'per_competency' => [],
            ];
        }

        $totalWeight = 0.0;
        $weightedGap = 0.0;
        $maxPossibleWeightedGap = 0.0;
        $missingCritical = 0;
        $perCompetency = [];

        foreach ($profileRows as $p) {
            $compId = (int) $p['competency_id'];
            $target = (int) $p['target_level'];
            $weight = (float) ($p['weight'] ?? 1);
            $isCritical = (bool) ($p['is_critical'] ?? false);
            $current = (int) ($currentLevelMap[$compId] ?? 0);
            $gap = max(0, $target - $current);

            $totalWeight += $weight;
            $weightedGap += $gap * $weight;
            // Max gap kalau current = 0 dan target = 5 → 5 (terburuk)
            $maxPossibleWeightedGap += $target * $weight;

            if ($isCritical && $current < $target) $missingCritical++;

            $perCompetency[] = [
                'competency_id' => $compId,
                'target' => $target,
                'current' => $current,
                'gap' => $gap,
                'weight' => $weight,
                'critical' => $isCritical,
            ];
        }

        // Normalize gap ke skor 0-100 (100 = max gap, 0 = no gap)
        $gapScore = $maxPossibleWeightedGap > 0
            ? round(($weightedGap / $maxPossibleWeightedGap) * 100, 2)
            : 0.0;
        $readinessPct = round(100 - $gapScore, 2);

        return [
            'gap_score'        => $gapScore,
            'total_weight'     => round($totalWeight, 2),
            'readiness_pct'    => $readinessPct,
            'missing_critical' => $missingCritical,
            'per_competency'   => $perCompetency,
        ];
    }

    /**
     * Pure helper: normalize nama jadi slug uppercase untuk kolom `code`.
     * "Communication Leadership" → "COMM_LEADERSHIP".
     */
    public static function normalizeSlugCode(string $name): string
    {
        $s = strtoupper(trim($name));
        $s = preg_replace('/[^A-Z0-9]+/', '_', $s);
        $s = trim($s, '_');
        return substr($s, 0, 32);
    }

    /**
     * Build gap analysis untuk satu employee terhadap posisi target
     * (default: posisi current). Return struktur sama dengan calcGapScore
     * + enriched per_competency dengan name + descriptor.
     */
    public function gapAnalysis(int $employeeId, ?int $targetPositionId = null): array
    {
        $db = $this->db();
        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak ditemukan');

        $positionId = $targetPositionId ?? (int) ($emp['position_id'] ?? 0);
        if ($positionId <= 0) {
            return [
                'gap_score' => 0.0, 'total_weight' => 0.0, 'readiness_pct' => 0.0,
                'missing_critical' => 0, 'per_competency' => [],
                'note' => 'Employee belum punya position_id atau target tidak diset',
            ];
        }

        $profileRows = $db->table('position_competency_profiles')
            ->where('position_id', $positionId)
            ->get()->getResultArray();
        if (empty($profileRows)) {
            return [
                'gap_score' => 0.0, 'total_weight' => 0.0, 'readiness_pct' => 0.0,
                'missing_critical' => 0, 'per_competency' => [],
                'note' => 'Position belum punya competency profile',
            ];
        }

        // Latest assessment per competency_id untuk employee ini
        $assessRows = $db->table('employee_competency_assessments')
            ->where('employee_id', $employeeId)
            ->orderBy('assessed_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();
        $currentMap = [];
        foreach ($assessRows as $a) {
            $cid = (int) $a['competency_id'];
            if (! isset($currentMap[$cid])) {
                // Skip kalau certification expired
                if (! empty($a['valid_until']) && strtotime($a['valid_until']) < time()) continue;
                $currentMap[$cid] = (int) $a['current_level'];
            }
        }

        $result = self::calcGapScore($profileRows, $currentMap);

        // Enrich per_competency dengan nama
        if (! empty($result['per_competency'])) {
            $compIds = array_column($result['per_competency'], 'competency_id');
            $comps = $db->table('competencies')
                ->whereIn('id', $compIds)
                ->get()->getResultArray();
            $byId = [];
            foreach ($comps as $c) $byId[(int) $c['id']] = $c;
            foreach ($result['per_competency'] as &$row) {
                $cid = $row['competency_id'];
                $row['name']     = $byId[$cid]['name'] ?? null;
                $row['code']     = $byId[$cid]['code'] ?? null;
                $row['category'] = $byId[$cid]['category'] ?? null;
            }
            unset($row);
        }
        $result['employee_id'] = $employeeId;
        $result['position_id'] = $positionId;
        return $result;
    }

    /**
     * Rekomendasikan training berdasarkan gap. Filter `trainings` yang
     * `competency_id` masuk ke gap > 0 dan ranking by impact (gap × weight).
     *
     * @return array<int, array<string,mixed>>
     */
    public function recommendTraining(int $employeeId, int $limit = 10): array
    {
        $db = $this->db();
        if (! $db->tableExists('trainings')) return [];
        $cols = $db->getFieldNames('trainings');
        if (! in_array('competency_id', $cols, true)) return [];

        $analysis = $this->gapAnalysis($employeeId);
        $gappedCompetencies = array_filter(
            $analysis['per_competency'] ?? [],
            fn ($r) => ($r['gap'] ?? 0) > 0,
        );
        if (empty($gappedCompetencies)) return [];

        $compIds = array_map(fn ($r) => $r['competency_id'], $gappedCompetencies);
        $impactMap = [];
        foreach ($gappedCompetencies as $r) {
            $impactMap[$r['competency_id']] = ($r['gap'] ?? 0) * ($r['weight'] ?? 1);
        }

        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return [];

        $rows = $db->table('trainings')
            ->select('id, title, competency_id, start_date, end_date, status')
            ->where('company_id', (int) $emp['company_id'])
            ->whereIn('competency_id', $compIds)
            ->where('status', 'PUBLISHED')
            ->where('end_date >=', date('Y-m-d'))
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        // Sort by impact desc
        usort($rows, function ($a, $b) use ($impactMap) {
            $ia = $impactMap[(int) $a['competency_id']] ?? 0;
            $ib = $impactMap[(int) $b['competency_id']] ?? 0;
            return $ib <=> $ia;
        });
        foreach ($rows as &$r) {
            $r['impact_score'] = $impactMap[(int) $r['competency_id']] ?? 0;
        }
        unset($r);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Catat assessment baru. Tidak menghapus history (audit trail).
     *
     * @return array{id: int}
     */
    public function recordAssessment(array $data): array
    {
        $required = ['employee_id', 'competency_id', 'current_level'];
        foreach ($required as $f) {
            if (empty($data[$f]) && $data[$f] !== 0) {
                throw new \InvalidArgumentException("Field {$f} required");
            }
        }
        $level = (int) $data['current_level'];
        if ($level < 1 || $level > 5) {
            throw new \InvalidArgumentException('current_level harus 1-5');
        }
        $assessor = strtoupper((string) ($data['assessor_type'] ?? 'SUPERVISOR'));
        if (! in_array($assessor, self::ASSESSOR_TYPES, true)) {
            throw new \InvalidArgumentException('assessor_type tidak valid');
        }

        $db = $this->db();
        $emp = $db->table('employees')->where('id', (int) $data['employee_id'])->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak ditemukan');

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => (int) $emp['company_id'],
            'employee_id'      => (int) $data['employee_id'],
            'competency_id'    => (int) $data['competency_id'],
            'current_level'    => $level,
            'evidence'         => $data['evidence'] ?? null,
            'assessor_type'    => $assessor,
            'assessor_user_id' => isset($data['assessor_user_id']) ? (int) $data['assessor_user_id'] : null,
            'assessed_at'      => $data['assessed_at'] ?? $now,
            'valid_until'      => $data['valid_until'] ?? null,
            'cycle_id'         => isset($data['cycle_id']) ? (int) $data['cycle_id'] : null,
            'notes'            => $data['notes'] ?? null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        $db->table('employee_competency_assessments')->insert($insert);
        return ['id' => (int) $db->insertID()];
    }
}