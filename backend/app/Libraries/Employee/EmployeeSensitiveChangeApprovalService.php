<?php

namespace App\Libraries\Employee;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class EmployeeSensitiveChangeApprovalService
{
    private const ALLOWED_FIELDS = [
        'employee_number', 'nik', 'npwp', 'bank_name', 'bank_account', 'bank_account_holder',
        'bpjs_kesehatan_no', 'bpjs_tk_no', 'tax_method', 'ptkp_status', 'pph21_method',
    ];
    private const PAYROLL_FIELDS = [
        'npwp', 'bank_name', 'bank_account', 'bank_account_holder', 'bpjs_kesehatan_no',
        'bpjs_tk_no', 'tax_method', 'ptkp_status', 'pph21_method',
    ];
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null,
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(int $employeeId, int $companyId, int $userId, array $payload): array
    {
        if (array_key_exists('basic_salary', $payload)) {
            throw new \InvalidArgumentException('Perubahan basic_salary wajib melalui Compensation Review');
        }
        $payload = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        if ($payload === []) throw new \InvalidArgumentException('Tidak ada perubahan sensitif yang diajukan');
        $employee = $this->employee($employeeId, $companyId);
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if (! $actor || (int) ($actor['employee_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Pembuat perubahan sensitif harus user aktif yang terhubung ke employee');
        }
        $this->validate($payload, $employee, $companyId);
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encrypted = Services::encryption()->encrypt($payloadJson);
        if ($encrypted === null || $encrypted === '') throw new \RuntimeException('Gagal mengenkripsi payload perubahan');
        $payloadHash = hash('sha256', $payloadJson);
        $snapshotHash = $this->snapshotHash($employee);
        $now = date('Y-m-d H:i:s');
        $requestNumber = 'ESC-' . date('YmdHis') . '-' . strtoupper(substr(hash('sha256', $employeeId . '|' . $userId . '|' . microtime(true)), 0, 8));
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('employee_sensitive_change_requests')->insert([
                'company_id' => $companyId, 'employee_id' => $employeeId,
                'request_number' => $requestNumber, 'status' => 'PENDING_APPROVAL',
                'changed_fields' => json_encode(array_keys($payload), JSON_UNESCAPED_UNICODE),
                'payload_encrypted' => $encrypted, 'payload_hash' => $payloadHash,
                'employee_snapshot_hash' => $snapshotHash,
                'submitted_by' => $userId, 'submitted_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int) $db->insertID();
            $isPayroll = array_intersect(array_keys($payload), self::PAYROLL_FIELDS) !== [];
            $params = [
                'company_id' => $companyId, 'module' => 'EMPLOYEE_SENSITIVE_CHANGE',
                'entity_table' => 'employee_sensitive_change_requests', 'entity_id' => $id,
                'employee_id' => $employeeId,
                'meta' => [
                    'submitted_by' => $userId,
                    'changed_fields' => array_keys($payload),
                    'is_payroll_sensitive' => $isPayroll ? 1 : 0,
                    'is_login_identifier_change' => array_key_exists('employee_number', $payload) ? 1 : 0,
                    'payload_hash' => $payloadHash,
                    'employee_snapshot_hash' => $snapshotHash,
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat approval perubahan sensitif');
            $db->transCommit();
            return ['request' => $this->request($id, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $approvalRequest @return array<string,mixed> */
    public function approve(array $approvalRequest, int $userId): array
    {
        $id = (int) $approvalRequest['entity_id'];
        $companyId = (int) $approvalRequest['company_id'];
        $request = $this->request($id, $companyId);
        if ($request['status'] !== 'PENDING_APPROVAL') throw new \RuntimeException('Request perubahan tidak pending');
        $payloadJson = Services::encryption()->decrypt((string) $request['payload_encrypted']);
        if ($payloadJson === null || $payloadJson === '') throw new \RuntimeException('Payload perubahan tidak dapat didekripsi');
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) throw new \RuntimeException('Payload perubahan tidak valid');
        $meta = $this->meta($approvalRequest);
        $payloadHash = hash('sha256', $payloadJson);
        $employee = $this->employee((int) $request['employee_id'], $companyId);
        if (! hash_equals((string) $request['payload_hash'], $payloadHash)
            || ! hash_equals((string) ($meta['payload_hash'] ?? ''), $payloadHash)
            || ! hash_equals((string) $request['employee_snapshot_hash'], $this->snapshotHash($employee))
            || ! hash_equals((string) ($meta['employee_snapshot_hash'] ?? ''), $this->snapshotHash($employee))) {
            throw new \RuntimeException('Data employee atau payload berubah setelah submit');
        }
        $this->validate($payload, $employee, $companyId);
        $update = $this->domainUpdate($payload);
        $now = date('Y-m-d H:i:s');
        $update['updated_at'] = $now;
        $this->db()->table('employees')->where('id', (int) $request['employee_id'])
            ->where('company_id', $companyId)->where('updated_at', $employee['updated_at'])->update($update);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Employee berubah saat finalisasi');
        $this->db()->table('employee_sensitive_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => $now, 'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Bukti approval perubahan gagal disimpan');
        return ['request_id' => $id, 'employee_id' => (int) $request['employee_id'],
            'status' => 'APPROVED', 'changed_fields' => array_keys($payload)];
    }

    /** @return array<string,mixed> */
    public function reject(int $id, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');
        $request = $this->request($id, $companyId);
        if ($request['status'] !== 'PENDING_APPROVAL') throw new \RuntimeException('Request perubahan tidak pending');
        $now = date('Y-m-d H:i:s');
        $this->db()->table('employee_sensitive_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'REJECTED', 'rejected_by' => $userId, 'rejected_at' => $now,
                'rejection_reason' => $reason, 'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Request berubah saat penolakan');
        return ['request_id' => $id, 'employee_id' => (int) $request['employee_id'], 'status' => 'REJECTED'];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $employee */
    private function validate(array $payload, array $employee, int $companyId): void
    {
        if (isset($payload['employee_number'])) {
            $value = trim((string) $payload['employee_number']);
            if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value)) throw new \InvalidArgumentException('Format NIK employee tidak valid');
            $duplicate = $this->db()->table('employees')->where('company_id', $companyId)
                ->where('employee_number', $value)->where('id !=', (int) $employee['id'])
                ->where('deleted_at IS NULL')->countAllResults();
            if ($duplicate > 0) throw new \InvalidArgumentException('NIK employee sudah digunakan');
        }
        if (isset($payload['nik']) && trim((string) $payload['nik']) !== ''
            && ! preg_match('/^\d{16}$/', trim((string) $payload['nik']))) {
            throw new \InvalidArgumentException('NIK KTP harus 16 digit');
        }
        if (isset($payload['bank_account']) && trim((string) $payload['bank_account']) !== ''
            && ! preg_match('/^[A-Za-z0-9-]{5,34}$/', trim((string) $payload['bank_account']))) {
            throw new \InvalidArgumentException('Nomor rekening tidak valid');
        }
        foreach (['tax_method' => ['GROSS', 'GROSS_UP', 'NET'], 'pph21_method' => ['TER', 'ANNUALIZED']] as $field => $allowed) {
            if (isset($payload[$field]) && ! in_array(strtoupper((string) $payload[$field]), $allowed, true)) {
                throw new \InvalidArgumentException("{$field} tidak valid");
            }
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function domainUpdate(array $payload): array
    {
        $update = array_intersect_key($payload, array_flip([
            'employee_number', 'bank_name', 'bank_account_holder', 'bpjs_kesehatan_no', 'bpjs_tk_no',
            'tax_method', 'ptkp_status', 'pph21_method',
        ]));
        $encryption = Services::encryption();
        if (array_key_exists('nik', $payload)) {
            $update['nik_encrypted'] = $encryption->encrypt($payload['nik']);
            $update['nik_hash'] = $encryption->hash($payload['nik']);
        }
        if (array_key_exists('npwp', $payload)) $update['npwp_encrypted'] = $encryption->encrypt($payload['npwp']);
        if (array_key_exists('bank_account', $payload)) {
            $update['bank_account_encrypted'] = $encryption->encrypt($payload['bank_account']);
        }
        return $update;
    }

    /** @param array<string,mixed> $employee */
    private function snapshotHash(array $employee): string
    {
        $fields = ['id', 'company_id', 'employee_number', 'nik_encrypted', 'nik_hash', 'npwp_encrypted',
            'bank_name', 'bank_account_encrypted', 'bank_account_holder', 'bpjs_kesehatan_no', 'bpjs_tk_no',
            'tax_method', 'ptkp_status', 'pph21_method', 'updated_at'];
        return hash('sha256', (string) json_encode(
            array_intersect_key($employee, array_flip($fields)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /** @return array<string,mixed> */
    private function employee(int $id, int $companyId): array
    {
        $row = $this->db()->table('employees')->where('id', $id)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Employee tidak ditemukan di tenant aktif');
        return $row;
    }

    /** @return array<string,mixed> */
    private function request(int $id, int $companyId): array
    {
        $row = $this->db()->table('employee_sensitive_change_requests')->where('id', $id)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new \RuntimeException('Request perubahan sensitif tidak ditemukan');
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
