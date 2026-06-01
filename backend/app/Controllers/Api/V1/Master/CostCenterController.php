<?php

namespace App\Controllers\Api\V1\Master;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Master\CostCenterService;
use Config\Services;

/**
 * CostCenterController — REST API untuk master Cost Center & budget.
 *
 * Authorization:
 *   - `cost_center.view`   → list/show + tree + budget vs actual
 *   - `cost_center.manage` → CRUD master + budget + backfill
 *
 * Endpoint:
 *   GET    /cost-centers                    — list flat dengan filter
 *   GET    /cost-centers/tree               — hirarki tree
 *   GET    /cost-centers/picker             — list ringan untuk dropdown
 *   GET    /cost-centers/(:num)             — detail + budget summary
 *   POST   /cost-centers                    — create
 *   PUT    /cost-centers/(:num)             — update
 *   DELETE /cost-centers/(:num)             — soft-delete
 *   POST   /cost-centers/backfill-employees — bulk apply default ke karyawan
 *
 *   GET    /cost-centers/(:num)/budgets               — list budget periodisasi
 *   POST   /cost-centers/(:num)/budgets               — set budget per periode
 *   PUT    /cost-center-budgets/(:num)                — update single budget row
 *   DELETE /cost-center-budgets/(:num)                — hapus
 *
 *   GET    /cost-centers/(:num)/budget-vs-actual?year=2026
 *                                              — laporan budget vs actual
 *   GET    /cost-centers/(:num)/actuals?from=&to=
 *                                              — breakdown actual aja
 */
class CostCenterController extends ApiController
{
    // ────────────────────────────────────────────────────────────────
    // List / Tree / Picker
    // ────────────────────────────────────────────────────────────────

