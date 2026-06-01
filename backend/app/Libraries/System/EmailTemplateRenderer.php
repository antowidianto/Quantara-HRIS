<?php

namespace App\Libraries\System;

/**
 * EmailTemplateRenderer
 *
 * Render template email dengan variable substitution `{{var_name}}`.
 * Sprint 7 — sebelumnya `NotificationService::renderEmailHtml()` hardcode
 * HTML; sekarang HR bisa edit template per-company × event_type lewat
 * tabel `email_templates`.
 *
 * Resolusi template (most-specific first):
 *   1. company_id = $companyId AND event_type = $eventType
 *   2. company_id IS NULL (global default) AND event_type = $eventType
 *   3. fallback ke template build-in (lihat `defaultTemplate()`)
 *
 * Variable substitution support:
 *  - `{{employee_name}}`, `{{leave_dates}}`, dll — apa saja yang
 *    diberikan caller di array `$vars`.
 *  - HTML escape default (untuk cegah XSS); pakai `{{!raw_html}}` kalau
 *    sengaja inject HTML (mis. signed link untuk download).
 *  - `{{date}}`, `{{datetime}}`, `{{year}}` — auto-injected kalau caller
 *    tidak override.
 *
 * Pakai sebagai service:
 *
 *   $r = Services::emailTemplate();
 *   $rendered = $r->render(
 *       companyId: 1,
 *       eventType: 'leave.requested',
 *       vars: ['employee_name' => 'Budi', 'leave_dates' => '12-15 Jan'],
 *   );
 *   // → ['subject' => '...', 'body_html' => '...']
 */
class EmailTemplateRenderer
{
    /**
     * Default templates fallback kalau tabel `email_templates` belum
     * di-populate. Indexed by event_type. Sengaja minimum dan generic
     * — perusahaan disarankan override per event_type yang sering dipakai.
     *
     * @return array<string, array{subject: string, body_html: string}>
     */
    private static function defaults(): array
    {
        return [
            // Format default lama (sebelum Sprint 7) untuk backward compat.
            '*' => [
                'subject'   => '{{title}}',
                'body_html' => self::buildDefaultHtml(),
            ],
        ];
    }

    /**
     * Render template untuk event tertentu.
     *
     * @param int|null   $companyId
     * @param string     $eventType
     * @param array      $vars      Substitution variables (key → value).
     *                              Auto-injected: title, message, link_url
     *                              kalau dikirim di vars.
     * @return array{subject: string, body_html: string}
     */
    public function render(?int $companyId, string $eventType, array $vars): array
    {
        $tpl = $this->loadTemplate($companyId, $eventType);
        $vars = $this->withDefaults($vars);

        return [
            'subject'   => $this->substitute($tpl['subject'], $vars),
            'body_html' => $this->substitute($tpl['body_html'], $vars),
        ];
    }

    /**
     * Substitusi variable di string. Public agar bisa dipakai standalone
     * dari caller (mis. WhatsApp template).
     *
     * Format:
     *  - `{{var}}` — HTML-escaped value
     *  - `{{!var}}` — raw HTML (caller responsibility untuk safety)
     */
    public function substitute(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*(!?)([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function ($m) use ($vars) {
                $raw = $m[1] === '!';
                $key = $m[2];
                $val = $vars[$key] ?? '';
                if (is_array($val) || is_object($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                $val = (string) $val;
                return $raw ? $val : htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            },
            $template,
        ) ?? $template;
    }

    /**
     * Load template dari DB dengan fallback prioritas.
     *
     * @return array{subject: string, body_html: string}
     */
    private function loadTemplate(?int $companyId, string $eventType): array
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('email_templates')) {
                return $this->fallbackDefault();
            }

            // 1) Company-specific
            if ($companyId !== null) {
                $row = $db->table('email_templates')
                    ->where('company_id', $companyId)
                    ->where('event_type', $eventType)
                    ->where('is_active', 1)
                    ->limit(1)
                    ->get()
                    ->getRowArray();
                if ($row) {
                    return [
                        'subject'   => (string) ($row['subject'] ?? ''),
                        'body_html' => (string) ($row['body_html'] ?? ''),
                    ];
                }
            }

            // 2) Global default
            $row = $db->table('email_templates')
                ->where('company_id IS NULL')
                ->where('event_type', $eventType)
                ->where('is_active', 1)
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($row) {
                return [
                    'subject'   => (string) ($row['subject'] ?? ''),
                    'body_html' => (string) ($row['body_html'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // DB not available — fallback
        }
        return $this->fallbackDefault();
    }

    /**
     * Inject default vars (title/message/link_url + date/year/datetime)
     * kalau belum disuplai caller.
     */
    private function withDefaults(array $vars): array
    {
        $vars['date']     = $vars['date']     ?? date('Y-m-d');
        $vars['datetime'] = $vars['datetime'] ?? date('Y-m-d H:i:s');
        $vars['year']     = $vars['year']     ?? date('Y');
        $vars['title']    = $vars['title']    ?? 'Notifikasi';
        $vars['message']  = $vars['message']  ?? '';
        $vars['link_url'] = $vars['link_url'] ?? null;

        // Build link button HTML (raw HTML supaya tidak di-escape)
        if (! empty($vars['link_url'])) {
            $safeLink = htmlspecialchars((string) $vars['link_url'], ENT_QUOTES, 'UTF-8');
            $vars['link_button'] = '<p><a href="' . $safeLink
                . '" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;'
                . 'border-radius:6px;text-decoration:none;">Buka di HRIS</a></p>';
        } else {
            $vars['link_button'] = '';
        }
        return $vars;
    }

    /**
     * @return array{subject: string, body_html: string}
     */
    private function fallbackDefault(): array
    {
        return self::defaults()['*'];
    }

    /**
     * Default HTML email template (responsive, neutral styling).
     */
    private static function buildDefaultHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><title>{{title}}</title></head>
<body style="font-family: Arial, sans-serif; background:#f3f4f6; padding:24px;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px 0;color:#111827;">{{title}}</h2>
    <p style="color:#374151;line-height:1.5;">{{message}}</p>
    {{!link_button}}
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
    <p style="color:#9ca3af;font-size:12px;">Email otomatis dari sistem HRIS. Mohon tidak membalas email ini.</p>
  </div>
</body>
</html>
HTML;
    }
}