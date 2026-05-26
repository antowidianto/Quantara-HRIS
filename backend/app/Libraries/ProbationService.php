<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * ProbationService — manage masa percobaan karyawan PKWTT (Sprint 2).
 *
 * Konteks Indonesia (UU 13/2003 Pasal 60):
 *   - Probation hanya berlaku untuk PKWTT (karyawan tetap)
 *   - Maksimal 3 bulan dari tanggal join
 *   - Kalau lewat 3 bulan tanpa keputusan tertulis → otomatis jadi
 *     karyawan tetap (probation_status di-set PASSED otomatis lewat cron)
 *   - PKWT TIDAK boleh ada masa percobaan (PP 35/2021 Pasal 12)
 *
 * Tanggung jawab:
 *   1. `applyDefaults()` — auto-fill probation_end_date saat hire kalau
 *      karyawan PKWTT & belum di-set HR
 *   2. `decide()` — record keputusan PASS/FAIL/EXTEND dari HR/Supervisor
 *   3. `findExpiringSoon()` — list karyawan yang probation berakhir dalam
 *      H+14 hari (untuk reminder ke HR + supervisor)
 *   4. `findExpiredWithoutDecision()` — list karyawan yang sudah lewat
 *      probation_end_date tapi belum ada keputusan → auto-PASS dengan
 *      audit CRITICAL (compliance UU)
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item HIRE-1.
 */
class ProbationService
{
    public const STATUSES = ['ONGOING', 'PASSED', 'FAILED', 'EXTENDED', 'NA'];
    public const MAX_PROBATION_MONTHS = 3;
    public const REMINDER_LEAD_DAYS = 14; // Reminder H-14

    /**
     * Apply default probation values saat hire. Idempotent — tidak overwrite
     * kalau HR sudah set manual.
     *
     * Dipanggil dari RecruitmentService::convertToEmployee setelah employee
     * di-insert.
     *
     * Aturan:
     *   - PKWTT + probation_end_date kosong → set probation_end_date = join_date + 3 bulan
     *     dan probation_status = ONGOING
     *   - PKWTT + probation_end_date sudah di-set → status = ONGOING
     *   - PKWT/MAGANG/OUTSOURCING → status = NA, probation_end_date null
     *     (PP 35/2021 Pasal 12: PKWT tidak boleh ada probation)
     *
     * @return string Final probation_status yang di-set
     */
    public function applyDefaults(int $employeeId): string
    {
        $db = Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }

        $cols = $db->getFieldNames('employees');
        if (! in_array('probation_status', $cols, true)) {
            // Migration 52 belum dijalankan — silent skip
            return 'NA';
        }

        $employmentStatus = (string) ($emp['employment_status'] ?? '');
        $now = date('Y-m-d H:i:s');
        $update = ['updated_at' => $now];

        if ($employmentStatus !== 'PKWTT') {
            // Non-PKWTT tidak ada probation
            $update['probation_status'] = 'NA';
            $update['probation_end_date'] = null;
            $db->table('employees')->where('id', $employeeId)->update($update);
            return 'NA';
        }

        // PKWTT — set probation_end_date kalau belum ada
        $endDate = $emp['probation_end_date'] ?? null;
        if (! $endDate || trim((string) $endDate) === '') {
            $joinDate = $emp['join_date'] ?? date('Y-m-d');
            $endDate = date('Y-m-d', strtotime($joinDate . ' +' . self::MAX_PROBATION_MONTHS . ' months'));
            $update['probation_end_date'] = $endDate;
        }
        $update['probation_status'] = 'ONGOING';

        $db->table('employees')->where('id', $employeeId)->update($update);

        try {
            Services::audit()->forModule('HR')->log(
                action: 'probation.set_defaults',
                entity: 'employees',
                entityId: $employeeId,
                description: "Probation auto-set: end_date={$endDate} (PKWTT max 3 bulan UU 13/2003 Pasal 60)",
                severity: 'NOTICE',
                metadata: ['probation_end_date' => $endDate],
            );
        } catch (\Throwable $e) {}

