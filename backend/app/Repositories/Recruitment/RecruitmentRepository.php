<?php

declare(strict_types=1);

namespace App\Repositories\Recruitment;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class RecruitmentRepository
{
    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) {
            throw new \DomainException('Active company context is required');
        }
    }

    public function nextJobReferenceNumber(int $companyId, string $year): string
    {
        $this->requireCompany($companyId);
        $prefix = "JOB-{$year}-";
        $db = $this->db();
        $count = $db->table('job_postings')
            ->where('company_id', $companyId)
            ->like('reference_number', $prefix, 'after')
            ->countAllResults();
        $candidate = $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);

        for ($tries = 0; $tries < 50; $tries++, $count++) {
            $exists = $db->table('job_postings')
                ->where('company_id', $companyId)
                ->where('reference_number', $candidate)
                ->countAllResults();
            if ($exists === 0) {
                return $candidate;
            }
            $candidate = $prefix . str_pad((string) ($count + 2), 5, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /** @return array<string, mixed>|null */
    public function findCandidate(int $candidateId, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('candidates')
            ->where('id', $candidateId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function funnelMetrics(?int $jobPostingId, int $companyId, string $since): array
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        if (! $db->tableExists('candidate_stage_history')) {
            return [];
        }
        $builder = $db->table('candidate_stage_history h')
            ->select('h.stage, COUNT(*) AS sample, AVG(h.duration_hours) AS avg_hours, MAX(h.duration_hours) AS max_hours')
            ->join('candidates c', 'c.id = h.candidate_id AND c.company_id = h.company_id')
            ->where('h.company_id', $companyId)
            ->where('h.exited_at IS NOT NULL')
            ->where('h.entered_at >=', $since . ' 00:00:00')
            ->groupBy('h.stage');
        if ($jobPostingId !== null) {
            $builder->where('c.job_posting_id', $jobPostingId);
        }
        return $builder->get()->getResultArray();
    }

    /** @param list<string> $terminalStages @return list<array<string, mixed>> */
    public function staleCandidates(int $companyId, string $cutoff, array $terminalStages): array
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        if (! $db->tableExists('candidate_stage_history')) {
            return [];
        }
        return $db->table('candidate_stage_history h')
            ->select('h.id, h.candidate_id, h.stage, h.entered_at, c.full_name, c.email, c.job_posting_id, jp.title AS job_title')
            ->join('candidates c', 'c.id = h.candidate_id AND c.company_id = h.company_id')
            ->join('job_postings jp', 'jp.id = c.job_posting_id AND jp.company_id = h.company_id', 'left')
            ->where('h.company_id', $companyId)
            ->where('h.exited_at IS NULL')
            ->where('h.entered_at <', $cutoff)
            ->whereNotIn('h.stage', $terminalStages)
            ->orderBy('h.entered_at', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function onlineTestAssignments(int $candidateId, int $companyId, ?int $categoryId = null): array
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        if (! $db->tableExists('online_test_assignments')) {
            return [];
        }
        $builder = $db->table('online_test_assignments ota')
            ->select('ota.id, ota.status, ota.best_result, ot.name AS test_name')
            ->join('online_tests ot', 'ot.id = ota.online_test_id AND ot.company_id = ota.company_id')
            ->where('ota.candidate_id', $candidateId)
            ->where('ota.company_id', $companyId);
        if ($categoryId !== null) {
            $builder->where('ot.category_id', $categoryId);
        }
        return $builder->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function pipelineCounts(int $companyId, ?int $jobPostingId): array
    {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('candidates')
            ->select('current_stage, COUNT(*) AS total')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if ($jobPostingId !== null) {
            $builder->where('job_posting_id', $jobPostingId);
        }
        return $builder->groupBy('current_stage')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function resolvePipelineStages(int $jobPostingId, int $companyId): array
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        if ($jobPostingId <= 0 || ! $db->tableExists('recruitment_pipeline_stages')) {
            return [];
        }
        $job = $db->table('job_postings')
            ->where('id', $jobPostingId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $job) {
            return [];
        }
        $templateId = (int) ($job['pipeline_template_id'] ?? 0);
        if ($templateId === 0) {
            $default = $db->table('recruitment_pipeline_templates')
                ->where('company_id', $companyId)
                ->where('is_default', 1)
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->limit(1)->get()->getRowArray();
            $templateId = (int) ($default['id'] ?? 0);
        }
        if ($templateId === 0) {
            return [];
        }
        return $db->table('recruitment_pipeline_stages s')
            ->join('recruitment_pipeline_templates t', 't.id = s.template_id')
            ->where('s.template_id', $templateId)
            ->where('t.company_id', $companyId)
            ->orderBy('s.order_index', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->select('s.*')
            ->get()->getResultArray();
    }

    public function onlineTestCategoryName(int $categoryId, int $companyId): string
    {
        $this->requireCompany($companyId);
        $row = $this->db()->table('online_test_categories')
            ->select('name')
            ->where('id', $categoryId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        return (string) ($row['name'] ?? "kategori #{$categoryId}");
    }
}
