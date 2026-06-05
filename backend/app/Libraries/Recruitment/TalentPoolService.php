<?php

namespace App\Libraries\Recruitment;

/**
 * TalentPoolService (Sprint 17 / H3.6)
 *
 * Internal Talent Marketplace + Skill Matching.
 *
 * Tanggung jawab:
 *   1. CRUD `employee_skills` + `employee_aspirations`
 *   2. CRUD `internal_job_postings` (visible internal sebelum public)
 *   3. Apply ke internal posting + flow ACCEPTED → JIC otomatis
 *   4. Skill matching: rekomendasi internal candidate untuk MPP / posting
 *   5. 9-Box Grid: matrix performance × potential per company
 *
 * Pure helpers:
 *   - normalizeSkillSlug(name)
 *   - matchScore(candidateSkills, requiredSkills)
 *   - boxFromGrid(performance, potential)
 */
class TalentPoolService
{
    public const PROFICIENCY_LEVELS = ['BEGINNER' => 1, 'INTERMEDIATE' => 2,
        'ADVANCED' => 3, 'EXPERT' => 4];

    public const TIMELINE = ['IMMEDIATE', 'SHORT_TERM', 'MID_TERM', 'LONG_TERM'];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Pure helper: normalize nama skill jadi slug lowercase.
     * "PHP / Laravel" → "php_laravel"
     */
    public static function normalizeSkillSlug(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim($s, '_');
        return substr($s, 0, 100);
    }

    /**
     * Pure match score 0-100 antara skill set kandidat dan kebutuhan
     * posisi/posting. Skill required dengan proficiency_min: kandidat
     * harus punya level >= min, hilang = 0 score, surplus level = bonus
     * setengah point per level.
     *
     * @param array<int, array{slug: string, level: int}> $candidateSkills
     * @param array<int, array{slug: string, min_level: int, weight?: float}> $requiredSkills
     */
    public static function matchScore(array $candidateSkills, array $requiredSkills): array
    {
        if (empty($requiredSkills)) {
            return ['score' => 0.0, 'matches' => 0, 'missing' => 0,
                'matched_skills' => [], 'missing_skills' => []];
        }
        $candMap = [];
        foreach ($candidateSkills as $s) {
            $candMap[strtolower((string) $s['slug'])] = (int) ($s['level'] ?? 1);
        }

        $totalWeight = 0.0;
        $earnedWeight = 0.0;
        $matched = [];
        $missing = [];

        foreach ($requiredSkills as $req) {
            $slug = strtolower((string) $req['slug']);
            $min = (int) ($req['min_level'] ?? 1);
            $weight = (float) ($req['weight'] ?? 1);
            $totalWeight += $weight;

            if (! isset($candMap[$slug])) {
                $missing[] = $slug;
                continue;
            }
            $candLevel = $candMap[$slug];
            if ($candLevel < $min) {
                // Partial credit untuk yang punya tapi kurang
                $partialPct = $candLevel / max($min, 1);
                $earnedWeight += $partialPct * $weight;
                $missing[] = $slug;
                continue;
            }
            // Full match + bonus 0.5 point per level surplus, max 1.5x weight
            $surplus = $candLevel - $min;
            $multiplier = min(1.5, 1 + ($surplus * 0.1));
            $earnedWeight += $weight * $multiplier;
            $matched[] = ['slug' => $slug, 'level' => $candLevel, 'min' => $min];
        }

        $score = $totalWeight > 0
            ? round(min(100, ($earnedWeight / $totalWeight) * 100), 2)
            : 0.0;
        return [
            'score'          => $score,
            'matches'        => count($matched),
            'missing'        => count($missing),
            'matched_skills' => $matched,
            'missing_skills' => $missing,
        ];
    }

