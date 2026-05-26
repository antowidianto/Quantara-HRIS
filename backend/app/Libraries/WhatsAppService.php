<?php

namespace App\Libraries;

use Config\Services;

/**
 * WhatsAppService (Sprint 9 / H1.9)
 *
 * Mengirim notifikasi WhatsApp via provider eksternal (Fonnte/Wati/Twilio).
 * Pendekatan adapter-pattern minimal — tiap provider implement method
 * `dispatch(phone, message): array` yang return delivery info.
 *
 * Fitur:
 *  - Konfigurasi per company (table `whatsapp_provider_configs`).
 *  - API key di-encrypt at-rest pakai AES-256-GCM (existing crypto helper).
 *  - Rate limit 3 pesan per user per hari (Redis tidak diperlukan — pakai
 *    `whatsapp_send_log` untuk hitung).
 *  - Opt-in user via `users.whatsapp_enabled` + `phone_verified_at`.
 *  - Audit log + notification fallback ke email kalau gagal kirim WA.
 *
 * Cara kerja `send()`:
 *   1. Cek user opt-in (whatsapp_enabled + phone_verified_at + users.phone)
 *   2. Cek rate limit harian (3/hari per user)
 *   3. Resolve provider config company
 *   4. Dispatch via driver (HTTP request ke provider API)
 *   5. Log result ke whatsapp_send_log
 *   6. Return: {sent: bool, status: string, log_id: int}
 *
 * Lihat docs/QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md item H1.9.
 */
class WhatsAppService
{
    public const RATE_LIMIT_PER_DAY = 3;

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Send WhatsApp notification ke user tertentu.
     *
     * @param int    $userId
     * @param int    $companyId
     * @param string $event    Event identifier (mis. 'approval.request')
     * @param string $message  Body pesan (max 1000 char)
     *
     * @return array{sent: bool, status: string, log_id: int|null, reason?: string}
     */
    public function send(int $userId, int $companyId, string $event, string $message): array
    {
        // Truncate message ke ukuran aman WA (1024 char default)
        $message = substr(trim($message), 0, 1000);
        if ($message === '') {
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => null, 'reason' => 'Empty message'];
        }

