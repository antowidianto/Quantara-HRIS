<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Constants\ErrorCode;
use App\Constants\WebhookEvent;
use App\Libraries\Payroll\BonusCalculator;
use App\Libraries\Payroll\BonusService;
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
 *     POST   /bonus-runs/{id}/approve          — CALCULATED → APPROVED
 *     POST   /bonus-runs/{id}/mark-paid        — APPROVED → PAID
 *     POST   /bonus-runs/{id}/cancel           — DRAFT/CALCULATED/APPROVED → CANCELLED
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
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $b = $db->table('bonus_schemes')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($b);
        if ($kind = (string) $this->request->getGet('kind')) {
            if (in_array($kind, self::VALID_KINDS, true)) $b->where('kind', $kind);
        }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $b->groupStart()->like('code', $q)->orLike('name', $q)->groupEnd();
        }
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Decode eligibility JSON untuk client
        foreach ($rows as &$r) {
            $r['eligibility'] = $this->decodeJson($r['eligibility_json'] ?? null);
            unset($r['eligibility_json']);
        }
        unset($r);

        return $this->paginated($rows, $total, $page, $perPage);
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

        $db = \Config\Database::connect();
        $code = strtoupper(trim((string) $data['code']));

        // Cek duplikat code per company
        $exists = $db->table('bonus_schemes')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail("Code '{$code}' sudah dipakai di company ini",
                422, ErrorCode::RESOURCE_CONFLICT);
        }

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
        $db->table('bonus_schemes')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()->forModule('PAYROLL')->logCreate(
            'bonus_scheme', $id, $insert,
            "Buat skema bonus '{$insert['name']}' ({$insert['kind']}, {$insert['formula_type']})",
        );

        return $this->ok(['id' => $id], 'Skema bonus dibuat', 201);
    }

    public function showScheme($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadSchemeOrFail((int) $id);
        if (! $row) return $this->response;
        $row['eligibility'] = $this->decodeJson($row['eligibility_json'] ?? null);
        unset($row['eligibility_json']);

        return $this->ok($row);
    }

    public function updateScheme($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadSchemeOrFail((int) $id);
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
        \Config\Database::connect()->table('bonus_schemes')
            ->where('id', (int) $row['id'])->update($update);

        Services::audit()->forModule('PAYROLL')->logUpdate(
            'bonus_scheme', (int) $row['id'], $row, $update,
            "Update skema bonus #{$row['id']}",
        );

        return $this->ok(null, 'Updated');
    }

    public function deleteScheme($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadSchemeOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        // Cek ada run aktif yang refer scheme ini?
        $hasActiveRun = $db->table('bonus_runs')
            ->where('bonus_scheme_id', (int) $row['id'])
            ->whereIn('status', ['DRAFT', 'CALCULATED', 'APPROVED'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($hasActiveRun > 0) {
            return $this->fail(
                'Skema masih dipakai di bonus run aktif. Cancel run dulu.',
                422, ErrorCode::RESOURCE_CONFLICT,
            );
        }

        $db->table('bonus_schemes')->where('id', (int) $row['id'])->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

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
        $data = $this->getJsonInput();
        $ref  = (string) ($data['reference_date'] ?? date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
            return $this->fail('reference_date harus format YYYY-MM-DD', 422);
        }

        $scheme = is_array($data['scheme'] ?? null) ? $data['scheme'] : [];
        $emp    = is_array($data['employee'] ?? null) ? $data['employee'] : [];

        $db = \Config\Database::connect();

        // Mode 1: lookup berdasarkan ID
        if (empty($scheme) && isset($data['bonus_scheme_id'])) {
            $schemeRow = $this->loadSchemeOrFail((int) $data['bonus_scheme_id']);
            if (! $schemeRow) return $this->response;
            $schemeRow['eligibility'] = $this->decodeJson($schemeRow['eligibility_json'] ?? null);
            unset($schemeRow['eligibility_json']);
            $scheme = $schemeRow;
        }

        if (empty($emp) && isset($data['employee_id'])) {
            $empId = (int) $data['employee_id'];
            $tenant = $this->tenant();
            // SELECT * defensif — schema bisa beda antar tier (mis. kolom
            // `fixed_allowance` belum tentu ada di base employees migration;
            // ada deployment yang menyimpan fixed allowance via tabel
            // `employee_allowances` terpisah). Filter di PHP di bawah.
            $empRow = $db->table('employees')
                ->where('id', $empId)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if (! $empRow) {
                return $this->fail("Employee #{$empId} tidak ditemukan", 404,
                    ErrorCode::RESOURCE_NOT_FOUND);
            }
            if (! $tenant->isSuperAdmin() && (int) $empRow['company_id'] !== $tenant->getCompanyId()) {
                return $this->fail('Employee lintas company', 403);
            }
            // Fallback kalau kolom fixed_allowance belum ada di schema.
            if (! array_key_exists('fixed_allowance', $empRow)
                || $empRow['fixed_allowance'] === null) {
                $empRow['fixed_allowance'] = 0;
            }
            // KPI score opsional — kalau ada tabel kpi_scores aktif, ambil
            // score terbaru ≤ reference_date. Kalau tidak ada, default 1.0.
            $empRow['kpi_score'] = $this->lookupKpiScore($empId, $ref);
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
    private function lookupKpiScore(int $employeeId, string $referenceDate): float
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('kpi_scores')) return 1.0;
            $row = $db->table('kpi_scores')
                ->where('employee_id', $employeeId)
                ->where('period_end <=', $referenceDate)
                ->orderBy('period_end', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            if (! $row || ! isset($row['score'])) return 1.0;
            return max(0.0, min(2.0, (float) $row['score']));
        } catch (\Throwable $e) {
            return 1.0;
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     * RUNS
     * ──────────────────────────────────────────────────────────────── */

    public function listRuns()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $b = $db->table('bonus_runs r')
            ->select('r.*, s.code AS scheme_code, s.name AS scheme_name, s.kind AS scheme_kind')
            ->join('bonus_schemes s', 's.id = r.bonus_scheme_id', 'left')
            ->where('r.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'r.company_id');

        if ($status = (string) $this->request->getGet('status')) {
            $b->where('r.status', $status);
        }
        if ($schemeId = (int) $this->request->getGet('bonus_scheme_id')) {
            $b->where('r.bonus_scheme_id', $schemeId);
        }
        if ($year = (int) $this->request->getGet('period_year')) {
            $b->where('r.period_year', $year);
        }

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('r.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
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

        $db = \Config\Database::connect();
        $scheme = $db->table('bonus_schemes')
            ->where('id', (int) $data['bonus_scheme_id'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $scheme || (int) $scheme['company_id'] !== $companyId) {
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
        if (in_array('cost_center_id', $db->getFieldNames('bonus_runs'), true)) {
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

        $db->table('bonus_runs')->insert($insert);
        $id = (int) $db->insertID();

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
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;

        // Tambahkan info scheme + payroll_period
        $db = \Config\Database::connect();
        $scheme = $db->table('bonus_schemes')->where('id', (int) $row['bonus_scheme_id'])
            ->get()->getRowArray();
        $row['scheme'] = $scheme;

        return $this->ok($row);
    }

    public function updateRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadRunOrFail((int) $id);
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

        \Config\Database::connect()->table('bonus_runs')
            ->where('id', (int) $row['id'])->update($update);

        return $this->ok(null, 'Updated');
    }

    public function deleteRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya DRAFT yang bisa dihapus permanen', 422);
        }

        $db = \Config\Database::connect();
        $db->table('bonus_recipients')->where('bonus_run_id', (int) $row['id'])->delete();
        $db->table('bonus_runs')->where('id', (int) $row['id'])->delete();

        Services::audit()->forModule('PAYROLL')->logDelete(
            'bonus_run', (int) $row['id'], $row,
            "Hapus bonus run draft #{$row['id']}",
        );

        return $this->ok(null, 'Deleted');
    }

    public function calculateRun($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;

        // Set calculator user_id sebelum service jalan supaya audit trail masuk
        \Config\Database::connect()->table('bonus_runs')->where('id', (int) $row['id'])
            ->update(['calculated_by' => $this->userId()]);

        try {
            $result = (new BonusService())->calculateRun((int) $row['id']);
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

    public function approveRun($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'CALCULATED') {
            return $this->fail('Hanya CALCULATED yang bisa di-approve', 422,
                ErrorCode::APPROVAL_INVALID_STATE);
        }

        // Cek recipient_count > 0. Kalau 0 berarti calculate sebelumnya
        // gagal (mis. SQL error) atau eligibility filter tidak match
        // siapapun. Approve run kosong tidak masuk akal — bonus tidak
        // akan muncul ke karyawan dan total amount = 0.
        $db = \Config\Database::connect();
        $actualCount = $db->table('bonus_recipients')
            ->where('bonus_run_id', (int) $row['id'])
            ->where('is_excluded', 0)
            ->countAllResults();
        if ($actualCount === 0) {
            return $this->fail(
                'Run ini belum punya recipient (0 karyawan eligible). '
                    . 'Klik "Re-calculate" dulu, atau cek scheme.eligibility '
                    . '(employment_status, min_tenure_months, dll). Kalau setelah '
                    . 'calculate masih 0, kemungkinan tidak ada karyawan yang lolos filter.',
                422,
                ErrorCode::APPROVAL_INVALID_STATE,
            );
        }

        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('bonus_runs')->where('id', (int) $row['id'])->update([
            'status'      => 'APPROVED',
            'approved_by' => $this->userId(),
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.approve',
            entity: 'bonus_run',
            entityId: (int) $row['id'],
            description: "Approve bonus run #{$row['id']} (total Rp "
                . number_format((float) $row['total_amount'], 0, ',', '.') . ')',
            severity: 'CRITICAL',
        );

        $this->dispatchWebhook(
            WebhookEvent::BONUS_RUN_APPROVED,
            (int) $row['company_id'],
            [
                'bonus_run_id'    => (int) $row['id'],
                'name'            => $row['name'] ?? null,
                'recipient_count' => (int) $row['recipient_count'],
                'total_amount'    => (float) $row['total_amount'],
                'approved_by'     => $this->userId(),
                'approved_at'     => $now,
            ],
        );

        return $this->ok(null, 'Bonus run disetujui');
    }

    public function markRunPaid($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'APPROVED') {
            return $this->fail('Hanya APPROVED yang bisa di-mark PAID', 422,
                ErrorCode::APPROVAL_INVALID_STATE);
        }

        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('bonus_runs')->where('id', (int) $row['id'])->update([
            'status'     => 'PAID',
            'paid_by'    => $this->userId(),
            'paid_at'    => $now,
            'updated_at' => $now,
        ]);

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
        $row = $this->loadRunOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] === 'PAID') {
            return $this->fail('Tidak bisa cancel run yang sudah PAID', 422);
        }
        if ($row['status'] === 'CANCELLED') {
            return $this->fail('Sudah dibatalkan', 422);
        }

        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) {
            return $this->fail('Alasan minimal 5 karakter', 422);
        }

        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('bonus_runs')->where('id', (int) $row['id'])->update([
            'status'              => 'CANCELLED',
            'cancelled_by'        => $this->userId(),
            'cancelled_at'        => $now,
            'cancellation_reason' => $reason,
            'updated_at'          => $now,
        ]);

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
        $run = $this->loadRunOrFail((int) $runId);
        if (! $run) return $this->response;

        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));

        $b = $db->table('bonus_recipients')->where('bonus_run_id', (int) $run['id']);
        $total = $b->countAllResults(false);
        $rows = $b->orderBy('amount', 'DESC')
            ->orderBy('employee_name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Decode breakdown JSON
        foreach ($rows as &$r) {
            $r['breakdown'] = $this->decodeJson($r['breakdown_json'] ?? null);
            unset($r['breakdown_json']);
        }
        unset($r);

        return $this->paginated($rows, $total, $page, $perPage);
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
        $run = $this->loadRunOrFail((int) $runId);
        if (! $run) return $this->response;
        if (! in_array($run['status'], ['DRAFT', 'CALCULATED'], true)) {
            return $this->fail('Run sudah locked, recipient tidak boleh diubah',
                422, ErrorCode::APPROVAL_INVALID_STATE);
        }

        $db = \Config\Database::connect();

        // Snapshot count BEFORE untuk reporting
        $beforeExcluded = (int) $db->table('bonus_recipients')
            ->where('bonus_run_id', (int) $run['id'])
            ->where('is_excluded', 1)
            ->countAllResults();
        $beforeOverridden = (int) $db->table('bonus_recipients')
            ->where('bonus_run_id', (int) $run['id'])
            ->where('amount_overridden', 1)
            ->countAllResults();
        $totalRecipients = (int) $db->table('bonus_recipients')
            ->where('bonus_run_id', (int) $run['id'])
            ->countAllResults();

        if ($totalRecipients === 0) {
            return $this->fail(
                'Run ini belum punya recipient sama sekali. Klik Calculate dulu.',
                422,
            );
        }

        // Load scheme untuk recompute
        $scheme = $db->table('bonus_schemes')
            ->where('id', (int) $run['bonus_scheme_id'])
            ->get()->getRowArray();
        if (! $scheme) {
            return $this->fail('Scheme tidak ditemukan', 404);
        }

        $referenceDate = (string) $run['reference_date'];

        // Loop tiap recipient → re-compute amount dari scheme (kalau ada
        // employee data masih valid). Reset is_excluded=0, amount_overridden=0.
        $rows = $db->table('bonus_recipients')
            ->where('bonus_run_id', (int) $run['id'])
            ->get()->getResultArray();

        $now = date('Y-m-d H:i:s');
        $totalRecomputed = 0;

        foreach ($rows as $rec) {
            // Lookup employee fresh (basic_salary mungkin sudah berubah)
            $emp = $db->table('employees')
                ->where('id', (int) $rec['employee_id'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if (! $emp) continue;
            // Default fixed_allowance = 0 kalau kolom belum ada
            if (! array_key_exists('fixed_allowance', $emp) || $emp['fixed_allowance'] === null) {
                $emp['fixed_allowance'] = 0;
            }

            $compute = BonusCalculator::compute($scheme, $emp, $referenceDate);
            $newAmount = (float) $compute['amount'];

            $db->table('bonus_recipients')
                ->where('id', (int) $rec['id'])
                ->update([
                    'is_excluded'       => 0,
                    'exclude_reason'    => null,
                    'amount_overridden' => 0,
                    'override_reason'   => null,
                    'amount'            => $newAmount,
                    'amount_capped'     => $compute['capped'] ? 1 : 0,
                    'breakdown_json'    => json_encode($compute['breakdown'], JSON_UNESCAPED_UNICODE),
                    'updated_at'        => $now,
                ]);
            $totalRecomputed++;
        }

        // Recompute aggregate run
        $sum = $db->table('bonus_recipients')
            ->selectSum('amount', 'total')
            ->selectCount('id', 'cnt')
            ->where('bonus_run_id', (int) $run['id'])
            ->where('is_excluded', 0)
            ->get()->getRowArray();
        $db->table('bonus_runs')->where('id', (int) $run['id'])->update([
            'total_amount'    => (float) ($sum['total'] ?? 0),
            'recipient_count' => (int) ($sum['cnt'] ?? 0),
            'updated_at'      => $now,
        ]);

        Services::audit()->forModule('PAYROLL')->log(
            action: 'bonus.include_all',
            entity: 'bonus_run',
            entityId: (int) $run['id'],
            after: [
                'before_excluded'   => $beforeExcluded,
                'before_overridden' => $beforeOverridden,
                'recomputed'        => $totalRecomputed,
                'new_total'         => (float) ($sum['total'] ?? 0),
            ],
            description: "Include all + recompute: {$totalRecomputed} recipient di-reset (sebelumnya: "
                . "{$beforeExcluded} excluded, {$beforeOverridden} overridden)",
            severity: 'NOTICE',
        );

        return $this->ok([
            'affected'          => $totalRecomputed,
            'before_excluded'   => $beforeExcluded,
            'before_overridden' => $beforeOverridden,
            'total_included'    => (int) ($sum['cnt'] ?? 0),
            'total_amount'      => (float) ($sum['total'] ?? 0),
        ], "Berhasil reset {$totalRecomputed} recipient (include + clear override)");
    }

    /** PUT /bonus-recipients/{id} — override amount + reason */
    public function overrideRecipient($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);

        $db = \Config\Database::connect();
        $rec = $db->table('bonus_recipients')->where('id', (int) $id)->get()->getRowArray();
        if (! $rec) return $this->fail('Recipient tidak ditemukan', 404);

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $rec['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Cek run masih editable (DRAFT/CALCULATED)
        $run = $db->table('bonus_runs')->where('id', (int) $rec['bonus_run_id'])->get()->getRowArray();
        if (! $run || ! in_array($run['status'], ['DRAFT', 'CALCULATED'], true)) {
            return $this->fail('Run sudah locked, recipient tidak boleh diubah',
                422, ErrorCode::APPROVAL_INVALID_STATE);
        }

        $data = $this->getJsonInput();
        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if (array_key_exists('amount', $data)) {
            $newAmount = (float) $data['amount'];
            if ($newAmount < 0) return $this->fail('Amount tidak boleh negatif', 422);

            // Cek cap_amount di scheme
            $scheme = $db->table('bonus_schemes')->where('id', (int) $run['bonus_scheme_id'])
                ->get()->getRowArray();
            if ($scheme && $scheme['cap_amount'] !== null && $newAmount > (float) $scheme['cap_amount']) {
                return $this->fail(
                    'Amount melebihi cap_amount skema (Rp '
                        . number_format((float) $scheme['cap_amount'], 0, ',', '.') . ')',
                    422,
                );
            }
            $update['amount'] = $newAmount;
            $update['amount_overridden'] = 1;
            $update['override_reason'] = trim((string) ($data['reason'] ?? ''));
        }
        if (array_key_exists('is_excluded', $data)) {
            $update['is_excluded'] = (int) (bool) $data['is_excluded'];
            if ((int) $update['is_excluded'] === 1) {
                $update['exclude_reason'] = trim((string) ($data['exclude_reason'] ?? ''));
                $update['amount'] = 0;
            }
        }

        $db->table('bonus_recipients')->where('id', (int) $rec['id'])->update($update);

        // Recompute aggregate run
        $sum = $db->table('bonus_recipients')
            ->selectSum('amount', 'total')
            ->selectCount('id', 'cnt')
            ->where('bonus_run_id', (int) $run['id'])
            ->where('is_excluded', 0)
            ->get()->getRowArray();
        $db->table('bonus_runs')->where('id', (int) $run['id'])->update([
            'total_amount'    => (float) ($sum['total'] ?? 0),
            'recipient_count' => (int) ($sum['cnt'] ?? 0),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        Services::audit()->forModule('PAYROLL')->logUpdate(
            'bonus_recipient', (int) $rec['id'], $rec, $update,
            "Override bonus recipient #{$rec['id']} ({$rec['employee_name']})",
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

    private function loadSchemeOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('bonus_schemes')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Skema tidak ditemukan', 404,
                ErrorCode::RESOURCE_NOT_FOUND);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }

        return $row;
    }

    private function loadRunOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('bonus_runs')->where('id', $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Bonus run tidak ditemukan', 404,
                ErrorCode::RESOURCE_NOT_FOUND);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }

        return $row;
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

        $companyId = $tenant->isSuperAdmin() ? null : $tenant->getCompanyId();

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
        $diagnostic = $this->bonusHistoryDiagnostic(
            $employeeId,
            $companyId,
            $tenant->getCompanyId(),
        );

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
    private function bonusHistoryDiagnostic(int $employeeId, ?int $filterCompanyId, int $userCompanyId): array
    {
        $db = \Config\Database::connect();

        // Total recipient row untuk employee ini, lintas semua status & company
        $allRecipients = $db->table('bonus_recipients r')
            ->select('r.id, r.is_excluded, r.bonus_run_id, run.status AS run_status, run.company_id AS run_company_id')
            ->join('bonus_runs run', 'run.id = r.bonus_run_id')
            ->where('r.employee_id', $employeeId)
            ->where('run.deleted_at IS NULL')
            ->get()->getResultArray();

        $byStatus = ['DRAFT' => 0, 'CALCULATED' => 0, 'APPROVED' => 0, 'PAID' => 0, 'CANCELLED' => 0];
        $excludedCount = 0;
        $companyIds = [];
        foreach ($allRecipients as $r) {
            $st = (string) $r['run_status'];
            if (isset($byStatus[$st])) $byStatus[$st]++;
            if ((int) $r['is_excluded'] === 1) $excludedCount++;
            $companyIds[(int) $r['run_company_id']] = true;
        }

        // Cek employee record exists di company user
        $emp = $db->table('employees')
            ->select('id, company_id, full_name, employee_number')
            ->where('id', $employeeId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();

        return [
            'employee_id'           => $employeeId,
            'employee_company_id'   => $emp ? (int) $emp['company_id'] : null,
            'employee_exists'       => $emp !== null,
            'user_tenant_company_id'=> $userCompanyId,
            'filter_company_id'     => $filterCompanyId,                  // null = super admin
            'cross_company'         => $emp && (int) $emp['company_id'] !== $userCompanyId,
            'total_recipients_db'   => count($allRecipients),
            'excluded_count'        => $excludedCount,
            'recipients_by_status'  => $byStatus,
            'distinct_run_companies'=> array_keys($companyIds),
        ];
    }
}
