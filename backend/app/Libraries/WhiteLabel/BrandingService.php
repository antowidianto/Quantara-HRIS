<?php

namespace App\Libraries\WhiteLabel;

use Config\Database;

/**
 * S3.1 — White-Label Branding Service.
 *
 * Resolve branding settings untuk satu tenant. Strategi caching:
 *   - Per-request memoize via static $cache[$companyId]
 *   - Long-lived cache (Redis) optional via env BRANDING_CACHE_TTL
 *
 * Branding terdiri dari 4 layer (priority desc):
 *   1. Tenant-specific (kolom companies.brand_*)
 *   2. Plan default (gunakan default Quantara untuk plan FREE/STARTER)
 *   3. Quantara default (logo Quantara, color #1E40AF)
 *
 * Pure helpers `mergePure()` untuk testing tanpa DB.
 */
class BrandingService
{
    /** @var array<int, array> */
    private static array $cache = [];

    /**
     * Quantara default branding (fallback).
     */
    public const DEFAULTS = [
        'app_name'        => 'Quantara HRIS',
        'logo_path'       => null,
        'favicon_path'    => null,
        'primary_color'   => '#1E40AF',
        'secondary_color' => '#3B82F6',
        'login_bg_path'   => null,
        'support_email'   => 'support@quantara.io',
        'email_footer_html' => null,
        'custom_domain'   => null,
        'has_custom_domain' => false,
    ];

    /**
     * Resolve branding untuk company aktif.
     *
     * Caller mostly: Controller untuk return ke frontend, atau email
     * renderer untuk inject logo/footer.
     */
    public function resolve(int $companyId): array
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        $db = Database::connect();
        if (! $db->tableExists('companies')) {
            return self::$cache[$companyId] = self::DEFAULTS;
        }

        $row = $db->table('companies')
            ->select([
                'id', 'code', 'name',
                'brand_logo_path', 'brand_favicon_path',
                'brand_primary_color', 'brand_secondary_color',
                'brand_login_bg_path', 'brand_email_footer_html',
                'custom_domain', 'custom_domain_verified_at',
                'brand_app_name', 'brand_support_email',
            ])
            ->where('id', $companyId)
            ->get()
            ->getRowArray();

        if (! $row) {
            return self::$cache[$companyId] = self::DEFAULTS;
        }

