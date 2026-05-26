<?php

namespace App\Libraries;

use Config\Database;
use Config\Services;

/**
 * ManpowerService — domain logic untuk Manpower Planning (MPP) request.
 *
 * Tanggung jawab:
 *  1. Generate `reference_number` MPP unik (`MPP-<YEAR>-<5digit>`).
 *  2. Submit pengajuan: DRAFT → SUBMITTED, generate approval steps
 *     berdasarkan request_type (BUDGET / UNBUDGET).
 *  3. Approve / Reject langkah approval, auto-progress status MPP
 *     bila semua langkah PASSED.
 *  4. Convert MPP APPROVED → `job_postings` (carry-over field judul,
 *     gaji, deskripsi, dst) dan link via pivot `manpower_request_postings`.
 *  5. Sync hired_count saat candidate HIRED + converted_employee_id
 *     terisi; auto-set status FILLED bila headcount terpenuhi.
 *
 * Approval workflow default:
 *   - BUDGET   → 1 step: HRD Manager (atau user dengan permission `mpp.approve`)
 *   - UNBUDGET → 2 step: HRD Manager → Director
 *
 * Bisa di-override per company via `settings` table key `mpp.approval_steps`
 * (future). Untuk MVP pakai default ini supaya alur jelas.
 */
class ManpowerService
{
    public const STATUSES = [
        'DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED',
        'HIRING', 'FILLED', 'CLOSED', 'CANCELLED',
    ];

    public const TYPES = ['BUDGET', 'UNBUDGET'];

    public const REASONS = ['NEW_POSITION', 'REPLACEMENT', 'EXPANSION', 'SEASONAL', 'OTHER'];

    /**
     * Approval steps default per request_type.
     * Setiap step punya: step number, approver_role hint.
     */
    public const DEFAULT_APPROVAL_STEPS = [
        'BUDGET' => [
            ['step' => 1, 'approver_role' => 'HRD_MANAGER'],
        ],
        'UNBUDGET' => [
            ['step' => 1, 'approver_role' => 'HRD_MANAGER'],
            ['step' => 2, 'approver_role' => 'DIRECTOR'],
        ],
    ];

