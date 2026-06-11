<?php

namespace App\Libraries\Billing;

use Config\Database;

/**
 * S1.1 — FeatureGateService (Roadmap SaaS)
 *
 * Single source of truth untuk pertanyaan: "Apakah fitur X aktif untuk
 * tenant Y?". Hierarki resolusi (urutan prioritas):
 *
 *   1. Override per-tenant aktif  → `company_feature_overrides`
 *   2. Plan tenant include        → `plan_features`
 *   3. Default dari registry      → `feature_flags.default_enabled`
 *
 * Caching: per-request memoization saja (lokal property). Tidak pakai
 * Redis/cache global supaya feature toggle langsung berlaku saat super
 * admin override.
 *
 * Pure helper: `resolvePure()` — menerima 3 array (override, plan_feature,
 * flag_registry) dan tidak menyentuh DB. Cocok untuk unit test.
 */
class FeatureGateService
{
    /** @var array<string, array<string, bool>> Cached resolution per company_id */
    private array $cache = [];

    /**
     * Cek apakah feature aktif untuk company tertentu.
     * Bila `$companyId` null, return default registry saja.
     */
    public function enabled(string $key, ?int $companyId = null): bool
    {
        if ($companyId === null) {
            return $this->defaultFromRegistry($key);
        }

        if (! isset($this->cache[$companyId])) {
            $this->cache[$companyId] = $this->resolveAllForCompany($companyId);
        }

        // Bila feature tidak ada di hasil resolusi → cek registry default
        if (! array_key_exists($key, $this->cache[$companyId])) {
            return $this->defaultFromRegistry($key);
        }

        return (bool) $this->cache[$companyId][$key];
    }

    /**
     * Throw exception bila feature tidak aktif. Dipakai di service layer.
     *
     * @throws FeatureNotAvailableException
     */
    public function requireFeature(string $key, ?int $companyId = null): void
    {
        if (! $this->enabled($key, $companyId)) {
            throw new FeatureNotAvailableException($key);
        }
    }

    /**
     * Return semua feature key + status untuk company tertentu.
     * Dipakai oleh endpoint `GET /features/me` untuk frontend.
     *
     * @return array<int, array{key:string,enabled:bool,quota_value:?int,source:string}>
     */
    public function listForCompany(int $companyId): array
    {
        $db = Database::connect();
        $registry = $db->table('feature_flags')->select('key, default_enabled, category, description')->get()->getResultArray();
        $resolution = $this->resolveAllForCompanyDetailed($companyId);

        $result = [];
        foreach ($registry as $r) {
            $key = $r['key'];
            if (isset($resolution[$key])) {
                $result[] = [
                    'key'         => $key,
                    'enabled'     => (bool) $resolution[$key]['enabled'],
                    'quota_value' => $resolution[$key]['quota_value'],
                    'source'      => $resolution[$key]['source'], // OVERRIDE|PLAN|DEFAULT
                    'category'    => $r['category'],
                    'description' => $r['description'],
                ];
            } else {
                $result[] = [
                    'key'         => $key,
                    'enabled'     => (bool) $r['default_enabled'],
                    'quota_value' => null,
                    'source'      => 'DEFAULT',
                    'category'    => $r['category'],
                    'description' => $r['description'],
                ];
            }
        }
        return $result;
    }

