<?php

namespace App\Libraries;

use Config\Services;

/**
 * LookupCache — Sprint D+1 / D4.
 *
 * High-level read-through cache untuk lookup data quasi-static (jarang
 * berubah, sering dibaca). Wrapper di atas `CacheService` yang sudah
 * punya key builder, ditambah loader callback yang langsung melakukan
 * query DB dan TTL convention per resource.
 *
 * Tujuan:
 *   - Menghilangkan N+1 di endpoint payroll, leave-balance, attendance
 *     yang lookup UMP/holidays/BPJS rates per employee per request.
 *   - Konsisten cache key & TTL (saat data jarang berubah, TTL panjang;
 *     saat data sering berubah, TTL pendek).
 *   - Hooks invalidation eksplisit dipanggil saat admin update master.
 *
 * Pattern pakai:
 *
 *   $lookup = service('lookupCache');
 *   $umpRow = $lookup->getUmp($provinceId, $year);          // read-through cache
 *   $lookup->forgetUmp($provinceId, $year);                  // invalidate saat update
 *   $perms  = $lookup->getRolePermissions($roleId);          // array of slug
 *
 * Tidak meng-handle PII / data tenant-specific yang besar — fokus ke
 * lookup tabel kecil yang stabil. Untuk data company-scoped (holidays,
 * BPJS rates per company), key sudah terisolasi by company_id.
 *
 * Gracefully degrade: kalau cache backend down, tetap query DB —
 * pattern di `CacheService::remember()` sudah handle exception.
 */
class LookupCache
{
    private CacheService $cache;

    /**
     * DB connection — duck-typed sebagai object supaya gampang di-stub di test.
     * Production pakai CodeIgniter BaseConnection (`Config\Database::connect()`).
     * Stub minimum harus expose `table(string)` yang return query builder
     * dengan chainable `where/orderBy/limit/join/select/get/getRowArray/getResultArray`.
     *
     * @var object
     */
    private object $db;

    public function __construct(?CacheService $cache = null, ?object $db = null)
    {
        $this->cache = $cache ?? Services::cacheService();
        $this->db    = $db    ?? \Config\Database::connect();
    }

    // ─── UMP (provincial minimum wage) ────────────────────────────

    /**
     * UMP per provinsi × tahun. TTL panjang (1 hari) karena UMP hanya
     * di-update sekali per tahun (Pergub akhir tahun untuk tahun berikutnya).
     *
     * @return array<string,mixed>|null
     */
    public function getUmp(int $provinceId, int $year): ?array
    {
        return $this->cache->remember(
            $this->cache->keyUmp($provinceId, $year),
            CacheService::TTL_DAY,
            fn() => $this->loadUmp($provinceId, $year),
        );
    }

    /**
     * Invalidate cache UMP. Dipanggil saat admin import seeder UMP
     * yearly atau update via admin UI.
     */
    public function forgetUmp(int $provinceId, int $year): void
    {
        $this->cache->forget($this->cache->keyUmp($provinceId, $year));
    }

    /**
     * Bulk invalidate semua UMP di tahun tertentu (mis. setelah import
     * yearly batch). Pakai prefix wildcard kalau cache backend support.
     */
    public function forgetAllUmpForYear(int $year): void
    {
        // Tidak ada API delete-by-suffix, jadi pakai prefix matching kalau
        // backend support; fallback no-op aman karena TTL akan expire.
        $this->cache->forgetPrefix('ump:');
    }

    /** @return array<string,mixed>|null */
    private function loadUmp(int $provinceId, int $year): ?array
    {
        $row = $this->db->table('ump_rates')
            ->where('province_id', $provinceId)
            ->where('year', $year)
            ->get()->getRowArray();
        return $row ?: null;
    }

    // ─── UMK (city/regency minimum wage) ──────────────────────────

    /** @return array<string,mixed>|null */
    public function getUmk(int $cityId, int $year): ?array
    {
        return $this->cache->remember(
            $this->cache->keyUmk($cityId, $year),
            CacheService::TTL_DAY,
            fn() => $this->loadUmk($cityId, $year),
        );
    }

    public function forgetUmk(int $cityId, int $year): void
    {
        $this->cache->forget($this->cache->keyUmk($cityId, $year));
    }

    /** @return array<string,mixed>|null */
    private function loadUmk(int $cityId, int $year): ?array
    {
        $row = $this->db->table('umk_rates')
            ->where('city_id', $cityId)
            ->where('year', $year)
            ->get()->getRowArray();
        return $row ?: null;
    }

    // ─── Holidays ─────────────────────────────────────────────────

    /**
     * Daftar tanggal libur untuk company × year. TTL menengah (1 jam)
     * karena admin kadang update holiday tahun berjalan (libur cuti
     * bersama dadakan dari pemerintah). Caller tetap bisa
     * `forgetHolidays()` saat add/edit.
     *
     * Return list of rows dengan minimum kolom `date` dan `name`.
     *
     * @return list<array{date:string,name:string,is_national:int}>
     */
    public function getHolidays(int $companyId, int $year): array
    {
        $rows = $this->cache->remember(
            $this->cache->keyHolidays($companyId, $year),
            CacheService::TTL_HOUR,
            fn() => $this->loadHolidays($companyId, $year),
        );
        return is_array($rows) ? $rows : [];
    }

