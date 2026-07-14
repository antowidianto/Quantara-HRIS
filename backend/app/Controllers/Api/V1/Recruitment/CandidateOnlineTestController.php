<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CandidateOnlineTestController — endpoint kandidat untuk ambil test online.
 *
 * Filter: candidatejwt — semua endpoint butuh kandidat sudah login.
 *
 * Routes:
 *   GET  /careers/account/tests                      → list assignment
 *   GET  /careers/account/tests/(:num)               → detail assignment
 *   POST /careers/account/tests/(:num)/start         → mulai/resume attempt
 *   GET  /careers/account/attempts/(:num)            → state attempt
 *   POST /careers/account/attempts/(:num)/answer     → save jawaban
 *   POST /careers/account/attempts/(:num)/submit     → submit attempt
 *   POST /careers/account/attempts/(:num)/event      → log anti-cheat event
 *   GET  /careers/account/attempts/(:num)/result     → hasil setelah submit
 */
class CandidateOnlineTestController extends BaseController
{
    use \App\Traits\DispatchesWebhooks;

    public function listAssignments()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $db = \Config\Database::connect();
        $rows = $db->table('online_test_assignments ota')
            ->select('ota.id, ota.online_test_id, ota.candidate_id, ota.status,'
                . ' ota.available_from, ota.due_at, ota.max_attempts, ota.attempts_used,'
                . ' ota.best_percentage, ota.best_result, ota.completed_at, ota.created_at,'
                . ' ot.name AS test_name, ot.category AS test_category,'
                . ' ot.duration_minutes, ot.passing_score_pct,'
                . ' jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('online_tests ot', 'ot.id = ota.online_test_id AND ot.company_id = ota.company_id')
            ->join('job_postings jp', 'jp.id = ota.job_posting_id AND jp.company_id = ota.company_id', 'left')
            ->where('ota.candidate_account_id', (int) $account['id'])
            ->orderBy('ota.created_at', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function showAssignment($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $assignment = $this->loadOwnedAssignment((int) $id, (int) $account['id']);
        if (! $assignment) return $this->fail('Assignment tidak ditemukan', 404);

        $db = \Config\Database::connect();
        $test = $db->table('online_tests')
            ->where('id', (int) $assignment['online_test_id'])
            ->where('company_id', (int) $assignment['company_id'])
            ->get()->getRowArray();

        $attempts = $db->table('online_test_attempts a')
            ->select('a.*')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', (int) $assignment['id'])
            ->where('x.company_id', (int) $assignment['company_id'])
            ->where('x.candidate_account_id', (int) $account['id'])
            ->orderBy('attempt_number', 'ASC')
            ->get()->getResultArray();

        return $this->ok([
            'assignment' => $assignment,
            'test' => $test ? [
                'id'                => (int) $test['id'],
                'name'              => $test['name'],
                'category'          => $test['category'],
                'description'       => $test['description'] ?? null,
                'instructions_html' => $test['instructions_html'] ?? null,
                'duration_minutes'  => (int) $test['duration_minutes'],
                'passing_score_pct' => (int) $test['passing_score_pct'],
                'max_attempts'      => (int) $test['max_attempts'],
                'show_result_to_candidate' => (int) $test['show_result_to_candidate'] === 1,
            ] : null,
            'attempts' => array_map(static fn ($a) => [
                'id'           => (int) $a['id'],
                'attempt_number' => (int) $a['attempt_number'],
                'status'       => $a['status'],
                'started_at'   => $a['started_at'],
                'submitted_at' => $a['submitted_at'],
                'percentage'   => (float) $a['percentage'],
                'result'       => $a['result'],
            ], $attempts),
        ]);
    }

    public function startAttempt($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $assignment = $this->loadOwnedAssignment((int) $id, (int) $account['id']);
        if (! $assignment) return $this->fail('Assignment tidak ditemukan', 404);

        try {
            $view = Services::onlineTest()->startAttempt(
                (int) $assignment['id'],
                $this->request->getIPAddress(),
                $this->request->getHeaderLine('User-Agent'),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'startAttempt error: ' . $e->getMessage());
            return $this->fail('Gagal memulai test', 500);
        }

        return $this->ok($view, 'Attempt started', 201);
    }

    public function completeAssignment($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $assignment = $this->loadOwnedAssignment((int) $id, (int) $account['id']);
        if (! $assignment) return $this->fail('Assignment tidak ditemukan', 404);
        if (in_array($assignment['status'], ['COMPLETED', 'EXPIRED', 'CANCELLED'], true)) {
            return $this->ok(['assignment' => $assignment], 'Assignment already closed');
        }

        $db = \Config\Database::connect();
        $inProgress = $db->table('online_test_attempts a')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', (int) $assignment['id'])
            ->where('x.company_id', (int) $assignment['company_id'])
            ->where('x.candidate_account_id', (int) $account['id'])
            ->where('a.status', 'IN_PROGRESS')
            ->countAllResults();
        if ($inProgress > 0) {
            return $this->fail('Masih ada percobaan yang sedang berjalan. Submit dulu sebelum menyelesaikan test.', 422);
        }

        $submitted = $db->table('online_test_attempts a')
            ->join('online_test_assignments x', 'x.id = a.assignment_id')
            ->where('a.assignment_id', (int) $assignment['id'])
            ->where('x.company_id', (int) $assignment['company_id'])
            ->where('x.candidate_account_id', (int) $account['id'])
            ->whereIn('a.status', ['SUBMITTED', 'GRADED', 'EXPIRED'])
            ->countAllResults();
        if ($submitted <= 0) {
            return $this->fail('Belum ada percobaan yang sudah dikirim.', 422);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('online_test_assignments')->where('id', (int) $assignment['id'])
            ->where('company_id', (int) $assignment['company_id'])
            ->where('candidate_account_id', (int) $account['id'])->update([
            'status'       => 'COMPLETED',
            'completed_at' => $assignment['completed_at'] ?: $now,
            'updated_at'   => $now,
        ]);
        $assignment['status'] = 'COMPLETED';
        $assignment['completed_at'] = $assignment['completed_at'] ?: $now;

        return $this->ok(['assignment' => $assignment], 'Assignment completed');
    }

    public function attemptState($attemptId = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        if (! $this->ownsAttempt((int) $attemptId, (int) $account['id'])) {
            return $this->fail('Attempt tidak ditemukan', 404);
        }
        try {
            $view = Services::onlineTest()->buildAttemptView((int) $attemptId);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }
        return $this->ok($view);
    }

    public function saveAnswer($attemptId = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        if (! $this->ownsAttempt((int) $attemptId, (int) $account['id'])) {
            return $this->fail('Attempt tidak ditemukan', 404);
        }
        $body = $this->getBody();
        $answerId = (int) ($body['answer_id'] ?? 0);
        if ($answerId <= 0) return $this->fail('answer_id wajib', 422);
        $response = $body['response'] ?? null;
        $time = (int) ($body['time_spent_sec'] ?? 0);
        try {
            Services::onlineTest()->saveAnswer((int) $attemptId, $answerId, $response, $time);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Saved');
    }

    public function submitAttempt($attemptId = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        if (! $this->ownsAttempt((int) $attemptId, (int) $account['id'])) {
            return $this->fail('Attempt tidak ditemukan', 404);
        }
        try {
            $svc = Services::onlineTest();
            // Hide correct_answer/explanation kalau test set show_result_to_candidate=0
            $result = $svc->submitAttempt((int) $attemptId);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // Webhook: test completed (idempotent — tetap aman dipanggil
        // walau submit dipanggil 2x karena submitAttempt idempotent juga)
        $assignment = $this->getAssignmentForAttempt((int) $attemptId);
        if ($assignment) {
            $this->dispatchWebhook(
                \App\Constants\WebhookEvent::ONLINE_TEST_COMPLETED,
                (int) $assignment['company_id'],
                [
                    'assignment_id'        => (int) $assignment['id'],
                    'attempt_id'           => (int) $attemptId,
                    'candidate_id'         => (int) $assignment['candidate_id'],
                    'candidate_account_id' => (int) ($assignment['candidate_account_id'] ?? 0),
                    'online_test_id'       => (int) $assignment['online_test_id'],
                    'job_posting_id'       => isset($assignment['job_posting_id']) ? (int) $assignment['job_posting_id'] : null,
                    'percentage'           => $result['attempt']['percentage'] ?? null,
                    'result'               => $result['attempt']['result'] ?? null,
                    'submitted_at'         => $result['attempt']['submitted_at'] ?? date('c'),
                ],
            );
        }

        return $this->ok($this->scrubResultForCandidate($result), 'Submitted');
    }

    public function logEvent($attemptId = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        if (! $this->ownsAttempt((int) $attemptId, (int) $account['id'])) {
            return $this->fail('Attempt tidak ditemukan', 404);
        }

        $body = $this->getBody();
        $type = strtoupper((string) ($body['event_type'] ?? ''));
        $allowed = ['TAB_SWITCH', 'FULLSCREEN_EXIT', 'COPY_ATTEMPT', 'PASTE_ATTEMPT',
            'RIGHT_CLICK', 'RESIZE', 'DEVTOOLS_OPEN', 'HEARTBEAT'];
        if (! in_array($type, $allowed, true)) {
            return $this->fail('event_type tidak dikenal', 422);
        }
        $meta = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : null;
        $now = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $db->table('online_test_events')->insert([
            'attempt_id'  => (int) $attemptId,
            'event_type'  => $type,
            'occurred_at' => $now,
            'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at'  => $now,
        ]);
        // Increment counter di attempt untuk indicator anti-cheat
        if ($type === 'TAB_SWITCH') {
            $db->query('UPDATE online_test_attempts SET tab_switch_count = tab_switch_count + 1 WHERE id = ?', [(int) $attemptId]);
        } elseif ($type === 'FULLSCREEN_EXIT') {
            $db->query('UPDATE online_test_attempts SET fullscreen_exit_count = fullscreen_exit_count + 1 WHERE id = ?', [(int) $attemptId]);
        }
        return $this->ok(null, 'Logged');
    }

    public function attemptResult($attemptId = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        if (! $this->ownsAttempt((int) $attemptId, (int) $account['id'])) {
            return $this->fail('Attempt tidak ditemukan', 404);
        }
        $result = Services::onlineTest()->buildResultView((int) $attemptId, true);
        // Status check: kalau attempt belum submit, jangan tampilkan jawaban
        if (! in_array($result['attempt']['status'] ?? '', ['SUBMITTED', 'GRADED', 'EXPIRED'], true)) {
            return $this->fail('Attempt belum disubmit', 422);
        }
        return $this->ok($this->scrubResultForCandidate($result));
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Pastikan kandidat hanya bisa lihat assignment miliknya sendiri.
     */
    private function loadOwnedAssignment(int $assignmentId, int $accountId): ?array
    {
        if ($assignmentId <= 0) return null;
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments')
            ->where('id', $assignmentId)
            ->where('candidate_account_id', $accountId)
            ->where('company_id >', 0)
            ->get()->getRowArray();
        return $row ?: null;
    }

    private function ownsAttempt(int $attemptId, int $accountId): bool
    {
        if ($attemptId <= 0) return false;
        $db = \Config\Database::connect();
        $row = $db->table('online_test_attempts ota')
            ->select('ota.id')
            ->join('online_test_assignments oa', 'oa.id = ota.assignment_id')
            ->where('ota.id', $attemptId)
            ->where('oa.candidate_account_id', $accountId)
            ->where('oa.company_id >', 0)
            ->get()->getRowArray();
        return $row !== null;
    }

    private function getAssignmentForAttempt(int $attemptId): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('online_test_assignments oa')
            ->select('oa.*')
            ->join('online_test_attempts ota', 'ota.assignment_id = oa.id')
            ->where('ota.id', $attemptId)
            ->where('oa.company_id >', 0)
            ->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Strip correct_answer / explanation kalau test set show_result_to_candidate=0.
     */
    private function scrubResultForCandidate(array $result): array
    {
        $show = $result['test']['show_result_to_candidate'] ?? true;
        if (! $show && isset($result['answers'])) {
            foreach ($result['answers'] as &$a) {
                unset($a['correct_answer'], $a['explanation']);
                $a['is_correct'] = null;
            }
            unset($a);
            // Sembunyikan total skor juga
            $result['attempt']['total_score'] = null;
            $result['attempt']['percentage'] = null;
            $result['attempt']['result'] = $result['attempt']['result'] === 'REVIEW' ? 'PENDING' : 'PENDING';
        }
        return $result;
    }

    private function getBody(): array
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body)) $body = $this->request->getPost();
        return $body ?: [];
    }

    private function candidateAccount(): ?array
    {
        return $this->request->candidate ?? null;
    }

    protected function ok($data = null, string $message = 'OK', int $status = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function fail(string $message, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'status'  => 'error',
            'message' => $message,
        ]);
    }
}
