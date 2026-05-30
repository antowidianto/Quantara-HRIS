<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * NotificationPreferenceController — endpoint untuk Profile → Notifikasi UI.
 *
 *   GET  /api/v1/notification-preferences         → list pref user yang login
 *   PUT  /api/v1/notification-preferences         → bulk update array of {notif_type, channel, enabled}
 *   GET  /api/v1/notification-preferences/quiet-hours → ambil window DND
 *   PUT  /api/v1/notification-preferences/quiet-hours → set window DND
 *
 * Multi-tenant: tidak ada filter company_id karena pref attached ke user.
 * User hanya bisa edit pref miliknya sendiri.
 *
 * Lihat docs/AUDIT.md item K.6 + K.7. Quiet hours ditambahkan di sprint
 * mobile M7 — kolom `users.quiet_hours_start/end` (migration #075).
 */
class NotificationPreferenceController extends ApiController
{
    public function index()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthorized', 401);

        $rows = Services::notification()->getPreferences($userId);

        // Group by notif_type → daftar channel + enabled, lebih nyaman untuk UI.
        $byType = [];
        foreach ($rows as $r) {
            $type = $r['notif_type'];
            if (! isset($byType[$type])) {
                $byType[$type] = ['notif_type' => $type, 'channels' => []];
            }
            $byType[$type]['channels'][$r['channel']] = (bool) $r['enabled'];
        }
        return $this->ok([
            'preferences' => array_values($byType),
            'available_channels' => ['in_app', 'email', 'whatsapp', 'web_push'],
            'available_types' => $this->knownNotifTypes(),
            'quiet_hours' => $this->loadQuietHours($userId),
        ]);
    }

    public function update()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthorized', 401);

        $data = $this->getJsonInput();
        $items = $data['preferences'] ?? [];
        if (! is_array($items) || empty($items)) {
            return $this->fail('Field `preferences` wajib (array of {notif_type, channel, enabled})', 400);
        }

        $valid = ['in_app', 'email', 'whatsapp', 'web_push'];
        $count = 0;
        foreach ($items as $p) {
            $type    = (string) ($p['notif_type'] ?? '');
            $channel = (string) ($p['channel'] ?? '');
            $enabled = (bool) ($p['enabled'] ?? false);
            if ($type === '' || ! in_array($channel, $valid, true)) continue;
            // In-app tidak boleh di-disable
            if ($channel === 'in_app' && ! $enabled) continue;
            Services::notification()->setPreference($userId, $type, $channel, $enabled);
            $count++;
        }

        Services::audit()->forModule('NOTIFICATION')->log(
            action: 'preferences.updated',
            entity: 'user_notification_preferences',
            entityId: $userId,
            description: "User update {$count} preference channel notifikasi",
            severity: 'NOTICE',
        );

        return $this->ok(['updated' => $count], 'Preferensi disimpan');
    }

    /**
     * GET quiet-hours window. Kalau migration #075 belum jalan atau kolom
     * tidak ada → return null start/end (no DND).
     */
    public function getQuietHours()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthorized', 401);
        return $this->ok($this->loadQuietHours($userId));
    }

    /**
     * PUT quiet-hours window. Body: { start: "HH:MM"|null, end: "HH:MM"|null }.
     * Kedua null → matikan DND. Format invalid → 400.
     */
    public function updateQuietHours()
    {
        $userId = $this->userId();
        if (! $userId) return $this->fail('Unauthorized', 401);

        $data = $this->getJsonInput();
        $start = $data['start'] ?? null;
        $end   = $data['end']   ?? null;
        $start = $start === null ? null : trim((string) $start);
        $end   = $end   === null ? null : trim((string) $end);

        $reFmt = '/^([0-1]\d|2[0-3]):([0-5]\d)$/';
        if ($start !== null && $start !== '' && ! preg_match($reFmt, $start)) {
            return $this->fail('Format `start` harus HH:MM (24 jam)', 400);
        }
        if ($end !== null && $end !== '' && ! preg_match($reFmt, $end)) {
            return $this->fail('Format `end` harus HH:MM (24 jam)', 400);
        }
        // Kalau salah satu null/empty, paksa keduanya null untuk konsistensi.
        if (($start === null || $start === '') || ($end === null || $end === '')) {
            $start = null;
            $end   = null;
        }

        $db = \Config\Database::connect();
        if (! $db->fieldExists('quiet_hours_start', 'users')) {
            // Migration belum dijalankan — tidak fatal, beri tahu caller.
            return $this->fail('Quiet-hours feature belum aktif (run migration)', 503);
        }

        $db->table('users')->where('id', $userId)->update([
            'quiet_hours_start' => $start,
            'quiet_hours_end'   => $end,
        ]);

        Services::audit()->forModule('NOTIFICATION')->log(
            action: 'quiet_hours.updated',
            entity: 'users',
            entityId: $userId,
            description: $start === null
                ? 'User mematikan quiet hours'
                : "User set quiet hours {$start}-{$end}",
            severity: 'INFO',
        );

        return $this->ok([
            'start' => $start,
            'end'   => $end,
        ], 'Quiet hours disimpan');
    }

    /**
     * Read kolom quiet_hours_start/end dengan fallback aman jika belum ada
     * migrasi.
     *
     * @return array{start:?string,end:?string}
     */
    private function loadQuietHours(int $userId): array
    {
        $db = \Config\Database::connect();
        if (! $db->fieldExists('quiet_hours_start', 'users')) {
            return ['start' => null, 'end' => null];
        }
        $row = $db->table('users')
            ->select('quiet_hours_start, quiet_hours_end')
            ->where('id', $userId)
            ->get()
            ->getRowArray();
        // Backend MySQL kembalikan TIME sebagai "HH:MM:SS"; mobile expect "HH:MM"
        $trim = static function (?string $v): ?string {
            if ($v === null || $v === '') return null;
            return substr($v, 0, 5);
        };
        return [
            'start' => $trim($row['quiet_hours_start'] ?? null),
            'end'   => $trim($row['quiet_hours_end']   ?? null),
        ];
    }

    /** Daftar notif type yang HRIS pakai (untuk UI render checkbox grid). */
    private function knownNotifTypes(): array
    {
        return [
            ['key' => '*',                     'label' => 'Default (semua tipe)'],
            ['key' => 'leave.requested',       'label' => 'Pengajuan cuti masuk'],
            ['key' => 'leave.approved',        'label' => 'Cuti disetujui'],
            ['key' => 'leave.rejected',        'label' => 'Cuti ditolak'],
            ['key' => 'overtime.requested',    'label' => 'Pengajuan lembur masuk'],
            ['key' => 'overtime.approved',     'label' => 'Lembur disetujui'],
            ['key' => 'overtime.rejected',     'label' => 'Lembur ditolak'],
            ['key' => 'attendance.change_requested', 'label' => 'Permintaan perubahan absen'],
            ['key' => 'approval.pending',      'label' => 'Approval menunggu Anda'],
            ['key' => 'payslip.published',     'label' => 'Slip gaji terbit'],
            ['key' => 'contract.expiring',     'label' => 'Kontrak akan habis'],
            ['key' => 'cert.expiring',         'label' => 'Sertifikat akan habis'],
            ['key' => 'sp.issued',             'label' => 'Surat peringatan diterbitkan'],
        ];
    }
}