    /**
     * Pure helper: 9-box grid mapper.
     * Performance & potential di rentang 1-5 dipetakan ke kotak 1-9:
     *
     *   ╔══════════════╤════════════╤═════════════╗
     *   ║ 7 (talent)   │ 8 (high)   │ 9 (star)    ║  potential 4-5
     *   ╠══════════════╪════════════╪═════════════╣
     *   ║ 4 (effective)│ 5 (core)   │ 6 (high pro)║  potential 3
     *   ╠══════════════╪════════════╪═════════════╣
     *   ║ 1 (under)    │ 2 (limit)  │ 3 (solid)   ║  potential 1-2
     *   ╚══════════════╧════════════╧═════════════╝
     *      perf 1-2     perf 3       perf 4-5
     */
    public static function boxFromGrid(float $performance, float $potential): int
    {
        $perfBucket = $performance <= 2 ? 0 : ($performance <= 3 ? 1 : 2);
        $potBucket  = $potential   <= 2 ? 0 : ($potential   <= 3 ? 1 : 2);
        return $potBucket * 3 + $perfBucket + 1;
    }

    public static function boxLabel(int $box): string
    {
        return match ($box) {
            1 => 'Underperformer',
            2 => 'Limited Performer',
            3 => 'Solid Performer',
            4 => 'Effective',
            5 => 'Core Player',
            6 => 'High Professional',
            7 => 'Latent Talent',
            8 => 'High Potential',
            9 => 'Star',
            default => 'Unknown',
        };
    }

