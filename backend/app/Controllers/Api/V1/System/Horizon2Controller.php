<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * Horizon2Controller — orchestrator endpoint untuk semua fitur Horizon 2
 * (Sprint 11-13). Beberapa modul kecil di-konsolidasi di satu controller
 * agar tidak bikin file controller baru per fitur yang masing-masing
 * cuma 2-3 endpoint.
 *
 * Coverage:
 *   H2.4 Candidate Scoring     → POST /candidates/{id}/recalculate-score
 *                                  POST /job-postings/{id}/recalculate-scores
 *   H2.2 Goals Cascading       → GET  /performance/goals/tree?cycle_id=
 *                                  POST /performance/goals/{id}/recompute-rollup
 *   H2.3 360° Peer Feedback    → POST /performance/cycles/{id}/peer-nominations
 *                                  GET  /performance/cycles/{id}/peer-rating?reviewee=
 *   H2.5 Employee Referral     → POST /referrals/links
 *                                  GET  /referrals/links/me
 *                                  GET  /referrals/r/{slug}     (public)
 *                                  GET  /referrals/top-referrers
 *   H2.6 Turnover Analytics    → GET  /turnover/snapshot?period_type=&period_key=
 *                                  POST /turnover/snapshot      (compute+persist)
 *                                  GET  /turnover/trend?limit=12
 *   H2.7 Payroll Simulasi      → POST /payroll/simulate
 *                                  POST /payroll/simulate/compare
 *                                  POST /payroll/simulate/reverse
 *   H2.8 Reimbursement OCR     → POST /reimbursements/{id}/ocr-extract
 *
 * Authorization: tiap endpoint cek permission yang relevan via tenant.
 */
class Horizon2Controller extends ApiController
{
    // ─── H2.4 Candidate Scoring ──────────────────────────────────

    public function recalcCandidateScore(int $candidateId)
    {
        if (! $this->canRecruit()) return $this->fail('Forbidden', 403);
        try {
            $result = Services::candidateScoring()->recalculate($candidateId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'candidate.recalc_score',
            entity: 'candidate',
            entityId: $candidateId,
            description: 'Recalculate composite score: ' . round($result['composite_score'] ?? 0, 2),
            metadata: $result,
        );
        return $this->ok($result);
    }

    public function recalcJobPostingScores(int $jobPostingId)
    {
        if (! $this->canRecruit()) return $this->fail('Forbidden', 403);
        $count = Services::candidateScoring()->recalculateForJobPosting($jobPostingId);
        return $this->ok(['updated' => $count]);
    }

    // ─── H2.2 Goals Cascading ────────────────────────────────────

    public function goalsTree()
    {
        if (! $this->canViewPerformance()) return $this->fail('Forbidden', 403);
        $cycleId = (int) $this->request->getGet('cycle_id');
        if ($cycleId <= 0) return $this->fail('cycle_id required', 422);
        $rootId = $this->request->getGet('root_goal_id');
        $rootId = $rootId !== null && $rootId !== '' ? (int) $rootId : null;
        $tree = Services::performanceCascading()->buildTree($cycleId, $rootId);
        return $this->ok($tree);
    }

    public function recomputeGoalRollup(int $goalId)
    {
        if (! $this->canManagePerformance()) return $this->fail('Forbidden', 403);
        $updated = Services::performanceCascading()->recomputeFromChild($goalId);
        return $this->ok(['updated_ancestors' => $updated]);
    }

    // ─── H2.3 360° Peer Feedback ────────────────────────────────

    public function nominatePeer(int $cycleId)
    {
        if (! $this->canManagePerformance()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reviewee_id' => 'required|integer',
            'reviewer_id' => 'required|integer',
        ]);
        if (! $valid) return null;

        try {
            $result = Services::performanceCascading()->nominate(
                $cycleId,
                (int) $data['reviewee_id'],
                (int) $data['reviewer_id'],
                strtoupper((string) ($data['source'] ?? 'SUPERVISOR')),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result, 'Peer reviewer nominated', 201);
    }

    public function peerRating(int $cycleId)
    {
        if (! $this->canViewPerformance()) return $this->fail('Forbidden', 403);
        $revieweeId = (int) $this->request->getGet('reviewee_id');
        if ($revieweeId <= 0) return $this->fail('reviewee_id required', 422);
        $result = Services::performanceCascading()->aggregatePeerRating($cycleId, $revieweeId);
        return $this->ok($result);
    }

    // ─── H2.5 Employee Referral ─────────────────────────────────

    public function createReferralLink()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Login required', 401);
        $data = $this->getJsonInput();
        // Resolve employee dari user_id
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found for this user', 404);

        $jobPostingId = ! empty($data['job_posting_id']) ? (int) $data['job_posting_id'] : null;
        $result = Services::referralProgram()->generateLink((int) $emp['id'], $jobPostingId);
        return $this->ok($result, 'Referral link generated', 201);
    }

