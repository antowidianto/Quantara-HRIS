<?php

namespace App\Libraries\Recruitment;

use Config\Services;

/**
 * OnboardingService
 *
 * Logika domain Onboarding & Offboarding tasks.
 *
 * Tanggung jawab:
 *   1. `applyTemplate($employeeId, $templateId|$type, $anchorDate)` —
 *      generate `employee_tasks` dari `task_templates.tasks` JSON, dengan
 *      `due_date = anchorDate + due_offset_days` per item.
 *   2. `resolveAssignee($task, $employee)` — petakan owner_role ke user
 *      konkret (HR → role 2, IT → permission khusus, SUPERVISOR → atasan
 *      langsung, EMPLOYEE → user karyawan ybs).
 *   3. `progress($employeeId, $type)` — total, completed, mandatory, pct.
 *   4. `notifyAssignee($task)` — kirim notifikasi ke user yang ditugaskan.
 *
 * Owner role yang dikenal:
 *   - HR        → semua user dengan role hr_admin (role 2)
 *   - IT        → user dengan slug 'admin_it' (kalau ada) atau role hr_admin
 *   - FINANCE   → role 5 (finance)
 *   - SUPERVISOR→ atasan langsung karyawan (employee.supervisor_id → user)
 *   - MANAGER   → manager karyawan (employee.manager_id → user)
 *   - EMPLOYEE  → user karyawan ybs
 */
class OnboardingService
{
    /**
     * Apply template ke karyawan. Idempotent: kalau task dengan
     * (employee_id, type, task_key) sudah ada, akan di-skip — tidak duplikasi.
     *
     * @param int      $employeeId
     * @param int|null $templateId  Kalau null, otomatis pakai default per type.
     * @param string   $type        ONBOARD|OFFBOARD
     * @param string   $anchorDate  Tanggal acuan untuk hitung due_date (Y-m-d)
     * @return array {created: int, skipped: int, tasks: array}
     */
    public function applyTemplate(int $employeeId, ?int $templateId, string $type, string $anchorDate): array
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        if (! in_array($type, ['ONBOARD', 'OFFBOARD'], true)) {
            throw new \InvalidArgumentException("Type harus ONBOARD atau OFFBOARD");
        }

