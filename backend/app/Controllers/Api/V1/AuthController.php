<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class AuthController extends ApiController
{
    public function login()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'email'    => 'required|valid_email|max_length[191]',
            'password' => 'required|min_length[6]|max_length[200]',
        ]);
        if (! $valid) return null;

        $email = strtolower(trim($data['email']));
        $password = (string) $data['password'];

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('email', $email)->get()->getRowArray();

        $ip = $this->request->getIPAddress();
        $ua = substr((string) $this->request->getUserAgent(), 0, 255);

        if (! $user) {
            $db->table('login_attempts')->insert([
                'email' => $email, 'ip_address' => $ip, 'success' => 0,
                'reason' => 'unknown_email', 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            Services::audit()
                ->forModule('AUTH')
                ->logFailure('auth.login', null, null, 'INVALID_CREDENTIALS',
                    "Login gagal: email tidak terdaftar ({$email})");
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
                'email' => $email, 'ip_address' => $ip, 'success' => 0,
                'reason' => 'bad_password', 'user_agent' => $ua, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $audit = Services::audit()->forModule('AUTH');
            if ($locked) {
                $audit->logCritical('auth.login.locked', 'user', (int) $user['id'],
                    "Akun dikunci sementara setelah {$failed} percobaan gagal: {$email}");
            } else {
                $audit->logFailure('auth.login', 'user', (int) $user['id'], 'INVALID_CREDENTIALS',
                    "Login gagal (password salah, percobaan ke-{$failed}): {$email}");
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
            'email' => $email, 'ip_address' => $ip, 'success' => 1,
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
                description: "Login berhasil: {$email}",
                metadata: ['email' => $email, 'company_id' => $companyId]
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
        $valid = $this->validateOrFail($data, [
            'challenge' => 'required|alpha_numeric|max_length[64]',
            'code'      => 'required|numeric|exact_length[6]',
        ]);
        if (! $valid) return null;

        $cache = Services::cache();
        $entry = $cache->get('2fa:' . $data['challenge']);
        if (! $entry) {
            return $this->fail('Invalid or expired challenge', 400, 'INVALID_CHALLENGE');
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $entry['user_id'])->get()->getRowArray();
        if (! $user) return $this->fail('User not found', 404);

        $tfa = new \RobThree\Auth\TwoFactorAuth((string) env('twofa.issuer', 'HRIS'));
        if (! $tfa->verifyCode($user['twofa_secret'], $data['code'])) {
            return $this->fail('Invalid 2FA code', 401, 'INVALID_2FA');
        }

        $cache->delete('2fa:' . $data['challenge']);

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

        return $this->ok([
            'tokens' => $tokens,
            'user'   => $this->shapeUser($user, $companyId),
        ], '2FA verified');
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
        $jti = $this->request->jwt['jti'] ?? null;
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

        // Simpan ke avatars/{user_id}/<timestamp>.<ext> — segmen random
        // mencegah cache lama browser tampil setelah ganti foto.
        $ext = pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: 'jpg';
        $ext = preg_replace('/[^A-Za-z0-9]+/', '', strtolower($ext));
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
            'new_password'     => 'required|string|min_length[10]|regex_match[/^(?=.*[A-Za-z])(?=.*\d).{10,}$/]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $this->userId())->get()->getRowArray();
        if (! $user || ! password_verify($data['current_password'], $user['password_hash'])) {
            return $this->fail('Current password incorrect', 400, 'INVALID_CURRENT_PASSWORD');
        }

        if (password_verify($data['new_password'], $user['password_hash'])) {
            return $this->fail('New password must differ from current', 400, 'PASSWORD_REUSE');
        }

        $db->table('users')->where('id', $user['id'])->update([
            'password_hash'        => password_hash($data['new_password'], PASSWORD_ARGON2ID),
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'must_change_password' => 0,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

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
            'new_password' => 'required|min_length[10]|regex_match[/^(?=.*[A-Za-z])(?=.*\d).{10,}$/]',
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

        $db->table('users')->where('id', $row['user_id'])->update([
            'password_hash'       => password_hash($data['new_password'], PASSWORD_ARGON2ID),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'must_change_password'=> 0,
            'failed_attempts'     => 0,
            'locked_until'        => null,
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $db->table('password_resets')->where('id', $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);

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

        return $this->ok([
            'secret' => $secret,
            'qr'     => $qr,
            'note'   => 'Confirm by sending a verification code to /auth/2fa/confirm',
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