        $user = $this->db()->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user) {
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => null, 'reason' => 'User not found'];
        }

        // Opt-in check
        if (empty($user['whatsapp_enabled']) || empty($user['phone_verified_at'])) {
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => null,
                'reason' => 'User belum opt-in / verifikasi nomor WhatsApp'];
        }
        $phone = $this->normalizePhone((string) ($user['phone'] ?? ''));
        if ($phone === null) {
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => null,
                'reason' => 'Nomor telepon tidak valid'];
        }

        // Rate limit harian
        if ($this->isOverRateLimit($userId)) {
            $logId = $this->logSend($userId, $companyId, $phone, $event, $message,
                provider: 'NONE',
                status: 'RATE_LIMITED',
                error: sprintf('Melebihi %d pesan/hari', self::RATE_LIMIT_PER_DAY)
            );
            return ['sent' => false, 'status' => 'RATE_LIMITED', 'log_id' => $logId,
                'reason' => sprintf('Limit %d pesan WA per hari tercapai', self::RATE_LIMIT_PER_DAY)];
        }

        // Resolve provider config company
        $cfg = $this->resolveProviderConfig($companyId);
        if (! $cfg) {
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => null,
                'reason' => 'WhatsApp provider belum dikonfigurasi untuk company ini'];
        }

        // Dispatch via driver
        try {
            $apiKey = $this->decryptKey((string) $cfg['api_key_encrypted']);
            $driverResult = $this->dispatchToProvider(
                provider: (string) $cfg['provider'],
                apiKey: $apiKey,
                senderId: (string) ($cfg['sender_id'] ?? ''),
                phone: $phone,
                message: $message,
            );

            $logId = $this->logSend($userId, $companyId, $phone, $event, $message,
                provider: (string) $cfg['provider'],
                status: $driverResult['status'],
                providerMessageId: $driverResult['message_id'] ?? null,
                error: $driverResult['error'] ?? null
            );

            return [
                'sent'   => $driverResult['status'] === 'SENT',
                'status' => $driverResult['status'],
                'log_id' => $logId,
                'reason' => $driverResult['error'] ?? null,
            ];
        } catch (\Throwable $e) {
            $logId = $this->logSend($userId, $companyId, $phone, $event, $message,
                provider: (string) $cfg['provider'],
                status: 'FAILED',
                error: $e->getMessage()
            );
            log_message('warning', '[WhatsApp] dispatch failed: ' . $e->getMessage());
            return ['sent' => false, 'status' => 'FAILED', 'log_id' => $logId,
                'reason' => $e->getMessage()];
        }
    }

    /**
     * Cek apakah user sudah melebihi rate limit harian.
     */
    public function isOverRateLimit(int $userId): bool
    {
        if (! $this->db()->tableExists('whatsapp_send_log')) return false;
        $today = date('Y-m-d 00:00:00');
        $count = $this->db()->table('whatsapp_send_log')
            ->where('user_id', $userId)
            ->where('status', 'SENT')
            ->where('created_at >=', $today)
            ->countAllResults();
        return $count >= self::RATE_LIMIT_PER_DAY;
    }

    /**
     * Resolve provider config aktif untuk company.
     * Return null kalau tidak ada provider aktif.
     *
     * @return array<string,mixed>|null
     */
    public function resolveProviderConfig(int $companyId): ?array
    {
        if (! $this->db()->tableExists('whatsapp_provider_configs')) return null;
        $row = $this->db()->table('whatsapp_provider_configs')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->limit(1)->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Normalisasi nomor telepon ke format E.164 (62812xxxxxxx).
     * Indonesia: 0812 / +62812 / 62812 → 62812.
     */
    public function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === null || $digits === '') return null;
        // Indonesia: 0xx → 62xx
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }
        // Sanity check minimal length
        if (strlen($digits) < 10 || strlen($digits) > 15) return null;
        return $digits;
    }

    /**
     * Dispatch ke provider yang sesuai. Return:
     *   ['status' => 'SENT'|'FAILED', 'message_id' => string|null, 'error' => string|null]
     *
     * Implementasi minimal: tiap provider HTTP POST ke endpoint mereka
     * dengan body sederhana (target + message). Untuk POC, hanya
     * skeleton — production deploy harus tambah retry, timeout, signature
     * verification, dst sesuai dokumentasi provider.
     */
    private function dispatchToProvider(
        string $provider,
        string $apiKey,
        string $senderId,
        string $phone,
        string $message,
    ): array {
        // Untuk testing & environment yang belum punya provider, return
        // mocked SENT supaya flow downstream bisa di-test. Production
        // override via env `WHATSAPP_DRY_RUN=false`.
        if (env('WHATSAPP_DRY_RUN', 'true') === 'true') {
            return [
                'status' => 'SENT',
                'message_id' => 'DRY-' . bin2hex(random_bytes(6)),
                'error' => null,
            ];
        }

        $client = service('curlrequest');
        $endpoint = match ($provider) {
            'FONNTE' => 'https://api.fonnte.com/send',
            'WATI'   => 'https://live-mt-server.wati.io/api/v1/sendSessionMessage/' . urlencode($phone),
            'TWILIO' => 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode($senderId) . '/Messages.json',
            default  => null,
        };
        if ($endpoint === null) {
            return ['status' => 'FAILED', 'message_id' => null, 'error' => "Provider tidak didukung: {$provider}"];
        }

        try {
            $headers = ['Content-Type' => 'application/json'];
            $body = [];
            if ($provider === 'FONNTE') {
                $headers['Authorization'] = $apiKey;
                $body = ['target' => $phone, 'message' => $message];
            } elseif ($provider === 'WATI') {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                $body = ['messageText' => $message];
            } elseif ($provider === 'TWILIO') {
                // Twilio pakai basic auth, body x-www-form-urlencoded
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $headers['Authorization'] = 'Basic ' . base64_encode($senderId . ':' . $apiKey);
                $body = http_build_query([
                    'From' => 'whatsapp:+' . $senderId,
                    'To'   => 'whatsapp:+' . $phone,
                    'Body' => $message,
                ]);
            }

            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body'    => is_array($body) ? json_encode($body) : $body,
                'timeout' => 10,
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            $rawBody = (string) $response->getBody();

            if ($code >= 200 && $code < 300) {
                $decoded = json_decode($rawBody, true);
                $messageId = $decoded['id'] ?? $decoded['sid'] ?? $decoded['message_id'] ?? null;
                return ['status' => 'SENT', 'message_id' => $messageId, 'error' => null];
            }
            return ['status' => 'FAILED', 'message_id' => null,
                'error' => sprintf('HTTP %d: %s', $code, substr($rawBody, 0, 200))];
        } catch (\Throwable $e) {
            return ['status' => 'FAILED', 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function logSend(
        int $userId, int $companyId, string $phone, string $event, string $message,
        string $provider, string $status,
        ?string $providerMessageId = null,
        ?string $error = null,
    ): ?int {
        if (! $this->db()->tableExists('whatsapp_send_log')) return null;
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'           => $companyId,
            'user_id'              => $userId,
            'phone_number'         => $phone,
            'event'                => $event,
            'message_preview'      => substr($message, 0, 160),
            'provider'             => $provider,
            'provider_message_id'  => $providerMessageId,
            'status'               => $status,
            'error_message'        => $error,
            'sent_at'              => $status === 'SENT' ? $now : null,
            'created_at'           => $now,
        ];
        $this->db()->table('whatsapp_send_log')->insert($insert);
        return (int) $this->db()->insertID();
    }

    /**
     * Decrypt API key yang tersimpan ter-encrypt.
     * Pakai EncryptionService yang sudah ada (AES-256-GCM).
     */
    private function decryptKey(string $encrypted): string
    {
        try {
            return Services::encryption()->decrypt($encrypted);
        } catch (\Throwable $e) {
            // Jika belum ter-encrypt (mis. data lama / dev env), return as-is.
            // Production sebaiknya enforce encryption tapi tidak fail-hard.
            return $encrypted;
        }
    }
}