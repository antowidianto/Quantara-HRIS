<?php

namespace App\Libraries\Employee;

use CodeIgniter\Database\BaseConnection;

final class EmployeeEffectiveHistoryService
{
    private const SNAPSHOT_FIELDS = [
        'position_id', 'manager_id', 'supervisor_id', 'department_id', 'branch_id', 'cost_center_id', 'job_grade_id', 'work_location_id',
        'basic_salary', 'fixed_allowance', 'employment_status', 'employment_type',
        'contract_type', 'contract_start_date', 'contract_end_date', 'benefit_policy_ids',
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    public function asOf(int $companyId, int $employeeId, string $date): ?array
    {
        $this->assertContext($companyId, $employeeId, $date);
        $row = $this->db->table('employee_master_history')->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('valid_from <=', $date)->groupStart()->where('valid_to IS NULL')->orWhere('valid_to >=', $date)->groupEnd()
            ->where('status !=', 'CANCELLED')->orderBy('valid_from', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();
        return $row ? $this->decode($row) : null;
    }

    public function history(int $companyId, int $employeeId): array
    {
        if ($companyId <= 0 || $employeeId <= 0) throw new \InvalidArgumentException('Company and employee are required.');
        return array_map(fn (array $row): array => $this->decode($row), $this->db->table('employee_master_history')
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->orderBy('valid_from', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray());
    }

    public function schedule(int $companyId, int $employeeId, string $effectiveDate, array $changes, int $approvedBy, string $sourceType = 'MANUAL', ?int $sourceId = null, ?int $createdBy = null): array
    {
        $this->assertContext($companyId, $employeeId, $effectiveDate);
        if ($approvedBy <= 0) throw new \InvalidArgumentException('An approving user is required.');
        $changes = array_intersect_key($changes, array_flip(self::SNAPSHOT_FIELDS));
        if ($changes === []) throw new \InvalidArgumentException('At least one supported change is required.');
        $this->validateReferences($companyId, $employeeId, $changes);
        if ($this->db->table('employee_master_history')->where('company_id', $companyId)->where('employee_id', $employeeId)->where('valid_from', $effectiveDate)->countAllResults() > 0) {
            throw new \RuntimeException('An effective-dated snapshot already exists for this employee and date.');
        }

        $snapshot = $this->asOf($companyId, $employeeId, $effectiveDate) ?? $this->currentSnapshot($companyId, $employeeId, $effectiveDate);
        foreach ($changes as $field => $value) $snapshot[$field] = $value;
        $now = date('Y-m-d H:i:s');
        $row = $this->encodeSnapshot($snapshot) + [
            'company_id' => $companyId, 'employee_id' => $employeeId, 'valid_from' => $effectiveDate, 'valid_to' => null,
            'status' => 'SCHEDULED', 'changed_fields_json' => json_encode(array_keys($changes)),
            'source_type' => strtoupper(substr($sourceType, 0, 40)), 'source_id' => $sourceId,
            'approved_by' => $approvedBy, 'approved_at' => $now, 'applied_at' => null,
            'created_by' => $createdBy ?? $approvedBy, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db->table('employee_master_history')->insert($row);
        $id = (int) $this->db->insertID();
        if ($effectiveDate <= date('Y-m-d')) $this->applyRecord($id);
        return $this->find($companyId, $id) ?? throw new \RuntimeException('Unable to load scheduled snapshot.');
    }

    public function applyDue(?int $companyId = null, ?string $throughDate = null): array
    {
        $throughDate ??= date('Y-m-d');
        $this->assertDate($throughDate);
        $builder = $this->db->table('employee_master_history')->select('id')->where('status', 'SCHEDULED')->where('valid_from <=', $throughDate)->orderBy('valid_from', 'ASC')->orderBy('id', 'ASC');
        if ($companyId !== null) $builder->where('company_id', $companyId);
        $rows = $builder->get()->getResultArray();
        $result = ['processed' => count($rows), 'succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $row) {
            try { $this->applyRecord((int) $row['id']); $result['succeeded']++; }
            catch (\Throwable $error) { $result['failed']++; $result['errors'][] = ['id' => (int) $row['id'], 'message' => $error->getMessage()]; }
        }
        return $result;
    }

    public function recordAppliedSnapshot(int $companyId, int $employeeId, string $effectiveDate, string $sourceType, int $sourceId, ?int $actorUserId = null): array
    {
        $this->assertContext($companyId, $employeeId, $effectiveDate);
        $snapshot = $this->currentSnapshot($companyId, $employeeId, $effectiveDate);
        $existing = $this->db->table('employee_master_history')->where('company_id', $companyId)->where('employee_id', $employeeId)->where('valid_from', $effectiveDate)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $payload = $this->encodeSnapshot($snapshot) + [
            'status' => 'ACTIVE', 'source_type' => strtoupper(substr($sourceType, 0, 40)), 'source_id' => $sourceId,
            'approved_by' => $actorUserId, 'approved_at' => $now, 'applied_at' => $now, 'updated_at' => $now,
        ];
        $this->closePrevious($companyId, $employeeId, $effectiveDate, $existing ? (int) $existing['id'] : null);
        if ($existing) {
            $this->db->table('employee_master_history')->where('id', (int) $existing['id'])->where('company_id', $companyId)->update($payload);
            return $this->find($companyId, (int) $existing['id']) ?? [];
        }
        $this->db->table('employee_master_history')->insert($payload + [
            'company_id' => $companyId, 'employee_id' => $employeeId, 'valid_from' => $effectiveDate, 'valid_to' => null,
            'changed_fields_json' => json_encode([]), 'created_by' => $actorUserId, 'created_at' => $now,
        ]);
        return $this->find($companyId, (int) $this->db->insertID()) ?? [];
    }

    private function applyRecord(int $id): void
    {
        $row = $this->db->table('employee_master_history')->where('id', $id)->where('status', 'SCHEDULED')->get()->getRowArray();
        if (! $row) return;
        $companyId = (int) $row['company_id']; $employeeId = (int) $row['employee_id'];
        $this->db->transBegin();
        try {
            $this->closePrevious($companyId, $employeeId, (string) $row['valid_from'], $id);
            $employeeUpdate = array_intersect_key($row, array_flip([
                'position_id', 'manager_id', 'supervisor_id', 'department_id', 'branch_id', 'cost_center_id', 'job_grade_id', 'work_location_id',
                'basic_salary', 'fixed_allowance', 'employment_status', 'employment_type',
            ]));
            $employeeUpdate['contract_end_date'] = $row['contract_end_date'];
            $employeeUpdate['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->update($employeeUpdate);

            $changed = json_decode((string) ($row['changed_fields_json'] ?? '[]'), true) ?: [];
            if (array_intersect($changed, ['contract_type', 'contract_start_date', 'contract_end_date']) !== []) {
                $this->db->table('employee_contracts')->insert([
                    'company_id' => $companyId, 'employee_id' => $employeeId,
                    'contract_number' => sprintf('EFF/%d/%s/%d', $employeeId, str_replace('-', '', (string) $row['valid_from']), $id),
                    'contract_type' => $row['contract_type'] ?: 'PKWTT', 'start_date' => $row['contract_start_date'] ?: $row['valid_from'],
                    'end_date' => $row['contract_end_date'], 'basic_salary' => $row['basic_salary'], 'position_id' => $row['position_id'],
                    'department_id' => $row['department_id'], 'notes' => 'Effective-dated approved employee master change',
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->db->table('employee_master_history')->where('id', $id)->where('company_id', $companyId)->update(['status' => 'ACTIVE', 'applied_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->transStatus() === false) throw new \RuntimeException('Failed to apply effective-dated employee change.');
            $this->db->transCommit();
        } catch (\Throwable $error) { $this->db->transRollback(); throw $error; }
    }

    private function closePrevious(int $companyId, int $employeeId, string $validFrom, ?int $excludeId): void
    {
        $builder = $this->db->table('employee_master_history')->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('valid_from <', $validFrom)->where('status !=', 'CANCELLED')->orderBy('valid_from', 'DESC')->orderBy('id', 'DESC');
        if ($excludeId !== null) $builder->where('id !=', $excludeId);
        $previous = $builder->get()->getRowArray();
        if (! $previous) return;
        $validTo = date('Y-m-d', strtotime($validFrom . ' -1 day'));
        $this->db->table('employee_master_history')->where('id', (int) $previous['id'])->where('company_id', $companyId)
            ->update(['valid_to' => $validTo, 'status' => 'SUPERSEDED', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function currentSnapshot(int $companyId, int $employeeId, string $effectiveDate): array
    {
        $employee = $this->db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) throw new \InvalidArgumentException('Employee not found in the active company.');
        $contract = $this->db->table('employee_contracts')->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('start_date <=', $effectiveDate)->orderBy('start_date', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();
        $policies = $this->db->table('benefit_policies')->select('id')->where('company_id', $companyId)
            ->groupStart()->where('job_grade_id IS NULL')->orWhere('job_grade_id', $employee['job_grade_id'] ?? 0)->groupEnd()->orderBy('id', 'ASC')->get()->getResultArray();
        return array_merge($employee, [
            'contract_type' => $contract['contract_type'] ?? null, 'contract_start_date' => $contract['start_date'] ?? null,
            'contract_end_date' => $contract['end_date'] ?? $employee['contract_end_date'] ?? null,
            'benefit_policy_ids' => array_map('intval', array_column($policies, 'id')),
        ]);
    }

    private function validateReferences(int $companyId, int $employeeId, array $changes): void
    {
        foreach (['position_id' => 'positions', 'department_id' => 'departments', 'branch_id' => 'branches', 'cost_center_id' => 'cost_centers', 'job_grade_id' => 'job_grades', 'work_location_id' => 'work_locations'] as $field => $table) {
            if (! array_key_exists($field, $changes) || $changes[$field] === null) continue;
            if ($this->db->table($table)->where('id', (int) $changes[$field])->where('company_id', $companyId)->countAllResults() !== 1) throw new \InvalidArgumentException("{$field} does not belong to the active company.");
        }
        foreach (['manager_id', 'supervisor_id'] as $field) {
            if (! array_key_exists($field, $changes) || $changes[$field] === null) continue;
            $id = (int) $changes[$field];
            if ($id === $employeeId || $this->db->table('employees')->where('id', $id)->where('company_id', $companyId)->where('deleted_at IS NULL')->countAllResults() !== 1) throw new \InvalidArgumentException("{$field} is invalid for the active company.");
        }
        if (isset($changes['benefit_policy_ids'])) {
            foreach (array_unique(array_map('intval', (array) $changes['benefit_policy_ids'])) as $policyId) {
                if ($this->db->table('benefit_policies')->where('id', $policyId)->where('company_id', $companyId)->countAllResults() !== 1) throw new \InvalidArgumentException('A benefit policy does not belong to the active company.');
            }
        }
    }

    private function encodeSnapshot(array $snapshot): array
    {
        $encoded = array_intersect_key($snapshot, array_flip(array_diff(self::SNAPSHOT_FIELDS, ['benefit_policy_ids'])));
        $encoded['benefit_eligibility_json'] = json_encode(array_values(array_unique(array_map('intval', (array) ($snapshot['benefit_policy_ids'] ?? [])))));
        return $encoded;
    }

    private function decode(array $row): array
    {
        $row['benefit_policy_ids'] = array_map('intval', json_decode((string) ($row['benefit_eligibility_json'] ?? '[]'), true) ?: []);
        $row['changed_fields'] = json_decode((string) ($row['changed_fields_json'] ?? '[]'), true) ?: [];
        unset($row['benefit_eligibility_json'], $row['changed_fields_json']);
        return $row;
    }

    private function find(int $companyId, int $id): ?array
    {
        $row = $this->db->table('employee_master_history')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        return $row ? $this->decode($row) : null;
    }

    private function assertContext(int $companyId, int $employeeId, string $date): void
    {
        if ($companyId <= 0 || $employeeId <= 0) throw new \InvalidArgumentException('Company and employee are required.');
        $this->assertDate($date);
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Date must use YYYY-MM-DD format.');
    }
}
