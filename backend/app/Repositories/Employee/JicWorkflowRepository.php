<?php

declare(strict_types=1);

namespace App\Repositories\Employee;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class JicWorkflowRepository
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
        if ($companyId <= 0) throw new \DomainException('Active company context is required for JIC operations');
    }

    /** @return array<string,mixed>|null */
    public function employee(int $employeeId, int $companyId): ?array
    {
        $this->requireCompany($companyId);
        return $this->db()->table('employees')->where('id', $employeeId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')
            ->get()->getRowArray() ?: null;
    }

    public function hasActiveRequest(int $employeeId, int $companyId): bool
    {
        $this->requireCompany($companyId);
        return $this->db()->table('job_information_changes')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->whereIn('status', [
                'DRAFT', 'PENDING_SUPERVISOR', 'PENDING_DEPT_HEAD', 'PENDING_HR',
                'PENDING_BOD', 'PENDING_EMPLOYEE_CONSENT', 'APPROVED',
            ])->where('deleted_at IS NULL')->countAllResults() > 0;
    }

    public function positionTier(int $positionId, int $companyId): ?string
    {
        $this->requireCompany($companyId);
        $row = $this->db()->table('positions')->select('tier')->where('id', $positionId)
            ->where('company_id', $companyId)->get()->getRowArray();
        return $row ? (string) ($row['tier'] ?? 'STAFF') : null;
    }

    /** @param array<string,mixed> $payload */
    public function create(array $payload, int $companyId): int
    {
        $this->requireCompany($companyId);
        if ((int) ($payload['company_id'] ?? 0) !== $companyId) throw new \DomainException('JIC tenant mismatch');
        $db = $this->db();
        $db->table('job_information_changes')->insert($payload);
        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $payload */
    public function update(int $id, int $companyId, array $payload): void
    {
        $this->requireCompany($companyId);
        $this->db()->table('job_information_changes')->where('id', $id)
            ->where('company_id', $companyId)->update($payload);
    }

    public function softDelete(int $id, int $companyId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->update($id, $companyId, ['deleted_at' => $now, 'updated_at' => $now]);
    }

    public function nextSkNumber(int $companyId): string
    {
        $this->requireCompany($companyId);
        $year = date('Y');
        $month = date('m');
        $row = $this->db()->table('job_information_changes')->select('sk_number')
            ->like('sk_number', "SK-JIC/{$year}/{$month}/%", 'after')
            ->where('company_id', $companyId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $next = 1;
        if ($row && preg_match('#/(\d+)$#', (string) ($row['sk_number'] ?? ''), $matches)) {
            $next = (int) $matches[1] + 1;
        }
        return sprintf('SK-JIC/%s/%s/%04d', $year, $month, $next);
    }
}
