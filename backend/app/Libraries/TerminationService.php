<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * TerminationService — PHK formal workflow (Sprint 4 / OFF-5).
 *
 * Beda dari ResignationController (voluntary):
 *   - Inisiator = perusahaan, bukan karyawan
 *   - Multi-step approval: HR Director → BOD (kondisional)
 *   - Wajib bipartite negotiation untuk UNILATERAL non-pelanggaran-berat
 *   - Severance auto-create dengan multiplier sesuai PP 35/2021
 *   - PHK letter generated di stage APPROVED
 *
 * Lifecycle:
 *   DRAFT
 *     ↓ submit() — kalau butuh bipartite, masuk PENDING_BIPARTITE
 *     ↓ recordBipartite() — set outcome → PENDING_HR_DIRECTOR
 *   PENDING_BIPARTITE  (kalau termination_type=UNILATERAL & reason bukan VIOLATION_GROSS)
 *     ↓ recordBipartite()
 *   PENDING_HR_DIRECTOR
 *     ↓ approveStep() — HR Director approve → PENDING_BOD or APPROVED
 *   PENDING_BOD  (kalau severance > threshold, atau PHK kolektif)
 *     ↓ approveStep() — BOD approve → APPROVED
 *   APPROVED
 *     ↓ execute() — generate PHK letter, create severance, set resign_date
 *   EXECUTED
 *
 * Reject paths: REJECTED (any approve step), CANCELLED (initiator/HR cancel
 * before EXECUTED).
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item OFF-5 + SeveranceCalculator untuk
 * detail multiplier per `reason_code`.
 */
class TerminationService
{
    public const TYPES = ['UNILATERAL', 'MUTUAL', 'CONTRACT_END', 'RETIREMENT', 'DEATH', 'DISABILITY'];
    public const STATUSES = [
        'DRAFT', 'PENDING_BIPARTITE', 'PENDING_HR_DIRECTOR', 'PENDING_BOD',
        'APPROVED', 'EXECUTED', 'REJECTED', 'CANCELLED',
    ];

    /**
     * Threshold pesangon (rupiah) yang trigger BOD approval.
     * Default 100 juta. HR bisa override via settings.
     */
    private const BOD_THRESHOLD_DEFAULT = 100_000_000;

    /**
     * Reason codes yang TIDAK butuh bipartite (langsung lanjut ke approval).
     * VIOLATION_GROSS / LONG_ABSENT_PROHIBIT — by law tidak butuh bipartite
     * (cukup bukti pelanggaran). DEATH / RETIREMENT / CONTRACT_END juga.
     */
    private const NO_BIPARTITE_REASONS = [
        'VIOLATION_GROSS', 'LONG_ABSENT_PROHIBIT',
        'DEATH', 'PENSION_RETIRE', 'DISABILITY_PROLONGED',
        'END_OF_PKWT', 'CONTRACT_EXPIRY',
    ];

