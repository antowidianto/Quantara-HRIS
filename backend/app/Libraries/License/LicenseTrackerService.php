<?php

namespace App\Libraries\License;

use Config\Database;

/**
 * License & Certification Expiry Tracker.
 *
 * Tanggung jawab:
 *  1. CRUD master license_types & employee_licenses.
 *  2. Cek status expiry dan update otomatis (ACTIVE → EXPIRING_SOON
 *     → EXPIRED) berdasarkan tanggal hari ini vs expires_on.
 *  3. Hitung threshold notifikasi mana yang seharusnya difire (untuk
 *     dipakai cron `licenses:check-expiry`).
 *  4. Renewal flow — link license lama ke license baru sebagai audit
 *     trail.
 *  5. Compliance check — apakah karyawan punya license aktif untuk
 *     posisinya (untuk dipakai roster assignment guard).
 *
 * Pure helpers di akhir untuk unit-test.
 */
class LicenseTrackerService
{
    public const STATUSES = ['ACTIVE', 'EXPIRING_SOON', 'EXPIRED', 'RENEWED', 'REVOKED'];

    public const CATEGORIES = [
        'PROFESSIONAL', 'SAFETY', 'TECHNICAL', 'MEDICAL', 'DRIVING', 'OTHER',
    ];

    /** Threshold notifikasi default (hari sebelum expiry). */
    public const DEFAULT_WARN_THRESHOLDS = [30, 7, 1];

    /**
     * Cari semua license yang harus diaksi cron hari ini:
     *  - Sudah lewat tanggal → mark EXPIRED + notif (kalau belum)
     *  - Mendekati threshold → mark EXPIRING_SOON + notif sesuai threshold
     *
     * @return array{
     *   to_expire: array<int, array<string,mixed>>,
     *   to_warn:   array<int, array<string,mixed>>
     * }
     */
    public function scanForActions(?string $today = null): array
    {
        $today    = $today ?: date('Y-m-d');
        $todayTs  = strtotime($today);
        $db       = Database::connect();

        if (! $db->tableExists('employee_licenses')) {
            return ['to_expire' => [], 'to_warn' => []];
        }

        $rows = $db->table('employee_licenses el')
            ->select('el.*, lt.warn_before_days_json, lt.code AS license_code, lt.name AS license_name')
            ->join('license_types lt', 'lt.id = el.license_type_id', 'left')
            ->whereIn('el.status', ['ACTIVE', 'EXPIRING_SOON'])
            ->where('el.deleted_at', null)
            ->where('el.expires_on IS NOT NULL', null, false)
            ->get()->getResultArray();

        $toExpire = [];
        $toWarn   = [];
        foreach ($rows as $r) {
            $expires = strtotime((string) $r['expires_on']);
            if ($expires === false) continue;
            $daysLeft = (int) floor(($expires - $todayTs) / 86400);

            $thresholds = self::parseThresholdsPure((string) ($r['warn_before_days_json'] ?? ''))
                ?: self::DEFAULT_WARN_THRESHOLDS;
            $action = self::resolveActionPure($daysLeft, $thresholds, (int) ($r['last_notified_threshold_day'] ?? -1));
            if ($action === 'EXPIRE') {
                $toExpire[] = $r + ['days_left' => $daysLeft];
            } elseif (is_int($action)) {
                $toWarn[] = $r + ['days_left' => $daysLeft, 'threshold' => $action];
            }
        }

        return ['to_expire' => $toExpire, 'to_warn' => $toWarn];
    }

