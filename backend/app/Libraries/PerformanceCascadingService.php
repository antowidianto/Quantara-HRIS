<?php

namespace App\Libraries;

/**
 * PerformanceCascadingService (Sprint 11 / H2.2 + H2.3)
 *
 * H2.2 Goals Cascading:
 *   - Build goal tree dari `goals.parent_id` (existing column dari migration 000024).
 *   - Hitung weighted progress parent dari child progress.
 *   - Cycle detection (parent ↔ child ↔ ... loop).
 *
 * H2.3 360° Peer Feedback:
 *   - Nominasi peer reviewer per cycle × reviewee.
 *   - Aggregate peer review secara anonim (min N reviewer untuk anonimitas).
 *   - Hitung final rating: weighted avg dari self/supervisor/peer per
 *     bobot di `performance_cycles`.
 *
 * Pure formulas:
 *   - rollupProgress: parent.progress = sum(child.progress * child.weight) / sum(child.weight)
 *   - finalRating:    sum(rating_type × weight_type) / sum(weight_type)
 *                      kalau peer_count < min_reviewers, peer_rating dianggap NULL
 */
class PerformanceCascadingService
{
    public const ALL_REVIEW_TYPES = ['SELF', 'SUPERVISOR', 'PEER', 'SUBORDINATE'];

    private function db()
    {
        return \Config\Database::connect();
    }

    // ─── H2.2 Goals Cascading ─────────────────────────────────────