    /**
     * Buat termination request DRAFT.
     *
     * @param array $data {
     *   employee_id (req), termination_type (req), reason_code (req),
     *   reason_detail, planned_termination_date (req), last_disciplinary_id,
     *   attachment_path
     * }
     */
    public function create(array $data, int $userId): array
    {
        $db = Database::connect();

        $employeeId = (int) ($data['employee_id'] ?? 0);
        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        if (! empty($emp['resign_date'])) {
            throw new \RuntimeException(
                "Karyawan sudah resign per {$emp['resign_date']} — tidak bisa di-PHK lagi."
            );
        }

        $termType = (string) ($data['termination_type'] ?? '');
        if (! in_array($termType, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "termination_type tidak valid. Pilihan: " . implode(', ', self::TYPES)
            );
        }
        $reasonCode = (string) ($data['reason_code'] ?? '');
        if (! isset(SeveranceCalculator::REASONS[$reasonCode])) {
            throw new \InvalidArgumentException(
                "reason_code tidak valid. Pilihan: " . implode(', ', array_keys(SeveranceCalculator::REASONS))
            );
        }
        $plannedDate = (string) ($data['planned_termination_date'] ?? '');
        if ($plannedDate === '' || ! strtotime($plannedDate)) {
            throw new \InvalidArgumentException('planned_termination_date wajib (Y-m-d)');
        }

        // Cek tidak ada termination aktif lain untuk employee ini
        $activeExists = $db->table('termination_requests')
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['DRAFT', 'PENDING_BIPARTITE', 'PENDING_HR_DIRECTOR', 'PENDING_BOD', 'APPROVED'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($activeExists > 0) {
            throw new \RuntimeException(
                'Sudah ada PHK request aktif untuk karyawan ini. Selesaikan / cancel dulu.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => (int) $emp['company_id'],
            'employee_id'       => $employeeId,
            'reference_number'  => $this->nextReferenceNumber($db, (int) $emp['company_id']),
            'termination_type'  => $termType,
            'reason_code'       => $reasonCode,
            'reason_detail'     => $data['reason_detail'] ?? null,
            'planned_termination_date' => $plannedDate,
            'last_disciplinary_id' => isset($data['last_disciplinary_id']) ? (int) $data['last_disciplinary_id'] : null,
            'attachment_path'   => $data['attachment_path'] ?? null,
            'status'            => 'DRAFT',
            'initiated_by'      => $userId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('termination_requests')->insert($insert);
        $id = (int) $db->insertID();

        try {
            Services::audit()->forModule('TERMINATION')->logCreate(
                'termination_request', $id, $insert,
                "Inisiasi PHK draft #{$insert['reference_number']} untuk {$emp['full_name']} "
                . "(reason: {$reasonCode})"
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Update DRAFT — ubah field yang aman sebelum submit.
     */
    public function update(int $id, array $data, int $userId): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya DRAFT yang bisa di-edit. Current: {$row['status']}");
        }
        $update = [];
        foreach ([
            'termination_type', 'reason_code', 'reason_detail',
            'planned_termination_date', 'last_disciplinary_id', 'attachment_path',
        ] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }
        if (isset($update['termination_type']) && ! in_array($update['termination_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('termination_type tidak valid');
        }
        if (isset($update['reason_code']) && ! isset(SeveranceCalculator::REASONS[$update['reason_code']])) {
            throw new \InvalidArgumentException('reason_code tidak valid');
        }
        if (empty($update)) {
            throw new \InvalidArgumentException('Tidak ada field yang di-update');
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('termination_requests')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('TERMINATION')->logUpdate(
                'termination_request', $id, $row, $update
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Submit DRAFT → next stage.
     * Kalau reason perlu bipartite → PENDING_BIPARTITE.
     * Kalau tidak perlu → langsung PENDING_HR_DIRECTOR.
     */
    public function submit(int $id, int $userId): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya DRAFT yang bisa di-submit. Current: {$row['status']}");
        }

        $needsBipartite = $this->requiresBipartite($row);
        $nextStatus = $needsBipartite ? 'PENDING_BIPARTITE' : 'PENDING_HR_DIRECTOR';

        $now = date('Y-m-d H:i:s');
        $db->table('termination_requests')->where('id', $id)->update([
            'status'       => $nextStatus,
            'submitted_at' => $now,
            'updated_at'   => $now,
        ]);

        try {
            Services::audit()->forModule('TERMINATION')->log(
                action: 'termination.submit',
                entity: 'termination_request',
                entityId: $id,
                description: "Submit PHK #{$row['reference_number']} → {$nextStatus}",
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Record bipartite outcome. Kalau AGREED → lanjut PENDING_HR_DIRECTOR.
     * Kalau DISAGREED → set tripartite_required = 1, status tetap
     * PENDING_BIPARTITE (HR harus eskalasi ke disnaker manual).
     * Kalau PENDING_TRIPARTITE → status ke PENDING_HR_DIRECTOR (tetap proses
     * paralel dengan disnaker).
     */
    public function recordBipartite(
        int $id,
        int $userId,
        string $outcome,
        string $minutes,
    ): array {
        if (! in_array($outcome, ['AGREED', 'DISAGREED', 'PENDING_TRIPARTITE'], true)) {
            throw new \InvalidArgumentException(
                'outcome harus salah satu: AGREED, DISAGREED, PENDING_TRIPARTITE'
            );
        }
        if (strlen(trim($minutes)) < 20) {
            throw new \InvalidArgumentException('Notulen bipartite minimum 20 karakter');
        }

        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'PENDING_BIPARTITE') {
            throw new \RuntimeException(
                "Status harus PENDING_BIPARTITE untuk record bipartite. Current: {$row['status']}"
            );
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'bipartite_held_at'   => $now,
            'bipartite_outcome'   => $outcome,
            'bipartite_minutes'   => $minutes,
            'tripartite_required' => $outcome === 'DISAGREED' ? 1 : 0,
            'updated_at'          => $now,
        ];
        // AGREED & PENDING_TRIPARTITE → lanjut ke HR Director.
        // DISAGREED → tahan di PENDING_BIPARTITE (perlu disnaker).
        if (in_array($outcome, ['AGREED', 'PENDING_TRIPARTITE'], true)) {
            $update['status'] = 'PENDING_HR_DIRECTOR';
        }
        $db->table('termination_requests')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('TERMINATION')->log(
                action: 'termination.bipartite',
                entity: 'termination_request',
                entityId: $id,
                description: "Bipartite #{$row['reference_number']} — outcome: {$outcome}",
                severity: $outcome === 'DISAGREED' ? 'WARNING' : 'NOTICE',
                metadata: ['outcome' => $outcome],
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Approve step di stage saat ini.
     *
     * @param string $stage 'HR_DIRECTOR' | 'BOD'
     */
    public function approveStep(int $id, int $userId, string $stage, ?string $note = null): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        $now = date('Y-m-d H:i:s');

        if ($stage === 'HR_DIRECTOR') {
            if ($row['status'] !== 'PENDING_HR_DIRECTOR') {
                throw new \RuntimeException(
                    "Status harus PENDING_HR_DIRECTOR. Current: {$row['status']}"
                );
            }
            // Cek apakah butuh BOD (severance preview > threshold OR PHK kolektif)
            $needsBod = $this->requiresBodApproval($row);
            $nextStatus = $needsBod ? 'PENDING_BOD' : 'APPROVED';

            $db->table('termination_requests')->where('id', $id)->update([
                'status'                => $nextStatus,
                'hr_director_user_id'   => $userId,
                'hr_director_action_at' => $now,
                'hr_director_decision'  => 'APPROVED',
                'hr_director_note'      => $note,
                'updated_at'            => $now,
            ]);

            try {
                Services::audit()->forModule('TERMINATION')->log(
                    action: 'termination.approve_hr_director',
                    entity: 'termination_request',
                    entityId: $id,
                    description: "HR Director approve PHK #{$row['reference_number']} → {$nextStatus}",
                    severity: 'WARNING',
                );
            } catch (\Throwable $e) {}

            return $this->load($id);
        }

        if ($stage === 'BOD') {
            if ($row['status'] !== 'PENDING_BOD') {
                throw new \RuntimeException("Status harus PENDING_BOD. Current: {$row['status']}");
            }
            $db->table('termination_requests')->where('id', $id)->update([
                'status'         => 'APPROVED',
                'bod_user_id'    => $userId,
                'bod_action_at'  => $now,
                'bod_decision'   => 'APPROVED',
                'bod_note'       => $note,
                'updated_at'     => $now,
            ]);

            try {
                Services::audit()->forModule('TERMINATION')->log(
                    action: 'termination.approve_bod',
                    entity: 'termination_request',
                    entityId: $id,
                    description: "BOD approve PHK #{$row['reference_number']} → APPROVED",
                    severity: 'WARNING',
                );
            } catch (\Throwable $e) {}

            return $this->load($id);
        }

        throw new \InvalidArgumentException("stage tidak valid: {$stage}. Pilih HR_DIRECTOR / BOD.");
    }

    /**
     * Reject di stage HR_DIRECTOR atau BOD. Status → REJECTED.
     */
    public function reject(int $id, int $userId, string $stage, string $reason): array
    {
        if (strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Alasan reject minimum 10 karakter');
        }

        $db = Database::connect();
        $row = $this->loadRaw($id);
        $now = date('Y-m-d H:i:s');

        $update = [
            'status'           => 'REJECTED',
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ];

        if ($stage === 'HR_DIRECTOR') {
            if ($row['status'] !== 'PENDING_HR_DIRECTOR') {
                throw new \RuntimeException(
                    "Status harus PENDING_HR_DIRECTOR. Current: {$row['status']}"
                );
            }
            $update['hr_director_user_id']   = $userId;
            $update['hr_director_action_at'] = $now;
            $update['hr_director_decision']  = 'REJECTED';
            $update['hr_director_note']      = $reason;
        } elseif ($stage === 'BOD') {
            if ($row['status'] !== 'PENDING_BOD') {
                throw new \RuntimeException("Status harus PENDING_BOD. Current: {$row['status']}");
            }
            $update['bod_user_id']    = $userId;
            $update['bod_action_at']  = $now;
            $update['bod_decision']   = 'REJECTED';
            $update['bod_note']       = $reason;
        } else {
            throw new \InvalidArgumentException("stage tidak valid: {$stage}");
        }

        $db->table('termination_requests')->where('id', $id)->update($update);

        try {
            Services::audit()->forModule('TERMINATION')->log(
                action: 'termination.reject_' . strtolower($stage),
                entity: 'termination_request',
                entityId: $id,
                description: "Reject PHK #{$row['reference_number']} di stage {$stage}: "
                    . mb_substr($reason, 0, 200),
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Cancel termination request (oleh HR/initiator). Hanya allowed dari
     * status sebelum EXECUTED.
     */
    public function cancel(int $id, int $userId, string $reason): array
    {
        if (strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Alasan cancel minimum 10 karakter');
        }
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if (in_array($row['status'], ['EXECUTED', 'REJECTED', 'CANCELLED'], true)) {
            throw new \RuntimeException("Tidak bisa cancel di status {$row['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('termination_requests')->where('id', $id)->update([
            'status'              => 'CANCELLED',
            'cancelled_at'        => $now,
            'cancelled_by'        => $userId,
            'cancellation_reason' => $reason,
            'updated_at'          => $now,
        ]);

        try {
            Services::audit()->forModule('TERMINATION')->log(
                action: 'termination.cancel',
                entity: 'termination_request',
                entityId: $id,
                description: "PHK #{$row['reference_number']} dibatalkan: " . mb_substr($reason, 0, 200),
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Execute APPROVED termination — final step:
     *   1. Auto-create severance_calculations DRAFT (lewat preview SeveranceCalculator)
     *   2. Generate PHK letter (best-effort, kalau template ada)
     *   3. Set employees.resign_date + resign_reason
     *   4. Trigger offboarding tasks dari template OFFBOARD
     *   5. Notify employee + audit CRITICAL
     *   6. Status → EXECUTED
     */
    public function execute(int $id, int $userId): array
    {
        $db = Database::connect();
        $row = $this->loadRaw($id);
        if ($row['status'] !== 'APPROVED') {
            throw new \RuntimeException(
                "Hanya status APPROVED yang bisa di-execute. Current: {$row['status']}"
            );
        }

        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if (! $emp) {
            throw new \RuntimeException("Employee #{$row['employee_id']} tidak ditemukan");
        }

        $effectiveDate = $row['planned_termination_date'];
        $now = date('Y-m-d H:i:s');

        // 1. Auto-create severance DRAFT
        $severanceId = null;
        try {
            $severanceId = $this->autoCreateSeverance($db, $row, $emp, $userId);
        } catch (\Throwable $e) {
            log_message('warning', 'Termination::execute autoCreateSeverance failed: ' . $e->getMessage());
        }

        // 2. Generate PHK letter (best-effort)
        $phkLetterId = null;
        try {
            $phkLetterId = $this->generatePhkLetter($db, $row, $emp, $userId);
        } catch (\Throwable $e) {
            log_message('warning', 'Termination::execute generatePhkLetter failed: ' . $e->getMessage());
        }

        // 3. Update termination_requests
        $update = [
            'status'                  => 'EXECUTED',
            'effective_date'          => $effectiveDate,
            'executed_by'             => $userId,
            'executed_at'             => $now,
            'severance_id'            => $severanceId,
            'phk_letter_id'           => $phkLetterId,
            'phk_letter_generated_at' => $phkLetterId ? $now : null,
            'updated_at'              => $now,
        ];
        $db->table('termination_requests')->where('id', $id)->update($update);

        // 4. Update employees.resign_date + reason
        $reasonLabel = SeveranceCalculator::REASONS[$row['reason_code']]['label'] ?? $row['reason_code'];
        $db->table('employees')->where('id', (int) $row['employee_id'])->update([
            'resign_date'   => $effectiveDate,
            'resign_reason' => 'PHK_' . $row['termination_type'] . ': ' . $reasonLabel,
            'is_active'     => 1, // tetap active sampai resign_date tercapai (payroll & access tergantung tanggal)
            'updated_at'    => $now,
        ]);

        // 5. Trigger offboarding tasks
        $tasksCreated = 0;
        try {
            $svc = new OnboardingService();
            $result = $svc->applyTemplate(
                (int) $row['employee_id'],
                null,
                'OFFBOARD',
                $effectiveDate,
            );
            $tasksCreated = (int) ($result['created'] ?? 0);
        } catch (\Throwable $e) {
            log_message('warning', 'Termination::execute offboarding tasks failed: ' . $e->getMessage());
        }

        // 6. Auto-create MPP DRAFT untuk pengganti (kalau bukan PHK kolektif efisiensi)
        $autoMppId = null;
        if (! in_array($row['reason_code'], ['EFFICIENCY_LOSS', 'EFFICIENCY_PREVENT', 'CLOSURE_LOSS_2YR', 'CLOSURE_NO_REASON', 'PKPU_BANKRUPTCY'], true)) {
            try {
                $autoMppId = Services::manpower()->autoCreateForResignation(
                    (int) $row['company_id'],
                    (int) $row['employee_id'],
                    $userId,
                    (string) $effectiveDate,
                    $emp,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Termination::execute auto-create MPP failed: ' . $e->getMessage());
            }
        }

        // 7. Notify employee
        if (! empty($emp['user_id'])) {
            try {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    (int) $row['company_id'],
                    'termination.executed',
                    'Pemberitahuan Pemutusan Hubungan Kerja',
                    "Surat PHK #{$row['reference_number']} telah diterbitkan. "
                    . "Tanggal efektif: {$effectiveDate}. Silakan koordinasi dengan HR untuk proses offboarding.",
                    [
                        'termination_id'  => $id,
                        'reference'       => $row['reference_number'],
                        'effective_date'  => $effectiveDate,
                        'severance_id'    => $severanceId,
                        'phk_letter_id'   => $phkLetterId,
                    ],
                    null,
                    true,
                );
            } catch (\Throwable $e) {}
        }

        // 8. Audit CRITICAL
        try {
            Services::audit()->forModule('TERMINATION')->log(
                action: 'termination.execute',
                entity: 'termination_request',
                entityId: $id,
                description: "PHK #{$row['reference_number']} EXECUTED untuk {$emp['full_name']} "
                    . "({$reasonLabel}). Severance #" . ($severanceId ?: '?')
                    . ", PHK letter #" . ($phkLetterId ?: '?'),
                severity: 'CRITICAL',
                metadata: [
                    'severance_id' => $severanceId,
                    'phk_letter_id' => $phkLetterId,
                    'tasks_created' => $tasksCreated,
                    'mpp_auto_created' => $autoMppId,
                ],
            );
        } catch (\Throwable $e) {}

        return $this->load($id);
    }

    /**
     * Daftar PHK requests dengan filter.
     */
    public function listRequests(array $filters = []): array
    {
        $db = Database::connect();
        $b = $db->table('termination_requests tr')
            ->select('tr.*, e.full_name AS employee_name, e.employee_number, '
                . 'd.name AS department_name, p.name AS position_name')
            ->join('employees e', 'e.id = tr.employee_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('tr.deleted_at IS NULL');

        if (! empty($filters['company_id'])) {
            $b->where('tr.company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['employee_id'])) {
            $b->where('tr.employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $b->where('tr.status', $filters['status']);
        }
        if (! empty($filters['termination_type'])) {
            $b->where('tr.termination_type', $filters['termination_type']);
        }
        if (! empty($filters['reason_code'])) {
            $b->where('tr.reason_code', $filters['reason_code']);
        }
        if (! empty($filters['date_from'])) {
            $b->where('tr.planned_termination_date >=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $b->where('tr.planned_termination_date <=', $filters['date_to']);
        }

        $b->orderBy('tr.created_at', 'DESC');

        $perPage = (int) ($filters['per_page'] ?? 0);
        $page    = (int) ($filters['page'] ?? 1);
        if ($perPage > 0) {
            $total = (clone $b)->countAllResults(false);
            $rows = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
            return [
                'rows' => $rows, 'total' => $total,
                'page' => $page, 'per_page' => $perPage,
            ];
        }

        return ['rows' => $b->get()->getResultArray()];
    }

    /**
     * Load row + decoded JSON + employee info.
     */
    public function load(int $id): array
    {
        $db = Database::connect();
        $row = $db->table('termination_requests tr')
            ->select('tr.*, e.full_name AS employee_name, e.employee_number, '
                . 'e.user_id AS employee_user_id, '
                . 'd.name AS department_name, p.name AS position_name')
            ->join('employees e', 'e.id = tr.employee_id', 'left')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('positions p', 'p.id = e.position_id', 'left')
            ->where('tr.id', $id)
            ->where('tr.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Termination request #{$id} tidak ditemukan");
        }
        return $row;
    }

    private function loadRaw(int $id): array
    {
        $db = Database::connect();
        $row = $db->table('termination_requests')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Termination request #{$id} tidak ditemukan");
        }
        return $row;
    }

    /**
     * Cek apakah termination ini perlu bipartite negotiation.
     * Tidak perlu kalau:
     *   - termination_type = MUTUAL (sudah ada kesepakatan)
     *   - termination_type = CONTRACT_END / RETIREMENT / DEATH / DISABILITY
     *   - reason_code di NO_BIPARTITE_REASONS
     */
    public function requiresBipartite(array $row): bool
    {
        if (in_array($row['termination_type'], ['MUTUAL', 'CONTRACT_END', 'RETIREMENT', 'DEATH', 'DISABILITY'], true)) {
            return false;
        }
        if (in_array($row['reason_code'], self::NO_BIPARTITE_REASONS, true)) {
            return false;
        }
        return true;
    }

    /**
     * Apakah perlu BOD approval setelah HR Director?
     * Default: ya kalau preview pesangon > BOD_THRESHOLD_DEFAULT (100jt).
     * Future: HR bisa override threshold via settings table.
     */
    public function requiresBodApproval(array $row): bool
    {
        try {
            $preview = $this->previewSeverance($row);
            return ($preview['total_amount'] ?? 0) > self::BOD_THRESHOLD_DEFAULT;
        } catch (\Throwable $e) {
            // Defensive: kalau tidak bisa hitung preview, default true
            return true;
        }
    }

    /**
     * Preview severance amount untuk decision making (apakah lewat BOD).
     * Tidak menyimpan ke DB. Pakai SeveranceCalculator.
     *
     * @return array Result calc dari SeveranceCalculator
     */
    public function previewSeverance(array $row): array
    {
        $db = Database::connect();
        $emp = $db->table('employees')->where('id', (int) $row['employee_id'])->get()->getRowArray();
        if (! $emp) return ['total_amount' => 0];

        $calc = Services::severance();
        $serviceYears = $calc->computeServiceYears(
            (string) ($emp['join_date'] ?? date('Y-m-d')),
            (string) $row['planned_termination_date'],
        );
        $salary = (float) ($emp['basic_salary'] ?? 0) + (float) ($emp['fixed_allowance'] ?? 0);

        // Default unused leave
        $year = (int) date('Y', strtotime($row['planned_termination_date']));
        $leave = $db->table('leave_balances lb')
            ->select('lb.remaining')
            ->join('leave_types lt', 'lt.id = lb.leave_type_id')
            ->where('lb.employee_id', (int) $emp['id'])
            ->where('lb.period_year', $year)
            ->where('lt.category', 'ANNUAL')
            ->orderBy('lb.id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        $unusedLeave = $leave ? (float) $leave['remaining'] : 0;

        try {
            return $calc->calculate([
                'employee_id'         => (int) $emp['id'],
                'last_monthly_salary' => $salary,
                'service_years'       => $serviceYears,
                'termination_reason'  => $row['reason_code'],
                'termination_date'    => $row['planned_termination_date'],
                'unused_leave_days'   => $unusedLeave,
            ]);
        } catch (\Throwable $e) {
            return ['total_amount' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Auto-create severance_calculations DRAFT saat execute.
     */
    private function autoCreateSeverance(
        \CodeIgniter\Database\BaseConnection $db,
        array $termination,
        array $emp,
        int $userId,
    ): ?int {
        if (! $db->tableExists('severance_calculations')) return null;

        $calc = Services::severance();
        $serviceYears = $calc->computeServiceYears(
            (string) ($emp['join_date'] ?? date('Y-m-d')),
            (string) $termination['planned_termination_date'],
        );
        $salary = (float) ($emp['basic_salary'] ?? 0) + (float) ($emp['fixed_allowance'] ?? 0);

        // Unused leave
        $year = (int) date('Y', strtotime($termination['planned_termination_date']));
        $leave = $db->table('leave_balances lb')
            ->select('lb.remaining')
            ->join('leave_types lt', 'lt.id = lb.leave_type_id')
            ->where('lb.employee_id', (int) $emp['id'])
            ->where('lb.period_year', $year)
            ->where('lt.category', 'ANNUAL')
            ->orderBy('lb.id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        $unusedLeave = $leave ? (float) $leave['remaining'] : 0;

        $result = $calc->calculate([
            'employee_id'         => (int) $emp['id'],
            'last_monthly_salary' => $salary,
            'service_years'       => $serviceYears,
            'termination_reason'  => $termination['reason_code'],
            'termination_date'    => $termination['planned_termination_date'],
            'unused_leave_days'   => $unusedLeave,
        ]);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => (int) $emp['company_id'],
            'employee_id'       => (int) $emp['id'],
            'disciplinary_record_id' => isset($termination['last_disciplinary_id'])
                ? (int) $termination['last_disciplinary_id'] : null,
            'termination_date'  => $termination['planned_termination_date'],
            'termination_reason'=> $result['termination_reason'],
            'reason_label'      => $result['reason_label'],
            'last_monthly_salary'=> $result['last_monthly_salary'],
            'service_years'     => $result['service_years'],
            'service_months'    => $result['service_months'],
            'pesangon_multiplier'=> $result['pesangon_multiplier'] ?? 0,
            'pesangon_months'   => $result['pesangon_months'] ?? 0,
            'pesangon_amount'   => $result['pesangon_amount'] ?? 0,
            'upmk_multiplier'   => $result['upmk_multiplier'] ?? 0,
            'upmk_months'       => $result['upmk_months'] ?? 0,
            'upmk_amount'       => $result['upmk_amount'] ?? 0,
            'uph_unused_leave_amount'=> $result['uph_unused_leave_amount'] ?? 0,
            'uph_homecoming_amount'  => $result['uph_homecoming_amount'] ?? 0,
            'uph_other_amount'  => $result['uph_other_amount'] ?? 0,
            'uph_total'         => $result['uph_total'] ?? 0,
            'separation_pay'    => $result['separation_pay'] ?? 0,
            'unused_leave_days' => $result['unused_leave_days'] ?? 0,
            'total_amount'      => $result['total_amount'],
            'notes'             => "Auto-create dari termination request #{$termination['reference_number']}",
            'status'            => 'DRAFT',
            'calculated_by'     => $userId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('severance_calculations')->insert($insert);
        return (int) $db->insertID();
    }

    /**
     * Generate PHK letter via ContractRenderer (best-effort).
     * Cari template contract_type=PHK_LETTER, fallback ke fallback HTML.
     */
    private function generatePhkLetter(
        \CodeIgniter\Database\BaseConnection $db,
        array $termination,
        array $emp,
        int $userId,
    ): ?int {
        if (! $db->tableExists('generated_contracts')) return null;
        $companyId = (int) $emp['company_id'];

        // Resolve template
        $template = null;
        try {
            $template = $db->table('contract_templates')
                ->where('company_id', $companyId)
                ->where('contract_type', 'PHK_LETTER')
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
        } catch (\Throwable $e) {
            $template = null;
        }

        // Build context
        $renderer = new ContractRenderer();
        $context = $renderer->buildContext((int) $emp['id']);
        $reasonLabel = SeveranceCalculator::REASONS[$termination['reason_code']]['label']
            ?? $termination['reason_code'];
        $context['phk'] = [
            'reference_number'  => $termination['reference_number'],
            'termination_type'  => $termination['termination_type'],
            'reason_code'       => $termination['reason_code'],
            'reason_label'      => $reasonLabel,
            'reason_detail'     => $termination['reason_detail'] ?? null,
            'effective_date'    => $termination['planned_termination_date'],
            'submitted_at'      => $termination['submitted_at'] ?? null,
            'approved_at'       => $termination['bod_action_at'] ?? $termination['hr_director_action_at'] ?? null,
        ];

        $html = $template
            ? $renderer->render($template, $context)
            : $this->renderFallbackPhkLetter($context);

        // Generate contract number
        $year = date('Y');
        $count = $db->table('generated_contracts')
            ->where('company_id', $companyId)
            ->like('contract_number', "PHK-{$year}-", 'after')
            ->countAllResults();
        $contractNumber = "PHK-{$year}-" . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);

        $now = date('Y-m-d H:i:s');
        $db->table('generated_contracts')->insert([
            'company_id'           => $companyId,
            'employee_id'          => (int) $emp['id'],
            'employee_contract_id' => null,
            'template_id'          => $template ? (int) $template['id'] : null,
            'contract_number'      => $contractNumber,
            'subject'              => 'Surat Pemutusan Hubungan Kerja — ' . ($emp['full_name'] ?? ''),
            'rendered_html'        => $html,
            'variables_snapshot'   => json_encode($context, JSON_UNESCAPED_UNICODE),
            'status'               => 'SENT',
            'sent_at'              => $now,
            'created_by'           => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        return (int) $db->insertID();
    }

    /**
     * Fallback HTML kalau template PHK_LETTER belum ada.
     */
    private function renderFallbackPhkLetter(array $context): string
    {
        $emp     = $context['employee'] ?? [];
        $company = $context['company'] ?? [];
        $phk     = $context['phk'] ?? [];

        $esc = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $longDate = static function (?string $d): string {
            if (! $d) return '-';
            $ts = strtotime($d);
            if (! $ts) return $d;
            $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
        };

        $companyName = $esc($company['name'] ?? 'Perusahaan');
        $companyCity = $esc($company['city'] ?? '');
        $empName     = $esc($emp['full_name'] ?? '');
        $empNumber   = $esc($emp['employee_number'] ?? '');
        $position    = $esc($context['position']['name'] ?? '-');
        $department  = $esc($context['department']['name'] ?? '-');
        $reference   = $esc($phk['reference_number'] ?? '');
        $reasonLabel = $esc($phk['reason_label'] ?? '');
        $reasonDetail= $esc($phk['reason_detail'] ?? '');
        $effective   = $longDate($phk['effective_date'] ?? null);
        $today       = $longDate(date('Y-m-d'));

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Surat Pemutusan Hubungan Kerja</title>
<style>
  body { font-family: 'Times New Roman', serif; font-size: 12pt; line-height: 1.6; color: #111; padding: 24px; }
  h1 { font-size: 14pt; text-align: center; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 1px; }
  h2 { font-size: 12pt; text-align: center; margin: 0 0 32px; font-weight: normal; text-decoration: underline; }
  table.id-table { width: 80%; margin: 8px 0 16px 24px; }
  table.id-table td { vertical-align: top; padding: 2px 6px; }
  table.id-table td.label { width: 30%; }
  .footer-note { margin-top: 32px; font-size: 10pt; color: #666; text-align: center; }
  .signature-block { margin-top: 56px; }
  .signature-block .right { text-align: left; margin-left: auto; width: 280px; }
</style>
</head>
<body>

<h1>Surat Pemutusan Hubungan Kerja</h1>
<h2>Nomor: {$reference}</h2>

<p>Dengan segala hormat, manajemen <strong>{$companyName}</strong> menyampaikan
keputusan resmi untuk memutuskan hubungan kerja dengan saudara/i:</p>

<table class="id-table">
  <tr><td class="label">Nama</td><td>: <strong>{$empName}</strong></td></tr>
  <tr><td class="label">Nomor Induk Karyawan</td><td>: {$empNumber}</td></tr>
  <tr><td class="label">Jabatan</td><td>: {$position}</td></tr>
  <tr><td class="label">Departemen</td><td>: {$department}</td></tr>
</table>

<p>Pemutusan hubungan kerja ini dilakukan dengan alasan: <strong>{$reasonLabel}</strong>,
dengan rincian sebagai berikut:</p>

<p style="margin: 8px 24px;">{$reasonDetail}</p>

<p>Tanggal efektif berakhirnya hubungan kerja adalah <strong>{$effective}</strong>.</p>

<p>Sesuai dengan ketentuan UU 13/2003 jo UU 6/2023 (Cipta Kerja) dan PP 35/2021,
hak-hak yang menjadi tanggungan perusahaan akan dihitung dan dibayarkan paling
lambat 7 (tujuh) hari kerja setelah tanggal efektif PHK, yang terdiri dari
pesangon, uang penghargaan masa kerja (UPMK), dan uang penggantian hak (UPH)
sesuai perhitungan yang akan diserahkan terpisah.</p>

<p>Karyawan yang bersangkutan tetap berkewajiban untuk:</p>
<ul>
  <li>Mengembalikan seluruh aset milik perusahaan;</li>
  <li>Menyelesaikan kewajiban finansial yang masih outstanding (jika ada);</li>
  <li>Menjaga kerahasiaan informasi perusahaan sesuai perjanjian kerja yang berlaku.</li>
</ul>

<p>Demikian surat ini kami sampaikan. Atas perhatian dan kerja sama saudara/i
selama ini, manajemen mengucapkan terima kasih.</p>

<div class="signature-block">
  <div class="right">
    <p>{$companyCity}, {$today}<br>
    <strong>{$companyName}</strong></p>
    <br><br><br>
    <p style="border-top: 1px solid #111; padding-top: 4px;">
      <strong>HR Director / Direksi</strong>
    </p>
  </div>
</div>

<p class="footer-note">Dokumen ini diterbitkan secara digital melalui sistem HRIS
dan sah tanpa cap basah.</p>

</body>
</html>
HTML;
    }

    /**
     * Generate reference number unik. Format: PHK-{YEAR}-{5digit}
     */
    public function nextReferenceNumber(\CodeIgniter\Database\BaseConnection $db, int $companyId): string
    {
        $year = date('Y');
        $prefix = "PHK-{$year}-";
        $count = $db->table('termination_requests')
            ->where('company_id', $companyId)
            ->like('reference_number', $prefix, 'after')
            ->countAllResults();
        $tries = 0;
        while ($tries < 50) {
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
            $exists = $db->table('termination_requests')
                ->where('company_id', $companyId)
                ->where('reference_number', $candidate)
                ->countAllResults();
            if ($exists === 0) return $candidate;
            $count++; $tries++;
        }
        return $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }
}