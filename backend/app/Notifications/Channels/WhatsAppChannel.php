<?php

namespace App\Notifications\Channels;

use App\Notifications\NotificationChannel;
use Config\Services;

/**
 * WhatsAppChannel — kirim notifikasi via WhatsApp Business API.
 *
 * Driver dipilih lewat env `whatsapp.driver`:
 *   - 'fonnte'   → Fonnte (paling populer di Indonesia, harga terjangkau)
 *   - 'twilio'   → Twilio (resmi WhatsApp Business API, lebih ketat compliance)
 *   - 'log'      → DEFAULT untuk dev — tidak kirim, hanya log payload
 *
 * Config yang dibutuhkan:
 *   whatsapp.driver       = log | fonnte | twilio
 *   whatsapp.fonnte.token = <API_TOKEN>
 *   whatsapp.twilio.sid   = <ACCOUNT_SID>
 *   whatsapp.twilio.token = <AUTH_TOKEN>
 *   whatsapp.twilio.from  = whatsapp:+14155238886
 *
 * Tidak throw — return false saat gagal supaya channel lain tetap jalan.
 * Audit log entry per send (success / fail).
 *
 * Catatan compliance: untuk Twilio butuh template approved 24-jam window
 * rule. Driver `log` aman untuk dev, `fonnte` aman untuk MVP, `twilio` butuh
 * setup tambahan template di Twilio console (di luar scope command ini).
 */
class WhatsAppChannel implements NotificationChannel
{
    public function slug(): string
    {
        return 'whatsapp';
    }

    public function isAvailable(array $user): bool
    {
        return ! empty($user['whatsapp_number']);
    }

    public function send(array $user, array $payload): bool
    {
        $driver = (string) (env('whatsapp.driver') ?? 'log');
        $to     = $this->normalizePhone((string) $user['whatsapp_number']);
        if ($to === '') {
            log_message('warning', 'WhatsApp number tidak valid untuk user #' . ($user['id'] ?? '?'));
            return false;
        }

        $body = $this->renderText(
            (string) $payload['title'],
            (string) $payload['message'],
            $payload['link_url'] ?? null,
        );

        try {
            $sent = match ($driver) {
                'fonnte' => $this->sendFonnte($to, $body),
                'twilio' => $this->sendTwilio($to, $body),
                default  => $this->sendLog($to, $body),
            };
            if ($sent) {
                Services::audit()->forModule('NOTIFICATION')->log(
                    action: 'whatsapp.sent',
                    entity: 'notification',
                    entityId: (int) ($payload['notification_id'] ?? 0),
                    description: "WhatsApp terkirim ke {$to} via {$driver}",
                    severity: 'NOTICE',
                );
            }
            return $sent;
        } catch (\Throwable $e) {
            log_message('warning', 'WhatsAppChannel send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format pesan WA — pakai marker `*bold*` minimal yang didukung WA.
     * Jangan terlalu panjang (Fonnte limit 1000 char untuk free tier).
     */
    private function renderText(string $title, string $message, ?string $linkUrl): string
    {
        $body = "*{$title}*\n\n{$message}";
        if ($linkUrl) {
            $body .= "\n\n🔗 {$linkUrl}";
        }
        $body .= "\n\n_— Notifikasi otomatis HRIS_";
        return mb_substr($body, 0, 1000);
    }

    /** Normalisasi ke format E.164 tanpa `+` (62812xxxxxxxx). */
    private function normalizePhone(string $phone): string
    {
        $clean = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($clean, '0')) {
            $clean = '62' . substr($clean, 1);
        } elseif (! str_starts_with($clean, '62')) {
            $clean = '62' . $clean;
        }
        return strlen($clean) >= 10 && strlen($clean) <= 15 ? $clean : '';
    }

    private function sendFonnte(string $to, string $body): bool
    {
        $token = (string) env('whatsapp.fonnte.token', '');
        if ($token === '') {
            log_message('warning', 'whatsapp.fonnte.token tidak di-set, falling back to log');
            return $this->sendLog($to, $body);
        }
        $ch = curl_init('https://api.fonnte.com/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $token],
            CURLOPT_POSTFIELDS     => http_build_query([
                'target'  => $to,
                'message' => $body,
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            log_message('warning', "Fonnte HTTP {$httpCode}: " . substr((string) $resp, 0, 200));
            return false;
        }
        $data = json_decode((string) $resp, true);
        return (bool) ($data['status'] ?? false);
    }

    private function sendTwilio(string $to, string $body): bool
    {
        $sid   = (string) env('whatsapp.twilio.sid', '');
        $token = (string) env('whatsapp.twilio.token', '');
        $from  = (string) env('whatsapp.twilio.from', '');
        if ($sid === '' || $token === '' || $from === '') {
            log_message('warning', 'Twilio config tidak lengkap, fallback to log');
            return $this->sendLog($to, $body);
        }
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$sid}:{$token}",
            CURLOPT_POSTFIELDS     => http_build_query([
                'From' => $from,
                'To'   => 'whatsapp:+' . $to,
                'Body' => $body,
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode >= 200 && $httpCode < 300;
    }

    private function sendLog(string $to, string $body): bool
    {
        log_message('info', "[WhatsAppChannel:dev-log] to={$to} body=" . mb_substr($body, 0, 100));
        return true; // anggap sukses supaya flow downstream tidak retry
    }
}