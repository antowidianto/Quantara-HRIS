<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Constants\ErrorCode;
use App\Constants\WebhookEvent;
use App\Libraries\Payroll\BonusCalculator;
use App\Libraries\Payroll\BonusApprovalException;
use App\Libraries\Payroll\BonusApprovalWorkflowService;
use App\Libraries\Payroll\BonusService;
use App\Libraries\Payroll\BonusWorkflowService;
use App\Repositories\Payroll\BonusRepository;
use Config\Services;

/**
 * BonusController — Bonus Engine endpoints (Sprint 16+).
 *
 * Endpoint:
 *   Schemes:
 *     GET    /bonus-schemes                    — list
 *     POST   /bonus-schemes                    — create
 *     GET    /bonus-schemes/{id}               — detail
 *     PUT    /bonus-schemes/{id}               — update (DRAFT only)
 *     DELETE /bonus-schemes/{id}               — soft-delete
 *     POST   /bonus-schemes/preview            — preview compute (no save)
 *
 *   Runs:
 *     GET    /bonus-runs                       — list (filter status/scheme/year)
 *     POST   /bonus-runs                       — create draft
 *     GET    /bonus-runs/{id}                  — detail + recipients summary
 *     PUT    /bonus-runs/{id}                  — update name/notes/pay_date (DRAFT)
 *     DELETE /bonus-runs/{id}                  — hard-delete (DRAFT only)
 *     POST   /bonus-runs/{id}/calculate        — eligibility + amount (DRAFT/CALCULATED)
 *     POST   /bonus-runs/{id}/submit           — CALCULATED → PENDING_APPROVAL
 *     POST   /bonus-runs/{id}/mark-paid        — APPROVED → PAID
 *     POST   /bonus-runs/{id}/cancel           — non-PAID → CANCELLED; pending workflow ikut ditutup
 *     GET    /bonus-runs/{id}/recipients       — list line items per karyawan
 *     PUT    /bonus-recipients/{id}            — override amount (DRAFT/CALCULATED)
 *
 * Authorization:
 *   - `bonus.view`    → list/show
 *   - `bonus.manage`  → create/edit scheme & run, calculate, override recipient
 *   - `bonus.approve` → approve & mark-paid
 *
 * Multi-tenant: scoped via TenantContext::applyScope.
 */
