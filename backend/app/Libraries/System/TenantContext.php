<?php

namespace App\Libraries\System;

use App\Libraries\Identity\EffectivePermissionResolver;

/**
 * TenantContext
 *
 * Holds the currently authenticated user's tenant scope (company_id),
 * user_id, role_id, employee_id, branch_id. Models query within this scope
 * to ensure users from one business unit cannot access another's data.
 *
 * Branch scoping rules:
 *  - Super admin (role_id=1)              -> see across all companies/branches.
 *  - User dengan permission
 *    `attendance.view_all_branches`        -> see lintas branch dalam company
 *                                             yang sama (mis. HR pusat).
 *  - User lain (mis. manager cabang, HR
 *    cabang, employee biasa)               -> dipaksa ke `branch_id` mereka.
 *  - Karyawan tanpa branch (mis. user
 *    pusat tanpa cabang)                   -> tidak melihat data berbasis
 *                                             branch sampai branch-nya diset.
 */
class TenantContext
{
    private int $companyId = 0;
    private int $userId = 0;
    private int $roleId = 0;
    private int $employeeId = 0;
    private int $branchId = 0;
    private bool $isSuperAdmin = false;

    /** @var string[] permission slugs for the current user (cached on first call) */
    private ?array $permissions = null;

    /**
     * User row (dari tabel `users`) hasil resolve di JwtAuthFilter.
     * Disimpan di sini supaya tidak perlu set dynamic property
     * `$request->user` (memicu PHP 8.2 deprecation warning) dan controller
     * lain bisa akses lewat `Services::tenant()->getAuthUser()` tanpa
     * query DB ulang.
     *
     * @var array<string,mixed>|null
     */
    private ?array $authUser = null;

    /**
     * JWT payload (claims yang sudah di-validate oleh JwtService).
     * Pengganti `$request->jwt` lama.
     *
     * @var array<string,mixed>|null
     */
    private ?array $jwtPayload = null;

    public function setCompanyId(int $id): void
    {
        if ($this->companyId !== $id) {
            $this->permissions = null;
        }
        $this->companyId = $id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function setUserId(int $id): void
    {
        $this->userId = $id;
        // Reset cached permissions whenever the user changes.
        $this->permissions = null;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setRoleId(int $id): void
    {
        if ($this->roleId !== $id) {
            $this->permissions = null;
        }
        $this->roleId = $id;
        // Role 1 is reserved for super admin in our seeder
        $this->isSuperAdmin = ($id === 1);
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function setEmployeeId(int $id): void
    {
        if ($this->employeeId !== $id) {
            $this->permissions = null;
        }
        $this->employeeId = $id;
    }

    public function getEmployeeId(): int
    {
        return $this->employeeId;
    }

    public function setBranchId(int $id): void
    {
        $this->branchId = $id;
    }

    public function getBranchId(): int
    {
        return $this->branchId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    public function setAuthUser(?array $user): void
    {
        $this->authUser = $user;
    }

    public function getAuthUser(): ?array
    {
        return $this->authUser;
    }

    public function setJwtPayload(?array $payload): void
    {
        $this->jwtPayload = $payload;
    }

    public function getJwtPayload(): ?array
    {
        return $this->jwtPayload;
    }

    /**
     * @return string[]
     */
    public function getPermissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }
        $this->permissions = (new EffectivePermissionResolver())->resolve(
            $this->roleId,
            $this->employeeId,
            $this->companyId,
        );
        return $this->permissions;
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }
        return in_array($slug, $this->getPermissions(), true);
    }

    /**
     * Apply tenant scoping to a query builder.
     *
     * Aturan scoping:
     *   - Super admin TANPA company aktif (companyId === 0) → tidak ada filter
     *     (lihat data lintas tenant; biasanya hanya untuk halaman global
     *     seperti list company itu sendiri).
     *   - Super admin DENGAN company aktif (header X-Company-Id terkirim)
     *     → filter ke companyId tersebut. Ini penting supaya saat super
     *     admin memilih perusahaan dari dropdown topbar, semua list (master
     *     data, karyawan, kehadiran, dst.) langsung berubah mengikuti
     *     perusahaan yang dipilih, bukan menampilkan campuran.
     *   - User non-super-admin → selalu filter ke companyId mereka.
     */
    public function applyScope($builder, string $column = 'company_id')
    {
        if ($this->isSuperAdmin && $this->companyId === 0) {
            return $builder;
        }
        return $builder->where($column, $this->companyId);
    }

    /**
     * Apply company + branch scoping to an attendance-style builder.
     *
     * - Super admin: tidak ada filter sama sekali.
     * - User dengan `attendance.view_all_branches`: filter company saja.
     * - User lain: filter company + branch_id.
     *
     * `$companyColumn` dan `$branchColumn` boleh diubah sesuai alias join,
     * mis. `'a.company_id'` / `'e.branch_id'`.
     */
    public function applyAttendanceScope(
        $builder,
        string $companyColumn = 'company_id',
        string $branchColumn  = 'branch_id'
    ) {
        // Super admin tanpa company aktif: tidak filter (akses lintas tenant).
        if ($this->isSuperAdmin && $this->companyId === 0) {
            return $builder;
        }
        $builder->where($companyColumn, $this->companyId);

        // Super admin dengan company aktif: tetap lintas-cabang dalam company itu.
        if ($this->isSuperAdmin) {
            return $builder;
        }

        if (! $this->hasPermission('attendance.view_all_branches')) {
            // Force branch isolation. Kalau user belum punya branch_id,
            // kondisi ini akan match 0 row — itu memang yang diinginkan
            // (defensive default).
            $builder->where($branchColumn, $this->branchId);
        }
        return $builder;
    }

    /**
     * Cek apakah user boleh mengakses baris employee dari company/branch
     * yang diberikan. Berguna untuk endpoint *show* / *update*.
     */
    public function canAccessBranch(?int $companyId, ?int $branchId): bool
    {
        if ($this->isSuperAdmin) {
            // Super admin tanpa pilih company: bebas. Kalau sudah pilih
            // company, batasi ke company itu — konsisten dengan applyScope.
            if ($this->companyId === 0) return true;
            return (int) $companyId === $this->companyId;
        }
        if ((int) $companyId !== $this->companyId) {
            return false;
        }
        if ($this->hasPermission('attendance.view_all_branches')) {
            return true;
        }
        // Tanpa permission lintas-cabang: branch harus sama persis dengan
        // branch user. Kalau salah satu null, anggap mismatch.
        return $branchId !== null && $this->branchId !== 0
            && (int) $branchId === $this->branchId;
    }
}
