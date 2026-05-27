<?php

namespace App\Libraries;

/**
 * AiAssistantService (Sprint 19+ / H4.1)
 *
 * AI-Powered HR Copilot — adapter pattern dengan driver pluggable.
 *
 * Driver via env `AI_ASSISTANT_DRIVER`:
 *   - `mock` (default DEV) — return canned responses untuk UI development
 *   - `openai` — OpenAI Chat Completions API (gpt-4o, gpt-4-turbo)
 *   - `anthropic` — Claude API (claude-3-5-sonnet)
 *   - `local` — local LLM via Ollama / vLLM HTTP endpoint
 *
 * Privacy-first design:
 *   1. PII redactor — strip NIK, NPWP, no rekening, email, phone dari
 *      pesan user sebelum kirim ke LLM external. Flag `redacted=1`.
 *   2. System prompt scope-aware: AI hanya boleh suggest query yang
 *      dalam permission user. Final exec tetap di-validate backend.
 *   3. Audit trail lengkap di `ai_chat_messages` per turn.
 *   4. No training data leakage — driver real harus set flag privacy.
 *
 * Pure helpers (testable tanpa DB / network):
 *   - redactPII(text)
 *   - estimateTokens(text)
 *   - buildSystemPrompt(userContext)
 */
class AiAssistantService
{
    public const DRIVERS = ['mock', 'openai', 'anthropic', 'local'];
    public const MAX_HISTORY_MESSAGES = 30;
    public const MAX_TOKENS_PER_TURN = 4000;

    private string $driver;

    public function __construct(?string $driver = null)
    {
        $this->driver = strtolower($driver ?? (string) env('AI_ASSISTANT_DRIVER', 'mock'));
        if (! in_array($this->driver, self::DRIVERS, true)) {
            $this->driver = 'mock';
        }
    }

    private function db()
    {
        return \Config\Database::connect();
    }

    /**
     * Pure PII redactor untuk pesan user sebelum kirim ke LLM.
     */
    public static function redactPII(string $text): array
    {
        $original = $text;
        $patterns = [
            '/\b\d{2}\.\d{3}\.\d{3}\.\d{1}-\d{3}\.\d{3}\b/' => '[REDACTED_NPWP]',
            '/\b(\d{16})\b/' => '[REDACTED_NIK]',
            '/\b(\d{15})\b/' => '[REDACTED_NPWP]',
            '/(rekening|rek|account)\s*[:#]?\s*(\d{10,16})/i' => '$1 [REDACTED_ACCOUNT]',
            '/\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/' => '[REDACTED_EMAIL]',
            '/\b(\+62|62|0)8\d{8,11}\b/' => '[REDACTED_PHONE]',
        ];
        $hits = 0;
        foreach ($patterns as $pat => $repl) {
            $count = 0;
            $text = preg_replace($pat, $repl, $text, -1, $count);
            $hits += $count;
        }
        return [
            'text'     => $text,
            'original' => $original,
            'redacted' => $hits > 0,
            'hits'     => $hits,
        ];
    }

    /**
     * Rough token estimate untuk budget tracking.
     * Indonesian ~3.5 chars/token (BPE less optimal than English).
     */
    public static function estimateTokens(string $text): int
    {
        $chars = mb_strlen($text);
        return (int) ceil($chars / 3.5);
    }

    /**
     * Build system prompt scope-aware dari role + permissions user.
     */
    public static function buildSystemPrompt(array $userContext): string
    {
        $name = $userContext['full_name'] ?? 'pengguna';
        $role = $userContext['role'] ?? 'employee';
        $permissions = $userContext['permissions'] ?? [];

        $base = "Kamu adalah Quantara HR Copilot, asisten HR untuk Indonesian HRIS. "
            . "User aktif: {$name} (role: {$role}).\n\n"
            . "ATURAN WAJIB:\n"
            . "1. Selalu jawab dalam Bahasa Indonesia kecuali user explicit minta English.\n"
            . "2. Jangan berikan saran legal definitif — sebut 'konsultasikan ke HR/Legal'.\n"
            . "3. Jangan tampilkan data pribadi karyawan lain kecuali user punya permission.\n"
            . "4. Untuk query data, sarankan endpoint API Quantara — JANGAN expose query SQL.\n"
            . "5. Tolak jika diminta hal di luar scope HR/payroll/operasional.\n\n";

        if (in_array('payroll.view', $permissions, true)
            || in_array('payroll.manage', $permissions, true)) {
            $base .= "User ini punya akses ke data payroll.\n";
        }
        if (in_array('recruitment.manage', $permissions, true)) {
            $base .= "User ini punya akses ke data recruitment.\n";
        }
        if (empty($permissions) && $role === 'employee') {
            $base .= "User ini karyawan biasa — fokus self-service: cek saldo cuti, "
                . "ajukan reimbursement, update profil. Jangan tampilkan data karyawan lain.\n";
        }
        return $base;
    }