        return 'ONGOING';
    }

    /**
     * Record keputusan probation: PASSED / FAILED / EXTENDED.
     *
     *   - PASSED: karyawan jadi tetap. Status ONGOING → PASSED.
     *   - FAILED: karyawan diberhentikan dengan kompensasi sesuai PP 35/2021.
     *     Status → FAILED. resign_date di-set ke tanggal keputusan.
     *     HR perlu lanjutkan via Severance untuk hitung kompensasi.
     *   - EXTENDED: probation diperpanjang (mis. 1 bulan lagi). Hanya boleh
     *     kalau total durasi probation tidak melebihi 3 bulan dari join_date.
     *     UU 13/2003 Pasal 60 bersifat strict — banyak putusan PHI menolak
     *     extension yang melewati 3 bulan total.
     */
    public function decide(
        int $employeeId,
        string $decision,
        int $userId,
        ?string $note = null,
        ?string $newEndDate = null, // hanya untuk EXTENDED
    ): array {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['PASSED', 'FAILED', 'EXTENDED'], true)) {
            throw new \InvalidArgumentException('decision harus PASSED, FAILED, atau EXTENDED');
        }
        if (in_array($decision, ['FAILED', 'EXTENDED'], true) && empty(trim((string) $note))) {
            throw new \InvalidArgumentException(
                "Note wajib untuk keputusan {$decision}. Untuk FAILED, jelaskan alasan; "
                . "untuk EXTENDED, jelaskan justifikasi & area improvement."
            );
        }

        $db = Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }

        $cols = $db->getFieldNames('employees');
        if (! in_array('probation_status', $cols, true)) {
            throw new \RuntimeException('Migration 52 belum dijalankan');
        }

        if (($emp['probation_status'] ?? 'NA') !== 'ONGOING') {
            throw new \RuntimeException(
                "Hanya probation status ONGOING yang bisa diputuskan. "
                . "Current: " . ($emp['probation_status'] ?? 'NA')
            );
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'probation_status'        => $decision,
            'probation_decision_at'   => $now,
            'probation_decision_by'   => $userId,
            'probation_decision_note' => $note,
            'updated_at'              => $now,
        ];
        $severity = 'NOTICE';
        $description = "Keputusan probation {$decision} untuk {$emp['full_name']}";

        if ($decision === 'EXTENDED') {
            if (! $newEndDate) {
                throw new \InvalidArgumentException('newEndDate wajib untuk EXTENDED');
            }
            // Validasi: total probation tidak boleh > 3 bulan dari join_date
            $joinDate = $emp['join_date'] ?? null;
            if ($joinDate) {
                $maxAllowed = date('Y-m-d', strtotime($joinDate . ' +' . self::MAX_PROBATION_MONTHS . ' months'));
                if (strtotime($newEndDate) > strtotime($maxAllowed)) {
                    throw new \RuntimeException(
                        "newEndDate ({$newEndDate}) melebihi batas 3 bulan dari join_date ({$joinDate}). "
                        . "Maks: {$maxAllowed} (UU 13/2003 Pasal 60)."
                    );
                }
            }
            $update['probation_end_date'] = $newEndDate;
            // Status reset ke ONGOING karena probation belum benar-benar selesai
            $update['probation_status'] = 'ONGOING';
            $description .= " (extended sampai {$newEndDate})";
            $severity = 'WARNING';
        } elseif ($decision === 'FAILED') {
            // Set resign_date ke today supaya payroll tahu karyawan keluar
            $update['resign_date'] = date('Y-m-d');
            $update['resign_reason'] = 'PROBATION_FAIL: ' . mb_substr((string) $note, 0, 200);
            $severity = 'CRITICAL';
            $description = "Probation FAIL untuk {$emp['full_name']} → resign_date set ke today. "
                . "HR wajib lanjut ke modul Severance untuk hitung kompensasi sesuai PP 35/2021.";
        }

        $db->table('employees')->where('id', $employeeId)->update($update);

        // Probation FAIL bukan sekedar set resign_date — secara hukum
        // tetap PHK yang berhak kompensasi PP 35/2021 Pasal 41-42 (UPMK +
        // sebagian UPH). Auto-trigger:
        //   1. Severance DRAFT (HR bisa review angka & finalize)
        //   2. Offboarding tasks dari template OFFBOARD
        //   3. Set status karyawan tetap aktif sampai resign_date — payroll
        //      yang akan handle deactivation via cron.
        $autoSeveranceId = null;
        $autoOffboardTasks = 0;
        if ($decision === 'FAILED') {
            try {
                $autoSeveranceId = $this->autoCreateProbationSeverance($db, $emp, $userId, (string) $note);
            } catch (\Throwable $e) {
                log_message('warning', "Probation FAIL auto-severance failed for #{$employeeId}: " . $e->getMessage());
            }
            try {
                $result = Services::onboarding()->applyTemplate(
                    $employeeId, null, 'OFFBOARD', date('Y-m-d')
                );
                $autoOffboardTasks = (int) ($result['created'] ?? 0);
            } catch (\Throwable $e) {
                log_message('warning', "Probation FAIL auto-offboarding failed for #{$employeeId}: " . $e->getMessage());
            }
            if ($autoSeveranceId || $autoOffboardTasks > 0) {
                $description .= " Auto-created: severance #" . ($autoSeveranceId ?: '?')
                    . ", offboarding tasks: {$autoOffboardTasks}.";
            }
        }

        // Notifikasi karyawan
        if (! empty($emp['user_id'])) {
            $notifTitle = match ($decision) {
                'PASSED'   => 'Selamat! Probation Anda PASSED',
                'FAILED'   => 'Status Probation Anda',
                'EXTENDED' => 'Probation Anda Diperpanjang',
            };
            $notifMsg = match ($decision) {
                'PASSED'   => 'Selamat, masa percobaan Anda telah berakhir dengan hasil baik. '
                    . 'Anda resmi menjadi karyawan tetap PKWTT.',
                'FAILED'   => 'HR telah memutuskan tidak melanjutkan masa kerja Anda setelah probation. '
                    . 'Silakan koordinasi dengan HR untuk penyelesaian akhir. Catatan: '
                    . mb_substr((string) $note, 0, 200),
                'EXTENDED' => "Masa percobaan Anda diperpanjang sampai {$newEndDate}. "
                    . 'Catatan dari HR: ' . mb_substr((string) $note, 0, 200),
            };
            try {
                Services::notification()->send(
                    (int) $emp['user_id'],
                    (int) $emp['company_id'],
                    'probation.' . strtolower($decision),
                    $notifTitle,
                    $notifMsg,
                    ['employee_id' => $employeeId],
                    null,
                    true,
                );
            } catch (\Throwable $e) {}
        }

        try {
            Services::audit()->forModule('HR')->log(
                action: 'probation.' . strtolower($decision),
                entity: 'employees',
                entityId: $employeeId,
                description: $description,
                severity: $severity,
                metadata: array_filter([
                    'note'         => $note,
                    'new_end_date' => $newEndDate,
                ], static fn ($v) => $v !== null),
            );
        } catch (\Throwable $e) {}

        return [
            'employee_id'      => $employeeId,
            'probation_status' => $update['probation_status'],
            'decided_at'       => $now,
        ];
    }

    /**
     * List karyawan yang probation_end_date dalam H+14 hari & status ONGOING.
     * Cron command pakai ini untuk reminder HR + supervisor.
     */
    public function findExpiringSoon(?int $companyId = null): array
    {
        $db = Database::connect();
        $cols = $db->getFieldNames('employees');
        if (! in_array('probation_status', $cols, true)) return [];

        $today = date('Y-m-d');
        $until = date('Y-m-d', strtotime("+" . self::REMINDER_LEAD_DAYS . " days"));

        $b = $db->table('employees e')
            ->select('e.id, e.company_id, e.full_name, e.employee_number, '
                . 'e.join_date, e.probation_end_date, e.probation_status, '
                . 'e.user_id, e.supervisor_id, e.department_id, '
                . 'd.name AS department_name')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->where('e.probation_status', 'ONGOING')
            ->where('e.probation_end_date IS NOT NULL')
            ->where('e.probation_end_date >=', $today)
            ->where('e.probation_end_date <=', $until)
            ->orderBy('e.probation_end_date', 'ASC');
        if ($companyId) $b->where('e.company_id', $companyId);

        return $b->get()->getResultArray();
    }

    /**
     * List karyawan yang probation_end_date sudah lewat tapi status masih ONGOING.
     * Cron auto-set jadi PASSED dengan audit CRITICAL — UU 13/2003 Pasal 60
     * mengatur kalau lewat 3 bulan tanpa keputusan, otomatis jadi karyawan tetap.
     */
    public function findExpiredWithoutDecision(?int $companyId = null): array
    {
        $db = Database::connect();
        $cols = $db->getFieldNames('employees');
        if (! in_array('probation_status', $cols, true)) return [];

        $today = date('Y-m-d');

        $b = $db->table('employees e')
            ->select('e.id, e.company_id, e.full_name, e.employee_number, '
                . 'e.join_date, e.probation_end_date, e.user_id')
            ->where('e.deleted_at IS NULL')
            ->where('e.is_active', 1)
            ->where('e.probation_status', 'ONGOING')
            ->where('e.probation_end_date IS NOT NULL')
            ->where('e.probation_end_date <', $today)
            ->orderBy('e.probation_end_date', 'ASC');
        if ($companyId) $b->where('e.company_id', $companyId);

        return $b->get()->getResultArray();
    }

    /**
     * Auto-pass probation untuk karyawan yang sudah lewat tanpa keputusan.
     * Dipanggil dari cron `hr:check-probation`. Idempotent — skip kalau status
     * sudah berubah di antara fetch & update.
     *
     * @return int Jumlah karyawan yang di-auto-pass
     */
    public function autoPassExpired(?int $companyId = null): int
    {
        $expired = $this->findExpiredWithoutDecision($companyId);
        $passed = 0;
        foreach ($expired as $e) {
            try {
                $this->decide(
                    (int) $e['id'],
                    'PASSED',
                    0, // user_id 0 = system
                    'AUTO-PASS oleh sistem: probation_end_date ('
                    . $e['probation_end_date'] . ') sudah lewat tanpa keputusan HR. '
                    . 'Sesuai UU 13/2003 Pasal 60, karyawan otomatis jadi tetap.',
                );
                $passed++;
            } catch (\Throwable $ex) {
                log_message('warning', 'Auto-pass probation failed for #' . $e['id'] . ': ' . $ex->getMessage());
            }
        }
        return $passed;
    }

    /**
     * Auto-create severance_calculations DRAFT untuk karyawan probation FAIL.
     * Reason code: PROBATION_FAIL — sesuai PP 35/2021 Pasal 41-42, kompensasi
     * meliputi UPMK (kalau masa kerja >= 3 tahun, jarang untuk probation)
     * dan UPH (sisa cuti, ongkos pulang). Pesangon penuh tidak berlaku.
     *
     * Best-effort: skip silent kalau:
     *   - Tabel severance_calculations belum ada (migration belum jalan)
     *   - SeveranceCalculator tidak punya reason PROBATION_FAIL → fallback
     *     ke FAILED_PROBATION atau MUTUAL (yang biasanya valid)
     *   - Sudah ada severance untuk employee+termination_date yang sama
     *     (idempotent)
     */
    private function autoCreateProbationSeverance(
        \CodeIgniter\Database\BaseConnection $db,
        array $emp,
        int $userId,
        string $note,
    ): ?int {
        if (! $db->tableExists('severance_calculations')) return null;

        $employeeId = (int) $emp['id'];
        $today = date('Y-m-d');

        // Idempotent — kalau sudah ada severance untuk employee ini di
        // termination_date hari ini, skip.
        $exists = $db->table('severance_calculations')
            ->where('employee_id', $employeeId)
            ->where('termination_date', $today)
            ->whereIn('status', ['DRAFT', 'FINALIZED', 'PAID'])
            ->countAllResults();
        if ($exists > 0) return null;

        // Resolve reason code yang didukung SeveranceCalculator
        $reasonCode = null;
        $candidates = ['PROBATION_FAIL', 'FAILED_PROBATION', 'MUTUAL', 'EFFICIENCY_PREVENT'];
        foreach ($candidates as $cand) {
            if (isset(\App\Libraries\SeveranceCalculator::REASONS[$cand])) {
                $reasonCode = $cand;
                break;
            }
        }
        if (! $reasonCode) {
            log_message('warning', 'No supported severance reason for probation fail — skipping auto-create');
            return null;
        }

        $calc = Services::severance();
        $serviceYears = method_exists($calc, 'computeServiceYears')
            ? $calc->computeServiceYears($emp['join_date'] ?? $today, $today)
            : 0.25; // typical probation < 3 bulan

        try {
            $preview = $calc->calculate([
                'reason_code'  => $reasonCode,
                'service_years'=> (float) $serviceYears,
                'basic_salary' => (float) ($emp['basic_salary'] ?? 0),
                'fixed_allowance' => 0,
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Severance calc failed for probation fail: ' . $e->getMessage());
            $preview = ['total_amount' => 0, 'breakdown' => []];
        }

        $now = date('Y-m-d H:i:s');
        $cols = $db->getFieldNames('severance_calculations');

        // Build insert dengan field yang ada saja (skema bisa beda antar version).
        $insertSuper = [
            'company_id'        => (int) $emp['company_id'],
            'employee_id'       => $employeeId,
            'termination_date'  => $today,
            'reason_code'       => $reasonCode,
            'service_years'     => $serviceYears,
            'basic_salary'      => (float) ($emp['basic_salary'] ?? 0),
            'fixed_allowance'   => 0,
            'severance_amount'  => (float) ($preview['severance_amount'] ?? 0),
            'service_award'     => (float) ($preview['service_award'] ?? 0),
            'compensation'      => (float) ($preview['compensation'] ?? 0),
            'separation_pay'    => (float) ($preview['separation_pay'] ?? 0),
            'total_amount'      => (float) ($preview['total_amount'] ?? 0),
            'breakdown'         => json_encode($preview['breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
            'notes'             => 'Auto-created dari probation FAIL. Note HR: '
                . mb_substr($note, 0, 500),
            'status'            => 'DRAFT',
            'created_by'        => $userId ?: null,
            'updated_by'        => $userId ?: null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $insert = array_intersect_key($insertSuper, array_flip($cols));
        if (empty($insert)) return null;

        try {
            $db->table('severance_calculations')->insert($insert);
            $id = (int) $db->insertID();
            try {
                Services::audit()->forModule('HR')->log(
                    action: 'severance.auto_create_probation',
                    entity: 'severance_calculations',
                    entityId: $id,
                    description: "Severance DRAFT auto-create dari probation FAIL untuk {$emp['full_name']}. "
                        . "Total: " . number_format((float) ($preview['total_amount'] ?? 0), 0, ',', '.')
                        . ". HR perlu review & finalize.",
                    severity: 'CRITICAL',
                );
            } catch (\Throwable $e) {}
            return $id;
        } catch (\Throwable $e) {
            log_message('warning', 'Severance auto-insert for probation fail failed: ' . $e->getMessage());
            return null;
        }
    }
}
