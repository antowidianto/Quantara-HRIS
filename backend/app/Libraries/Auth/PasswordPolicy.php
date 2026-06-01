<?php

namespace App\Libraries\Auth;

/**
 * PasswordPolicy
 *
 * Aturan & utility untuk validasi password sesuai standar industri:
 *
 *  R1. Panjang minimum 10 karakter (max 200 untuk DoS protection).
 *  R2. Wajib mengandung huruf besar, huruf kecil, angka, dan simbol.
 *  R3. Tidak boleh sama dengan email/nama user (case-insensitive substring).
 *  R4. Tidak boleh masuk daftar password umum (check ringan via blocklist
 *      built-in + opsional cek HIBP via `breachCheck()` di runtime).
 *  R5. Tidak boleh dipakai ulang dari N riwayat terakhir (default 5).
 *  R6. Forced rotation opsional (default 0 = disabled, satuan hari).
 *
 * Utility:
 *  - `validate()` cek aturan R1-R4 secara sync (no DB).
 *  - `validateAgainstUser()` cek R3 dengan data user.
 *  - `isReused()` cek R5 terhadap tabel `password_history`.
 *  - `recordUsed()` simpan hash ke `password_history` saat ganti password.
 *  - `mustRotate()` cek R6 terhadap `users.password_changed_at`.
 *  - `breachCheck()` (opsional) cek ke Have I Been Pwned API via k-anonymity.
 *
 * Pakai sebagai service (lihat `Config\Services::passwordPolicy()`) supaya
 * controller bisa call:
 *
 *   $policy = Services::passwordPolicy();
 *   $errors = $policy->validate($plain);
 *   $errors = array_merge($errors, $policy->validateAgainstUser($plain, $user));
 *   if ($policy->isReused($userId, $plain)) $errors[] = 'Password sudah pernah dipakai.';
 *   if (! empty($errors)) return $this->fail($errors);
 *
 *   // setelah berhasil ganti password:
 *   $policy->recordUsed($userId, $newHash);
 */
class PasswordPolicy
{
    /** Panjang minimum & maksimum yang aman. */
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 200;

    /** Default jumlah riwayat password yang dilarang ulang. */
    public const HISTORY_LIMIT = 5;

