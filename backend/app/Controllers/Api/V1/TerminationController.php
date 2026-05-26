<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * TerminationController — REST API untuk PHK formal workflow
 * (Sprint 4 / OFF-5).
 *
 * Endpoint:
 *   GET    /terminations                          — list (filter)
 *   GET    /terminations/options                  — types & reasons (untuk dropdown)
 *   POST   /terminations                          — create DRAFT
 *   GET    /terminations/(:num)                   — detail + preview severance
 *   PUT    /terminations/(:num)                   — update DRAFT
 *   DELETE /terminations/(:num)                   — soft-delete (hanya DRAFT)
 *   POST   /terminations/(:num)/submit            — DRAFT → next stage
 *   POST   /terminations/(:num)/bipartite         — record bipartite outcome
 *   POST   /terminations/(:num)/approve           — HR Director / BOD approve
 *   POST   /terminations/(:num)/reject            — HR Director / BOD reject
 *   POST   /terminations/(:num)/cancel            — Cancel sebelum executed
 *   POST   /terminations/(:num)/execute           — APPROVED → EXECUTED
 *   GET    /terminations/(:num)/letter            — Render PHK letter HTML/PDF
 *
 * Authorization:
 *   - View:    `termination.view`
 *   - Manage:  `termination.manage` (HR initiate)
 *   - Approve: `termination.approve` (HR Director / BOD)
 *   - Execute: `termination.execute` (HR final step)
 */
