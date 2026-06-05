<?php

namespace App\Libraries\Employee;

use Config\Database;
use Config\Services;

use App\Libraries\Approval\ReportingChainService;
/**
 * EmployeeTransferService — pemindahan karyawan antar branch / department /
 * posisi / work_location dengan workflow ringan (1-tier HR approval).
 *
 * Kontrak:
 *   - Tidak menyentuh basic_salary / job_grade — kalau perlu, gunakan JIC.
 *   - Snapshot field "old_*" diambil saat submit, tidak akan berubah lagi
 *     supaya audit trail historis tetap akurat (misal nama dept lama
 *     dihapus / di-rename setelahnya).
 *   - Setelah IMPLEMENTED, akan trigger:
 *       a. ReportingChainService::recomputeForCompany — manager_id otomatis
 *          disesuaikan ke struktur dept tujuan.
 *       b. AllowanceService::recomputeForEmployee (jika ada) — recompute
 *          tunjangan transport/makan yang berbasis branch / jarak ke rumah.
 *
 * Idempoten: implement() dijaga agar tidak bisa double-apply (status check
 * + transactional update).
 *
 * Lihat docs/EMPLOYEE_TRANSFER.md untuk detail aturan & flow.
 */
class EmployeeTransferService
{
    /**
     * Buat draft / submit baru. Snapshot posisi lama otomatis di-fill
     * dari record employees saat ini.
     *
     * @param array $data {
     *   employee_id, transfer_type, reason, reason_text,
     *   new_branch_id?, new_department_id?, new_position_id?, new_work_location_id?,
     *   effective_date,
     *   requires_consent?,
     *   submit?: bool  // true = langsung set status SUBMITTED
     * }
     * @return array Row baru yang dibuat
     * @throws \InvalidArgumentException untuk validasi gagal
     */
    public function create(int $companyId, int $userId, array $data): array
    {
        $db = Database::connect();

        // ─── Validasi minimal ──────────────────────────────────
        $empId = (int) ($data['employee_id'] ?? 0);
        if ($empId <= 0) {
            throw new \InvalidArgumentException('employee_id wajib diisi');
        }
        $emp = $db->table('employees')
            ->select('e.*, b.name AS branch_name, d.name AS department_name, '
                . 'p.name AS position_name, wl.name AS work_location_name')
            ->from('employees e', true)
            ->join('branches b',        'b.id = e.branch_id',        'left')
            ->join('departments d',     'd.id = e.department_id',    'left')
            ->join('positions p',       'p.id = e.position_id',      'left')
            ->join('work_locations wl', 'wl.id = e.work_location_id','left')
            ->where('e.id', $empId)
            ->where('e.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException('Karyawan tidak ditemukan');
        }
        if ((int) $emp['company_id'] !== $companyId) {
            throw new \InvalidArgumentException('Karyawan di luar perusahaan ini');
        }

        $type = $data['transfer_type'] ?? '';
        $allowedTypes = ['BRANCH', 'DEPARTMENT', 'POSITION', 'WORK_LOCATION', 'COMBINED'];
        if (! in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException('transfer_type tidak valid');
        }

        $reason = $data['reason'] ?? '';
        $allowedReasons = ['OPERATIONAL', 'EMPLOYEE_REQUEST', 'ROTATION', 'REORGANIZATION',
            'PROMOTION_PREP', 'PERSONAL', 'OTHER'];
        if (! in_array($reason, $allowedReasons, true)) {
            throw new \InvalidArgumentException('reason tidak valid');
        }

        $effDate = $data['effective_date'] ?? '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effDate)) {
            throw new \InvalidArgumentException('effective_date wajib format YYYY-MM-DD');
        }

        // Validasi field tujuan: harus ada minimal satu yang berubah
        $newBranchId    = $this->intOrNull($data['new_branch_id']    ?? null);
        $newDeptId      = $this->intOrNull($data['new_department_id']?? null);
        $newPosId       = $this->intOrNull($data['new_position_id']  ?? null);
        $newWorkLocId   = $this->intOrNull($data['new_work_location_id'] ?? null);

