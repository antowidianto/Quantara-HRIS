<?php

namespace App\Libraries\Notification;

/**
 * PushLocalizer — Sprint Mobile M7.
 *
 * Resolve title + body push notification ke locale tertentu.
 * Sumber data: tabel `notification_locale_strings` (lihat migration #075).
 *
 * Cara pakai (di NotificationDispatcher / PushNotifier wrapper):
 *
 *   $resolved = $localizer->resolve('leave.approved', 'en', [
 *       'employee_name' => 'Budi',
 *       'date_range'    => '27-29 May 2026',
 *   ]);
 *   // $resolved = ['title' => 'Leave approved', 'body' => 'Hi Budi, your leave...']
 *
 * Lookup order (paling spesifik dulu):
 *   1. (company_id, notif_type, locale)  — override per-tenant
 *   2. (NULL, notif_type, locale)        — global default per-locale
 *   3. (company_id, notif_type, 'id')    — fallback ke Indonesian per-tenant
 *   4. (NULL, notif_type, 'id')          — fallback ke global Indonesian
 *   5. Caller-provided default (title/body asli)
 *
 * Placeholder pakai `{key}` yang disubstitusi dari `$context`. Mising key
 * → biarkan literal supaya jelas saat QA.
 *
 * Cache row pakai TTL 5 menit per (company, type, locale) supaya saat
 * fanout 50+ user tidak hammer DB.
 */
final class PushLocalizer
{
    private const CACHE_TTL = 300; // 5 menit

    /** @var array<string,array{title:string,body:string}|null> in-process cache */
    private array $memoCache = [];

    public function __construct(private readonly ?\CodeIgniter\Database\BaseConnection $db = null)
    {
    }

    /**
     * Resolve title+body untuk satu user. Selalu return array; kalau
     * tidak ada match, fallback ke `$default`.
     *
     * @param array<string,scalar> $context
     * @param array{title?:string,body?:string} $default
     * @return array{title:string,body:string}
     */
    public function resolve(
        string $notifType,
        string $locale,
        array $context = [],
        ?int $companyId = null,
        array $default = [],
    ): array {
        $row = $this->lookup($notifType, $locale, $companyId);
        $title = $row['title'] ?? ($default['title'] ?? $notifType);
        $body  = $row['body']  ?? ($default['body']  ?? '');

        return [
            'title' => self::interpolate($title, $context),
            'body'  => self::interpolate($body, $context),
        ];
    }

    /**
     * Lookup record paling spesifik. Return null kalau benar-benar tidak
     * ada (caller pakai default).
     *
     * @return array{title:string,body:string}|null
     */
    private function lookup(string $notifType, string $locale, ?int $companyId): ?array
    {
        $key = "{$companyId}|{$notifType}|{$locale}";
        if (array_key_exists($key, $this->memoCache)) {
            return $this->memoCache[$key];
        }

        $db = $this->db ?? \Config\Database::connect();

        // Step 1-4: empat candidate query, eksekusi sekuensial sampai dapat row.
        $candidates = [
            ['company_id' => $companyId, 'locale' => $locale],
            ['company_id' => null,        'locale' => $locale],
            ['company_id' => $companyId, 'locale' => 'id'],
            ['company_id' => null,        'locale' => 'id'],
        ];

        foreach ($candidates as $c) {
            // Skip duplicate kalau companyId == null (case 1==2 atau 3==4)
            if ($c['company_id'] === null && $c === $candidates[0]) {
                continue;
            }
            try {
                $b = $db->table('notification_locale_strings')
                    ->where('notif_type', $notifType)
                    ->where('locale', $c['locale']);
                if ($c['company_id'] === null) {
                    $b->where('company_id', null);
                } else {
                    $b->where('company_id', $c['company_id']);
                }
                $row = $b->limit(1)->get()->getRowArray();
                if ($row) {
                    $found = ['title' => (string) $row['title'], 'body' => (string) $row['body']];
                    $this->memoCache[$key] = $found;
                    return $found;
                }
            } catch (\Throwable $e) {
                // Tabel belum dibuat (migration #075 belum jalan) atau DB
                // tidak available. Fail-soft → biarkan caller pakai default.
                $this->memoCache[$key] = null;
                return null;
            }
        }

        $this->memoCache[$key] = null;
        return null;
    }

    /**
     * Interpolate `{key}` placeholder dari context. Pure function; di-static
     * supaya bisa di-test tanpa DB.
     *
     * @param array<string,scalar> $context
     */
    public static function interpolate(string $template, array $context): string
    {
        if ($template === '' || $context === []) return $template;
        $out = $template;
        foreach ($context as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }
}