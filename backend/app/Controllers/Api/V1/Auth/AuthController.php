<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class AuthController extends ApiController
{
    public function login()
    {
        $data = $this->getJsonInput();

        // Field `identifier` (baru): bisa email ATAU NIK karyawan.
        // Backward-compat: kalau caller masih kirim `email`, dipakai
        // sebagai identifier juga supaya legacy client (mobile, integrasi)
        // tidak break.
        $identifierRaw = (string) ($data['identifier'] ?? $data['email'] ?? '');
        $valid = $this->validateOrFail(
            ['identifier' => $identifierRaw, 'password' => $data['password'] ?? ''],
            [
                'identifier' => 'required|max_length[191]',
                'password'   => 'required|min_length[6]|max_length[200]',
            ],
        );
        if (! $valid) return null;

        $identifier = trim($identifierRaw);
        $password = (string) $data['password'];

        $db = \Config\Database::connect();

        // Resolve user: dua jalur identifier
        //   1. Format mengandung "@" → treat as email (legacy primary).
        //   2. Else → treat as NIK karyawan (employee_number);
        //      lookup users via employees.employee_number.
        // Untuk audit, simpan baik email yang resolved (kalau ada) maupun
        // NIK yang dicoba — supaya HR bisa diagnose login gagal lewat
        // login_attempts log.
        $user = null;
        $resolvedVia = 'email';
        $logIdentifier = strtolower($identifier); // untuk login_attempts.email
        if (str_contains($identifier, '@')) {
            $email = strtolower($identifier);
            $user = $db->table('users')->where('email', $email)->get()->getRowArray();
            $logIdentifier = $email;
        } else {
            // NIK lookup — case-sensitive di kebanyakan DB tapi pakai trim.
            // Join via employees.user_id (kolom existing) — kalau NIK belum
            // ter-link ke user, login akan dianggap "unknown_email" supaya
            // pesan error generic (tidak bocorin apakah NIK exist atau tidak).
            $row = $db->table('employees e')
                ->select('u.*')
                ->join('users u', 'u.id = e.user_id')
                ->where('e.employee_number', $identifier)
                ->where('e.deleted_at IS NULL')
                ->get()->getRowArray();
            if ($row) {
                $user = $row;
                $resolvedVia = 'nik';
                $logIdentifier = strtolower((string) ($row['email'] ?? $identifier));
            }
        }

        $ip = $this->request->getIPAddress();
        $ua = substr((string) $this->request->getUserAgent(), 0, 255);

        if (! $user) {
            $db->table('login_attempts')->insert([
                'email' => $logIdentifier, 'ip_address' => $ip, 'success' => 0,
                'reason' => 'unknown_identifier', 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $msg = $resolvedVia === 'nik'
                ? "Login gagal: NIK tidak terdaftar atau belum ter-link ke akun ({$identifier})"
                : "Login gagal: email tidak terdaftar ({$logIdentifier})";
            Services::audit()
                ->forModule('AUTH')
                ->logFailure('auth.login', null, null, 'INVALID_CREDENTIALS', $msg);
            return $this->fail('Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        // Account lockout check
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return $this->fail('Account locked. Try again later.', 423, 'ACCOUNT_LOCKED');
        }

        if ((int) $user['is_active'] !== 1) {
            return $this->fail('Account is disabled', 403, 'ACCOUNT_DISABLED');
        }

        if (! password_verify($password, $user['password_hash'])) {
            $failed = (int) $user['failed_attempts'] + 1;
            $update = ['failed_attempts' => $failed, 'updated_at' => date('Y-m-d H:i:s')];
            $maxAttempts = (int) env('ratelimit.loginAttempts', 5);
            $locked = false;
            if ($failed >= $maxAttempts) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (int) env('ratelimit.loginWindow', 900));
                $locked = true;
            }
            $db->table('users')->where('id', $user['id'])->update($update);
            $db->table('login_attempts')->insert([
                'email' => $logIdentifier, 'ip_address' => $ip, 'success' => 0,
                'reason' => 'bad_password', 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $audit = Services::audit()->forModule('AUTH');
            if ($locked) {
                $audit->logCritical('auth.login.locked', 'user', (int) $user['id'],
                    "Akun dikunci sementara setelah {$failed} percobaan gagal: {$logIdentifier}");
            } else {
                $audit->logFailure('auth.login', 'user', (int) $user['id'], 'INVALID_CREDENTIALS',
                    "Login gagal (password salah, percobaan ke-{$failed}, via {$resolvedVia}): {$logIdentifier}");
            }
            return $this->fail('Invalid credentials', 401, 'INVALID_CREDENTIALS');
        }

        // Rehash if needed
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $db->table('users')->where('id', $user['id'])->update([
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ]);
        }

        // 2FA challenge
        if ((int) $user['twofa_enabled'] === 1) {
            $challenge = bin2hex(random_bytes(16));
            $cache = Services::cache();
            $cache->save('2fa:' . $challenge, ['user_id' => $user['id']], 300);
            return $this->ok([
                'requires_2fa' => true,
                'challenge'    => $challenge,
            ], 'Two-factor required');
        }

        // Reset failed counter, update last login
        $db->table('users')->where('id', $user['id'])->update([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip,
        ]);
        $db->table('login_attempts')->insert([
            'email' => $logIdentifier, 'ip_address' => $ip, 'success' => 1,
            'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $employee = $db->table('employees')->where('user_id', $user['id'])->get()->getRowArray();
        $companyId = $employee['company_id'] ?? $user['company_id'] ?? null;
        $user['employee_id'] = $employee['id'] ?? null;

        $tokens = Services::jwt()->issueTokenPair($user, $companyId ? (int) $companyId : null);

        Services::audit()
            ->forModule('AUTH')
            ->log(
                action: 'auth.login',
                entity: 'user',
                entityId: (int) $user['id'],
                description: "Login berhasil (via {$resolvedVia}): {$logIdentifier}",
                metadata: ['email' => $logIdentifier, 'via' => $resolvedVia, 'company_id' => $companyId]
            );

        return $this->ok([
            'tokens' => $tokens,
            'user'   => $this->shapeUser($user, $companyId),
            'must_change_password' => (int) $user['must_change_password'] === 1,
        ], 'Login successful');
    }

    public function verifyTwoFactor()
    {
        $data = $this->getJsonInput();
        // Accept either:
        //  - `code`        : 6 digit TOTP dari authenticator app
        //  - `backup_code` : 10 char alfanumerik recovery code (Sprint 3)
        // Salah satu wajib ada.
        $valid = $this->validateOrFail($data, [
            'challenge'   => 'required|alpha_numeric|max_length[64]',
            'code'        => 'permit_empty|numeric|exact_length[6]',
            'backup_code' => 'permit_empty|max_length[32]',
        ]);
        if (! $valid) return null;

        if (empty($data['code']) && empty($data['backup_code'])) {
            return $this->fail('Wajib isi kode 2FA atau backup code', 400, 'MISSING_2FA_CODE');
        }

        $cache = Services::cache();
        $entry = $cache->get('2fa:' . $data['challenge']);
        if (! $entry) {
            return $this->fail('Invalid or expired challenge', 400, 'INVALID_CHALLENGE');
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $entry['user_id'])->get()->getRowArray();
        if (! $user) return $this->fail('User not found', 404);

        $usedBackup = false;
        if (! empty($data['backup_code'])) {
            // Sprint 3 — recovery via backup code
            if (! Services::twoFactorBackupCodes()->verify((int) $user['id'], (string) $data['backup_code'])) {
                Services::audit()->forModule('AUTH')->logFailure(
                    'auth.2fa.backup_invalid',
                    'user', (int) $user['id'],
                    'INVALID_BACKUP_CODE',
                    "Backup code 2FA tidak valid: {$user['email']}"
                );
                return $this->fail('Invalid 2FA backup code', 401, 'INVALID_2FA_BACKUP');
            }
            $usedBackup = true;
        } else {
            $tfa = new \RobThree\Auth\TwoFactorAuth((string) env('twofa.issuer', 'HRIS'));
            if (! $tfa->verifyCode($user['twofa_secret'], $data['code'])) {
                return $this->fail('Invalid 2FA code', 401, 'INVALID_2FA');
            }
        }

        $cache->delete('2fa:' . $data['challenge']);

        if ($usedBackup) {
            $remaining = Services::twoFactorBackupCodes()->remainingCount((int) $user['id']);
            Services::audit()->forModule('AUTH')->log(
                action: 'auth.2fa.backup_used',
                entity: 'user', entityId: (int) $user['id'],
                description: "Login via backup code 2FA: {$user['email']} (sisa {$remaining} kode)",
                severity: 'NOTICE'
            );
        }

        $db->table('users')->where('id', $user['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $this->request->getIPAddress(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);

        $employee = $db->table('employees')->where('user_id', $user['id'])->get()->getRowArray();
        $companyId = $employee['company_id'] ?? $user['company_id'] ?? null;
        $user['employee_id'] = $employee['id'] ?? null;

        $tokens = Services::jwt()->issueTokenPair($user, $companyId ? (int) $companyId : null);

        $resp = [
            'tokens' => $tokens,
            'user'   => $this->shapeUser($user, $companyId),
        ];
        if ($usedBackup) {
            $resp['used_backup_code'] = true;
            $resp['backup_codes_remaining'] = Services::twoFactorBackupCodes()->remainingCount((int) $user['id']);
        }
        return $this->ok($resp, '2FA verified');
    }

    public function refresh()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'refresh_token' => 'required|string',
        ]);
        if (! $valid) return null;

        $tokens = Services::jwt()->rotate($data['refresh_token']);
        if (! $tokens) {
            return $this->fail('Invalid or expired refresh token', 401, 'INVALID_REFRESH');
        }
        return $this->ok(['tokens' => $tokens], 'Token refreshed');
    }

    public function logout()
    {
        // JWT payload sekarang disimpan di TenantContext (lihat
        // JwtAuthFilter). Sebelumnya pakai $this->request->jwt yang
        // memicu PHP 8.2 dynamic property deprecation.
        $payload = $this->tenant()->getJwtPayload();
        $jti = $payload['jti'] ?? null;
        if ($jti) {
            // We can only revoke refresh tokens by user; revoke all is safest
            Services::jwt()->revokeAllForUser($this->userId());
        }
        Services::audit()
            ->forModule('AUTH')
            ->log(
                action: 'auth.logout',
                entity: 'user',
                entityId: $this->userId(),
                description: 'Logout & seluruh refresh token dicabut'
            );
        return $this->ok(null, 'Logged out');
    }

    /**
     * GET /api/v1/auth/sessions — Sprint A / B5
     *
     * Daftar refresh token aktif untuk user (= "Active Sessions" / device list).
     * UI di Profile.vue boleh display ini supaya user bisa lihat dirinya
     * login di mana saja & revoke per device atau "log out other devices".
     */
    public function listSessions()
    {
        $sessions = Services::jwt()->listActiveSessions($this->userId());
        return $this->ok(['sessions' => $sessions]);
    }

    /**
     * DELETE /api/v1/auth/sessions/{jti} — Sprint A / B5
     *
     * Revoke refresh token tertentu. Hanya boleh revoke milik user sendiri
     * (server cek user_id match). Setelah revoke, device tersebut harus
     * login ulang saat access tokennya kedaluwarsa.
     */
    public function revokeSession($jti = null)
    {
        $jti = (string) $jti;
        if ($jti === '' || strlen($jti) > 64) {
            return $this->fail('JTI tidak valid', 400);
        }
        $ok = Services::jwt()->revokeByUserAndJti($this->userId(), $jti);
        if (! $ok) {
            return $this->fail('Sesi tidak ditemukan atau bukan milik Anda', 404);
        }
        Services::audit()->forModule('AUTH')->log(
            action: 'auth.session.revoke',
            entity: 'user',
            entityId: $this->userId(),
            description: "Mencabut sesi (jti={$jti})",
            severity: 'NOTICE',
            metadata: ['jti' => $jti],
        );
        return $this->ok(null, 'Sesi dicabut');
    }

    /**
     * POST /api/v1/auth/sessions/revoke-others — Sprint A / B5
     *
     * Revoke semua refresh token milik user kecuali sesi yang aktif sekarang.
     * Berguna ketika user lupa logout di device publik.
     */
    public function revokeOtherSessions()
    {
        // jti dari refresh token tidak ada di access payload, jadi kita pakai
        // last-used heuristic: cari refresh token milik user yang last_used_at
        // paling baru — itulah sesi current device. Fallback: revoke semua.
        $userId = $this->userId();
        $db = \Config\Database::connect();
        $current = $db->table('refresh_tokens')
            ->select('jti')
            ->where('user_id', $userId)
            ->where('revoked', 0)
            ->orderBy('last_used_at IS NULL', 'ASC')
            ->orderBy('last_used_at', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        $exceptJti = (string) ($current['jti'] ?? '');
        $count = Services::jwt()->revokeAllExcept($userId, $exceptJti);

        Services::audit()->forModule('AUTH')->log(
            action: 'auth.session.revoke_others',
            entity: 'user',
            entityId: $userId,
            description: "Mencabut {$count} sesi lain (kecuali sesi current)",
            severity: 'NOTICE',
            metadata: ['revoked_count' => $count, 'kept_jti' => $exceptJti],
        );
        return $this->ok(['revoked_count' => $count], 'Sesi lain dicabut');
    }

    public function me()
    {
        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        if (! $user) return $this->fail('User not found', 404);

        $companyId = $this->companyId();
        $employee = null;
        if ($user['employee_id']) {
            $employee = $db->table('employees')->where('id', $user['employee_id'])->get()->getRowArray();
        }

        // Permissions
        $perms = [];
        if ($user['role_id']) {
            $rows = $db->table('role_permissions rp')
                ->select('p.slug')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $user['role_id'])
                ->get()
                ->getResultArray();
            foreach ($rows as $r) $perms[] = $r['slug'];
        }

        $company = null;
        if ($companyId) {
            $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        }

        $role = null;
        if ($user['role_id']) {
            $role = $db->table('roles')->where('id', $user['role_id'])->get()->getRowArray();
        }

        $branch = null;
        if ($employee && ! empty($employee['branch_id'])) {
            $branch = $db->table('branches')
                ->select('id, code, name, company_id')
                ->where('id', (int) $employee['branch_id'])
                ->get()
                ->getRowArray();
        }

        // Generate signed URL untuk avatar supaya frontend bisa langsung
        // pakai di <img>. Kalau employee punya avatar, prioritaskan itu
        // (foto resmi HR); fallback ke avatar di users (yang user upload
        // sendiri lewat /auth/me/avatar).
        $avatarPath = $employee['avatar_path'] ?? $user['avatar_path'] ?? null;
        $avatarUrl = null;
        if ($avatarPath) {
            try {
                $avatarUrl = Services::fileStorage()->signedUrl($avatarPath, 3600);
            } catch (\Throwable $e) { /* file mungkin sudah hilang, abaikan */ }
        }

        $userOut = $this->shapeUser($user, $companyId);
        $userOut['avatar_url'] = $avatarUrl;

        return $this->ok([
            'user'        => $userOut,
            'employee'    => $employee ? [
                'id' => $employee['id'],
                'employee_number' => $employee['employee_number'],
                'full_name' => $employee['full_name'],
                'position_id' => $employee['position_id'],
                'department_id' => $employee['department_id'],
                'branch_id' => $employee['branch_id'] ?? null,
                'avatar_path' => $employee['avatar_path'] ?? null,
                'avatar_url'  => $avatarUrl,
            ] : null,
            'company'     => $company ? ['id' => $company['id'], 'code' => $company['code'], 'name' => $company['name'], 'logo_path' => $company['logo_path']] : null,
            'branch'      => $branch,
            'role'        => $role ? ['id' => $role['id'], 'slug' => $role['slug'], 'name' => $role['name']] : null,
            'permissions' => $perms,
        ]);
    }

    /**
     * Upload foto profil user.
     *
     * Endpoint: POST /api/v1/auth/me/avatar
     * Body:     multipart/form-data dengan field `file` (image/jpeg|png|webp ≤ 2 MB)
     *
     * Self-service: tidak butuh permission khusus, user hanya mengubah
     * avatar-nya sendiri. Jika user terhubung ke record `employees`, kolom
     * `employees.avatar_path` juga diupdate sehingga foto konsisten muncul
     * di profil HR & list karyawan.
     */
    public function uploadAvatar()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File tidak valid: '
                . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        $maxBytes = 2 * 1024 * 1024;  // 2 MB cukup untuk avatar JPEG quality 85
        if ($file->getSize() > $maxBytes) {
            return $this->fail('Ukuran foto melebihi 2 MB', 413);
        }
        $mime = $file->getMimeType();
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($mime, $allowedMime, true)) {
            return $this->fail('Hanya JPEG/PNG/WebP yang diperbolehkan', 415);
        }

        // Sprint 3 — verify magic bytes, jangan trust MIME yang dikirim browser.
        // Mencegah upload .php/.html/.exe yang direname ke .jpg.
        $tempPath = $file->getTempName();
        if (! \App\Libraries\System\FileSignatureValidator::matches($tempPath, $allowedMime)) {
            Services::audit()->forModule('AUTH')->logFailure(
                'profile.avatar.invalid_signature',
                'user', (int) $userId,
                'INVALID_FILE_SIGNATURE',
                'Upload avatar ditolak: file signature tidak match (kemungkinan spoofed MIME)'
            );
            return $this->fail('File bukan gambar JPEG/PNG/WebP yang valid', 415, 'INVALID_FILE_SIGNATURE');
        }

        // Simpan ke avatars/{user_id}/<timestamp>.<ext> — segmen random
        // mencegah cache lama browser tampil setelah ganti foto.
        // Sprint 3 — pakai ext dari magic bytes (bukan dari nama file)
        // supaya konsisten dan tidak bisa di-fake (mis. fake.gif.exe).
        $ext = \App\Libraries\System\FileSignatureValidator::safeExtension($tempPath) ?: 'jpg';
        $relativePath = sprintf(
            'avatars/%d/%s_%s.%s',
            $userId,
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
            $ext,
        );

        $storage = Services::fileStorage();
        $bytes = file_get_contents($file->getTempName());
        if ($bytes === false || ! $storage->put($relativePath, $bytes)) {
            return $this->fail('Gagal menyimpan file', 500);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Hapus avatar lama (kalau ada) supaya tidak orphan menumpuk.
        $existing = $db->table('users')->select('avatar_path')->where('id', $userId)->get()->getRowArray();
        $oldPath = $existing['avatar_path'] ?? null;

        $db->table('users')->where('id', $userId)->update([
            'avatar_path' => $relativePath,
            'updated_at'  => $now,
        ]);

        // Sinkronisasi ke employees.avatar_path supaya foto karyawan di
        // halaman HR juga ikut terbarui.
        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if ($emp) {
            $oldPath = $oldPath ?: ($emp['avatar_path'] ?? null);
            $db->table('employees')->where('id', $emp['id'])->update([
                'avatar_path' => $relativePath,
                'updated_at'  => $now,
            ]);
        }

        // Best-effort delete file lama; jangan fail kalau gagal (file bisa
        // jadi sudah dihapus manual atau path-nya tidak valid lagi).
        if ($oldPath && $oldPath !== $relativePath) {
            try { $storage->delete($oldPath); } catch (\Throwable $e) {}
        }

        Services::audit()->forModule('AUTH')->log(
            action: 'profile.avatar.upload',
            entity: 'user',
            entityId: (int) $userId,
            description: 'Mengubah foto profil',
            severity: 'NOTICE',
        );

        return $this->ok([
            'avatar_path' => $relativePath,
            'avatar_url'  => $storage->signedUrl($relativePath, 3600),
        ], 'Foto profil tersimpan');
    }

    /**
     * Hapus foto profil. Endpoint: DELETE /api/v1/auth/me/avatar
     */
    public function deleteAvatar()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthenticated', 401);

        $db = \Config\Database::connect();
        $user = $db->table('users')->select('avatar_path')->where('id', $userId)->get()->getRowArray();
        $oldPath = $user['avatar_path'] ?? null;

        $db->table('users')->where('id', $userId)->update([
            'avatar_path' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $emp = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if ($emp) {
            $oldPath = $oldPath ?: ($emp['avatar_path'] ?? null);
            $db->table('employees')->where('id', $emp['id'])->update([
                'avatar_path' => null,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        if ($oldPath) {
            try { Services::fileStorage()->delete($oldPath); } catch (\Throwable $e) {}
        }

        Services::audit()->forModule('AUTH')->log(
            action: 'profile.avatar.delete',
            entity: 'user',
            entityId: (int) $userId,
            description: 'Menghapus foto profil',
        );

        return $this->ok(null, 'Foto profil dihapus');
    }

    public function changePassword()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|max_length[200]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        if (! $user || ! password_verify($data['current_password'], $user['password_hash'])) {
            return $this->fail('Current password incorrect', 400, 'INVALID_CURRENT_PASSWORD');
        }

        // ─── Sprint 3 — Password Policy enforcement ─────────────────
        $policy = Services::passwordPolicy();
        $errors = $policy->validate($data['new_password']);
        $errors = array_merge($errors, $policy->validateAgainstUser($data['new_password'], $user));
        if ($policy->isReused((int) $user['id'], $data['new_password'])) {
            $errors[] = sprintf(
                'Password tidak boleh sama dengan %d password sebelumnya.',
                \App\Libraries\Auth\PasswordPolicy::HISTORY_LIMIT
            );
        }
        if (password_verify($data['new_password'], $user['password_hash'])) {
            $errors[] = 'Password baru harus berbeda dari password saat ini.';
        }
        if (! empty($errors)) {
            return $this->fail($errors[0], 422, 'PASSWORD_POLICY_VIOLATION', ['errors' => $errors]);
        }

        $newHash = password_hash($data['new_password'], PASSWORD_ARGON2ID);
        $db->table('users')->where('id', $user['id'])->update([
            'password_hash'        => $newHash,
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'must_change_password' => 0,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        // Catat ke history (Sprint 3 R5)
        $policy->recordUsed((int) $user['id'], $newHash);

        // Revoke all refresh tokens for safety
        Services::jwt()->revokeAllForUser($user['id']);
        Services::audit()
            ->forModule('AUTH')
            ->log(
                action: 'auth.password_changed',
                entity: 'user',
                entityId: (int) $user['id'],
                description: 'User mengubah password sendiri',
                severity: 'NOTICE'
            );

        return $this->ok(null, 'Password changed. Please login again.');
    }

    public function forgotPassword()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, ['email' => 'required|valid_email']);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('email', strtolower($data['email']))->where('is_active', 1)->get()->getRowArray();

        // Always respond same way to prevent enumeration
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            $db->table('password_resets')->insert([
                'user_id'    => $user['id'],
                'token_hash' => $hash,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $resetUrl = (string) env('app.frontendUrl', 'http://localhost:5173') . '/reset-password?token=' . $token;
            Services::notification()->queueEmail(
                null,
                $user['email'],
                $user['name'],
                'Reset Password HRIS',
                "<p>Halo {$user['name']},</p><p>Klik link berikut untuk reset password (berlaku 1 jam):</p><p><a href=\"{$resetUrl}\">{$resetUrl}</a></p>"
            );
        }
        return $this->ok(null, 'If the email exists, a reset link has been sent');
    }

    public function resetPassword()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'token'        => 'required|alpha_numeric|min_length[64]|max_length[64]',
            'new_password' => 'required|max_length[200]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $hash = hash('sha256', $data['token']);
        $row = $db->table('password_resets')
            ->where('token_hash', $hash)
            ->where('used_at IS NULL')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();
        if (! $row) {
            return $this->fail('Invalid or expired token', 400, 'INVALID_TOKEN');
        }

        // ─── Sprint 3 — Password Policy enforcement ─────────────────
        $user = $db->table('users')->where('id', (int) $row['user_id'])->get()->getRowArray();
        $policy = Services::passwordPolicy();
        $errors = $policy->validate($data['new_password']);
        if ($user) {
            $errors = array_merge($errors, $policy->validateAgainstUser($data['new_password'], $user));
        }
        if ($policy->isReused((int) $row['user_id'], $data['new_password'])) {
            $errors[] = sprintf(
                'Password tidak boleh sama dengan %d password sebelumnya.',
                \App\Libraries\Auth\PasswordPolicy::HISTORY_LIMIT
            );
        }
        if (! empty($errors)) {
            return $this->fail($errors[0], 422, 'PASSWORD_POLICY_VIOLATION', ['errors' => $errors]);
        }

        $newHash = password_hash($data['new_password'], PASSWORD_ARGON2ID);
        $db->table('users')->where('id', $row['user_id'])->update([
            'password_hash'       => $newHash,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'must_change_password'=> 0,
            'failed_attempts'     => 0,
            'locked_until'        => null,
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $db->table('password_resets')->where('id', $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);
        // Catat ke history (Sprint 3 R5)
        $policy->recordUsed((int) $row['user_id'], $newHash);

        Services::jwt()->revokeAllForUser((int) $row['user_id']);
        Services::audit()
            ->forModule('AUTH')
            ->log(
                action: 'auth.password_reset',
                entity: 'user',
                entityId: (int) $row['user_id'],
                description: 'Password direset via token email',
                severity: 'NOTICE'
            );

        return $this->ok(null, 'Password reset successful');
    }

    public function enableTwoFactor()
    {
        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        $tfa = new \RobThree\Auth\TwoFactorAuth((string) env('twofa.issuer', 'HRIS'));
        $secret = $tfa->createSecret();
        $qr = $tfa->getQRCodeImageAsDataUri($user['email'], $secret);

        $db->table('users')->where('id', $user['id'])->update(['twofa_secret' => $secret, 'twofa_enabled' => 0]);

        // Sprint 3 — generate 8 backup codes sekaligus.
        // User HARUS simpan ini; tidak akan diperlihatkan lagi.
        $backupCodes = Services::twoFactorBackupCodes()->regenerate((int) $user['id']);
        Services::audit()->forModule('AUTH')->log(
            action: 'auth.2fa.enable',
            entity: 'user', entityId: (int) $user['id'],
            description: "User enable 2FA + " . count($backupCodes) . ' backup codes',
            severity: 'NOTICE'
        );

        return $this->ok([
            'secret'       => $secret,
            'qr'           => $qr,
            'backup_codes' => $backupCodes,
            'note'         => 'Simpan backup codes ini di tempat aman — tidak akan ditampilkan ulang. Confirm 2FA dengan kirim kode TOTP ke /auth/2fa/confirm.',
        ]);
    }

    public function disableTwoFactor()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, ['password' => 'required|string']);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        if (! password_verify($data['password'], $user['password_hash'])) {
            return $this->fail('Password incorrect', 400);
        }
        $db->table('users')->where('id', $user['id'])->update([
            'twofa_secret'  => null,
            'twofa_enabled' => 0,
        ]);
        return $this->ok(null, '2FA disabled');
    }

    private function shapeUser(array $user, $companyId = null): array
    {
        return [
            'id'            => (int) $user['id'],
            'email'         => $user['email'],
            'name'          => $user['name'],
            'avatar_path'   => $user['avatar_path'] ?? null,
            'role_id'       => $user['role_id'] ?? null,
            'company_id'    => $companyId ? (int) $companyId : null,
            'employee_id'   => $user['employee_id'] ?? null,
            'twofa_enabled' => (int) ($user['twofa_enabled'] ?? 0),
        ];
    }
}