    public function index()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 100)));
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $active = $this->request->getGet('is_active');

        $b = $db->table('cost_centers cc')
            ->select('cc.*, p.code AS parent_code, p.name AS parent_name, '
                . 'm.full_name AS manager_name')
            ->join('cost_centers p', 'p.id = cc.parent_id', 'left')
            ->join('employees m', 'm.id = cc.manager_employee_id', 'left')
            ->where('cc.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'cc.company_id');

        if ($q !== '') {
            $b->groupStart()
                ->like('cc.code', $q)
                ->orLike('cc.name', $q)
                ->orLike('cc.description', $q)
                ->groupEnd();
        }
        if ($active !== null && $active !== '') {
            $b->where('cc.is_active', (int) (bool) $active);
        }

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('cc.sort_order', 'ASC')
            ->orderBy('cc.code', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function tree()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $includeInactive = (int) $this->request->getGet('include_inactive') === 1;
        $tree = Services::costCenter()->tree($companyId, $includeInactive);
        return $this->ok($tree);
    }

    /**
     * Lightweight picker untuk dropdown frontend. Return id+code+name+parent_code
     * supaya picker bisa nampilkan path "PARENT / CHILD".
     */
    public function picker()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $b = $db->table('cost_centers cc')
            ->select('cc.id, cc.code, cc.name, cc.parent_id, cc.is_active, '
                . 'p.code AS parent_code, p.name AS parent_name')
            ->join('cost_centers p', 'p.id = cc.parent_id', 'left')
            ->where('cc.deleted_at IS NULL')
            ->where('cc.is_active', 1);
        $this->tenant()->applyScope($b, 'cc.company_id');
        $rows = $b->orderBy('cc.code', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        $row['parent']  = null;
        if (! empty($row['parent_id'])) {
            $row['parent'] = $db->table('cost_centers')->select('id, code, name')
                ->where('id', (int) $row['parent_id'])->get()->getRowArray();
        }
        $row['manager'] = null;
        if (! empty($row['manager_employee_id'])) {
            $row['manager'] = $db->table('employees')->select('id, full_name, employee_number')
                ->where('id', (int) $row['manager_employee_id'])->get()->getRowArray();
        }
        // Cache children count
        $row['children_count'] = $db->table('cost_centers')
            ->where('parent_id', (int) $row['id'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        // Headcount aktif
        if ($db->tableExists('employees')) {
            $cols = $db->getFieldNames('employees');
            if (in_array('cost_center_id', $cols, true)) {
                $row['employee_count'] = $db->table('employees')
                    ->where('cost_center_id', (int) $row['id'])
                    ->where('is_active', 1)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
            }
        }
        return $this->ok($row);
    }

    // ────────────────────────────────────────────────────────────────
    // Create / Update / Delete
    // ────────────────────────────────────────────────────────────────

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh cost_center.manage', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|max_length[32]',
            'name' => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $codeErrors = CostCenterService::validateCostCenterCodePure((string) $data['code']);
        if (! empty($codeErrors)) {
            return $this->fail(['message' => 'Code invalid', 'errors' => $codeErrors], 422);
        }

        $db = \Config\Database::connect();
        $code = strtoupper(trim((string) $data['code']));

        // Unik per company
        $exists = $db->table('cost_centers')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail("Code '{$code}' sudah dipakai di company ini", 422);
        }

        // Validate parent
        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId) {
            $parent = $db->table('cost_centers')
                ->where('id', $parentId)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if (! $parent || (int) $parent['company_id'] !== $companyId) {
                return $this->fail('Parent cost center tidak valid', 422);
            }
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => $companyId,
            'parent_id'          => $parentId,
            'code'               => $code,
            'name'               => trim((string) $data['name']),
            'description'        => $data['description'] ?? null,
            'default_branch_id'     => ! empty($data['default_branch_id']) ? (int) $data['default_branch_id'] : null,
            'default_department_id' => ! empty($data['default_department_id']) ? (int) $data['default_department_id'] : null,
            'manager_employee_id'   => ! empty($data['manager_employee_id']) ? (int) $data['manager_employee_id'] : null,
            'budget_yearly'      => (float) ($data['budget_yearly'] ?? 0),
            'is_active'          => (int) (bool) ($data['is_active'] ?? 1),
            'sort_order'         => (int) ($data['sort_order'] ?? 0),
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $this->userId(),
            'updated_by'         => $this->userId(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('cost_centers')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()->forModule('FINANCE')->logCreate('cost_center', $id, $insert,
            "Cost center {$code} - {$insert['name']} dibuat");

        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'name', 'description', 'parent_id',
            'default_branch_id', 'default_department_id', 'manager_employee_id',
            'budget_yearly', 'is_active', 'sort_order', 'notes',
        ]));

        // Coerce ints
        foreach (['parent_id','default_branch_id','default_department_id','manager_employee_id'] as $k) {
            if (array_key_exists($k, $update)) {
                $update[$k] = ! empty($update[$k]) ? (int) $update[$k] : null;
            }
        }
        if (array_key_exists('is_active', $update)) {
            $update['is_active'] = (int) (bool) $update['is_active'];
        }
        if (array_key_exists('budget_yearly', $update)) {
            $update['budget_yearly'] = (float) $update['budget_yearly'];
        }

        // Validate parent (no cycle)
        if (array_key_exists('parent_id', $update) && $update['parent_id']) {
            if ((int) $update['parent_id'] === (int) $id) {
                return $this->fail('Parent tidak boleh diri sendiri', 422);
            }
            $db = \Config\Database::connect();
            $allNodes = $db->table('cost_centers')
                ->select('id, parent_id')
                ->where('company_id', (int) $row['company_id'])
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();
            // Cek apakah parent baru adalah descendant dari node ini → cycle
            if (CostCenterService::isDescendantPure((int) $update['parent_id'], (int) $id, $allNodes)) {
                return $this->fail('Parent tidak boleh descendant — akan membuat cycle', 422);
            }
        }

        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');

        \Config\Database::connect()->table('cost_centers')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('FINANCE')->logUpdate('cost_center', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        if ($row['code'] === 'DEFAULT') {
            return $this->fail('Cost center DEFAULT tidak boleh dihapus', 422);
        }

        $db = \Config\Database::connect();
        // Cek apakah ada child cost center
        $childCount = $db->table('cost_centers')
            ->where('parent_id', (int) $id)
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($childCount > 0) {
            return $this->fail("Cost center ini punya {$childCount} child — pindahkan/hapus child dulu", 422);
        }
        // Cek apakah ada karyawan masih di-tag
        $cols = $db->getFieldNames('employees');
        if (in_array('cost_center_id', $cols, true)) {
            $empCount = $db->table('employees')
                ->where('cost_center_id', (int) $id)
                ->where('deleted_at IS NULL')
                ->countAllResults();
            if ($empCount > 0) {
                return $this->fail("Cost center ini masih dipakai {$empCount} karyawan — re-tag dulu", 422);
            }
        }

        $db->table('cost_centers')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()->forModule('FINANCE')->logDelete('cost_center', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    /**
     * Bulk apply default cost_center_id ke karyawan yang belum di-tag.
     * Idempotent — yang sudah di-tag tidak dioverwrite.
     */
    public function backfillEmployees()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if (! $companyId) return $this->fail('Company context required', 400);
        $result = Services::costCenter()->backfillEmployees($companyId);
        Services::audit()->forModule('FINANCE')->log(
            action: 'cost_center.backfill_employees',
            entity: 'employees',
            entityId: 0,
            description: "Backfill cost_center_id ke karyawan: {$result['updated']} di-update, {$result['skipped']} skip",
            severity: 'NOTICE',
            metadata: $result,
        );
        return $this->ok($result, 'Backfill selesai');
    }

    // ────────────────────────────────────────────────────────────────
    // Budget per periode
    // ────────────────────────────────────────────────────────────────

    public function listBudgets($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        $rows = $db->table('cost_center_budgets')
            ->where('cost_center_id', (int) $id)
            ->where('period_year', $year)
            ->orderBy('period_type', 'ASC')
            ->orderBy('period_no', 'ASC')
            ->orderBy('category', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function setBudgets($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $items = isset($data['budgets']) && is_array($data['budgets']) ? $data['budgets'] : [];
        if (empty($items)) {
            return $this->fail('budgets array wajib (minimal 1 item)', 422);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;
        foreach ($items as $i => $item) {
            $year = (int) ($item['period_year'] ?? 0);
            $type = strtoupper((string) ($item['period_type'] ?? 'ANNUAL'));
            if (! in_array($type, CostCenterService::PERIOD_TYPES, true)) {
                return $this->fail("Item #{$i}: period_type tidak valid", 422);
            }
            $no = isset($item['period_no']) && $item['period_no'] !== '' ? (int) $item['period_no'] : null;
            if ($type === 'QUARTERLY' && ($no < 1 || $no > 4)) {
                return $this->fail("Item #{$i}: period_no untuk QUARTERLY harus 1-4", 422);
            }
            if ($type === 'MONTHLY' && ($no < 1 || $no > 12)) {
                return $this->fail("Item #{$i}: period_no untuk MONTHLY harus 1-12", 422);
            }
            $amount = (float) ($item['amount'] ?? 0);
            if ($amount < 0) {
                return $this->fail("Item #{$i}: amount tidak boleh negatif", 422);
            }
            $category = ! empty($item['category']) ? strtoupper((string) $item['category']) : null;
            if ($category && ! in_array($category, CostCenterService::BUDGET_CATEGORIES, true)) {
                return $this->fail("Item #{$i}: category tidak dikenal", 422);
            }

            // Upsert by uniq key (cost_center_id, year, type, no, category)
            $existing = $db->table('cost_center_budgets')
                ->where('cost_center_id', (int) $id)
                ->where('period_year', $year)
                ->where('period_type', $type)
                ->where('period_no', $no, true) // null-safe
                ->where('category', $category, true)
                ->get()->getRowArray();

            if ($existing) {
                $db->table('cost_center_budgets')->where('id', (int) $existing['id'])->update([
                    'amount'     => $amount,
                    'notes'      => $item['notes'] ?? $existing['notes'],
                    'updated_by' => $this->userId(),
                    'updated_at' => $now,
                ]);
                $updated++;
            } else {
                $db->table('cost_center_budgets')->insert([
                    'company_id'     => (int) $row['company_id'],
                    'cost_center_id' => (int) $id,
                    'period_year'    => $year,
                    'period_type'    => $type,
                    'period_no'      => $no,
                    'amount'         => $amount,
                    'category'       => $category,
                    'notes'          => $item['notes'] ?? null,
                    'created_by'     => $this->userId(),
                    'updated_by'     => $this->userId(),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $inserted++;
            }
        }

        // Update cache budget_yearly = sum semua ANNUAL kategori = null tahun ini
        $sum = $db->table('cost_center_budgets')
            ->select('SUM(amount) AS total')
            ->where('cost_center_id', (int) $id)
            ->where('period_year', date('Y'))
            ->get()->getRowArray();
        $db->table('cost_centers')->where('id', (int) $id)->update([
            'budget_yearly' => (float) ($sum['total'] ?? 0),
            'updated_at'    => $now,
        ]);

        Services::audit()->forModule('FINANCE')->log(
            action: 'cost_center.set_budgets',
            entity: 'cost_center_budgets',
            entityId: (int) $id,
            description: "Budget cost center {$row['code']} di-set: {$inserted} inserted, {$updated} updated",
            severity: 'NOTICE',
        );
        return $this->ok(['inserted' => $inserted, 'updated' => $updated]);
    }

    public function deleteBudget($budgetId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('cost_center_budgets')->where('id', (int) $budgetId)->get()->getRowArray();
        if (! $row) return $this->fail('Budget row not found', 404);
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('cost_center_budgets')->where('id', (int) $budgetId)->delete();
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Reports — Budget vs Actual
    // ────────────────────────────────────────────────────────────────

    public function budgetVsActual($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        try {
            $report = Services::costCenter()->budgetVsActual((int) $id, $year);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($report);
    }

    public function actuals($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $from = (string) ($this->request->getGet('from') ?? sprintf('%04d-01-01', date('Y')));
        $to   = (string) ($this->request->getGet('to')   ?? sprintf('%04d-12-31', date('Y')));
        $report = Services::costCenter()->actualsBreakdown((int) $id, $from, $to);
        return $this->ok($report);
    }

    /**
     * GET /cost-centers/{id}/committed?year=2026
     *
     * Sprint 6 — committed spending. Estimasi biaya yang sudah di-pesan
     * tapi belum direalisasi (MPP APPROVED, karyawan baru join tanpa
     * payslip, bonus run CALCULATED, reimbursement belum PAID).
     *
     * Pakai untuk dashboard "Forecast vs Budget" supaya Finance lihat
     * proyeksi sisa tahun, bukan menunggu payroll cycle untuk sadar
     * over-budget.
     */
    public function committed($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        $report = Services::costCenter()->committedSpending((int) $id, $year);
        return $this->ok($report);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('cost_center.view')
            || $t->hasPermission('cost_center.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('cost_center.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('cost_centers')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Cost center not found', 404);
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