    public function forgetHolidays(int $companyId, int $year): void
    {
        $this->cache->forget($this->cache->keyHolidays($companyId, $year));
    }

    /** @return list<array<string,mixed>> */
    private function loadHolidays(int $companyId, int $year): array
    {
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);
        $rows = $this->db->table('holidays')
            ->where('company_id', $companyId)
            ->where('date >=', $start)
            ->where('date <=', $end)
            ->orderBy('date', 'ASC')
            ->get()->getResultArray();
        return $rows;
    }

    // ─── Role permissions ─────────────────────────────────────────

    /**
     * Daftar permission slug untuk role. TTL panjang (1 jam) karena
     * permission jarang berubah; admin yang update role harus panggil
     * `forgetRolePermissions()`.
     *
     * @return list<string>
     */
    public function getRolePermissions(int $roleId): array
    {
        $list = $this->cache->remember(
            $this->cache->keyRolePermissions($roleId),
            CacheService::TTL_HOUR,
            fn() => $this->loadRolePermissions($roleId),
        );
        return is_array($list) ? $list : [];
    }

    public function forgetRolePermissions(int $roleId): void
    {
        $this->cache->forget($this->cache->keyRolePermissions($roleId));
    }

    /**
     * Bulk invalidate semua role permissions — pakai saat re-seed
     * permission lookup table.
     */
    public function forgetAllRolePermissions(): void
    {
        $this->cache->forgetPrefix('perms:');
    }

    /** @return list<string> */
    private function loadRolePermissions(int $roleId): array
    {
        $rows = $this->db->table('role_permissions rp')
            ->select('p.slug')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()->getResultArray();
        return array_values(array_filter(array_map(
            static fn(array $r) => isset($r['slug']) ? (string) $r['slug'] : '',
            $rows,
        )));
    }

    // ─── BPJS rates per company ───────────────────────────────────

    /**
     * BPJS rates konfigurasi per company. Stabil (jarang diubah),
     * cache 1 hari. Invalidate manual saat admin edit di UI.
     *
     * @return array<string,mixed>|null
     */
    public function getBpjsRates(int $companyId): ?array
    {
        return $this->cache->remember(
            $this->cache->keyBpjsRates($companyId),
            CacheService::TTL_DAY,
            fn() => $this->loadBpjsRates($companyId),
        );
    }

    public function forgetBpjsRates(int $companyId): void
    {
        $this->cache->forget($this->cache->keyBpjsRates($companyId));
    }

    /** @return array<string,mixed>|null */
    private function loadBpjsRates(int $companyId): ?array
    {
        $row = $this->db->table('bpjs_rates')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->orderBy('effective_from', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        return $row ?: null;
    }

    // ─── PayrollSettings per company ──────────────────────────────

    /**
     * Override `working_days_per_month`, `overtime_hours_per_month`,
     * dst dari tabel `settings`. TTL menengah (15 menit) karena admin
     * kadang test-tweak setting.
     *
     * @return array<string,mixed>|null
     */
    public function getPayrollSettings(int $companyId): ?array
    {
        return $this->cache->remember(
            $this->cache->keyPayrollSettings($companyId),
            CacheService::TTL_15_MIN,
            fn() => $this->loadPayrollSettings($companyId),
        );
    }

    public function forgetPayrollSettings(int $companyId): void
    {
        $this->cache->forget($this->cache->keyPayrollSettings($companyId));
    }

    /** @return array<string,mixed>|null */
    private function loadPayrollSettings(int $companyId): ?array
    {
        $rows = $this->db->table('settings')
            ->where('company_id', $companyId)
            ->where('namespace', 'payroll')
            ->get()->getResultArray();
        if (empty($rows)) {
            return null;
        }
        $kv = [];
        foreach ($rows as $r) {
            if (isset($r['key'])) {
                $kv[(string) $r['key']] = $r['value'] ?? null;
            }
        }
        return $kv;
    }

    // ─── TER rates per category × year ────────────────────────────

    /**
     * TER (Tarif Efektif Rata-rata) PMK 168/2023. Stabil sepanjang
     * tahun pajak — invalidate saat admin update tarif (mis. tahun pajak
     * baru). TTL = 1 minggu.
     *
     * @return list<array<string,mixed>>
     */
    public function getTerRates(string $category, int $year): array
    {
        $rows = $this->cache->remember(
            $this->cache->keyTerRates($category, $year),
            CacheService::TTL_WEEK,
            fn() => $this->loadTerRates($category, $year),
        );
        return is_array($rows) ? $rows : [];
    }

    public function forgetTerRates(string $category, int $year): void
    {
        $this->cache->forget($this->cache->keyTerRates($category, $year));
    }

    /** @return list<array<string,mixed>> */
    private function loadTerRates(string $category, int $year): array
    {
        $rows = $this->db->table('ter_rates')
            ->where('category', $category)
            ->where('year', $year)
            ->orderBy('income_from', 'ASC')
            ->get()->getResultArray();
        return $rows;
    }
}