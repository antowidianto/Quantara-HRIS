<?php

namespace App\Libraries;

use Config\Services;

/**
 * PrivacyScope — enforce personal data isolation per (employee_id, company_id)
 * dengan permission-aware filter whitelist.
 *
 * Latar belakang masalah:
 *   Banyak endpoint list (mis. /leaves?employee_id=N, /payslips?employee_id=N,
 *   /overtimes?employee_id=N) menerima query parameter `employee_id` mentah-mentah
 *   tanpa cek permission. Setiap user yang authenticated bisa pass ID employee
 *   lain → data privacy leak.
 *
 *   Pattern yang benar: wajib filter ke `getEmployeeId()` sendiri kecuali user
 *   punya permission "lihat semua" (`<module>.view`). Kalau cuma punya
 *   `<module>.view.own`, maka filter `employee_id` di query param di-override
 *   ke milik user, dan filter lain (mis. `branch_id`, `department_id`) di-strip.
 *
 * Konvensi permission slug yang dipakai:
 *   <module>.view        → bisa lihat semua employee dalam scope tenant
 *                          (mis. leave.view = HR melihat semua cuti)
 *   <module>.view.own    → hanya milik sendiri (mis. leave.view.own = employee
 *                          biasa hanya melihat cuti pribadi)
 *   attendance.view_all_branches → bisa lihat lintas-branch dalam company
 *                          (untuk employee yang belum punya branch atau HR pusat)
 *
 * Lihat docs/DATA_PRIVACY.md untuk daftar lengkap mapping & test matrix.
 *
 * Sprint 5-6 follow-up: tightening data scope per feedback user.
 */
class PrivacyScope
{
    private TenantContext $tenant;

    /** @var string[] cache permission yang sudah di-resolve per request */
    private array $permsCache = [];

    public function __construct(?TenantContext $tenant = null)
    {
        $this->tenant = $tenant ?? Services::tenant();
    }

    /** Cache layer di atas TenantContext::hasPermission untuk hindari N×query. */
    public function has(string $perm): bool
    {
        if (! isset($this->permsCache[$perm])) {
            $this->permsCache[$perm] = $this->tenant->hasPermission($perm);
        }
        return $this->permsCache[$perm];
    }

    /**
     * Mapping module → permission slugs. Backward-compatible dengan slugs
     * existing di seeder (`leave.approve`, `payslip.view_all`, dst). Saat
     * tambah module baru, append di sini supaya convention konsisten.
     *
     * Format:
     *   'module' => [
     *       'all'  => ['slug1', 'slug2'],   // punya salah satu = canViewAll
     *       'own'  => ['slug1', 'slug2'],   // punya salah satu = canViewOwn
     *   ]
     */
    private const MODULE_MAP = [
        'leave' => [
            'all' => ['leave.approve', 'leave.view_all'],
            'own' => ['leave.view', 'leave.create', 'leave.view.own'],
        ],
        'overtime' => [
            'all' => ['overtime.approve', 'overtime.view_all'],
            'own' => ['overtime.view', 'overtime.create', 'overtime.view.own'],
        ],
        'payslip' => [
            'all' => ['payslip.view_all'],
            'own' => ['payslip.view_self', 'payslip.view.own'],
        ],
        'attendance' => [
            'all' => ['attendance.view_all', 'attendance.approve'],
            'own' => ['attendance.view', 'attendance.clock_in', 'attendance.view.own'],
        ],
        'attendance_change' => [
            'all' => ['attendance.approve_change', 'attendance.view_all'],
            'own' => ['attendance.create_change', 'attendance.view'],
        ],
        'reimbursement' => [
            'all' => ['reimbursement.view_all', 'reimbursement.approve'],
            'own' => ['reimbursement.view', 'reimbursement.create'],
        ],
        'loan' => [
            'all' => ['loan.view_all', 'loan.approve'],
            'own' => ['loan.view', 'loan.create'],
        ],
        // HR-managed modules — semua punya canViewAll yang nyata (permission HR);
        // employee biasa hanya boleh lihat miliknya saat memang ada flow self-service.
        'asset' => [
            'all' => ['asset.view', 'asset.manage'],
            'own' => ['asset.view.own'],
        ],
        'disciplinary' => [
            'all' => ['disciplinary.view', 'disciplinary.manage'],
            'own' => ['disciplinary.view.own'],
        ],
        'severance' => [
            'all' => ['severance.view', 'severance.manage'],
            'own' => ['severance.view.own'],
        ],
        'business_trip' => [
            'all' => ['business_trip.approve', 'business_trip.view_all'],
            'own' => ['business_trip.view', 'business_trip.create'],
        ],
        'employee_task' => [
            'all' => ['onboarding.view', 'onboarding.manage', 'task.view_all'],
            'own' => ['onboarding.view.own', 'task.view'],
        ],
        'training' => [
            'all' => ['training.view', 'training.manage', 'training.approve'],
            'own' => ['training.register', 'training.view.own'],
        ],
        'performance' => [
            'all' => ['performance.view', 'performance.manage', 'performance.review'],
            'own' => ['performance.view.own', 'performance.self_review'],
        ],
        'contract' => [
            'all' => ['contract.view', 'contract.manage', 'employee.view'],
            'own' => ['contract.view.own'],
        ],
        'shift_assignment' => [
            // Shift bukan PII murni tapi tetap di-scope: HR atur jadwal,
            // employee biasa hanya lihat miliknya.
            'all' => ['shift.manage', 'shift.view_all'],
            'own' => ['shift.view'],
        ],
    ];

