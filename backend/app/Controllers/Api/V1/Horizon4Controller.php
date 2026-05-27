<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * Horizon4Controller — Platform Evolution cluster (Sprint 19+).
 *
 * H4.1 AI Copilot           → /ai/sessions, /ai/sessions/:id/messages
 * H4.3 Workforce Planning   → /workforce-scenarios, /workforce-scenarios/:id/run
 * H4.4 Employee Wellbeing   → /pulse-surveys, /pulse-surveys/:id/respond,
 *                                /pulse-surveys/:id/aggregates
 * H4.5 Compensation Intel   → /compensation/compa-ratio, /compensation/gender-equity,
 *                                /compensation/band-recommendations,
 *                                /salary-benchmarks (CRUD)
 *
 * H4.2 Multi-Country Payroll di-defer (refactor PayrollService besar).
 */
class Horizon4Controller extends ApiController
{
    // ─── H4.1 AI Copilot ────────────────────────────────────────

    public function listAiSessions()
    {
        if (! $this->canUseAi()) return $this->fail('Forbidden', 403);
        $userId = $this->userId();
        $db = \Config\Database::connect();
        $rows = $db->table('ai_chat_sessions')
            ->where('user_id', $userId)
            ->where('is_archived', 0)
            ->orderBy('updated_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function startAiSession()
    {
        if (! $this->canUseAi()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $title = $data['title'] ?? null;
        $result = (new \App\Libraries\AiAssistantService())->startSession(
            $companyId, $this->userId(), $title
        );
        return $this->ok($result, 'Session started', 201);
    }

    public function getAiMessages(int $sessionId)
    {
        if (! $this->canUseAi()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $session = $db->table('ai_chat_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (! $session) return $this->fail('Session tidak ditemukan', 404);
        // Owner-only kecuali punya permission audit
        if ((int) $session['user_id'] !== $this->userId() && ! $this->canAuditAi()) {
            return $this->fail('Forbidden', 403);
        }
        $rows = (new \App\Libraries\AiAssistantService())->listMessages($sessionId);
        return $this->ok([
            'session'  => $session,
            'messages' => $rows,
        ]);
    }

    public function sendAiMessage(int $sessionId)
    {
        if (! $this->canUseAi()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') return $this->fail('content required', 422);

        $db = \Config\Database::connect();
        $session = $db->table('ai_chat_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (! $session) return $this->fail('Session tidak ditemukan', 404);
        if ((int) $session['user_id'] !== $this->userId()) {
            return $this->fail('Forbidden', 403);
        }

        // Build user context for system prompt
        $tenant = $this->tenant();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray() ?: [];
        $perms = method_exists($tenant, 'getPermissions') ? $tenant->getPermissions() : [];
        $userContext = [
            'full_name'   => $user['full_name'] ?? null,
            'role'        => $user['role_id'] === 1 ? 'super_admin'
                : ($user['role_id'] === 2 ? 'hr_admin'
                : ($user['role_id'] === 3 ? 'manager' : 'employee')),
            'permissions' => $perms,
        ];

        try {
            $result = (new \App\Libraries\AiAssistantService())->sendMessage(
                $sessionId, $content, $userContext
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 500);
        }
        return $this->ok($result);
    }

    public function archiveAiSession(int $sessionId)
    {
        if (! $this->canUseAi()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $session = $db->table('ai_chat_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (! $session) return $this->fail('Session tidak ditemukan', 404);
        if ((int) $session['user_id'] !== $this->userId()) {
            return $this->fail('Forbidden', 403);
        }
        (new \App\Libraries\AiAssistantService())->archiveSession($sessionId);
        return $this->ok(null, 'Session archived');
    }

    // ─── H4.3 Workforce Planning ────────────────────────────────

    public function listScenarios()
    {
        if (! $this->canViewWorkforce()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $rows = $db->table('workforce_scenarios')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->orderBy('is_baseline', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createScenario()
    {
        if (! $this->canManageWorkforce()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'required|max_length[200]',
            'horizon_months' => 'required|integer|greater_than[0]|less_than[61]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => $companyId,
            'name'               => trim((string) $data['name']),
            'description'        => $data['description'] ?? null,
            'horizon_months'     => (int) $data['horizon_months'],
            'assumptions_json'   => json_encode($data['assumptions'] ?? []),
            'created_by_user_id' => $this->userId(),
            'is_baseline'        => (int) (bool) ($data['is_baseline'] ?? false),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('workforce_scenarios')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('HR_ANALYTICS')->logCreate('workforce_scenario', $id, $insert);
        return $this->ok(['id' => $id], 'Scenario created', 201);
    }

    public function runScenario(int $scenarioId)
    {
        if (! $this->canManageWorkforce()) return $this->fail('Forbidden', 403);
        try {
            $result = (new \App\Libraries\WorkforceSimulationService())->runScenario(
                $scenarioId, $this->userId()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function compareScenarios()
    {
        if (! $this->canViewWorkforce()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $ids = $data['scenario_ids'] ?? [];
        if (! is_array($ids) || count($ids) < 2 || count($ids) > 5) {
            return $this->fail('scenario_ids harus array 2-5 ID', 422);
        }
        try {
            $result = (new \App\Libraries\WorkforceSimulationService())->compareScenarios(
                $ids, $this->userId()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    // ─── H4.4 Employee Wellbeing ────────────────────────────────

    public function listSurveys()
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $b = $db->table('pulse_surveys')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL');
        if (! $this->canManageWellbeing()) {
            // Karyawan biasa hanya OPEN
            $b->where('status', 'OPEN');
        }
        $rows = $b->orderBy('created_at', 'DESC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createSurvey()
    {
        if (! $this->canManageWellbeing()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'title'     => 'required|max_length[200]',
            'starts_at' => 'required',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'   => $companyId,
            'title'        => trim((string) $data['title']),
            'description'  => $data['description'] ?? null,
            'frequency'    => strtoupper((string) ($data['frequency'] ?? 'QUARTERLY')),
            'starts_at'    => $data['starts_at'],
            'ends_at'      => $data['ends_at'] ?? null,
            'min_participation_for_dept_view' => (int) ($data['min_participation_for_dept_view'] ?? 5),
            'status'       => 'DRAFT',
            'created_by_user_id' => $this->userId(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $db->table('pulse_surveys')->insert($insert);
        $id = (int) $db->insertID();

        // Insert questions kalau ada di payload
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $i => $q) {
                $db->table('pulse_survey_questions')->insert([
                    'survey_id'     => $id,
                    'sort_order'    => (int) ($q['sort_order'] ?? $i),
                    'question_text' => (string) ($q['question_text'] ?? ''),
                    'question_type' => strtoupper((string) ($q['question_type'] ?? 'LIKERT_5')),
                    'is_required'   => (int) (bool) ($q['is_required'] ?? true),
                    'reverse_score' => (int) (bool) ($q['reverse_score'] ?? false),
                    'created_at'    => $now,
                ]);
            }
        }
        return $this->ok(['id' => $id], 'Survey created', 201);
    }

    public function openSurvey(int $surveyId)
    {
        if (! $this->canManageWellbeing()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $db->table('pulse_surveys')->where('id', $surveyId)->update([
            'status' => 'OPEN', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Survey opened');
    }

    public function getSurveyQuestions(int $surveyId)
    {
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $survey = $db->table('pulse_surveys')->where('id', $surveyId)->get()->getRowArray();
        if (! $survey || (int) $survey['company_id'] !== $companyId) {
            return $this->fail('Survey tidak ditemukan', 404);
        }
        $rows = $db->table('pulse_survey_questions')
            ->where('survey_id', $surveyId)
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();
        return $this->ok(['survey' => $survey, 'questions' => $rows]);
    }

    public function respondSurvey(int $surveyId)
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Login required', 401);
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Employee record tidak ditemukan', 404);

        $data = $this->getJsonInput();
        $answers = $data['answers'] ?? [];
        if (! is_array($answers) || empty($answers)) {
            return $this->fail('answers tidak boleh kosong', 422);
        }
        try {
            $result = (new \App\Libraries\PulseSurveyService())->submitResponse(
                $surveyId, (int) $emp['id'], $answers
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result, 'Response terkirim (anonim)', 201);
    }

    public function surveyAggregates(int $surveyId)
    {
        if (! $this->canViewWellbeing()) return $this->fail('Forbidden', 403);
        $scope = strtoupper((string) ($this->request->getGet('scope') ?? 'COMPANY'));
        $scopeId = $this->request->getGet('scope_id');
        $scopeId = $scopeId !== null && $scopeId !== '' ? (int) $scopeId : null;
        $scopeLabel = $this->request->getGet('scope_label');
        try {
            $result = (new \App\Libraries\PulseSurveyService())->computeAggregate(
                $surveyId, $scope, $scopeId, $scopeLabel
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function refreshSurveyAggregates(int $surveyId)
    {
        if (! $this->canManageWellbeing()) return $this->fail('Forbidden', 403);
        try {
            $result = (new \App\Libraries\PulseSurveyService())->computeAllAggregates($surveyId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['aggregates_count' => count($result), 'data' => $result]);
    }

    // ─── H4.5 Compensation Intelligence ─────────────────────────

    public function compaRatio()
    {
        if (! $this->canViewCompIntel()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $result = (new \App\Libraries\CompensationIntelligenceService())->computeCompaRatio($companyId);
        return $this->ok($result);
    }

    public function genderEquity()
    {
        if (! $this->canViewCompIntel()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $result = (new \App\Libraries\CompensationIntelligenceService())->computeGenderEquity($companyId);
        return $this->ok($result);
    }

    public function bandRecommendations()
    {
        if (! $this->canViewCompIntel()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $result = (new \App\Libraries\CompensationIntelligenceService())->recommendSalaryBands($companyId);
        return $this->ok($result);
    }

    public function listBenchmarks()
    {
        if (! $this->canViewCompIntel()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $db = \Config\Database::connect();
        $rows = $db->table('salary_benchmarks')
            ->where('company_id', $companyId)
            ->orderBy('effective_date', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createBenchmark()
    {
        if (! $this->canManageCompIntel()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'p50_salary'     => 'required|numeric|greater_than[0]',
            'effective_date' => 'required',
            'source'         => 'required|in_list[MANUAL,GLINTS,JOBSTREET,MERCER,KORN_FERRY,INTERNAL]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'     => $companyId,
            'job_grade_id'   => isset($data['job_grade_id']) ? (int) $data['job_grade_id'] : null,
            'position_id'    => isset($data['position_id']) ? (int) $data['position_id'] : null,
            'role_title'     => $data['role_title'] ?? null,
            'source'         => strtoupper((string) $data['source']),
            'p10_salary'     => isset($data['p10_salary']) ? (float) $data['p10_salary'] : null,
            'p25_salary'     => isset($data['p25_salary']) ? (float) $data['p25_salary'] : null,
            'p50_salary'     => (float) $data['p50_salary'],
            'p75_salary'     => isset($data['p75_salary']) ? (float) $data['p75_salary'] : null,
            'p90_salary'     => isset($data['p90_salary']) ? (float) $data['p90_salary'] : null,
            'sample_size'    => isset($data['sample_size']) ? (int) $data['sample_size'] : null,
            'effective_date' => $data['effective_date'],
            'expires_at'     => $data['expires_at'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
        $db->table('salary_benchmarks')->insert($insert);
        $id = (int) $db->insertID();
        return $this->ok(['id' => $id], 'Benchmark created', 201);
    }

    // ─── Authorization helpers ──────────────────────────────────

    private function canUseAi(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('ai_assistant.use');
    }
    private function canAuditAi(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('ai_assistant.audit');
    }
    private function canViewWorkforce(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('workforce_planning.view')
            || $t->hasPermission('workforce_planning.manage');
    }
    private function canManageWorkforce(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('workforce_planning.manage');
    }
    private function canViewWellbeing(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('wellbeing.view_aggregate')
            || $t->hasPermission('wellbeing.manage');
    }
    private function canManageWellbeing(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('wellbeing.manage');
    }
    private function canViewCompIntel(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('compensation_intelligence.view')
            || $t->hasPermission('compensation_intelligence.manage');
    }
    private function canManageCompIntel(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('compensation_intelligence.manage');
    }
}