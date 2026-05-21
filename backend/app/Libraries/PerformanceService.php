<?php

namespace App\Libraries;

/**
 * PerformanceService — orkestrasi cycle, goals, check-ins, reviews.
 *
 * Tanggung jawab:
 *   1. State machine cycle: DRAFT → OPEN → GOAL_SETTING → IN_PROGRESS → REVIEW → CLOSED
 *   2. Goal weight validation (total per employee per cycle = 100)
 *   3. Auto-update progress dari current_value/target_value
 *   4. Check-in trigger update goal status & current_value
 *   5. Compute overall_score dari weighted goal scores
 *   6. Auto-derive rating dari overall_score (rubric configurable)
 */
class PerformanceService
{
    public const RATING_RUBRIC = [
        // score >= => rating
        90 => 'OUTSTANDING',
        80 => 'EXCEEDS',
        65 => 'MEETS',
        50 => 'NEEDS_IMPROVEMENT',
        0  => 'UNSATISFACTORY',
    ];

    private function db() { return \Config\Database::connect(); }

    // ─── Cycle ──────────────────────────────────────────────────────

    /**
     * Validasi total weight goals per (cycle, employee) = 100.
     * Return: ['ok'=>bool, 'total'=>float, 'missing'=>float]
     */
    public function validateWeights(int $cycleId, int $employeeId): array
    {
        $rows = $this->db()->table('goals')
            ->select('weight')
            ->where('cycle_id', $cycleId)
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['CANCELLED'])
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $total = 0;
        foreach ($rows as $r) $total += (float) $r['weight'];

