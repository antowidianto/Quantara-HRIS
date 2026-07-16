<?php

namespace App\Libraries\Compensation;

use CodeIgniter\Database\BaseConnection;

final class CompensationReviewService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(private ?BaseConnection $db = null, ?callable $approvalStarter = null)
    {
        $this->db ??= \Config\Database::connect();
        $this->approvalStarter = $approvalStarter;
    }

    public function cycles(int $companyId): array
    {
        $this->assertCompany($companyId);
        $cycles = $this->db->table('compensation_review_cycles')->where('company_id', $companyId)->orderBy('effective_date', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        foreach ($cycles as &$cycle) $cycle['summary'] = $this->summary($companyId, (int) $cycle['id']);
        return $cycles;
    }

    public function createCycle(int $companyId, array $input, int $actorId): array
    {
        $this->assertCompany($companyId);
        $code = strtoupper(trim((string) ($input['code'] ?? ''))); $name = trim((string) ($input['name'] ?? '')); $date = (string) ($input['effective_date'] ?? '');
        if ($code === '' || $name === '' || ! $this->validDate($date)) throw new \InvalidArgumentException('Code, name, and a valid effective date are required.');
        $levels = min(5, max(1, (int) ($input['approval_levels'] ?? 2))); $now = date('Y-m-d H:i:s');
        try { $this->db->table('compensation_review_cycles')->insert(['company_id' => $companyId, 'code' => substr($code, 0, 40), 'name' => substr($name, 0, 160), 'effective_date' => $date, 'status' => 'DRAFT', 'approval_levels' => $levels, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]); }
        catch (\Throwable $e) { throw new \RuntimeException('Cycle code must be unique in the active company.', 0, $e); }
        return $this->cycle($companyId, (int) $this->db->insertID());
    }

    public function replaceBudgets(int $companyId, int $cycleId, array $budgets): array
    {
        $cycle = $this->cycle($companyId, $cycleId); if ($cycle['status'] !== 'DRAFT') throw new \RuntimeException('Budgets can only be changed while the cycle is DRAFT.');
        if ($budgets === []) throw new \InvalidArgumentException('At least one budget is required.');
        $rows = []; $scopeKeys = []; $now = date('Y-m-d H:i:s');
        foreach ($budgets as $budget) {
            if (! is_array($budget) || (float) ($budget['budget_amount'] ?? 0) < 0) throw new \InvalidArgumentException('Budget amounts cannot be negative.');
            $departmentId = isset($budget['department_id']) ? (int) $budget['department_id'] : null; $costCenterId = isset($budget['cost_center_id']) ? (int) $budget['cost_center_id'] : null;
            $this->assertReference($companyId, 'departments', $departmentId); $this->assertReference($companyId, 'cost_centers', $costCenterId);
            $scopeKey = ($departmentId ?: 0) . ':' . ($costCenterId ?: 0); if (isset($scopeKeys[$scopeKey])) throw new \InvalidArgumentException('Budget scopes must be unique.'); $scopeKeys[$scopeKey] = true;
            $rows[] = ['company_id' => $companyId, 'cycle_id' => $cycleId, 'department_id' => $departmentId, 'cost_center_id' => $costCenterId, 'budget_amount' => (float) $budget['budget_amount'], 'created_at' => $now, 'updated_at' => $now];
        }
        if (isset($scopeKeys['0:0']) && count($scopeKeys) > 1) throw new \InvalidArgumentException('A company-wide budget cannot be mixed with scoped budgets.');
        $this->db->transBegin(); try { $this->db->table('compensation_review_budgets')->where('company_id', $companyId)->where('cycle_id', $cycleId)->delete(); $this->db->table('compensation_review_budgets')->insertBatch($rows); if ($this->db->transStatus() === false) throw new \RuntimeException('Failed to save budgets.'); $this->db->transCommit(); } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
        return $this->db->table('compensation_review_budgets')->where('company_id', $companyId)->where('cycle_id', $cycleId)->orderBy('id')->get()->getResultArray();
    }

    public function openCycle(int $companyId, int $cycleId): array
    {
        $cycle = $this->cycle($companyId, $cycleId); if ($cycle['status'] !== 'DRAFT') throw new \RuntimeException('Only a DRAFT cycle can be opened.');
        if ($this->db->table('compensation_review_budgets')->where('company_id', $companyId)->where('cycle_id', $cycleId)->countAllResults() < 1) throw new \RuntimeException('Configure a merit budget before opening the cycle.');
        $this->db->table('compensation_review_cycles')->where('id', $cycleId)->where('company_id', $companyId)->update(['status' => 'OPEN', 'updated_at' => date('Y-m-d H:i:s')]); return $this->cycle($companyId, $cycleId);
    }

    public function detail(int $companyId, int $cycleId): array
    {
        $cycle = $this->cycle($companyId, $cycleId); $cycle['budgets'] = $this->db->table('compensation_review_budgets b')->select('b.*, d.name department_name, cc.name cost_center_name')->join('departments d', 'd.id = b.department_id AND d.company_id = b.company_id', 'left')->join('cost_centers cc', 'cc.id = b.cost_center_id AND cc.company_id = b.company_id', 'left')->where('b.company_id', $companyId)->where('b.cycle_id', $cycleId)->orderBy('b.id')->get()->getResultArray();
        $cycle['proposals'] = $this->db->table('compensation_review_proposals p')->select('p.*, e.employee_number, e.full_name, d.name department_name, cc.name cost_center_name')->join('employees e', 'e.id = p.employee_id AND e.company_id = p.company_id')->join('departments d', 'd.id = p.department_id AND d.company_id = p.company_id', 'left')->join('cost_centers cc', 'cc.id = p.cost_center_id AND cc.company_id = p.company_id', 'left')->where('p.company_id', $companyId)->where('p.cycle_id', $cycleId)->orderBy('e.full_name')->get()->getResultArray();
        $cycle['summary'] = $this->summary($companyId, $cycleId); return $cycle;
    }

    public function metrics(float $salary, float $min, float $max): array
    {
        $mid = ($min + $max) / 2;
        return ['compa_ratio' => $mid > 0 ? round($salary / $mid, 4) : null, 'range_penetration' => $max > $min ? round(($salary - $min) / ($max - $min), 4) : null];
    }

    public function propose(int $companyId, int $cycleId, int $employeeId, float $salary, ?float $performance, ?string $calibration, int $managerId): array
    {
        $cycle = $this->cycle($companyId, $cycleId); if ($cycle['status'] !== 'OPEN') throw new \RuntimeException('Cycle is not open for proposals.');
        $employee = $this->db->table('employees e')->select('e.*, jg.min_salary, jg.max_salary')->join('job_grades jg', 'jg.id = e.job_grade_id AND jg.company_id = e.company_id', 'left')
            ->where('e.id', $employeeId)->where('e.company_id', $companyId)->where('e.deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) throw new \InvalidArgumentException('Employee not found in active company.');
        if ($salary < 0) throw new \InvalidArgumentException('Proposed salary cannot be negative.');
        if ($performance !== null && ($performance < 0 || $performance > 5)) throw new \InvalidArgumentException('Performance score must be between 0 and 5.');
        $current = (float) ($employee['basic_salary'] ?? 0); $min = (float) ($employee['min_salary'] ?? 0); $max = (float) ($employee['max_salary'] ?? 0); $metric = $this->metrics($salary, $min, $max); $now = date('Y-m-d H:i:s');
        $payload = ['company_id' => $companyId, 'cycle_id' => $cycleId, 'employee_id' => $employeeId, 'department_id' => $employee['department_id'], 'cost_center_id' => $employee['cost_center_id'] ?? null,
            'current_salary' => $current, 'proposed_salary' => $salary, 'range_min' => $min, 'range_max' => $max, 'compa_ratio' => $metric['compa_ratio'], 'range_penetration' => $metric['range_penetration'],
            'performance_score' => $performance, 'calibration_input' => $calibration, 'status' => 'SUBMITTED', 'approval_level' => 0, 'proposed_by' => $managerId, 'updated_at' => $now];
        $manager = $this->db->table('users')->where('id', $managerId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $requesterEmployeeId = (int) ($manager['employee_id'] ?? 0);
        if ($requesterEmployeeId <= 0) throw new \InvalidArgumentException('Proposer must be linked to an active employee in the company.');
        $existing = $this->db->table('compensation_review_proposals')->where('company_id', $companyId)->where('cycle_id', $cycleId)->where('employee_id', $employeeId)->get()->getRowArray();
        if ($existing && $existing['status'] !== 'REJECTED') {
            throw new \RuntimeException('An active or approved proposal is immutable; reject it before resubmission.');
        }
        $this->db->transBegin();
        try {
            if ($existing) {
                $payload += ['approved_by' => null, 'approved_at' => null, 'history_snapshot_id' => null,
                    'letter_reference' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null];
                $this->db->table('compensation_review_proposals')->where('id', $existing['id'])
                    ->where('company_id', $companyId)->where('status', 'REJECTED')->update($payload);
                if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Proposal changed; reload data.');
                $proposalId = (int) $existing['id'];
            } else {
                $payload['created_at'] = $now;
                $this->db->table('compensation_review_proposals')->insert($payload);
                $proposalId = (int) $this->db->insertID();
            }
            $increase = $salary - $current;
            $approval = $this->startApproval([
                'company_id' => $companyId,
                'module' => 'COMPENSATION_REVIEW',
                'entity_table' => 'compensation_review_proposals',
                'entity_id' => $proposalId,
                'employee_id' => $requesterEmployeeId,
                'meta' => [
                    'amount' => max(0, $increase),
                    'salary_variance_pct' => $current > 0 ? round(($increase / $current) * 100, 4) : 0,
                    'target_employee_id' => $employeeId,
                    'department_id' => $employee['department_id'] ?? null,
                    'cost_center_id' => $employee['cost_center_id'] ?? null,
                    'effective_date' => $cycle['effective_date'],
                    'submitted_by' => $managerId,
                ],
            ]);
            if (! $this->db->transStatus()) throw new \RuntimeException('Failed to create compensation approval.');
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
        $result = $this->proposal($companyId, $cycleId, $employeeId);
        $result['approval'] = $approval;
        return $result;
    }

    public function letter(int $companyId, int $proposalId): array
    {
        $row = $this->db->table('compensation_review_proposals p')->select('p.*, c.name cycle_name, c.effective_date, e.full_name, e.employee_number')->join('compensation_review_cycles c', 'c.id = p.cycle_id AND c.company_id = p.company_id')->join('employees e', 'e.id = p.employee_id AND e.company_id = p.company_id')->where('p.id', $proposalId)->where('p.company_id', $companyId)->get()->getRowArray();
        if (! $row || $row['status'] !== 'APPROVED') throw new \RuntimeException('An approved proposal is required to issue a letter.');
        return ['reference' => $row['letter_reference'], 'employee' => ['id' => (int) $row['employee_id'], 'number' => $row['employee_number'], 'name' => $row['full_name']], 'cycle' => $row['cycle_name'], 'effective_date' => $row['effective_date'], 'current_salary' => (float) $row['current_salary'], 'new_salary' => (float) $row['proposed_salary'], 'increase' => (float) $row['proposed_salary'] - (float) $row['current_salary'], 'issued_at' => $row['approved_at']];
    }

    private function summary(int $companyId, int $cycleId): array
    {
        $budget = $this->db->table('compensation_review_budgets')->selectSum('budget_amount')->where('company_id', $companyId)->where('cycle_id', $cycleId)->get()->getRowArray();
        $rows = $this->db->table('compensation_review_proposals')->where('company_id', $companyId)->where('cycle_id', $cycleId)->get()->getResultArray(); $used = 0.0; $counts = [];
        foreach ($rows as $row) { $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1; if ($row['status'] === 'APPROVED') $used += (float) $row['proposed_salary'] - (float) $row['current_salary']; }
        $total = (float) ($budget['budget_amount'] ?? 0); return ['budget' => $total, 'approved_increase' => $used, 'remaining' => $total - $used, 'proposal_count' => count($rows), 'status_counts' => $counts];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function startApproval(array $params): array
    {
        if ($this->approvalStarter !== null) return ($this->approvalStarter)($params);
        return \Config\Services::approval()->start($params);
    }

    private function assertCompany(int $companyId): void { if ($companyId <= 0) throw new \InvalidArgumentException('Active company is required.'); }
    private function validDate(string $date): bool { $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $parsed !== false && $parsed->format('Y-m-d') === $date; }
    private function assertReference(int $companyId, string $table, ?int $id): void { if ($id !== null && $id > 0 && $this->db->table($table)->where('id', $id)->where('company_id', $companyId)->countAllResults() !== 1) throw new \InvalidArgumentException("{$table} reference does not belong to the active company."); }

    private function cycle(int $companyId, int $id): array { return $this->db->table('compensation_review_cycles')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray() ?: throw new \InvalidArgumentException('Cycle not found.'); }
    private function proposal(int $companyId, int $cycleId, int $employeeId): array { return $this->db->table('compensation_review_proposals')->where('company_id', $companyId)->where('cycle_id', $cycleId)->where('employee_id', $employeeId)->get()->getRowArray() ?: []; }
}
