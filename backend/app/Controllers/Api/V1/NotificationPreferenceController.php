<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * NotificationPreferenceController — endpoint untuk Profile → Notifikasi UI.
 *
 *   GET  /api/v1/notification-preferences         → list pref user yang login
 *   PUT  /api/v1/notification-preferences         → bulk update array of {notif_type, channel, enabled}
 *
 * Multi-tenant: tidak ada filter company_id karena pref attached ke user.
 * User hanya bisa edit pref miliknya sendiri.
 *
 * Lihat docs/AUDIT.md item K.6 + K.7.
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