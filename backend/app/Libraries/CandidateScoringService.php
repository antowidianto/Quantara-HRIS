<?php

namespace App\Libraries;

/**
 * CandidateScoringService (Sprint 10 / H2.4)
 *
 * Hitung composite score kandidat berdasarkan kriteria yang dikonfigurasi
 * per job_posting. Engine: weighted average dari sub-skor 0-100 per
 * kriteria, di-normalize ke total bobot.
 *
 * Pure formula:
 *   composite = sum(sub_score_i * weight_i) / sum(weight_i)
 *
 * Sub-score per criterion (semua 0-100):
 *   - ONLINE_TEST_SCORE   : best_percentage dari online_test_assignments
 *                            (rata-rata kalau multiple test). 0 jika belum
 *                            ada assignment / hasil.
 *   - SALARY_FIT          : 100 jika expected_salary di range salary_min..max,
 *                            menurun linear ke 0 untuk over/under range.
 *   - EXPERIENCE_YEARS    : min(years_experience / target_value, 1) * 100.
 *   - AVAILABILITY_DAYS   : 100 jika available_from <= today, menurun linear
 *                            ke 0 untuk delay > target_value hari.
 *   - EDUCATION_LEVEL     : map last_education_level → score (config_json
 *                            override atau default S3=100, S2=85, S1=70,
 *                            D4/D3=55, D2/D1=40, SMA=25, dst).
 *   - INTERVIEW_RATING    : avg rating dari candidate_stages × 20
 *                            (rating 1-5 → 20-100).
 *
 * Pure analyzer: dapat di-unit-test dengan input array tanpa DB.
 */
class CandidateScoringService
{
    public const CRITERION_TYPES = [
        'ONLINE_TEST_SCORE',
        'SALARY_FIT',
        'EXPERIENCE_YEARS',
        'AVAILABILITY_DAYS',
        'EDUCATION_LEVEL',
        'INTERVIEW_RATING',
    ];

