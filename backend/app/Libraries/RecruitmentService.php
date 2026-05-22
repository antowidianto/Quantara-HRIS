<?php

namespace App\Libraries;

use Config\Services;

/**
 * RecruitmentService
 *
 * Logika domain Recruitment / ATS.
 *
 * Tanggung jawab:
 *  1. Generate `reference_number` posting unik (`JOB-<YEAR>-<5digit>`).
 *  2. Move candidate antar stage (track history di `candidate_stages`).
 *  3. Convert candidate HIRED ke `employees` row (clone profile dgn
 *     auto-generate employee_number, link `converted_employee_id`),
 *     trigger ONBOARD_DEFAULT via `OnboardingService`.
 *  4. Validasi: hanya HIRED candidate bisa di-convert; tidak boleh
 *     double-convert.
 */
class RecruitmentService
{
    public const STAGES = [
        'NEW',
        'SCREENING',
        'INTERVIEW_HR',
        'INTERVIEW_USER',
        'OFFER',
        'HIRED',
        'REJECTED',
        'WITHDRAWN',
    ];

    public const TERMINAL_STAGES = ['HIRED', 'REJECTED', 'WITHDRAWN'];

    public function nextJobReferenceNumber(int $companyId): string
    {
        $db = \Config\Database::connect();
        $year = date('Y');
        $prefix = "JOB-{$year}-";
        $count = $db->table('job_postings')
            ->where('company_id', $companyId)
            ->like('reference_number', $prefix, 'after')
            ->countAllResults();
        $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
        $candidate = $prefix . $seq;
        $tries = 0;
        while ($tries < 50) {
            $exists = $db->table('job_postings')
                ->where('company_id', $companyId)
                ->where('reference_number', $candidate)->countAllResults();
            if ($exists === 0) return $candidate;
            $tries++; $count++;
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
        }
        return $candidate;
    }

