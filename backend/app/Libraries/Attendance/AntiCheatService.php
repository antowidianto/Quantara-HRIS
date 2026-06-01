<?php

namespace App\Libraries\Attendance;

/**
 * AntiCheatService — kalkulasi anomaly score dan track device fingerprint
 * untuk attendance.
 *
 * Anomaly flags & weight:
 *   MOCK_GPS         (40 poin) — Mock Location terdeteksi
 *   LOW_ACCURACY     (15 poin) — GPS accuracy > threshold (default 100m)
 *   NEW_DEVICE       (10 poin) — Device pertama kali clock-in
 *   BLOCKED_DEVICE   (60 poin) — Device sudah di-block HR
 *   IP_MISMATCH      (10 poin) — IP berubah drastis dari last_ip device
 *   GEOFENCE_FAR     (15 poin) — Jarak ke kantor > radius cabang
 *   NO_SELFIE        (10 poin) — Belum upload selfie padahal company require
 *
 * Score 0-100. Threshold untuk "high risk" konfigurable
 * (`anticheat.high_anomaly_threshold`, default 60).
 */
class AntiCheatService
{
    public const FLAG_WEIGHTS = [
        'MOCK_GPS'       => 40,
        'LOW_ACCURACY'   => 15,
        'NEW_DEVICE'     => 10,
        'BLOCKED_DEVICE' => 60,
        'IP_MISMATCH'    => 10,
        'GEOFENCE_FAR'   => 15,
        'NO_SELFIE'      => 10,
    ];

    private function db() { return \Config\Database::connect(); }

    private function setting(int $companyId, string $key, $default = null)
    {
        $row = $this->db()->table('settings')
            ->where('company_id', $companyId)->where('key', $key)
            ->get()->getRowArray();
        return $row['value'] ?? $default;
    }

