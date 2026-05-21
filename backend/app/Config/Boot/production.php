<?php

/*
 * Production environment bootstrap.
 *
 * Catatan PHP 8.4+:
 *  Konstanta E_STRICT dihapus per PHP 8.4 dan menyebabkan warning deprecated
 *  bila masih dirujuk langsung. Kita susun mask error secara dinamis supaya
 *  file ini tetap aman di-include pada PHP 7.4 sampai 8.4+.
 */
ini_set('display_errors', '0');

$mask = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_DEPRECATED;

// E_STRICT dideprecate di PHP 8.4 dan menghasilkan warning meskipun
// konstantanya masih terdaftar untuk kompatibilitas. Kita hanya
// mereferensikan-nya pada PHP < 8.4. Bitnya sendiri (2048) sudah tidak
// pernah dipakai PHP modern, jadi aman jika tidak dimask.
if (PHP_VERSION_ID < 80400 && defined('E_STRICT')) {
    $mask &= ~constant('E_STRICT');
}
error_reporting($mask);
unset($mask);

defined('CI_DEBUG') || define('CI_DEBUG', false);

/*
 * Fail-fast guards:
 *
 * Memastikan operator tidak deploy ke production tanpa override config kritis.
 * Daripada pakai default `localhost` (yang akan menghasilkan PDF/email URL
 * salah) atau pakai placeholder JWT secret (yang berarti SEMUA token bisa
 * di-forge), kita stop boot dengan pesan jelas.
 *
 * Tidak menggunakan exception/log_message di sini karena Services belum
 * tersedia saat boot file ini di-include.
 *
 * Escape hatches (tidak menonaktifkan keamanan production):
 *   1. CLI (`php spark *`) — selalu skip, perlu untuk migrate/audit di server.
 *   2. Akses dari host lokal (127.0.0.1, ::1, localhost, IP private RFC1918)
 *      — skip dengan asumsi ini sesi maintenance manual dari ops, bukan
 *      traffic publik. Production yang sebenarnya selalu di balik proxy/CDN
 *      sehingga REMOTE_ADDR ≠ 127.0.0.1 (kecuali op port-forward via SSH,
 *      yang memang skenario maintenance).
 *   3. ENV `production.skip_boot_guard=true` — opt-in eksplisit untuk
 *      situasi tertentu (mis. container build smoke check). Default false.
 */
if (PHP_SAPI === 'cli') {
    return;
}

$skipGuard = (string) (getenv('production.skip_boot_guard') ?: '');
if ($skipGuard === 'true' || $skipGuard === '1') {
    return;
}

// Detect akses lokal — IPv4/IPv6 loopback atau IP private RFC1918.
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal = false;
if ($remote === '127.0.0.1' || $remote === '::1' || $remote === '') {
    $isLocal = true;
} elseif (str_starts_with($remote, '10.')
       || str_starts_with($remote, '192.168.')
       || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remote) === 1) {
    $isLocal = true;
}
if ($isLocal) {
    // Tidak block — tapi log warning ke header response supaya developer
    // tetap aware bahwa config belum lengkap.
    header('X-HRIS-Boot-Warning: production env dengan config placeholder. Aman di lokal, JANGAN dipakai di publik.');
    return;
}

$guards = [];

$baseUrl = (string) (getenv('app.baseURL') ?: '');
if ($baseUrl === '' || str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1')) {
    $guards[] = '`app.baseURL` masih default localhost. Set ke URL HTTPS publik di .env.';
}

$jwtSecret = (string) (getenv('jwt.secret') ?: getenv('JWT_ACCESS_SECRET') ?: '');
if (str_starts_with($jwtSecret, 'CHANGE_ME') || strlen($jwtSecret) < 32) {
    $guards[] = '`jwt.secret` masih placeholder atau < 32 karakter. Generate via: php -r "echo bin2hex(random_bytes(64));"';
}

$encKey = (string) (getenv('encryption.key') ?: '');
if (str_contains($encKey, 'GENERATE_VIA') || $encKey === '' || $encKey === 'base64:') {
    $guards[] = '`encryption.key` belum di-generate. Jalankan: php spark key:generate';
}

if ($guards !== []) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "HRIS — Production boot diblokir karena konfigurasi kritis belum diisi:\n\n";
    foreach ($guards as $i => $g) {
        echo '  ' . ($i + 1) . ". {$g}\n";
    }
    echo "\nFix di file .env, lalu restart server. Lihat docs/AUDIT.md item E.\n";
    exit(1);
}
unset($baseUrl, $jwtSecret, $encKey, $guards);