    /**
     * Apply hasil scan: update status + last_notified threshold.
     * Notifikasi sebenarnya (push email / in-app) dilakukan di luar
     * service ini supaya bisa di-mock di test.
     *
     * @return int jumlah row yang di-update
     */
    public function applyScanResults(array $scan): int
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($scan['to_expire'] ?? [] as $row) {
            $db->table('employee_licenses')->where('id', (int) $row['id'])->update([
                'status'                      => 'EXPIRED',
                'last_notified_threshold_day' => 0,
                'last_notified_at'            => $now,
                'updated_at'                  => $now,
            ]);
            $count++;
        }
        foreach ($scan['to_warn'] ?? [] as $row) {
            $db->table('employee_licenses')->where('id', (int) $row['id'])->update([
                'status'                      => 'EXPIRING_SOON',
                'last_notified_threshold_day' => (int) $row['threshold'],
                'last_notified_at'            => $now,
                'updated_at'                  => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Renewal: license lama di-mark RENEWED, license baru di-create
     * dengan link ke license lama (audit trail).
     */
    public function renew(int $oldLicenseId, array $newPayload): int
    {
        $db = Database::connect();
        $old = $db->table('employee_licenses')->where('id', $oldLicenseId)->get()->getRowArray();
        if (! $old) {
            throw new \RuntimeException('License tidak ditemukan');
        }
        if (in_array($old['status'], ['RENEWED', 'REVOKED'], true)) {
            throw new \RuntimeException('License sudah ' . $old['status']);
        }

        $now = date('Y-m-d H:i:s');
        $newRow = [
            'company_id'      => (int) $old['company_id'],
            'employee_id'     => (int) $old['employee_id'],
            'license_type_id' => (int) $old['license_type_id'],
            'license_number'  => mb_substr((string) ($newPayload['license_number'] ?? $old['license_number']), 0, 100),
            'issued_on'       => $newPayload['issued_on'] ?? date('Y-m-d'),
            'expires_on'      => $newPayload['expires_on'] ?? null,
            'document_path'   => $newPayload['document_path'] ?? null,
            'status'          => 'ACTIVE',
            'note'            => $newPayload['note'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('employee_licenses')->insert($newRow);
        $newId = (int) $db->insertID();

        $db->table('employee_licenses')->where('id', $oldLicenseId)->update([
            'status'                => 'RENEWED',
            'renewed_to_license_id' => $newId,
            'updated_at'            => $now,
        ]);
        return $newId;
    }

    /**
     * Cek apakah karyawan compliant terhadap license requirement
     * untuk posisi tertentu pada tanggal tertentu.
     *
     * @return array{compliant: bool, missing_codes: string[], expired_codes: string[]}
     */
    public function complianceFor(int $employeeId, int $positionId, ?string $onDate = null): array
    {
        $onDate = $onDate ?: date('Y-m-d');
        $db = Database::connect();

        // Cari license_types yang require posisi ini
        $licenseTypes = $db->table('license_types')
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $required = [];
        foreach ($licenseTypes as $lt) {
            $reqIds = json_decode((string) ($lt['is_required_for_position_ids'] ?? ''), true);
            if (is_array($reqIds) && in_array($positionId, array_map('intval', $reqIds), true)) {
                $required[(int) $lt['id']] = $lt;
            }
        }

        if ($required === []) {
            return ['compliant' => true, 'missing_codes' => [], 'expired_codes' => []];
        }

        $heldRows = $db->table('employee_licenses')
            ->where('employee_id', $employeeId)
            ->whereIn('license_type_id', array_keys($required))
            ->whereIn('status', ['ACTIVE', 'EXPIRING_SOON', 'EXPIRED'])
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $heldByType = [];
        foreach ($heldRows as $h) {
            $heldByType[(int) $h['license_type_id']] = $h;
        }

        $missing = [];
        $expired = [];
        foreach ($required as $typeId => $lt) {
            $h = $heldByType[$typeId] ?? null;
            if (! $h) {
                $missing[] = (string) $lt['code'];
                continue;
            }
            if (! self::isLicenseValidPure($h, $onDate)) {
                $expired[] = (string) $lt['code'];
            }
        }

        return [
            'compliant'     => $missing === [] && $expired === [],
            'missing_codes' => $missing,
            'expired_codes' => $expired,
        ];
    }

    // ─── PURE HELPERS ─────────────────────────────────────────────────

    /**
     * Resolve action per row.
     *  - 'EXPIRE' kalau days_left ≤ 0 dan belum di-mark expired
     *  - integer threshold (30/7/1) kalau crossed threshold yang baru
     *  - null kalau tidak perlu aksi
     *
     * Aturan threshold:
     *  - Kalau belum pernah notif (lastNotified < 0), pilih threshold
     *    terbesar yang ≥ daysLeft.
     *  - Kalau sudah pernah notif H-N, hanya boleh pilih threshold yang
     *    LEBIH KECIL dari N (jadi tidak duplikat) dan ≥ daysLeft.
     *  - Pick threshold terbesar di antara kandidat eligible.
     *
     * @param int[] $thresholds
     * @return string|int|null
     */
    public static function resolveActionPure(int $daysLeft, array $thresholds, int $lastNotifiedThreshold): string|int|null
    {
        if ($daysLeft <= 0 && $lastNotifiedThreshold !== 0) {
            return 'EXPIRE';
        }
        if ($daysLeft <= 0) {
            return null;
        }
        // Sort thresholds desc — pilih kandidat terbesar yang eligible
        $sorted = $thresholds;
        rsort($sorted);
        foreach ($sorted as $t) {
            // Threshold harus mencakup daysLeft (daysLeft ≤ t).
            if ($daysLeft > $t) continue;
            // Kalau sudah pernah notif untuk threshold ≥ t, skip — hanya
            // boleh fire threshold yang LEBIH KECIL dari yang sudah ter-notif.
            if ($lastNotifiedThreshold >= 0 && $t >= $lastNotifiedThreshold) continue;
            return $t;
        }
        return null;
    }

    /**
     * Parse JSON thresholds dari kolom config. Return empty array kalau invalid.
     *
     * @return int[]
     */
    public static function parseThresholdsPure(string $json): array
    {
        if ($json === '') return [];
        $arr = json_decode($json, true);
        if (! is_array($arr)) return [];
        $out = [];
        foreach ($arr as $v) {
            $i = (int) $v;
            if ($i > 0 && $i <= 365) $out[] = $i;
        }
        return array_values(array_unique($out));
    }

    /**
     * Hitung expires_on dari issued_on + validity_months.
     * Pure — null in/out friendly.
     */
    public static function computeExpiryPure(?string $issuedOn, ?int $validityMonths): ?string
    {
        if (! $issuedOn || ! $validityMonths || $validityMonths <= 0) return null;
        $ts = strtotime($issuedOn);
        if ($ts === false) return null;
        return date('Y-m-d', strtotime("+{$validityMonths} months", $ts));
    }

    /**
     * Apakah license masih valid (active, belum expired, bukan REVOKED).
     *
     * @param array<string,mixed> $row
     */
    public static function isLicenseValidPure(array $row, string $onDate): bool
    {
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['REVOKED', 'EXPIRED', 'RENEWED'], true)) {
            return false;
        }
        $expires = $row['expires_on'] ?? null;
        if ($expires === null || $expires === '') return true; // lifetime
        $a = strtotime((string) $expires);
        $b = strtotime($onDate);
        return $a !== false && $b !== false && $a >= $b;
    }
}