        $oldBranchId    = $emp['branch_id'] !== null ? (int) $emp['branch_id'] : null;
        $oldDeptId      = $emp['department_id'] !== null ? (int) $emp['department_id'] : null;
        $oldPosId       = $emp['position_id'] !== null ? (int) $emp['position_id'] : null;
        $oldWorkLocId   = $emp['work_location_id'] !== null ? (int) $emp['work_location_id'] : null;

        $changes = [];
        if ($newBranchId  !== null && $newBranchId  !== $oldBranchId)  $changes[] = 'branch';
        if ($newDeptId    !== null && $newDeptId    !== $oldDeptId)    $changes[] = 'department';
        if ($newPosId     !== null && $newPosId     !== $oldPosId)     $changes[] = 'position';
        if ($newWorkLocId !== null && $newWorkLocId !== $oldWorkLocId) $changes[] = 'work_location';
        if (empty($changes)) {
            throw new \InvalidArgumentException(
                'Tidak ada perubahan. Pilih minimal satu tujuan yang berbeda dari posisi saat ini.'
            );
        }

        // Validasi referensi tujuan: cross-tenant block
        $this->assertSameTenant($db, 'branches',       $newBranchId,  $companyId, 'Cabang tujuan');
        $this->assertSameTenant($db, 'departments',    $newDeptId,    $companyId, 'Department tujuan');
        $this->assertSameTenant($db, 'positions',      $newPosId,     $companyId, 'Posisi tujuan');
        $this->assertSameTenant($db, 'work_locations', $newWorkLocId, $companyId, 'Lokasi kerja tujuan');

        $now = date('Y-m-d H:i:s');
        $shouldSubmit = ! empty($data['submit']);
        $status = $shouldSubmit ? 'SUBMITTED' : 'DRAFT';
        $submittedAt = $shouldSubmit ? $now : null;

        $requiresConsent = (int) ($data['requires_consent'] ?? 0) === 1 ? 1 : 0;
        $consentStatus = $requiresConsent ? 'PENDING' : 'NOT_REQUIRED';

        // ─── Lookup nama snapshot (sekali, lalu disimpan) ──────
        $resolveNewName = function (?int $id, string $table, string $col = 'name') use ($db): ?string {
            if ($id === null || $id <= 0) return null;
            $row = $db->table($table)->select($col)->where('id', $id)->get()->getRowArray();
            return $row[$col] ?? null;
        };
        $newBranchName  = $resolveNewName($newBranchId,  'branches');
        $newDeptName    = $resolveNewName($newDeptId,    'departments');
        $newPosName     = $resolveNewName($newPosId,     'positions');
        $newWorkLocName = $resolveNewName($newWorkLocId, 'work_locations');

        // ─── Build payload ─────────────────────────────────────
        $insert = [
            'company_id'              => $companyId,
            'employee_id'             => $empId,
            'transfer_number'         => $shouldSubmit ? $this->nextNumber($db, $companyId) : null,
            'transfer_type'           => $type,
            'reason'                  => $reason,
            'reason_text'             => $data['reason_text'] ?? null,

            // snapshot lama
            'old_branch_id'           => $oldBranchId,
            'old_branch_name'         => $emp['branch_name'] ?? null,
            'old_department_id'       => $oldDeptId,
            'old_department_name'     => $emp['department_name'] ?? null,
            'old_position_id'         => $oldPosId,
            'old_position_name'       => $emp['position_name'] ?? null,
            'old_work_location_id'    => $oldWorkLocId,
            'old_work_location_name'  => $emp['work_location_name'] ?? null,
            'old_manager_id'          => $emp['manager_id']    !== null ? (int) $emp['manager_id']    : null,
            'old_supervisor_id'       => $emp['supervisor_id'] !== null ? (int) $emp['supervisor_id'] : null,

            // tujuan
            'new_branch_id'           => $newBranchId,
            'new_branch_name'         => $newBranchName,
            'new_department_id'       => $newDeptId,
            'new_department_name'     => $newDeptName,
            'new_position_id'         => $newPosId,
            'new_position_name'       => $newPosName,
            'new_work_location_id'    => $newWorkLocId,
            'new_work_location_name'  => $newWorkLocName,

            'effective_date'          => $effDate,
            'submitted_at'            => $submittedAt,
            'requires_consent'        => $requiresConsent,
            'consent_status'          => $consentStatus,

            'status'                  => $status,
            'created_by'              => $userId,
            'created_at'              => $now,
            'updated_at'              => $now,
        ];