    /**
     * Build goal tree dari root (goals tanpa parent_id) untuk cycle tertentu.
     * Recursive — output terstruktur untuk visualisasi tree.
     *
     * @return array<int, array<string,mixed>>
     */
    public function buildTree(int $cycleId, ?int $rootGoalId = null): array
    {
        $rows = $this->db()->table('goals')
            ->where('cycle_id', $cycleId)
            ->orderBy('parent_id', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return self::treeFromRows($rows, $rootGoalId);
    }

    /**
     * Pure tree builder — input flat list, output nested tree.
     * Bisa di-test tanpa DB.
     *
     * @param array<int, array<string,mixed>> $rows
     * @param int|null $rootGoalId Filter: hanya tree di bawah goal ini
     * @return array<int, array<string,mixed>>
     */
    public static function treeFromRows(array $rows, ?int $rootGoalId = null): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[(int) $r['id']] = $r;
        }
        $tree = [];
        foreach ($byId as $id => &$row) {
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$row;
            } elseif ($rootGoalId === null) {
                // No parent → root
                $tree[] = &$row;
            }
        }
        unset($row);

        // Filter ke subtree dari rootGoalId saja kalau dispesifikkan
        if ($rootGoalId !== null) {
            return isset($byId[$rootGoalId]) ? [$byId[$rootGoalId]] : [];
        }
        return $tree;
    }

    /**
     * Cycle detection: cek apakah set parent_id baru akan membuat loop.
     * Return true kalau aman (no cycle), false kalau membuat cycle.
     */
    public function canSetParent(int $childId, int $proposedParentId): bool
    {
        if ($childId === $proposedParentId) return false;

        // Walk up dari proposedParent → kalau ketemu childId, ada cycle
        $db = $this->db();
        $current = $proposedParentId;
        $visited = [];
        $maxDepth = 50;
        while ($current !== null && $maxDepth-- > 0) {
            if (in_array($current, $visited, true)) return false;
            if ($current === $childId) return false;
            $visited[] = $current;
            $row = $db->table('goals')->select('parent_id')->where('id', $current)->get()->getRowArray();
            if (! $row || $row['parent_id'] === null) break;
            $current = (int) $row['parent_id'];
        }
        return true;
    }

    /**
     * Hitung progress parent goal sebagai weighted avg dari child progress.
     * Kalau goal tidak punya children, return progress-nya sendiri.
     *
     * @return float Progress 0-100
     */
    public static function rollupProgress(array $goalNode): float
    {
        $children = $goalNode['children'] ?? [];
        if (empty($children)) {
            return (float) ($goalNode['progress_percent'] ?? 0);
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        foreach ($children as $child) {
            $w = (float) ($child['weight'] ?? 1);
            if ($w <= 0) continue;
            $childProgress = self::rollupProgress($child);
            $totalWeight += $w;
            $weightedSum += $childProgress * $w;
        }
        if ($totalWeight <= 0) return (float) ($goalNode['progress_percent'] ?? 0);
        return round($weightedSum / $totalWeight, 2);
    }

    /**
     * Recompute & persist progress parent setelah child update.
     * Walk dari leaf → root, update tiap parent.
     */
    public function recomputeFromChild(int $goalId): int
    {
        $updated = 0;
        $db = $this->db();
        $current = $goalId;
        $maxDepth = 50;
        while ($current !== null && $maxDepth-- > 0) {
            $row = $db->table('goals')->where('id', $current)->get()->getRowArray();
            if (! $row || $row['parent_id'] === null) break;
            $parentId = (int) $row['parent_id'];

            // Compute parent's new progress dari semua children
            $childRows = $db->table('goals')
                ->where('parent_id', $parentId)
                ->get()->getResultArray();
            $totalWeight = 0.0;
            $weightedSum = 0.0;
            foreach ($childRows as $c) {
                $w = (float) ($c['weight'] ?? 1);
                if ($w <= 0) continue;
                $totalWeight += $w;
                $weightedSum += (float) ($c['progress_percent'] ?? 0) * $w;
            }
            $newProgress = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
            $db->table('goals')->where('id', $parentId)->update([
                'progress_percent' => $newProgress,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            $updated++;
            $current = $parentId;
        }
        return $updated;
    }

    // ─── H2.3 360° Peer Feedback ──────────────────────────────────

    /**
     * Nominasi peer reviewer untuk reviewee tertentu.
     *
     * @return array{id: int, status: string}
     */
    public function nominate(
        int $cycleId,
        int $revieweeId,
        int $reviewerId,
        string $source = 'SUPERVISOR',
    ): array {
        if ($revieweeId === $reviewerId) {
            throw new \RuntimeException('Reviewer tidak boleh sama dengan reviewee');
        }
        if (! in_array($source, ['SELF', 'SUPERVISOR', 'HR'], true)) {
            throw new \RuntimeException('Source nominasi tidak valid');
        }

        $cycle = $this->db()->table('performance_cycles')->where('id', $cycleId)->get()->getRowArray();
        if (! $cycle) throw new \RuntimeException('Cycle tidak ditemukan');
        if (empty($cycle['peer_review_enabled'])) {
            throw new \RuntimeException('Peer review tidak aktif untuk cycle ini');
        }

        $existing = $this->db()->table('peer_review_nominations')
            ->where('cycle_id', $cycleId)
            ->where('reviewee_id', $revieweeId)
            ->where('reviewer_id', $reviewerId)
            ->get()->getRowArray();
        if ($existing) {
            return ['id' => (int) $existing['id'], 'status' => (string) $existing['status']];
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => (int) ($cycle['company_id'] ?? 0),
            'cycle_id'          => $cycleId,
            'reviewee_id'       => $revieweeId,
            'reviewer_id'       => $reviewerId,
            'nomination_source' => $source,
            'status'            => $source === 'SELF' ? 'PENDING' : 'APPROVED',
            'approved_at'       => $source === 'SELF' ? null : $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $this->db()->table('peer_review_nominations')->insert($insert);
        return ['id' => (int) $this->db()->insertID(), 'status' => $insert['status']];
    }

    /**
     * Aggregate hasil peer review ke 1 angka rating + flag anonimitas.
     * Return null kalau jumlah peer reviewer < min_reviewers (privacy).
     *
     * @return array{rating: float|null, count: int, anonymous: bool}
     */
    public function aggregatePeerRating(int $cycleId, int $revieweeId): array
    {
        $cycle = $this->db()->table('performance_cycles')->where('id', $cycleId)->get()->getRowArray();
        $minReviewers = (int) ($cycle['peer_min_reviewers'] ?? 3);

        $rows = $this->db()->table('peer_review_nominations n')
            ->select('pr.final_score AS score')
            ->join('performance_reviews pr', 'pr.id = n.review_id', 'inner')
            ->where('n.cycle_id', $cycleId)
            ->where('n.reviewee_id', $revieweeId)
            ->where('n.status', 'COMPLETED')
            ->where('pr.final_score IS NOT NULL')
            ->get()->getResultArray();

        $count = count($rows);
        if ($count < $minReviewers) {
            return ['rating' => null, 'count' => $count, 'anonymous' => false,
                'min_required' => $minReviewers];
        }
        $sum = 0.0;
        foreach ($rows as $r) $sum += (float) $r['score'];
        return [
            'rating' => round($sum / $count, 2),
            'count' => $count,
            'anonymous' => true,
            'min_required' => $minReviewers,
        ];
    }

    /**
     * Pure final rating calculator — input scores per type + weights, output
     * weighted average. Kalau salah satu rating null (mis. peer < min),
     * weight-nya di-skip dan total dinormalize ulang.
     *
     * @param array{self?: float|null, supervisor?: float|null, peer?: float|null} $ratings
     * @param array{self_weight?: float, supervisor_weight?: float, peer_weight?: float} $weights
     */
    public static function computeFinalRating(array $ratings, array $weights): ?float
    {
        $totalWeight = 0.0;
        $weightedSum = 0.0;
        foreach (['self', 'supervisor', 'peer'] as $type) {
            $rating = $ratings[$type] ?? null;
            if ($rating === null) continue;
            $w = (float) ($weights[$type . '_weight'] ?? 0);
            if ($w <= 0) continue;
            $totalWeight += $w;
            $weightedSum += (float) $rating * $w;
        }
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
    }
}