    public function myReferralLinks()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Login required', 401);
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->ok([]);

        $rows = $db->table('referral_links rl')
            ->select('rl.*, jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('job_postings jp', 'jp.id = rl.job_posting_id', 'left')
            ->where('rl.employee_id', (int) $emp['id'])
            ->orderBy('rl.created_at', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    /**
     * Public endpoint — resolve slug ke job posting URL (atau career page).
     * Tidak butuh auth.
     */
    public function resolveReferralSlug(string $slug)
    {
        $row = Services::referralProgram()->resolveSlug($slug);
        if (! $row) return $this->fail('Referral link tidak valid atau sudah expire', 404);
        // Frontend akan redirect: kalau ada job_posting_id → ke /careers/{slug}/jobs/{id}
        // kalau null → ke /careers/{company_slug}
        return $this->ok([
            'referral_link_id' => (int) $row['id'],
            'company_id'       => (int) $row['company_id'],
            'job_posting_id'   => $row['job_posting_id'] !== null ? (int) $row['job_posting_id'] : null,
        ]);
    }

    public function topReferrers()
    {
        if (! $this->canRecruit()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $limit = min(50, max(1, (int) ($this->request->getGet('limit') ?? 10)));
        $since = $this->request->getGet('since');
        $rows = Services::referralProgram()->topReferrers($companyId, $limit,
            $since ? (string) $since : null);
        return $this->ok($rows);
    }

    // ─── H2.6 Turnover Analytics ────────────────────────────────

    public function turnoverSnapshot()
    {
        if (! $this->canViewHrAnalytics()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $periodType = strtoupper((string) ($this->request->getGet('period_type') ?? 'MONTH'));
        $periodKey  = (string) ($this->request->getGet('period_key') ?? date('Y-m'));
        if (! in_array($periodType, ['MONTH', 'YEAR'], true)) {
            return $this->fail('period_type must be MONTH or YEAR', 422);
        }
        $result = Services::turnoverAnalytics()->compute($companyId, $periodType, $periodKey);
        return $this->ok($result);
    }

    public function turnoverSnapshotPersist()
    {
        if (! $this->canManageHrAnalytics()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $data = $this->getJsonInput();
        $periodType = strtoupper((string) ($data['period_type'] ?? 'MONTH'));
        $periodKey  = (string) ($data['period_key'] ?? date('Y-m'));
        if (! in_array($periodType, ['MONTH', 'YEAR'], true)) {
            return $this->fail('period_type must be MONTH or YEAR', 422);
        }
        $result = Services::turnoverAnalytics()->snapshot($companyId, $periodType, $periodKey);
        Services::audit()->forModule('HR_ANALYTICS')->log(
            action: 'turnover.snapshot',
            entity: 'turnover_metrics_snapshot',
            entityId: (int) ($result['id'] ?? 0),
            description: "Turnover snapshot {$periodType}/{$periodKey} computed",
            metadata: $result,
        );
        return $this->ok($result);
    }

    public function turnoverTrend()
    {
        if (! $this->canViewHrAnalytics()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $periodType = strtoupper((string) ($this->request->getGet('period_type') ?? 'MONTH'));
        $limit = min(60, max(1, (int) ($this->request->getGet('limit') ?? 12)));
        $rows = Services::turnoverAnalytics()->trend($companyId, $periodType, $limit);
        return $this->ok($rows);
    }

    // ─── H2.7 Payroll Simulasi ──────────────────────────────────

    public function simulatePayroll()
    {
        if (! $this->canSimulatePayroll()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        if (! isset($data['company_id']) || (int) $data['company_id'] <= 0) {
            $data['company_id'] = $this->companyId();
        }
        try {
            $result = Services::payrollSimulation()->simulate($data);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function simulatePayrollCompare()
    {
        if (! $this->canSimulatePayroll()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $current = $data['current'] ?? [];
        $proposed = $data['proposed'] ?? [];
        if (empty($current) || empty($proposed)) {
            return $this->fail('current dan proposed wajib', 422);
        }
        if (! isset($current['company_id'])) $current['company_id'] = $this->companyId();
        if (! isset($proposed['company_id'])) $proposed['company_id'] = $this->companyId();
        try {
            $result = Services::payrollSimulation()->compare($current, $proposed);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function simulatePayrollReverse()
    {
        if (! $this->canSimulatePayroll()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $target = (float) ($data['target_net'] ?? 0);
        if ($target <= 0) return $this->fail('target_net wajib > 0', 422);
        $base = $data['base'] ?? [];
        if (! isset($base['company_id'])) $base['company_id'] = $this->companyId();
        try {
            $result = Services::payrollSimulation()->reverseFromNet($target, $base);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    // ─── H2.8 Reimbursement OCR ─────────────────────────────────

    public function ocrExtractReimbursement(int $reimbursementId)
    {
        if (! $this->canManageReimbursement()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('reimbursements')->where('id', $reimbursementId)->get()->getRowArray();
        if (! $row) return $this->fail('Reimbursement not found', 404);

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $attachmentPath = $row['attachment_path'] ?? null;
        if (! $attachmentPath) {
            return $this->fail('Reimbursement belum punya attachment untuk di-OCR', 422);
        }
        // Resolve path absolut via FileStorage kalau perlu
        $fullPath = $attachmentPath;
        if (! is_file($fullPath)) {
            // Coba resolve relative ke writable dir
            $candidate = WRITEPATH . 'uploads/' . ltrim($attachmentPath, '/');
            if (is_file($candidate)) $fullPath = $candidate;
        }
        try {
            $result = Services::ocr()->extractAndPersist($reimbursementId, $fullPath);
        } catch (\Throwable $e) {
            return $this->fail('OCR extract gagal: ' . $e->getMessage(), 422);
        }
        Services::audit()->forModule('REIMBURSEMENT')->log(
            action: 'reimbursement.ocr_extract',
            entity: 'reimbursement',
            entityId: $reimbursementId,
            description: "OCR extract via {$result['driver']}, confidence={$result['confidence']}",
            metadata: ['driver' => $result['driver'], 'confidence' => $result['confidence']],
        );
        return $this->ok($result);
    }

    // ─── Authorization helpers ──────────────────────────────────

    private function canRecruit(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('recruitment.manage')
            || $t->hasPermission('candidate.manage');
    }

    private function canViewPerformance(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('performance.view')
            || $t->hasPermission('performance.manage');
    }

    private function canManagePerformance(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('performance.manage');
    }

    private function canViewHrAnalytics(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('hr_analytics.view')
            || $t->hasPermission('compliance.report.view');
    }

    private function canManageHrAnalytics(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('hr_analytics.manage');
    }

    private function canSimulatePayroll(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('payroll.simulate')
            || $t->hasPermission('payroll.manage')
            || $t->hasPermission('recruitment.manage'); // HR/recruiter pakai untuk offer
    }

    private function canManageReimbursement(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('reimbursement.manage')
            || $t->hasPermission('reimbursement.create');
    }
}