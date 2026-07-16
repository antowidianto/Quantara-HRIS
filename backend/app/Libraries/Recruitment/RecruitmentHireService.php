<?php

declare(strict_types=1);

namespace App\Libraries\Recruitment;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class RecruitmentHireService
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
    }

    public function convertToEmployee(int $candidateId, int $companyId, array $employeeOverrides = []): array
    {
        if ($companyId <= 0) {
            throw new \DomainException('Active company context is required');
        }
        $db = $this->db;
        $db->transBegin();
        try {
            // Lock candidate supaya double-click/request paralel tidak dapat
            // membuat dua employee dari kandidat yang sama.
            $cand = $db->query(
                'SELECT * FROM candidates WHERE id = ? AND company_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$candidateId, $companyId],
            )->getRowArray();
            if (! $cand) throw new \InvalidArgumentException('Candidate not found');
            if (! empty($cand['converted_employee_id'])) {
                throw new \RuntimeException("Candidate ini sudah di-convert ke employee #{$cand['converted_employee_id']}");
            }

            $acceptedOffer = $db->table('candidate_offers')
                ->where('candidate_id', $candidateId)
                ->where('company_id', $companyId)
                ->where('status', 'ACCEPTED')
                ->where('deleted_at IS NULL')
                ->orderBy('responded_at', 'DESC')
                ->get()->getRowArray();
            if (! $acceptedOffer) {
                throw new \RuntimeException(
                    'Kandidat hanya dapat di-hire setelah offer diterima (ACCEPTED).'
                );
            }

        $job = $db->table('job_postings')
            ->where('id', (int) $cand['job_posting_id'])
            ->where('company_id', $companyId)
            ->get()->getRowArray();
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
            'employment_status' => $employeeOverrides['employment_status']
                ?? $acceptedOffer['employment_status'] ?? $job['employment_type'] ?? 'PKWTT',
            'join_date'         => $employeeOverrides['join_date']
                ?? $acceptedOffer['proposed_join_date'] ?? $cand['available_from'] ?? date('Y-m-d'),
            'basic_salary'      => $employeeOverrides['basic_salary']
                ?? $acceptedOffer['basic_salary'] ?? $cand['expected_salary'] ?? 0,
            'is_active'         => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        // ─── Payroll-essential overrides (Sprint 23+) ────────────────
        // Saat HR melakukan hire, form sekarang sekalian capture data
        // payroll dasar supaya periode payroll pertama bisa langsung
        // di-calculate tanpa harus edit profil employee dulu. Field
        // sensitif (NPWP, rekening bank) di-encrypt at-rest pakai
        // EncryptionService — sama dengan flow di EmployeeController.
        $enc = Services::encryption();
        $employeeCols = $db->getFieldNames('employees');
        if (in_array('job_grade_id', $employeeCols, true) && isset($employeeOverrides['job_grade_id'])) {
            $insert['job_grade_id'] = (int) $employeeOverrides['job_grade_id'] ?: null;
        }
        if (in_array('manager_id', $employeeCols, true) && isset($employeeOverrides['manager_id'])) {
            $insert['manager_id'] = (int) $employeeOverrides['manager_id'] ?: null;
        }
        if (in_array('probation_end_date', $employeeCols, true) && isset($employeeOverrides['probation_end_date'])) {
            $insert['probation_end_date'] = $employeeOverrides['probation_end_date'] ?: null;
        }
        if (in_array('contract_end_date', $employeeCols, true) && isset($employeeOverrides['contract_end_date'])) {
            $insert['contract_end_date'] = $employeeOverrides['contract_end_date'] ?: null;
        }
        if (in_array('ptkp_status', $employeeCols, true) && ! empty($employeeOverrides['ptkp_status'])) {
            $insert['ptkp_status'] = (string) $employeeOverrides['ptkp_status'];
        }
        if (in_array('tax_method', $employeeCols, true) && ! empty($employeeOverrides['tax_method'])) {
            $insert['tax_method'] = (string) $employeeOverrides['tax_method'];
        }
        if (in_array('pph21_method', $employeeCols, true) && ! empty($employeeOverrides['pph21_method'])) {
            $insert['pph21_method'] = (string) $employeeOverrides['pph21_method'];
        }
        // NPWP — override eksplisit menang dari carry-over candidate.
        // Encrypt sebelum disimpan. Empty string → biarkan null supaya
        // tidak ter-encrypt jadi ciphertext kosong.
        if (array_key_exists('npwp', $employeeOverrides)) {
            $npwp = trim((string) $employeeOverrides['npwp']);
            if (in_array('npwp_encrypted', $employeeCols, true)) {
                $insert['npwp_encrypted'] = $npwp !== '' ? $enc->encrypt($npwp) : null;
            }

        }
        if (array_key_exists('bpjs_kesehatan_no', $employeeOverrides)
            && in_array('bpjs_kesehatan_no', $employeeCols, true)) {
            $insert['bpjs_kesehatan_no'] = trim((string) $employeeOverrides['bpjs_kesehatan_no']) ?: null;
        }
        if (array_key_exists('bpjs_tk_no', $employeeOverrides)
            && in_array('bpjs_tk_no', $employeeCols, true)) {
            $insert['bpjs_tk_no'] = trim((string) $employeeOverrides['bpjs_tk_no']) ?: null;
        }
        if (array_key_exists('bpjs_kesehatan_class', $employeeOverrides)
            && in_array('bpjs_kesehatan_class', $employeeCols, true)) {
            $cls = (int) $employeeOverrides['bpjs_kesehatan_class'];
            $insert['bpjs_kesehatan_class'] = in_array($cls, [1, 2, 3], true) ? $cls : 1;
        }
        if (array_key_exists('bank_name', $employeeOverrides)
            && in_array('bank_name', $employeeCols, true)) {
            $insert['bank_name'] = trim((string) $employeeOverrides['bank_name']) ?: null;
        }
        if (array_key_exists('bank_account', $employeeOverrides)) {
            $acc = trim((string) $employeeOverrides['bank_account']);
            if (in_array('bank_account_encrypted', $employeeCols, true)) {
                $insert['bank_account_encrypted'] = $acc !== '' ? $enc->encrypt($acc) : null;
            }
        }
        if (array_key_exists('bank_account_holder', $employeeOverrides)
            && in_array('bank_account_holder', $employeeCols, true)) {
            $insert['bank_account_holder'] = trim((string) $employeeOverrides['bank_account_holder']) ?: null;
        }

        // Carry-over field standar HR Indonesia kalau tabel `employees`
        // sudah punya kolomnya — pakai array_intersect_key supaya aman
        // di environment yang belum migrate field-field tersebut.
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
            'kk_number'      => $cand['kk_number']      ?? null,
            'city'           => $cand['city']           ?? null,
            'province'       => $cand['province']       ?? null,
            // Sprint 23+ — extended profile
            'blood_type'     => $cand['blood_type']     ?? null,
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

        // Carry-over PII payroll fields dari candidate (kalau belum di-set
        // via overrides dari hire form). NPWP & rekening bank harus
        // di-encrypt; nomor BPJS plain text. Hanya carry-over kalau
        // override-nya belum supply value (atau key tidak ada di payload).
        if (! isset($insert['npwp_encrypted'])
            && ! empty($cand['npwp'])
            && in_array('npwp_encrypted', $employeeCols, true)) {
            $insert['npwp_encrypted'] = $enc->encrypt((string) $cand['npwp']);
        }
        if (! isset($insert['bpjs_kesehatan_no'])
            && ! empty($cand['bpjs_kesehatan_no'])
            && in_array('bpjs_kesehatan_no', $employeeCols, true)) {
            $insert['bpjs_kesehatan_no'] = (string) $cand['bpjs_kesehatan_no'];
        }
        if (! isset($insert['bpjs_tk_no'])
            && ! empty($cand['bpjs_tk_no'])
            && in_array('bpjs_tk_no', $employeeCols, true)) {
            $insert['bpjs_tk_no'] = (string) $cand['bpjs_tk_no'];
        }

        // Sprint 23+ — carry-over additional payroll/identity fields
        // yang dikumpulkan via Career Portal application form. Field-field
        // ini opsional di apply, jadi cek dulu apakah ada di candidate row
        // & belum diset oleh override hire form.
        if (! isset($insert['postal_code'])
            && ! empty($cand['postal_code'])
            && in_array('postal_code', $employeeCols, true)) {
            $insert['postal_code'] = (string) $cand['postal_code'];
        }
        if (! isset($insert['bank_name'])
            && ! empty($cand['bank_name'])
            && in_array('bank_name', $employeeCols, true)) {
            $insert['bank_name'] = (string) $cand['bank_name'];
        }
        if (! isset($insert['bank_account_encrypted'])
            && ! empty($cand['bank_account_number'])
            && in_array('bank_account_encrypted', $employeeCols, true)) {
            $insert['bank_account_encrypted'] = $enc->encrypt((string) $cand['bank_account_number']);
        }
        if (! isset($insert['bank_account_holder'])
            && ! empty($cand['bank_account_holder'])
            && in_array('bank_account_holder', $employeeCols, true)) {
            $insert['bank_account_holder'] = (string) $cand['bank_account_holder'];
        }
        // PTKP dari kandidat menang dari mapping otomatis dari marital_status
        // kalau dia sudah eksplisit isi (mis. K2 untuk yang punya 2 anak).
        if (! isset($insert['ptkp_status'])
            && ! empty($cand['ptkp_status'])
            && in_array('ptkp_status', $employeeCols, true)) {
            $insert['ptkp_status'] = (string) $cand['ptkp_status'];
        }
        // Emergency contacts
        foreach (['emergency_contact_name', 'emergency_contact_phone'] as $col) {
            if (! isset($insert[$col])
                && ! empty($cand[$col])
                && in_array($col, $employeeCols, true)) {
                $insert[$col] = (string) $cand[$col];
            }
        }
        // emergency_contact_relation di candidates ('ORANG_TUA' dst) →
        // employees pakai kolom `emergency_contact_rel` (alias).
        if (! isset($insert['emergency_contact_rel'])
            && ! empty($cand['emergency_contact_relation'])
            && in_array('emergency_contact_rel', $employeeCols, true)) {
            $insert['emergency_contact_rel'] = (string) $cand['emergency_contact_relation'];
        }

            $db->table('employees')->insert($insert);
            $employeeId = (int) $db->insertID();

        // Copy candidate_documents → employee_documents kalau tabel

        // employee_documents ada (tidak semua schema include).
            if ($db->tableExists('employee_documents')) {
                $this->copyCandidateDocsToEmployee($candidateId, $employeeId, (int) $cand['company_id']);
            }

        // Update candidate
            if ($cand['current_stage'] !== 'HIRED') {
                $db->table('candidate_stages')->insert([
                    'company_id'   => (int) $cand['company_id'],
                    'candidate_id' => $candidateId,
                    'stage'        => 'HIRED',
                    'result'       => 'PASSED',
                    'completed_at' => $now,
                    'created_by'   => $employeeOverrides['_actor_user_id'] ?? null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
            $db->table('candidates')->where('id', $candidateId)->where('company_id', $companyId)->update([
                'current_stage'        => 'HIRED',
                'converted_employee_id'=> $employeeId,
                'converted_at'         => $now,
                'updated_at'           => $now,
            ]);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Gagal menyimpan data inti hasil hire');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        // Sprint 2 — Probation auto-tracking (HIRE-1)
        // Untuk PKWTT, auto-set probation_end_date = join_date + 3 bulan
        // sesuai UU 13/2003 Pasal 60. Untuk PKWT/MAGANG/OUTSOURCING set NA.
        // Best-effort: kegagalan tidak boleh blok hire flow.
        try {
            Services::probation()->applyDefaults($employeeId);
        } catch (\Throwable $e) {
            log_message('warning', "Probation defaults apply failed for employee #{$employeeId}: " . $e->getMessage());
        }

        // Sprint 5 — MPP-driven onboarding planning (job_grade, supervisor,
        // manager + asset pre-allocation + saldo cuti gender-aware).
        // Field-field ini di-plan saat MPP submission oleh requestor, jadi
        // saat hire HR tidak perlu cari ulang. Best-effort untuk semua
        // sub-step — kegagalan satu langkah tidak boleh blok hire flow,
        // hanya logged & jadi action item HR di onboarding board.
        $planningResult = ['job_grade_id' => null, 'supervisor_id' => null, 'manager_id' => null];
        $leaveSeed = ['seeded' => 0, 'skipped_gender' => 0];
        $assetPrealloc = ['pre_allocated' => 0, 'unfulfilled' => 0, 'intent_only' => 0];
        try {
            $planSvc = Services::onboardingPlan();
            $planningResult = $planSvc->applyPlanningToEmployee(
                $employeeId,
                isset($cand['job_posting_id']) ? (int) $cand['job_posting_id'] : null,
                $employeeOverrides
            );
        } catch (\Throwable $e) {
            log_message('warning', "applyPlanningToEmployee failed for #{$employeeId}: " . $e->getMessage());
        }

        // Sprint 6 — Cost Center: resolve dan apply ke employee row.
        // Prioritas: override eksplisit → posting.cost_center_id (carry-over MPP)
        // → department.default → branch.default → company root.
        try {
            $empCols = $db->getFieldNames('employees');
            if (in_array('cost_center_id', $empCols, true)) {
                // Build override chain: cek posting punya cost_center_id?
                $postingCcId = null;
                if (! empty($cand['job_posting_id'])) {
                    $postRow = $db->table('job_postings')
                        ->select('cost_center_id')
                        ->where('id', (int) $cand['job_posting_id'])
                        ->where('company_id', $companyId)
                        ->get()->getRowArray();
                    if ($postRow && ! empty($postRow['cost_center_id'])) {
                        $postingCcId = (int) $postRow['cost_center_id'];
                    }
                }
                $ccOverrides = [];
                if (! empty($employeeOverrides['cost_center_id'])) {
                    $ccOverrides['cost_center_id'] = (int) $employeeOverrides['cost_center_id'];
                } elseif ($postingCcId) {
                    $ccOverrides['cost_center_id'] = $postingCcId;
                }
                $resolvedCc = Services::costCenter()->resolveForEmployee($employeeId, $ccOverrides);
                if ($resolvedCc) {
                    $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->update([
                        'cost_center_id' => $resolvedCc,
                        'updated_at'     => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', "Cost center resolve for #{$employeeId} failed: " . $e->getMessage());
        }
        try {
            $leaveSeed = Services::onboardingPlan()->seedInitialLeaveBalances($employeeId);
        } catch (\Throwable $e) {
            log_message('warning', "seedInitialLeaveBalances failed for #{$employeeId}: " . $e->getMessage());
        }
        try {
            $assetPrealloc = Services::onboardingPlan()->preAllocateAssetsForEmployee(
                $employeeId,
                isset($cand['job_posting_id']) ? (int) $cand['job_posting_id'] : null,
                (int) ($employeeOverrides['_actor_user_id'] ?? 0) ?: $employeeId
            );
        } catch (\Throwable $e) {
            log_message('warning', "preAllocateAssetsForEmployee failed for #{$employeeId}: " . $e->getMessage());
        }

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

        // Auto-generate kontrak kerja dari template default perusahaan
        // setelah employee berhasil di-hire.
        if (! empty($insert['employment_status'])) {
            try {
                $this->autoGenerateContract($employeeId, $cand, $insert, $autoResult);
            } catch (\Throwable $e) {
                log_message('warning', "Auto-generate contract failed for employee #{$employeeId}: " . $e->getMessage());
            }
        }

        // Update job_postings status kalau headcount terpenuhi
        $hiredCount = $db->table('candidates')

            ->where('job_posting_id', (int) $cand['job_posting_id'])
            ->where('company_id', $companyId)
            ->where('current_stage', 'HIRED')
            ->where('converted_employee_id IS NOT NULL')
            ->countAllResults();
        if ($hiredCount >= (int) $job['headcount'] && $job['status'] === 'OPEN') {
            $db->table('job_postings')->where('id', (int) $job['id'])->where('company_id', $companyId)->update([
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
            // Sprint 5 — onboarding planning summary, dipakai HR di response
            // hire untuk lihat apa saja yang sudah otomatis ter-set.
            'planning'             => $planningResult,
            'leave_seeded'         => (int) ($leaveSeed['seeded'] ?? 0),
            'leave_skipped_gender' => (int) ($leaveSeed['skipped_gender'] ?? 0),
            'assets_pre_allocated' => (int) ($assetPrealloc['pre_allocated'] ?? 0),
            'assets_intent_only'   => (int) ($assetPrealloc['intent_only'] ?? 0),
            'assets_unfulfilled'   => (int) ($assetPrealloc['unfulfilled'] ?? 0),
        ];
    }

    public function nextEmployeeNumber(int $companyId): string
    {
        $db = $this->db;
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

    private function copyCandidateDocsToEmployee(int $candidateId, int $employeeId, int $companyId): void
    {
        $db = $this->db;
        $docs = $db->table('candidate_documents')
            ->where('candidate_id', $candidateId)
            ->where('company_id', $companyId)
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

    private function autoGenerateContract(int $employeeId, array $candidate, array $empInsert, array $autoResult): void
    {

        $db = $this->db;
        $companyId = (int) $empInsert['company_id'];

        // Cari template kontrak default untuk tipe employment
        $template = $db->table('contract_templates')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->groupStart()
                ->where('is_default', 1)
                ->where('contract_type', $empInsert['employment_status'] ?? 'PKWTT')
            ->groupEnd()
            ->orderBy('is_default', 'DESC')
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        if (! $template) {
            $template = $db->table('contract_templates')
                ->where('company_id', $companyId)
                ->where('contract_type', $empInsert['employment_status'] ?? 'PKWTT')
                ->where('is_active', 1)
                ->orderBy('id', 'ASC')
                ->limit(1)
                ->get()->getRowArray();
        }

        $now = date('Y-m-d H:i:s');
        $contractNumber = 'CT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $startDate = $empInsert['join_date'] ?? date('Y-m-d');
        $endDate = null;
        if (in_array($empInsert['employment_status'] ?? '', ['PKWT', 'PROBATION', 'MAGANG'], true)) {
            $endDate = date('Y-m-d', strtotime($startDate . ' +1 year'));
        }

        // Update employee record dengan contract dates
        $employeeUpdate = ['updated_at' => $now];
        if ($endDate) $employeeUpdate['contract_end_date'] = $endDate;
        $db->table('employees')->where('id', $employeeId)->where('company_id', $companyId)->update($employeeUpdate);

        // Insert employee_contracts
        $db->table('employee_contracts')->insert([
            'company_id'      => $companyId,
            'employee_id'     => $employeeId,
            'contract_number' => $contractNumber,
            'contract_type'   => $empInsert['employment_status'] ?? 'PKWTT',
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'basic_salary'    => $empInsert['basic_salary'] ?? 0,
            'position_id'     => $empInsert['position_id'] ?? null,
            'department_id'   => $empInsert['department_id'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $contractId = (int) $db->insertID();

        // Render kontrak dari template (atau fallback minimal)
        if ($template) {
            $renderer = new \App\Libraries\Lifecycle\ContractRenderer();
            $context = $renderer->buildContext($employeeId, $contractId);
            $renderedHtml = $renderer->render($template, $context);
        } else {
            $context = ['generated_at' => $now, 'note' => 'Template tidak ditemukan — kontrak dibuat otomatis saat hire.'];
            $renderedHtml = '<p>Kontrak kerja ' . htmlspecialchars($empInsert['full_name'] ?? 'Karyawan', ENT_QUOTES, 'UTF-8')
                . ' — ' . $contractNumber . '. Silakan hubungi HR untuk template kontrak resmi.</p>';
        }

        // Insert generated_contracts dengan status SENT (langsung dikirim)
        $db->table('generated_contracts')->insert([
            'company_id'           => $companyId,
            'employee_id'          => $employeeId,
            'employee_contract_id' => $contractId,
            'template_id'          => $template ? (int) $template['id'] : null,
            'contract_number'      => $contractNumber,
            'subject'              => 'Kontrak Kerja - ' . ($empInsert['full_name'] ?? ''),
            'rendered_html'        => $renderedHtml,
            'variables_snapshot'   => json_encode($context, JSON_UNESCAPED_UNICODE),
            'status'               => 'SENT',
            'sent_at'              => $now,
            'created_by'           => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $generatedId = (int) $db->insertID();

        // Kirim notifikasi ke employee user
        $userId = $autoResult['user_created'] ?? null;
        if ($userId) {
            try {
                Services::notification()->send(
                    userId: $userId,
                    companyId: $companyId,
                    type: 'contract.sign',
                    title: 'Kontrak Kerja Siap Ditandatangani',
                    message: 'Selamat! Kontrak kerja Anda telah terbit. Silakan tanda tangan digital untuk menyelesaikan proses onboarding.',
                    data: [
                        'contract_id'   => $generatedId,
                        'contract_number' => $contractNumber,
                        'employee_id'   => $employeeId,
                    ],
                    linkUrl: '/employee/contracts/sign/' . $generatedId,
                );
            } catch (\Throwable $e) {
                log_message('warning', "Contract sign notification failed for employee #{$employeeId}: " . $e->getMessage());
            }
        }
    }
}