    /**
     * Self-declare / update skill karyawan. Idempotent — re-update level
     * untuk skill yang sudah ada.
     */
    public function declareSkill(int $employeeId, array $data): array
    {
        $name = trim((string) ($data['skill_name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('skill_name required');
        $proficiency = strtoupper((string) ($data['proficiency'] ?? 'INTERMEDIATE'));
        if (! isset(self::PROFICIENCY_LEVELS[$proficiency])) {
            throw new \InvalidArgumentException('proficiency tidak valid');
        }
        $slug = self::normalizeSkillSlug($name);

        $db = $this->db();
        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak ditemukan');

        $now = date('Y-m-d H:i:s');
        $existing = $db->table('employee_skills')
            ->where('employee_id', $employeeId)
            ->where('skill_slug', $slug)
            ->get()->getRowArray();
        $payload = [
            'company_id'        => (int) $emp['company_id'],
            'employee_id'       => $employeeId,
            'skill_name'        => $name,
            'skill_slug'        => $slug,
            'proficiency'       => $proficiency,
            'years_experience'  => isset($data['years_experience']) ? (float) $data['years_experience'] : null,
            'evidence'          => $data['evidence'] ?? null,
            'updated_at'        => $now,
        ];
        if ($existing) {
            // Self-declared change un-validates (perlu re-validate supervisor)
            if ((string) $existing['proficiency'] !== $proficiency) {
                $payload['is_validated']         = 0;
                $payload['validated_by_user_id'] = null;
                $payload['validated_at']         = null;
            }
            $db->table('employee_skills')->where('id', (int) $existing['id'])->update($payload);
            return ['id' => (int) $existing['id'], 'slug' => $slug];
        }
        $payload['is_validated'] = 0;
        $payload['created_at'] = $now;
        $db->table('employee_skills')->insert($payload);
        return ['id' => (int) $db->insertID(), 'slug' => $slug];
    }

    /**
     * Validate skill (oleh supervisor / HR).
     */
    public function validateSkill(int $skillId, int $byUserId, ?string $note = null): array
    {
        $db = $this->db();
        $row = $db->table('employee_skills')->where('id', $skillId)->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Skill tidak ditemukan');
        $now = date('Y-m-d H:i:s');
        $db->table('employee_skills')->where('id', $skillId)->update([
            'is_validated'         => 1,
            'validated_by_user_id' => $byUserId,
            'validated_at'         => $now,
            'evidence'             => $note ?? $row['evidence'],
            'updated_at'           => $now,
        ]);
        return ['id' => $skillId, 'validated' => true];
    }

    /**
     * Submit aplikasi internal job. Validasi:
     *   - posting harus OPEN
     *   - employee tenure >= min_tenure_months
     *   - belum apply sebelumnya
     */
    public function applyInternal(int $internalJobPostingId, int $employeeId, ?string $coverLetter = null): array
    {
        $db = $this->db();
        $posting = $db->table('internal_job_postings')->where('id', $internalJobPostingId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $posting) throw new \RuntimeException('Internal job posting tidak ditemukan');
        if ($posting['status'] !== 'OPEN') {
            throw new \RuntimeException('Posting belum/sudah tidak open: ' . $posting['status']);
        }
        if (! empty($posting['closes_at']) && strtotime($posting['closes_at']) < time()) {
            throw new \RuntimeException('Posting sudah lewat tanggal penutupan');
        }

        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->where('is_active', 1)->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak aktif / tidak ditemukan');
        if ((int) $emp['company_id'] !== (int) $posting['company_id']) {
            throw new \RuntimeException('Cross-tenant application tidak diperbolehkan');
        }

        // Tenure check
        $minTenure = (int) ($posting['min_tenure_months'] ?? 0);
        if ($minTenure > 0 && ! empty($emp['join_date'])) {
            $joinTs = strtotime($emp['join_date']);
            $tenureMonths = (int) (((time() - $joinTs) / 86400) / 30.4);
            if ($tenureMonths < $minTenure) {
                throw new \RuntimeException(
                    "Tenure {$tenureMonths} bulan kurang dari minimum {$minTenure} bulan"
                );
            }
        }

        // Sudah apply?
        $existing = $db->table('internal_applications')
            ->where('internal_job_posting_id', $internalJobPostingId)
            ->where('employee_id', $employeeId)
            ->get()->getRowArray();
        if ($existing) {
            throw new \RuntimeException('Anda sudah pernah apply ke posting ini');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('internal_applications')->insert([
            'company_id'              => (int) $posting['company_id'],
            'internal_job_posting_id' => $internalJobPostingId,
            'employee_id'             => $employeeId,
            'cover_letter'            => $coverLetter,
            'status'                  => 'SUBMITTED',
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
        $appId = (int) $db->insertID();

        // TODO: Notify supervisor lama (transparency) — placeholder
        // di NotificationService kalau ingin diaktifkan kemudian.

        return ['id' => $appId];
    }

    /**
     * Rekomendasikan internal candidate untuk satu posting (ranking by
     * skill match + tenure + performance).
     *
     * @return array<int, array<string,mixed>>
     */
    public function recommendCandidatesForPosting(int $internalJobPostingId, int $limit = 20): array
    {
        $db = $this->db();
        $posting = $db->table('internal_job_postings')->where('id', $internalJobPostingId)->get()->getRowArray();
        if (! $posting) return [];
        $companyId = (int) $posting['company_id'];

        // Required skills dari position_competency_profiles target posting.
        // Kalau tidak ada profile, fallback ke text matching dari description.
        $requiredSkills = [];
        if ($db->tableExists('position_competency_profiles')) {
            $profiles = $db->table('position_competency_profiles pcp')
                ->select('pcp.target_level, pcp.weight, c.code, c.name')
                ->join('competencies c', 'c.id = pcp.competency_id')
                ->where('pcp.position_id', (int) $posting['position_id'])
                ->get()->getResultArray();
            foreach ($profiles as $p) {
                $requiredSkills[] = [
                    'slug'      => self::normalizeSkillSlug((string) $p['code']),
                    'min_level' => (int) $p['target_level'],
                    'weight'    => (float) $p['weight'],
                ];
            }
        }
        if (empty($requiredSkills)) return [];

        // Pool: aktif, eligible (tenure check)
        $minTenure = (int) ($posting['min_tenure_months'] ?? 0);
        $candidates = $db->table('employees')
            ->select('id, full_name, employee_number, position_id, department_id, branch_id, join_date')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        if ($minTenure > 0) {
            $cutoff = date('Y-m-d', strtotime("-{$minTenure} months"));
            $candidates = array_filter($candidates,
                fn ($e) => ! empty($e['join_date']) && $e['join_date'] <= $cutoff);
        }
        if (empty($candidates)) return [];

        $empIds = array_map(fn ($e) => (int) $e['id'], $candidates);
        $skillRows = $db->table('employee_skills')
            ->select('employee_id, skill_slug, proficiency')
            ->whereIn('employee_id', $empIds)
            ->get()->getResultArray();
        $skillsByEmp = [];
        foreach ($skillRows as $s) {
            $eid = (int) $s['employee_id'];
            $skillsByEmp[$eid][] = [
                'slug'  => $s['skill_slug'],
                'level' => self::PROFICIENCY_LEVELS[$s['proficiency']] ?? 1,
            ];
        }

        $recommendations = [];
        foreach ($candidates as $emp) {
            $eid = (int) $emp['id'];
            $candSkills = $skillsByEmp[$eid] ?? [];
            $match = self::matchScore($candSkills, $requiredSkills);
            if ($match['score'] <= 0) continue;
            $recommendations[] = $emp + [
                'match_score'   => $match['score'],
                'matched_count' => $match['matches'],
                'missing_count' => $match['missing'],
            ];
        }
        usort($recommendations, fn ($a, $b) => $b['match_score'] <=> $a['match_score']);
        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Build 9-box grid untuk seluruh karyawan aktif di company.
     * Performance: avg overall_score / 20 (0-100 → 0-5).
     * Potential: heuristic dari assessment level + last review acknowledged.
     *
     * NOTE: Kolom yang benar di `performance_reviews` adalah `overall_score`
     * (lihat migration #024), bukan `final_score`. Versi sebelumnya melempar
     * "Unknown column 'final_score' in field list" di production.
     */
    public function nineBoxGrid(int $companyId): array
    {
        $db = $this->db();
        $boxCounts = array_fill(1, 9, 0);
        $byBox = array_fill(1, 9, []);

        // Resolve nama kolom skor sekali di awal supaya defensive di DB
        // schema bervariasi (legacy mungkin pakai final_score, current
        // pakai overall_score). Kalau tidak ada, performance dianggap 0
        // dan grid jatuh ke baris bawah.
        $scoreCol = null;
        if ($db->tableExists('performance_reviews')) {
            $cols = $db->getFieldNames('performance_reviews');
            $scoreCol = in_array('overall_score', $cols, true)
                ? 'overall_score'
                : (in_array('final_score', $cols, true) ? 'final_score' : null);
        }

        $emps = $db->table('employees')
            ->select('id, full_name, employee_number, position_id, department_id')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            // Performance: avg score / 20 (0-100 → 0-5)
            $perf = 0.0;
            if ($scoreCol !== null) {
                $perfRow = $db->table('performance_reviews')
                    ->selectAvg($scoreCol, 'avg_score')
                    ->where('employee_id', $eid)
                    ->where($scoreCol . ' IS NOT NULL', null, false)
                    ->get()->getRowArray();
                $perf = ! empty($perfRow['avg_score']) ? min(5, ((float) $perfRow['avg_score']) / 20) : 0.0;
            }

            // Potential: avg current_level dari assessments
            $potential = 0.0;
            if ($db->tableExists('employee_competency_assessments')) {
                $potRow = $db->table('employee_competency_assessments')
                    ->selectAvg('current_level', 'avg_level')
                    ->where('employee_id', $eid)
                    ->get()->getRowArray();
                $potential = ! empty($potRow['avg_level']) ? (float) $potRow['avg_level'] : 0.0;
            }

            if ($perf <= 0 && $potential <= 0) continue; // skip yang tidak ada data
            $box = self::boxFromGrid($perf, $potential);
            $boxCounts[$box]++;
            // Cap 5 sample per box untuk response size
            if (count($byBox[$box]) < 5) {
                $byBox[$box][] = $e + [
                    'performance' => round($perf, 2),
                    'potential'   => round($potential, 2),
                ];
            }
        }

        $result = [];
        for ($i = 1; $i <= 9; $i++) {
            $result[$i] = [
                'box'     => $i,
                'label'   => self::boxLabel($i),
                'count'   => $boxCounts[$i],
                'samples' => $byBox[$i],
            ];
        }
        return [
            'total_employees' => array_sum($boxCounts),
            'boxes'           => array_values($result),
        ];
    }
}