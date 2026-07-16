<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

final class ApprovalReadinessAudit extends BaseCommand
{
    protected $group = 'HRIS';
    protected $name = 'approval:readiness';
    protected $description = 'Audit active approval chains, global approver roles, and organization resolver coverage';
    protected $usage = 'approval:readiness [--company=ID]';
    protected $options = ['--company' => 'Optional company ID'];

    public function run(array $params)
    {
        $db = Database::connect();
        $companyFilter = (int) (CLI::getOption('company') ?? 0);
        $companies = $db->table('companies')->select('id, name')->where('deleted_at IS NULL');
        if ($companyFilter > 0) $companies->where('id', $companyFilter);
        $rows = $companies->orderBy('id', 'ASC')->get()->getResultArray();
        if ($rows === []) {
            CLI::error('Company tidak ditemukan');
            return 1;
        }

        $failures = 0;
        foreach ($rows as $company) {
            $companyId = (int) $company['id'];
            CLI::write("\n[{$companyId}] {$company['name']}", 'cyan');
            $chains = $db->table('approval_chains')->where('company_id', $companyId)
                ->where('is_active', 1)->where('is_default', 1)->where('deleted_at IS NULL')
                ->orderBy('module', 'ASC')->get()->getResultArray();
            CLI::write('  Default chain aktif: ' . count($chains));
            foreach ($chains as $chain) {
                $steps = $db->table('approval_chain_steps')->where('chain_id', (int) $chain['id'])
                    ->where('company_id', $companyId)->orderBy('step_order', 'ASC')->get()->getResultArray();
                if ($steps === []) {
                    CLI::write("  FAIL {$chain['module']}: chain tanpa step", 'red');
                    $failures++;
                    continue;
                }
                foreach ($steps as $step) {
                    $count = $this->globalCandidateCount($companyId, $step);
                    if ($count === null) continue;
                    $label = "{$chain['module']} step {$step['step_order']} {$step['name']}";
                    if ($count <= 0) {
                        CLI::write("  FAIL {$label}: approver aktif tidak ditemukan", 'red');
                        $failures++;
                    } else {
                        CLI::write("  OK   {$label}: {$count} kandidat", 'green');
                    }
                }
            }

            [$linked, $supervisorReady] = $this->supervisorCoverage($companyId);
            [$departmentEmployees, $departmentReady] = $this->departmentHeadCoverage($companyId);
            [$postings, $hiringManagerReady] = $this->hiringManagerCoverage($companyId);
            CLI::write("  INFO supervisor coverage: {$supervisorReady}/{$linked} employee ber-user");
            foreach ($this->employeesWithoutSupervisorApprover($companyId) as $employee) {
                $isRoot = empty($employee['supervisor_id']);
                $prefix = $isRoot ? 'INFO root reporting line' : 'WARN supervisor belum siap';
                CLI::write("  {$prefix}: {$employee['employee_number']} - {$employee['full_name']}", $isRoot ? null : 'yellow');
            }
            CLI::write("  INFO department-head coverage: {$departmentReady}/{$departmentEmployees} employee ber-departemen");
            CLI::write("  INFO hiring-manager coverage: {$hiringManagerReady}/{$postings} job posting aktif");
        }
        CLI::write("\nApproval readiness: failures={$failures}", $failures > 0 ? 'red' : 'green');
        return $failures > 0 ? 1 : 0;
    }

    /** @param array<string,mixed> $step */
    private function globalCandidateCount(int $companyId, array $step): ?int
    {
        $db = Database::connect();
        $type = (string) $step['approver_type'];
        if ($type === 'HR') {
            $slugs = ['hr_admin', 'hr_director', 'hr_business_partner'];
            $name = strtolower((string) ($step['name'] ?? ''));
            if (str_contains($name, 'l&d') || str_contains($name, 'talent')) $slugs[] = 'learning_development';
            if (str_contains($name, 'c&b')) $slugs[] = 'compensation_benefits';
            if (str_contains($name, 'payroll')) $slugs[] = 'payroll_admin';
            return $this->usersInRoles($companyId, $slugs);
        }
        if ($type === 'FINANCE') return $this->usersInRoles($companyId, ['finance', 'cfo']);
        if ($type === 'ROLE') {
            return $db->table('users')->where('company_id', $companyId)->where('is_active', 1)
                ->where('deleted_at IS NULL')->where('role_id', (int) $step['approver_role_id'])->countAllResults();
        }
        if ($type === 'SPECIFIC_USER') {
            return $db->table('users')->where('company_id', $companyId)->where('is_active', 1)
                ->where('deleted_at IS NULL')->where('id', (int) $step['approver_user_id'])->countAllResults();
        }
        if ($type === 'SPECIFIC_EMPLOYEE') {
            return $db->table('employees')->where('company_id', $companyId)->where('deleted_at IS NULL')
                ->where('id', (int) $step['approver_employee_id'])->where('user_id IS NOT NULL')->countAllResults();
        }
        return null;
    }