class TerminationController extends ApiController
{
    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $filters = [
            'company_id'       => $this->tenant()->getCompanyId() ?: null,
            'employee_id'      => (int) ($this->request->getGet('employee_id') ?? 0) ?: null,
            'status'           => trim((string) ($this->request->getGet('status') ?? '')) ?: null,
            'termination_type' => trim((string) ($this->request->getGet('termination_type') ?? '')) ?: null,
            'reason_code'      => trim((string) ($this->request->getGet('reason_code') ?? '')) ?: null,
            'date_from'        => trim((string) ($this->request->getGet('date_from') ?? '')) ?: null,
            'date_to'          => trim((string) ($this->request->getGet('date_to') ?? '')) ?: null,
            'page'             => max(1, (int) ($this->request->getGet('page') ?? 1)),
            'per_page'         => min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20))),
        ];
        $result = Services::termination()->listRequests(array_filter($filters, static fn ($v) => $v !== null));

        if (isset($result['total'])) {
            return $this->paginated(
                $result['rows'], (int) $result['total'],
                (int) $result['page'], (int) $result['per_page'],
            );
        }
        return $this->ok($result['rows'] ?? []);
    }

    /**
     * GET /terminations/options — kasih daftar termination types & reason codes
     * untuk dropdown UI.
     */
    public function options()
    {
        $reasons = [];
        foreach (\App\Libraries\SeveranceCalculator::REASONS as $code => $cfg) {
            $reasons[] = [
                'code'                => $code,
                'label'               => $cfg['label'],
                'pesangon_multiplier' => $cfg['pesangon_mul'],
                'upmk_multiplier'     => $cfg['upmk_mul'],
                'requires_separation' => ! empty($cfg['requires_separation']),
            ];
        }
        return $this->ok([
            'types'    => \App\Libraries\TerminationService::TYPES,
            'statuses' => \App\Libraries\TerminationService::STATUSES,
            'reasons'  => $reasons,
        ]);
    }

    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::termination()->load((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Sertakan severance preview supaya UI bisa show estimasi
        try {
            $row['severance_preview'] = Services::termination()->previewSeverance($row);
            $row['requires_bipartite'] = Services::termination()->requiresBipartite($row);
            $row['requires_bod'] = Services::termination()->requiresBodApproval($row);
        } catch (\Throwable $e) {
            $row['severance_preview'] = null;
        }

        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh termination.manage', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id'              => 'required|integer',
            'termination_type'         => 'required',
            'reason_code'              => 'required|max_length[64]',
            'planned_termination_date' => 'required|valid_date[Y-m-d]',
        ]);
        if (! $valid) return null;

        try {
            $row = Services::termination()->create($data, $this->userId());
            return $this->ok($row, "PHK draft dibuat: {$row['reference_number']}", 201);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::termination()->update((int) $id, $this->getJsonInput(), $this->userId());
            return $this->ok($row, 'PHK draft updated');
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
            $row = Services::termination()->load($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa dihapus, lainnya cancel saja', 422);
        }
        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('termination_requests')->where('id', $id)->update([
            'deleted_at' => $now, 'updated_at' => $now,
        ]);
        return $this->ok(null, 'Deleted');
    }

    public function submit($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::termination()->submit((int) $id, $this->userId());
            return $this->ok($row, "Status: {$row['status']}");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function bipartite($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'outcome' => 'required|in_list[AGREED,DISAGREED,PENDING_TRIPARTITE]',
            'minutes' => 'required|min_length[20]|max_length[5000]',
        ]);
        if (! $valid) return null;

        try {
            $row = Services::termination()->recordBipartite(
                (int) $id, $this->userId(), $valid['outcome'], $valid['minutes']
            );
            return $this->ok($row, "Bipartite outcome tercatat: {$valid['outcome']}");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /terminations/{id}/approve
     *
     * Body: { stage: HR_DIRECTOR | BOD, note: string }
     */
    public function approve($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden — butuh termination.approve', 403);
        $data = $this->getJsonInput();
        $stage = (string) ($data['stage'] ?? '');
        if (! in_array($stage, ['HR_DIRECTOR', 'BOD'], true)) {
            return $this->fail("stage harus HR_DIRECTOR atau BOD", 422);
        }
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        try {
            $row = Services::termination()->approveStep((int) $id, $this->userId(), $stage, $note);
            return $this->ok($row, "Approved di stage {$stage}, status: {$row['status']}");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function reject($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'stage'  => 'required|in_list[HR_DIRECTOR,BOD]',
            'reason' => 'required|min_length[10]|max_length[2000]',
        ]);
        if (! $valid) return null;

        try {
            $row = Services::termination()->reject(
                (int) $id, $this->userId(), $valid['stage'], $valid['reason']
            );
            return $this->ok($row, "PHK ditolak di stage {$valid['stage']}");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function cancel($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|min_length[10]|max_length[2000]',
        ]);
        if (! $valid) return null;
        try {
            $row = Services::termination()->cancel((int) $id, $this->userId(), $valid['reason']);
            return $this->ok($row, 'PHK dibatalkan');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /terminations/{id}/execute — eksekusi final, generate semua artifact.
     */
    public function execute($id = null)
    {
        if (! $this->canExecute()) return $this->fail('Forbidden — butuh termination.execute', 403);
        try {
            $row = Services::termination()->execute((int) $id, $this->userId());
            return $this->ok($row, "PHK #{$row['reference_number']} EXECUTED");
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * GET /terminations/(:num)/letter?format=html|pdf
     * Render surat PHK. PDF default; HTML untuk preview/iframe.
     */
    public function letter($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        try {
            $row = Services::termination()->load((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (empty($row['phk_letter_id'])) {
            return $this->fail(
                'PHK letter belum di-generate. Execute PHK request dulu.',
                422
            );
        }

        $db = \Config\Database::connect();
        $contract = $db->table('generated_contracts')->where('id', (int) $row['phk_letter_id'])
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $contract) return $this->fail('Generated contract tidak ditemukan', 404);

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        $html = (string) ($contract['rendered_html'] ?? '');

        if ($format === 'html') {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody($html);
        }

        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        $filename = 'PHK_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['reference_number']) . '.pdf';
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($output);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('termination.view')
            || $t->hasPermission('termination.manage')
            || $t->hasPermission('termination.approve')
            || $t->hasPermission('termination.execute');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('termination.manage');
    }

    private function canApprove(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('termination.approve');
    }

    private function canExecute(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('termination.execute');
    }
}