    /**
     * Top common passwords (subset dari 10k SecLists). Cek case-insensitive
     * sebelum hit DB / API; cukup untuk filter casual brute-force input.
     */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', '123456', '12345678',
        '123456789', 'qwerty', 'qwerty123', 'abc123', 'iloveyou',
        'admin', 'admin123', 'welcome', 'welcome1', 'welcome123',
        'letmein', 'monkey', 'dragon', 'sunshine', 'master',
        'asdfgh', 'asdf1234', '1q2w3e4r', '1qaz2wsx', 'zaq12wsx',
        'qazwsx', 'p@ssw0rd', 'p@ssword', 'passw0rd', 'p4ssw0rd',
        'changeme', 'temp1234', 'hris123', 'kemenaker', 'indonesia',
    ];

    /**
     * Validasi aturan R1, R2, R4 (sync, tidak butuh DB / network).
     *
     * @return string[] Daftar pesan error berbahasa Indonesia.
     *                  Empty = password valid.
     */
    public function validate(string $password): array
    {
        $errors = [];

        // R1 — panjang
        $len = strlen($password);
        if ($len < self::MIN_LENGTH) {
            $errors[] = sprintf('Password minimal %d karakter.', self::MIN_LENGTH);
        }
        if ($len > self::MAX_LENGTH) {
            $errors[] = sprintf('Password terlalu panjang (maks %d karakter).', self::MAX_LENGTH);
        }

        // R2 — kompleksitas. Skip kalau panjang minimum belum tercapai
        // supaya pesan tidak duplikatif.
        if ($len >= self::MIN_LENGTH) {
            if (! preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password harus mengandung minimal satu huruf besar (A-Z).';
            }
            if (! preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password harus mengandung minimal satu huruf kecil (a-z).';
            }
            if (! preg_match('/\d/', $password)) {
                $errors[] = 'Password harus mengandung minimal satu angka (0-9).';
            }
            if (! preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'Password harus mengandung minimal satu simbol (!@#$ dst).';
            }
        }

        // R4 — common password blocklist
        if ($this->isCommon($password)) {
            $errors[] = 'Password terlalu umum / mudah ditebak. Pilih kombinasi yang lebih unik.';
        }

        return $errors;
    }

    /**
     * Validasi R3 — password tidak boleh memuat email/nama user.
     *
     * @return string[]
     */
    public function validateAgainstUser(string $password, array $user): array
    {
        $errors = [];
        $lower = strtolower($password);

        // Email local-part (sebelum @). Lewati local-part pendek (<4 char)
        // supaya tidak false-positive untuk email seperti "ir@hris.local"
        // dengan password "Pirate..." yang kebetulan mengandung "ir".
        $email = strtolower((string) ($user['email'] ?? ''));
        if ($email !== '') {
            $local = (string) strtok($email, '@');
            if (strlen($local) >= 4 && str_contains($lower, $local)) {
                $errors[] = 'Password tidak boleh mengandung bagian email Anda.';
            }
        }

        // Nama: split per kata, cek kata yang ≥ 4 huruf supaya tidak false
        // positive untuk inisial pendek seperti "Bp" / "Ir".
        $name = strtolower((string) ($user['name'] ?? ''));
        if ($name !== '') {
            foreach (preg_split('/\s+/', $name) as $part) {
                if (strlen($part) >= 4 && str_contains($lower, $part)) {
                    $errors[] = 'Password tidak boleh mengandung nama Anda.';
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Cek password masuk daftar common (case-insensitive).
     */
    public function isCommon(string $password): bool
    {
        return in_array(strtolower($password), self::COMMON_PASSWORDS, true);
    }

    /**
     * Cek password sudah pernah dipakai user (R5). Bandingkan plaintext
     * baru terhadap N hash terakhir di `password_history`.
     *
     * @param int $userId
     * @param string $plainPassword Password baru (plaintext)
     * @param int $limit Jumlah riwayat yang dicek (default 5)
     */
    public function isReused(int $userId, string $plainPassword, int $limit = self::HISTORY_LIMIT): bool
    {
        if ($userId <= 0 || $plainPassword === '' || $limit <= 0) return false;

        $db = \Config\Database::connect();
        if (! $db->tableExists('password_history')) return false;

        $rows = $db->table('password_history')
            ->select('password_hash')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        foreach ($rows as $r) {
            if (password_verify($plainPassword, (string) $r['password_hash'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Catat hash password yang baru dipakai ke history. Hash WAJIB sudah
     * dihasilkan via `password_hash()` (tidak boleh plain).
     *
     * Saat insert, sisa baris di luar `keep` di-trim untuk hemat storage.
     */
    public function recordUsed(int $userId, string $passwordHash, int $keep = self::HISTORY_LIMIT): void
    {
        if ($userId <= 0 || $passwordHash === '') return;
        $db = \Config\Database::connect();
        if (! $db->tableExists('password_history')) return;

        $now = date('Y-m-d H:i:s');
        $db->table('password_history')->insert([
            'user_id'       => $userId,
            'password_hash' => $passwordHash,
            'created_at'    => $now,
        ]);

        // Trim ke `keep` baris terakhir
        $extras = $db->table('password_history')
            ->select('id')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(1000, $keep)  // skip $keep baris pertama, ambil sisa
            ->get()
            ->getResultArray();
        if (! empty($extras)) {
            $db->table('password_history')
                ->whereIn('id', array_column($extras, 'id'))
                ->delete();
        }
    }

    /**
     * Cek apakah user perlu rotasi password karena sudah lewat batas hari.
     * Default 0 (disabled) — admin bisa set per-company `password_max_age_days`.
     */
    public function mustRotate(array $user, int $maxAgeDays = 0): bool
    {
        if ($maxAgeDays <= 0) return false;
        $changedAt = $user['password_changed_at'] ?? null;
        if (! $changedAt) return true;  // belum pernah set → wajib ganti

        $age = (time() - strtotime((string) $changedAt)) / 86400;
        return $age > $maxAgeDays;
    }

    /**
     * Cek breach via Have I Been Pwned API menggunakan k-anonymity:
     * client kirim 5 char SHA1 prefix saja, API balikan list suffix +
     * count. Tidak ada plaintext password yang keluar dari server.
     *
     * Return:
     *  - integer = jumlah breach (0 = aman)
     *  - null    = service tidak tersedia / network error (jangan blok)
     *
     * Catatan: dipanggil OPSIONAL (bukan part dari validate()) karena
     * butuh network call. Idealnya dipanggil saat self-service password
     * change — untuk login flow biasa, skip supaya tidak mengganggu UX.
     */
    public function breachCheck(string $password, int $timeoutSec = 3): ?int
    {
        if ($password === '') return 0;
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $url = "https://api.pwnedpasswords.com/range/{$prefix}";
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: HRIS-PasswordPolicy/1.0\r\n",
            'timeout' => $timeoutSec,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return null;

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) !== 2) continue;
            if (strcasecmp($parts[0], $suffix) === 0) {
                return (int) $parts[1];
            }
        }
        return 0;
    }
}