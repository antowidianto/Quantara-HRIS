<?php

declare(strict_types=1);

namespace App\Repositories\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class BonusRepository
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

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginateSchemes(int $companyId, int $page, int $perPage, ?string $kind, string $search): array
    {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('bonus_schemes')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if ($kind !== null) {
            $builder->where('kind', $kind);
        }
        if ($search !== '') {
            $builder->groupStart()->like('code', $search)->orLike('name', $search)->groupEnd();
        }
        $total = $builder->countAllResults(false);
        return [
            'rows' => $builder->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)
                ->get()->getResultArray(),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function findScheme(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('bonus_schemes')
            ->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('bonus_runs')
            ->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findRecipient(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('bonus_recipients')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findEmployee(int $id, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('employees')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')
            ->get()->getRowArray() ?: null;
    }

    public function latestKpiScore(int $employeeId, int $companyId, string $referenceDate): float
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        if (! $db->tableExists('kpi_scores')) {
            return 1.0;
        }
        $row = $db->table('kpi_scores')->where('employee_id', $employeeId)
            ->where('company_id', $companyId)->where('period_end <=', $referenceDate)
            ->orderBy('period_end', 'DESC')->limit(1)->get()->getRowArray();
        return isset($row['score']) ? max(0.0, min(2.0, (float) $row['score'])) : 1.0;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginateRuns(
        int $companyId,
        int $page,
        int $perPage,
        ?string $status,
        ?int $schemeId,
        ?int $year,
    ): array {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('bonus_runs r')
            ->select('r.*, s.code AS scheme_code, s.name AS scheme_name, s.kind AS scheme_kind')
            ->join('bonus_schemes s', 's.id = r.bonus_scheme_id AND s.company_id = r.company_id', 'left')
            ->where('r.company_id', $companyId)->where('r.deleted_at IS NULL');
        if ($status !== null && $status !== '') $builder->where('r.status', $status);
        if ($schemeId !== null) $builder->where('r.bonus_scheme_id', $schemeId);
        if ($year !== null) $builder->where('r.period_year', $year);
        $total = $builder->countAllResults(false);
        return [
            'rows' => $builder->orderBy('r.id', 'DESC')->limit($perPage, ($page - 1) * $perPage)
                ->get()->getResultArray(),
            'total' => $total,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginateRecipients(int $runId, int $companyId, int $page, int $perPage): array
    {
        $this->requireCompany($companyId);
        $builder = $this->db()->table('bonus_recipients')
            ->where('bonus_run_id', $runId)->where('company_id', $companyId);
        $total = $builder->countAllResults(false);
        return [
            'rows' => $builder->orderBy('amount', 'DESC')->orderBy('employee_name', 'ASC')
                ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray(),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed> */
    public function historyDiagnostic(int $employeeId, int $companyId): array
    {
        $this->requireCompany($companyId);
        $db = $this->db();
        $recipients = $db->table('bonus_recipients r')
            ->select('r.is_excluded, run.status AS run_status')
            ->join('bonus_runs run', 'run.id = r.bonus_run_id AND run.company_id = r.company_id')
            ->where('r.employee_id', $employeeId)->where('r.company_id', $companyId)
            ->where('run.deleted_at IS NULL')->get()->getResultArray();
        $employee = $this->findEmployee($employeeId, $companyId);
        $byStatus = ['DRAFT' => 0, 'CALCULATED' => 0, 'APPROVED' => 0, 'PAID' => 0, 'CANCELLED' => 0];
        $excluded = 0;
        foreach ($recipients as $recipient) {
            $status = (string) $recipient['run_status'];
            if (isset($byStatus[$status])) $byStatus[$status]++;
            if ((int) $recipient['is_excluded'] === 1) $excluded++;
        }
        return [
            'employee_id' => $employeeId,
            'employee_company_id' => $employee ? $companyId : null,
            'employee_exists' => $employee !== null,
            'user_tenant_company_id' => $companyId,
            'filter_company_id' => $companyId,
            'cross_company' => false,
            'total_recipients_db' => count($recipients),
            'excluded_count' => $excluded,
            'recipients_by_status' => $byStatus,
            'distinct_run_companies' => $recipients === [] ? [] : [$companyId],
        ];
    }
}