    /**
     * Buat session baru atau resume existing.
     *
     * @return array{id: int, driver: string, model: string|null}
     */
    public function startSession(int $companyId, int $userId, ?string $title = null): array
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $model = (string) env('AI_ASSISTANT_MODEL', $this->defaultModel());
        $insert = [
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'title'       => $title,
            'driver'      => $this->driver,
            'model'       => $model,
            'message_count' => 0,
            'token_count' => 0,
            'is_archived' => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('ai_chat_sessions')->insert($insert);
        return [
            'id'     => (int) $db->insertID(),
            'driver' => $this->driver,
            'model'  => $model,
        ];
    }

    /**
     * Kirim pesan user, dapatkan reply assistant. Audit trail lengkap.
     *
     * @param array $userContext  Untuk system prompt (full_name, role, permissions)
     * @return array{
     *   user_message_id: int,
     *   assistant_message_id: int,
     *   reply: string,
     *   redacted: bool,
     *   tokens: int
     * }
     */
    public function sendMessage(int $sessionId, string $userMessage, array $userContext = []): array
    {
        $db = $this->db();
        $session = $db->table('ai_chat_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (! $session) {
            throw new \RuntimeException('Session tidak ditemukan');
        }

        // Redact PII dari user message sebelum stored / dikirim ke driver
        $redact = self::redactPII($userMessage);
        $cleanInput = $redact['text'];
        $userTokens = self::estimateTokens($cleanInput);

        $now = date('Y-m-d H:i:s');
        $db->table('ai_chat_messages')->insert([
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $cleanInput,
            'tokens'     => $userTokens,
            'redacted'   => $redact['redacted'] ? 1 : 0,
            'created_at' => $now,
        ]);
        $userMsgId = (int) $db->insertID();

        // Build conversation history (last N messages)
        $history = $db->table('ai_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'DESC')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()->getResultArray();
        $history = array_reverse($history);

        // Construct full message array dengan system prompt
        $messages = [
            ['role' => 'system', 'content' => self::buildSystemPrompt($userContext)],
        ];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }

        // Dispatch ke driver
        try {
            $reply = match ($this->driver) {
                'openai'    => $this->driverOpenAi($messages),
                'anthropic' => $this->driverAnthropic($messages),
                'local'     => $this->driverLocal($messages),
                default     => $this->driverMock($cleanInput),
            };
        } catch (\Throwable $e) {
            // Fallback ke mock supaya UI tidak hang
            $reply = "[Error driver {$this->driver}: " . $e->getMessage() . "]\n\n"
                . $this->driverMock($cleanInput);
        }

        $assistantTokens = self::estimateTokens($reply);
        $db->table('ai_chat_messages')->insert([
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $reply,
            'tokens'     => $assistantTokens,
            'redacted'   => 0,
            'created_at' => $now,
        ]);
        $assistantMsgId = (int) $db->insertID();

        // Update session counters
        $db->table('ai_chat_sessions')->where('id', $sessionId)->update([
            'message_count' => (int) $session['message_count'] + 2,
            'token_count'   => (int) $session['token_count'] + $userTokens + $assistantTokens,
            'title'         => $session['title'] ?: mb_substr($userMessage, 0, 80),
            'updated_at'    => $now,
        ]);

        return [
            'user_message_id'      => $userMsgId,
            'assistant_message_id' => $assistantMsgId,
            'reply'                => $reply,
            'redacted'             => $redact['redacted'],
            'tokens'               => $userTokens + $assistantTokens,
        ];
    }

