<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * RecruitmentController
 *
 * REST API untuk Recruitment / ATS.
 *
 * Authorization:
 *   - `recruitment.view`   → list/show
 *   - `recruitment.manage` → create/update/delete posting & candidate, move stage
 *   - `recruitment.hire`   → convert candidate ke employee
 *
 * Endpoint:
 *   GET    /job-postings                       — list posting
 *   POST   /job-postings                       — create posting
 *   GET    /job-postings/(:num)                — detail + pipeline stats
 *   PUT    /job-postings/(:num)                — update
 *   DELETE /job-postings/(:num)                — soft-delete
 *   POST   /job-postings/(:num)/open           — DRAFT/PAUSED → OPEN
 *   POST   /job-postings/(:num)/close          — OPEN → CLOSED
 *   GET    /candidates                         — list dgn filter
 *   POST   /candidates                         — create candidate
 *   GET    /candidates/(:num)                  — detail + stages history + documents
 *   PUT    /candidates/(:num)                  — update profil
 *   DELETE /candidates/(:num)                  — soft-delete
 *   POST   /candidates/(:num)/move-stage       — pindah stage
 *   POST   /candidates/(:num)/reject           — reject dengan alasan
 *   POST   /candidates/(:num)/hire             — convert ke employee + trigger onboarding
 *   GET    /candidates/(:num)/stages           — history stages
 *   GET    /recruitment/pipeline-stats         — stats aggregate
 */