    /**
     * Cek apakah user boleh lihat SEMUA data module ini (HR/manager scope).
     * Super admin selalu return true.
     *
     * Lookup ke MODULE_MAP['module']['all']. Kalau module tidak terdaftar,
     * fallback ke convention generik `<module>.view_all`.
     */
    public function canViewAll(string $module): bool
    {
        if ($this->tenant->isSuperAdmin()) return true;

        $map = self::MODULE_MAP[$module] ?? null;
        $slugs = $map['all'] ?? [$module . '.view_all'];
        foreach ($slugs as $slug) {
            if ($this->has($slug)) return true;
        }
        return false;
    }

    /**
     * Cek apakah user punya akses minimal "lihat milik sendiri" untuk module ini.
     *
     * Logic:
     *   1. Kalau punya canViewAll → otomatis canViewOwn juga.
     *   2. Lookup ke MODULE_MAP['module']['own'].
     *   3. Fallback: convention `<module>.view` atau `<module>.view.own`.
     */
    public function canViewOwn(string $module): bool
    {
        if ($this->canViewAll($module)) return true;

        $map = self::MODULE_MAP[$module] ?? null;
        $slugs = $map['own'] ?? [$module . '.view', $module . '.view.own'];
        foreach ($slugs as $slug) {
            if ($this->has($slug)) return true;
        }
        return false;
    }

    /**
     * Sanitasi employee_id yang di-request user.
     *
     * Returns:
     *   - integer employee_id yang aman dipakai filter, ATAU
     *   - 0 jika user tidak punya akses sama sekali (caller harus block).
     *
     * Logic:
     *   - super_admin: pass-through nilai apapun (boleh kosong → 0 = no filter)
     *   - canViewAll: pass-through (HR bebas filter ke siapapun dalam tenant)
     *   - canViewOwn only: paksa ke employee_id user, abaikan input
     *   - tidak punya akses sama sekali: 0
     *
     * @param int|null $requested ID employee yang di-request user (dari query param)
     * @param string $module slug module untuk permission check
     */
    public function sanitizeEmployeeFilter(?int $requested, string $module): int
    {
        $tenantEmpId = $this->tenant->getEmployeeId();

        // Super admin / canViewAll: pass-through
        if ($this->canViewAll($module)) {
            return (int) ($requested ?? 0);
        }

        // canViewOwn only: paksa ke milik sendiri (abaikan request)
        if ($this->canViewOwn($module)) {
            // Kalau user request explicit ID lain, tetap paksa ke milik sendiri
            // (silent override — log untuk audit visibility)
            if ($requested && (int) $requested !== $tenantEmpId) {
                try {
                    Services::audit()->forModule(strtoupper($module))->log(
                        action: 'privacy.scope_override',
                        entity: 'query',
                        description: "User #{$this->tenant->getUserId()} request employee_id={$requested}, "
                            . "di-override ke milik sendiri ({$tenantEmpId}) karena hanya punya `{$module}.view.own`",
                        severity: 'NOTICE',
                    );
                } catch (\Throwable $e) {}
            }
            return $tenantEmpId;
        }

        // Tidak punya akses sama sekali
        return 0;
    }

