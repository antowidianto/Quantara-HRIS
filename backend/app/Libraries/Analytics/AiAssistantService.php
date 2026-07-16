<?php

namespace App\Libraries\Analytics;

/**
 * AiAssistantService (Sprint 19+ / H4.1)
 *
 * AI-Powered HR Copilot — adapter pattern dengan driver pluggable.
 *
 * Driver via env `AI_ASSISTANT_DRIVER`:
 *   - `mock` (default DEV) — return canned responses untuk UI development
 *   - `openai` — OpenAI Chat Completions API (gpt-4o, gpt-4-turbo). BERBAYAR.
 *   - `anthropic` — Claude API (claude-3-5-sonnet). BERBAYAR.
 *   - `gemini` — Google Gemini API. GRATIS (1500 req/hari free tier,
 *                gemini-1.5-flash). Daftar API key di https://aistudio.google.com
 *   - `groq` — Groq API. GRATIS (14400 req/hari free tier, Llama 3.x super
 *              cepat). Daftar di https://console.groq.com
 *   - `openrouter` — OpenRouter aggregator. Banyak model `:free` tersedia
 *                    (Llama, Gemma, Mistral). Daftar di https://openrouter.ai
 *   - `local` — local LLM via Ollama / vLLM HTTP endpoint. GRATIS, no internet.
 *
 * Quick free-tier setup di `backend/.env` (rekomendasi: gemini):
 *   AI_ASSISTANT_DRIVER=gemini
 *   GEMINI_API_KEY=AIza...
 *   AI_ASSISTANT_MODEL=gemini-1.5-flash
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
    public const DRIVERS = ['mock', 'custom', 'openai', 'anthropic', 'gemini', 'groq', 'openrouter', 'local'];
    public const MAX_HISTORY_MESSAGES = 30;
    public const MAX_TOKENS_PER_TURN = 4000;

    /** @var array<string, array{label: string, permissions: list<string>}> */
    private const ASSISTANT_UI_LINKS = [
        '/profile'             => ['label' => 'Profil Saya', 'permissions' => []],
        '/leaves'              => ['label' => 'Cuti Saya', 'permissions' => []],
        '/leaves/new'          => ['label' => 'Ajukan Cuti', 'permissions' => []],
        '/leaves/balance'      => ['label' => 'Saldo Cuti', 'permissions' => []],
        '/payslips/my'         => ['label' => 'Slip Gaji Saya', 'permissions' => []],
        '/attendance/my'       => ['label' => 'Kehadiran Saya', 'permissions' => []],
        '/attendance/clock'    => ['label' => 'Clock In/Out', 'permissions' => []],
        '/attendance/wfh'      => ['label' => 'Pengajuan WFH', 'permissions' => []],
        '/performance/my'      => ['label' => 'Kinerja Saya', 'permissions' => []],
        '/hr/my-resignation'  => ['label' => 'Pengajuan Resign', 'permissions' => []],
        '/helpdesk/tickets'   => ['label' => 'Bantuan HR', 'permissions' => []],
        '/reimbursements'     => ['label' => 'Reimbursement', 'permissions' => ['reimbursement.view', 'reimbursement.manage']],
        '/overtimes'          => ['label' => 'Lembur', 'permissions' => ['overtime.view', 'overtime.manage']],
        '/overtimes/plan'     => ['label' => 'Rencana Lembur', 'permissions' => ['overtime.view', 'overtime.manage']],
        '/payroll/periods'    => ['label' => 'Periode Payroll', 'permissions' => ['payroll.view', 'payroll.manage']],
        '/payroll/simulate'   => ['label' => 'Simulasi Payroll', 'permissions' => ['payroll.simulate']],
        '/recruitment/jobs'   => ['label' => 'Lowongan Recruitment', 'permissions' => ['recruitment.manage']],
    ];

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
        $configuredModel = trim((string) env('AI_ASSISTANT_MODEL', ''));

        $base = "Kamu adalah Quantara HR Copilot, asisten HR untuk Indonesian HRIS. "
            . "User aktif: {$name} (role: {$role}).\n\n"
            . "ATURAN WAJIB:\n"
            . "1. Selalu jawab dalam Bahasa Indonesia kecuali user explicit minta English.\n"
            . "2. Jangan berikan saran legal definitif — sebut 'konsultasikan ke HR/Legal'.\n"
            . "3. Jangan tampilkan data pribadi karyawan lain kecuali user punya permission.\n"
            . "4. Jangan pernah tampilkan endpoint API, HTTP method, atau query SQL kepada user. "
            . "Untuk tindakan di aplikasi, arahkan ke menu UI dan bila sesuai berikan tautan Markdown internal.\n"
            . "5. Link internal yang boleh dipakai untuk user ini: " . self::allowedLinksPrompt($userContext)
            . ". Gunakan hanya link tersebut yang relevan; jangan membuat path lain. Jika user meminta "
            . "endpoint, halaman, atau aksi di luar daftar tersebut, jelaskan bahwa aksesnya tidak tersedia dan "
            . "jangan memberi cara melewati permission.\n"
            . "6. Jika ditanya identitas model, jawab bahwa kamu adalah Quantara HR Copilot"
            . ($configuredModel !== '' ? " yang dikonfigurasi menggunakan {$configuredModel}" : '')
            . ". Jangan mengaku sebagai Claude, Anthropic, atau provider/model lain.\n"
            . "7. Tolak jika diminta hal di luar scope HR/payroll/operasional.\n\n";

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
     * Daftar link UI yang boleh direkomendasikan untuk context RBAC ini.
     * Server API tetap menjadi lapisan otorisasi utama untuk setiap aksi.
     *
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public static function allowedAssistantLinks(array $userContext): array
    {
        $permissions = $userContext['permissions'] ?? [];
        $isSuperAdmin = ($userContext['role'] ?? '') === 'super_admin';
        $allowed = [];
        foreach (self::ASSISTANT_UI_LINKS as $path => $definition) {
            if ($isSuperAdmin || $definition['permissions'] === []
                || array_intersect($definition['permissions'], $permissions) !== []) {
                $allowed[$path] = $definition;
            }
        }
        return $allowed;
    }

    /**
     * Defense-in-depth untuk output LLM: hanya pertahankan tautan UI yang
     * sudah lolos RBAC dan hilangkan referensi endpoint API mentah.
     */
    public static function enforceResponsePolicy(string $reply, array $userContext): string
    {
        $allowed = self::allowedAssistantLinks($userContext);
        $reply = preg_replace_callback(
            '/\[([^\]\r\n]{1,120})\]\((\/[A-Za-z0-9_\/?#=&.\-]{0,240})\)/',
            static function (array $match) use ($allowed): string {
                $path = strtok($match[2], '?#');
                return isset($allowed[$path]) ? $match[0] : $match[1];
            },
            $reply,
        );
        $reply = preg_replace(
            '/\b(?:GET|POST|PUT|PATCH|DELETE)\s+`?\/api\/[A-Za-z0-9_\/?#=&.\-]+`?/i',
            '[akses API dibatasi]',
            $reply,
        );
        return preg_replace('/`?\/api\/[A-Za-z0-9_\/?#=&.\-]+`?/i', '[akses API dibatasi]', $reply);
    }

    private static function allowedLinksPrompt(array $userContext): string
    {
        $links = self::allowedAssistantLinks($userContext);
        if ($links === []) {
            return 'tidak ada';
        }
        return implode(', ', array_map(
            static fn (string $path, array $definition): string => "[{$definition['label']}]({$path})",
            array_keys($links),
            array_values($links),
        ));
    }

    /**
     * Buat session baru atau resume existing.
     *
     * @param string|null $scope Scope topik (general/recruitment/payroll/...)
     *                           dipakai sebagai konteks tambahan di system prompt.
     *                           Disimpan di kolom `scope` kalau ada di schema,
     *                           kalau tidak fallback embed ke title.
     *
     * @return array{id: int, driver: string, model: string|null, title: string|null, scope: string|null, message_count: int}
     */
    public function startSession(
        int $companyId,
        int $userId,
        ?string $title = null,
        ?string $scope = null
    ): array {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $model = (string) env('AI_ASSISTANT_MODEL', $this->defaultModel());

        $cols = array_map(
            fn ($c) => strtolower($c->name),
            $db->getFieldData('ai_chat_sessions')
        );

        $insert = [
            'company_id'    => $companyId,
            'user_id'       => $userId,
            'title'         => $title,
            'driver'        => $this->driver,
            'model'         => $model,
            'message_count' => 0,
            'token_count'   => 0,
            'is_archived'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        if ($scope !== null && in_array('scope', $cols, true)) {
            $insert['scope'] = $scope;
        }

        $db->table('ai_chat_sessions')->insert($insert);
        $id = (int) $db->insertID();

        return [
            'id'            => $id,
            'driver'        => $this->driver,
            'model'         => $model,
            'title'         => $title,
            'scope'         => $scope,
            'message_count' => 0,
        ];
    }

    /**
     * Kirim pesan user, dapatkan reply assistant. Audit trail lengkap.
     *
     * Return:
     *   - `user_message`: row lengkap user (id, role, content, tokens, redacted, created_at)
     *   - `assistant_message`: row lengkap assistant
     *   - `redacted`: bool flag PII di pesan user
     *   - `tokens`: total token (user + assistant)
     *
     * Frontend bisa langsung append `assistant_message` ke list & replace
     * optimistic user message dengan `user_message`. Sebelumnya hanya
     * return `reply` string — frontend tidak bisa render hasil.
     *
     * @param array $userContext  Untuk system prompt (full_name, role, permissions)
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
        $userRow = [
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $cleanInput,
            'tokens'     => $userTokens,
            'redacted'   => $redact['redacted'] ? 1 : 0,
            'created_at' => $now,
        ];
        $db->table('ai_chat_messages')->insert($userRow);
        $userMsgId = (int) $db->insertID();
        $userRow['id'] = $userMsgId;

        // Build conversation history (last N messages)
        $history = $db->table('ai_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'DESC')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()->getResultArray();
        $history = array_reverse($history);

        // Inject scope context kalau ada (kolom scope di session) supaya
        // AI lebih fokus ke topik yang dipilih saat session dibuat.
        $sessionScope = $session['scope'] ?? null;
        $systemPrompt = self::buildSystemPrompt($userContext);
        if ($sessionScope) {
            $systemPrompt .= "\nFokus session ini: scope = {$sessionScope}. "
                . "Prioritaskan jawaban yang relevan ke scope tersebut.\n";
        }

        // Construct full message array dengan system prompt
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }

        // Dispatch ke driver
        try {
            $reply = match ($this->driver) {
                'custom'     => $this->driverCustom($messages),
                'openai'     => $this->driverOpenAi($messages),
                'anthropic'  => $this->driverAnthropic($messages),
                'gemini'     => $this->driverGemini($messages),
                'groq'       => $this->driverGroq($messages),
                'openrouter' => $this->driverOpenRouter($messages),
                'local'      => $this->driverLocal($messages),
                default      => $this->driverMock($cleanInput),
            };
        } catch (\Throwable $e) {
            // Fallback ke mock supaya UI tidak hang
            log_message('warning', 'AI driver failed: ' . $e->getMessage());
            $reply = "[Driver {$this->driver} error: " . $e->getMessage() . "]\n\n"
                . $this->driverMock($cleanInput);
        }

        $reply = self::enforceResponsePolicy($reply, $userContext);

        $assistantTokens = self::estimateTokens($reply);
        $assistantRow = [
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $reply,
            'tokens'     => $assistantTokens,
            'redacted'   => 0,
            // Stamp +1 detik supaya timestamp tidak sama dengan user message
            // (UI sort by created_at lebih akurat tanpa harus pakai id).
            'created_at' => date('Y-m-d H:i:s', time() + 1),
        ];
        $db->table('ai_chat_messages')->insert($assistantRow);
        $assistantMsgId = (int) $db->insertID();
        $assistantRow['id'] = $assistantMsgId;

        // Update session counters + auto-set title dari first message
        $newTitle = $session['title'];
        if (empty($newTitle)) {
            $newTitle = mb_substr(trim($userMessage), 0, 80);
        }
        $db->table('ai_chat_sessions')->where('id', $sessionId)->update([
            'message_count' => (int) $session['message_count'] + 2,
            'token_count'   => (int) $session['token_count'] + $userTokens + $assistantTokens,
            'title'         => $newTitle,
            'updated_at'    => $now,
        ]);

        return [
            'user_message'      => $userRow,
            'assistant_message' => $assistantRow,
            'redacted'          => $redact['redacted'],
            'tokens'            => $userTokens + $assistantTokens,
            // Backward compat — endpoint lama mungkin masih konsumsi field ini
            'user_message_id'      => $userMsgId,
            'assistant_message_id' => $assistantMsgId,
            'reply'                => $reply,
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
            'custom'     => '',
            'openai'     => 'gpt-4o-mini',
            'anthropic'  => 'claude-3-5-sonnet-20241022',
            'gemini'     => 'gemini-1.5-flash',          // gratis, 1500 req/hari
            'groq'       => 'llama-3.3-70b-versatile',   // gratis, super cepat
            'openrouter' => 'meta-llama/llama-3.1-8b-instruct:free',
            'local'      => 'llama3:8b',
            default      => 'mock',
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

    /**
     * Provider pihak ketiga yang mengimplementasikan OpenAI-compatible
     * Chat Completions API.
     */
    private function driverCustom(array $messages): string
    {
        $apiKey = trim((string) env('AI_ASSISTANT_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('AI_ASSISTANT_API_KEY belum di-set');
        }

        $model = trim((string) env('AI_ASSISTANT_MODEL', ''));
        if ($model === '') {
            throw new \RuntimeException('AI_ASSISTANT_MODEL belum di-set');
        }

        $baseUrl = rtrim(trim((string) env('AI_ASSISTANT_BASE_URL', '')), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('AI_ASSISTANT_BASE_URL belum di-set');
        }

        $url = str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl . '/chat/completions';
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => self::MAX_TOKENS_PER_TURN,
            'temperature' => 0.7,
        ];

        return $this->httpPostJson(
            $url,
            $payload,
            ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            function (array $r): string {
                $responseModel = preg_replace('/[^A-Za-z0-9._:\/-]/', '', (string) ($r['model'] ?? ''));
                if ($responseModel !== '') {
                    log_message('info', 'AI custom provider response model: ' . $responseModel);
                }
                return (string) ($r['choices'][0]['message']['content'] ?? '');
            },
        );
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
     * Google Gemini — GRATIS dengan free tier 1500 req/hari (gemini-1.5-flash)
     * atau 50 req/hari (gemini-1.5-pro). Daftar API key di
     * https://aistudio.google.com/app/apikey
     *
     * Format Gemini berbeda: pakai `contents` (bukan `messages`) dengan role
     * `user`/`model` (bukan `assistant`). System prompt jadi
     * `systemInstruction` di top-level, bukan inline message.
     */
    private function driverGemini(array $messages): string
    {
        $apiKey = (string) env('GEMINI_API_KEY', env('GOOGLE_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY belum di-set. Daftar gratis di https://aistudio.google.com/app/apikey');
        }
        $model = (string) env('AI_ASSISTANT_MODEL', 'gemini-1.5-flash');

        // Pisahkan system prompt; konversi role assistant→model untuk Gemini
        $system = '';
        $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system .= $m['content'] . "\n";
            } else {
                $contents[] = [
                    'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ];
            }
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => self::MAX_TOKENS_PER_TURN,
                'temperature'     => 0.7,
            ],
        ];
        if (trim($system) !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => trim($system)]]];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key="
            . urlencode($apiKey);

        return $this->httpPostJson(
            $url,
            $payload,
            ['Content-Type: application/json'],
            function (array $r): string {
                $parts = $r['candidates'][0]['content']['parts'] ?? [];
                $text = '';
                foreach ($parts as $p) {
                    $text .= (string) ($p['text'] ?? '');
                }
                return $text;
            },
        );
    }

    /**
     * Groq — GRATIS dengan free tier 14400 req/hari, super cepat (10x lebih
     * cepat dari OpenAI). API kompatibel OpenAI Chat Completions, jadi format
     * payload sama. Daftar di https://console.groq.com/keys
     *
     * Model gratis populer: llama-3.3-70b-versatile, llama-3.1-8b-instant,
     * mixtral-8x7b-32768, gemma2-9b-it.
     */
    private function driverGroq(array $messages): string
    {
        $apiKey = (string) env('GROQ_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('GROQ_API_KEY belum di-set. Daftar gratis di https://console.groq.com/keys');
        }
        $model = (string) env('AI_ASSISTANT_MODEL', 'llama-3.3-70b-versatile');
        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => self::MAX_TOKENS_PER_TURN,
            'temperature'=> 0.7,
        ];
        return $this->httpPostJson(
            'https://api.groq.com/openai/v1/chat/completions',
            $payload,
            ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            fn (array $r) => (string) ($r['choices'][0]['message']['content'] ?? ''),
        );
    }

    /**
     * OpenRouter — aggregator yang menyediakan banyak model gratis (`:free`
     * suffix). API kompatibel OpenAI. Daftar di https://openrouter.ai/keys
     *
     * Model gratis populer (2025+):
     *   - meta-llama/llama-3.1-8b-instruct:free
     *   - meta-llama/llama-3.3-70b-instruct:free
     *   - google/gemma-2-9b-it:free
     *   - mistralai/mistral-7b-instruct:free
     *   - microsoft/phi-3-mini-128k-instruct:free
     *
     * Cek list lengkap di https://openrouter.ai/models?max_price=0
     */
    private function driverOpenRouter(array $messages): string
    {
        $apiKey = (string) env('OPENROUTER_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENROUTER_API_KEY belum di-set. Daftar gratis di https://openrouter.ai/keys');
        }
        $model = (string) env('AI_ASSISTANT_MODEL', 'meta-llama/llama-3.1-8b-instruct:free');
        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => self::MAX_TOKENS_PER_TURN,
            'temperature'=> 0.7,
        ];
        $referer = (string) env('app.baseURL', 'http://localhost');
        return $this->httpPostJson(
            'https://openrouter.ai/api/v1/chat/completions',
            $payload,
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                // OpenRouter rekomendasi (untuk app discovery, opsional)
                'HTTP-Referer: ' . $referer,
                'X-Title: Quantara HRIS',
            ],
            fn (array $r) => (string) ($r['choices'][0]['message']['content'] ?? ''),
        );
    }

    /**
     * Helper HTTP POST dengan JSON body + parser callback.
     */
    private function httpPostJson(string $url, array $payload, array $headers, callable $parser): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ];

        // PHP Windows tertentu tidak punya CA bundle bawaan. Gunakan bundle
        // per-aplikasi bila dikonfigurasi, tanpa menonaktifkan verifikasi TLS.
        $caBundle = trim((string) env('AI_ASSISTANT_CA_BUNDLE', ''));
        if ($caBundle !== '') {
            if (! preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\/)/', $caBundle)) {
                $caBundle = ROOTPATH . ltrim($caBundle, "\\\\/");
            }
            if (! is_readable($caBundle)) {
                throw new \RuntimeException('AI_ASSISTANT_CA_BUNDLE tidak dapat dibaca: ' . $caBundle);
            }
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $options);
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