class RecruitmentController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private const STAGES = ['NEW','SCREENING','ONLINE_TEST','INTERVIEW_HR','INTERVIEW_USER','OFFER','HIRED','REJECTED','WITHDRAWN'];
    private const SOURCES = ['WEBSITE','LINKEDIN','JOBSTREET','REFERRAL','WALK_IN','OTHER'];
    private const DOC_TYPES = ['CV','KTP','IJAZAH','TRANSKRIP','SERTIFIKAT','PORTFOLIO','OTHER'];

    /**
     * GET /recruitment/funnel-metrics (Sprint 9 / H1.4).
     *
     * Query: job_posting_id (optional), since=YYYY-MM-DD (optional).
     * Tanpa job_posting_id → metrics global di company user.
     */
    public function funnelMetrics()
    {
        $jobPostingId = (int) ($this->request->getGet('job_posting_id') ?? 0) ?: null;
        $since = (string) ($this->request->getGet('since') ?? '') ?: null;
        if ($since !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            return $this->fail('Format since: YYYY-MM-DD', 422);
        }
        $companyId = $this->companyId();
        $rows = Services::recruitment()->funnelMetrics($jobPostingId, $companyId ?: null, $since);
        return $this->ok([
            'job_posting_id' => $jobPostingId,
            'since'          => $since ?? date('Y-m-d', strtotime('-90 days')),
            'metrics'        => $rows,
        ]);
    }

    /**
     * GET /recruitment/stale-candidates (Sprint 9 / H1.4).
     *
     * Query: threshold_days (default 7).
     */
    public function staleCandidates()
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context tidak terdeteksi', 400);
        $threshold = max(1, min(60, (int) ($this->request->getGet('threshold_days') ?? 7)));
        $rows = Services::recruitment()->staleCandidates($companyId, $threshold);
        return $this->ok([
            'threshold_days' => $threshold,
            'count'          => count($rows),
            'candidates'     => $rows,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Job postings
    // ────────────────────────────────────────────────────────────────

    public function indexJobs()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $status = strtoupper((string) ($this->request->getGet('status') ?? ''));

        $builder = $db->table('job_postings jp')
            ->select('jp.*, d.name AS department_name, p.name AS position_name, b.name AS branch_name,'
                . ' (SELECT COUNT(*) FROM candidates c WHERE c.job_posting_id = jp.id AND c.deleted_at IS NULL) AS candidate_count')
            ->join('departments d', 'd.id = jp.department_id', 'left')
            ->join('positions p', 'p.id = jp.position_id', 'left')
            ->join('branches b', 'b.id = jp.branch_id', 'left')
            ->where('jp.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'jp.company_id');

        if ($q !== '') {
            $builder->groupStart()
                ->like('jp.title', $q)
                ->orLike('jp.reference_number', $q)
                ->orLike('jp.description', $q)
                ->groupEnd();
        }
        if ($status !== '') $builder->where('jp.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('jp.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showJob($id = null)
    {
        $row = $this->loadJobOrFail((int) $id);
        if (! $row) return $this->response;

        // Pipeline stats untuk posting ini
        $row['pipeline_stats'] = Services::recruitment()->pipelineStats((int) $row['company_id'], (int) $id);
        return $this->ok($row);
    }

    public function createJob()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'title' => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $svc = Services::recruitment();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'reference_number'=> $svc->nextJobReferenceNumber($companyId),
            'title'           => $data['title'],
            'department_id'   => ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            'position_id'     => ! empty($data['position_id']) ? (int) $data['position_id'] : null,
            'branch_id'       => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
            'employment_type' => in_array(strtoupper((string) ($data['employment_type'] ?? 'PKWTT')), ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT'], true)
                ? strtoupper((string) ($data['employment_type'] ?? 'PKWTT')) : 'PKWTT',
            'description'     => $data['description'] ?? null,
            'requirements'    => $data['requirements'] ?? null,
            'salary_min'      => isset($data['salary_min']) ? (float) $data['salary_min'] : null,
            'salary_max'      => isset($data['salary_max']) ? (float) $data['salary_max'] : null,
            'salary_visible'  => (int) (bool) ($data['salary_visible'] ?? 0),
            'headcount'       => max(1, (int) ($data['headcount'] ?? 1)),
            // Migration #065 — pipeline rekrutmen custom. NULL = pakai default.
            'pipeline_template_id' => isset($data['pipeline_template_id']) && $data['pipeline_template_id'] !== ''
                ? (int) $data['pipeline_template_id'] : null,
            'opened_at'       => $data['opened_at'] ?? null,
            'closed_at'       => $data['closed_at'] ?? null,
            'status'          => 'DRAFT',
            'hiring_manager_id'=> ! empty($data['hiring_manager_id']) ? (int) $data['hiring_manager_id'] : null,
            'notes'           => $data['notes'] ?? null,
            'created_by'      => $this->userId(),
            'updated_by'      => $this->userId(),
            'created_at'      => $now, 'updated_at' => $now,
        ];
        $db = \Config\Database::connect();
        $db->table('job_postings')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('RECRUITMENT')->logCreate('job_posting', $id, $insert,
            "Job posting baru: {$insert['reference_number']} - {$insert['title']}");
        return $this->ok(['id' => $id, 'reference_number' => $insert['reference_number']], 'Created', 201);
    }

    public function updateJob($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadJobOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'title','department_id','position_id','branch_id','employment_type',
            'description','requirements','salary_min','salary_max','salary_visible',
            'headcount','opened_at','closed_at','hiring_manager_id','notes',
            // Migration #065 — pipeline_template_id boleh diganti selama
            // belum ada kandidat aktif (RecruitmentPipelineController yg
            // enforce kalau update template stages, tapi swap antar template
            // di-allow di sini supaya HR bisa migrasi posting ke template baru).
            'pipeline_template_id',
        ]));
        if (isset($update['salary_visible'])) $update['salary_visible'] = (int) (bool) $update['salary_visible'];
        if (isset($update['headcount'])) $update['headcount'] = max(1, (int) $update['headcount']);
        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('job_postings')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('RECRUITMENT')->logUpdate('job_posting', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function deleteJob($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadJobOrFail((int) $id);
        if (! $row) return $this->response;
        \Config\Database::connect()->table('job_postings')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('RECRUITMENT')->logDelete('job_posting', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    public function openJob($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadJobOrFail((int) $id);
        if (! $row) return $this->response;
        if (! in_array($row['status'], ['DRAFT','PAUSED'], true)) {
            return $this->fail("Hanya DRAFT/PAUSED yang bisa dibuka, sekarang {$row['status']}", 422);
        }
        \Config\Database::connect()->table('job_postings')->where('id', (int) $id)->update([
            'status' => 'OPEN',
            'opened_at' => $row['opened_at'] ?? date('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'job.open', entity: 'job_posting', entityId: (int) $id,
            description: "Buka posting {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok(null, 'Opened');
    }

    public function closeJob($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadJobOrFail((int) $id);
        if (! $row) return $this->response;
        if (! in_array($row['status'], ['OPEN','PAUSED','FILLED'], true)) {
            return $this->fail("Tidak bisa close dari status {$row['status']}", 422);
        }
        \Config\Database::connect()->table('job_postings')->where('id', (int) $id)->update([
            'status' => 'CLOSED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'job.close', entity: 'job_posting', entityId: (int) $id,
            description: "Tutup posting {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok(null, 'Closed');
    }

    // ────────────────────────────────────────────────────────────────
    // Candidates
    // ────────────────────────────────────────────────────────────────

    public function indexCandidates()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $stage = strtoupper((string) ($this->request->getGet('stage') ?? ''));
        $jobId = (int) ($this->request->getGet('job_posting_id') ?? 0);

        $builder = $db->table('candidates c')
            ->select('c.*, jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('job_postings jp', 'jp.id = c.job_posting_id')
            ->where('c.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'c.company_id');

        if ($q !== '') {
            $builder->groupStart()
                ->like('c.full_name', $q)
                ->orLike('c.email', $q)
                ->orLike('c.phone', $q)
                ->orLike('c.current_position', $q)
                ->groupEnd();
        }
        if ($stage !== '') $builder->where('c.current_stage', $stage);
        if ($jobId) $builder->where('c.job_posting_id', $jobId);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('c.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showCandidate($id = null)
    {
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        $row['stages'] = $db->table('candidate_stages')
            ->where('candidate_id', (int) $id)
            ->orderBy('id', 'ASC')->get()->getResultArray();

        // Dokumen pelamar (CV, KTP, ijazah, foto, dst). Tambah signed URL
        // tiap dokumen supaya HR bisa preview/download via tombol di UI.
        // URL expiry default 5 menit dari FileStorage::signedUrl().
        $documents = $db->table('candidate_documents')
            ->where('candidate_id', (int) $id)
            ->orderBy('id', 'DESC')->get()->getResultArray();
        // Signed URL (HMAC + expiry 5 menit). Frontend pakai untuk
        // preview di tab baru atau force-download via <a download>.
        $storage = Services::fileStorage();
        foreach ($documents as &$d) {
            if (! empty($d['file_path'])) {
                try {
                    $d['view_url'] = $storage->signedUrl($d['file_path'], 300);
                } catch (\Throwable $e) {
                    $d['view_url'] = null;
                }
            }
        }
        unset($d);
        $row['documents'] = $documents;

        // Sertakan juga signed URL untuk legacy column di candidates
        // (cv_path, photo_path, ktp_path, ijazah_path) — pelamar lewat
        // Career Portal biasanya isi via 4 kolom ini (sebelum
        // candidate_documents normalize). UI fallback ke field ini kalau
        // documents[] kosong.
        $legacyDocs = [];
        $legacyMap = [
            'cv_path'     => ['type' => 'CV',     'label' => 'CV'],
            'photo_path'  => ['type' => 'OTHER',  'label' => 'Pas Foto'],
            'ktp_path'    => ['type' => 'KTP',    'label' => 'KTP'],
            'ijazah_path' => ['type' => 'IJAZAH', 'label' => 'Ijazah'],
        ];
        foreach ($legacyMap as $col => $info) {
            $path = $row[$col] ?? null;
            if (! empty($path)) {
                try {
                    $legacyDocs[] = [
                        'document_type' => $info['type'],
                        'label'         => $info['label'],
                        'file_name'     => basename($path),
                        'file_path'     => $path,
                        'view_url'      => $storage->signedUrl($path, 300),
                    ];
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
        $row['legacy_documents'] = $legacyDocs;

        $job = $db->table('job_postings')->where('id', (int) $row['job_posting_id'])->get()->getRowArray();
        $row['job'] = $job;

        // Sprint 23+ — gate online test status. Frontend pakai untuk
        // disable tombol "Pindah ke INTERVIEW_HR/USER/OFFER/HIRED" kalau
        // test belum selesai. Backend tetap enforce di moveStage(), ini
        // hanya UX hint supaya HR tidak nge-klik & dapat error.
        try {
            $row['online_test_status'] = Services::recruitment()
                ->onlineTestCompletionStatus((int) $row['id']);
        } catch (\Throwable $e) {
            $row['online_test_status'] = null;
        }

        // Daftar assignment online test untuk display di candidate detail
        $row['online_test_assignments'] = $db->tableExists('online_test_assignments')
            ? $db->table('online_test_assignments ota')
                ->select('ota.id, ota.status, ota.best_result, ota.best_percentage,'
                    . ' ota.due_at, ota.completed_at, ota.attempts_used, ota.max_attempts,'
                    . ' ot.name AS test_name, ot.category AS test_category')
                ->join('online_tests ot', 'ot.id = ota.online_test_id', 'left')
                ->where('ota.candidate_id', (int) $row['id'])
                ->orderBy('ota.created_at', 'DESC')
                ->get()->getResultArray()
            : [];

        return $this->ok($row);
    }

    public function createCandidate()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'job_posting_id' => 'required|integer',
            'full_name'      => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $job = $db->table('job_postings')->where('id', (int) $data['job_posting_id'])->get()->getRowArray();
        if (! $job) return $this->fail('Job posting not found', 404);
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $job['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => (int) $job['company_id'],
            'job_posting_id'  => (int) $data['job_posting_id'],
            'full_name'       => $data['full_name'],
            'email'           => $data['email'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'birth_date'      => $data['birth_date'] ?? null,
            'gender'          => isset($data['gender']) && in_array(strtoupper($data['gender']), ['M','F','O'], true) ? strtoupper($data['gender']) : null,
            'address'         => $data['address'] ?? null,
            'current_position'=> $data['current_position'] ?? null,
            'current_company' => $data['current_company'] ?? null,
            'years_experience'=> isset($data['years_experience']) ? (int) $data['years_experience'] : null,
            'expected_salary' => isset($data['expected_salary']) ? (float) $data['expected_salary'] : null,
            'available_from'  => $data['available_from'] ?? null,
            'source'          => isset($data['source']) && in_array(strtoupper($data['source']), self::SOURCES, true) ? strtoupper($data['source']) : null,
            'referral_employee_id' => ! empty($data['referral_employee_id']) ? (int) $data['referral_employee_id'] : null,
            'current_stage'   => 'NEW',
            'rating'          => isset($data['rating']) ? (int) $data['rating'] : null,
            'notes'           => $data['notes'] ?? null,
            'created_by'      => $this->userId(),
            'created_at'      => $now, 'updated_at' => $now,
        ];
        $db->table('candidates')->insert($insert);
        $id = (int) $db->insertID();

        // Insert initial stage NEW
        $db->table('candidate_stages')->insert([
            'company_id' => (int) $job['company_id'],
            'candidate_id' => $id,
            'stage' => 'NEW',
            'result' => 'PENDING',
            'created_by' => $this->userId(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Services::audit()->forModule('RECRUITMENT')->logCreate('candidate', $id, $insert,
            "Kandidat baru: {$insert['full_name']} untuk {$job['title']}");
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function updateCandidate($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'full_name','email','phone','birth_date','gender','address',
            'current_position','current_company','years_experience','expected_salary',
            'available_from','source','referral_employee_id','rating','notes',
        ]));
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('candidates')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('RECRUITMENT')->logUpdate('candidate', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function deleteCandidate($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;
        if (! empty($row['converted_employee_id'])) {
            return $this->fail('Candidate sudah jadi employee, tidak bisa dihapus', 422);
        }
        \Config\Database::connect()->table('candidates')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('RECRUITMENT')->logDelete('candidate', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    public function moveStage($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $stage = strtoupper((string) ($data['stage'] ?? ''));
        if (! in_array($stage, self::STAGES, true)) {
            return $this->fail('Stage tidak valid', 422);
        }
        try {
            $stageId = Services::recruitment()->moveStage((int) $id, $stage, [
                'scheduled_at'  => $data['scheduled_at'] ?? null,
                'interviewer_id'=> $data['interviewer_id'] ?? null,
                'feedback'      => $data['feedback'] ?? null,
                'rating'        => $data['rating'] ?? null,
                'created_by'    => $this->userId(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'candidate.move_stage', entity: 'candidate', entityId: (int) $id,
            after: ['stage' => $stage], description: "{$row['full_name']} → {$stage}",
            severity: 'NOTICE'
        );
        return $this->ok(['stage_id' => $stageId, 'new_stage' => $stage], 'Stage updated');
    }

    public function rejectCandidate($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) return $this->fail('Alasan minimal 5 karakter', 422);
        try {
            Services::recruitment()->reject((int) $id, $reason, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'candidate.reject', entity: 'candidate', entityId: (int) $id,
            after: ['reason' => $reason], description: "Reject {$row['full_name']}: {$reason}",
            severity: 'WARNING'
        );
        return $this->ok(null, 'Rejected');
    }

    public function hireCandidate($id = null)
    {
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || $tenant->hasPermission('recruitment.hire'))) {
            return $this->fail('Forbidden: butuh permission recruitment.hire', 403);
        }
        $row = $this->loadCandidateOrFail((int) $id);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        // Sprint 23+ — saat hire, capture sekaligus data payroll-essential
        // (PTKP, NPWP, BPJS, rekening bank, tax method) supaya periode
        // payroll pertama langsung bisa di-calculate. Field sensitif
        // (npwp, bank_account) di-encrypt di service layer.
        $overrides = array_intersect_key($data, array_flip([
            'employee_number','department_id','position_id','branch_id',
            'job_grade_id','manager_id',
            'employment_status','join_date','basic_salary',
            'probation_end_date','contract_end_date',
            // Payroll
            'ptkp_status','tax_method','pph21_method',
            'npwp',
            'bpjs_kesehatan_no','bpjs_kesehatan_class','bpjs_tk_no',
            'bank_name','bank_account','bank_account_holder',
        ]));

        // Validasi ringan untuk field payroll yang punya enum constraint
        $validPtkp = ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3'];
        if (! empty($overrides['ptkp_status']) && ! in_array($overrides['ptkp_status'], $validPtkp, true)) {
            return $this->fail('ptkp_status tidak valid. Gunakan TK0-TK3 / K0-K3 / HB0-HB3.', 422);
        }
        if (! empty($overrides['tax_method'])
            && ! in_array($overrides['tax_method'], ['GROSS','NETT','GROSS_UP'], true)) {
            return $this->fail('tax_method harus GROSS / NETT / GROSS_UP.', 422);
        }
        if (! empty($overrides['pph21_method'])
            && ! in_array($overrides['pph21_method'], ['TER','GROSS_UP'], true)) {
            return $this->fail('pph21_method harus TER atau GROSS_UP.', 422);
        }
        if (isset($overrides['bpjs_kesehatan_class'])
            && ! in_array((int) $overrides['bpjs_kesehatan_class'], [1, 2, 3], true)) {
            return $this->fail('bpjs_kesehatan_class harus 1, 2, atau 3.', 422);
        }
        try {
            $result = Services::recruitment()->convertToEmployee((int) $id, $overrides);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $employeeId = $result['employee_id'];
        Services::audit()->forModule('RECRUITMENT')->log(
            action: 'candidate.hire', entity: 'candidate', entityId: (int) $id,
            after: ['employee_id' => $employeeId, 'user_id' => $result['user_id']],
            description: "Convert {$row['full_name']} → employee #{$employeeId} + auto-trigger onboarding"
                . ($result['user_id'] ? " + auto-create user account #{$result['user_id']}" : ''),
            severity: 'CRITICAL'
        );

        // Sprint 14+ — webhook outbound `recruitment.candidate_hired`.
        // Sengaja TIDAK kirim temp_password / email account ke external —
        // hanya identifier supaya consumer (mis. background-check tool,
        // analytics, accounting integration) bisa link ke employee baru.
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::CANDIDATE_HIRED,
            (int) $row['company_id'],
            [
                'candidate_id'      => (int) $id,
                'employee_id'       => $employeeId,
                'job_posting_id'    => isset($row['job_posting_id']) ? (int) $row['job_posting_id'] : null,
                'candidate_name'    => $row['full_name'] ?? null,
                'employment_status' => $overrides['employment_status'] ?? null,
                'join_date'         => $overrides['join_date'] ?? null,
                'department_id'     => isset($overrides['department_id']) ? (int) $overrides['department_id'] : null,
                'position_id'       => isset($overrides['position_id']) ? (int) $overrides['position_id'] : null,
                'branch_id'         => isset($overrides['branch_id']) ? (int) $overrides['branch_id'] : null,
                'hired_by'          => $this->userId(),
                'hired_at'          => date('c'),
            ],
        );

        // Sync MPP hired_count: kalau posting kandidat ini ditarik dari
        // sebuah MPP, increment hired_count + auto-set FILLED bila qty
        // terpenuhi. Best-effort, tidak blocking response hire.
        try {
            Services::manpower()->syncForJobPosting((int) $row['job_posting_id']);
        } catch (\Throwable $e) {
            log_message('warning', 'MPP sync after hire failed: ' . $e->getMessage());
        }
        // Response berisi credential ONE-TIME — frontend hire modal akan
        // tampilkan sekali ke HR yang melakukan hire. Tidak disimpan
        // plain text di DB; kalau hilang HR harus reset password manual.
        return $this->ok([
            'employee_id'          => $employeeId,
            'user_id'              => $result['user_id'],
            'user_email'           => $result['user_email'],
            'temp_password'        => $result['temp_password'],
            'auto_completed_tasks' => $result['auto_completed_tasks'],
        ], 'Converted to employee', 201);
    }

    public function pipelineStats()
    {
        $tenant = $this->tenant();
        $companyId = $tenant->getCompanyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $jobId = (int) ($this->request->getGet('job_posting_id') ?? 0);
        $stats = Services::recruitment()->pipelineStats($companyId, $jobId ?: null);
        return $this->ok($stats);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('recruitment.manage');
    }

    private function loadJobOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('job_postings')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Job posting not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    private function loadCandidateOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('candidates')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Candidate not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}