        $result = self::mergePure(self::DEFAULTS, $row);
        return self::$cache[$companyId] = $result;
    }

    /**
     * Resolve branding via host header (custom domain). Berguna untuk
     * tenant resolver pre-JWT (mis. login page yang harus tampil dengan
     * branding tenant sebelum user input password).
     *
     * Return null kalau host tidak match ada custom_domain verified.
     */
    public function resolveByHost(string $host): ?array
    {
        if ($host === '') {
            return null;
        }
        // Strip port + lowercase
        $host = strtolower(preg_replace('/:\d+$/', '', $host));

        $db = Database::connect();
        if (! $db->tableExists('companies')) {
            return null;
        }

        $row = $db->table('companies')
            ->where('custom_domain', $host)
            ->where('custom_domain_verified_at IS NOT NULL')
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $row) {
            return null;
        }
        return $this->resolve((int) $row['id']);
    }

    /**
     * Pure helper: merge raw company row + DEFAULTS jadi struktur clean.
     *
     * Output sesuai shape DEFAULTS + field metadata.
     */
    public static function mergePure(array $defaults, array $companyRow): array
    {
        $appName = ! empty($companyRow['brand_app_name'])
            ? (string) $companyRow['brand_app_name']
            : $defaults['app_name'];

        return [
            'app_name'          => $appName,
            'logo_path'         => $companyRow['brand_logo_path']     ?? $defaults['logo_path'],
            'favicon_path'      => $companyRow['brand_favicon_path']  ?? $defaults['favicon_path'],
            'primary_color'     => self::normalizeColorPure($companyRow['brand_primary_color']    ?? null) ?? $defaults['primary_color'],
            'secondary_color'   => self::normalizeColorPure($companyRow['brand_secondary_color']  ?? null) ?? $defaults['secondary_color'],
            'login_bg_path'     => $companyRow['brand_login_bg_path'] ?? $defaults['login_bg_path'],
            'support_email'     => ! empty($companyRow['brand_support_email'])
                ? (string) $companyRow['brand_support_email']
                : $defaults['support_email'],
            'email_footer_html' => $companyRow['brand_email_footer_html'] ?? $defaults['email_footer_html'],
            'custom_domain'     => $companyRow['custom_domain'] ?? null,
            'has_custom_domain' => ! empty($companyRow['custom_domain']) && ! empty($companyRow['custom_domain_verified_at']),
            'company_id'        => (int) ($companyRow['id'] ?? 0),
            'company_code'      => $companyRow['code'] ?? null,
            'company_name'      => $companyRow['name'] ?? null,
        ];
    }

    /**
     * Normalize color string ke #RRGGBB. Reject kalau invalid.
     * Pure helper untuk testing.
     */
    public static function normalizeColorPure(?string $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }
        $color = strtoupper(trim($color));
        if (! str_starts_with($color, '#')) {
            $color = '#' . $color;
        }
        if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return $color;
        }
        // Allow #RGB shorthand → expand ke #RRGGBB
        if (preg_match('/^#([0-9A-F])([0-9A-F])([0-9A-F])$/', $color, $m)) {
            return '#' . str_repeat($m[1], 2) . str_repeat($m[2], 2) . str_repeat($m[3], 2);
        }
        return null;
    }

    /**
     * Update branding untuk satu company. Return ['ok' => bool, 'errors' => array].
     *
     * Caller (Controller) sudah harus cek FeatureGate `white_label.enabled`
     * di plan tenant — service ini fokus ke validation + persist + audit.
     */
    public function update(int $companyId, int $userId, array $payload): array
    {
        $errors = $this->validatePayload($payload);
        if (! empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $db = Database::connect();
        $before = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $before) {
            return ['ok' => false, 'errors' => ['company' => 'not found']];
        }

        $update = [];
        foreach ([
            'brand_logo_path'         => 'logo_path',
            'brand_favicon_path'      => 'favicon_path',
            'brand_login_bg_path'     => 'login_bg_path',
            'brand_email_footer_html' => 'email_footer_html',
            'brand_app_name'          => 'app_name',
            'brand_support_email'     => 'support_email',
        ] as $col => $payloadKey) {
            if (array_key_exists($payloadKey, $payload)) {
                $update[$col] = $payload[$payloadKey] !== '' ? $payload[$payloadKey] : null;
            }
        }
        if (array_key_exists('primary_color', $payload)) {
            $update['brand_primary_color'] = self::normalizeColorPure($payload['primary_color']);
        }
        if (array_key_exists('secondary_color', $payload)) {
            $update['brand_secondary_color'] = self::normalizeColorPure($payload['secondary_color']);
        }
        if (array_key_exists('custom_domain', $payload)) {
            $domain = $payload['custom_domain'] ?? null;
            if ($domain !== null && $domain !== '') {
                $domain = strtolower(trim((string) $domain));
                // Reset verification kalau domain berubah
                if ($domain !== ($before['custom_domain'] ?? null)) {
                    $update['custom_domain']                    = $domain;
                    $update['custom_domain_verification_token'] = bin2hex(random_bytes(16));
                    $update['custom_domain_verified_at']        = null;
                }
            } else {
                $update['custom_domain']                    = null;
                $update['custom_domain_verified_at']        = null;
                $update['custom_domain_verification_token'] = null;
            }
        }

        if (empty($update)) {
            return ['ok' => true, 'errors' => [], 'noop' => true];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $db->table('companies')->where('id', $companyId)->update($update);
        unset(self::$cache[$companyId]);

        $after = $db->table('companies')->where('id', $companyId)->get()->getRowArray();

        // Audit log
        if ($db->tableExists('branding_audit_log')) {
            $db->table('branding_audit_log')->insert([
                'company_id'  => $companyId,
                'user_id'     => $userId,
                'action'      => 'BRANDING_UPDATED',
                'before_json' => json_encode($this->extractBrandingFields($before)),
                'after_json'  => json_encode($this->extractBrandingFields($after)),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'errors' => [], 'data' => $this->resolve($companyId)];
    }

    /**
     * Verify custom domain ownership via DNS TXT record.
     * Tenant harus pasang TXT record `_quantara.{domain}` dengan value =
     * verification_token. Strategi sederhana: pakai dns_get_record().
     */
    public function verifyDomain(int $companyId, int $userId): array
    {
        $db = Database::connect();
        $row = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $row || empty($row['custom_domain']) || empty($row['custom_domain_verification_token'])) {
            return ['ok' => false, 'reason' => 'no_domain_or_token'];
        }
        $domain = (string) $row['custom_domain'];
        $expected = (string) $row['custom_domain_verification_token'];

        $records = @dns_get_record('_quantara.' . $domain, DNS_TXT);
        if (! is_array($records)) {
            return ['ok' => false, 'reason' => 'dns_lookup_failed'];
        }
        $found = false;
        foreach ($records as $r) {
            $txt = (string) ($r['txt'] ?? $r['entries'][0] ?? '');
            if (trim($txt) === $expected) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            return ['ok' => false, 'reason' => 'token_mismatch', 'expected' => $expected];
        }

        $db->table('companies')->where('id', $companyId)->update([
            'custom_domain_verified_at' => date('Y-m-d H:i:s'),
            'updated_at'                => date('Y-m-d H:i:s'),
        ]);
        unset(self::$cache[$companyId]);

        if ($db->tableExists('branding_audit_log')) {
            $db->table('branding_audit_log')->insert([
                'company_id' => $companyId,
                'user_id'    => $userId,
                'action'     => 'DOMAIN_VERIFIED',
                'after_json' => json_encode(['custom_domain' => $domain]),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'verified_at' => date('Y-m-d H:i:s')];
    }

    /**
     * Validation pure rules untuk payload update.
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];

        if (array_key_exists('primary_color', $payload)
            && $payload['primary_color'] !== null
            && self::normalizeColorPure((string) $payload['primary_color']) === null
        ) {
            $errors['primary_color'] = 'invalid hex color (use #RRGGBB)';
        }
        if (array_key_exists('secondary_color', $payload)
            && $payload['secondary_color'] !== null
            && self::normalizeColorPure((string) $payload['secondary_color']) === null
        ) {
            $errors['secondary_color'] = 'invalid hex color (use #RRGGBB)';
        }

        if (array_key_exists('app_name', $payload)
            && $payload['app_name'] !== null
            && strlen((string) $payload['app_name']) > 64
        ) {
            $errors['app_name'] = 'max 64 characters';
        }

        if (array_key_exists('support_email', $payload)
            && $payload['support_email'] !== null
            && $payload['support_email'] !== ''
            && ! filter_var($payload['support_email'], FILTER_VALIDATE_EMAIL)
        ) {
            $errors['support_email'] = 'invalid email';
        }

        if (array_key_exists('custom_domain', $payload)
            && $payload['custom_domain'] !== null
            && $payload['custom_domain'] !== ''
        ) {
            $d = (string) $payload['custom_domain'];
            if (! preg_match('/^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $d)) {
                $errors['custom_domain'] = 'invalid domain format';
            }
        }

        return $errors;
    }

    private function extractBrandingFields(array $row): array
    {
        $keys = [
            'brand_logo_path', 'brand_favicon_path',
            'brand_primary_color', 'brand_secondary_color',
            'brand_login_bg_path', 'brand_email_footer_html',
            'custom_domain', 'custom_domain_verified_at',
            'brand_app_name', 'brand_support_email',
        ];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = $row[$k];
            }
        }
        return $out;
    }

    /**
     * Flush cache untuk company tertentu (atau semua kalau null).
     * Dipanggil kalau ada perubahan branding via super admin override.
     */
    public static function flushCache(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$cache = [];
            return;
        }
        unset(self::$cache[$companyId]);
    }
}