    /**
     * List messages dalam satu session (paginated).
     */
    public function listMessages(int $sessionId, int $limit = 100): array
    {
        return $this->db()->table('ai_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Archive session (soft hide dari list user, history tetap ada).
     */
    public function archiveSession(int $sessionId): void
    {
        $this->db()->table('ai_chat_sessions')->where('id', $sessionId)->update([
            'is_archived' => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function defaultModel(): string
    {
        return match ($this->driver) {
            'openai'    => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'local'     => 'llama3:8b',
            default     => 'mock',
        };
    }

    // ─── Drivers ────────────────────────────────────────────────────

    /**
     * Mock driver — return canned response berbasis keyword. Cukup
     * untuk UI development tanpa biaya API.
     */
    private function driverMock(string $userInput): string
    {
        $lower = strtolower($userInput);
        if (str_contains($lower, 'cuti') || str_contains($lower, 'leave')) {
            return "Untuk cek saldo cuti, kunjungi menu **Cuti → Saldo Saya** atau pakai endpoint "
                . "`GET /api/v1/leave-balances/{employee_id}`. Untuk ajukan cuti baru, gunakan "
                . "form di Cuti → Pengajuan.\n\n_Mode: mock — set AI_ASSISTANT_DRIVER ke openai/anthropic/local untuk reply real._";
        }
        if (str_contains($lower, 'gaji') || str_contains($lower, 'payroll') || str_contains($lower, 'thp')) {
            return "Slip gaji bisa dilihat di menu **Payroll → Slip Saya** atau via "
                . "`GET /api/v1/payslips/my`. Untuk simulasi take-home pay, ada fitur "
                . "**Payroll Simulator** di menu HR (butuh permission `payroll.simulate`).\n\n_Mode: mock._";
        }
        if (str_contains($lower, 'lembur') || str_contains($lower, 'overtime')) {
            return "Pengajuan lembur (pre-approval) di menu **Lembur → Rencana** atau "
                . "endpoint `POST /api/v1/overtimes/plan`. Setelah aktual lembur dilakukan, "
                . "input via `realize` endpoint.\n\n_Mode: mock._";
        }
        if (str_contains($lower, 'wfh') || str_contains($lower, 'work from home')) {
            return "WFH harus pre-approved supaya bisa skip geofence saat clock-in. "
                . "Form pengajuan: **Attendance → WFH Request** atau `POST /api/v1/wfh-requests`. "
                . "Default cap 12 hari/bulan.\n\n_Mode: mock._";
        }
        return "Ini reply dari mock driver. Saya bisa bantu dengan query terkait HR Quantara: "
            . "cuti, payroll, lembur, WFH, reimbursement, performance review, training, dan lainnya. "
            . "Coba tanyakan pertanyaan spesifik.\n\n_Mode: mock — ganti AI_ASSISTANT_DRIVER ke "
            . "openai/anthropic/local di .env untuk LLM real._";
    }

    private function driverOpenAi(array $messages): string
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY belum di-set');
        }
        $model = (string) env('AI_ASSISTANT_MODEL', 'gpt-4o-mini');
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'max_tokens' => self::MAX_TOKENS_PER_TURN,
            'store'    => false,  // privacy: jangan simpan di OpenAI side
        ];
        return $this->httpPostJson(
            'https://api.openai.com/v1/chat/completions',
            $payload,
            ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            fn (array $r) => (string) ($r['choices'][0]['message']['content'] ?? ''),
        );
    }

    private function driverAnthropic(array $messages): string
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY belum di-set');
        }
        $model = (string) env('AI_ASSISTANT_MODEL', 'claude-3-5-sonnet-20241022');

        // Anthropic format: system terpisah, user/assistant alternating
        $system = '';
        $convo = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system .= $m['content'] . "\n";
            } else {
                $convo[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        $payload = [
            'model'      => $model,
            'system'     => trim($system),
            'messages'   => $convo,
            'max_tokens' => self::MAX_TOKENS_PER_TURN,
        ];
        return $this->httpPostJson(
            'https://api.anthropic.com/v1/messages',
            $payload,
            ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
            fn (array $r) => (string) ($r['content'][0]['text'] ?? ''),
        );
    }

    private function driverLocal(array $messages): string
    {
        $url = (string) env('AI_ASSISTANT_LOCAL_URL', 'http://localhost:11434/api/chat');
        $model = (string) env('AI_ASSISTANT_MODEL', 'llama3:8b');
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ];
        return $this->httpPostJson(
            $url,
            $payload,
            ['Content-Type: application/json'],
            fn (array $r) => (string) ($r['message']['content'] ?? ''),
        );
    }

    /**
     * Helper HTTP POST dengan JSON body + parser callback.
     */
    private function httpPostJson(string $url, array $payload, array $headers, callable $parser): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException("HTTP error: {$err}");
        }
        if ($http < 200 || $http >= 300) {
            throw new \RuntimeException("HTTP {$http}: " . substr((string) $response, 0, 500));
        }
        $decoded = json_decode((string) $response, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response');
        }
        $result = $parser($decoded);
        if ($result === '') {
            throw new \RuntimeException('Empty content from AI driver');
        }
        return $result;
    }
}