        $db->table('employee_transfers')->insert($insert);
        $id = (int) $db->insertID();

        // Audit log
        Services::audit()->forModule('HR')->logCreate(
            'employee_transfer',
            $id,
            ['employee_id' => $empId, 'type' => $type, 'effective_date' => $effDate, 'status' => $status],
            "Membuat mutasi {$emp['employee_number']} ({$type})"
        );

        // Notifikasi sederhana ke approver kalau langsung submit
        if ($shouldSubmit) {
            $this->notifyApprovers($db, $companyId, $id, $emp);
        }

        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        return $row ?: [];
    }

    /**
     * Submit draft → SUBMITTED. Berlaku hanya untuk status DRAFT.
     */
    public function submit(int $id, int $userId): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if ($row['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException("Hanya draft yang bisa disubmit. Status saat ini: {$row['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $update = [
            'status'          => 'SUBMITTED',
            'submitted_at'    => $now,
            'updated_at'      => $now,
        ];
        if (empty($row['transfer_number'])) {
            $update['transfer_number'] = $this->nextNumber($db, (int) $row['company_id']);
        }
        $db->table('employee_transfers')->where('id', $id)->update($update);

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id, ['status' => 'DRAFT'], ['status' => 'SUBMITTED'],
            'Submit mutasi untuk approval HR'
        );

        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if ($emp) $this->notifyApprovers($db, (int) $row['company_id'], $id, $emp);

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Approve mutasi. Hanya berlaku untuk status SUBMITTED.
     * Setelah approve, status → APPROVED. Implementasi sebenarnya (update
     * tabel employees) dilakukan oleh implement() — bisa manual atau
     * otomatis lewat cron pada effective_date.
     */
    public function approve(int $id, int $approverUserId, ?string $note = null): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if ($row['status'] !== 'SUBMITTED') {
            throw new \InvalidArgumentException("Hanya mutasi SUBMITTED yang bisa di-approve. Status: {$row['status']}");
        }
        // Kalau requires_consent dan belum AGREED, blok dulu
        if ((int) $row['requires_consent'] === 1 && $row['consent_status'] !== 'AGREED') {
            throw new \InvalidArgumentException(
                'Mutasi ini perlu persetujuan karyawan sebelum di-approve. Upload surat consent terlebih dulu.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $db->table('employee_transfers')->where('id', $id)->update([
            'status'             => 'APPROVED',
            'approver_user_id'   => $approverUserId,
            'approver_action_at' => $now,
            'approver_note'      => $note,
            'approved_at'        => $now,
            'updated_at'         => $now,
        ]);

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id,
            ['status' => 'SUBMITTED'], ['status' => 'APPROVED'],
            'Approve mutasi'
        );

        // Notify employee + creator
        $this->notifyDecision($db, (int) $row['company_id'], $id, 'APPROVED');

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Reject mutasi. Status → REJECTED.
     */
    public function reject(int $id, int $approverUserId, string $reason): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if (! in_array($row['status'], ['SUBMITTED'], true)) {
            throw new \InvalidArgumentException("Hanya mutasi SUBMITTED yang bisa di-reject");
        }
        if (strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Alasan reject minimal 10 karakter untuk audit trail');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('employee_transfers')->where('id', $id)->update([
            'status'             => 'REJECTED',
            'approver_user_id'   => $approverUserId,
            'approver_action_at' => $now,
            'rejection_reason'   => $reason,
            'updated_at'         => $now,
        ]);

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id,
            ['status' => 'SUBMITTED'], ['status' => 'REJECTED'],
            'Reject mutasi: ' . substr($reason, 0, 80)
        );

        $this->notifyDecision($db, (int) $row['company_id'], $id, 'REJECTED');

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Cancel mutasi (oleh creator atau HR). Boleh untuk DRAFT, SUBMITTED,
     * atau APPROVED yang belum di-implement.
     */
    public function cancel(int $id, int $userId, string $reason): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if (! in_array($row['status'], ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
            throw new \InvalidArgumentException("Status {$row['status']} tidak bisa dibatalkan");
        }
        if (strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Alasan pembatalan minimal 10 karakter');
        }

        $now = date('Y-m-d H:i:s');
        $oldStatus = $row['status'];
        $db->table('employee_transfers')->where('id', $id)->update([
            'status'              => 'CANCELLED',
            'cancelled_at'        => $now,
            'cancelled_by'        => $userId,
            'cancellation_reason' => $reason,
            'updated_at'          => $now,
        ]);

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id,
            ['status' => $oldStatus], ['status' => 'CANCELLED'],
            'Cancel mutasi: ' . substr($reason, 0, 80)
        );

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Apply mutasi ke records employees. Hanya berlaku untuk APPROVED.
     * Idempoten: gunakan transaction + status guard.
     *
     * @param int $id Transfer id
     * @param int $userId User yang melakukan implement
     * @param ?string $note Catatan implementasi
     * @return array Updated transfer row
     */
    public function implement(int $id, int $userId, ?string $note = null): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if ($row['status'] !== 'APPROVED') {
            throw new \InvalidArgumentException("Hanya mutasi APPROVED yang bisa di-implement. Status: {$row['status']}");
        }

        $empId = (int) $row['employee_id'];
        $emp = $db->table('employees')->where('id', $empId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException('Karyawan target sudah tidak ada (mungkin dihapus). Apply diabaikan.');
        }

        $update = [];
        if ($row['new_branch_id']        !== null) $update['branch_id']        = (int) $row['new_branch_id'];
        if ($row['new_department_id']    !== null) $update['department_id']    = (int) $row['new_department_id'];
        if ($row['new_position_id']      !== null) $update['position_id']      = (int) $row['new_position_id'];
        if ($row['new_work_location_id'] !== null) $update['work_location_id'] = (int) $row['new_work_location_id'];

        if (empty($update)) {
            throw new \InvalidArgumentException('Tidak ada field yang berubah. Cek data mutasi.');
        }

        $now = date('Y-m-d H:i:s');
        $update['updated_at'] = $now;

        $db->transBegin();
        try {
            $db->table('employees')->where('id', $empId)->update($update);

            $db->table('employee_transfers')->where('id', $id)->update([
                'status'              => 'IMPLEMENTED',
                'implemented_at'      => $now,
                'implemented_by'      => $userId,
                'implementation_note' => $note,
                'updated_at'          => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new \RuntimeException('Gagal apply mutasi (transaksi gagal)');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        // Recompute manager chain — perubahan dept biasanya mengubah atasan
        try {
            (new ReportingChainService())->recomputeForCompany((int) $row['company_id']);
        } catch (\Throwable $e) { /* non-fatal, log */ }

        // Recompute allowance kalau branch berubah (transport / makan basis jarak)
        $allowanceRecomputed = 0;
        if (isset($update['branch_id']) || isset($update['work_location_id'])) {
            try {
                if (method_exists(Services::allowance(), 'recomputeEmployee')) {
                    Services::allowance()->recomputeEmployee($empId);
                    $allowanceRecomputed = 1;
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }
        if ($allowanceRecomputed) {
            $db->table('employee_transfers')->where('id', $id)->update([
                'allowance_recomputed' => 1, 'updated_at' => $now,
            ]);
        }

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id,
            ['status' => 'APPROVED'], ['status' => 'IMPLEMENTED'],
            "Apply mutasi {$row['transfer_number']} ke records employees"
        );
        Services::audit()->forModule('MASTER')->logUpdate(
            'employee', $empId,
            [
                'branch_id'        => $row['old_branch_id'],
                'department_id'    => $row['old_department_id'],
                'position_id'      => $row['old_position_id'],
                'work_location_id' => $row['old_work_location_id'],
            ],
            $update,
            "Mutasi karyawan via {$row['transfer_number']}"
        );

        $this->notifyDecision($db, (int) $row['company_id'], $id, 'IMPLEMENTED');

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Tandai consent karyawan. Untuk mutasi yang requires_consent=1.
     */
    public function markConsent(int $id, string $decision, ?string $signedPath = null): array
    {
        $db = Database::connect();
        $row = $db->table('employee_transfers')->where('id', $id)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException('Mutasi tidak ditemukan');
        if ((int) $row['requires_consent'] !== 1) {
            throw new \InvalidArgumentException('Mutasi ini tidak memerlukan consent');
        }
        if (! in_array($decision, ['AGREED', 'REJECTED'], true)) {
            throw new \InvalidArgumentException('Decision harus AGREED atau REJECTED');
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'consent_status'      => $decision,
            'consent_at'          => $now,
            'updated_at'          => $now,
        ];
        if ($signedPath !== null) $update['consent_signed_path'] = $signedPath;
        // Kalau karyawan reject, otomatis cancel mutasi
        if ($decision === 'REJECTED') {
            $update['status']              = 'CANCELLED';
            $update['cancelled_at']        = $now;
            $update['cancellation_reason'] = 'Karyawan menolak mutasi (consent rejected)';
        }
        $db->table('employee_transfers')->where('id', $id)->update($update);

        Services::audit()->forModule('HR')->logUpdate(
            'employee_transfer', $id,
            ['consent_status' => $row['consent_status']],
            ['consent_status' => $decision],
            "Karyawan {$decision} consent mutasi"
        );

        return $db->table('employee_transfers')->where('id', $id)->get()->getRowArray() ?: [];
    }

    /**
     * Apply semua mutasi yang APPROVED dengan effective_date <= hari ini.
     * Dipanggil oleh command `hr:apply-transfers`.
     *
     * @return array{processed: int, succeeded: int, failed: int, errors: array}
     */
    public function applyDueTransfers(?int $companyId = null): array
    {
        $db = Database::connect();
        $today = date('Y-m-d');
        $b = $db->table('employee_transfers')
            ->where('status', 'APPROVED')
            ->where('effective_date <=', $today)
            ->where('deleted_at IS NULL');
        if ($companyId !== null) $b->where('company_id', $companyId);
        $rows = $b->get()->getResultArray();

        $stats = ['processed' => count($rows), 'succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $r) {
            try {
                $this->implement((int) $r['id'], 0, 'Auto-apply pada effective_date');
                $stats['succeeded']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'transfer_id' => (int) $r['id'],
                    'error'       => $e->getMessage(),
                ];
            }
        }
        return $stats;
    }

    // ─── Helpers ────────────────────────────────────────────

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        return (int) $v;
    }

    private function assertSameTenant($db, string $table, ?int $id, int $companyId, string $label): void
    {
        if ($id === null) return;
        $row = $db->table($table)->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("$label tidak ditemukan");
        if (isset($row['company_id']) && (int) $row['company_id'] !== $companyId) {
            throw new \InvalidArgumentException("$label harus dalam perusahaan yang sama");
        }
    }

    /**
     * Generate transfer_number unik per-company. Format: MUT-YYYYMM-NNNN.
     */
    private function nextNumber($db, int $companyId): string
    {
        $prefix = 'MUT-' . date('Ym') . '-';
        $row = $db->table('employee_transfers')
            ->select('transfer_number')
            ->where('company_id', $companyId)
            ->like('transfer_number', $prefix, 'after')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        $n = 1;
        if ($row && preg_match('/(\d+)$/', (string) $row['transfer_number'], $m)) {
            $n = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Notify HR approvers + employee saat mutasi disubmit.
     *
     * Penting: dipanggil dengan signature lengkap NotificationService::send()
     *   send(userId, companyId, type, title, message, data?, linkUrl?)
     * Sebelumnya saya cuma kirim 3 arg → notification tidak ter-create di DB.
     */
    private function notifyApprovers($db, int $companyId, int $transferId, array $emp): void
    {
        $row = $db->table('employee_transfers')->where('id', $transferId)->get()->getRowArray();
        if (! $row) return;
        $empName = $emp['full_name'] ?? $emp['employee_number'] ?? 'Karyawan';
        $transferNo = $row['transfer_number'] ?? "#{$transferId}";

        // 1. Notify HR approvers (semua user dengan permission employee_transfer.approve)
        try {
            $rows = $db->table('users u')
                ->select('u.id')
                ->join('role_permissions rp', 'rp.role_id = u.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('p.slug', 'employee_transfer.approve')
                ->where('u.company_id', $companyId)
                ->where('u.is_active', 1)
                ->groupBy('u.id')
                ->get()->getResultArray();
            foreach ($rows as $u) {
                Services::notification()->send(
                    (int) $u['id'],
                    $companyId,
                    'transfer.submitted',
                    "Mutasi karyawan menunggu approval: {$transferNo}",
                    "Pengajuan mutasi untuk {$empName} menunggu persetujuan Anda.",
                    [
                        'transfer_id'     => $transferId,
                        'transfer_number' => $transferNo,
                        'employee_id'     => (int) ($emp['id'] ?? 0),
                        'employee_name'   => $empName,
                    ],
                    '/hr/transfers'
                );
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Notify approvers gagal: ' . $e->getMessage());
        }

        // 2. Notify karyawan ybs — terutama penting kalau requires_consent=1
        //    karena mereka harus aksi (approve/reject mutasi mereka sendiri).
        if (! empty($emp['user_id'])) {
            try {
                $needsConsent = (int) ($row['requires_consent'] ?? 0) === 1;
                $title = $needsConsent
                    ? "Persetujuan mutasi Anda dibutuhkan: {$transferNo}"
                    : "Pengajuan mutasi Anda telah dibuat: {$transferNo}";
                $message = $needsConsent
                    ? "Ada pengajuan mutasi yang membutuhkan persetujuan Anda. Buka 'Mutasi Saya' untuk meninjau dan memberi keputusan."
                    : "Pengajuan mutasi Anda sedang menunggu approval HR. Anda akan diberitahu lagi setelah ada keputusan.";
                Services::notification()->send(
                    (int) $emp['user_id'],
                    $companyId,
                    $needsConsent ? 'transfer.consent_required' : 'transfer.submitted',
                    $title,
                    $message,
                    [
                        'transfer_id'      => $transferId,
                        'transfer_number'  => $transferNo,
                        'requires_consent' => $needsConsent ? 1 : 0,
                    ],
                    '/hr/my-transfers'
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Notify employee gagal: ' . $e->getMessage());
            }
        }
    }

    /**
     * Notify karyawan + creator saat ada keputusan (APPROVED/REJECTED/IMPLEMENTED).
     */
    private function notifyDecision($db, int $companyId, int $transferId, string $decision): void
    {
        $row = $db->table('employee_transfers')->where('id', $transferId)->get()->getRowArray();
        if (! $row) return;

        $transferNo = $row['transfer_number'] ?? "#{$transferId}";
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();

        $titleMap = [
            'APPROVED'    => "Mutasi disetujui: {$transferNo}",
            'REJECTED'    => "Mutasi ditolak: {$transferNo}",
            'IMPLEMENTED' => "Mutasi telah berlaku: {$transferNo}",
        ];
        $messageMap = [
            'APPROVED'    => "Mutasi Anda telah disetujui dan akan berlaku pada tanggal efektif yang ditentukan.",
            'REJECTED'    => "Mutasi Anda telah ditolak. Buka detail untuk melihat alasannya.",
            'IMPLEMENTED' => "Mutasi Anda telah diimplementasikan. Cabang/departemen/posisi baru sudah berlaku.",
        ];
        $title = $titleMap[$decision] ?? "Status mutasi diperbarui: {$decision}";
        $message = $messageMap[$decision] ?? "Status mutasi #{$transferNo} sekarang: {$decision}";

        // Notify employee user (kalau punya akun login)
        if ($emp && ! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    $companyId,
                    'transfer.decision',
                    $title,
                    $message,
                    [
                        'transfer_id'     => $transferId,
                        'transfer_number' => $transferNo,
                        'decision'        => $decision,
                    ],
                    '/hr/my-transfers'
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Notify decision (employee) gagal: ' . $e->getMessage());
            }
        }

        // Notify creator kalau berbeda dari karyawan ybs (HR/atasan yang
        // submit). Skip kalau creator = user employee yang sudah dinotif.
        if (! empty($row['created_by']) && (! $emp || (int) ($emp['user_id'] ?? 0) !== (int) $row['created_by'])) {
            try {
                Services::notification()->send(
                    (int) $row['created_by'],
                    $companyId,
                    'transfer.decision',
                    $title,
                    "Mutasi yang Anda submit untuk " . ($emp['full_name'] ?? '?') . " sekarang berstatus {$decision}.",
                    [
                        'transfer_id'     => $transferId,
                        'transfer_number' => $transferNo,
                        'decision'        => $decision,
                    ],
                    '/hr/transfers'
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Notify decision (creator) gagal: ' . $e->getMessage());
            }
        }
    }
}