    /**
     * Apply scope ke query builder list endpoint:
     *   1. Tenant scope (company_id)
     *   2. Branch scope (kalau module attendance-style)
     *   3. Personal scope (paksa employee_id ke milik user kalau cuma view.own)
     *
     * Contoh pakai:
     *
     *   $builder = $db->table('leaves l')->select('l.*');
     *   $rid = (int) $this->request->getGet('employee_id');
     *   $effectiveEmpId = Services::privacy()->applyListScope(
     *       $builder, 'leave',
     *       companyColumn: 'l.company_id',
     *       employeeColumn: 'l.employee_id',
     *       requestedEmployeeId: $rid,
     *   );
     *   if ($effectiveEmpId === null) return $this->fail('Forbidden', 403);
     *
     * @param \CodeIgniter\Database\BaseBuilder $builder
     * @param string $module slug module (leave, overtime, payslip, dll)
     * @param string $companyColumn kolom company_id di builder
     * @param string $employeeColumn kolom employee_id di builder
     * @param int|null $requestedEmployeeId ID dari query param (bisa null)
     * @return int|null ID employee final yang diterapkan (atau 0 = no filter,
     *                  atau null = blocked/forbidden)
     */
    public function applyListScope(
        $builder,
        string $module,
        string $companyColumn = 'company_id',
        string $employeeColumn = 'employee_id',
        ?int $requestedEmployeeId = null,
    ): ?int {
        // Block kalau bahkan view.own pun tidak ada
        if (! $this->canViewOwn($module) && ! $this->canViewAll($module)) {
            return null;
        }

        // 1. Tenant scope
        if (! $this->tenant->isSuperAdmin()) {
            $builder->where($companyColumn, $this->tenant->getCompanyId());
        }

        // 2. Personal scope
        $effective = $this->sanitizeEmployeeFilter($requestedEmployeeId, $module);
        if (! $this->canViewAll($module)) {
            // Cuma view.own → wajib filter ke employee_id sendiri
            $builder->where($employeeColumn, $effective);
        } elseif ($effective > 0) {
            // canViewAll + user pilih filter spesifik → terapkan
            $builder->where($employeeColumn, $effective);
        }
        // canViewAll + tanpa filter → tidak tambah where (lihat semua dalam tenant)

        return $effective;
    }

    /**
     * Cek apakah user boleh akses 1 row tertentu (untuk endpoint show/update/delete).
     * Return true kalau:
     *   - Super admin, ATAU
     *   - canViewAll dan row.company_id = tenant.company_id, ATAU
     *   - row.employee_id = tenant.employee_id (selalu boleh akses miliknya sendiri)
     *
     * @param array $row baris dari DB yang punya kolom company_id + employee_id
     * @param string $module slug module untuk permission resolve
     */
    public function canAccessRow(array $row, string $module): bool
    {
        if ($this->tenant->isSuperAdmin()) return true;

        $rowCompany  = (int) ($row['company_id'] ?? 0);
        $rowEmpId    = (int) ($row['employee_id'] ?? 0);
        $tenantEmpId = $this->tenant->getEmployeeId();

        // Tenant boundary harus sama
        if ($rowCompany !== $this->tenant->getCompanyId()) return false;

        // Selalu boleh akses milik sendiri
        if ($rowEmpId === $tenantEmpId && $tenantEmpId > 0) return true;

        // Selain itu butuh canViewAll
        return $this->canViewAll($module);
    }

    /**
     * Sanitasi filter array dari query string → hanya pertahankan key yang
     * di-allow per module + role.
     *
     * Untuk user view.own: filter `employee_id`, `branch_id`, `department_id`
     * di-strip (forced ke milik sendiri). Untuk user view.all: pass-through.
     *
     * Contoh:
     *   $clean = Services::privacy()->sanitizeFilters(
     *       $this->request->getGet(),
     *       'leave',
     *       ['status', 'start_date', 'end_date', 'employee_id', 'branch_id'],
     *   );
     *
     * @param array $input semua query param
     * @param string $module untuk permission check
     * @param string[] $allowed list filter yang di-whitelist controller
     * @return array filter yang sudah disanitasi
     */
    public function sanitizeFilters(array $input, string $module, array $allowed): array
    {
        $clean = [];
        $isAllScope = $this->canViewAll($module);

        // Filter yang hanya boleh dipakai user dengan canViewAll
        $allScopeOnly = ['employee_id', 'branch_id', 'department_id', 'position_id', 'job_grade_id'];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $input)) continue;
            $value = $input[$key];
            if ($value === '' || $value === null) continue;

            if (in_array($key, $allScopeOnly, true) && ! $isAllScope) {
                // User cuma view.own → strip filter cross-employee
                continue;
            }

            $clean[$key] = $value;
        }
        return $clean;
    }
}