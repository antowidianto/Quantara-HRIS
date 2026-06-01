<?php

namespace App\Libraries\System;

/**
 * EmailQueueBackoff
 *
 * Hitung delay sebelum retry email yang gagal kirim, dengan strategi
 * exponential backoff + jitter. Sprint 7 — sebelumnya `processEmailQueue`
 * langsung mark FAILED dan retry tergantung jadwal cron tanpa delay yang
 * makin panjang; akibatnya kalau SMTP down sebentar, semua row retry lagi
 * tiap 1 menit dan bisa hit rate limit provider.
 *
 * Strategy:
 *   attempt 1 (gagal pertama) → retry setelah 1 menit
 *   attempt 2                 → retry setelah 5 menit
 *   attempt 3                 → retry setelah 30 menit
 *   attempt 4+                → mark FAILED final (kalau max attempts dilewati)
 *
 * Jitter: ±20% acak, mencegah "thundering herd" saat banyak email gagal
 * bareng-bareng karena outage SMTP.
 *
 * Pakai sebagai utility (tidak butuh DB):
 *
 *   $delaySec = EmailQueueBackoff::secondsForAttempt(1);   // ~60
 *   $nextRetry = EmailQueueBackoff::nextRetryAt(2, time()); // string Y-m-d H:i:s
 *   $isReady   = EmailQueueBackoff::isReady($row);          // boleh retry sekarang?
 */
class EmailQueueBackoff
{
    /**
     * Tabel delay (detik) per attempt index. Index 0 unused (attempt
     * pertama belum gagal); attempt 1 = setelah 60s, dst.
     *
     * @var int[]
     */
    private const DELAY_SCHEDULE = [
        0,         // attempt 0 (placeholder)
        60,        // 1 menit
        300,       // 5 menit
        1_800,     // 30 menit
    ];

    /** Maximum jumlah attempt sebelum marked FAILED final. */
    public const MAX_ATTEMPTS = 3;

    /** Jitter ±20% supaya retry tidak bareng-bareng. */
    private const JITTER_PERCENT = 20;

    /**
     * Delay (detik) untuk attempt index given. Attempt > MAX_ATTEMPTS
     * return 0 (caller harus mark FAILED tanpa retry lagi).
     */
    public static function secondsForAttempt(int $attempt): int
    {
        if ($attempt <= 0) return 0;
        if ($attempt >= count(self::DELAY_SCHEDULE)) return 0;

        $base = self::DELAY_SCHEDULE[$attempt];
        // Apply jitter: random ±20%
        $jitterRange = (int) round($base * self::JITTER_PERCENT / 100);
        if ($jitterRange === 0) return $base;

        $delta = random_int(-$jitterRange, $jitterRange);
        return max(1, $base + $delta);
    }

    /**
     * Hitung timestamp `Y-m-d H:i:s` untuk next retry. Berguna dipakai
     * langsung saat update kolom `next_retry_at` di `email_queue`.
     *
     * @param int $attempt Attempt index berikutnya (1-based)
     * @param int $now     Override "now" (default: time()) — useful untuk test
     */
    public static function nextRetryAt(int $attempt, ?int $now = null): string
    {
        $now ??= time();
        $delay = self::secondsForAttempt($attempt);
        return date('Y-m-d H:i:s', $now + $delay);
    }

    /**
     * Apakah row email_queue sudah boleh di-retry sekarang?
     * Ada 2 sinyal yang dicek:
     *   1. `next_retry_at` sudah lewat (kalau di-set)
     *   2. `attempts < MAX_ATTEMPTS` (jika sudah ≥, biarkan caller
     *      yang mark FAILED final)
     *
     * Kalau row tidak punya kolom Sprint 7 (legacy data), default true
     * supaya backward-compat.
     */
    public static function isReady(array $row, ?int $now = null): bool
    {
        $now ??= time();
        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) return false;

        $nextRetry = $row['next_retry_at'] ?? null;
        if ($nextRetry === null || $nextRetry === '') return true;
        return strtotime((string) $nextRetry) <= $now;
    }

    /**
     * Apakah sudah harus mark FAILED final (no more retries)?
     */
    public static function isExhausted(array $row): bool
    {
        return (int) ($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS;
    }
}