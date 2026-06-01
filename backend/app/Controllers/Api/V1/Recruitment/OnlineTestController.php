<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * OnlineTestController — admin (HR) endpoint untuk mengelola test online,
 * question bank, assignments, dan grading.
 *
 * Permissions:
 *   online_tests.view   — list & show
 *   online_tests.manage — create/update/delete tests, questions, assign
 *   online_tests.grade  — manual grading essay/short answer
 *
 * Routes (lihat Routes.php):
 *   Tests:
 *     GET    /online-tests
 *     POST   /online-tests
 *     GET    /online-tests/(:num)
 *     PUT    /online-tests/(:num)
 *     DELETE /online-tests/(:num)
 *     POST   /online-tests/(:num)/seed-template — seed soal default by category
 *
 *   Questions per test:
 *     GET    /online-tests/(:num)/questions
 *     POST   /online-tests/(:num)/questions
 *     PUT    /online-test-questions/(:num)
 *     DELETE /online-test-questions/(:num)
 *     POST   /online-tests/(:num)/import-from-bank
 *
 *   Question bank:
 *     GET    /online-test-questions-bank
 *     POST   /online-test-questions-bank
 *     PUT    /online-test-questions-bank/(:num)
 *     DELETE /online-test-questions-bank/(:num)
 *     GET    /online-test-questions-bank/categories
 *
 *   Assignments:
 *     GET    /online-test-assignments
 *     POST   /online-test-assignments              (HR assign ke kandidat)
 *     GET    /online-test-assignments/(:num)
 *     PUT    /online-test-assignments/(:num)
 *     DELETE /online-test-assignments/(:num)
 *     POST   /online-test-assignments/(:num)/cancel
 *
 *   Attempts/grading:
 *     GET    /online-test-attempts/(:num)          (HR view, full answers)
 *     POST   /online-test-attempts/(:num)/grade    (manual grade essay)
 */