    /**
     * Track / register device. Return ['device' => row, 'is_new' => bool].
     */
    public function trackDevice(int $companyId, int $userId, array $device): array
    {
        $deviceId = (string) ($device['device_id'] ?? '');
        if (! $deviceId) {
            return ['device' => null, 'is_new' => false];
        }

        $existing = $this->db()->table('attendance_devices')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->get()->getRowArray();

        $now = date('Y-m-d H:i:s');

        if (! $existing) {
            $insert = [
                'company_id'    => $companyId,
                'user_id'       => $userId,
                'device_id'     => $deviceId,
                'device_name'   => $device['device_name'] ?? null,
                'user_agent'    => $device['user_agent'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
                'last_ip'       => $device['ip'] ?? null,
                'usage_count'   => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $this->db()->table('attendance_devices')->insert($insert);
            $insert['id'] = (int) $this->db()->insertID();
            return ['device' => $insert, 'is_new' => true];
        }

        // Existing → bump usage count & last seen
        $this->db()->table('attendance_devices')
            ->where('id', (int) $existing['id'])
            ->update([
                'last_seen_at' => $now,
                'last_ip'      => $device['ip'] ?? $existing['last_ip'],
                'usage_count'  => (int) $existing['usage_count'] + 1,
                'updated_at'   => $now,
            ]);
        $existing['usage_count'] = (int) $existing['usage_count'] + 1;
        return ['device' => $existing, 'is_new' => false];
    }

    /**
     * Hitung anomaly flags + score untuk satu clock-in event.
     *
     * @param array $context  ['company_id','user_id','accuracy','is_mock_gps',
     *                         'distance_to_branch','geofence_radius',
     *                         'has_selfie','device' => result trackDevice()]
     */
    public function computeAnomaly(array $context): array
    {
        $flags = [];
        $companyId = (int) ($context['company_id'] ?? 0);

        // 1. Mock GPS
        if (! empty($context['is_mock_gps'])) {
            $flags[] = 'MOCK_GPS';
        }

        // 2. GPS accuracy
        $maxAccuracy = (float) $this->setting($companyId, 'anticheat.max_gps_accuracy_m', 100);
        $accuracy = (float) ($context['accuracy'] ?? 0);
        if ($accuracy > 0 && $accuracy > $maxAccuracy) {
            $flags[] = 'LOW_ACCURACY';
        }

        // 3. Device check
        $device = $context['device']['device'] ?? null;
        $isNew = ! empty($context['device']['is_new']);
        if ($device && ! empty($device['is_blocked'])) {
            $flags[] = 'BLOCKED_DEVICE';
        }
        if ($isNew && ! empty($context['user_id'])) {
            // Cek apakah user sudah punya device lain → benar-benar baru
            $devCount = $this->db()->table('attendance_devices')
                ->where('user_id', (int) $context['user_id'])->countAllResults();
            if ($devCount > 1) $flags[] = 'NEW_DEVICE';
        }
        if ($device && ! empty($device['last_ip']) && ! empty($context['ip'])
            && $device['last_ip'] !== $context['ip']) {
            $flags[] = 'IP_MISMATCH';
        }

        // 4. Geofence distance
        if (isset($context['distance_to_branch'], $context['geofence_radius'])
            && $context['geofence_radius'] > 0
            && $context['distance_to_branch'] > $context['geofence_radius']) {
            $flags[] = 'GEOFENCE_FAR';
        }

        // 5. Selfie required
        $requireSelfie = (int) $this->setting($companyId, 'anticheat.require_selfie', 0);
        if ($requireSelfie && empty($context['has_selfie'])) {
            $flags[] = 'NO_SELFIE';
        }

        // Compute score
        $score = 0;
        foreach ($flags as $f) {
            $score += self::FLAG_WEIGHTS[$f] ?? 0;
        }
        $score = min(100, $score);

        $threshold = (int) $this->setting($companyId, 'anticheat.high_anomaly_threshold', 60);
        $isHighRisk = $score >= $threshold;

        // Should block?
        $shouldBlock = false;
        $blockReason = null;
        if (in_array('MOCK_GPS', $flags, true)
            && (int) $this->setting($companyId, 'anticheat.block_mock_gps', 1) === 1) {
            $shouldBlock = true;
            $blockReason = 'Mock GPS terdeteksi. Matikan Mock Location di pengaturan developer.';
        }
        if (in_array('BLOCKED_DEVICE', $flags, true)) {
            $shouldBlock = true;
            $blockReason = 'Device ini sudah di-block HR. Hubungi HR untuk reset.';
        }
        if (in_array('LOW_ACCURACY', $flags, true) && $accuracy > $maxAccuracy * 3) {
            // Ekstrim — accuracy 3× lebih buruk dari threshold
            $shouldBlock = true;
            $blockReason = "GPS sangat tidak akurat ({$accuracy}m). Mohon ke area dengan sinyal lebih kuat.";
        }

        return [
            'flags'        => $flags,
            'score'        => $score,
            'is_high_risk' => $isHighRisk,
            'should_block' => $shouldBlock,
            'block_reason' => $blockReason,
            'threshold'    => $threshold,
        ];
    }

    /**
     * Generate device fingerprint dari user-agent + accept-language + screen size.
     * Lemah (bisa di-spoof) tapi cukup untuk track device baru di skenario normal.
     * Untuk client-side: gunakan FingerprintJS atau mirip untuk hash lebih kuat.
     */
    public function generateServerFingerprint(string $ua, string $acceptLang = '', string $extra = ''): string
    {
        $raw = $ua . '|' . $acceptLang . '|' . $extra;
        return substr(hash('sha256', $raw), 0, 32);
    }

    /**
     * Tandai device sebagai trusted (HR action).
     */
    public function trustDevice(int $deviceRowId, int $byUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db()->table('attendance_devices')->where('id', $deviceRowId)->update([
            'is_trusted'  => 1,
            'trusted_by'  => $byUserId,
            'trusted_at'  => $now,
            'is_blocked'  => 0,
            'blocked_reason' => null,
            'updated_at'  => $now,
        ]);
    }

    /**
     * Block device (curiga cheat).
     */
    public function blockDevice(int $deviceRowId, string $reason, int $byUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db()->table('attendance_devices')->where('id', $deviceRowId)->update([
            'is_blocked'     => 1,
            'blocked_reason' => $reason,
            'is_trusted'     => 0,
            'updated_at'     => $now,
        ]);
    }

    /**
     * Mark anomaly attendance sebagai sudah di-review.
     */
    public function markReviewed(int $attendanceId, int $userId, string $note = ''): void
    {
        $this->db()->table('attendances')->where('id', $attendanceId)->update([
            'anomaly_reviewed_by' => $userId,
            'anomaly_reviewed_at' => date('Y-m-d H:i:s'),
            'anomaly_review_note' => $note,
        ]);
    }

    /**
     * Stats untuk dashboard HR — counter anomaly per status.
     */
    public function stats(int $companyId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $b = $this->db()->table('attendances a')
            ->select('a.anomaly_score, a.anomaly_reviewed_at');
        // Multi-tenant filter via employees join
        $b->join('employees e', 'e.id = a.employee_id')
          ->where('e.company_id', $companyId);
        if ($fromDate) $b->where('a.work_date >=', $fromDate);
        if ($toDate)   $b->where('a.work_date <=', $toDate);

        $rows = $b->get()->getResultArray();
        $threshold = (int) $this->setting($companyId, 'anticheat.high_anomaly_threshold', 60);

        $total = count($rows);
        $highRisk = 0;
        $reviewed = 0;
        foreach ($rows as $r) {
            if ((int) $r['anomaly_score'] >= $threshold) {
                $highRisk++;
                if (! empty($r['anomaly_reviewed_at'])) $reviewed++;
            }
        }

        return [
            'total'         => $total,
            'high_risk'     => $highRisk,
            'reviewed'      => $reviewed,
            'pending'       => $highRisk - $reviewed,
            'threshold'     => $threshold,
        ];
    }
}