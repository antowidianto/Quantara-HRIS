<?php

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class MasterRateApprovalService
{
    private const DEFINITIONS = [
        'ALLOWANCE_TYPE' => ['table' => 'allowance_types', 'fields' => ['code','name','description','category','is_taxable','is_distance_based','default_threshold_km','payroll_component_id','is_active','is_taxable_natura','natura_tax_threshold']],
        'ALLOWANCE_RATE' => ['table' => 'allowance_rates', 'fields' => ['allowance_type_id','job_grade_id','branch_id','threshold_km','rate_below','rate_above','effective_from','effective_to','notes']],
        'PAYROLL_COMPONENT' => ['table' => 'payroll_components', 'fields' => ['code','name','type','category','is_taxable','is_fixed','amount','formula','is_active']],
    ];
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(private readonly ?BaseConnection $connection = null, ?callable $approvalStarter = null)
    {
        $this->approvalStarter = $approvalStarter;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(string $type, string $action, ?int $targetId, array $payload, int $companyId, int $userId): array
    {
        $type = strtoupper($type); $action = strtoupper($action);
        if (! isset(self::DEFINITIONS[$type]) || ! in_array($action, ['CREATE','UPDATE','DELETE'], true)) throw new \InvalidArgumentException('Jenis perubahan master rate tidak valid');
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)->where('is_active', 1)->get()->getRowArray();
        $makerEmployeeId = (int) ($actor['employee_id'] ?? 0);
        if ($makerEmployeeId <= 0) throw new \RuntimeException('Maker master rate harus user aktif yang terhubung ke employee');
        $definition = self::DEFINITIONS[$type];
        $payload = array_intersect_key($payload, array_flip($definition['fields']));
        $target = null;
        if ($action !== 'CREATE') {
            if (($targetId ?? 0) <= 0) throw new \InvalidArgumentException('Target perubahan wajib');
            $target = $this->target($definition['table'], (int) $targetId, $companyId);
            if ($this->db()->table('master_rate_change_requests')->where('company_id', $companyId)->where('entity_type', $type)->where('target_id', $targetId)->where('status', 'PENDING_APPROVAL')->countAllResults() > 0) throw new \RuntimeException('Target masih memiliki approval pending');
        }
        if ($action !== 'DELETE' && $payload === []) throw new \InvalidArgumentException('Payload perubahan wajib');
        $this->validate($type, $action, $payload, $target, $companyId);
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $payloadJson);
        $snapshotHash = $target ? $this->hash($target) : hash('sha256', 'CREATE|' . $type . '|' . $companyId);
        $now = date('Y-m-d H:i:s');
        $db = $this->db(); $db->transBegin();
        try {
            $db->table('master_rate_change_requests')->insert([
                'company_id' => $companyId, 'request_number' => 'MRC-' . date('YmdHis') . '-' . strtoupper(substr(hash('sha256', microtime(true) . '|' . $userId), 0, 8)),
                'entity_type' => $type, 'change_action' => $action, 'target_id' => $targetId,
                'status' => 'PENDING_APPROVAL', 'payload_json' => $payloadJson, 'payload_hash' => $payloadHash,
                'target_snapshot_hash' => $snapshotHash, 'submitted_by' => $userId, 'submitted_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int) $db->insertID();
            $params = ['company_id' => $companyId, 'module' => 'MASTER_RATE_CHANGE', 'entity_table' => 'master_rate_change_requests', 'entity_id' => $id, 'employee_id' => $makerEmployeeId,
                'meta' => ['submitted_by' => $userId, 'entity_type' => $type, 'change_action' => $action, 'payload_hash' => $payloadHash, 'target_snapshot_hash' => $snapshotHash, 'is_delete' => $action === 'DELETE' ? 1 : 0]];
            $approval = $this->approvalStarter ? ($this->approvalStarter)($params) : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat approval master rate');
            $db->transCommit();
            return ['request' => $this->request($id, $companyId), 'approval' => $approval];
        } catch (\Throwable $e) { $db->transRollback(); throw $e; }
    }

    /** @param array<string,mixed> $approvalRequest @return array<string,mixed> */
    public function approve(array $approvalRequest, int $userId): array
    {
        $id = (int) $approvalRequest['entity_id']; $companyId = (int) $approvalRequest['company_id'];
        $request = $this->request($id, $companyId);
        if ($request['status'] !== 'PENDING_APPROVAL') throw new \RuntimeException('Request master rate tidak pending');
        $payloadJson = (string) $request['payload_json']; $payload = json_decode($payloadJson, true);
        if (! is_array($payload) || ! hash_equals((string) $request['payload_hash'], hash('sha256', $payloadJson))) throw new \RuntimeException('Payload master rate berubah setelah submit');
        $meta = $approvalRequest['meta'] ?? []; if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        if (! is_array($meta) || ! hash_equals((string) ($meta['payload_hash'] ?? ''), hash('sha256', $payloadJson))) throw new \RuntimeException('Metadata master rate berubah setelah submit');
        $type = (string) $request['entity_type']; $action = (string) $request['change_action']; $definition = self::DEFINITIONS[$type];
        $target = null;
        if ($action !== 'CREATE') {
            $target = $this->target($definition['table'], (int) $request['target_id'], $companyId);
            if (! hash_equals((string) $request['target_snapshot_hash'], $this->hash($target))) throw new \RuntimeException('Master rate berubah setelah submit');
        }
        $this->validate($type, $action, $payload, $target, $companyId);
        $now = date('Y-m-d H:i:s'); $db = $this->db();
        if ($action === 'CREATE') {
            $payload += ['company_id' => $companyId, 'created_at' => $now]; $payload['updated_at'] = $now;
            if (in_array($type, ['ALLOWANCE_TYPE','PAYROLL_COMPONENT'], true)) $payload['is_active'] ??= 1;
            $db->table($definition['table'])->insert($payload); $targetId = (int) $db->insertID();
        } elseif ($action === 'UPDATE') {
            $payload['updated_at'] = $now; $targetId = (int) $request['target_id'];
            $db->table($definition['table'])->where('id', $targetId)->where('company_id', $companyId)->where('updated_at', $target['updated_at'])->update($payload);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('Master rate berubah saat finalisasi');
        } else {
            $targetId = (int) $request['target_id'];
            if ($type === 'ALLOWANCE_RATE') $db->table($definition['table'])->where('id', $targetId)->where('company_id', $companyId)->delete();
            else $db->table($definition['table'])->where('id', $targetId)->where('company_id', $companyId)->update(['is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
        }
        $db->table('master_rate_change_requests')->where('id', $id)->where('status', 'PENDING_APPROVAL')->update(['status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => $now, 'updated_at' => $now]);
        if (in_array($type, ['ALLOWANCE_TYPE','ALLOWANCE_RATE'], true)) Services::allowance()->recomputeCompany($companyId);
        return ['request_id' => $id, 'target_id' => $targetId, 'status' => 'APPROVED', 'entity_type' => $type, 'change_action' => $action];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');
        $request = $this->request($id, $companyId); if ($request['status'] !== 'PENDING_APPROVAL') throw new \RuntimeException('Request master rate tidak pending');
        $now = date('Y-m-d H:i:s');
        $this->db()->table('master_rate_change_requests')->where('id', $id)->where('status', 'PENDING_APPROVAL')->update(['status' => 'REJECTED', 'rejected_by' => $userId, 'rejected_at' => $now, 'rejection_reason' => trim($reason), 'updated_at' => $now]);
        return ['request_id' => $id, 'status' => 'REJECTED'];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed>|null $target */
    private function validate(string $type, string $action, array $payload, ?array $target, int $companyId): void
    {
        if ($action === 'DELETE') return;
        $merged = array_merge($target ?? [], $payload);
        if (in_array($type, ['ALLOWANCE_TYPE','PAYROLL_COMPONENT'], true)) {
            foreach (['code','name'] as $field) if (trim((string) ($merged[$field] ?? '')) === '') throw new \InvalidArgumentException("{$field} wajib");
            if ($action === 'CREATE' && $this->db()->table(self::DEFINITIONS[$type]['table'])->where('company_id', $companyId)->where('code', $merged['code'])->where('deleted_at IS NULL')->countAllResults() > 0) throw new \InvalidArgumentException('Kode sudah digunakan');
        }
        if ($type === 'ALLOWANCE_RATE') {
            if ((float) ($merged['rate_above'] ?? -1) < 0 || (float) ($merged['rate_below'] ?? 0) < 0) throw new \InvalidArgumentException('Tarif tidak boleh negatif');
            $typeId = (int) ($merged['allowance_type_id'] ?? 0);
            if ($this->db()->table('allowance_types')->where('id', $typeId)->where('company_id', $companyId)->where('deleted_at IS NULL')->countAllResults() === 0) throw new \InvalidArgumentException('Tipe allowance tidak valid');
            if (! empty($merged['effective_from']) && ! empty($merged['effective_to']) && $merged['effective_to'] < $merged['effective_from']) throw new \InvalidArgumentException('Rentang tanggal efektif tidak valid');
        }
        if ($type === 'PAYROLL_COMPONENT' && (float) ($merged['amount'] ?? 0) < 0) throw new \InvalidArgumentException('Nominal komponen tidak boleh negatif');
    }

    /** @return array<string,mixed> */
    private function target(string $table, int $id, int $companyId): array
    { $row = $this->db()->table($table)->where('id', $id)->where('company_id', $companyId)->get()->getRowArray(); if (! $row) throw new \RuntimeException('Master rate tidak ditemukan di tenant aktif'); return $row; }
    /** @return array<string,mixed> */
    private function request(int $id, int $companyId): array
    { $row = $this->db()->table('master_rate_change_requests')->where('id', $id)->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray(); if (! $row) throw new \RuntimeException('Request master rate tidak ditemukan'); return $row; }
    /** @param array<string,mixed> $row */
    private function hash(array $row): string { return hash('sha256', (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private function db(): BaseConnection { return $this->connection ?? Database::connect(); }
}
