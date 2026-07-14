<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Models\Employee\EmployeeModel;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * AllowanceController — kelola allowance_types, allowance_rates, dan
 * employee_allowance_assignments.
 *
 * Endpoint utama:
 *   GET  /allowance-types                         daftar tipe allowance
 *   POST /allowance-types                         tambah tipe (HR/super-admin)
 *   PUT  /allowance-types/{id}
 *   DEL  /allowance-types/{id}
 *
 *   GET  /allowance-rates                         daftar tarif (filter: type_id)
 *   POST /allowance-rates                         tambah tarif per grade
 *   PUT  /allowance-rates/{id}
 *   DEL  /allowance-rates/{id}
 *
 *   GET  /employee-allowances/{employee_id}       lihat assignment per karyawan
 *   POST /employee-allowances/{employee_id}/recompute  recompute snapshot
 *   PUT  /employee-allowances/{id}                override manual (HR)
 *   POST /allowances/recompute-company            recompute seluruh karyawan dlm company
 *
 * Filter scope: super-admin dapat akses semua, user lain dibatasi
 * `company_id` mereka. Operasi tulis butuh permission `payroll.manage`.
 */
class AllowanceController extends ApiController
{
    // ─── ALLOWANCE TYPES ────────────────────────────────────────────

    public function listTypes()
    {
        $db = \Config\Database::connect();
        $b = $db->table('allowance_types t')
            ->select('t.*, c.code AS payroll_component_code')
            ->join('payroll_components c', 'c.id = t.payroll_component_id AND c.company_id = t.company_id', 'left')
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 't.company_id');
        $rows = $b->orderBy('t.name', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createType()
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|alpha_dash|max_length[32]',
            'name' => 'required|max_length[100]',
            'category' => 'permit_empty|in_list[ALLOWANCE,TRANSPORT,MEAL,HOUSING,OTHER]',
            'default_threshold_km' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'is_distance_based' => 'permit_empty|in_list[0,1]',
            'is_taxable' => 'permit_empty|in_list[0,1]',
            'is_taxable_natura' => 'permit_empty|in_list[0,1]',
            'natura_tax_threshold' => 'permit_empty|numeric|greater_than_equal_to[0]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        if (! empty($data['payroll_component_id']) && ! $this->tenantReferenceExists($db, 'payroll_components', (int) $data['payroll_component_id'], $companyId)) {
            return $this->fail('Komponen payroll tidak valid', 422, 'INVALID_PAYROLL_COMPONENT');
        }
        $exists = $db->table('allowance_types')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) {
            return $this->fail('Kode allowance sudah dipakai', 422, 'DUPLICATE_CODE');
        }

        $isNatura = (int) ($data['is_taxable_natura'] ?? 0);
        $insert = [
            'company_id' => $companyId,
            'code'       => $data['code'],
            'name'       => $data['name'],
            'description'=> $data['description'] ?? null,
            'category'   => $data['category'] ?? 'TRANSPORT',
            'is_taxable' => $isNatura === 1 ? 1 : (isset($data['is_taxable']) ? (int) $data['is_taxable'] : 1),
            'is_taxable_natura' => $isNatura,
            'natura_tax_threshold' => isset($data['natura_tax_threshold']) && $data['natura_tax_threshold'] !== ''
                ? (float) $data['natura_tax_threshold'] : null,
            'is_distance_based' => isset($data['is_distance_based']) ? (int) $data['is_distance_based'] : 1,
            'default_threshold_km' => (float) ($data['default_threshold_km'] ?? 30),
            'payroll_component_id' => $data['payroll_component_id'] ?? null,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->table('allowance_types')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('PAYROLL')
            ->logCreate('allowance_type', $id, $insert,
                "Menambah tipe tunjangan: {$insert['name']} ({$insert['code']})");
        return $this->ok(['id' => $id], 'Tipe allowance dibuat', 201);
    }

    public function updateType($id = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('allowance_types')->where('id', $id)->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $existing) return $this->fail('Tidak ditemukan', 404);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'permit_empty|max_length[100]',
            'category' => 'permit_empty|in_list[ALLOWANCE,TRANSPORT,MEAL,HOUSING,OTHER]',
            'default_threshold_km' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'is_distance_based' => 'permit_empty|in_list[0,1]',
            'is_taxable' => 'permit_empty|in_list[0,1]',
            'is_taxable_natura' => 'permit_empty|in_list[0,1]',
            'natura_tax_threshold' => 'permit_empty|numeric|greater_than_equal_to[0]',
        ]);
        if (! $valid) return null;
        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') return $this->fail('Nama wajib', 422);
        if (! empty($data['payroll_component_id']) && ! $this->tenantReferenceExists($db, 'payroll_components', (int) $data['payroll_component_id'], $companyId)) {
            return $this->fail('Komponen payroll tidak valid', 422, 'INVALID_PAYROLL_COMPONENT');
        }
        $upd = array_intersect_key($data, array_flip([
            'name','description','category','is_taxable','is_distance_based',
            'default_threshold_km','payroll_component_id','is_active',
            'is_taxable_natura','natura_tax_threshold',
        ]));
        if (($upd['is_taxable_natura'] ?? $existing['is_taxable_natura'] ?? 0) == 1) $upd['is_taxable'] = 1;
        if (array_key_exists('natura_tax_threshold', $upd) && $upd['natura_tax_threshold'] === '') $upd['natura_tax_threshold'] = null;
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $db->table('allowance_types')->where('id', $id)->where('company_id', $companyId)->update($upd);

        Services::audit()
            ->forModule('PAYROLL')
            ->logUpdate('allowance_type', $id, array_intersect_key($existing, $upd), $upd,
                "Memperbarui tipe tunjangan: {$existing['name']}");

        // Setelah update tipe, recompute semua karyawan dalam company.
        Services::allowance()->recomputeCompany($companyId);

        return $this->ok(null, 'Tipe allowance diperbarui');
    }

    public function deleteType($id = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('allowance_types')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Tidak ditemukan', 404);
        $db->table('allowance_types')->where('id', $id)->where('company_id', $companyId)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()
            ->forModule('PAYROLL')
            ->logDelete('allowance_type', $id, $existing, "Menghapus tipe tunjangan {$existing['name']}");
        return $this->ok(null, 'Tipe allowance dihapus');
    }

    // ─── ALLOWANCE RATES ────────────────────────────────────────────

    public function listRates()
    {
        $db = \Config\Database::connect();
        $typeId = (int) ($this->request->getGet('allowance_type_id') ?? 0);
        $b = $db->table('allowance_rates r')
            ->select('r.*, t.name AS type_name, t.code AS type_code, t.category AS type_category, t.is_distance_based, jg.name AS grade_name, jg.code AS grade_code, br.name AS branch_name')
            ->join('allowance_types t', 't.id = r.allowance_type_id AND t.company_id = r.company_id')
            ->join('job_grades jg', 'jg.id = r.job_grade_id AND jg.company_id = r.company_id', 'left')
            ->join('branches br', 'br.id = r.branch_id AND br.company_id = r.company_id', 'left')
            ->where('t.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'r.company_id');
        if ($typeId) $b->where('r.allowance_type_id', $typeId);
        $rows = $b->orderBy('t.name', 'ASC')
            ->orderBy('jg.level', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createRate()
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'allowance_type_id' => 'required|integer',
            'threshold_km' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'rate_below'   => 'permit_empty|numeric|greater_than_equal_to[0]',
            'rate_above'   => 'required|numeric|greater_than_equal_to[0]',
            'effective_from' => 'permit_empty|valid_date',
            'effective_to'   => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        // Pastikan type milik company yang sama
        $type = $db->table('allowance_types')->where('id', $data['allowance_type_id'])->where('company_id', $companyId)->get()->getRowArray();
        if (! $type) {
            return $this->fail('Tipe allowance tidak valid', 422);
        }
        $invalidScope = $this->invalidRateScope($db, $data, $companyId);
        if ($invalidScope !== null) return $this->fail("Referensi {$invalidScope} tidak valid", 422, 'INVALID_SCOPE');
        if (! empty($data['effective_from']) && ! empty($data['effective_to']) && $data['effective_to'] < $data['effective_from']) {
            return $this->fail('effective_to harus setelah atau sama dengan effective_from', 422, 'INVALID_DATE_RANGE');
        }

        $insert = [
            'company_id'        => $companyId,
            'allowance_type_id' => (int) $data['allowance_type_id'],
            'job_grade_id'      => isset($data['job_grade_id']) && $data['job_grade_id'] ? (int) $data['job_grade_id'] : null,
            'branch_id'         => isset($data['branch_id']) && $data['branch_id'] ? (int) $data['branch_id'] : null,
            'threshold_km'      => (float) ($data['threshold_km'] ?? $type['default_threshold_km'] ?? 30),
            'rate_below'        => (float) ($data['rate_below'] ?? 0),
            'rate_above'        => (float) ($data['rate_above'] ?? 0),
            'effective_from'    => $data['effective_from'] ?? null,
            'effective_to'      => $data['effective_to'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        $db->table('allowance_rates')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('PAYROLL')
            ->logCreate('allowance_rate', $id, $insert,
                sprintf('Tarif tunjangan %s: <%.2fkm Rp%s, ≥%.2fkm Rp%s',
                    $type['name'],
                    $insert['threshold_km'],
                    number_format($insert['rate_below'], 0, ',', '.'),
                    $insert['threshold_km'],
                    number_format($insert['rate_above'], 0, ',', '.')
                )
            );

        Services::allowance()->recomputeCompany($companyId);
        return $this->ok(['id' => $id], 'Tarif allowance dibuat', 201);
    }

    public function updateRate($id = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('allowance_rates')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Tidak ditemukan', 404);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'threshold_km' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'rate_below' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'rate_above' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'effective_from' => 'permit_empty|valid_date',
            'effective_to' => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;
        $invalidScope = $this->invalidRateScope($db, $data, $companyId);
        if ($invalidScope !== null) return $this->fail("Referensi {$invalidScope} tidak valid", 422, 'INVALID_SCOPE');
        $effectiveFrom = $data['effective_from'] ?? $existing['effective_from'];
        $effectiveTo = $data['effective_to'] ?? $existing['effective_to'];
        if ($effectiveFrom && $effectiveTo && $effectiveTo < $effectiveFrom) {
            return $this->fail('effective_to harus setelah atau sama dengan effective_from', 422, 'INVALID_DATE_RANGE');
        }
        $upd = array_intersect_key($data, array_flip([
            'job_grade_id','branch_id','threshold_km','rate_below','rate_above',
            'effective_from','effective_to','notes',
        ]));
        // Normalize empty strings to null
        foreach (['job_grade_id','branch_id','effective_from','effective_to'] as $k) {
            if (isset($upd[$k]) && $upd[$k] === '') $upd[$k] = null;
        }
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $db->table('allowance_rates')->where('id', $id)->where('company_id', $companyId)->update($upd);

        Services::audit()
            ->forModule('PAYROLL')
            ->logUpdate('allowance_rate', $id, array_intersect_key($existing, $upd), $upd,
                'Memperbarui tarif tunjangan');

        Services::allowance()->recomputeCompany($companyId);
        return $this->ok(null, 'Tarif diperbarui');
    }

    public function deleteRate($id = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('allowance_rates')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Tidak ditemukan', 404);
        $db->table('allowance_rates')->where('id', $id)->where('company_id', $companyId)->delete();
        Services::audit()
            ->forModule('PAYROLL')
            ->logDelete('allowance_rate', $id, $existing, 'Menghapus tarif tunjangan');

        Services::allowance()->recomputeCompany($companyId);
        return $this->ok(null, 'Tarif dihapus');
    }

    // ─── EMPLOYEE ASSIGNMENTS ───────────────────────────────────────

    public function listForEmployee($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();

        $emp = (new EmployeeModel())->findScoped($employeeId);
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        // Karyawan biasa cuma boleh lihat punyanya sendiri
        $isSelf = $this->tenant()->getEmployeeId() === $employeeId;
        if (! $isSelf && ! $this->tenant()->hasPermission('employee.view')) {
            return $this->fail('Forbidden', 403);
        }

        $rows = $db->table('employee_allowance_assignments a')
            ->select('a.*, t.code AS type_code, t.name AS type_name, t.category, t.is_distance_based, br.name AS branch_name, jg.name AS grade_name')
            ->join('allowance_types t', 't.id = a.allowance_type_id AND t.company_id = a.company_id')
            ->join('branches br', 'br.id = a.branch_id AND br.company_id = a.company_id', 'left')
            ->join('job_grades jg', 'jg.id = a.job_grade_id AND jg.company_id = a.company_id', 'left')
            ->where('a.employee_id', $employeeId)
            ->where('a.company_id', $companyId)
            ->orderBy('t.name', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function recomputeEmployee($employeeId = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')
            && ! $this->tenant()->hasPermission('employee.update')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $employeeId = (int) $employeeId;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $emp = (new EmployeeModel())->findScoped($employeeId);
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        $count = Services::allowance()->recomputeEmployee($employeeId, $companyId);
        Services::audit()
            ->forModule('PAYROLL')
            ->log(
                action: 'allowance.recompute',
                entity: 'employee_allowance',
                entityId: $employeeId,
                description: "Recompute tunjangan untuk {$emp['full_name']}: {$count} entri"
            );
        return $this->ok(['count' => $count], 'Recompute selesai');
    }

    public function recomputeCompany()
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $count = Services::allowance()->recomputeCompany($companyId);
        Services::audit()
            ->forModule('PAYROLL')
            ->log(
                action: 'allowance.recompute_company',
                entity: 'company',
                entityId: $companyId,
                description: "Recompute tunjangan seluruh karyawan ({$count} entri)"
            );
        return $this->ok(['count' => $count], 'Recompute company selesai');
    }

    /**
     * Override manual oleh HR (mis. set amount custom, atau matikan).
     */
    public function overrideAssignment($id = null)
    {
        if (! $this->tenant()->hasPermission('payroll.manage')) {
            return $this->fail('Tidak ada izin', 403);
        }
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('employee_allowance_assignments')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Tidak ditemukan', 404);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'amount' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'eligible' => 'permit_empty|in_list[0,1]',
            'manual_override' => 'permit_empty|in_list[0,1]',
        ]);
        if (! $valid) return null;
        $upd = array_intersect_key($data, array_flip(['amount','eligible','note','manual_override']));
        if (isset($upd['amount'])) $upd['amount'] = (float) $upd['amount'];
        if (isset($upd['eligible'])) $upd['eligible'] = (int) (bool) $upd['eligible'];
        $upd['manual_override'] = isset($data['manual_override']) ? (int) (bool) $data['manual_override'] : 1;
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $db->table('employee_allowance_assignments')->where('id', $id)->where('company_id', $companyId)->update($upd);
        Services::audit()
            ->forModule('PAYROLL')
            ->logUpdate('employee_allowance', $id, $existing, $upd,
                'Override manual tunjangan karyawan');
        return $this->ok(null, 'Override tersimpan');
    }

    private function tenantReferenceExists(BaseConnection $db, string $table, int $id, int $companyId): bool
    {
        return $db->table($table)->where('id', $id)->where('company_id', $companyId)->countAllResults() > 0;
    }

    private function invalidRateScope(BaseConnection $db, array $data, int $companyId): ?string
    {
        foreach (['job_grade_id' => 'job_grades', 'branch_id' => 'branches'] as $field => $table) {
            if (empty($data[$field])) continue;
            if (! $this->tenantReferenceExists($db, $table, (int) $data[$field], $companyId)) return $field;
        }
        return null;
    }
}
