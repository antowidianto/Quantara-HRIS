<?php

namespace App\Libraries\Notification;

/**
 * QuietHours — Sprint Mobile M7.
 *
 * Helper untuk evaluasi apakah saat ini "quiet" untuk user tertentu.
 * Dipakai NotificationDispatcher / PushNotifier untuk skip channel push
 * (in-app & email tetap kirim — tetap masuk inbox, hanya alert silent).
 *
 * Window cross-midnight: kalau `end < start` (mis. 22:00 → 06:00),
 * artinya window malam yang melewati tengah malam. Test:
 *   isQuiet('22:00', '06:00', '23:30') === true
 *   isQuiet('22:00', '06:00', '03:00') === true
 *   isQuiet('22:00', '06:00', '07:00') === false
 *
 * Window normal (dalam hari yang sama):
 *   isQuiet('12:00', '13:00', '12:30') === true
 *   isQuiet('12:00', '13:00', '14:00') === false
 *
 * Edge case yg di-handle:
 *   - start == end → "always quiet" (durasi 24 jam)
 *   - start atau end null/empty → "never quiet"
 *   - format invalid → "never quiet" (fail-open: jangan block kalau
 *     data corrupt; mute by accident lebih buruk daripada notif jalan)
 */
final class QuietHours
{
    /**
     * Cek apakah `now` jatuh dalam window [start, end] inklusif start,
     * eksklusif end. Jam dipakai dalam timezone yang sama (caller wajib
     * konversi jika perlu — biasanya pakai Asia/Jakarta).
     */
    public static function isQuiet(?string $start, ?string $end, string $now): bool
    {
        $startMin = self::toMinutes($start);
        $endMin   = self::toMinutes($end);
        $nowMin   = self::toMinutes($now);

        if ($startMin === null || $endMin === null || $nowMin === null) {
            return false; // fail-open
        }

        if ($startMin === $endMin) {
            // Equal = 24 jam mute. Jarang dipakai user; kita honor saja.
            return true;
        }

        if ($endMin > $startMin) {
            // Window normal dalam hari yang sama.
            return $nowMin >= $startMin && $nowMin < $endMin;
        }

        // Cross-midnight (mis. 22:00 → 06:00).
        return $nowMin >= $startMin || $nowMin < $endMin;
    }

    /**
     * Convenience: cek pakai timestamp aktual dan timezone user.
     */
    public static function isQuietAt(
        ?string $start,
        ?string $end,
        \DateTimeImmutable $at,
        string $tz = 'Asia/Jakarta',
    ): bool {
        try {
            $local = $at->setTimezone(new \DateTimeZone($tz));
        } catch (\Throwable $e) {
            $local = $at;
        }
        return self::isQuiet($start, $end, $local->format('H:i'));
    }

    /**
     * Convert "HH:MM" atau "HH:MM:SS" → menit-of-day (0..1439).
     * Return null kalau format invalid.
     */
    private static function toMinutes(?string $hhmm): ?int
    {
        if ($hhmm === null) return null;
        $hhmm = trim($hhmm);
        if ($hhmm === '') return null;

        if (! preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $hhmm, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}