        // Resolve template
        $template = null;
        if ($templateId) {
            $template = $db->table('task_templates')
                ->where('id', $templateId)
                ->where('company_id', (int) $emp['company_id'])
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
        }
        if (! $template) {
            $template = $db->table('task_templates')
                ->where('company_id', (int) $emp['company_id'])
                ->where('type', $type)
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'ASC')
                ->limit(1)
                ->get()->getRowArray();
        }
        if (! $template) {
            return ['created' => 0, 'skipped' => 0, 'tasks' => [], 'note' => 'Tidak ada template aktif untuk type ' . $type];
        }

        $tasksJson = $template['tasks'] ?? '[]';
        $tasks = is_string($tasksJson) ? (json_decode($tasksJson, true) ?: []) : (array) $tasksJson;
        if (empty($tasks)) {
            return ['created' => 0, 'skipped' => 0, 'tasks' => [], 'note' => 'Template kosong'];
        }

        $now = date('Y-m-d H:i:s');
        $created = 0; $skipped = 0;
        $output = [];
        $sortOrder = 0;
        foreach ($tasks as $t) {
            $sortOrder++;
            $key = (string) ($t['key'] ?? '');
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') continue;

            // Idempotent: skip kalau task dengan key+type+employee sudah ada
            // (kecuali key kosong → selalu create)
            if ($key !== '') {
                $exists = $db->table('employee_tasks')
                    ->where('employee_id', $employeeId)
                    ->where('type', $type)
                    ->where('task_key', $key)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
                if ($exists > 0) { $skipped++; continue; }
            }

            $offset = (int) ($t['due_offset_days'] ?? 0);
            $due = date('Y-m-d', strtotime($anchorDate . " +{$offset} days"));
            $assigneeId = $this->resolveAssignee((string) ($t['owner_role'] ?? ''), $emp);

            $insert = [
                'company_id'      => (int) $emp['company_id'],
                'employee_id'     => $employeeId,
                'template_id'     => (int) $template['id'],
                'type'            => $type,
                'task_key'        => $key !== '' ? $key : null,
                'task_name'       => $name,
                'description'     => $t['description'] ?? null,
                'owner_role'      => $t['owner_role'] ?? null,
                'assignee_user_id'=> $assigneeId,
                'due_date'        => $due,
                'mandatory'       => isset($t['mandatory']) ? (int) (bool) $t['mandatory'] : 1,
                'sort_order'      => $sortOrder,
                'status'          => 'PENDING',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            $db->table('employee_tasks')->insert($insert);
            $insert['id'] = (int) $db->insertID();
            $output[] = $insert;
            $created++;

            // Notifikasi ke assignee
            if ($assigneeId) {
                $this->notifyAssignee($insert);
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'tasks' => $output, 'template' => $template];
    }

    /**
     * Auto-complete onboarding tasks bila datanya sudah lengkap dari
     * Career Portal application. Dijalankan setelah `applyTemplate()`
     * di flow `RecruitmentService::convertToEmployee`.
     *
     * Aturan auto-complete (semua task harus type=ONBOARD, status=PENDING):
     *   - `SUBMIT_DOCS` — kalau ada minimal 1 dokumen di
     *     `employee_documents` (di-copy dari candidate_documents).
     *   - `NPWP_VERIFY` — kalau `candidate.npwp` valid (15+ digit).
     *   - `BPJS_REGISTER` — kalau `bpjs_kesehatan_no` atau `bpjs_tk_no`
     *     ada di candidate (artinya sudah punya dari kerja sebelumnya;
     *     HR tinggal transfer kepesertaan).
     *   - `SETUP_HRIS` — auto-create user account dengan email candidate
     *     + temp password + role 4 (employee) + must_change_password,
     *     lalu link ke employee_id. Auto-complete kalau berhasil.
     *   - `CONTRACT_SIGN` — TIDAK auto-complete (HR perlu generate dari
     *     contract template + dapat tanda tangan fisik/e-sign).
     *
     * Return ringkasan: `{auto_completed: int, user_created: ?int}`
     */
    public function autoCompleteFromCandidate(int $employeeId, array $candidate): array
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return ['auto_completed' => 0, 'user_created' => null];

        $companyId = (int) $emp['company_id'];
        $now = date('Y-m-d H:i:s');
        $autoCompleted = 0;
        $userCreated = null;

        // 1. SUBMIT_DOCS — dokumen sudah di-copy oleh
        //    RecruitmentService::copyCandidateDocsToEmployee.
        if ($db->tableExists('employee_documents')) {
            $docCount = $db->table('employee_documents')
                ->where('employee_id', $employeeId)
                ->countAllResults();
            if ($docCount > 0) {
                $this->markComplete($employeeId, 'SUBMIT_DOCS',
                    "Auto-complete: {$docCount} dokumen sudah dikumpulkan saat lamaran "
                    . "via Career Portal (KTP/NPWP/ijazah/CV).",
                    $autoCompleted,
                );
            }
        }

        // 2. NPWP_VERIFY — kalau NPWP candidate ada dan format wajar.
        $npwp = preg_replace('/\D+/', '', (string) ($candidate['npwp'] ?? ''));
        if (strlen($npwp) >= 15) {
            $this->markComplete($employeeId, 'NPWP_VERIFY',
                "Auto-complete: NPWP {$this->formatNpwp($npwp)} sudah disubmit pelamar di "
                . "Career Portal dan ter-copy ke profil karyawan. Verifikasi fiskus "
                . "tetap dapat dilakukan HR via DJP Online bila perlu.",
                $autoCompleted,
            );
        }

        // 3. BPJS_REGISTER — kalau pelamar sudah punya BPJS dari kerja
        //    sebelumnya, tugas HR berubah jadi sekedar transfer kepesertaan,
        //    bukan registrasi awal.
        $bpjsKes = preg_replace('/\D+/', '', (string) ($candidate['bpjs_kesehatan_no'] ?? ''));
        $bpjsTk  = preg_replace('/\D+/', '', (string) ($candidate['bpjs_tk_no'] ?? ''));
        if ($bpjsKes !== '' || $bpjsTk !== '') {
            $parts = [];
            if ($bpjsKes !== '') $parts[] = "BPJS Kesehatan: {$bpjsKes}";
            if ($bpjsTk !== '')  $parts[] = "BPJS TK: {$bpjsTk}";
            $this->markComplete($employeeId, 'BPJS_REGISTER',
                "Auto-complete: pelamar sudah memiliki BPJS dari pekerjaan sebelumnya ("
                . implode('; ', $parts) . "). HR tinggal transfer kepesertaan ke "
                . "perusahaan baru via SiPP & e-Dabu.",
                $autoCompleted,
            );
        }

        // 4. SETUP_HRIS — auto-create user akun HRIS.
        $tempPassword = null;
        if (! empty($candidate['email']) && ! empty($emp['email'])) {
            [$userCreated, $tempPassword] = $this->autoCreateUserAccount($emp);
            if ($userCreated) {
                $this->markComplete($employeeId, 'SETUP_HRIS',
                    "Auto-complete: akun HRIS otomatis dibuat untuk {$emp['email']} "
                    . "dengan password sementara (must_change_password=1). "
                    . "HR yang melakukan hire menerima credential lengkap di "
                    . "modal sukses. Karyawan wajib ganti password saat login pertama.",
                    $autoCompleted,
                );
            }
        }

        return [
            'auto_completed' => $autoCompleted,
            'user_created'   => $userCreated,
            'user_email'     => $userCreated ? strtolower((string) $emp['email']) : null,
            // Temp password dikembalikan ONE-TIME ke pemanggil (controller hire)
            // supaya bisa ditampilkan ke HR dengan tombol copy. Tidak disimpan
            // plain text di mana pun. Setelah response ini, HR satu-satunya
            // yang punya credential — kalau hilang, harus reset password manual
            // via admin user page.
            'temp_password'  => $tempPassword,
        ];
    }

    /**
     * Public method untuk menandai task ONBOARD sebagai COMPLETED dari luar.
     * Dipanggil misalnya dari GeneratedContractController saat kontrak
     * ditandatangani.
     */
    public function completeTaskByKey(int $employeeId, string $type, string $taskKey, string $note = ''): void
    {
        $db = \Config\Database::connect();
        $task = $db->table('employee_tasks')
            ->where('employee_id', $employeeId)
            ->where('type', $type)
            ->where('task_key', $taskKey)
            ->where('status', 'PENDING')
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $task) return;
        $now = date('Y-m-d H:i:s');
        $db->table('employee_tasks')->where('id', (int) $task['id'])->update([
            'status'          => 'COMPLETED',
            'completed_at'    => $now,
            'completion_note' => $note ?: "Auto-complete: {$taskKey} selesai via sistem",
            'updated_at'      => $now,
        ]);
        log_message('info', "Task {$taskKey} auto-completed for employee #{$employeeId}");
    }

    /**
     * Mark satu task ONBOARD dengan key tertentu sebagai COMPLETED.
     * Idempotent: skip kalau sudah completed/cancelled. Increment counter
     * via reference.
     */
    private function markComplete(int $employeeId, string $taskKey, string $note, int &$counter): void
    {
        $db = \Config\Database::connect();
        $task = $db->table('employee_tasks')
            ->where('employee_id', $employeeId)
            ->where('type', 'ONBOARD')
            ->where('task_key', $taskKey)
            ->where('status', 'PENDING')
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $task) return; // task tidak ada di template, atau sudah completed
        $now = date('Y-m-d H:i:s');
        $db->table('employee_tasks')->where('id', (int) $task['id'])->update([
            'status'          => 'COMPLETED',
            'completed_at'    => $now,
            'completion_note' => $note,
            'updated_at'      => $now,
        ]);
        $counter++;
        log_message('info', "Onboarding task {$taskKey} auto-completed for employee #{$employeeId}");
    }

    /**
     * Auto-create user account untuk karyawan baru.
     *
     * @return array{0: ?int, 1: ?string} `[user_id, temp_password]`. Both
     *     null kalau gagal (collision email, error DB). Temp password hanya
     *     dikembalikan sekali ke pemanggil dan tidak disimpan plain text
     *     di mana pun setelah ini.
     */
    private function autoCreateUserAccount(array $employee): array
    {
        $db = \Config\Database::connect();
        $email = strtolower(trim((string) ($employee['email'] ?? '')));
        if ($email === '') return [null, null];

        // Cek collision — kalau email sudah dipakai user lain, skip.
        $exists = $db->table('users')->where('email', $email)->countAllResults();
        if ($exists > 0) return [null, null];

        // Generate password sementara 12 char
        $tempPassword = $this->generateTempPassword();
        $hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'  => (int) $employee['company_id'],
            'role_id'     => 4, // role 4 = employee biasa (sesuai konvensi seeder)
            'employee_id' => (int) $employee['id'],
            'email'       => $email,
            'password_hash' => $hash,
            'name'        => $employee['full_name'] ?? $email,
            'must_change_password' => 1,
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        try {
            $db->table('users')->insert($insert);
            $userId = (int) $db->insertID();

            // Update employees.user_id supaya linkage konsisten (kalau kolom ada).
            $empCols = $db->getFieldNames('employees');
            if (in_array('user_id', $empCols, true)) {
                $db->table('employees')->where('id', (int) $employee['id'])->update([
                    'user_id' => $userId,
                    'updated_at' => $now,
                ]);
            }

            // Email langsung ke alamat email yang dipakai pelamar saat lamaran.
            // Berisi password sementara — bypass user notification preferences
            // karena ini onboarding-critical. Email di-enqueue ke
            // `email_queue`, dikirim oleh worker `email:process` dengan retry
            // policy (30s → 6h, max 5x). Body HTML aman: pakai htmlspecialchars
            // untuk semua user-supplied / generated values.
            $this->queueWelcomeEmail(
                companyId: (int) $employee['company_id'],
                toEmail: $email,
                toName: (string) ($employee['full_name'] ?? $email),
                tempPassword: $tempPassword,
            );

            // Notifikasi in-app — tanpa password (sudah lewat email).
            // Kalau email gagal kirim, HR masih punya credential di response
            // hire dan bisa kirim manual.
            try {
                Services::notification()->send(
                    $userId,
                    (int) $employee['company_id'],
                    'onboarding.account_created',
                    'Akun HRIS Anda telah dibuat',
                    "Akun HRIS sudah dibuat untuk email {$email}. Detail login "
                        . "(termasuk password sementara) telah dikirim ke email Anda. "
                        . "Anda wajib mengganti password saat login pertama.",
                    ['employee_id' => (int) $employee['id']],
                    null,
                    true,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Failed to send notification: ' . $e->getMessage());
            }

            // Audit dengan severity tinggi karena involve credential issuance.
            // Password TIDAK dilogged — kalau hilang HR harus reset manual.
            try {
                Services::audit()->forModule('ONBOARDING')->log(
                    action: 'user.auto_create',
                    entity: 'user',
                    entityId: $userId,
                    description: "Auto-create user account #{$userId} untuk employee #{$employee['id']} ({$email}). Temp password issued ke HR yang melakukan hire.",
                    severity: 'NOTICE',
                );
            } catch (\Throwable $e) {
                // ignore
            }

            return [$userId, $tempPassword];
        } catch (\Throwable $e) {
            log_message('warning', 'autoCreateUserAccount failed: ' . $e->getMessage());
            return [null, null];
        }
    }

    /**
     * Enqueue welcome email berisi credential ke karyawan baru.
     *
     * Best-effort: kalau email_queue insert gagal (mis. SMTP belum
     * dikonfigurasi atau email column NULL), log warning tapi jangan
     * abort — HR tetap bisa kirim credential manual via response hire.
     *
     * Email body memakai `htmlspecialchars` untuk semua nilai dinamis
     * untuk mencegah injeksi HTML. Login URL diambil dari env
     * `app.frontendUrl` (fallback `app.baseURL`).
     */
    private function queueWelcomeEmail(int $companyId, string $toEmail, string $toName, string $tempPassword): void
    {
        try {
            // Resolve company name & login URL untuk personalisasi email.
            $db = \Config\Database::connect();
            $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
            $companyName = $company['name'] ?? 'HRIS';
            $loginUrl = $this->resolveLoginUrl();

            $bodyHtml = $this->renderWelcomeEmailHtml(
                companyName: $companyName,
                fullName: $toName,
                email: $toEmail,
                tempPassword: $tempPassword,
                loginUrl: $loginUrl,
            );
            Services::notification()->queueEmail(
                companyId: $companyId,
                toEmail: $toEmail,
                toName: $toName,
                subject: "Selamat datang di {$companyName} — Akun HRIS Anda",
                bodyHtml: $bodyHtml,
            );
            log_message('info', "Welcome email queued for {$toEmail} (company #{$companyId})");
        } catch (\Throwable $e) {
            log_message('warning', 'queueWelcomeEmail failed: ' . $e->getMessage());
            // Tidak throw — credential sudah dikembalikan ke HR via response.
        }
    }

    /**
     * Resolve URL frontend untuk halaman login. Prioritas:
     *   1. env `app.frontendUrl` (mis. `https://hris.acme.co.id`)
     *   2. env `app.baseURL` (kalau frontend di-host bersama backend)
     *   3. Fallback string statis `[Login URL]` (HR akan tau dari context)
     */
    private function resolveLoginUrl(): string
    {
        $url = (string) (env('app.frontendUrl') ?: env('app.baseURL') ?: '');
        $url = rtrim(trim($url), '/');
        if ($url === '') return '[Hubungi HR untuk URL login]';
        return $url . '/login';
    }

    /**
     * Render HTML email selamat datang untuk karyawan baru.
     * Semua nilai dinamis di-escape via `htmlspecialchars`.
     */
    private function renderWelcomeEmailHtml(
        string $companyName,
        string $fullName,
        string $email,
        string $tempPassword,
        string $loginUrl
    ): string {
        $companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
        $nameEsc    = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $emailEsc   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $passEsc    = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
        $loginEsc   = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $loginText  = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $isUrl      = (bool) preg_match('#^https?://#i', $loginUrl);

        $loginButton = $isUrl
            ? '<p style="margin:24px 0"><a href="' . $loginEsc . '" '
                . 'style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-weight:600">Login ke HRIS</a></p>'
                . '<p style="font-size:12px;color:#6b7280">Atau buka URL ini di browser:<br>'
                . '<a href="' . $loginEsc . '" style="color:#2563eb">' . $loginText . '</a></p>'
            : '<p style="font-size:13px;color:#6b7280">URL login: ' . $loginText . '</p>';

        return '<!DOCTYPE html><html lang="id"><body style="font-family:Arial,sans-serif;color:#222;background:#f9fafb;margin:0;padding:0">'
            . '<div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#fff">'
            . '<h1 style="color:#1d4ed8;font-size:22px;margin:0 0 8px">Selamat datang di ' . $companyEsc . '!</h1>'
            . '<p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 16px">Halo <strong>' . $nameEsc . '</strong>,</p>'
            . '<p style="font-size:14px;line-height:1.6;color:#374151">'
            . 'Selamat bergabung dengan tim ' . $companyEsc . '. Akun HRIS Anda sudah dibuat. '
            . 'Gunakan kredensial di bawah untuk login pertama kali:'
            . '</p>'
            . '<div style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:16px 0">'
            . '<table style="width:100%;font-size:14px;border-collapse:collapse">'
            . '<tr><td style="padding:6px 0;color:#6b7280;width:140px">Email login:</td>'
            . '<td style="padding:6px 0;font-family:monospace;color:#111"><strong>' . $emailEsc . '</strong></td></tr>'
            . '<tr><td style="padding:6px 0;color:#6b7280">Password sementara:</td>'
            . '<td style="padding:6px 0;font-family:monospace;color:#111"><strong>' . $passEsc . '</strong></td></tr>'
            . '</table>'
            . '</div>'
            . '<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin:16px 0;font-size:13px;color:#78350f">'
            . '<strong>Penting:</strong> Saat login pertama Anda akan diminta untuk mengganti password. '
            . 'Pastikan password baru Anda aman dan tidak dibagikan ke siapa pun.'
            . '</div>'
            . $loginButton
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">'
            . '<p style="font-size:12px;color:#6b7280;line-height:1.5">'
            . 'Jika Anda tidak merasa melamar atau menerima offering kerja dari ' . $companyEsc . ', '
            . 'mohon abaikan email ini dan hubungi tim HR kami.<br><br>'
            . 'Email otomatis dari sistem HRIS — tidak perlu dibalas.'
            . '</p>'
            . '</div></body></html>';
    }

    private function generateTempPassword(): string
    {
        // 12 char total: 8 random hex (capitalized first) + 3 angka + 1 simbol.
        // Mix uppercase/lowercase/digit/symbol — memenuhi password policy umum.
        $hex = bin2hex(random_bytes(4));        // 8 char hex
        $digits = (string) random_int(100, 999); // 3 digit
        $symbol = ['!','@','#','$','%','&','*'][random_int(0, 6)];
        return ucfirst($hex) . $digits . $symbol; // 8 + 3 + 1 = 12 char
    }

    private function formatNpwp(string $digits): string
    {
        // 15-digit lama: 12.345.678.9-012.000
        // 16-digit baru (NIK-based): tampil apa adanya
        if (strlen($digits) === 15) {
            return sprintf(
                '%s.%s.%s.%s-%s.%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 1),
                substr($digits, 9, 3),
                substr($digits, 12, 3),
            );
        }
        return $digits;
    }

    /**
     * Resolve owner_role → user_id konkret.
     */
    public function resolveAssignee(string $ownerRole, array $employee): ?int
    {
        $db = \Config\Database::connect();
        $companyId = (int) $employee['company_id'];
        $role = strtoupper($ownerRole);

        switch ($role) {
            case 'EMPLOYEE':
                return ! empty($employee['user_id']) ? (int) $employee['user_id'] : null;

            case 'SUPERVISOR':
                if (! empty($employee['supervisor_id'])) {
                    $sup = $db->table('employees')->where('id', (int) $employee['supervisor_id'])->get()->getRowArray();
                    if ($sup && ! empty($sup['user_id'])) return (int) $sup['user_id'];
                }
                return null;

            case 'MANAGER':
                if (! empty($employee['manager_id'])) {
                    $mgr = $db->table('employees')->where('id', (int) $employee['manager_id'])->get()->getRowArray();
                    if ($mgr && ! empty($mgr['user_id'])) return (int) $mgr['user_id'];
                }
                return null;

            case 'HR':
            case 'HR_ADMIN':
                $row = $db->table('users')
                    ->where('company_id', $companyId)
                    ->where('role_id', 2)
                    ->where('is_active', 1)
                    ->orderBy('id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                return $row ? (int) $row['id'] : null;

            case 'FINANCE':
                $row = $db->table('users')
                    ->where('company_id', $companyId)
                    ->where('role_id', 5)
                    ->where('is_active', 1)
                    ->orderBy('id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                return $row ? (int) $row['id'] : null;

            case 'IT':
                // Cari user dgn slug 'it_admin' kalau ada; fallback ke HR
                $row = $db->table('users u')
                    ->select('u.id')
                    ->join('roles r', 'r.id = u.role_id', 'left')
                    ->where('u.company_id', $companyId)
                    ->where('u.is_active', 1)
                    ->where('r.slug', 'it_admin')
                    ->orderBy('u.id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                if ($row) return (int) $row['id'];
                return $this->resolveAssignee('HR', $employee); // fallback

            default:
                return null;
        }
    }

    /**
     * Ringkasan progress task per karyawan untuk type tertentu.
     */
    public function progress(int $employeeId, ?string $type = null): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('employee_tasks')
            ->where('employee_id', $employeeId)
            ->where('deleted_at IS NULL');
        if ($type) $builder->where('type', $type);

        $rows = $builder->get()->getResultArray();
        $total = count($rows);
        $completed = 0;
        $mandatory = 0;
        $mandatoryDone = 0;
        $overdue = 0;
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            if ($r['status'] === 'COMPLETED') $completed++;
            if ((int) $r['mandatory'] === 1) {
                $mandatory++;
                if ($r['status'] === 'COMPLETED' || $r['status'] === 'SKIPPED') $mandatoryDone++;
            }
            if ($r['status'] === 'PENDING' && ! empty($r['due_date']) && $r['due_date'] < $today) {
                $overdue++;
            }
        }
        return [
            'total'          => $total,
            'completed'      => $completed,
            'mandatory'      => $mandatory,
            'mandatory_done' => $mandatoryDone,
            'overdue'        => $overdue,
            'percent'        => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'mandatory_percent' => $mandatory > 0 ? (int) round(($mandatoryDone / $mandatory) * 100) : 0,
            'is_complete'    => $mandatory === 0 || $mandatoryDone >= $mandatory,
        ];
    }

    /**
     * Cari task overdue lintas perusahaan / company tertentu.
     * @return array<int,array>
     */
    public function findOverdue(?int $companyId = null, int $limit = 200): array
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $builder = $db->table('employee_tasks et')
            ->select('et.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = et.employee_id')
            ->where('et.deleted_at IS NULL')
            ->where('et.status', 'PENDING')
            ->where('et.due_date IS NOT NULL')
            ->where('et.due_date <', $today);
        if ($companyId) $builder->where('et.company_id', $companyId);
        return $builder->orderBy('et.due_date', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function notifyAssignee(array $task): void
    {
        if (empty($task['assignee_user_id'])) return;
        $type = $task['type'] === 'OFFBOARD' ? 'Offboarding' : 'Onboarding';
        Services::notification()->send(
            (int) $task['assignee_user_id'],
            (int) $task['company_id'],
            'task.assigned',
            "Tugas {$type} baru ditugaskan ke Anda",
            $task['task_name'] . (! empty($task['due_date']) ? ' (jatuh tempo: ' . $task['due_date'] . ')' : ''),
            ['task_id' => (int) ($task['id'] ?? 0), 'type' => $task['type']],
            null,
            true
        );
    }
}