    /**
     * Reset in-memory cache. Dipakai di test / saat super admin override
     * langsung butuh lihat hasil tanpa restart request.
     */
    public function flushCache(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$companyId]);
        }
    }

    /**
     * Pure resolver. Dipakai untuk unit test tanpa setup DB.
     *
     * @param array<string,array{is_enabled:bool,expires_at:?string}>      $overrides
     * @param array<string,array{is_included:bool,quota_value:?int}>       $planFeatures
     * @param array<string,bool>                                           $defaults
     * @param string|null                                                  $now Timestamp acuan untuk cek expiry override
     *
     * @return array<string, array{enabled:bool,quota_value:?int,source:string}>
     */
    public static function resolvePure(
        array $overrides,
        array $planFeatures,
        array $defaults,
        ?string $now = null,
    ): array {
        $now = $now ?? date('Y-m-d H:i:s');
        $allKeys = array_unique(array_merge(
            array_keys($overrides),
            array_keys($planFeatures),
            array_keys($defaults),
        ));

        $result = [];
        foreach ($allKeys as $key) {
            // 1. Override aktif (belum expired)
            if (isset($overrides[$key])) {
                $ov = $overrides[$key];
                $expired = ! empty($ov['expires_at']) && $ov['expires_at'] <= $now;
                if (! $expired) {
                    $result[$key] = [
                        'enabled'     => (bool) $ov['is_enabled'],
                        'quota_value' => $ov['quota_value'] ?? null,
                        'source'      => 'OVERRIDE',
                    ];
                    continue;
                }
            }
            // 2. Plan
            if (isset($planFeatures[$key])) {
                $pf = $planFeatures[$key];
                $result[$key] = [
                    'enabled'     => (bool) $pf['is_included'],
                    'quota_value' => $pf['quota_value'] ?? null,
                    'source'      => 'PLAN',
                ];
                continue;
            }
            // 3. Default registry
            $result[$key] = [
                'enabled'     => (bool) ($defaults[$key] ?? false),
                'quota_value' => null,
                'source'      => 'DEFAULT',
            ];
        }
        return $result;
    }

    // ─── Private DB helpers ─────────────────────────────────────────

    private function defaultFromRegistry(string $key): bool
    {
        $db = Database::connect();
        $row = $db->table('feature_flags')->where('key', $key)->get()->getRowArray();
        return (bool) ($row['default_enabled'] ?? 0);
    }

    /**
     * @return array<string, bool> key => enabled
     */
    private function resolveAllForCompany(int $companyId): array
    {
        $detailed = $this->resolveAllForCompanyDetailed($companyId);
        $simple = [];
        foreach ($detailed as $k => $v) {
            $simple[$k] = (bool) $v['enabled'];
        }
        return $simple;
    }

    /**
     * @return array<string, array{enabled:bool,quota_value:?int,source:string}>
     */
    private function resolveAllForCompanyDetailed(int $companyId): array
    {
        $db = Database::connect();

        // Overrides (active only — expires_at IS NULL or > now)
        $overrideRows = $db->table('company_feature_overrides')
            ->select('feature_key, is_enabled, quota_value, expires_at')
            ->where('company_id', $companyId)
            ->groupStart()
                ->where('expires_at IS NULL')
                ->orWhere('expires_at >', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->get()->getResultArray();
        $overrides = [];
        foreach ($overrideRows as $r) {
            $overrides[$r['feature_key']] = [
                'is_enabled' => (int) $r['is_enabled'] === 1,
                'quota_value' => $r['quota_value'] !== null ? (int) $r['quota_value'] : null,
                'expires_at' => $r['expires_at'],
            ];
        }

        // Plan features (via subscriptions → plan_features)
        $planFeatures = [];
        $sub = $db->table('subscriptions')
            ->where('company_id', $companyId)
            ->whereIn('status', ['TRIAL', 'ACTIVE', 'PAST_DUE']) // PAST_DUE masih punya akses sampai suspend
            ->get()->getRowArray();
        if ($sub) {
            $rows = $db->table('plan_features')
                ->select('feature_key, is_included, quota_value')
                ->where('plan_id', $sub['plan_id'])
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $planFeatures[$r['feature_key']] = [
                    'is_included' => (int) $r['is_included'] === 1,
                    'quota_value' => $r['quota_value'] !== null ? (int) $r['quota_value'] : null,
                ];
            }
        }

        // Default registry
        $defaultRows = $db->table('feature_flags')->select('key, default_enabled')->get()->getResultArray();
        $defaults = [];
        foreach ($defaultRows as $r) {
            $defaults[$r['key']] = (int) $r['default_enabled'] === 1;
        }

        return self::resolvePure($overrides, $planFeatures, $defaults);
    }
}