    private const DEFAULT_EDUCATION_MAP = [
        'S3' => 100, 'S2' => 85, 'S1' => 70,
        'D4' => 60, 'D3' => 55, 'D2' => 40, 'D1' => 35,
        'SMK' => 30, 'SMA' => 25, 'SMP' => 10, 'SD' => 5,
    ];

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Hitung composite score untuk kandidat dan persist ke DB.
     *
     * @return array{composite: float|null, breakdown: array<int, array>}
     */
    public function recalculate(int $candidateId): array
    {
        $cand = $this->db()->table('candidates')->where('id', $candidateId)->get()->getRowArray();
        if (! $cand) {
            return ['composite' => null, 'breakdown' => []];
        }
        $jobPostingId = (int) $cand['job_posting_id'];
        $job = $this->db()->table('job_postings')->where('id', $jobPostingId)->get()->getRowArray();

        $criteria = $this->db()->table('job_posting_scoring_criteria')
            ->where('job_posting_id', $jobPostingId)
            ->get()->getResultArray();
        if (empty($criteria)) {
            return ['composite' => null, 'breakdown' => []];
        }

        $context = [
            'candidate' => $cand,
            'job'       => $job,
            'today'     => date('Y-m-d'),
            'online_test_score'   => $this->resolveOnlineTestScore($candidateId),
            'interview_rating'    => $this->resolveInterviewRating($candidateId),
        ];

        $result = self::computeScore($criteria, $context);

        // Persist ke DB
        $this->db()->table('candidates')->where('id', $candidateId)->update([
            'composite_score' => $result['composite'],
            'score_breakdown' => json_encode($result['breakdown'], JSON_UNESCAPED_UNICODE),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $result;
    }

    /**
     * Pure scoring algorithm — input config + context, output {composite, breakdown}.
     * Dipisah dari DB layer supaya bisa di-unit-test.
     *
     * @param array<int, array<string,mixed>> $criteria List dari job_posting_scoring_criteria
     * @param array<string,mixed> $context Resolved context (candidate, job, today, online_test_score, interview_rating)
     *
     * @return array{composite: float|null, breakdown: array<int, array>}
     */
    public static function computeScore(array $criteria, array $context): array
    {
        if (empty($criteria)) {
            return ['composite' => null, 'breakdown' => []];
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $breakdown = [];

        foreach ($criteria as $c) {
            $weight = (float) ($c['weight'] ?? 1);
            if ($weight <= 0) continue;
            $type = (string) $c['criterion_type'];
            $target = $c['target_value'] !== null ? (float) $c['target_value'] : null;
            $cfg = self::decodeConfig($c['config_json'] ?? null);

            $sub = match ($type) {
                'ONLINE_TEST_SCORE'  => self::scoreOnlineTest($context),
                'SALARY_FIT'         => self::scoreSalaryFit($context),
                'EXPERIENCE_YEARS'   => self::scoreExperience($context, $target),
                'AVAILABILITY_DAYS'  => self::scoreAvailability($context, $target),
                'EDUCATION_LEVEL'    => self::scoreEducation($context, $cfg),
                'INTERVIEW_RATING'   => self::scoreInterview($context),
                default              => null,
            };

            if ($sub === null) {
                $breakdown[] = [
                    'criterion_type' => $type,
                    'weight' => $weight,
                    'sub_score' => null,
                    'note' => 'Data tidak tersedia, skip',
                ];
                continue;
            }

            $totalWeight += $weight;
            $weightedSum += $sub * $weight;
            $breakdown[] = [
                'criterion_type' => $type,
                'weight' => $weight,
                'sub_score' => round($sub, 2),
                'contribution' => round(($sub * $weight) / 100, 2),
            ];
        }

        $composite = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;

        return [
            'composite' => $composite,
            'breakdown' => $breakdown,
            'total_weight' => round($totalWeight, 2),
        ];
    }

    // ─── Sub-score calculators (pure) ─────────────────────────────

    public static function scoreOnlineTest(array $context): ?float
    {
        $score = $context['online_test_score'] ?? null;
        return $score !== null ? max(0.0, min(100.0, (float) $score)) : null;
    }

    public static function scoreSalaryFit(array $context): ?float
    {
        $expected = (float) ($context['candidate']['expected_salary'] ?? 0);
        if ($expected <= 0) return null;
        $min = (float) ($context['job']['salary_min'] ?? 0);
        $max = (float) ($context['job']['salary_max'] ?? 0);
        if ($min <= 0 && $max <= 0) return null;

        // Dalam range → 100
        if ($min > 0 && $expected >= $min && $max > 0 && $expected <= $max) {
            return 100.0;
        }
        // Di bawah range → bonus 100 (kandidat lebih murah)
        if ($min > 0 && $expected < $min) {
            return 100.0;
        }
        // Di atas range → menurun linear, 50% over → 0
        if ($max > 0 && $expected > $max) {
            $overRatio = ($expected - $max) / max($max, 1);
            $score = 100.0 - ($overRatio * 200);
            return max(0.0, min(100.0, $score));
        }
        return 50.0;
    }

    public static function scoreExperience(array $context, ?float $target): ?float
    {
        $years = (int) ($context['candidate']['years_experience'] ?? 0);
        if ($target === null || $target <= 0) {
            // Tanpa target, beri skor proporsional dengan cap di 10 tahun.
            return min(100.0, ($years / 10) * 100);
        }
        return min(100.0, ($years / $target) * 100);
    }

    public static function scoreAvailability(array $context, ?float $target): ?float
    {
        $available = $context['candidate']['available_from'] ?? null;
        if (! $available) return null;
        $today = $context['today'] ?? date('Y-m-d');
        $delayDays = (strtotime((string) $available) - strtotime($today)) / 86400;
        if ($delayDays <= 0) return 100.0;
        $maxDelay = $target !== null && $target > 0 ? $target : 60.0;
        $score = 100.0 - (($delayDays / $maxDelay) * 100);
        return max(0.0, min(100.0, $score));
    }

    public static function scoreEducation(array $context, array $cfg): ?float
    {
        $level = strtoupper((string) ($context['candidate']['last_education_level'] ?? ''));
        if ($level === '') return null;
        $map = is_array($cfg['map'] ?? null) ? $cfg['map'] : self::DEFAULT_EDUCATION_MAP;
        return isset($map[$level]) ? (float) $map[$level] : null;
    }

    public static function scoreInterview(array $context): ?float
    {
        $rating = $context['interview_rating'] ?? null;
        if ($rating === null) return null;
        // Rating 1-5 → 20-100
        return max(0.0, min(100.0, (float) $rating * 20));
    }

    // ─── DB resolvers ─────────────────────────────────────────────

    private function resolveOnlineTestScore(int $candidateId): ?float
    {
        if (! $this->db()->tableExists('online_test_assignments')) return null;
        $row = $this->db()->table('online_test_assignments')
            ->selectAvg('best_percentage', 'avg_score')
            ->where('candidate_id', $candidateId)
            ->where('status', 'COMPLETED')
            ->get()->getRowArray();
        if (! $row || $row['avg_score'] === null) return null;
        return (float) $row['avg_score'];
    }

    private function resolveInterviewRating(int $candidateId): ?float
    {
        if (! $this->db()->tableExists('candidate_stages')) return null;
        $row = $this->db()->table('candidate_stages')
            ->selectAvg('rating', 'avg_rating')
            ->where('candidate_id', $candidateId)
            ->where('rating IS NOT NULL')
            ->get()->getRowArray();
        if (! $row || $row['avg_rating'] === null) return null;
        return (float) $row['avg_rating'];
    }

    private static function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Recalculate semua kandidat untuk satu job posting.
     * Dipakai HR setelah edit kriteria scoring.
     */
    public function recalculateForJobPosting(int $jobPostingId): int
    {
        $candidates = $this->db()->table('candidates')
            ->where('job_posting_id', $jobPostingId)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $count = 0;
        foreach ($candidates as $c) {
            $this->recalculate((int) $c['id']);
            $count++;
        }
        return $count;
    }
}