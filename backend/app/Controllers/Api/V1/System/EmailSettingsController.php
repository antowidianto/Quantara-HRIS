<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * EmailSettingsController
 *
 * UI per-company untuk mengkonfigurasi SMTP. Disimpan di tabel `settings`
 * dengan key `email.*`. Password SMTP di-encrypt at-rest pakai
 * `Services::encryption()` (AES-256-GCM, supports key rotation).
 *
 * Endpoint:
 *   GET   /email-settings           — get current config (password masked)
 *   PUT   /email-settings           — update config (password optional;
 *                                     kosong = pertahankan yang lama)
 *   POST  /email-settings/test      — kirim email test ke alamat tertentu
 *
 * Authorization: super-admin atau punya `settings.manage`.
 *
 * Override priority di runtime:
 *   1. Setting per-company di DB (paling spesifik)
 *   2. ENV `mail.*` di .env (fallback global, juga default kalau company
 *      belum konfigurasi sendiri)
 */
class EmailSettingsController extends ApiController
{
    private const KEYS = [
        'email.host',
        'email.port',
        'email.username',
        'email.encryption',     // tls|ssl|none
        'email.from_address',
        'email.from_name',
        // password disimpan terpisah dengan flag encrypted
        'email.password_enc',
    ];

    public function index()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();

        $db = \Config\Database::connect();
        $rows = $db->table('settings')
            ->where('company_id', $companyId)
            ->whereIn('key', self::KEYS)
            ->get()->getResultArray();

        $cfg = [];
        foreach ($rows as $r) {
            $cfg[$r['key']] = $r['value'];
        }

        // Resolve effective config (DB → fallback ENV)
        $envFallback = [
            'host'         => (string) env('mail.host', ''),
            'port'         => (int) env('mail.port', 587),
            'username'     => (string) env('mail.username', ''),
            'encryption'   => (string) env('mail.encryption', 'tls'),
            'from_address' => (string) env('mail.fromAddress', ''),
            'from_name'    => (string) env('mail.fromName', ''),
        ];

        $hasPassword = ! empty($cfg['email.password_enc']);
        $isDevelopment = $this->isDevelopment();
        $effective = $this->applyDevelopmentMailpit([
            'host'         => $cfg['email.host']         ?? $envFallback['host'],
            'port'         => isset($cfg['email.port']) ? (int) $cfg['email.port'] : $envFallback['port'],
            'username'     => $cfg['email.username']     ?? $envFallback['username'],
            'encryption'   => $cfg['email.encryption']   ?? $envFallback['encryption'],
            'from_address' => $cfg['email.from_address'] ?? $envFallback['from_address'],
            'from_name'    => $cfg['email.from_name']    ?? $envFallback['from_name'],
            'password'     => '',
        ]);