class BonusController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    private const VALID_KINDS = ['ANNUAL','KPI','MILESTONE','SIGNON','RETENTION','DISCRETIONARY'];
    private const VALID_TAX = ['GROSS','NET','NON_TAXABLE'];

    /* ──────────────────────────────────────────────────────────────────
     * SCHEMES
     * ──────────────────────────────────────────────────────────────── */

    public function listSchemes()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $kind = strtoupper((string) $this->request->getGet('kind'));
        $kind = in_array($kind, self::VALID_KINDS, true) ? $kind : null;
        $result = $this->bonusRepository()->paginateSchemes(
            $companyId, $page, $perPage, $kind, trim((string) $this->request->getGet('q')),
        );
        $rows = $result['rows'];

        // Decode eligibility JSON untuk client
        foreach ($rows as &$r) {
            $r['eligibility'] = $this->decodeJson($r['eligibility_json'] ?? null);
            unset($r['eligibility_json']);
        }
        unset($r);

        return $this->paginated($rows, $result['total'], $page, $perPage);
    }

    public function createScheme()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code'         => 'required|max_length[50]',
            'name'         => 'required|max_length[200]',
            'kind'         => 'permit_empty|in_list[' . implode(',', self::VALID_KINDS) . ']',
            'formula_type' => 'required|in_list[' . implode(',', BonusCalculator::FORMULA_TYPES) . ']',
        ]);
        if (! $valid) return null;

        $code = strtoupper(trim((string) $data['code']));

        $taxTreatment = strtoupper((string) ($data['tax_treatment'] ?? 'GROSS'));
        if (! in_array($taxTreatment, self::VALID_TAX, true)) $taxTreatment = 'GROSS';

        $eligibility = is_array($data['eligibility'] ?? null) ? $data['eligibility'] : [];

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => $companyId,
            'code'             => $code,
            'name'             => trim((string) $data['name']),
            'kind'             => strtoupper((string) ($data['kind'] ?? 'DISCRETIONARY')),
            'formula_type'     => $data['formula_type'],
            'formula_value'    => isset($data['formula_value']) ? (float) $data['formula_value'] : null,
            'formula_expr'     => $data['formula_expr'] ?? null,
            'cap_amount'       => isset($data['cap_amount']) ? (float) $data['cap_amount'] : null,
            'eligibility_json' => $eligibility ? json_encode($eligibility, JSON_UNESCAPED_UNICODE) : null,
            'tax_treatment'    => $taxTreatment,
            'description'      => $data['description'] ?? null,
            'is_active'        => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'created_by'       => $this->userId(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        try {
            $id = $this->bonusWorkflow()->createScheme($companyId, $insert);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::RESOURCE_CONFLICT);
        }

        Services::audit()->forModule('PAYROLL')->logCreate(
            'bonus_scheme', $id, $insert,
            "Buat skema bonus '{$insert['name']}' ({$insert['kind']}, {$insert['formula_type']})",
        );

        return $this->ok(['id' => $id], 'Skema bonus dibuat', 201);
    }

    public function showScheme($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadSchemeOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        $row['eligibility'] = $this->decodeJson($row['eligibility_json'] ?? null);
        unset($row['eligibility_json']);

        return $this->ok($row);
    }

    public function updateScheme($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadSchemeOrFail((int) $id, $companyId);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'name', 'kind', 'formula_type', 'formula_value', 'formula_expr',
            'cap_amount', 'tax_treatment', 'description', 'is_active',
        ]));
        if (isset($update['kind'])) {
            $update['kind'] = strtoupper((string) $update['kind']);
            if (! in_array($update['kind'], self::VALID_KINDS, true)) {
                return $this->fail('Kind tidak valid', 422);
            }
        }
        if (isset($update['formula_type'])
            && ! in_array($update['formula_type'], BonusCalculator::FORMULA_TYPES, true)) {
            return $this->fail('formula_type tidak valid', 422);
        }
        if (isset($update['tax_treatment'])) {
            $update['tax_treatment'] = strtoupper((string) $update['tax_treatment']);
            if (! in_array($update['tax_treatment'], self::VALID_TAX, true)) {
                return $this->fail('tax_treatment tidak valid', 422);
            }
        }
        if (isset($update['is_active'])) {
            $update['is_active'] = (int) (bool) $update['is_active'];
        }
        if (array_key_exists('eligibility', $data)) {
            $elig = is_array($data['eligibility']) ? $data['eligibility'] : null;
            $update['eligibility_json'] = $elig
                ? json_encode($elig, JSON_UNESCAPED_UNICODE)
                : null;
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        $this->bonusWorkflow()->updateScheme((int) $row['id'], $companyId, $update);

        Services::audit()->forModule('PAYROLL')->logUpdate(
            'bonus_scheme', (int) $row['id'], $row, $update,
            "Update skema bonus #{$row['id']}",
        );

        return $this->ok(null, 'Updated');
    }

    public function deleteScheme($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadSchemeOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        try {
            $this->bonusWorkflow()->deleteScheme((int) $row['id'], $companyId);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::RESOURCE_CONFLICT);
        }

        Services::audit()->forModule('PAYROLL')->logDelete(
            'bonus_scheme', (int) $row['id'], $row,
            "Hapus skema bonus #{$row['id']}",
        );

        return $this->ok(null, 'Deleted');
    }

    /**
     * POST /bonus-schemes/preview
     *
     * Compute pure tanpa save — admin bisa coba-coba formula sebelum
     * commit ke DB.
     *
     * Mendukung 2 mode body:
     *
     *   1) Lookup mode (UI):
     *      { bonus_scheme_id: int, employee_id: int, reference_date: "..." }
     *      → Backend load scheme + employee dari DB (multi-tenant safe).
     *
     *   2) Inline mode (ad-hoc / API testing):
     *      { scheme: {formula_type, formula_value, ...},
     *        employee: {basic_salary, join_date, ...},
     *        reference_date: "..." }
     *      → Pure compute tanpa DB lookup.
     *
     * Mode pertama lebih sering dipakai dari UI Bonus Scheme List;
     * mode kedua tetap dipertahankan untuk admin tooling & unit test.
     */
    public function previewScheme()
    {
        try {
            return $this->doPreviewScheme();
        } catch (\Throwable $e) {
            // Tangkap error fatal supaya frontend dapat JSON ramah, bukan
            // 500 HTML page yang bikin toast hanya bilang "Gagal preview"
            // tanpa info penyebab.
            log_message('error', 'Bonus preview failed: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());

            return $this->fail(
                'Preview gagal: ' . $e->getMessage(),
                500,
                ErrorCode::INTERNAL_ERROR,
            );
        }
    }

    private function doPreviewScheme()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $data = $this->getJsonInput();
        $ref  = (string) ($data['reference_date'] ?? date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
            return $this->fail('reference_date harus format YYYY-MM-DD', 422);
        }

        $scheme = is_array($data['scheme'] ?? null) ? $data['scheme'] : [];
        $emp    = is_array($data['employee'] ?? null) ? $data['employee'] : [];

        // Mode 1: lookup berdasarkan ID
        if (empty($scheme) && isset($data['bonus_scheme_id'])) {
            $schemeRow = $this->loadSchemeOrFail((int) $data['bonus_scheme_id'], $companyId);
            if (! $schemeRow) return $this->response;
            $schemeRow['eligibility'] = $this->decodeJson($schemeRow['eligibility_json'] ?? null);
            unset($schemeRow['eligibility_json']);
            $scheme = $schemeRow;
        }

        if (empty($emp) && isset($data['employee_id'])) {
            $empId = (int) $data['employee_id'];
            // SELECT * defensif — schema bisa beda antar tier (mis. kolom
            // `fixed_allowance` belum tentu ada di base employees migration;
            // ada deployment yang menyimpan fixed allowance via tabel
            // `employee_allowances` terpisah). Filter di PHP di bawah.
            $empRow = $this->bonusRepository()->findEmployee($empId, $companyId);
            if (! $empRow) {
                return $this->fail("Employee #{$empId} tidak ditemukan", 404,
                    ErrorCode::RESOURCE_NOT_FOUND);
            }
            // Fallback kalau kolom fixed_allowance belum ada di schema.
            if (! array_key_exists('fixed_allowance', $empRow)
                || $empRow['fixed_allowance'] === null) {
                $empRow['fixed_allowance'] = 0;
            }
            // KPI score opsional — kalau ada tabel kpi_scores aktif, ambil
            // score terbaru ≤ reference_date. Kalau tidak ada, default 1.0.
            $empRow['kpi_score'] = $this->bonusRepository()->latestKpiScore($empId, $companyId, $ref);
            $emp = $empRow;
        }

        if (! isset($scheme['formula_type'])
            || ! in_array($scheme['formula_type'], BonusCalculator::FORMULA_TYPES, true)) {
            return $this->fail(
                'scheme.formula_type wajib & valid. Kirim `bonus_scheme_id` (referensi skema tersimpan) '
                    . 'atau `scheme: {...}` (inline) di body.',
                422,
            );
        }
        if (empty($emp)) {
            return $this->fail(
                'Data karyawan wajib. Kirim `employee_id` atau `employee: {...}` di body.',
                422,
            );
        }

        // Eligibility check (kalau ada eligibility config) supaya UI bisa
        // tampilkan alasan tidak eligible sebelum reveal angka.
        $eligibility = is_array($scheme['eligibility'] ?? null) ? $scheme['eligibility'] : [];
        $check = BonusCalculator::checkEligibility($eligibility, $emp, $ref);

        if (! $check['eligible']) {
            return $this->ok([
                'eligible' => false,
                'reason'   => $check['reason'],
                'amount'   => 0,
                'capped'   => false,
                'breakdown'=> null,
            ]);
        }

        $result = BonusCalculator::compute($scheme, $emp, $ref);
        $result['eligible'] = true;
        $result['reason']   = '';

        return $this->ok($result);
    }

    /**
     * Cari KPI score karyawan terbaru ≤ referenceDate.
     * Defensive: kalau tabel `kpi_scores` belum ada di schema (multi-tenant
     * tier rendah), fallback ke 1.0 supaya formula KPI_SCORE tetap jalan.
     */
    /* ──────────────────────────────────────────────────────────────────
     * RUNS
     * ──────────────────────────────────────────────────────────────── */

    public function listRuns()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $schemeId = (int) $this->request->getGet('bonus_scheme_id');
        $year = (int) $this->request->getGet('period_year');
        $result = $this->bonusRepository()->paginateRuns(
            $companyId, $page, $perPage, (string) $this->request->getGet('status'),
            $schemeId > 0 ? $schemeId : null, $year > 0 ? $year : null,
        );
        return $this->paginated($result['rows'], $result['total'], $page, $perPage);
    }

    public function createRun()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'bonus_scheme_id' => 'required|integer',
            'name'            => 'required|max_length[200]',
            'reference_date'  => 'required|valid_date',
            'pay_date'        => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $scheme = $this->bonusRepository()->findScheme((int) $data['bonus_scheme_id'], $companyId);
        if (! $scheme) {
            return $this->fail('Skema tidak ditemukan / lintas company', 404);
        }
        if ((int) $scheme['is_active'] !== 1) {
            return $this->fail('Skema tidak aktif', 422);
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'        => $companyId,
            'bonus_scheme_id'   => (int) $scheme['id'],
            'payroll_period_id' => isset($data['payroll_period_id']) && (int) $data['payroll_period_id'] > 0
                ? (int) $data['payroll_period_id'] : null,
            'name'              => trim((string) $data['name']),
            'reference_date'    => $data['reference_date'],
            'pay_date'          => $data['pay_date'],
            'period_year'       => (int) date('Y', strtotime($data['pay_date'])),
            'status'            => 'DRAFT',
            'total_amount'      => 0,
            'recipient_count'   => 0,
            'notes'             => $data['notes'] ?? null,
            'created_by'        => $this->userId(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        // Sprint 6 — Cost Center: header-level untuk semua recipient.
        // Validate kalau eksplisit di-set; kalau tidak, biarkan null —
        // recipient-level resolve ke cost_center_id masing-masing karyawan
        // saat calculate (pattern di BonusService::calculateRun).
        if (array_key_exists('cost_center_id', $data)) {
            $explicitCc = ! empty($data['cost_center_id']) ? (int) $data['cost_center_id'] : null;
            if ($explicitCc) {
                try {
                    Services::costCenter()->assertValidForCompany($explicitCc, $companyId);
                    $insert['cost_center_id'] = $explicitCc;
                } catch (\Throwable $e) {
                    return $this->fail($e->getMessage(), 422);
                }
            }
        }

        try {
            $id = $this->bonusWorkflow()->createRun($companyId, (int) $scheme['id'], $insert);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->logCreate(
            'bonus_run', $id, $insert,
            "Buat bonus run '{$insert['name']}' (scheme: {$scheme['code']})",
        );

        return $this->ok(['id' => $id, 'status' => 'DRAFT'],
            'Bonus run dibuat. Lakukan POST /bonus-runs/{id}/calculate untuk hitung.', 201);
    }

    public function showRun($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        $row['scheme'] = $this->bonusRepository()->findScheme((int) $row['bonus_scheme_id'], $companyId);

        return $this->ok($row);
    }

    public function updateRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa diedit', 422,
                ErrorCode::APPROVAL_INVALID_STATE);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'name', 'reference_date', 'pay_date', 'payroll_period_id', 'notes',
        ]));
        if (! empty($update['pay_date'])) {
            $update['period_year'] = (int) date('Y', strtotime($update['pay_date']));
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $this->bonusWorkflow()->updateRun((int) $row['id'], $companyId, $update);

        return $this->ok(null, 'Updated');
    }

    public function deleteRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa dihapus permanen', 422);
        }

        $this->bonusWorkflow()->deleteRun((int) $row['id'], $companyId);

        Services::audit()->forModule('PAYROLL')->logDelete(
            'bonus_run', (int) $row['id'], $row,
            "Hapus bonus run draft #{$row['id']}",
        );

        return $this->ok(null, 'Deleted');
    }

    public function calculateRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;

        $this->bonusWorkflow()->markCalculator((int) $row['id'], $companyId, $this->userId());

        try {
            $result = (new BonusService())->calculateRun((int) $row['id'], $companyId);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422,
                ErrorCode::APPROVAL_INVALID_STATE);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.calculate',
            entity: 'bonus_run',
            entityId: (int) $row['id'],
            after: $result,
            description: "Calculate bonus run #{$row['id']}: {$result['recipient_count']} recipient, total Rp "
                . number_format((float) $result['total_amount'], 0, ',', '.'),
            severity: 'NOTICE',
        );

        // Sprint 16+ — webhook outbound `bonus.run_calculated`
        $this->dispatchWebhook(
            WebhookEvent::BONUS_RUN_CALCULATED,
            (int) $row['company_id'],
            [
                'bonus_run_id'    => (int) $row['id'],
                'bonus_scheme_id' => (int) $row['bonus_scheme_id'],
                'name'            => $row['name'] ?? null,
                'pay_date'        => $row['pay_date'] ?? null,
                'recipient_count' => (int) $result['recipient_count'],
                'total_amount'    => (float) $result['total_amount'],
                'skipped'         => (int) $result['skipped'],
                'calculated_by'   => $this->userId(),
                'calculated_at'   => date('c'),
            ],
        );

        return $this->ok($result, 'Run dihitung');
    }

    public function submitRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        try {
            $result = (new BonusApprovalWorkflowService())->submit(
                (int) $row['id'], $companyId, $this->userId()
            );
        } catch (BonusApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }

        // Cek recipient_count > 0. Kalau 0 berarti calculate sebelumnya
        // gagal (mis. SQL error) atau eligibility filter tidak match
        // siapapun. Approve run kosong tidak masuk akal — bonus tidak
        // akan muncul ke karyawan dan total amount = 0.
        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.submit_approval',
            entity: 'bonus_run',
            entityId: (int) $row['id'],
            after: ['approval_request_id' => $result['approval']['request_id'] ?? null],
            description: "Ajukan approval bonus run #{$row['id']} (total Rp "
                . number_format((float) $row['total_amount'], 0, ',', '.') . ')',
            severity: 'CRITICAL',
        );

        return $this->ok($result, 'Bonus run diajukan ke approval bertingkat');
    }

    public function markRunPaid($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;
        try {
            $transition = $this->bonusWorkflow()->markPaid((int) $row['id'], $companyId, $this->userId());
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::APPROVAL_INVALID_STATE);
        }
        $now = (string) $transition['transitioned_at'];

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.mark_paid',
            entity: 'bonus_run',
            entityId: (int) $row['id'],
            description: "Mark bonus run #{$row['id']} sebagai PAID",
            severity: 'CRITICAL',
        );

        $this->dispatchWebhook(
            WebhookEvent::BONUS_RUN_PAID,
            (int) $row['company_id'],
            [
                'bonus_run_id'    => (int) $row['id'],
                'name'            => $row['name'] ?? null,
                'recipient_count' => (int) $row['recipient_count'],
                'total_amount'    => (float) $row['total_amount'],
                'paid_by'         => $this->userId(),
                'paid_at'         => $now,
            ],
        );

        return $this->ok(null, 'Bonus run ditandai sudah dibayar');
    }

    public function cancelRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $row = $this->loadRunOrFail((int) $id, $companyId);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        try {
            $this->bonusWorkflow()->cancelRun((int) $row['id'], $companyId, $this->userId(), $reason);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::APPROVAL_INVALID_STATE);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.cancel',
            entity: 'bonus_run',
            entityId: (int) $row['id'],
            after: ['reason' => $reason],
            description: "Cancel bonus run #{$row['id']}: {$reason}",
            severity: 'WARNING',
        );

        return $this->ok(null, 'Bonus run dibatalkan');
    }

    public function listRecipients($runId = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $run = $this->loadRunOrFail((int) $runId, $companyId);
        if (! $run) return $this->response;

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $result = $this->bonusRepository()->paginateRecipients(
            (int) $run['id'], $companyId, $page, $perPage,
        );
        $rows = $result['rows'];

        // Decode breakdown JSON
        foreach ($rows as &$r) {
            $r['breakdown'] = $this->decodeJson($r['breakdown_json'] ?? null);
            unset($r['breakdown_json']);
        }
        unset($r);

        return $this->paginated($rows, $result['total'], $page, $perPage);
    }

    /**
     * POST /bonus-runs/{id}/recipients/include-all — bulk include semua
     * recipient yang sebelumnya di-exclude. Berguna kalau HR tidak sengaja
     * exclude all atau setelah review ingin reset ke include semua.
     *
     * Hanya berlaku untuk run DRAFT/CALCULATED.
     */
    /**
     * Include all + reset override + recompute amount dari scheme formula.
     *
     * Pakai brute-force update (tanpa WHERE is_excluded=1) supaya idempotent
     * dan tahan terhadap data inconsistency (mis. amount=0 + is_excluded=0
     * yang mestinya in-status tapi nominalnya kosong karena override
     * hilang). Sekaligus reset `amount_overridden=0` dan amount = re-compute
     * dari scheme — efeknya sama dengan re-calculate untuk recipient existing,
     * tapi tanpa menghapus row (preserve audit history).
     */
    public function includeAllRecipients($runId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $run = $this->loadRunOrFail((int) $runId, $companyId);
        if (! $run) return $this->response;

        try {
            $result = $this->bonusWorkflow()->includeAllRecipients((int) $run['id'], $companyId);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::APPROVAL_INVALID_STATE);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.include_all',
            entity: 'bonus_run',
            entityId: (int) $run['id'],
            after: $result,
            description: "Include all + recompute: {$result['affected']} recipient di-reset",
            severity: 'NOTICE',
        );

        return $this->ok(
            $result,
            "Berhasil reset {$result['affected']} recipient (include + clear override)",
        );
    }

    /** PUT /bonus-recipients/{id} — override amount + reason */
    public function overrideRecipient($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        try {
            $result = $this->bonusWorkflow()->overrideRecipient(
                (int) $id,
                $companyId,
                $this->getJsonInput(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 404, ErrorCode::RESOURCE_NOT_FOUND);
        } catch (\DomainException $exception) {
            return $this->fail($exception->getMessage(), 422, ErrorCode::APPROVAL_INVALID_STATE);
        }

        $recipient = $result['recipient'];
        Services::audit()->forModule('PAYROLL')->logUpdate(
            'bonus_recipient',
            (int) $recipient['id'],
            $recipient,
            $result['update'],
            "Override bonus recipient #{$recipient['id']} ({$recipient['employee_name']})",
        );

        return $this->ok(null, 'Updated');
    }

    /* ──────────────────────────────────────────────────────────────────
     * Helpers
     * ──────────────────────────────────────────────────────────────── */

    private function canView(): bool
    {
        $t = $this->tenant();

        return $t->isSuperAdmin()
            || $t->hasPermission('bonus.view')
            || $t->hasPermission('bonus.manage')
            || $t->hasPermission('bonus.approve');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();

        return $t->isSuperAdmin() || $t->hasPermission('bonus.manage');
    }

    private function canApprove(): bool
    {
        $t = $this->tenant();

        return $t->isSuperAdmin() || $t->hasPermission('bonus.approve');
    }

    private function loadSchemeOrFail(int $id, int $companyId): ?array
    {
        $row = $this->bonusRepository()->findScheme($id, $companyId);
        if (! $row) {
            $this->response = $this->fail('Skema tidak ditemukan', 404,
                ErrorCode::RESOURCE_NOT_FOUND);
            return null;
        }

        return $row;
    }

    private function loadRunOrFail(int $id, int $companyId): ?array
    {
        $row = $this->bonusRepository()->findRun($id, $companyId);
        if (! $row) {
            $this->response = $this->fail('Bonus run tidak ditemukan', 404,
                ErrorCode::RESOURCE_NOT_FOUND);
            return null;
        }

        return $row;
    }

    private function bonusRepository(): BonusRepository
    {
        return new BonusRepository();
    }

    private function bonusWorkflow(): BonusWorkflowService
    {
        return new BonusWorkflowService();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sprint 20+ — GET /employees/{id}/bonus-history
     *
     * Riwayat bonus seorang karyawan untuk tab di profile / HR analytics.
     * Authorization:
     *   - User boleh lihat history-nya sendiri (kalau employee_id == self)
     *   - HR dengan permission `bonus.view` boleh lihat siapa saja
     *
     * Query params:
     *   - limit (default 50, max 200)
     *   - include_pending (default false; true → include DRAFT/CALCULATED)
     */
    public function employeeHistory($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        if ($employeeId <= 0) return $this->fail('Invalid employee id', 400);

        $tenant = $this->tenant();
        $isSelf = (int) $tenant->getEmployeeId() === $employeeId;

        // Authorization: self atau bonus.view
        if (! $isSelf && ! $this->canView()) {
            return $this->fail('Forbidden', 403, ErrorCode::PERMISSION_DENIED);
        }

        $limit = min(200, max(1, (int) ($this->request->getGet('limit') ?? 50)));
        $includePending = filter_var(
            $this->request->getGet('include_pending') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        // Self user tidak boleh lihat pending — confidential sebelum approved
        if ($isSelf && ! $this->canView()) {
            $includePending = false;
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $service = new \App\Libraries\Payroll\BonusService();
        $history = $service->bonusHistoryForEmployee($employeeId, $companyId, [
            'limit'           => $limit,
            'include_pending' => $includePending,
        ]);

        // Aggregate stats untuk header
        $totalAmount = array_sum(array_map(fn ($r) => (float) $r['amount'], $history));
        $byKind = [];
        foreach ($history as $r) {
            $kind = $r['scheme_kind'] ?? 'OTHER';
            $byKind[$kind] = ($byKind[$kind] ?? 0) + (float) $r['amount'];
        }

        // Diagnostic info — kalau hasil kosong, kasih data konkret supaya
        // user/HR bisa langsung tahu kenapa: total recipient row di DB
        // (lintas semua status, termasuk excluded), break down by status,
        // company_id yang sedang di-filter. Sangat membantu debugging
        // "kok tidak muncul" tanpa harus buka SQL/log.
        $diagnostic = $this->bonusRepository()->historyDiagnostic($employeeId, $companyId);

        return $this->ok([
            'history' => $history,
            'stats'   => [
                'total_amount'   => $totalAmount,
                'count'          => count($history),
                'by_kind'        => $byKind,
                'include_pending'=> $includePending,
            ],
            'diagnostic' => $diagnostic,
        ]);
    }

    /**
     * Diagnostic info untuk halaman bonus history — dipakai UI ketika hasil
     * utama kosong supaya user bisa identifikasi cause cepat.
     *
     * @param int      $employeeId Employee yang sedang di-query
     * @param int|null $filterCompanyId Company yang di-apply ke query
     *                                  (null untuk super admin = tidak filter)
     * @param int      $userCompanyId Tenant company dari user login (untuk cross-check)
     */
}
