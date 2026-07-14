<?php

namespace App\Libraries\Employee;

use App\Libraries\System\Encryption;
use App\Libraries\System\IndonesianValidator;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

final class EmployeeDataQualityService
{
    private Encryption $encryption;

    public function __construct(private ?BaseConnection $db = null, ?Encryption $encryption = null)
    {
        $this->db ??= \Config\Database::connect();
        $this->encryption = $encryption ?? Services::encryption();
    }

    /** @return array{generated_at:string,company_id:int,total_active_employees:int,total_issues:int,categories:array<string,array<string,mixed>>} */
    public function dashboard(int $companyId, int $sampleLimit = 50): array
    {
        if ($companyId <= 0) throw new \InvalidArgumentException('A company context is required.');
        $sampleLimit = max(1, min(100, $sampleLimit));
        $employees = $this->employees($companyId);
        $wages = $this->wageCatalog();

        $identity = [];
        $bank = [];
        $bpjs = [];
        $organization = [];
        $minimumWage = [];
        $orphaned = [];
        $expired = [];
        $bankOwners = [];

        foreach ($employees as $employee) {
            $identityProblems = [];
            $nik = $this->decrypt($employee['nik_encrypted'] ?? null);
            $npwp = $this->decrypt($employee['npwp_encrypted'] ?? null);
            if (! IndonesianValidator::isValidNik($nik)) $identityProblems[] = empty($nik) ? 'NIK_MISSING' : 'NIK_INVALID';
            if (! IndonesianValidator::isValidNpwp($npwp)) $identityProblems[] = empty($npwp) ? 'NPWP_MISSING' : 'NPWP_INVALID';
            if ($identityProblems !== []) $identity[] = $this->issue($employee, $identityProblems);

            $account = preg_replace('/\D+/', '', (string) ($this->decrypt($employee['bank_account_encrypted'] ?? null) ?? '')) ?: '';
            $bankProblems = [];
            if ($account === '' || empty($employee['bank_name']) || empty($employee['bank_account_holder'])) $bankProblems[] = 'BANK_ACCOUNT_INCOMPLETE';
            if ($account !== '') $bankOwners[$account][] = $employee;
            if ($bankProblems !== []) $bank[] = $this->issue($employee, $bankProblems);

            $bpjsProblems = [];
            if (! IndonesianValidator::isValidBpjsKesehatan($employee['bpjs_kesehatan_no'] ?? null)) $bpjsProblems[] = empty($employee['bpjs_kesehatan_no']) ? 'BPJS_HEALTH_MISSING' : 'BPJS_HEALTH_INVALID';
            if (! IndonesianValidator::isValidBpjsKetenagakerjaan($employee['bpjs_tk_no'] ?? null)) $bpjsProblems[] = empty($employee['bpjs_tk_no']) ? 'BPJS_EMPLOYMENT_MISSING' : 'BPJS_EMPLOYMENT_INVALID';
            if ($bpjsProblems !== []) $bpjs[] = $this->issue($employee, $bpjsProblems);

            $orgProblems = [];
            foreach (['manager_id' => 'MANAGER_MISSING', 'position_id' => 'POSITION_MISSING', 'cost_center_id' => 'COST_CENTER_MISSING', 'work_location_id' => 'WORK_LOCATION_MISSING'] as $field => $code) {
                if (empty($employee[$field])) $orgProblems[] = $code;
            }
            if ($orgProblems !== []) $organization[] = $this->issue($employee, $orgProblems);

            $orphanProblems = [];
            foreach (['user_id' => 'user_found', 'manager_id' => 'manager_found', 'branch_id' => 'branch_found', 'department_id' => 'department_found', 'position_id' => 'position_found', 'cost_center_id' => 'cost_center_found', 'work_location_id' => 'work_location_found'] as $field => $found) {
                if (! empty($employee[$field]) && empty($employee[$found])) $orphanProblems[] = strtoupper(str_replace('_id', '', $field)) . '_ORPHANED';
            }
            if ($orphanProblems !== []) $orphaned[] = $this->issue($employee, $orphanProblems);

            if (! empty($employee['contract_end_date']) && $employee['contract_end_date'] < date('Y-m-d')) {
                $expired[] = $this->issue($employee, ['CONTRACT_EXPIRED'], ['expired_at' => $employee['contract_end_date']]);
            }

            $wage = $this->minimumWageFor($employee, $wages);
            if ($wage !== null && (float) ($employee['basic_salary'] ?? 0) < $wage['amount']) {
                $minimumWage[] = $this->issue($employee, ['SALARY_BELOW_MINIMUM'], [
                    'salary' => (float) $employee['basic_salary'], 'minimum_wage' => $wage['amount'],
                    'wage_level' => $wage['level'], 'wage_year' => $wage['year'],
                ]);
            }
        }

        foreach ($bankOwners as $owners) {
            if (count($owners) < 2) continue;
            foreach ($owners as $owner) $bank[] = $this->issue($owner, ['BANK_ACCOUNT_DUPLICATE']);
        }

        $expired = array_merge($expired, $this->expiredEvidence($companyId));
        $categories = [
            'identity_tax' => $this->category($identity, $sampleLimit),
            'bank_account' => $this->category($bank, $sampleLimit),
            'bpjs' => $this->category($bpjs, $sampleLimit),
            'expired_records' => $this->category($expired, $sampleLimit),
            'organization_completeness' => $this->category($organization, $sampleLimit),
            'minimum_wage' => $this->category($minimumWage, $sampleLimit),
            'orphaned_references' => $this->category($orphaned, $sampleLimit),
        ];

        return [
            'generated_at' => date(DATE_ATOM), 'company_id' => $companyId,
            'total_active_employees' => count($employees),
            'total_issues' => array_sum(array_column($categories, 'count')),
            'categories' => $categories,
        ];
    }

