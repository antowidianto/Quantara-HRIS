<?php

declare(strict_types=1);

namespace App\Repositories\Recruitment;

use CodeIgniter\Database\BaseConnection;

/** Tenant-aware read model for online-test administration. */
final class OnlineTestRepository
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function paginateTests(int $companyId, int $page, int $perPage, string $category = ''): array
    {
        $this->assertCompany($companyId);
        $builder = $this->db->table('online_tests')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if ($category !== '') $builder->where('category', $category);

        $total = (clone $builder)->countAllResults(false);
        $rows = $builder->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function paginateAssignments(int $companyId, int $page, int $perPage, array $filters): array
    {
        $this->assertCompany($companyId);
        $builder = $this->db->table('online_test_assignments oa')
            ->select('oa.*, c.full_name AS candidate_name, c.email AS candidate_email,'
                . ' ot.name AS test_name, ot.category AS test_category,'
                . ' jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('candidates c', 'c.id = oa.candidate_id AND c.company_id = oa.company_id')
            ->join('online_tests ot', 'ot.id = oa.online_test_id AND ot.company_id = oa.company_id')
            ->join('job_postings jp', 'jp.id = oa.job_posting_id AND jp.company_id = oa.company_id', 'left')
            ->where('oa.company_id', $companyId);

        if (($filters['status'] ?? '') !== '') $builder->where('oa.status', $filters['status']);
        if ((int) ($filters['candidate_id'] ?? 0) > 0) $builder->where('oa.candidate_id', (int) $filters['candidate_id']);
        if ((int) ($filters['online_test_id'] ?? 0) > 0) $builder->where('oa.online_test_id', (int) $filters['online_test_id']);

        $total = (clone $builder)->countAllResults(false);
        $rows = $builder->orderBy('oa.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function paginateBank(int $companyId, int $page, int $perPage, array $filters): array
    {
        $this->assertCompany($companyId);
        $builder = $this->db->table('online_test_question_bank')
            ->where('company_id', $companyId)->where('deleted_at IS NULL');
        foreach (['category', 'difficulty', 'question_type'] as $filter) {
            if (($filters[$filter] ?? '') !== '') $builder->where($filter, $filters[$filter]);
        }
        if (($filters['q'] ?? '') !== '') $builder->like('prompt', $filters['q']);

        $total = (clone $builder)->countAllResults(false);
        $rows = $builder->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findTest(int $id, int $companyId): ?array
    {
        $this->assertCompany($companyId);
        $row = $this->db->table('online_tests')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')
            ->get()->getRowArray();
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listQuestions(int $testId, int $companyId): array
    {
        $this->assertCompany($companyId);
        return $this->db->table('online_test_questions q')
            ->select('q.*')->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.online_test_id', $testId)->where('t.company_id', $companyId)
            ->orderBy('q.sort_order', 'ASC')->orderBy('q.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function findQuestion(int $id, int $companyId): ?array
    {
        $this->assertCompany($companyId);
        $row = $this->db->table('online_test_questions q')
            ->select('q.*')->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.id', $id)->where('t.company_id', $companyId)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findBankQuestion(int $id, int $companyId): ?array
    {
        return $this->findCompanyRow('online_test_question_bank', $id, $companyId);
    }

    /** @return array<string, mixed>|null */
    public function findAssignment(int $id, int $companyId): ?array
    {
        return $this->findCompanyRow('online_test_assignments', $id, $companyId);
    }

    /** @return array<string, mixed>|null */
    public function findCandidate(int $id, int $companyId): ?array
    {
        return $this->findCompanyRow('candidates', $id, $companyId);
    }

    /** @return array<string, mixed>|null */
    public function findAttempt(int $id, int $companyId): ?array
    {
        $this->assertCompany($companyId);
        $row = $this->db->table('online_test_attempts a')
            ->select('a.*')->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.id', $id)->where('x.company_id', $companyId)
            ->get()->getRowArray();
        return $row ?: null;
    }

    public function countQuestions(int $testId, int $companyId): int
    {
        $this->assertCompany($companyId);
        return $this->db->table('online_test_questions q')
            ->join('online_tests t', 't.id = q.online_test_id')
            ->where('q.online_test_id', $testId)->where('t.company_id', $companyId)
            ->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    public function listAttempts(int $assignmentId, int $companyId): array
    {
        $this->assertCompany($companyId);
        return $this->db->table('online_test_attempts a')
            ->select('a.*')->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', $assignmentId)->where('x.company_id', $companyId)
            ->orderBy('a.attempt_number', 'ASC')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    private function findCompanyRow(string $table, int $id, int $companyId): ?array
    {
        $this->assertCompany($companyId);
        $row = $this->db->table($table)->where('id', $id)
            ->where('company_id', $companyId)->get()->getRowArray();
        return $row ?: null;
    }

    private function assertCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required');
    }
}
