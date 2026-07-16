<?php

namespace App\Libraries\Lifecycle;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class GeneratedContractApprovalWorkflowService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null,
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    /** @return array<string,mixed> */
    public function submit(int $id, int $companyId, int $userId, string $action): array
    {
        $action = strtoupper(trim($action));
        if (! in_array($action, ['SEND', 'REVOKE'], true)) {
            throw new GeneratedContractApprovalException('Aksi approval kontrak tidak valid', 'INVALID_ACTION', 422);
        }
        $contract = $this->contract($id, $companyId);
        $allowed = $action === 'SEND' ? ['DRAFT', 'APPROVAL_REJECTED'] : ['SENT', 'SIGNED'];
        if (! in_array((string) $contract['status'], $allowed, true)) {
            throw new GeneratedContractApprovalException(
                "Kontrak tidak dapat diajukan untuk {$action} dari status {$contract['status']}"
            );
        }
        $context = $this->assertContext($contract, $companyId);
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if ((int) ($actor['employee_id'] ?? 0) <= 0) {
            throw new GeneratedContractApprovalException(
                'Pembuat transaksi kontrak harus user aktif yang terhubung ke employee',
                'CONTRACT_MAKER_INVALID',
                422
            );
        }
        $policy = $this->policy($contract, $context['employee'], $action);
        $previousStatus = $action === 'SEND' ? 'DRAFT' : (string) $contract['status'];
        $now = date('Y-m-d H:i:s');
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('generated_contracts')->where('id', $id)->where('company_id', $companyId)
                ->whereIn('status', $allowed)->update([
                    'status' => 'PENDING_APPROVAL',
                    'approval_action' => $action,
                    'approval_previous_status' => $previousStatus,
                    'approval_rejected_by' => null,
                    'approval_rejected_at' => null,
                    'approval_rejection_reason' => null,
                    'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) {
                throw new GeneratedContractApprovalException('Kontrak berubah; muat ulang data');
            }
            $params = [
                'company_id' => $companyId,
                'module' => 'GENERATED_CONTRACT',
                'entity_table' => 'generated_contracts',
                'entity_id' => $id,
                'employee_id' => (int) $contract['employee_id'],
                'meta' => [
                    'amount' => $policy['salary_delta'],
                    'is_salary_change' => $policy['is_salary_change'] ? 1 : 0,
                    'is_policy_exception' => $policy['is_policy_exception'] ? 1 : 0,
                    'approval_action' => $action,
                    'contract_type' => $policy['contract_type'],
                    'contract_salary' => $policy['contract_salary'],
                    'current_salary' => $policy['current_salary'],
                    'salary_variance_pct' => $policy['salary_variance_pct'],
                    'document_snapshot_hash' => $this->snapshotHash($contract),
                    'submitted_by' => $userId,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval kontrak');
            $db->transCommit();
            return ['contract' => $this->contract($id, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $id = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $contract = $this->pending($id, $companyId);
        $context = $this->assertContext($contract, $companyId);
        $meta = $this->meta($request);
        $action = strtoupper((string) ($meta['approval_action'] ?? ''));
        if (! in_array($action, ['SEND', 'REVOKE'], true)
            || $action !== strtoupper((string) ($contract['approval_action'] ?? ''))) {
            throw new GeneratedContractApprovalException('Aksi approval kontrak berubah setelah submit');
        }
        if (! hash_equals((string) ($meta['document_snapshot_hash'] ?? ''), $this->snapshotHash($contract))) {
            throw new GeneratedContractApprovalException('Isi kontrak berubah setelah submit; ajukan ulang approval');
        }
        if (abs((float) ($context['employee']['basic_salary'] ?? 0) - (float) ($meta['current_salary'] ?? 0)) > 0.005) {
            throw new GeneratedContractApprovalException(
                'Gaji berjalan karyawan berubah setelah submit; ajukan ulang approval',
                'CONTRACT_POLICY_CHANGED'
            );
        }
        $now = date('Y-m-d H:i:s');
        $update = [
            'status' => $action === 'SEND' ? 'SENT' : 'REVOKED',
            'approval_action' => null,
            'approval_previous_status' => null,
            'updated_at' => $now,
        ];
        if ($action === 'SEND') $update['sent_at'] = $now;
        $this->db()->table('generated_contracts')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->where('approval_action', $action)->update($update);
        if ($this->db()->affectedRows() !== 1) {
            throw new GeneratedContractApprovalException('Kontrak berubah; muat ulang data');
        }
        return [
            'id' => $id,
            'status' => $update['status'],
            'approval_action' => $action,
            'contract_number' => $contract['contract_number'],
            'employee_id' => (int) $contract['employee_id'],
            'employee_user_id' => (int) ($context['employee']['user_id'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new GeneratedContractApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED', 422);
        }
        $contract = $this->pending($id, $companyId);
        $action = strtoupper((string) $contract['approval_action']);
        $restoredStatus = $action === 'SEND'
            ? 'APPROVAL_REJECTED'
            : (string) ($contract['approval_previous_status'] ?: 'SENT');
        $now = date('Y-m-d H:i:s');
        $this->db()->table('generated_contracts')->where('id', $id)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => $restoredStatus,
                'approval_action' => null,
                'approval_previous_status' => null,
                'approval_rejected_by' => $userId,
                'approval_rejected_at' => $now,
                'approval_rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) {
            throw new GeneratedContractApprovalException('Kontrak berubah; muat ulang data');
        }
        return [
            'id' => $id,
            'status' => $restoredStatus,
            'approval_action' => $action,
            'contract_number' => $contract['contract_number'],
        ];
    }

    /** @param array<string,mixed> $contract @return array{employee:array<string,mixed>} */
    private function assertContext(array $contract, int $companyId): array
    {
        $employee = $this->db()->table('employees')->where('id', (int) $contract['employee_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $employee) {
            throw new GeneratedContractApprovalException('Karyawan kontrak tidak tersedia di tenant ini', 'RESOURCE_NOT_FOUND', 404);
        }
        if (! empty($contract['employee_contract_id'])) {
            $source = $this->db()->table('employee_contracts')
                ->where('id', (int) $contract['employee_contract_id'])
                ->where('company_id', $companyId)
                ->where('employee_id', (int) $contract['employee_id'])->get()->getRowArray();
            if (! $source) {
                throw new GeneratedContractApprovalException('Sumber data kontrak tidak valid untuk karyawan ini');
            }
        }
        return ['employee' => $employee];
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $employee @return array<string,mixed> */
    private function policy(array $contract, array $employee, string $action): array
    {
        $variables = $contract['variables_snapshot'] ?? [];
        if (is_string($variables)) $variables = json_decode($variables, true) ?: [];
        if (! is_array($variables)) $variables = [];
        $contractSalary = (float) ($variables['salary']['basic'] ?? $variables['contract']['basic_salary'] ?? 0);
        $currentSalary = (float) ($employee['basic_salary'] ?? 0);
        $delta = $contractSalary - $currentSalary;
        $variance = $currentSalary > 0 ? ($delta / $currentSalary) * 100 : ($contractSalary > 0 ? 100.0 : 0.0);
        return [
            'contract_type' => strtoupper((string) ($variables['contract']['contract_type'] ?? 'OTHER')),
            'contract_salary' => $contractSalary,
            'current_salary' => $currentSalary,
            'salary_delta' => abs($delta),
            'salary_variance_pct' => round($variance, 4),
            'is_salary_change' => abs($delta) > 0.005,
            'is_policy_exception' => $action === 'REVOKE' || abs($variance) > 20,
        ];
    }

    /** @param array<string,mixed> $contract */
    private function snapshotHash(array $contract): string
    {
        $fields = ['employee_id', 'employee_contract_id', 'template_id', 'contract_number',
            'subject', 'rendered_html', 'variables_snapshot'];
        $snapshot = [];
        foreach ($fields as $field) $snapshot[$field] = $contract[$field] ?? null;
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function pending(int $id, int $companyId): array
    {
        $contract = $this->contract($id, $companyId);
        if ($contract['status'] !== 'PENDING_APPROVAL') {
            throw new GeneratedContractApprovalException('Kontrak tidak sedang menunggu approval');
        }
        return $contract;
    }

    /** @return array<string,mixed> */
    private function contract(int $id, int $companyId): array
    {
        $contract = $this->db()->table('generated_contracts')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $contract) {
            throw new GeneratedContractApprovalException('Kontrak tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        }
        return $contract;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function meta(array $request): array
    {
        $meta = $request['meta'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        return is_array($meta) ? $meta : [];
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
