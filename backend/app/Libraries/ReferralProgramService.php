<?php

namespace App\Libraries;

/**
 * ReferralProgramService (Sprint 12 / H2.5)
 *
 * Employee Referral Program — karyawan share job posting via referral link
 * unik, kandidat yang apply via link ter-tag ke referrer, bonus auto-trigger
 * setelah kandidat HIRED + lulus probasi.
 *
 * Flow:
 *   1. Karyawan klik "Share" → generate `referral_links` row dengan slug unik
 *   2. Slug embed ke URL public Career Portal (`/r/<slug>`)
 *   3. Pelamar apply via slug → `candidates.referral_link_id` di-set
 *   4. Saat probation lulus (ProbationService::decide PASS), service ini
 *      dipanggil untuk evaluasi bonus → buat bonus run.
 *
 * Bonus matching: rule paling spesifik menang (position+grade > position >
 * grade > catch-all). Kalau tidak ada rule, skip (no bonus).
 */
class ReferralProgramService
{
    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Generate referral link untuk employee + (opsional) job_posting.
     * Re-use existing aktif kalau sudah ada, agar slug konsisten.
     *
     * @return array{id: int, slug: string, url_path: string}
     */
    public function generateLink(int $employeeId, ?int $jobPostingId = null): array
    {
        $db = $this->db();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) throw new \RuntimeException('Employee tidak ditemukan');

