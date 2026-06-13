<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * Horizon3Controller — Talent Management cluster (Sprint 14 + 17).
 *
 * Cakupan modul:
 *   H3.1 Competency Library   → /competencies, /position-competency-profiles,
 *                                 /employee-competency-assessments,
 *                                 /employees/:id/competency-gap
 *   H3.2 Succession Planning  → /critical-positions, /succession-candidates,
 *                                 /succession/coverage
 *   H3.6 Talent Pool          → /employees/:id/skills, /employees/:id/aspirations,
 *                                 /internal-job-postings, /internal-applications,
 *                                 /talent-pool/9-box, /talent-pool/match/:postingId
 *
 * Authorization: tiap endpoint cek permission yang relevan via tenant.
 *
 * H3.3 Flexible Benefit, H3.4 ERP Integration, H3.5 Predictive Analytics,
 * H3.7 PSrE Digital Signing, H3.8 Open API — di-defer (butuh vendor /
 * infrastruktur eksternal). Lihat checklist Horizon 3.
 */
class Horizon3Controller extends ApiController
{
    // ─── H3.1 Competency Library ─────────────────────────────────

    public function listCompetencies()
    {
        if (! $this->canViewCompetency()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $b = $db->table('competencies')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        $cat = $this->request->getGet('category');
        if ($cat) $b->where('category', strtoupper((string) $cat));
        $active = $this->request->getGet('is_active');
        if ($active !== null && $active !== '') $b->where('is_active', (int) (bool) $active);
        $rows = $b->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createCompetency()
    {
        if (! $this->canManageCompetency()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'required|max_length[200]',
            'category' => 'required|in_list[CORE,LEADERSHIP,FUNCTIONAL,TECHNICAL,BEHAVIORAL]',
        ]);
        if (! $valid) return null;

        $code = ! empty($data['code'])
            ? strtoupper((string) $data['code'])
            : \App\Libraries\Performance\CompetencyService::normalizeSlugCode((string) $data['name']);
        $db = \Config\Database::connect();
        $exists = $db->table('competencies')
            ->where('company_id', $companyId)->where('code', $code)
            ->where('deleted_at IS NULL')->countAllResults();
        if ($exists > 0) return $this->fail("Code '{$code}' sudah dipakai", 422);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'  => $companyId,
            'code'        => $code,
            'name'        => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'category'    => strtoupper((string) $data['category']),
            'is_active'   => (int) (bool) ($data['is_active'] ?? 1),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('competencies')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('PERFORMANCE')->logCreate('competency', $id, $insert);
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function listPositionProfile(int $positionId)
    {
        if (! $this->canViewCompetency()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $rows = $db->table('position_competency_profiles pcp')
            ->select('pcp.*, c.code, c.name AS competency_name, c.category')
            ->join('competencies c', 'c.id = pcp.competency_id')
            ->where('pcp.position_id', $positionId)
            ->orderBy('pcp.is_critical', 'DESC')
            ->orderBy('c.sort_order', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function setPositionProfile(int $positionId)
    {
        if (! $this->canManageCompetency()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $items = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : [];
        if (empty($items)) return $this->fail('profile array wajib (minimal 1 item)', 422);

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $inserted = 0; $updated = 0;
        foreach ($items as $item) {
            $compId = (int) ($item['competency_id'] ?? 0);
            $target = (int) ($item['target_level'] ?? 0);
            if ($compId <= 0 || $target < 1 || $target > 5) continue;

            $existing = $db->table('position_competency_profiles')
                ->where('position_id', $positionId)
                ->where('competency_id', $compId)
                ->get()->getRowArray();
            $payload = [
                'company_id'    => $companyId,
                'position_id'   => $positionId,
                'competency_id' => $compId,
                'target_level'  => $target,
                'weight'        => (float) ($item['weight'] ?? 1),
                'is_critical'   => (int) (bool) ($item['is_critical'] ?? false),
                'updated_at'    => $now,
            ];
            if ($existing) {
                $db->table('position_competency_profiles')->where('id', (int) $existing['id'])->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = $now;
                $db->table('position_competency_profiles')->insert($payload);
                $inserted++;
            }
        }
        return $this->ok(['inserted' => $inserted, 'updated' => $updated]);
    }

    public function competencyGap(int $employeeId)
    {
        if (! $this->canViewCompetency()) return $this->fail('Forbidden', 403);
        try {
            $targetPos = $this->request->getGet('target_position_id');
            $targetPos = $targetPos !== null && $targetPos !== '' ? (int) $targetPos : null;
            $result = (new \App\Libraries\Performance\CompetencyService())->gapAnalysis($employeeId, $targetPos);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function recommendTraining(int $employeeId)
    {
        if (! $this->canViewCompetency()) return $this->fail('Forbidden', 403);
        $limit = min(20, max(1, (int) ($this->request->getGet('limit') ?? 10)));
        $rows = (new \App\Libraries\Performance\CompetencyService())->recommendTraining($employeeId, $limit);
        return $this->ok($rows);
    }

    public function recordAssessment()
    {
        if (! $this->canRecordAssessment()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        try {
            $result = (new \App\Libraries\Performance\CompetencyService())->recordAssessment(
                $data + ['assessor_user_id' => $this->userId()],
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('PERFORMANCE')->logCreate(
            'competency_assessment',
            (int) $result['id'],
            $data,
        );
        return $this->ok($result, 'Assessment recorded', 201);
    }

    // ─── H3.2 Succession Planning ────────────────────────────────

    public function listCriticalPositions()
    {
        if (! $this->canViewSuccession()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $rows = $db->table('critical_positions cp')
            ->select('cp.*, p.name AS position_name, e.full_name AS incumbent_name')
            ->join('positions p', 'p.id = cp.position_id', 'left')
            ->join('employees e', 'e.id = cp.incumbent_employee_id', 'left')
            ->where('cp.company_id', $companyId)
            ->where('cp.is_active', 1)
            ->orderBy("FIELD(cp.criticality, 'HIGH', 'MEDIUM', 'LOW')", '', false)
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createCriticalPosition()
    {
        if (! $this->canManageSuccession()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'position_id' => 'required|integer',
            'criticality' => 'required|in_list[HIGH,MEDIUM,LOW]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        // Cek dup
        $exists = $db->table('critical_positions')
            ->where('company_id', $companyId)
            ->where('position_id', (int) $data['position_id'])
            ->countAllResults();
        if ($exists > 0) return $this->fail('Position sudah di-mark critical', 422);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'              => $companyId,
            'position_id'             => (int) $data['position_id'],
            'criticality'             => strtoupper((string) $data['criticality']),
            'risk_factor'             => $data['risk_factor'] ?? null,
            'incumbent_employee_id'   => isset($data['incumbent_employee_id']) ? (int) $data['incumbent_employee_id'] : null,
            'min_successor_count'     => (int) ($data['min_successor_count'] ?? 2),
            'is_active'               => 1,
            'created_at'              => $now,
            'updated_at'              => $now,
        ];
        $db->table('critical_positions')->insert($insert);
        $id = (int) $db->insertID();
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function nominateSuccessor(int $criticalPositionId)
    {
        if (! $this->canManageSuccession()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, ['employee_id' => 'required|integer']);
        if (! $valid) return null;
        try {
            $result = (new \App\Libraries\Performance\SuccessionService())->nominate(
                $criticalPositionId,
                (int) $data['employee_id'],
                $this->userId(),
                $data['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result, 'Successor nominated', 201);
    }

    public function listSuccessors(int $criticalPositionId)
    {
        if (! $this->canViewSuccession()) return $this->fail('Forbidden', 403);
        $rows = (new \App\Libraries\Performance\SuccessionService())->listSuccessors($criticalPositionId);
        return $this->ok($rows);
    }

    public function recomputeReadiness(int $candidateId)
    {
        if (! $this->canManageSuccession()) return $this->fail('Forbidden', 403);
        try {
            $result = (new \App\Libraries\Performance\SuccessionService())->recomputeReadiness($candidateId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function successionCoverage()
    {
        if (! $this->canViewSuccession()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $report = (new \App\Libraries\Performance\SuccessionService())->coverageReport($companyId);
        return $this->ok($report);
    }

    // ─── H3.6 Talent Pool ────────────────────────────────────────

    public function listEmployeeSkills(int $employeeId)
    {
        if (! $this->canViewTalentPool() && ! $this->isOwnEmployee($employeeId)) {
            return $this->fail('Forbidden', 403);
        }
        $db = \Config\Database::connect();
        $rows = $db->table('employee_skills')
            ->where('employee_id', $employeeId)
            ->orderBy("FIELD(proficiency, 'EXPERT', 'ADVANCED', 'INTERMEDIATE', 'BEGINNER')", '', false)
            ->orderBy('skill_name', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function declareSkill(int $employeeId)
    {
        if (! $this->isOwnEmployee($employeeId) && ! $this->canManageTalentPool()) {
            return $this->fail('Forbidden — hanya employee bersangkutan / HR', 403);
        }
        $data = $this->getJsonInput();
        try {
            $result = (new \App\Libraries\Recruitment\TalentPoolService())->declareSkill($employeeId, $data);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function validateSkill(int $skillId)
    {
        if (! $this->canManageTalentPool()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        try {
            $result = (new \App\Libraries\Recruitment\TalentPoolService())->validateSkill(
                $skillId,
                $this->userId(),
                $data['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function listInternalPostings()
    {
        // Karyawan biasa boleh lihat posting OPEN (untuk apply)
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $b = $db->table('internal_job_postings ijp')
            ->select('ijp.*, p.name AS position_name, d.name AS department_name')
            ->join('positions p', 'p.id = ijp.position_id', 'left')
            ->join('departments d', 'd.id = ijp.department_id', 'left')
            ->where('ijp.company_id', $companyId)
            ->where('ijp.deleted_at IS NULL');

        $tenant = $this->tenant();
        $canManage = $this->canManageTalentPool();
        if (! $canManage) {
            // Karyawan biasa hanya lihat OPEN
            $b->where('ijp.status', 'OPEN');
        } else {
            $status = $this->request->getGet('status');
            if ($status) $b->where('ijp.status', strtoupper((string) $status));
        }
        $rows = $b->orderBy('ijp.created_at', 'DESC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createInternalPosting()
    {
        if (! $this->canManageTalentPool()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'title'       => 'required|max_length[200]',
            'position_id' => 'required|integer',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => $companyId,
            'job_posting_id'     => isset($data['job_posting_id']) ? (int) $data['job_posting_id'] : null,
            'position_id'        => (int) $data['position_id'],
            'department_id'      => isset($data['department_id']) ? (int) $data['department_id'] : null,
            'branch_id'          => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'title'              => trim((string) $data['title']),
            'description'        => $data['description'] ?? null,
            'requirements'       => $data['requirements'] ?? null,
            'min_tenure_months'  => (int) ($data['min_tenure_months'] ?? 12),
            'eligibility_json'   => isset($data['eligibility']) ? json_encode($data['eligibility']) : null,
            'opens_at'           => $data['opens_at'] ?? $now,
            'closes_at'          => $data['closes_at'] ?? null,
            'status'             => strtoupper((string) ($data['status'] ?? 'OPEN')),
            'created_by_user_id' => $this->userId(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('internal_job_postings')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('RECRUITMENT')->logCreate('internal_job_posting', $id, $insert);
        return $this->ok(['id' => $id], 'Internal posting created', 201);
    }

    public function applyInternal(int $internalJobPostingId)
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Login required', 401);
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->where('is_active', 1)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee record tidak ditemukan untuk user ini', 404);

        $data = $this->getJsonInput();
        try {
            $result = (new \App\Libraries\Recruitment\TalentPoolService())->applyInternal(
                $internalJobPostingId,
                (int) $emp['id'],
                $data['cover_letter'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('RECRUITMENT')->logCreate(
            'internal_application',
            (int) $result['id'],
            ['internal_job_posting_id' => $internalJobPostingId, 'employee_id' => (int) $emp['id']],
        );
        return $this->ok($result, 'Lamaran internal terkirim', 201);
    }

    public function listInternalApplications(int $internalJobPostingId)
    {
        if (! $this->canManageTalentPool()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $rows = $db->table('internal_applications ia')
            ->select('ia.*, e.full_name, e.employee_number, e.position_id AS current_position_id')
            ->join('employees e', 'e.id = ia.employee_id')
            ->where('ia.internal_job_posting_id', $internalJobPostingId)
            ->orderBy('ia.created_at', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function recommendInternalCandidates(int $internalJobPostingId)
    {
        if (! $this->canManageTalentPool()) return $this->fail('Forbidden', 403);
        $limit = min(50, max(1, (int) ($this->request->getGet('limit') ?? 20)));
        $rows = (new \App\Libraries\Recruitment\TalentPoolService())->recommendCandidatesForPosting(
            $internalJobPostingId, $limit
        );
        return $this->ok($rows);
    }

    // ─── H3.6 — Employee Aspirations CRUD ────────────────────────────
    // Career goal employee (target posisi/dept/skill + timeline + visibility).
    // Self-service: karyawan boleh lihat/edit aspirasi sendiri; supervisor/HR
    // boleh lihat sesuai `visibility` (SUPERVISOR/HR/COMPANY).

    /**
     * GET /api/v1/employees/(:num)/aspirations
     */
    public function listAspirations(int $employeeId)
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);

        $isOwn = $this->isOwnEmployee($employeeId);
        $canManage = $this->canManageTalentPool();
        if (! $isOwn && ! $canManage) {
            return $this->fail('Forbidden — hanya employee bersangkutan / HR', 403);
        }

        $db = \Config\Database::connect();
        $rows = $db->table('employee_aspirations a')
            ->select('a.*, p.name AS target_position_name, d.name AS target_department_name, b.name AS target_branch_name')
            ->join('positions p', 'p.id = a.target_position_id', 'left')
            ->join('departments d', 'd.id = a.target_department_id', 'left')
            ->join('branches b', 'b.id = a.target_branch_id', 'left')
            ->where('a.company_id', $companyId)
            ->where('a.employee_id', $employeeId)
            ->orderBy('a.timeline', 'ASC')
            ->orderBy('a.id', 'DESC')
            ->get()->getResultArray();

        // Privacy filter: kalau bukan owner & bukan HR full, hide row dengan
        // visibility lebih restricted dari role caller. SUPERVISOR < HR < COMPANY.
        if (! $isOwn && ! $canManage) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['visibility'] === 'COMPANY'));
        }

        return $this->ok($rows);
    }

    /**
     * POST /api/v1/employees/(:num)/aspirations — create.
     * PATCH /api/v1/employees/(:num)/aspirations/(:num) — update.
     * Body:
     *   aspiration_type  ENUM(POSITION/DEPARTMENT/CAREER_TRACK/SKILL_DEVELOPMENT/GEOGRAPHY)
     *   target_position_id, target_department_id, target_branch_id (opsional)
     *   target_text (free-form)
     *   timeline ENUM(IMMEDIATE/SHORT_TERM/MID_TERM/LONG_TERM)
     *   visibility ENUM(SUPERVISOR/HR/COMPANY) default SUPERVISOR
     */
    public function upsertAspiration(int $employeeId, ?int $aspirationId = null)
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        if (! $this->isOwnEmployee($employeeId) && ! $this->canManageTalentPool()) {
            return $this->fail('Forbidden — hanya employee bersangkutan / HR', 403);
        }

        $data = $this->getJsonInput();
        try {
            $svc = new \App\Libraries\Recruitment\TalentPoolService();
            $row = $svc->upsertAspiration(
                $companyId,
                $employeeId,
                $aspirationId ? (int) $aspirationId : null,
                $data
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($row);
    }

    /**
     * DELETE /api/v1/employees/(:num)/aspirations/(:num)
     */
    public function deleteAspiration(int $employeeId, int $aspirationId)
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        if (! $this->isOwnEmployee($employeeId) && ! $this->canManageTalentPool()) {
            return $this->fail('Forbidden — hanya employee bersangkutan / HR', 403);
        }

        try {
            $svc = new \App\Libraries\Recruitment\TalentPoolService();
            $svc->deleteAspiration($companyId, $employeeId, $aspirationId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['deleted' => true]);
    }

    public function nineBoxGrid()
    {
        if (! $this->canViewTalentPool()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $result = (new \App\Libraries\Recruitment\TalentPoolService())->nineBoxGrid($companyId);
        return $this->ok($result);
    }

    // ─── Authorization helpers ──────────────────────────────────

    private function canViewCompetency(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('competency.view')
            || $t->hasPermission('competency.manage');
    }
    private function canManageCompetency(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('competency.manage');
    }
    private function canRecordAssessment(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('competency.manage')
            || $t->hasPermission('competency.assess_self');
    }
    private function canViewSuccession(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('succession.view')
            || $t->hasPermission('succession.manage');
    }
    private function canManageSuccession(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('succession.manage');
    }
    private function canViewTalentPool(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('talent_pool.view')
            || $t->hasPermission('talent_pool.manage');
    }
    private function canManageTalentPool(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('talent_pool.manage');
    }
    private function isOwnEmployee(int $employeeId): bool
    {
        $userId = $this->userId();
        if (! $userId) return false;
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        return $emp && (int) $emp['id'] === $employeeId;
    }
}