        return $this->ok([
            'config' => [
                ...$effective,
                // Password TIDAK pernah dikembalikan plain text. Frontend
                // pakai placeholder dan submit empty kalau tidak ganti.
                'has_password' => $hasPassword,
                'is_mailpit'   => $isDevelopment,
            ],
            'source' => [
                // Development selalu dipaksa ke Mailpit; selain itu DB → ENV.
                'host'         => $isDevelopment ? 'mailpit (development)' : (isset($cfg['email.host']) ? 'db' : 'env'),
                'port'         => $isDevelopment ? 'mailpit (development)' : (isset($cfg['email.port']) ? 'db' : 'env'),
                'username'     => $isDevelopment ? 'mailpit (development)' : (isset($cfg['email.username']) ? 'db' : 'env'),
                'encryption'   => $isDevelopment ? 'mailpit (development)' : (isset($cfg['email.encryption']) ? 'db' : 'env'),
                'from_address' => isset($cfg['email.from_address']) ? 'db' : 'env',
                'from_name'    => isset($cfg['email.from_name'])    ? 'db' : 'env',
                'password'     => $hasPassword ? 'db' : 'env',
            ],
            'queue_stats' => $this->queueStats($companyId),
        ]);
    }

    public function update()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $companyId = $this->companyId();

        // Validasi: dasarkan di field-by-field. Empty string = clear ke env fallback.
        $allowedEnc = ['tls', 'ssl', 'none', ''];
        if (isset($data['encryption']) && ! in_array((string) $data['encryption'], $allowedEnc, true)) {
            return $this->fail('Encryption harus tls / ssl / none', 422);
        }
        if (isset($data['port'])) {
            $port = (int) $data['port'];
            if ($port < 1 || $port > 65535) {
                return $this->fail('Port harus antara 1 dan 65535', 422);
            }
        }
        if (isset($data['from_address']) && $data['from_address'] !== ''
            && ! filter_var($data['from_address'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Format from_address tidak valid', 422);
        }

        $now = date('Y-m-d H:i:s');
        $map = [
            'host'         => 'email.host',
            'port'         => 'email.port',
            'username'     => 'email.username',
            'encryption'   => 'email.encryption',
            'from_address' => 'email.from_address',
            'from_name'    => 'email.from_name',
        ];
        foreach ($map as $field => $key) {
            if (! array_key_exists($field, $data)) continue;
            $value = (string) $data[$field];
            $this->upsertSetting($companyId, $key, $value, 'STRING', $now);
        }

        // Password handling — encrypt sebelum simpan, hanya kalau caller
        // memberikan value baru (empty string = clear, null/missing = no-op).
        if (array_key_exists('password', $data)) {
            $newPwd = (string) $data['password'];
            if ($newPwd === '') {
                // Clear password — fallback ke env
                $this->deleteSetting($companyId, 'email.password_enc');
            } else {
                $cipher = Services::encryption()->encrypt($newPwd);
                if ($cipher) {
                    $this->upsertSetting($companyId, 'email.password_enc', $cipher, 'STRING', $now);
                }
            }
        }

        // Audit — TANPA password value, hanya keys yang berubah.
        $changed = array_keys(array_intersect_key(
            $data, array_flip(['host','port','username','encryption','from_address','from_name','password']),
        ));
        Services::audit()->forModule('SETTINGS')->log(
            action: 'email.update',
            entity: 'settings',
            entityId: 0,
            after: ['changed_fields' => $changed],
            description: 'Update SMTP email settings: ' . implode(', ', $changed),
            severity: 'NOTICE',
        );

        return $this->ok(null, 'Email settings updated');
    }

    /**
     * Kirim email test ke alamat yang diberikan untuk verify konfigurasi.
     * Pakai konfigurasi DB kalau ada (bukan ENV) supaya admin bisa test
     * setting BARU sebelum save permanent.
     *
     * Body:
     *   - to: email tujuan (wajib)
     *   - subject (opsional, default "Tes konfigurasi email HRIS")
     *   - body (opsional, default sample HTML)
     *   - override (opsional): {host, port, username, password, encryption,
     *     from_address, from_name} untuk dry-run pakai config baru tanpa
     *     persist dulu.
     */
    public function test()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $to = trim((string) ($data['to'] ?? ''));
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Email tujuan tidak valid', 422);
        }

        $subject = (string) ($data['subject'] ?? 'Tes konfigurasi email HRIS');
        $body    = (string) ($data['body'] ?? $this->sampleBody());

        $cfg = $this->resolveConfig($this->companyId(), $data['override'] ?? null);
        if (empty($cfg['host'])) {
            return $this->fail('Host SMTP belum dikonfigurasi', 422);
        }

        try {
            $this->sendDirectly($cfg, $to, $subject, $body);
        } catch (\Throwable $e) {
            // Kembalikan pesan error mentah supaya admin bisa diagnose
            // (mis. "SMTP Error: Could not authenticate.")
            return $this->fail('Gagal kirim test email: ' . $e->getMessage(), 502);
        }

        Services::audit()->forModule('SETTINGS')->log(
            action: 'email.test_send',
            entity: 'settings',
            entityId: 0,
            description: "Test email terkirim ke {$to}",
            severity: 'INFO',
        );

        return $this->ok(['to' => $to], 'Test email berhasil dikirim — cek inbox tujuan');
    }

    // ───────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('settings.manage');
    }

    private function upsertSetting(int $companyId, string $key, string $value, string $type, string $now): void
    {
        $db = \Config\Database::connect();
        $existing = $db->table('settings')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->get()->getRowArray();
        if ($existing) {
            $db->table('settings')->where('id', $existing['id'])->update([
                'value' => $value, 'data_type' => $type, 'updated_at' => $now,
            ]);
        } else {
            $db->table('settings')->insert([
                'company_id' => $companyId,
                'key'        => $key,
                'value'      => $value,
                'data_type'  => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function deleteSetting(int $companyId, string $key): void
    {
        \Config\Database::connect()->table('settings')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Resolve effective email config: prioritas override → DB → env.
     * Return array with keys: host, port, username, password (decrypted),
     *   encryption, from_address, from_name.
     */
    private function resolveConfig(int $companyId, ?array $override = null): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table('settings')
            ->where('company_id', $companyId)
            ->whereIn('key', self::KEYS)
            ->get()->getResultArray();
        $kv = array_column($rows, 'value', 'key');

        $cfg = [
            'host'         => $kv['email.host']         ?? (string) env('mail.host', ''),
            'port'         => (int) ($kv['email.port']  ?? (int) env('mail.port', 587)),
            'username'     => $kv['email.username']     ?? (string) env('mail.username', ''),
            'encryption'   => $kv['email.encryption']   ?? (string) env('mail.encryption', 'tls'),
            'from_address' => $kv['email.from_address'] ?? (string) env('mail.fromAddress', 'noreply@hris.local'),
            'from_name'    => $kv['email.from_name']    ?? (string) env('mail.fromName', 'HRIS'),
            'password'     => '',
        ];
        // Decrypt password: prioritas override.password → DB encrypted → env
        if (is_array($override) && isset($override['password']) && $override['password'] !== '') {
            $cfg['password'] = (string) $override['password'];
        } elseif (! empty($kv['email.password_enc'])) {
            $cfg['password'] = (string) (Services::encryption()->decrypt($kv['email.password_enc']) ?? '');
        } else {
            $cfg['password'] = (string) env('mail.password', '');
        }

        // Apply field overrides selain password
        if (is_array($override)) {
            foreach (['host','port','username','encryption','from_address','from_name'] as $f) {
                if (isset($override[$f]) && $override[$f] !== '') {
                    $cfg[$f] = $f === 'port' ? (int) $override[$f] : (string) $override[$f];
                }
            }
        }
        return $this->applyDevelopmentMailpit($cfg);
    }

    /**
     * Development safety net: semua email ditangkap Mailpit, termasuk jika
     * tenant punya SMTP production tersimpan di DB.
     */
    private function applyDevelopmentMailpit(array $cfg): array
    {
        if (! $this->isDevelopment()) {
            return $cfg;
        }

        $cfg['host'] = (string) env('mail.mailpitHost', 'localhost');
        $cfg['port'] = (int) env('mail.mailpitPort', 1025);
        $cfg['username'] = '';
        $cfg['password'] = '';
        $cfg['encryption'] = 'none';

        return $cfg;
    }

    private function isDevelopment(): bool
    {
        return strtolower((string) env('CI_ENVIRONMENT', ENVIRONMENT)) === 'development';
    }

    private function sendDirectly(array $cfg, string $to, string $subject, string $bodyHtml): void
    {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host     = $cfg['host'];
        $mailer->Port     = (int) $cfg['port'];
        $mailer->SMTPAuth = ! empty($cfg['username']);
        if ($mailer->SMTPAuth) {
            $mailer->Username = $cfg['username'];
            $mailer->Password = $cfg['password'];
        }
        $enc = strtolower((string) $cfg['encryption']);
        if ($enc === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mailer->Timeout = 15; // jangan tunggu 60 detik di test
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($cfg['from_address'], $cfg['from_name']);
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body    = $bodyHtml;
        $mailer->AltBody = strip_tags($bodyHtml);
        $mailer->send();
    }

    private function sampleBody(): string
    {
        $now = date('Y-m-d H:i:s');
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif">'
            . '<div style="max-width:600px;margin:24px auto;padding:24px;border:1px solid #e5e7eb;border-radius:8px">'
            . '<h2 style="color:#1d4ed8;margin:0 0 8px">✉ Tes Konfigurasi Email Berhasil</h2>'
            . '<p>Konfigurasi SMTP HRIS Anda berfungsi dengan baik. Kalau Anda menerima email ini, '
            . 'berarti karyawan baru juga akan menerima credential mereka secara otomatis saat dihire via Career Portal.</p>'
            . '<p style="font-size:12px;color:#6b7280">Dikirim pada: ' . $now . '</p>'
            . '</div></body></html>';
    }

    private function queueStats(int $companyId): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('email_queue')) {
            return ['queued' => 0, 'sent' => 0, 'failed' => 0];
        }
        $b = $db->table('email_queue')->where('company_id', $companyId);
        $stats = ['queued' => 0, 'sent' => 0, 'failed' => 0];
        foreach (['QUEUED', 'SENT', 'FAILED'] as $st) {
            $bb = clone $b;
            $stats[strtolower($st)] = (int) $bb->where('status', $st)->countAllResults();
        }
        return $stats;
    }
}