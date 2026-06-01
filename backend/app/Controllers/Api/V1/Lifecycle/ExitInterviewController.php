<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * ExitInterviewController — REST API exit interview (Sprint 4 / OFF-1).
 *
 * Endpoint:
 *   GET    /exit-interviews              — list (HR + filter)
 *   GET    /exit-interviews/options      — modes, rehire options, primary reasons
 *   GET    /exit-interviews/analytics    — aggregate dashboard
 *   POST   /exit-interviews              — create (link to resignation/termination)
 *   GET    /exit-interviews/(:num)       — detail
 *   PUT    /exit-interviews/(:num)       — update DRAFT
 *   DELETE /exit-interviews/(:num)       — soft-delete
 *   POST   /exit-interviews/(:num)/complete — DRAFT → COMPLETED
 *   POST   /exit-interviews/(:num)/skip      — DRAFT → SKIPPED
 *
 * Authorization:
 *   - View: `exit_interview.view`
 *   - Manage: `exit_interview.manage`
 */
class ExitInterviewController extends ApiController
{
    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $filters = [
            'company_id'     => $this->tenant()->getCompanyId() ?: null,
            'employee_id'    => (int) ($this->request->getGet('employee_id') ?? 0) ?: null,
            'status'         => trim((string) ($this->request->getGet('status') ?? '')) ?: null,
            'would_rehire'   => trim((string) ($this->request->getGet('would_rehire') ?? '')) ?: null,
            'primary_reason' => trim((string) ($this->request->getGet('primary_reason') ?? '')) ?: null,
            'date_from'      => trim((string) ($this->request->getGet('date_from') ?? '')) ?: null,
            'date_to'        => trim((string) ($this->request->getGet('date_to') ?? '')) ?: null,
            'page'           => max(1, (int) ($this->request->getGet('page') ?? 1)),
            'per_page'       => min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20))),
        ];
        $result = Services::exitInterview()->listInterviews(array_filter($filters, static fn ($v) => $v !== null));

        if (isset($result['total'])) {
            return $this->paginated(
                $result['rows'], (int) $result['total'],
                (int) $result['page'], (int) $result['per_page'],
            );
        }
        return $this->ok($result['rows'] ?? []);
    }

    /**
     * GET /exit-interviews/options — dropdown values untuk frontend.
     */
    public function options()
    {
        $reasons = [];
        foreach (\App\Libraries\Lifecycle\ExitInterviewService::PRIMARY_REASON_OPTIONS as $code => $label) {
            $reasons[] = ['code' => $code, 'label' => $label];
        }
        return $this->ok([
            'modes'            => \App\Libraries\Lifecycle\ExitInterviewService::MODES,
            'statuses'         => \App\Libraries\Lifecycle\ExitInterviewService::STATUSES,
            'rehire_options'   => \App\Libraries\Lifecycle\ExitInterviewService::REHIRE_OPTIONS,
            'primary_reasons'  => $reasons,
        ]);
    }

    /**
     * GET /exit-interviews/analytics — aggregate untuk HR dashboard.
     */
    public function analytics()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = (int) $this->tenant()->getCompanyId();
        if ($companyId <= 0 && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Tenant context tidak ditemukan', 422);
        }
        $from = trim((string) ($this->request->getGet('date_from') ?? '')) ?: null;
        $to   = trim((string) ($this->request->getGet('date_to') ?? '')) ?: null;

        $analytics = Services::exitInterview()->analytics($companyId, $from, $to);

        // Compute eNPS dari completed records
        try {
            $db = \Config\Database::connect();
            $b = $db->table('exit_interviews')
                ->select('recommend_score')
                ->where('company_id', $companyId)
                ->where('status', 'COMPLETED')
                ->where('recommend_score IS NOT NULL')
                ->where('deleted_at IS NULL');
            if ($from) $b->where('completed_at >=', $from . ' 00:00:00');
            if ($to)   $b->where('completed_at <=', $to . ' 23:59:59');
            $rows = $b->get()->getResultArray();
            $scores = array_map(static fn ($r) => $r['recommend_score'] ?? null, $rows);
            $analytics['enps'] = Services::exitInterview()->computeNps($scores);
        } catch (\Throwable $e) {
            $analytics['enps'] = null;
        }

        return $this->ok($analytics);
    }

    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::exitInterview()->load((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh exit_interview.manage', 403);
        $data = $this->getJsonInput();
        try {
            $row = Services::exitInterview()->create($data, $this->userId());
            return $this->ok($row, 'Exit interview dibuat (DRAFT)', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::exitInterview()->update((int) $id, $this->getJsonInput(), $this->userId());
            return $this->ok($row, 'Exit interview updated');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $row = Services::exitInterview()->load($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('exit_interviews')->where('id', $id)->update([
            'deleted_at' => $now, 'updated_at' => $now,
        ]);
        return $this->ok(null, 'Deleted');
    }

    public function complete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::exitInterview()->complete((int) $id, $this->userId());
            return $this->ok($row, 'Exit interview completed');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function skip($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|min_length[5]|max_length[1000]',
        ]);
        if (! $valid) return null;
        try {
            $row = Services::exitInterview()->skip((int) $id, $this->userId(), $valid['reason']);
            return $this->ok($row, 'Exit interview di-skip');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('exit_interview.view')
            || $t->hasPermission('exit_interview.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('exit_interview.manage');
    }
}