    /** @param list<string> $slugs */
    private function usersInRoles(int $companyId, array $slugs): int
    {
        return Database::connect()->table('users u')->join('roles r', 'r.id = u.role_id')
            ->where('u.company_id', $companyId)->where('u.is_active', 1)->where('u.deleted_at IS NULL')
            ->whereIn('r.slug', array_values(array_unique($slugs)))->countAllResults();
    }

    /** @return array{int,int} */
    private function supervisorCoverage(int $companyId): array
    {
        $db = Database::connect();
        $base = $db->table('employees')->where('company_id', $companyId)->where('is_active', 1)
            ->where('deleted_at IS NULL')->where('user_id IS NOT NULL')->countAllResults();
        $ready = $db->table('employees e')->join('employees s', 's.id = e.supervisor_id AND s.company_id = e.company_id')
            ->join('users u', 'u.id = s.user_id AND u.company_id = e.company_id')
            ->where('e.company_id', $companyId)->where('e.is_active', 1)->where('e.deleted_at IS NULL')
            ->where('e.user_id IS NOT NULL')->where('u.is_active', 1)->where('u.deleted_at IS NULL')
            ->countAllResults();
        return [$base, $ready];
    }

    /** @return list<array{employee_number:string,full_name:string,supervisor_id:int|null}> */
    private function employeesWithoutSupervisorApprover(int $companyId): array
    {
        $rows = Database::connect()->table('employees e')
            ->select('e.employee_number, e.full_name, e.supervisor_id, s.id AS supervisor_employee_id, s.user_id AS supervisor_user_id, u.id AS approver_user_id, u.is_active AS approver_is_active, u.deleted_at AS approver_deleted_at')
            ->join('employees s', 's.id = e.supervisor_id AND s.company_id = e.company_id', 'left')
            ->join('users u', 'u.id = s.user_id AND u.company_id = e.company_id', 'left')
            ->where('e.company_id', $companyId)->where('e.is_active', 1)
            ->where('e.deleted_at IS NULL')->where('e.user_id IS NOT NULL')
            ->orderBy('e.employee_number', 'ASC')->get()->getResultArray();

        return array_values(array_map(
            static fn (array $row): array => [
                'employee_number' => (string) $row['employee_number'],
                'full_name' => (string) $row['full_name'],
                'supervisor_id' => empty($row['supervisor_id']) ? null : (int) $row['supervisor_id'],
            ],
            array_filter($rows, static fn (array $row): bool =>
                empty($row['supervisor_id'])
                || empty($row['supervisor_employee_id'])
                || empty($row['supervisor_user_id'])
                || empty($row['approver_user_id'])
                || (int) $row['approver_is_active'] !== 1
                || $row['approver_deleted_at'] !== null
            )
        ));
    }

    /** @return array{int,int} */
    private function departmentHeadCoverage(int $companyId): array
    {
        $db = Database::connect();
        $base = $db->table('employees')->where('company_id', $companyId)->where('is_active', 1)
            ->where('deleted_at IS NULL')->where('department_id IS NOT NULL')->countAllResults();
        $ready = $db->table('employees e')->join('departments d', 'd.id = e.department_id AND d.company_id = e.company_id')
            ->join('employees h', 'h.id = d.manager_employee_id AND h.company_id = e.company_id')
            ->join('users u', 'u.id = h.user_id AND u.company_id = e.company_id')
            ->where('e.company_id', $companyId)->where('e.is_active', 1)->where('e.deleted_at IS NULL')
            ->where('u.is_active', 1)->where('u.deleted_at IS NULL')->countAllResults();
        return [$base, $ready];
    }

    /** @return array{int,int} */
    private function hiringManagerCoverage(int $companyId): array
    {
        $db = Database::connect();
        $base = $db->table('job_postings')->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->whereIn('status', ['OPEN', 'PAUSED'])->countAllResults();
        $ready = $db->table('job_postings j')
            ->join('users u', 'u.id = j.hiring_manager_id AND u.company_id = j.company_id')
            ->where('j.company_id', $companyId)->where('j.deleted_at IS NULL')
            ->whereIn('j.status', ['OPEN', 'PAUSED'])->where('u.is_active', 1)
            ->where('u.deleted_at IS NULL')->countAllResults();
        return [$base, $ready];
    }
}
