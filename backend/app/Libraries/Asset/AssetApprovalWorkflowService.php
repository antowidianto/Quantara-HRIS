<?php

namespace App\Libraries\Asset;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class AssetApprovalWorkflowService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null,
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function submitAssign(int $assetId, int $companyId, int $userId, int $employeeId, array $params): array
    {
        $asset = $this->asset($assetId, $companyId);
        if ($asset['status'] !== 'AVAILABLE' || ! empty($asset['approval_action'])) {
            throw new AssetApprovalException('Asset tidak tersedia atau sedang menunggu approval');
        }
        $employee = $this->employee($employeeId, $companyId);
        $payload = [
            'assigned_at' => $params['assigned_at'] ?? null,
            'expected_return' => $params['expected_return'] ?? null,
            'purpose' => $params['purpose'] ?? null,
            'condition_when_assigned' => strtoupper((string) ($params['condition_when_assigned'] ?? $asset['condition'] ?? 'GOOD')),
        ];
        return $this->submit($asset, $employee, $userId, 'ASSIGN', null, $payload);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function submitReturn(int $assetId, int $companyId, int $userId, array $params): array
    {
        $asset = $this->asset($assetId, $companyId);
        if ($asset['status'] !== 'ASSIGNED' || empty($asset['current_assignment_id']) || ! empty($asset['approval_action'])) {
            throw new AssetApprovalException('Asset tidak sedang di-assign atau sedang menunggu approval');
        }
        $assignment = $this->db()->table('asset_assignments')
            ->where('id', (int) $asset['current_assignment_id'])->where('company_id', $companyId)
            ->where('asset_id', $assetId)->where('returned_at IS NULL')->get()->getRowArray();
        if (! $assignment) throw new AssetApprovalException('Assignment aktif asset tidak valid');
        $employee = $this->employee((int) $assignment['employee_id'], $companyId);
        $condition = strtoupper((string) ($params['condition_when_returned'] ?? 'GOOD'));
        if (! in_array($condition, ['NEW', 'GOOD', 'FAIR', 'POOR', 'DAMAGED', 'LOST'], true)) {
            throw new AssetApprovalException('Kondisi pengembalian tidak valid', 'ASSET_RETURN_INVALID', 422);
        }
        $payload = [
            'returned_at' => $params['returned_at'] ?? null,
            'condition_when_returned' => $condition,
            'damage_note' => $params['damage_note'] ?? null,
        ];
        return $this->submit($asset, $employee, $userId, 'RETURN', (int) $assignment['id'], $payload);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function submitRetire(int $assetId, int $companyId, int $userId, array $params): array
    {
        $asset = $this->asset($assetId, $companyId);
        if (! in_array((string) $asset['status'], ['AVAILABLE', 'REPAIR', 'LOST'], true)
            || ! empty($asset['current_assignment_id']) || ! empty($asset['approval_action'])) {
            throw new AssetApprovalException('Asset harus sudah tidak dipegang karyawan dan tidak sedang menunggu approval');
        }
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        $employee = $this->employee((int) ($actor['employee_id'] ?? 0), $companyId);
        $method = strtoupper(trim((string) ($params['disposal_method'] ?? '')));
        if (! in_array($method, ['SALE', 'DONATION', 'SCRAP', 'RECYCLE', 'RETURN_VENDOR', 'OTHER'], true)) {
            throw new AssetApprovalException('Metode disposal tidak valid', 'ASSET_RETIRE_INVALID', 422);
        }
        $reason = trim((string) ($params['retirement_reason'] ?? ''));
        $reference = trim((string) ($params['disposal_reference'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reference) < 3) {
            throw new AssetApprovalException('Alasan retirement dan referensi bukti disposal wajib diisi', 'ASSET_RETIRE_EVIDENCE_REQUIRED', 422);
        }
        $bookValue = (float) ($params['book_value'] ?? -1);
        $purchasePrice = (float) ($asset['purchase_price'] ?? 0);
        $proceeds = (float) ($params['disposal_proceeds'] ?? 0);
        if ($bookValue < 0 || $proceeds < 0 || ($purchasePrice > 0 && $bookValue > $purchasePrice)) {
            throw new AssetApprovalException('Nilai buku/proceeds disposal tidak valid', 'ASSET_RETIRE_VALUE_INVALID', 422);
        }
        $retirementDate = (string) ($params['retirement_date'] ?? date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $retirementDate)
            || strtotime($retirementDate) === false || $retirementDate > date('Y-m-d')) {
            throw new AssetApprovalException('Tanggal retirement tidak valid atau berada di masa depan', 'ASSET_RETIRE_DATE_INVALID', 422);
        }
        $payload = [
            'retirement_date' => $retirementDate,
            'retirement_reason' => $reason,
            'disposal_method' => $method,
            'disposal_proceeds' => $proceeds,
            'disposal_reference' => $reference,
            'book_value' => $bookValue,
            'write_off_amount' => max(0.0, $bookValue - $proceeds),
        ];
        return $this->submit($asset, $employee, $userId, 'RETIRE', null, $payload);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $id = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $asset = $this->asset($id, $companyId);
        $meta = $this->meta($request);
        $action = strtoupper((string) ($meta['approval_action'] ?? ''));
        if (! in_array($action, ['ASSIGN', 'RETURN', 'RETIRE'], true)
            || $action !== strtoupper((string) ($asset['approval_action'] ?? ''))) {
            throw new AssetApprovalException('Aksi asset berubah setelah submit');
        }
        if (! hash_equals((string) ($meta['asset_snapshot_hash'] ?? ''), $this->snapshotHash($asset))) {
            throw new AssetApprovalException('Asset berubah setelah submit; ajukan ulang approval');
        }
        $employee = $this->employee((int) $asset['approval_employee_id'], $companyId);
        $payloadJson = (string) ($asset['approval_payload'] ?? '');
        if (! hash_equals((string) ($meta['asset_payload_hash'] ?? ''), hash('sha256', $payloadJson))) {
            throw new AssetApprovalException('Payload transaksi asset berubah setelah submit');
        }
        $payload = json_decode($payloadJson, true) ?: [];
        $db = $this->db();
        if ($action === 'RETIRE') {
            if (! in_array((string) $asset['status'], ['AVAILABLE', 'REPAIR', 'LOST'], true)
                || ! empty($asset['current_assignment_id'])) {
                throw new AssetApprovalException('Asset kembali di-assign atau statusnya tidak dapat dipensiunkan');
            }
            $retiredAt = (string) ($payload['retirement_date'] ?? date('Y-m-d')) . ' 23:59:59';
            $db->table('assets')->where('id', $id)->where('company_id', $companyId)
                ->where('approval_action', 'RETIRE')->where('current_assignment_id IS NULL')->update(
                    $this->clearPendingFields() + [
                        'status' => 'RETIRED',
                        'retired_at' => $retiredAt,
                        'retired_by' => (int) ($meta['submitted_by'] ?? 0),
                        'retirement_approved_by' => $userId,
                        'retirement_reason' => (string) ($payload['retirement_reason'] ?? ''),
                        'disposal_method' => (string) ($payload['disposal_method'] ?? ''),
                        'disposal_proceeds' => (float) ($payload['disposal_proceeds'] ?? 0),
                        'disposal_reference' => (string) ($payload['disposal_reference'] ?? ''),
                        'book_value_at_retirement' => (float) ($payload['book_value'] ?? 0),
                        'write_off_amount' => (float) ($payload['write_off_amount'] ?? 0),
                    ]
                );
            if ($db->affectedRows() !== 1) throw new AssetApprovalException('Asset berubah saat finalisasi retirement');
            return ['id' => $id, 'asset_number' => $asset['asset_number'], 'status' => 'RETIRED',
                'approval_action' => $action, 'employee_id' => (int) $employee['id'],
                'write_off_amount' => (float) ($payload['write_off_amount'] ?? 0)];
        }
        $db->table('assets')->where('id', $id)->where('company_id', $companyId)
            ->where('approval_action', $action)->update($this->clearPendingFields());
        if ($db->affectedRows() !== 1) throw new AssetApprovalException('Asset berubah; muat ulang data');

        $service = new AssetService($db);
        if ($action === 'ASSIGN') {
            $assignmentId = $service->assignToEmployee($id, (int) $employee['id'], $payload + ['assigned_by' => (int) ($meta['submitted_by'] ?? $userId)]);
            return ['id' => $id, 'asset_number' => $asset['asset_number'], 'status' => 'ASSIGNED',
                'approval_action' => $action, 'assignment_id' => $assignmentId, 'employee_id' => (int) $employee['id']];
        }
        $assignmentId = (int) $asset['approval_assignment_id'];
        if ($assignmentId <= 0 || $assignmentId !== (int) $asset['current_assignment_id']) {
            throw new AssetApprovalException('Assignment asset berubah setelah submit');
        }
        $service->returnAsset($assignmentId, $payload + ['returned_by' => (int) ($meta['submitted_by'] ?? $userId)]);
        $condition = strtoupper((string) ($payload['condition_when_returned'] ?? 'GOOD'));
        return ['id' => $id, 'asset_number' => $asset['asset_number'],
            'status' => $condition === 'LOST' ? 'LOST' : ($condition === 'DAMAGED' ? 'REPAIR' : 'AVAILABLE'),
            'approval_action' => $action, 'assignment_id' => $assignmentId, 'employee_id' => (int) $employee['id']];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new AssetApprovalException('Alasan penolakan minimal 5 karakter', 'REASON_REQUIRED', 422);
        }
        $asset = $this->asset($id, $companyId);
        if (empty($asset['approval_action'])) throw new AssetApprovalException('Asset tidak sedang menunggu approval');
        $action = (string) $asset['approval_action'];
        $update = $this->clearPendingFields() + [
            'approval_rejected_by' => $userId,
            'approval_rejected_at' => date('Y-m-d H:i:s'),
            'approval_rejection_reason' => $reason,
        ];
        $this->db()->table('assets')->where('id', $id)->where('company_id', $companyId)
            ->where('approval_action', $action)->update($update);
        if ($this->db()->affectedRows() !== 1) throw new AssetApprovalException('Asset berubah; muat ulang data');
        return ['id' => $id, 'asset_number' => $asset['asset_number'], 'status' => $asset['status'],
            'approval_action' => $action, 'employee_id' => (int) $asset['approval_employee_id']];
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $employee @param array<string,mixed> $payload @return array<string,mixed> */
    private function submit(array $asset, array $employee, int $userId, string $action, ?int $assignmentId, array $payload): array
    {
        $companyId = (int) $asset['company_id'];
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if ((int) ($actor['employee_id'] ?? 0) <= 0) {
            throw new AssetApprovalException('Pembuat transaksi asset harus user aktif yang terhubung ke employee', 'ASSET_MAKER_INVALID', 422);
        }
        $condition = strtoupper((string) ($payload['condition_when_returned'] ?? ''));
        $lossOrDamage = $action === 'RETIRE' || in_array($condition, ['LOST', 'DAMAGED'], true);
        $amount = $action === 'RETIRE'
            ? (float) ($payload['write_off_amount'] ?? 0)
            : (float) ($asset['purchase_price'] ?? 0);
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('assets')->where('id', (int) $asset['id'])->where('company_id', $companyId)
                ->where('approval_action IS NULL')->update([
                    'approval_action' => $action,
                    'approval_employee_id' => (int) $employee['id'],
                    'approval_assignment_id' => $assignmentId,
                    'approval_payload' => $payloadJson,
                    'approval_rejected_by' => null,
                    'approval_rejected_at' => null,
                    'approval_rejection_reason' => null,
                    'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) throw new AssetApprovalException('Asset berubah; muat ulang data');
            $params = [
                'company_id' => $companyId, 'module' => 'ASSET_TRANSACTION',
                'entity_table' => 'assets', 'entity_id' => (int) $asset['id'],
                'employee_id' => (int) $employee['id'],
                'meta' => [
                    'amount' => $amount,
                    'approval_action' => $action,
                    'is_loss_or_damage' => $lossOrDamage ? 1 : 0,
                    'is_high_value_loss' => $lossOrDamage && $amount > 50000000 ? 1 : 0,
                    'is_asset_retirement' => $action === 'RETIRE' ? 1 : 0,
                    'asset_snapshot_hash' => $this->snapshotHash($asset),
                    'asset_payload_hash' => hash('sha256', $payloadJson),
                    'submitted_by' => $userId,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params) : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval asset');
            $db->transCommit();
            return ['asset' => $this->asset((int) $asset['id'], $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function clearPendingFields(): array
    {
        return ['approval_action' => null, 'approval_employee_id' => null,
            'approval_assignment_id' => null, 'approval_payload' => null,
            'updated_at' => date('Y-m-d H:i:s')];
    }

    /** @param array<string,mixed> $asset */
    private function snapshotHash(array $asset): string
    {
        $fields = ['asset_number', 'category', 'name', 'serial_number', 'purchase_price', 'condition',
            'status', 'current_assignment_id', 'current_employee_id', 'branch_id'];
        $snapshot = [];
        foreach ($fields as $field) $snapshot[$field] = $asset[$field] ?? null;
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function asset(int $id, int $companyId): array
    {
        $row = $this->db()->table('assets')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new AssetApprovalException('Asset tidak ditemukan', 'RESOURCE_NOT_FOUND', 404);
        return $row;
    }

    /** @return array<string,mixed> */
    private function employee(int $id, int $companyId): array
    {
        $row = $this->db()->table('employees')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new AssetApprovalException('Karyawan tidak ditemukan di tenant ini', 'RESOURCE_NOT_FOUND', 404);
        return $row;
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