    private function employees(int $companyId): array
    {
        return $this->db->table('employees e')->select(
            'e.id, e.employee_number, e.full_name, e.user_id, e.manager_id, e.branch_id, e.department_id, e.position_id, e.cost_center_id, e.work_location_id, '
            . 'e.nik_encrypted, e.npwp_encrypted, e.bank_name, e.bank_account_encrypted, e.bank_account_holder, e.bpjs_kesehatan_no, e.bpjs_tk_no, '
            . 'e.basic_salary, e.province, e.city, e.contract_end_date, '
            . 'u.id AS user_found, mgr.id AS manager_found, b.id AS branch_found, d.id AS department_found, p.id AS position_found, cc.id AS cost_center_found, wl.id AS work_location_found'
        )
            ->join('users u', 'u.id = e.user_id AND u.company_id = e.company_id', 'left')
            ->join('employees mgr', 'mgr.id = e.manager_id AND mgr.company_id = e.company_id AND mgr.deleted_at IS NULL', 'left')
            ->join('branches b', 'b.id = e.branch_id AND b.company_id = e.company_id', 'left')
            ->join('departments d', 'd.id = e.department_id AND d.company_id = e.company_id', 'left')
            ->join('positions p', 'p.id = e.position_id AND p.company_id = e.company_id', 'left')
            ->join('cost_centers cc', 'cc.id = e.cost_center_id AND cc.company_id = e.company_id', 'left')
            ->join('work_locations wl', 'wl.id = e.work_location_id AND wl.company_id = e.company_id', 'left')
            ->where('e.company_id', $companyId)->where('e.is_active', 1)->where('e.deleted_at IS NULL')
            ->orderBy('e.employee_number', 'ASC')->get()->getResultArray();
    }

    private function expiredEvidence(int $companyId): array
    {
        $today = date('Y-m-d');
        $issues = [];
        $documents = $this->db->table('employee_documents c')->select('c.employee_id, c.expires_at AS expired_at, c.title AS reference, e.employee_number, e.full_name')
            ->join('employees e', 'e.id = c.employee_id AND e.company_id = c.company_id', 'inner')
            ->where('c.company_id', $companyId)->where('c.expires_at IS NOT NULL')->where('c.expires_at <', $today)
            ->where('c.deleted_at IS NULL')->where('e.is_active', 1)->where('e.deleted_at IS NULL')->get()->getResultArray();
        foreach ($documents as $row) $issues[] = $this->issue(['id' => $row['employee_id']] + $row, ['DOCUMENT_EXPIRED'], ['expired_at' => $row['expired_at'], 'reference' => $row['reference']]);

        $certificates = $this->db->table('certificates c')->select('c.employee_id, c.expires_date AS expired_at, c.title AS reference, e.employee_number, e.full_name')
            ->join('employees e', 'e.id = c.employee_id AND e.company_id = c.company_id', 'inner')
            ->where('c.company_id', $companyId)->where('c.expires_date IS NOT NULL')->where('c.expires_date <', $today)
            ->where('c.status', 'ACTIVE')->where('c.deleted_at IS NULL')->where('e.is_active', 1)->where('e.deleted_at IS NULL')->get()->getResultArray();
        foreach ($certificates as $row) $issues[] = $this->issue(['id' => $row['employee_id']] + $row, ['CERTIFICATION_EXPIRED'], ['expired_at' => $row['expired_at'], 'reference' => $row['reference']]);
        return $issues;
    }

    private function wageCatalog(): array
    {
        if (! $this->db->tableExists('regional_minimum_wages')) return [];
        return $this->db->table('regional_minimum_wages')
            ->select('province, city, level, amount, effective_year')
            ->where('effective_year <=', (int) date('Y'))
            ->orderBy('effective_year', 'DESC')->get()->getResultArray();
    }

    private function minimumWageFor(array $employee, array $wages): ?array
    {
        $province = trim((string) ($employee['province'] ?? ''));
        if ($province === '' || (float) ($employee['basic_salary'] ?? 0) <= 0) return null;
        $city = trim((string) ($employee['city'] ?? ''));
        foreach (['UMK', 'UMP'] as $level) {
            foreach ($wages as $wage) {
                if (strcasecmp(trim((string) $wage['province']), $province) !== 0 || strtoupper((string) $wage['level']) !== $level) continue;
                if ($level === 'UMK' && ($city === '' || strcasecmp(trim((string) ($wage['city'] ?? '')), $city) !== 0)) continue;
                return ['amount' => (float) $wage['amount'], 'level' => $level, 'year' => (int) $wage['effective_year']];
            }
        }
        return null;
    }

    private function decrypt(mixed $value): ?string
    {
        try { return $this->encryption->decrypt(is_string($value) ? $value : null); } catch (\Throwable) { return null; }
    }

    private function issue(array $employee, array $codes, array $details = []): array
    {
        return ['employee_id' => (int) $employee['id'], 'employee_number' => $employee['employee_number'] ?? null, 'employee_name' => $employee['full_name'] ?? null, 'codes' => $codes] + $details;
    }

    private function category(array $issues, int $limit): array
    {
        return ['count' => count($issues), 'samples' => array_slice($issues, 0, $limit), 'truncated' => count($issues) > $limit];
    }
}
