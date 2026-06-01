<?php

namespace App\Libraries\System;

/**
 * CacheService — wrapper tipis di atas CodeIgniter cache untuk lookup
 * yang sering dipakai (UMP/UMK, holidays per company-year, permissions per role).
 *
 * Tujuan:
 *   - Convention TTL terpusat (mudah audit cache hit ratio)
 *   - Key prefix terstruktur (`hris:ump:31:2026`) untuk debugging dengan
 *     `redis-cli MONITOR`
 *   - Helper `remember()` untuk pattern read-through cache
 *
 * Pattern pakai:
 *   $cache = service('cacheService');
 *   $rate = $cache->remember(
 *       $cache->keyUmp($provinceId, $year),
 *       CacheService::TTL_DAY,
 *       fn() => $umpModel->find($provinceId, $year)
 *   );
 *
 * Invalidate manual saat data berubah (mis. UMP di-update via admin UI):
 *   $cache->forget($cache->keyUmp($provinceId, $year));
 *
 * Cache backend di-set di `Config\Cache.php` — file (dev) atau redis (prod).
 *
 * Backend di-injeksi sebagai object (duck-typed) dengan method `get`,
 * `save`, `delete`, `deleteMatching`. CodeIgniter `CacheInterface` cocok
 * (default dari `service('cache')`), tapi tidak di-type-hint supaya gampang
 * di-test dengan stub minimalis.
 */
class CacheService
{
    /** TTL dalam detik. Pilih yang konservatif kalau ragu (10× lebih kecil
     *  daripada actual update frequency). */
    public const TTL_MINUTE   = 60;
    public const TTL_5_MIN    = 300;
    public const TTL_15_MIN   = 900;
    public const TTL_HOUR     = 3600;
    public const TTL_DAY      = 86400;
    public const TTL_WEEK     = 604800;

    /** Default TTL kalau caller tidak specify. */
    public const TTL_DEFAULT  = self::TTL_5_MIN;

    /** Key prefix global — gampang dihapus semua dengan `redis-cli FLUSHDB`
     *  saat clear cache via `php spark cache:clear`. */
    private const PREFIX = 'hris:';

    /** Cache backend (CI4 CacheInterface atau stub testing). Duck-typed: must
     *  implement get(), save(), delete(), deleteMatching(). */
    private object $cache;

    public function __construct(?object $cache = null)
    {
        $this->cache = $cache ?? service('cache');
    }

    /**
     * Read-through cache. Return cached value, atau eksekusi $callback +
     * simpan + return. NULL/false dianggap valid (di-cache), kecuali
     * $skipFalsy=true.
     *
     * @template T
     * @param string         $key
     * @param int            $ttl Seconds
     * @param callable():T   $callback
     * @param bool           $skipFalsy Kalau true, NULL/false tidak di-cache
     * @return T
     */
    public function remember(string $key, int $ttl, callable $callback, bool $skipFalsy = false)
    {
        $fullKey = $this->prefixKey($key);
        $cached = $this->cache->get($fullKey);
        // Sentinel '__null__' supaya bisa cache NULL hasil callback (tanpa
        // ini, callback selalu re-eksekusi karena get() return null untuk miss).
        if ($cached === '__null__') return null;
        if ($cached !== null) return $cached;

        $value = $callback();
        if (! $skipFalsy || ($value !== null && $value !== false && $value !== [])) {
            $toStore = $value === null ? '__null__' : $value;
            try {
                $this->cache->save($fullKey, $toStore, $ttl);
            } catch (\Throwable $e) {
                // Cache backend down — log, jangan gagalkan request
                log_message('warning', 'Cache save failed for ' . $fullKey . ': ' . $e->getMessage());
            }
        }
        return $value;
    }

    /** Get raw value without callback. Returns null if miss. */
    public function get(string $key): mixed
    {
        $val = $this->cache->get($this->prefixKey($key));
        if ($val === '__null__') return null;
        return $val;
    }

    /** Set raw value. */
    public function set(string $key, mixed $value, int $ttl = self::TTL_DEFAULT): bool
    {
        $toStore = $value === null ? '__null__' : $value;
        try {
            return (bool) $this->cache->save($this->prefixKey($key), $toStore, $ttl);
        } catch (\Throwable $e) {
            log_message('warning', 'Cache save failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Delete a key (alias `forget` lebih readable). */
    public function forget(string $key): bool
    {
        try {
            return (bool) $this->cache->delete($this->prefixKey($key));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Bulk invalidate by suffix wildcard (mis. invalidate semua holidays
     *  saat upload massal). Hanya backend yang support deleteMatching;
     *  fallback no-op kalau tidak. */
    public function forgetPrefix(string $prefix): int
    {
        try {
            $result = $this->cache->deleteMatching($this->prefixKey($prefix) . '*');
            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ─── Key builders (terpusat di sini supaya konsisten) ────────────

    /** UMP/UMK rate — `hris:ump:{province_id}:{year}` */
    public function keyUmp(int $provinceId, int $year): string
    {
        return "ump:{$provinceId}:{$year}";
    }

    /** UMK kota/kabupaten — `hris:umk:{city_id}:{year}` */
    public function keyUmk(int $cityId, int $year): string
    {
        return "umk:{$cityId}:{$year}";
    }

    /** Holidays untuk company × year — `hris:holidays:{company_id}:{year}` */
    public function keyHolidays(int $companyId, int $year): string
    {
        return "holidays:{$companyId}:{$year}";
    }

    /** Permissions untuk role — `hris:perms:{role_id}` */
    public function keyRolePermissions(int $roleId): string
    {
        return "perms:{$roleId}";
    }

    /** TER rates per category × year — `hris:ter:{category}:{year}` */
    public function keyTerRates(string $category, int $year): string
    {
        return "ter:{$category}:{$year}";
    }

    /** BPJS rates per company — `hris:bpjs:{company_id}` */
    public function keyBpjsRates(int $companyId): string
    {
        return "bpjs:{$companyId}";
    }

    /** PayrollSettings per company — `hris:payset:{company_id}` */
    public function keyPayrollSettings(int $companyId): string
    {
        return "payset:{$companyId}";
    }

    /** Add global prefix. Public untuk debug / testing. */
    public function prefixKey(string $key): string
    {
        return self::PREFIX . $key;
    }
}