class OnlineTestController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.view') || $t->hasPermission('online_tests.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.manage');
    }

    private function canGrade(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('online_tests.grade') || $t->hasPermission('online_tests.manage');
    }

    // ─── Tests CRUD ─────────────────────────────────────────────────

    public function listTests()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $cat = (string) ($this->request->getGet('category') ?? '');
        $b = $db->table('online_tests')
            ->where('deleted_at IS NULL');
        $this->tenant()->applyScope($b);
        if ($cat !== '') $b->where('category', $cat);
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showTest($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_tests')
            ->where('id', (int) $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $questionCount = $db->table('online_test_questions')
            ->where('online_test_id', (int) $row['id'])->countAllResults();
        $row['question_count'] = $questionCount;
        $row['pool_filters'] = $row['pool_filters'] ? json_decode((string) $row['pool_filters'], true) : null;
        return $this->ok($row);
    }

    public function createTest()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'name'             => 'required|max_length[200]',
            'duration_minutes' => 'required|integer|greater_than[0]',
            'category'         => 'permit_empty|in_list[TPA,ENGLISH,DISC,TECHNICAL,CUSTOM]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            $code = strtoupper(($body['category'] ?? 'TEST') . '-' . date('Y') . '-' . substr(uniqid(), -5));
        }

        $db = \Config\Database::connect();
        $exists = $db->table('online_tests')
            ->where('company_id', $companyId)->where('code', $code)
            ->countAllResults();
        if ($exists > 0) return $this->fail('Kode test sudah dipakai', 422, 'TEST_CODE_DUPLICATE');

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => $companyId,
            'code'              => $code,
            'name'              => $body['name'],
            'category'          => $body['category'] ?? 'CUSTOM',
            'description'       => $body['description'] ?? null,
            'instructions_html' => $body['instructions_html'] ?? null,
            'duration_minutes'  => (int) $body['duration_minutes'],
            'passing_score_pct' => (int) ($body['passing_score_pct'] ?? 60),
            'max_attempts'      => max(1, (int) ($body['max_attempts'] ?? 1)),
            'shuffle_questions' => ! empty($body['shuffle_questions']) ? 1 : 0,
            'shuffle_choices'   => ! empty($body['shuffle_choices']) ? 1 : 0,
            'show_result_to_candidate' => array_key_exists('show_result_to_candidate', $body)
                ? (! empty($body['show_result_to_candidate']) ? 1 : 0) : 1,
            'use_pool'          => ! empty($body['use_pool']) ? 1 : 0,
            'pool_filters'      => isset($body['pool_filters']) ? json_encode($body['pool_filters']) : null,
            'is_active'         => array_key_exists('is_active', $body)
                ? (! empty($body['is_active']) ? 1 : 0) : 1,
            'created_by'        => $this->userId(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('online_tests')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()->forModule('RECRUITMENT')->logCreate(
            'online_test', $id, $insert,
            "Buat online test: {$insert['name']} ({$code})",
        );
        return $this->ok(['id' => $id, 'code' => $code], 'Test created', 201);
    }

    public function updateTest($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_tests')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        if ((int) ($row['is_template'] ?? 0) === 1) {
            return $this->fail('Test template built-in tidak dapat diubah', 422);
        }
        $body = $this->getJsonInput();
        $update = array_intersect_key($body, array_flip([
            'name', 'description', 'instructions_html', 'category',
            'duration_minutes', 'passing_score_pct', 'max_attempts',
            'shuffle_questions', 'shuffle_choices', 'show_result_to_candidate',
            'use_pool', 'pool_filters', 'is_active',
        ]));
        foreach (['shuffle_questions','shuffle_choices','show_result_to_candidate','use_pool','is_active'] as $b) {
            if (isset($update[$b])) $update[$b] = (int) (bool) $update[$b];
        }
        if (isset($update['pool_filters'])) {
            $update['pool_filters'] = $update['pool_filters'] !== null
                ? json_encode($update['pool_filters']) : null;
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('online_tests')->where('id', (int) $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function deleteTest($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_tests')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        if ((int) ($row['is_template'] ?? 0) === 1) {
            return $this->fail('Test template built-in tidak dapat dihapus', 422);
        }
        $assigned = $db->table('online_test_assignments')
            ->where('online_test_id', (int) $id)->countAllResults();
        if ($assigned > 0) {
            // Soft-delete saja supaya data attempt history tidak hilang
            $db->table('online_tests')->where('id', (int) $id)->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'is_active' => 0,
            ]);
            return $this->ok(null, 'Soft-deleted (ada assignment terkait)');
        }
        $db->table('online_tests')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }

    /**
     * Seed soal default by category. Hanya untuk tes yang BARU dibuat
     * (tidak punya soal). Pakai untuk bootstrap tes TPA / English / DISC
     * tanpa harus input manual.
     */
    public function seedTemplate($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_tests')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $existing = $db->table('online_test_questions')
            ->where('online_test_id', (int) $id)->countAllResults();
        if ($existing > 0) {
            return $this->fail('Test ini sudah punya soal — hapus dulu sebelum seed template', 422);
        }

        $body = $this->getJsonInput();
        $template = strtoupper((string) ($body['template'] ?? $row['category'] ?? 'CUSTOM'));
        $seeds = $this->builtinSeed($template);
        if (! $seeds) {
            return $this->fail('Template seed untuk kategori ini belum tersedia', 422);
        }
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($seeds as $idx => $q) {
            $rows[] = [
                'online_test_id' => (int) $id,
                'bank_question_id' => null,
                'question_type'  => $q['question_type'],
                'category'       => $q['category'] ?? null,
                'prompt'         => $q['prompt'],
                'choices'        => isset($q['choices']) ? json_encode($q['choices'], JSON_UNESCAPED_UNICODE) : null,
                'correct_answer' => isset($q['correct_answer']) ? json_encode($q['correct_answer']) : null,
                'disc_weight'    => $q['disc_weight'] ?? null,
                'points'         => (float) ($q['points'] ?? 1.0),
                'explanation'    => $q['explanation'] ?? null,
                'sort_order'     => $idx,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        $db->table('online_test_questions')->insertBatch($rows);
        return $this->ok(['seeded' => count($rows)], 'Template soal berhasil di-seed');
    }

    // ─── Questions per test ────────────────────────────────────────

    public function listQuestions($testId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $test = $db->table('online_tests')->where('id', (int) $testId)->get()->getRowArray();
        if (! $test || ! $this->ownsCompany((int) $test['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $rows = $db->table('online_test_questions')
            ->where('online_test_id', (int) $testId)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['choices']        = $r['choices'] ? json_decode((string) $r['choices'], true) : null;
            $r['correct_answer'] = $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null;
        }
        unset($r);
        return $this->ok($rows);
    }

    public function createQuestion($testId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $test = $db->table('online_tests')->where('id', (int) $testId)->get()->getRowArray();
        if (! $test || ! $this->ownsCompany((int) $test['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'prompt'        => 'required',
            'question_type' => 'required|in_list[' . implode(',', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES) . ']',
        ]);
        if (! $valid) return null;
        $now = date('Y-m-d H:i:s');
        $maxOrder = (int) ($db->table('online_test_questions')
            ->selectMax('sort_order', 'mx')
            ->where('online_test_id', (int) $testId)
            ->get()->getRowArray()['mx'] ?? 0);
        $insert = [
            'online_test_id' => (int) $testId,
            'bank_question_id' => isset($body['bank_question_id']) ? (int) $body['bank_question_id'] : null,
            'question_type'  => $body['question_type'],
            'category'       => $body['category'] ?? null,
            'prompt'         => $body['prompt'],
            'choices'        => isset($body['choices']) ? json_encode($body['choices'], JSON_UNESCAPED_UNICODE) : null,
            'correct_answer' => array_key_exists('correct_answer', $body)
                ? json_encode($body['correct_answer']) : null,
            'disc_weight'    => $body['disc_weight'] ?? null,
            'points'         => (float) ($body['points'] ?? 1.0),
            'explanation'    => $body['explanation'] ?? null,
            'sort_order'     => $maxOrder + 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
        $db->table('online_test_questions')->insert($insert);
        return $this->ok(['id' => (int) $db->insertID()], 'Question added', 201);
    }

    public function updateQuestion($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_questions')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Question tidak ditemukan', 404);
        $test = $db->table('online_tests')->where('id', (int) $row['online_test_id'])->get()->getRowArray();
        if (! $test || ! $this->ownsCompany((int) $test['company_id'])) {
            return $this->fail('Forbidden', 403);
        }
        $body = $this->getJsonInput();
        $update = array_intersect_key($body, array_flip([
            'prompt', 'question_type', 'category', 'disc_weight',
            'points', 'explanation', 'sort_order',
        ]));
        if (array_key_exists('choices', $body)) {
            $update['choices'] = $body['choices'] !== null
                ? json_encode($body['choices'], JSON_UNESCAPED_UNICODE) : null;
        }
        if (array_key_exists('correct_answer', $body)) {
            $update['correct_answer'] = $body['correct_answer'] !== null
                ? json_encode($body['correct_answer']) : null;
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('online_test_questions')->where('id', (int) $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function deleteQuestion($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_questions')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Question tidak ditemukan', 404);
        $test = $db->table('online_tests')->where('id', (int) $row['online_test_id'])->get()->getRowArray();
        if (! $test || ! $this->ownsCompany((int) $test['company_id'])) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('online_test_questions')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }

    public function importFromBank($testId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $test = $db->table('online_tests')->where('id', (int) $testId)->get()->getRowArray();
        if (! $test || ! $this->ownsCompany((int) $test['company_id'])) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $body = $this->getJsonInput();
        $ids = $body['bank_question_ids'] ?? [];
        if (! is_array($ids) || empty($ids)) {
            return $this->fail('bank_question_ids wajib (array of int)', 422);
        }
        $bankRows = $db->table('online_test_question_bank')
            ->whereIn('id', array_map('intval', $ids))
            ->where('company_id', (int) $test['company_id'])
            ->get()->getResultArray();
        if (! $bankRows) return $this->fail('Tidak ada soal yang ditemukan', 422);

        $maxOrder = (int) ($db->table('online_test_questions')
            ->selectMax('sort_order', 'mx')->where('online_test_id', (int) $testId)
            ->get()->getRowArray()['mx'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($bankRows as $i => $b) {
            $rows[] = [
                'online_test_id' => (int) $testId,
                'bank_question_id' => (int) $b['id'],
                'question_type'  => $b['question_type'],
                'category'       => $b['category'],
                'prompt'         => $b['prompt'],
                'choices'        => $b['choices'],
                'correct_answer' => $b['correct_answer'],
                'disc_weight'    => $b['disc_weight'],
                'points'         => 1.0,
                'explanation'    => $b['explanation'],
                'sort_order'     => $maxOrder + $i + 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        $db->table('online_test_questions')->insertBatch($rows);
        return $this->ok(['imported' => count($rows)], 'Soal di-import dari bank', 201);
    }

    // ─── Question Bank ─────────────────────────────────────────────

    public function bankCategories()
    {
        return $this->ok([
            'test_categories'     => \App\Libraries\Recruitment\OnlineTestService::CATEGORIES,
            'question_categories' => \App\Libraries\Recruitment\OnlineTestService::QUESTION_BANK_CATEGORIES,
            'question_types'      => \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES,
        ]);
    }

    public function listBank()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $cat = (string) ($this->request->getGet('category') ?? '');
        $diff = (string) ($this->request->getGet('difficulty') ?? '');
        $type = (string) ($this->request->getGet('question_type') ?? '');
        $q = trim((string) ($this->request->getGet('q') ?? ''));

        $b = $db->table('online_test_question_bank')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($b);
        if ($cat !== '')  $b->where('category', $cat);
        if ($diff !== '') $b->where('difficulty', $diff);
        if ($type !== '') $b->where('question_type', $type);
        if ($q !== '')    $b->like('prompt', $q);
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['choices']        = $r['choices'] ? json_decode((string) $r['choices'], true) : null;
            $r['correct_answer'] = $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null;
            $r['tags']           = $r['tags'] ? json_decode((string) $r['tags'], true) : [];
        }
        unset($r);
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function createBank()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'prompt'        => 'required',
            'question_type' => 'required|in_list[' . implode(',', \App\Libraries\Recruitment\OnlineTestService::ALL_TYPES) . ']',
        ]);
        if (! $valid) return null;
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'   => $companyId,
            'category'     => $body['category'] ?? 'GENERAL',
            'difficulty'   => $body['difficulty'] ?? 'MEDIUM',
            'question_type'=> $body['question_type'],
            'prompt'       => $body['prompt'],
            'choices'      => isset($body['choices']) ? json_encode($body['choices'], JSON_UNESCAPED_UNICODE) : null,
            'correct_answer' => array_key_exists('correct_answer', $body) ? json_encode($body['correct_answer']) : null,
            'disc_weight'  => $body['disc_weight'] ?? null,
            'explanation'  => $body['explanation'] ?? null,
            'tags'         => isset($body['tags']) ? json_encode($body['tags'], JSON_UNESCAPED_UNICODE) : null,
            'is_active'    => array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : 1,
            'created_by'   => $this->userId(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $db->table('online_test_question_bank')->insert($insert);
        return $this->ok(['id' => (int) $db->insertID()], 'Bank question added', 201);
    }

    public function updateBank($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_question_bank')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Bank question tidak ditemukan', 404);
        }
        $body = $this->getJsonInput();
        $update = array_intersect_key($body, array_flip([
            'category', 'difficulty', 'question_type', 'prompt',
            'disc_weight', 'explanation', 'is_active',
        ]));
        foreach (['choices','correct_answer','tags'] as $j) {
            if (array_key_exists($j, $body)) {
                $update[$j] = $body[$j] !== null ? json_encode($body[$j], JSON_UNESCAPED_UNICODE) : null;
            }
        }
        if (isset($update['is_active'])) $update['is_active'] = (int) (bool) $update['is_active'];
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('online_test_question_bank')->where('id', (int) $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function deleteBank($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_question_bank')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Bank question tidak ditemukan', 404);
        }
        $db->table('online_test_question_bank')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Deleted');
    }

    // ─── Assignments ───────────────────────────────────────────────

    public function listAssignments()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = (string) ($this->request->getGet('status') ?? '');
        $candidateId = (int) ($this->request->getGet('candidate_id') ?? 0);
        $testId = (int) ($this->request->getGet('online_test_id') ?? 0);

        $b = $db->table('online_test_assignments oa')
            ->select('oa.*, c.full_name AS candidate_name, c.email AS candidate_email,'
                . ' ot.name AS test_name, ot.category AS test_category,'
                . ' jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('candidates c', 'c.id = oa.candidate_id')
            ->join('online_tests ot', 'ot.id = oa.online_test_id')
            ->join('job_postings jp', 'jp.id = oa.job_posting_id', 'left');
        $this->tenant()->applyScope($b, 'oa.company_id');
        if ($status !== '') $b->where('oa.status', $status);
        if ($candidateId) $b->where('oa.candidate_id', $candidateId);
        if ($testId) $b->where('oa.online_test_id', $testId);

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('oa.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showAssignment($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Assignment tidak ditemukan', 404);
        }
        $candidate = $db->table('candidates')->where('id', (int) $row['candidate_id'])->get()->getRowArray();
        $test = $db->table('online_tests')->where('id', (int) $row['online_test_id'])->get()->getRowArray();
        $attempts = $db->table('online_test_attempts')
            ->where('assignment_id', (int) $row['id'])
            ->orderBy('attempt_number', 'ASC')
            ->get()->getResultArray();
        return $this->ok([
            'assignment' => $row,
            'candidate'  => $candidate,
            'test'       => $test,
            'attempts'   => $attempts,
        ]);
    }

    public function createAssignment()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'online_test_id' => 'required|integer',
            'candidate_id'   => 'required|integer',
        ]);
        if (! $valid) return null;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $test = $db->table('online_tests')->where('id', (int) $body['online_test_id'])->get()->getRowArray();
        if (! $test || (int) $test['company_id'] !== $companyId) {
            return $this->fail('Test tidak ditemukan', 404);
        }
        $candidate = $db->table('candidates')->where('id', (int) $body['candidate_id'])->get()->getRowArray();
        if (! $candidate || (int) $candidate['company_id'] !== $companyId) {
            return $this->fail('Kandidat tidak ditemukan', 404);
        }

        // Cegah double-assign test yang sama ke candidate yang sama (kecuali expired/cancelled)
        $existing = $db->table('online_test_assignments')
            ->where('candidate_id', (int) $body['candidate_id'])
            ->where('online_test_id', (int) $body['online_test_id'])
            ->whereIn('status', ['PENDING','IN_PROGRESS','COMPLETED'])
            ->countAllResults();
        if ($existing > 0) {
            return $this->fail('Kandidat sudah ditugaskan test ini sebelumnya', 422, 'TEST_ALREADY_ASSIGNED');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'           => $companyId,
            'online_test_id'       => (int) $body['online_test_id'],
            'candidate_id'         => (int) $body['candidate_id'],
            'candidate_account_id' => $candidate['candidate_account_id'] ?? null,
            'job_posting_id'       => $candidate['job_posting_id'] ?? null,
            'status'               => 'PENDING',
            'available_from'       => $body['available_from'] ?? null,
            'due_at'               => $body['due_at'] ?? null,
            'max_attempts'         => max(1, (int) ($body['max_attempts'] ?? $test['max_attempts'] ?? 1)),
            'invitation_token'     => Services::onlineTest()->generateInvitationToken(),
            'notes'                => $body['notes'] ?? null,
            'assigned_by'          => $this->userId(),
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        $db->table('online_test_assignments')->insert($insert);
        $aid = (int) $db->insertID();

        // Optional: pindahkan kandidat ke stage tertentu setelah ditugaskan test.
        // advance_stage bisa berupa string (nama stage, misal "ONLINE_TEST")
        // atau boolean true (default ke ONLINE_TEST).
        $targetStage = null;
        if (! empty($body['advance_stage'])) {
            if (is_string($body['advance_stage'])) {
                $targetStage = strtoupper($body['advance_stage']);
            } else {
                $targetStage = 'ONLINE_TEST';
            }
        }
        if ($targetStage) {
            $stageIdx = array_flip(\App\Libraries\Recruitment\RecruitmentService::STAGES);
            $currentIdx = $stageIdx[$candidate['current_stage']] ?? -1;
            $targetIdx = $stageIdx[$targetStage] ?? -1;
            if ($currentIdx >= 0 && $targetIdx >= 0 && $currentIdx < $targetIdx) {
                $db->table('candidates')->where('id', (int) $body['candidate_id'])->update([
                    'current_stage' => $targetStage,
                    'updated_at'    => $now,
                ]);
                $db->table('candidate_stages')->insert([
                    'company_id'   => $companyId,
                    'candidate_id' => (int) $body['candidate_id'],
                    'stage'        => $targetStage,
                    'result'       => 'PENDING',
                    'created_by'   => $this->userId(),
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        }

        Services::audit()->forModule('RECRUITMENT')->logCreate(
            'online_test_assignment', $aid, $insert,
            "Assign test '{$test['name']}' ke kandidat #{$body['candidate_id']}"
        );

        // Webhook outbound
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::ONLINE_TEST_ASSIGNED,
            $companyId,
            [
                'assignment_id'        => $aid,
                'candidate_id'         => (int) $body['candidate_id'],
                'candidate_account_id' => $candidate['candidate_account_id'] ?? null,
                'online_test_id'       => (int) $body['online_test_id'],
                'test_name'            => $test['name'],
                'test_category'        => $test['category'],
                'invitation_token'     => $insert['invitation_token'],
                'due_at'               => $insert['due_at'],
                'assigned_by'          => $this->userId(),
                'assigned_at'          => $now,
            ],
        );

        // Email kandidat (optional, kalau punya account)
        if (! empty($candidate['candidate_account_id']) && ! empty($candidate['email'])) {
            try {
                $body = '<p>Halo, ' . htmlspecialchars((string) $candidate['full_name']) . '.</p>'
                    . '<p>Tim recruitment kami menugaskan Anda untuk mengikuti tes online '
                    . '<strong>' . htmlspecialchars((string) $test['name']) . '</strong>.</p>'
                    . '<p>Login ke akun career portal Anda untuk mulai mengerjakan.</p>';
                Services::notification()->queueEmail(
                    $companyId, (string) $candidate['email'],
                    (string) $candidate['full_name'],
                    'Tugas Tes Online — ' . $test['name'], $body,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Email assign test failed: ' . $e->getMessage());
            }
        }

        return $this->ok(['id' => $aid, 'invitation_token' => $insert['invitation_token']], 'Assignment created', 201);
    }

    public function updateAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Assignment tidak ditemukan', 404);
        }
        if ($row['status'] !== 'PENDING') {
            return $this->fail('Assignment tidak bisa diubah setelah test dimulai', 422);
        }
        $body = $this->getJsonInput();
        $update = array_intersect_key($body, array_flip(['available_from', 'due_at', 'max_attempts', 'notes']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('online_test_assignments')->where('id', (int) $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function cancelAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Assignment tidak ditemukan', 404);
        }
        if (in_array($row['status'], ['COMPLETED', 'CANCELLED', 'EXPIRED'], true)) {
            return $this->fail('Assignment sudah selesai/dibatalkan', 422);
        }
        $db->table('online_test_assignments')->where('id', (int) $id)->update([
            'status'     => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Cancelled');
    }

    public function deleteAssignment($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments')->where('id', (int) $id)->get()->getRowArray();
        if (! $row || ! $this->ownsCompany((int) $row['company_id'])) {
            return $this->fail('Assignment tidak ditemukan', 404);
        }
        if ($row['status'] !== 'PENDING') {
            return $this->fail('Hanya assignment PENDING yang boleh dihapus', 422);
        }
        $db->table('online_test_assignments')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }

    // ─── Attempts (HR side: full view + grade) ─────────────────────

    public function showAttempt($attemptId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('online_test_attempts')->where('id', (int) $attemptId)->get()->getRowArray();
        if (! $row) return $this->fail('Attempt tidak ditemukan', 404);
        $assign = $db->table('online_test_assignments')->where('id', (int) $row['assignment_id'])->get()->getRowArray();
        if (! $assign || ! $this->ownsCompany((int) $assign['company_id'])) {
            return $this->fail('Forbidden', 403);
        }
        // HR view: include correct_answer + explanation
        $result = Services::onlineTest()->buildResultView((int) $attemptId, true);
        $result['assignment'] = $assign;
        $candidate = $db->table('candidates')->where('id', (int) $assign['candidate_id'])->get()->getRowArray();
        $result['candidate'] = $candidate ? [
            'id'        => (int) $candidate['id'],
            'full_name' => $candidate['full_name'],
            'email'     => $candidate['email'],
            'phone'     => $candidate['phone'] ?? null,
        ] : null;
        return $this->ok($result);
    }

    public function gradeAnswer($attemptId = null)
    {
        if (! $this->canGrade()) return $this->fail('Forbidden', 403);
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'answer_id'      => 'required|integer',
            'points_awarded' => 'required|numeric|greater_than_equal_to[0]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $row = $db->table('online_test_attempts')->where('id', (int) $attemptId)->get()->getRowArray();
        if (! $row) return $this->fail('Attempt tidak ditemukan', 404);
        $assign = $db->table('online_test_assignments')->where('id', (int) $row['assignment_id'])->get()->getRowArray();
        if (! $assign || ! $this->ownsCompany((int) $assign['company_id'])) {
            return $this->fail('Forbidden', 403);
        }

        try {
            $result = Services::onlineTest()->gradeManualAnswer(
                (int) $attemptId,
                (int) $body['answer_id'],
                (float) $body['points_awarded'],
                $body['feedback'] ?? null,
                $this->userId(),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result, 'Graded');
    }

    // ─── Internal helpers ──────────────────────────────────────────

    private function ownsCompany(int $rowCompanyId): bool
    {
        $t = $this->tenant();
        if ($t->isSuperAdmin()) return true;
        return (int) $t->getCompanyId() === $rowCompanyId;
    }

    /**
     * Built-in seed templates untuk TPA / English / DISC.
     * Sengaja simple (5 soal per template) — anggap demo/starter; HR
     * bisa import dari bank atau tambah custom setelah seed.
     */
    private function builtinSeed(string $template): array
    {
        return match ($template) {
            'TPA' => [
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_VERBAL',
                 'prompt' => 'PUSAKA = ?',
                 'choices' => [['key'=>'A','text'=>'Modal'],['key'=>'B','text'=>'Warisan'],['key'=>'C','text'=>'Hadiah'],['key'=>'D','text'=>'Pemberian']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_VERBAL',
                 'prompt' => 'KAPAL : LAUT = ? : DARAT',
                 'choices' => [['key'=>'A','text'=>'Mobil'],['key'=>'B','text'=>'Roda'],['key'=>'C','text'=>'Pesawat'],['key'=>'D','text'=>'Pelabuhan']],
                 'correct_answer' => 'A', 'points' => 1],
                ['question_type' => 'NUMERIC', 'category' => 'TPA_NUMERIK',
                 'prompt' => 'Sebuah barang dijual Rp 120.000 dengan untung 20%. Berapa harga belinya?',
                 'correct_answer' => 100000, 'points' => 1,
                 'explanation' => '120.000 / 1,2 = 100.000'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_NUMERIK',
                 'prompt' => 'Lanjutan deret: 2, 6, 12, 20, 30, ...',
                 'choices' => [['key'=>'A','text'=>'40'],['key'=>'B','text'=>'42'],['key'=>'C','text'=>'44'],['key'=>'D','text'=>'46']],
                 'correct_answer' => 'B', 'points' => 1, 'explanation' => 'Selisih bertambah 4,6,8,10,12 → +12 = 42'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'TPA_LOGIKA',
                 'prompt' => 'Semua ikan berenang. Beberapa ikan bermigrasi. Maka:',
                 'choices' => [
                     ['key'=>'A','text'=>'Semua yang berenang adalah ikan'],
                     ['key'=>'B','text'=>'Beberapa yang berenang bermigrasi'],
                     ['key'=>'C','text'=>'Semua ikan bermigrasi'],
                     ['key'=>'D','text'=>'Tidak ada kesimpulan yang sah'],
                 ],
                 'correct_answer' => 'B', 'points' => 1],
            ],
            'ENGLISH' => [
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'She ___ to the office every day.',
                 'choices' => [['key'=>'A','text'=>'go'],['key'=>'B','text'=>'goes'],['key'=>'C','text'=>'going'],['key'=>'D','text'=>'gone']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'The opposite of "expand" is:',
                 'choices' => [['key'=>'A','text'=>'extend'],['key'=>'B','text'=>'enlarge'],['key'=>'C','text'=>'shrink'],['key'=>'D','text'=>'broaden']],
                 'correct_answer' => 'C', 'points' => 1],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => 'If I ___ rich, I would travel the world.',
                 'choices' => [['key'=>'A','text'=>'am'],['key'=>'B','text'=>'will be'],['key'=>'C','text'=>'were'],['key'=>'D','text'=>'be']],
                 'correct_answer' => 'C', 'points' => 1, 'explanation' => 'Type 2 conditional: If + were + would'],
                ['question_type' => 'MCQ_SINGLE', 'category' => 'ENGLISH',
                 'prompt' => '"I have been working here ___ 2020."',
                 'choices' => [['key'=>'A','text'=>'for'],['key'=>'B','text'=>'since'],['key'=>'C','text'=>'in'],['key'=>'D','text'=>'at']],
                 'correct_answer' => 'B', 'points' => 1],
                ['question_type' => 'TRUE_FALSE', 'category' => 'ENGLISH',
                 'prompt' => '"Children" adalah bentuk plural yang benar dari "child".',
                 'correct_answer' => true, 'points' => 1],
            ],
            'DISC' => [
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya senang mengambil keputusan cepat dan bertindak segera.',
                 'disc_weight' => 'D', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya menikmati berinteraksi dengan banyak orang setiap hari.',
                 'disc_weight' => 'I', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya lebih nyaman dengan rutinitas yang stabil dan dapat diprediksi.',
                 'disc_weight' => 'S', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya selalu memeriksa detail dan memastikan akurasi sebelum menyelesaikan pekerjaan.',
                 'disc_weight' => 'C', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya berani memimpin tim dalam situasi sulit.',
                 'disc_weight' => 'D', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya mudah memotivasi orang lain dengan antusiasme saya.',
                 'disc_weight' => 'I', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya pendengar yang baik dan sabar terhadap rekan kerja.',
                 'disc_weight' => 'S', 'points' => 1],
                ['question_type' => 'LIKERT_1_5', 'category' => 'DISC',
                 'prompt' => 'Saya teliti dan kritis terhadap kualitas hasil kerja.',
                 'disc_weight' => 'C', 'points' => 1],
            ],
            default => [],
        };
    }
}