    /**
     * Move candidate ke stage baru. Tutup stage lama (kalau masih PENDING),
     * buat row stage baru dengan PENDING, update candidate.current_stage.
     */
    public function moveStage(int $candidateId, string $newStage, array $params = []): int
    {
        $db = \Config\Database::connect();
        if (! in_array($newStage, self::STAGES, true)) {
            throw new \InvalidArgumentException("Stage tidak valid: {$newStage}");
        }
        $cand = $db->table('candidates')->where('id', $candidateId)->get()->getRowArray();
        if (! $cand) throw new \InvalidArgumentException("Candidate not found");

        if (in_array($cand['current_stage'], self::TERMINAL_STAGES, true)
            && ! in_array($newStage, self::TERMINAL_STAGES, true)) {
            throw new \RuntimeException(
                "Candidate sudah di stage final {$cand['current_stage']}, tidak bisa kembali ke {$newStage}"
            );
        }

        $now = date('Y-m-d H:i:s');

        // Tutup stage aktif lama (PENDING) — set hasil sesuai konteks
        $db->table('candidate_stages')
            ->where('candidate_id', $candidateId)
            ->where('result', 'PENDING')
            ->update([
                'result' => 'PASSED', // default — caller bisa override
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        // Insert stage baru
        $insert = [
            'company_id'    => (int) $cand['company_id'],
            'candidate_id'  => $candidateId,
            'stage'         => $newStage,
            'result'        => $newStage === 'HIRED' ? 'PASSED' : 'PENDING',
            'scheduled_at'  => $params['scheduled_at'] ?? null,
            'interviewer_id'=> $params['interviewer_id'] ?? null,
            'feedback'      => $params['feedback'] ?? null,
            'rating'        => $params['rating'] ?? null,
            'created_by'    => $params['created_by'] ?? null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        if ($newStage === 'HIRED') $insert['completed_at'] = $now;

        $db->table('candidate_stages')->insert($insert);
        $stageId = (int) $db->insertID();

        // Update current_stage di candidates
        $db->table('candidates')->where('id', $candidateId)->update([
            'current_stage' => $newStage,
            'updated_at'    => $now,
        ]);

        return $stageId;
    }

    /**
     * Reject candidate dengan alasan. Stage berubah ke REJECTED.
     */
    public function reject(int $candidateId, string $reason, ?int $userId = null): void
    {
        $db = \Config\Database::connect();
        $cand = $db->table('candidates')->where('id', $candidateId)->get()->getRowArray();
        if (! $cand) throw new \InvalidArgumentException("Candidate not found");
        if (in_array($cand['current_stage'], self::TERMINAL_STAGES, true)) {
            throw new \RuntimeException("Candidate sudah di stage final {$cand['current_stage']}");
        }

        $now = date('Y-m-d H:i:s');
        // Tutup stage aktif lama dengan FAILED
        $db->table('candidate_stages')
            ->where('candidate_id', $candidateId)
            ->where('result', 'PENDING')
            ->update([
                'result' => 'FAILED',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        $db->table('candidate_stages')->insert([
            'company_id'   => (int) $cand['company_id'],
            'candidate_id' => $candidateId,
            'stage'        => 'REJECTED',
            'result'       => 'FAILED',
            'completed_at' => $now,
            'feedback'     => $reason,
            'created_by'   => $userId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $db->table('candidates')->where('id', $candidateId)->update([
            'current_stage'    => 'REJECTED',
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Convert candidate HIRED ke employees row baru.
     *
     * @return array{employee_id: int, user_id: ?int, user_email: ?string, temp_password: ?string, auto_completed_tasks: int}
     *     employee_id baru, plus credential ONE-TIME (kalau auto-create
     *     user akun berhasil) yang harus diteruskan ke HR untuk
     *     dikomunikasikan ke karyawan baru.
     */
    public function convertToEmployee(int $candidateId, array $employeeOverrides = []): array
    {
        $db = \Config\Database::connect();
        $cand = $db->table('candidates')->where('id', $candidateId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $cand) throw new \InvalidArgumentException("Candidate not found");
        if ($cand['current_stage'] !== 'HIRED') {
            throw new \RuntimeException("Hanya kandidat dengan stage HIRED yang bisa di-convert. Stage saat ini: {$cand['current_stage']}");
        }
        if (! empty($cand['converted_employee_id'])) {
            throw new \RuntimeException("Candidate ini sudah di-convert ke employee #{$cand['converted_employee_id']}");
        }

        $job = $db->table('job_postings')->where('id', (int) $cand['job_posting_id'])->get()->getRowArray();
        if (! $job) throw new \RuntimeException("Job posting hilang");

        // Generate employee_number
        $empNumber = $this->nextEmployeeNumber((int) $cand['company_id']);

        // Split full_name → first_name + last_name (schema lama pakai dua kolom)
        $parts = preg_split('/\s+/', trim((string) $cand['full_name']), 2);
        $firstName = $parts[0] ?? $cand['full_name'];
        $lastName  = $parts[1] ?? '';

        $now = date('Y-m-d H:i:s');
        // Field dasar — selalu dipakai
        $insert = [
            'company_id'        => (int) $cand['company_id'],
            'employee_number'   => $employeeOverrides['employee_number'] ?? $empNumber,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'full_name'         => $cand['full_name'],
            'email'             => $cand['email'] ?? null,
            'phone'             => $cand['phone'] ?? null,
            'date_of_birth'     => $cand['birth_date'] ?? null,
            'gender'            => $cand['gender'] ?? null,
            'address'           => $cand['address'] ?? null,
            'department_id'     => $employeeOverrides['department_id'] ?? $job['department_id'] ?? null,
            'position_id'       => $employeeOverrides['position_id'] ?? $job['position_id'] ?? null,
            'branch_id'         => $employeeOverrides['branch_id'] ?? $job['branch_id'] ?? null,
            'employment_status' => $employeeOverrides['employment_status'] ?? $job['employment_type'] ?? 'PKWTT',
            'join_date'         => $employeeOverrides['join_date'] ?? ($cand['available_from'] ?? date('Y-m-d')),
            'basic_salary'      => $employeeOverrides['basic_salary'] ?? ($cand['expected_salary'] ?? 0),
            'is_active'         => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        // Carry-over field standar HR Indonesia kalau tabel `employees`
        // sudah punya kolomnya — pakai array_intersect_key supaya aman
        // di environment yang belum migrate field-field tersebut.
        $employeeCols = $db->getFieldNames('employees');
        // Map candidate.marital_status (BELUM_KAWIN/KAWIN/...) → PTKP code
        // (TK0/K0/...) yang dipakai tabel employees. Default 0 tanggungan;
        // HR bisa edit di profil employee setelah hire.
        $maritalToPtkp = [
            'BELUM_KAWIN' => 'TK0',
            'KAWIN'       => 'K0',
            'CERAI_HIDUP' => 'TK0',
            'CERAI_MATI'  => 'TK0',
        ];
        $candMarital = (string) ($cand['marital_status'] ?? '');
        $ptkp = $maritalToPtkp[$candMarital] ?? null;

        $idCarryover = [
            'birth_place'    => $cand['birth_place']    ?? null,
            'place_of_birth' => $cand['birth_place']    ?? null,
            'religion'       => $cand['religion']       ?? null,
            'marital_status' => $ptkp,
            'nationality'    => $cand['nationality']    ?? null,
            'nik'            => $cand['nik']            ?? null,
            'ktp_number'     => $cand['nik']            ?? null,
            'npwp'           => $cand['npwp']           ?? null,
            'kk_number'      => $cand['kk_number']      ?? null,
            'bpjs_kesehatan_no' => $cand['bpjs_kesehatan_no'] ?? null,
            'bpjs_tk_no'     => $cand['bpjs_tk_no']     ?? null,
            'city'           => $cand['city']           ?? null,
            'province'       => $cand['province']       ?? null,
            'last_education_level'       => $cand['last_education_level']       ?? null,
            'last_education_institution' => $cand['last_education_institution'] ?? null,
            'last_education_major'       => $cand['last_education_major']       ?? null,
            'last_education_year'        => $cand['last_education_year']        ?? null,
            'gpa'            => $cand['gpa']            ?? null,
        ];
        foreach ($idCarryover as $col => $val) {
            if ($val !== null && in_array($col, $employeeCols, true) && ! isset($insert[$col])) {
                $insert[$col] = $val;
            }
        }

        $db->table('employees')->insert($insert);
        $employeeId = (int) $db->insertID();

        // Copy candidate_documents → employee_documents kalau tabel
        // employee_documents ada (tidak semua schema include).
        if ($db->tableExists('employee_documents')) {
            $this->copyCandidateDocsToEmployee($candidateId, $employeeId, (int) $cand['company_id']);
        }

        // Update candidate
        $db->table('candidates')->where('id', $candidateId)->update([
            'converted_employee_id' => $employeeId,
            'converted_at'          => $now,
            'updated_at'            => $now,
        ]);

        // Trigger ONBOARD_DEFAULT + auto-complete tasks yang datanya sudah
        // lengkap dari Career Portal application (SUBMIT_DOCS, NPWP_VERIFY,
        // BPJS_REGISTER, SETUP_HRIS dengan auto-create user account).
        $autoResult = ['auto_completed' => 0, 'user_created' => null, 'user_email' => null, 'temp_password' => null];
        try {
            $anchor = $insert['join_date'];
            $onboarding = Services::onboarding();
            $onboarding->applyTemplate($employeeId, null, 'ONBOARD', $anchor);
            // Pakai data candidate (bukan re-fetch) supaya tidak ada race
            // dengan update converted_employee_id di bawah.
            $autoResult = $onboarding->autoCompleteFromCandidate($employeeId, $cand);
        } catch (\Throwable $e) {
            log_message('warning', "Onboarding apply failed for employee #{$employeeId}: " . $e->getMessage());
        }

        // Update job_postings status kalau headcount terpenuhi
        $hiredCount = $db->table('candidates')
            ->where('job_posting_id', (int) $cand['job_posting_id'])
            ->where('current_stage', 'HIRED')
            ->where('converted_employee_id IS NOT NULL')
            ->countAllResults();
        if ($hiredCount >= (int) $job['headcount'] && $job['status'] === 'OPEN') {
            $db->table('job_postings')->where('id', (int) $job['id'])->update([
                'status' => 'FILLED',
                'updated_at' => $now,
            ]);
        }

        return [
            'employee_id'          => $employeeId,
            'user_id'              => $autoResult['user_created'] ?? null,
            'user_email'           => $autoResult['user_email']   ?? null,
            'temp_password'        => $autoResult['temp_password'] ?? null,
            'auto_completed_tasks' => (int) ($autoResult['auto_completed'] ?? 0),
        ];
    }

    /**
     * Generate employee_number unik. Format `EMP-<YEAR>-<5digit>`.
     */
    public function nextEmployeeNumber(int $companyId): string
    {
        $db = \Config\Database::connect();
        $year = date('Y');
        $prefix = "EMP-{$year}-";
        $count = $db->table('employees')
            ->where('company_id', $companyId)
            ->like('employee_number', $prefix, 'after')
            ->countAllResults();
        $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
        $candidate = $prefix . $seq;
        $tries = 0;
        while ($tries < 50) {
            $exists = $db->table('employees')
                ->where('company_id', $companyId)
                ->where('employee_number', $candidate)->countAllResults();
            if ($exists === 0) return $candidate;
            $tries++; $count++;
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
        }
        return $candidate;
    }

    /**
     * Copy candidate_documents → employee_documents saat hire.
     * Best-effort: kalau schema employee_documents tidak match, log dan skip.
     */
    private function copyCandidateDocsToEmployee(int $candidateId, int $employeeId, int $companyId): void
    {
        $db = \Config\Database::connect();
        $docs = $db->table('candidate_documents')
            ->where('candidate_id', $candidateId)
            ->get()->getResultArray();
        if (empty($docs)) return;

        $empDocFields = $db->getFieldNames('employee_documents');
        $now = date('Y-m-d H:i:s');
        foreach ($docs as $d) {
            // Sediakan superset semua nama kolom yang mungkin ada di
            // berbagai schema employee_documents (legacy `doc_type` vs
            // baru `document_type`, `file_mime` vs `mime_type`).
            $insert = [
                'employee_id'   => $employeeId,
                'company_id'    => $companyId,
                'doc_type'      => $d['document_type'] ?? 'OTHER',
                'document_type' => $d['document_type'] ?? 'OTHER',
                'title'         => $d['file_name']     ?? 'Document',
                'file_name'     => $d['file_name']     ?? null,
                'file_path'     => $d['file_path']     ?? null,
                'file_mime'     => $d['mime_type']     ?? 'application/octet-stream',
                'mime_type'     => $d['mime_type']     ?? null,
                'file_size'     => (int) ($d['file_size'] ?? 0),
                'notes'         => $d['notes']         ?? 'Copied dari kandidat saat hire',
                'uploaded_by'   => $d['uploaded_by']   ?? null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            // Hanya kolom yang benar-benar ada di tabel target
            $insert = array_intersect_key($insert, array_flip($empDocFields));
            if (empty($insert['file_path'])) continue;
            try {
                $db->table('employee_documents')->insert($insert);
            } catch (\Throwable $e) {
                log_message('warning', "Copy candidate doc failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Stats untuk dashboard recruitment.
     */
    public function pipelineStats(int $companyId, ?int $jobPostingId = null): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('candidates')
            ->select('current_stage, COUNT(*) AS total')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if ($jobPostingId) $builder->where('job_posting_id', $jobPostingId);
        $rows = $builder->groupBy('current_stage')->get()->getResultArray();
        $stats = array_fill_keys(self::STAGES, 0);
        foreach ($rows as $r) {
            if (isset($stats[$r['current_stage']])) $stats[$r['current_stage']] = (int) $r['total'];
        }
        return $stats;
    }
}