        // Cek existing aktif
        $existing = $db->table('referral_links')
            ->where('employee_id', $employeeId)
            ->where('job_posting_id', $jobPostingId)
            ->where('is_active', 1)
            ->where('expires_at IS NULL OR expires_at > NOW()', null, false)
            ->get()->getRowArray();
        if ($existing) {
            return [
                'id'       => (int) $existing['id'],
                'slug'     => (string) $existing['slug'],
                'url_path' => '/r/' . $existing['slug'],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $slug = $this->generateSlug();
        $insert = [
            'company_id'     => (int) $emp['company_id'],
            'employee_id'    => $employeeId,
            'job_posting_id' => $jobPostingId,
            'slug'           => $slug,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+90 days')),
            'is_active'      => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
        $db->table('referral_links')->insert($insert);
        $id = (int) $db->insertID();
        return ['id' => $id, 'slug' => $slug, 'url_path' => '/r/' . $slug];
    }

    /**
     * Resolve slug ke link row (untuk public Career Portal redirect).
     * Increment click_count.
     */
    public function resolveSlug(string $slug): ?array
    {
        $db = $this->db();
        $row = $db->table('referral_links')
            ->where('slug', $slug)
            ->where('is_active', 1)
            ->get()->getRowArray();
        if (! $row) return null;
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;

        $db->table('referral_links')->where('id', (int) $row['id'])->update([
            'click_count' => (int) $row['click_count'] + 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return $row;
    }

    /**
     * Tag candidate ke referral_link saat apply via Career Portal.
     * Increment apply_count.
     */
    public function tagCandidate(int $candidateId, int $referralLinkId): void
    {
        $db = $this->db();
        $link = $db->table('referral_links')->where('id', $referralLinkId)->get()->getRowArray();
        if (! $link) return;

        $db->table('candidates')->where('id', $candidateId)->update([
            'referral_link_id'     => $referralLinkId,
            'referrer_employee_id' => (int) $link['employee_id'],
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        $db->table('referral_links')->where('id', $referralLinkId)->update([
            'apply_count' => (int) $link['apply_count'] + 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Evaluate bonus saat employee lulus probasi.
     * Match referrer + rule yang paling spesifik, return bonus_amount.
     * Caller bertanggung jawab generate bonus run.
     *
     * @return array{should_pay: bool, referrer_employee_id: int|null, bonus_amount: float, rule_id: int|null}
     */
    public function evaluateBonusForHiredEmployee(int $employeeId): array
    {
        $db = $this->db();
        // Cari candidate yang ter-convert ke employee ini
        $cand = $db->table('candidates')
            ->where('converted_employee_id', $employeeId)
            ->where('referrer_employee_id IS NOT NULL')
            ->where('referral_bonus_paid_at IS NULL')
            ->get()->getRowArray();
        if (! $cand) {
            return ['should_pay' => false, 'referrer_employee_id' => null,
                'bonus_amount' => 0.0, 'rule_id' => null];
        }

        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            return ['should_pay' => false, 'referrer_employee_id' => (int) $cand['referrer_employee_id'],
                'bonus_amount' => 0.0, 'rule_id' => null];
        }

        $rule = $this->resolveBonusRule(
            (int) $emp['company_id'],
            isset($emp['position_id']) ? (int) $emp['position_id'] : null,
            isset($emp['job_grade_id']) ? (int) $emp['job_grade_id'] : null,
        );
        if (! $rule || (float) $rule['bonus_amount'] <= 0) {
            return ['should_pay' => false,
                'referrer_employee_id' => (int) $cand['referrer_employee_id'],
                'bonus_amount' => 0.0, 'rule_id' => null];
        }

        return [
            'should_pay' => true,
            'referrer_employee_id' => (int) $cand['referrer_employee_id'],
            'bonus_amount' => (float) $rule['bonus_amount'],
            'rule_id' => (int) $rule['id'],
            'candidate_id' => (int) $cand['id'],
        ];
    }

    /**
     * Mark candidate sebagai bonus_paid setelah bonus run berhasil dibuat.
     * Idempotent — caller tidak perlu cek dulu.
     */
    public function markBonusPaid(int $candidateId): void
    {
        $this->db()->table('candidates')->where('id', $candidateId)->update([
            'referral_bonus_paid_at' => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Pure rule resolver — input set rules + employee context, output rule paling spesifik.
     * Rule scoring: position match = 2, grade match = 1, catch-all = 0.
     *
     * @param array<int, array<string,mixed>> $rules
     */
    public static function pickBestRule(array $rules, ?int $positionId, ?int $gradeId): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            if (empty($r['is_active'])) continue;
            $rPos = isset($r['position_id']) ? ($r['position_id'] !== null ? (int) $r['position_id'] : null) : null;
            $rGrade = isset($r['job_grade_id']) ? ($r['job_grade_id'] !== null ? (int) $r['job_grade_id'] : null) : null;

            // Match check: NULL di rule = catch-all, match apa pun
            if ($rPos !== null && $rPos !== $positionId) continue;
            if ($rGrade !== null && $rGrade !== $gradeId) continue;

            $score = 0;
            if ($rPos !== null) $score += 2;
            if ($rGrade !== null) $score += 1;
            if ($score > $bestScore) {
                $best = $r;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function resolveBonusRule(int $companyId, ?int $positionId, ?int $gradeId): ?array
    {
        $rules = $this->db()->table('referral_bonus_rules')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->get()->getResultArray();
        return self::pickBestRule($rules, $positionId, $gradeId);
    }

    /**
     * Top referrers untuk dashboard analytics.
     *
     * @return array<int, array<string,mixed>>
     */
    public function topReferrers(int $companyId, int $limit = 10, ?string $since = null): array
    {
        $since = $since ?: date('Y-m-d', strtotime('-365 days'));
        return $this->db()->table('candidates c')
            ->select('c.referrer_employee_id, e.full_name AS referrer_name,'
                . ' COUNT(*) AS total_referred,'
                . ' SUM(CASE WHEN c.current_stage = "HIRED" THEN 1 ELSE 0 END) AS total_hired,'
                . ' SUM(CASE WHEN c.referral_bonus_paid_at IS NOT NULL THEN 1 ELSE 0 END) AS bonus_paid_count')
            ->join('employees e', 'e.id = c.referrer_employee_id')
            ->where('c.company_id', $companyId)
            ->where('c.referrer_employee_id IS NOT NULL')
            ->where('c.created_at >=', $since)
            ->groupBy('c.referrer_employee_id, e.full_name')
            ->orderBy('total_hired', 'DESC')
            ->orderBy('total_referred', 'DESC')
            ->limit($limit)->get()->getResultArray();
    }

    private function generateSlug(): string
    {
        $tries = 0;
        do {
            $slug = strtolower(bin2hex(random_bytes(6)));
            $exists = $this->db()->table('referral_links')->where('slug', $slug)->countAllResults();
            $tries++;
        } while ($exists > 0 && $tries < 10);
        return $slug;
    }
}