    public function nextReferenceNumber(int $companyId): string
    {
        $db = Database::connect();
        $year = date('Y');
        $prefix = "MPP-{$year}-";
        $count = $db->table('manpower_requests')
            ->where('company_id', $companyId)
            ->like('reference_number', $prefix, 'after')
            ->countAllResults();
        $tries = 0;
        while ($tries < 50) {
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
            $exists = $db->table('manpower_requests')
                ->where('company_id', $companyId)
                ->where('reference_number', $candidate)->countAllResults();
            if ($exists === 0) return $candidate;
            $count++; $tries++;
        }
        return $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Submit MPP: DRAFT → SUBMITTED, generate approval steps.
     *
     * Throws bila MPP bukan DRAFT, atau bila UNBUDGET tanpa justifikasi.
     */
    public function submit(int $mppId, int $userId): void
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if ($mpp['status'] !== 'DRAFT') {
            throw new \RuntimeException("MPP harus dalam status DRAFT untuk di-submit, sekarang {$mpp['status']}");
        }
        if ($mpp['request_type'] === 'UNBUDGET' && empty(trim((string) ($mpp['justification'] ?? '')))) {
            throw new \RuntimeException('Justifikasi wajib diisi untuk pengajuan UNBUDGET');
        }
        if ((int) $mpp['headcount'] < 1) {
            throw new \RuntimeException('Headcount harus minimal 1');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('manpower_requests')->where('id', $mppId)->update([
            'status'       => 'SUBMITTED',
            'submitted_at' => $now,
            'updated_by'   => $userId,
            'updated_at'   => $now,
        ]);

        // Generate approval steps. Hapus dulu step lama (kalau ada — kasus
        // re-submit dari DRAFT setelah cancelled/rejected, tapi MVP belum
        // dukung; ini just-in-case).
        $db->table('manpower_request_approvals')->where('manpower_request_id', $mppId)->delete();

        // Resolve expected approver dari organization_structures by role_key.
        // Best-effort: kalau role belum di-assign / vacant, kolom expected_*
        // null dan caller harus fallback ke permission-based routing.
        $orgSvc = Services::orgStructure();
        $companyId = (int) $mpp['company_id'];
        $hasExpectedCols = in_array('expected_approver_user_id',
            $db->getFieldNames('manpower_request_approvals'), true);

        $steps = self::DEFAULT_APPROVAL_STEPS[$mpp['request_type']] ?? self::DEFAULT_APPROVAL_STEPS['BUDGET'];
        foreach ($steps as $s) {
            $roleKey = $s['approver_role'] ?? null;
            $expectedUserId = null;
            $expectedEmpId = null;
            if ($roleKey && $hasExpectedCols) {
                $expectedUserId = $orgSvc->resolveApproverUser($companyId, $roleKey);
                $expectedEmpId  = $orgSvc->resolveApproverEmployee($companyId, $roleKey);
            }
            $insert = [
                'company_id'          => $companyId,
                'manpower_request_id' => $mppId,
                'step'                => (int) $s['step'],
                'approver_role'       => $roleKey,
                'decision'            => 'PENDING',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
            if ($hasExpectedCols) {
                $insert['expected_approver_user_id']     = $expectedUserId;
                $insert['expected_approver_employee_id'] = $expectedEmpId;
            }
            $db->table('manpower_request_approvals')->insert($insert);
        }
    }

    /**
     * Approve langkah approval saat ini (step PENDING terkecil).
     * Bila ini step terakhir, MPP otomatis pindah ke APPROVED.
     */
    public function approveStep(int $mppId, int $userId, ?string $comment = null): array
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if ($mpp['status'] !== 'SUBMITTED') {
            throw new \RuntimeException("MPP harus dalam status SUBMITTED untuk di-approve, sekarang {$mpp['status']}");
        }

        // Ambil step PENDING dengan urutan terkecil
        $pending = $db->table('manpower_request_approvals')
            ->where('manpower_request_id', $mppId)
            ->where('decision', 'PENDING')
            ->orderBy('step', 'ASC')
            ->limit(1)->get()->getRowArray();
        if (! $pending) {
            throw new \RuntimeException('Tidak ada langkah approval yang menunggu');
        }

        // Sprint 3 (CC-3) — Approval Delegation Wiring.
        // Kalau step ini punya `expected_approver_user_id`, validate caller-nya
        // adalah orang itu ATAU delegate aktif-nya. Tanpa expected (legacy
        // schema atau user-based permission), trust caller punya permission.
        $expected = isset($pending['expected_approver_user_id'])
            ? (int) $pending['expected_approver_user_id']
            : null;
        if ($expected) {
            $resolver = Services::approvalDelegationResolver();
            if (! $resolver->canApproveOnBehalfOf($userId, $expected, (int) $mpp['company_id'], 'MPP')) {
                throw new \RuntimeException(
                    "Hanya {$pending['approver_role']} (user #{$expected}) atau delegate aktif-nya "
                    . "yang berhak approve step ini."
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        // Catat delegated_from kalau caller adalah delegate, bukan original
        $isDelegate = $expected && $expected !== $userId;
        $db->table('manpower_request_approvals')->where('id', (int) $pending['id'])->update([
            'decision'         => 'APPROVED',
            'decided_at'       => $now,
            'approver_user_id' => $userId,
            'comment'          => $isDelegate
                ? '[DELEGATED dari user #' . $expected . '] ' . ($comment ?? '')
                : $comment,
            'updated_at'       => $now,
        ]);

        // Cek apakah masih ada step PENDING lagi
        $remaining = $db->table('manpower_request_approvals')
            ->where('manpower_request_id', $mppId)
            ->where('decision', 'PENDING')
            ->countAllResults();

        $finalApproved = false;
        if ($remaining === 0) {
            // Semua step approved → MPP APPROVED
            $db->table('manpower_requests')->where('id', $mppId)->update([
                'status'              => 'APPROVED',
                'approved_at'         => $now,
                'approved_by_user_id' => $userId,
                'updated_by'          => $userId,
                'updated_at'          => $now,
            ]);
            $finalApproved = true;
        }

        return [
            'step'           => (int) $pending['step'],
            'final_approved' => $finalApproved,
            'remaining_steps'=> $remaining,
        ];
    }

    /**
     * Reject MPP di langkah saat ini. Status → REJECTED, semua step
     * sisa di-mark SKIPPED.
     */
    public function rejectStep(int $mppId, int $userId, string $reason): void
    {
        if (strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Alasan reject minimal 5 karakter');
        }
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if ($mpp['status'] !== 'SUBMITTED') {
            throw new \RuntimeException("MPP harus dalam status SUBMITTED untuk di-reject, sekarang {$mpp['status']}");
        }

        $pending = $db->table('manpower_request_approvals')
            ->where('manpower_request_id', $mppId)
            ->where('decision', 'PENDING')
            ->orderBy('step', 'ASC')
            ->limit(1)->get()->getRowArray();
        if (! $pending) {
            throw new \RuntimeException('Tidak ada langkah approval yang menunggu');
        }

        $now = date('Y-m-d H:i:s');
        // Mark current step REJECTED
        $db->table('manpower_request_approvals')->where('id', (int) $pending['id'])->update([
            'decision'         => 'REJECTED',
            'decided_at'       => $now,
            'approver_user_id' => $userId,
            'comment'          => $reason,
            'updated_at'       => $now,
        ]);
        // Skip semua step PENDING setelahnya
        $db->table('manpower_request_approvals')
            ->where('manpower_request_id', $mppId)
            ->where('decision', 'PENDING')
            ->update([
                'decision'   => 'SKIPPED',
                'updated_at' => $now,
            ]);

        $db->table('manpower_requests')->where('id', $mppId)->update([
            'status'           => 'REJECTED',
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_by'       => $userId,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Cancel MPP oleh requester (biasanya saat masih DRAFT atau SUBMITTED
     * sebelum decision pertama). Setelah APPROVED tidak boleh cancel
     * karena sudah ada job_postings yang berpotensi terkait.
     */
    public function cancel(int $mppId, int $userId, ?string $reason = null): void
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if (! in_array($mpp['status'], ['DRAFT', 'SUBMITTED'], true)) {
            throw new \RuntimeException("Hanya MPP DRAFT/SUBMITTED yang bisa di-cancel, sekarang {$mpp['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $db->table('manpower_requests')->where('id', $mppId)->update([
            'status'         => 'CANCELLED',
            'rejection_reason' => $reason,
            'updated_by'     => $userId,
            'updated_at'     => $now,
        ]);
        // Skip semua approval step PENDING
        $db->table('manpower_request_approvals')
            ->where('manpower_request_id', $mppId)
            ->where('decision', 'PENDING')
            ->update(['decision' => 'SKIPPED', 'updated_at' => $now]);
    }

    /**
     * Convert MPP APPROVED ke `job_postings`.
     *
     * Membuat satu posting (default) atau lebih bila $overrides['headcount']
     * lebih kecil dari sisa headcount MPP — caller bisa panggil method ini
     * berkali-kali untuk split per cabang/lokasi.
     *
     * Status MPP berubah:
     *   APPROVED → HIRING (saat job_posting pertama dibuat)
     *
     * @return array{job_posting_id: int, reference_number: string}
     */
    public function createJobPostingFromMpp(int $mppId, int $userId, array $overrides = []): array
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if (! in_array($mpp['status'], ['APPROVED', 'HIRING'], true)) {
            throw new \RuntimeException("MPP harus APPROVED/HIRING untuk dibuatkan lowongan, sekarang {$mpp['status']}");
        }

        // Hitung sisa headcount: total MPP - sum(headcount lowongan terkait)
        $existingPostings = $db->table('manpower_request_postings mrp')
            ->select('jp.headcount')
            ->join('job_postings jp', 'jp.id = mrp.job_posting_id')
            ->where('mrp.manpower_request_id', $mppId)
            ->where('jp.deleted_at IS NULL')
            ->get()->getResultArray();
        $usedHeadcount = array_sum(array_map(fn($r) => (int) $r['headcount'], $existingPostings));
        $remaining = max(0, (int) $mpp['headcount'] - $usedHeadcount);
        if ($remaining <= 0) {
            throw new \RuntimeException('Semua headcount MPP sudah dialokasikan ke lowongan');
        }
        $postingHeadcount = (int) ($overrides['headcount'] ?? $remaining);
        if ($postingHeadcount > $remaining) {
            throw new \RuntimeException("Headcount lowongan ({$postingHeadcount}) melebihi sisa MPP ({$remaining})");
        }
        if ($postingHeadcount < 1) {
            throw new \RuntimeException('Headcount lowongan harus minimal 1');
        }

        $recruitment = Services::recruitment();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => (int) $mpp['company_id'],
            'reference_number'=> $recruitment->nextJobReferenceNumber((int) $mpp['company_id']),
            'title'           => $overrides['title'] ?? $mpp['proposed_title'],
            'department_id'   => $overrides['department_id'] ?? $mpp['department_id'],
            'position_id'     => $overrides['position_id']   ?? $mpp['position_id'],
            'branch_id'       => $overrides['branch_id']     ?? $mpp['branch_id'],
            'employment_type' => $overrides['employment_type'] ?? $mpp['employment_type'] ?? 'PKWTT',
            'description'     => $overrides['description'] ?? $mpp['job_description'],
            'requirements'    => $overrides['requirements'] ?? $mpp['requirements'],
            'salary_min'      => isset($overrides['salary_min']) ? (float) $overrides['salary_min'] : ($mpp['salary_min'] !== null ? (float) $mpp['salary_min'] : null),
            'salary_max'      => isset($overrides['salary_max']) ? (float) $overrides['salary_max'] : ($mpp['salary_max'] !== null ? (float) $mpp['salary_max'] : null),
            'salary_visible'  => (int) (bool) ($overrides['salary_visible'] ?? 0),
            'headcount'       => $postingHeadcount,
            'opened_at'       => $overrides['opened_at'] ?? null,
            'closed_at'       => $overrides['closed_at'] ?? null,
            'status'          => 'DRAFT',
            'hiring_manager_id'=> $overrides['hiring_manager_id'] ?? (int) $mpp['requested_by_user_id'],
            'notes'           => $overrides['notes'] ?? "Dibuat dari MPP {$mpp['reference_number']}",
            'created_by'      => $userId,
            'updated_by'      => $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('job_postings')->insert($insert);
        $postingId = (int) $db->insertID();

        // Pivot
        $db->table('manpower_request_postings')->insert([
            'company_id'          => (int) $mpp['company_id'],
            'manpower_request_id' => $mppId,
            'job_posting_id'      => $postingId,
            'created_by'          => $userId,
            'created_at'          => $now,
        ]);

        // Status MPP: APPROVED → HIRING
        if ($mpp['status'] === 'APPROVED') {
            $db->table('manpower_requests')->where('id', $mppId)->update([
                'status'     => 'HIRING',
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);
        }

        // Sprint 5 — Onboarding planning: copy job_grade, default supervisor/
        // manager, dan planned assets dari MPP ke job_postings + job_posting_assets.
        // Best-effort: kalau service belum tersedia atau migration belum jalan,
        // log warning tapi jangan break flow create-posting yang sudah sukses.
        try {
            Services::onboardingPlan()->syncFromMpp($mppId, $postingId, $userId);
        } catch (\Throwable $e) {
            log_message('warning', "MPP planning sync to posting #{$postingId} failed: " . $e->getMessage());
        }

        return [
            'job_posting_id'   => $postingId,
            'reference_number' => $insert['reference_number'],
        ];
    }

    /**
     * Recompute hired_count untuk satu MPP berdasarkan candidates yang
     * sudah HIRED + converted_employee_id terisi di posting yang
     * tertaut MPP ini. Dipanggil setelah hire candidate berhasil.
     *
     * Auto-set status FILLED bila hired_count >= headcount MPP.
     */
    public function syncHiredCount(int $mppId): array
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');

        // Hitung candidates HIRED + converted di semua posting tertaut
        $hired = $db->table('candidates c')
            ->join('manpower_request_postings mrp', 'mrp.job_posting_id = c.job_posting_id')
            ->where('mrp.manpower_request_id', $mppId)
            ->where('c.current_stage', 'HIRED')
            ->where('c.converted_employee_id IS NOT NULL')
            ->where('c.deleted_at IS NULL')
            ->countAllResults();

        $now = date('Y-m-d H:i:s');
        $update = ['hired_count' => $hired, 'updated_at' => $now];

        // Auto progress status
        $newStatus = $mpp['status'];
        if ($mpp['status'] === 'HIRING' && $hired >= (int) $mpp['headcount']) {
            $newStatus = 'FILLED';
            $update['status'] = 'FILLED';
        }

        $db->table('manpower_requests')->where('id', $mppId)->update($update);

        return [
            'hired_count' => $hired,
            'headcount'   => (int) $mpp['headcount'],
            'status'      => $newStatus,
        ];
    }

    /**
     * Recompute hired_count untuk semua MPP yang tertaut ke posting
     * tertentu. Dipanggil dari hook setelah hire candidate.
     */
    public function syncForJobPosting(int $jobPostingId): void
    {
        $db = Database::connect();
        $rows = $db->table('manpower_request_postings')
            ->where('job_posting_id', $jobPostingId)
            ->get()->getResultArray();
        foreach ($rows as $r) {
            try {
                $this->syncHiredCount((int) $r['manpower_request_id']);
            } catch (\Throwable $e) {
                log_message('warning', "MPP sync failed for #{$r['manpower_request_id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Close MPP secara manual (HRD/Requester saat headcount sudah cukup
     * atau diputuskan tidak dilanjutkan).
     */
    public function close(int $mppId, int $userId, ?string $reason = null): void
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if (! in_array($mpp['status'], ['HIRING', 'FILLED'], true)) {
            throw new \RuntimeException("Hanya MPP HIRING/FILLED yang bisa di-close, sekarang {$mpp['status']}");
        }
        $now = date('Y-m-d H:i:s');
        $db->table('manpower_requests')->where('id', $mppId)->update([
            'status'     => 'CLOSED',
            'closed_at'  => $now,
            'notes'      => $reason ? trim(($mpp['notes'] ?? '') . "\n[CLOSE] " . $reason) : $mpp['notes'],
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);
    }

    /**
     * Auto-create MPP DRAFT untuk replacement saat employee resign approved.
     *
     * Dipanggil dari `ResignationController::approve()` di tahap HR approve.
     * MPP dibuat dalam state DRAFT supaya HRD bisa review (mis. pertimbangkan
     * reorganisasi atau mengisi via internal mutasi sebelum buka rekrutmen)
     * lalu submit manual via tombol di MppList.
     *
     * @param int    $companyId    Company ID resigner
     * @param int    $employeeId   Employee yang resign (akan jadi replacement_for_employee_id)
     * @param int    $userId       User yang trigger (HR approver)
     * @param string $effectiveDate Tanggal efektif resign (target_start_date pengganti)
     * @param array  $emp          Row employee resigner (untuk carry-over dept/posisi/branch)
     * @return int|null            ID MPP yang dibuat, atau null bila skip
     */
    public function autoCreateForResignation(
        int $companyId, int $employeeId, int $userId, string $effectiveDate, array $emp
    ): ?int {
        $db = Database::connect();

        // Skip kalau sudah pernah dibuat MPP REPLACEMENT untuk employee ini
        // yang masih aktif. Hindari duplikasi kalau approval di-trigger ulang.
        $existing = $db->table('manpower_requests')
            ->where('company_id', $companyId)
            ->where('replacement_for_employee_id', $employeeId)
            ->whereNotIn('status', ['REJECTED', 'CANCELLED', 'CLOSED'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($existing > 0) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $title = $this->buildReplacementTitle($emp);
        $insert = [
            'company_id'                  => $companyId,
            'reference_number'            => $this->nextReferenceNumber($companyId),
            'requested_by_user_id'        => $userId,
            'requested_by_employee_id'    => $emp['id'] ?? null,
            'department_id'               => $emp['department_id'] ?? null,
            'position_id'                 => $emp['position_id'] ?? null,
            'branch_id'                   => $emp['branch_id'] ?? null,
            'request_type'                => 'BUDGET',
            'reason'                      => 'REPLACEMENT',
            'replacement_for_employee_id' => $employeeId,
            'proposed_title'              => $title,
            'headcount'                   => 1,
            'employment_type'             => $emp['employment_status'] ?? 'PKWTT',
            'salary_min'                  => null,
            'salary_max'                  => null,
            'target_start_date'           => $effectiveDate,
            'justification'               => 'Pengganti karyawan yang resign per ' . $effectiveDate
                . ' (' . ($emp['full_name'] ?? '#' . $employeeId) . ').',
            'job_description'             => null,
            'requirements'                => null,
            'notes'                       => 'Auto-generated dari approval resignation. '
                . 'Review & submit untuk melanjutkan ke approval HRD.',
            'status'                      => 'DRAFT',
            'created_by'                  => $userId,
            'updated_by'                  => $userId,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ];
        $db->table('manpower_requests')->insert($insert);
        return (int) $db->insertID();
    }

    /**
     * Bangun judul MPP replacement dari profile resigner.
     * Prioritas: position name → "Pengganti {full_name}".
     */
    private function buildReplacementTitle(array $emp): string
    {
        $db = Database::connect();
        if (! empty($emp['position_id'])) {
            $pos = $db->table('positions')->where('id', (int) $emp['position_id'])->get()->getRowArray();
            if ($pos && ! empty($pos['name'])) {
                return $pos['name'];
            }
        }
        return 'Pengganti ' . ($emp['full_name'] ?? '#' . ($emp['id'] ?? 'unknown'));
    }

    /**
     * List MPP yang sedang aktif (belum FILLED/CLOSED/REJECTED/CANCELLED) di
     * sebuah department. Dipakai oleh OrgStructure untuk menampilkan slot
     * kosong yang sedang dicari.
     *
     * @param int $companyId
     * @param int $departmentId
     * @return array<int, array>
     */
    public function activeRequestsForDepartment(int $companyId, int $departmentId): array
    {
        $db = Database::connect();
        return $db->table('manpower_requests')
            ->select('id, reference_number, proposed_title, request_type, reason, headcount, hired_count, status, target_start_date, position_id')
            ->where('company_id', $companyId)
            ->where('department_id', $departmentId)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'HIRING'])
            ->where('deleted_at IS NULL')
            // Sort priority HIRING → APPROVED → SUBMITTED → DRAFT, portable
            // (CASE WHEN works on MySQL & SQLite, FIELD() is MySQL-only).
            ->orderBy(
                "CASE status WHEN 'HIRING' THEN 1 WHEN 'APPROVED' THEN 2 "
                . "WHEN 'SUBMITTED' THEN 3 WHEN 'DRAFT' THEN 4 ELSE 5 END",
                '', false
            )
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Pindahkan MPP ke department lain. Dipanggil dari OrgStructure
     * drag-and-drop. Hanya boleh di state yang belum punya posting (DRAFT,
     * SUBMITTED, APPROVED) supaya tidak inkonsisten dengan job_postings
     * yang sudah ditarik dari MPP ini.
     */
    public function moveToDepartment(int $mppId, ?int $newDepartmentId, int $userId): array
    {
        $db = Database::connect();
        $mpp = $db->table('manpower_requests')->where('id', $mppId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $mpp) throw new \InvalidArgumentException('MPP not found');
        if (! in_array($mpp['status'], ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
            throw new \RuntimeException("MPP tidak bisa dipindah di status {$mpp['status']}, hanya DRAFT/SUBMITTED/APPROVED");
        }
        if ($newDepartmentId !== null) {
            $dept = $db->table('departments')->where('id', $newDepartmentId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $dept) throw new \InvalidArgumentException('Department tujuan tidak ditemukan');
            if ((int) $dept['company_id'] !== (int) $mpp['company_id']) {
                throw new \RuntimeException('Department tujuan harus dalam perusahaan yang sama');
            }
        }
        $oldDept = $mpp['department_id'] !== null ? (int) $mpp['department_id'] : null;
        if ($oldDept === $newDepartmentId) {
            return ['unchanged' => true, 'old' => $oldDept, 'new' => $newDepartmentId];
        }
        $db->table('manpower_requests')->where('id', $mppId)->update([
            'department_id' => $newDepartmentId,
            'updated_by'    => $userId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        return ['unchanged' => false, 'old' => $oldDept, 'new' => $newDepartmentId];
    }

    /**
     * Aggregate stats untuk dashboard MPP.
     */
    public function stats(int $companyId): array
    {
        $db = Database::connect();
        $rows = $db->table('manpower_requests')
            ->select('status, COUNT(*) AS total, SUM(headcount) AS total_hc, SUM(hired_count) AS total_hired')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->groupBy('status')->get()->getResultArray();
        $stats = array_fill_keys(self::STATUSES, ['count' => 0, 'headcount' => 0, 'hired' => 0]);
        foreach ($rows as $r) {
            $stats[$r['status']] = [
                'count'     => (int) $r['total'],
                'headcount' => (int) $r['total_hc'],
                'hired'     => (int) $r['total_hired'],
            ];
        }
        return $stats;
    }
}