        return [
            'ok'      => abs($total - 100) < 0.01,
            'total'   => $total,
            'missing' => max(0, 100 - $total),
        ];
    }

    /**
     * Update goal progress berdasarkan current_value/target_value.
     * Tidak override final_score.
     */
    public function recomputeGoalProgress(int $goalId): array
    {
        $g = $this->db()->table('goals')->where('id', $goalId)->get()->getRowArray();
        if (! $g) throw new \RuntimeException('Goal tidak ditemukan');

        $progress = 0;
        $type = $g['measurement_type'];
        $current = (float) $g['current_value'];
        $target  = (float) $g['target_value'];

        if ($type === 'BINARY') {
            // current 1 = achieved 100%, 0 = 0%
            $progress = $current >= 1 ? 100 : 0;
        } elseif ($target > 0) {
            // PERCENT, NUMBER, CURRENCY, MILESTONE — semua diasumsikan target tercapai = 100%
            $progress = min(100, ($current / $target) * 100);
        }

        // Auto-derive status kalau approved/in_progress
        $newStatus = $g['status'];
        if (in_array($newStatus, ['APPROVED','IN_PROGRESS'], true)) {
            if ($progress >= 100) $newStatus = 'ACHIEVED';
            elseif ($progress > 0) $newStatus = 'IN_PROGRESS';
        }

        $this->db()->table('goals')->where('id', $goalId)->update([
            'progress_percent' => round($progress, 2),
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['progress' => round($progress, 2), 'status' => $newStatus];
    }

    /**
     * Submit check-in: update goal.current_value + create check_in row.
     */
    public function submitCheckIn(int $goalId, int $employeeId, array $data): array
    {
        $g = $this->db()->table('goals')->where('id', $goalId)->get()->getRowArray();
        if (! $g) throw new \RuntimeException('Goal tidak ditemukan');
        if ((int) $g['employee_id'] !== $employeeId) {
            throw new \RuntimeException('Hanya pemilik goal yang bisa check-in');
        }
        if ($g['status'] === 'CANCELLED' || $g['status'] === 'DRAFT') {
            throw new \RuntimeException("Tidak bisa check-in goal status {$g['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $progressValue = isset($data['progress_value']) ? (float) $data['progress_value'] : (float) $g['current_value'];

        // Update goal current_value first
        $this->db()->table('goals')->where('id', $goalId)->update([
            'current_value' => $progressValue,
            'updated_at' => $now,
        ]);
        $recomp = $this->recomputeGoalProgress($goalId);

        $insert = [
            'company_id'    => (int) $g['company_id'],
            'goal_id'       => $goalId,
            'employee_id'   => $employeeId,
            'check_in_date' => $data['check_in_date'] ?? date('Y-m-d'),
            'progress_value' => $progressValue,
            'progress_percent' => $recomp['progress'],
            'status_color'  => strtoupper($data['status_color'] ?? 'GREEN'),
            'comment'       => $data['comment'] ?? null,
            'attachment'    => $data['attachment'] ?? null,
            'created_at'    => $now,
        ];
        $this->db()->table('goal_check_ins')->insert($insert);
        $checkInId = (int) $this->db()->insertID();

        return [
            'check_in_id' => $checkInId,
            'progress_percent' => $recomp['progress'],
            'goal_status' => $recomp['status'],
        ];
    }

    /**
     * Compute overall_score dari weighted goal scores.
     *
     * @param array $goalScores  ['goal_id' => score 0-100, ...]
     */
    public function computeOverallScore(int $cycleId, int $employeeId, array $goalScores): array
    {
        $goals = $this->db()->table('goals')
            ->select('id, weight, title')
            ->where('cycle_id', $cycleId)
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['CANCELLED'])
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $weighted = 0;
        $totalWeight = 0;
        $breakdown = [];

        foreach ($goals as $g) {
            $w = (float) $g['weight'];
            $score = isset($goalScores[$g['id']]) ? (float) $goalScores[$g['id']] : null;
            if ($score === null) continue;

            $weighted += ($score * $w) / 100;
            $totalWeight += $w;
            $breakdown[$g['id']] = [
                'title' => $g['title'],
                'weight' => $w,
                'score' => $score,
                'contribution' => round(($score * $w) / 100, 2),
            ];
        }

        // Kalau totalWeight < 100 (belum semua di-score), normalize ke total yg ada
        $overall = $totalWeight > 0 ? ($weighted * 100 / $totalWeight) : 0;
        $overall = round($overall, 2);

        $rating = $this->scoreToRating($overall);

        return [
            'overall_score' => $overall,
            'rating' => $rating,
            'breakdown' => $breakdown,
            'total_weight_scored' => $totalWeight,
        ];
    }

    public function scoreToRating(float $score): string
    {
        foreach (self::RATING_RUBRIC as $threshold => $rating) {
            if ($score >= $threshold) return $rating;
        }
        return 'UNSATISFACTORY';
    }

    /**
     * Submit review: hitung overall_score dari goal_scores, simpan ke row.
     */
    public function submitReview(int $reviewId, array $data): array
    {
        $r = $this->db()->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
        if (! $r) throw new \RuntimeException('Review tidak ditemukan');
        if (! in_array($r['status'], ['DRAFT'], true)) {
            throw new \RuntimeException("Hanya status DRAFT yang bisa di-submit, current: {$r['status']}");
        }

        $goalScores = $data['goal_scores'] ?? [];
        $compScores = $data['competency_scores'] ?? [];

        $computed = $this->computeOverallScore((int) $r['cycle_id'], (int) $r['employee_id'], $goalScores);

        // Kalau ada competency score, blend 70% goal + 30% competency
        $compAvg = null;
        if (! empty($compScores)) {
            $compAvg = array_sum(array_map('floatval', $compScores)) / max(1, count($compScores));
            $blendedScore = ($computed['overall_score'] * 0.7) + ($compAvg * 0.3);
            $computed['overall_score'] = round($blendedScore, 2);
            $computed['rating'] = $this->scoreToRating($computed['overall_score']);
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'overall_score'    => $computed['overall_score'],
            'rating'           => $computed['rating'],
            'goal_scores'      => json_encode($goalScores),
            'competency_scores' => json_encode($compScores),
            'strengths'        => $data['strengths'] ?? null,
            'improvements'     => $data['improvements'] ?? null,
            'development_plan' => $data['development_plan'] ?? null,
            'reviewer_comment' => $data['reviewer_comment'] ?? null,
            'recommendation'   => $data['recommendation'] ?? null,
            'recommended_increase_percent' => $data['recommended_increase_percent'] ?? null,
            'status'           => 'SUBMITTED',
            'submitted_at'     => $now,
            'updated_at'       => $now,
        ];
        $this->db()->table('performance_reviews')->where('id', $reviewId)->update($update);

        // Update goal final_score
        foreach ($goalScores as $gid => $score) {
            $this->db()->table('goals')->where('id', (int) $gid)->update([
                'final_score' => (float) $score,
                'updated_at' => $now,
            ]);
        }

        return [
            'overall_score' => $computed['overall_score'],
            'rating' => $computed['rating'],
            'breakdown' => $computed['breakdown'],
        ];
    }

    /**
     * Acknowledge by employee.
     */
    public function acknowledgeReview(int $reviewId, int $employeeId, array $data = []): void
    {
        $r = $this->db()->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
        if (! $r) throw new \RuntimeException('Review tidak ditemukan');
        if ((int) $r['employee_id'] !== $employeeId) {
            throw new \RuntimeException('Hanya yang bersangkutan yang bisa acknowledge');
        }
        if ($r['status'] !== 'SUBMITTED') {
            throw new \RuntimeException("Review harus SUBMITTED, current: {$r['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('performance_reviews')->where('id', $reviewId)->update([
            'status' => 'ACKNOWLEDGED',
            'acknowledged_at' => $now,
            'employee_comment' => $data['employee_comment'] ?? null,
            'updated_at' => $now,
        ]);
    }

    /**
     * Finalize (HR action — locks).
     */
    public function finalizeReview(int $reviewId): void
    {
        $r = $this->db()->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
        if (! $r) throw new \RuntimeException('Review tidak ditemukan');
        if (! in_array($r['status'], ['ACKNOWLEDGED','SUBMITTED'], true)) {
            throw new \RuntimeException("Tidak bisa finalize status {$r['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('performance_reviews')->where('id', $reviewId)->update([
            'status' => 'FINALIZED',
            'finalized_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Stats per cycle.
     */
    public function cycleStats(int $cycleId): array
    {
        $db = $this->db();
        // Goals
        $goalRows = $db->table('goals')
            ->select('status, weight, progress_percent, final_score, employee_id')
            ->where('cycle_id', $cycleId)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $byStatus = [];
        $employees = [];
        $totalProgress = 0;
        foreach ($goalRows as $g) {
            $byStatus[$g['status']] = ($byStatus[$g['status']] ?? 0) + 1;
            $employees[$g['employee_id']] = true;
            $totalProgress += (float) $g['progress_percent'];
        }
        $avgProgress = count($goalRows) ? round($totalProgress / count($goalRows), 2) : 0;

        // Reviews
        $reviewRows = $db->table('performance_reviews')
            ->select('status, rating, overall_score')
            ->where('cycle_id', $cycleId)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();
        $reviewsByStatus = [];
        $ratingDist = [];
        foreach ($reviewRows as $r) {
            $reviewsByStatus[$r['status']] = ($reviewsByStatus[$r['status']] ?? 0) + 1;
            if (! empty($r['rating'])) {
                $ratingDist[$r['rating']] = ($ratingDist[$r['rating']] ?? 0) + 1;
            }
        }

        return [
            'goals' => [
                'total' => count($goalRows),
                'by_status' => $byStatus,
                'avg_progress' => $avgProgress,
                'unique_employees' => count($employees),
            ],
            'reviews' => [
                'total' => count($reviewRows),
                'by_status' => $reviewsByStatus,
                'rating_distribution' => $ratingDist,
            ],
        ];
    }
}