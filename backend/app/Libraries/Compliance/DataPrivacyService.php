<?php

namespace App\Libraries\Compliance;

use Config\Services;

/**
 * DataPrivacyService — implementasi UU PDP (UU 27/2022).
 *
 * Layanan utama:
 *   1. Consent management (record, query, currentlyGranted)
 *   2. Right to access — `exportUserData()` kompilasi semua data pribadi
 *      milik user untuk download (Pasal 8 huruf b).
 *   3. Right to erasure — `requestDeletion()` & `processDeletion()`
 *      workflow penghapusan data (Pasal 8 huruf f).
 *   4. Anonimisasi PII untuk ex-karyawan setelah masa retensi.
 *
 * Catatan retensi:
 *   - Data finansial (payslip, payroll, bukti potong PPh21) WAJIB disimpan
 *     5 tahun (UU KUP Pasal 28 ayat 11) walaupun karyawan minta hapus.
 *     Service hanya anonimkan PII (nama→Anonim_<id>, NIK/NPWP→null)
 *     tapi rekam finansial tetap audit-able.
 *   - Audit log tidak boleh dihapus — pakai retensi 1 tahun aktif + arsip.
 *
 * Pakai sebagai service (`Services::dataPrivacy()`):
 *
 *   $svc = Services::dataPrivacy();
 *   $svc->recordConsent($userId, 'PRIVACY_POLICY', 'v1.0', granted: true);
 *   $hasConsent = $svc->currentlyGranted($userId, 'PRIVACY_POLICY', 'v1.0');
 *   $bundle = $svc->exportUserData($userId);
 *   $reqId = $svc->requestDeletion($userId, $companyId, 'reason here');
 */
class DataPrivacyService
{
    /** Versi default kebijakan privasi saat ini. Override via env / setting. */
    public const CURRENT_POLICY_VERSION = 'v1.0-2026';

    /** Daftar consent_type yang dikenali. */
    public const CONSENT_TYPES = [
        'PRIVACY_POLICY',  // setuju kebijakan privasi umum
        'BIOMETRIC',       // selfie, fingerprint, face match
        'LOCATION',        // GPS clock-in, geofence tracking
        'MARKETING',       // email/notif opsional
        'DATA_SHARING',    // share ke pihak ke-3 (asuransi, dll)
    ];

    // ─── Consent management ────────────────────────────────────────

    /**
     * Catat persetujuan / pencabutan persetujuan user.
     *
     * Kalau `consent_type=PRIVACY_POLICY`, juga update kolom cache di
     * `users.consent_version_accepted` + `users.consent_at` supaya cek
     * "user sudah setuju versi terbaru?" tidak butuh JOIN.
     */
    public function recordConsent(
        int $userId,
        string $consentType,
        string $version,
        bool $granted = true,
        ?int $companyId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): int {
        if (! in_array($consentType, self::CONSENT_TYPES, true)) {
            throw new \InvalidArgumentException("Consent type tidak dikenali: {$consentType}");
        }
        $db = \Config\Database::connect();
        if (! $db->tableExists('privacy_consents')) {
            throw new \RuntimeException('Tabel privacy_consents belum di-migrate');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('privacy_consents')->insert([
            'user_id'      => $userId,
            'company_id'   => $companyId,
            'consent_type' => $consentType,
            'version'      => $version,
            'granted'      => $granted ? 1 : 0,
            'ip_address'   => $ip,
            'user_agent'   => $userAgent ? substr($userAgent, 0, 255) : null,
            'created_at'   => $now,
        ]);
        $consentId = (int) $db->insertID();

        // Update cache di users untuk PRIVACY_POLICY
        if ($consentType === 'PRIVACY_POLICY' && $granted) {
            try {
                $db->table('users')->where('id', $userId)->update([
                    'consent_version_accepted' => $version,
                    'consent_at'               => $now,
                ]);
            } catch (\Throwable $e) {
                // Kolom belum di-migrate — abaikan, log warning
                log_message('warning', 'Update users.consent_* gagal: ' . $e->getMessage());
            }
        }

        // Audit
        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: 'privacy.consent.record',
                entity: 'user',
                entityId: $userId,
                description: ($granted ? 'Persetujuan' : 'Pencabutan') . " {$consentType} v{$version}",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $consentId;
    }

    /**
     * Apakah user sudah setuju versi terbaru dari `consentType`?
     * Cek consent terakhir; berlaku kalau `granted=1` dan versi match.
     */
    public function currentlyGranted(int $userId, string $consentType, ?string $version = null): bool
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('privacy_consents')) return false;

        $q = $db->table('privacy_consents')
            ->where('user_id', $userId)
            ->where('consent_type', $consentType)
            ->orderBy('created_at', 'DESC')
            ->limit(1);
        if ($version) $q->where('version', $version);
        $row = $q->get()->getRowArray();
        return $row && (int) $row['granted'] === 1;
    }

    /**
     * Riwayat consent user (untuk transparansi & audit user-side).
     *
     * @return array<int, array<string, mixed>>
     */
    public function consentHistory(int $userId): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('privacy_consents')) return [];
        return $db->table('privacy_consents')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    // ─── Right to access — data export ────────────────────────────

    /**
     * Kompilasi semua data pribadi milik user untuk download.
     *
     * Output adalah associative array yang siap di-encode JSON. Berisi:
     *   - profile (users + employees)
     *   - attendances, leaves, overtimes (riwayat utama)
     *   - payslips ringkas (slip + take-home; detail breakdown opsional)
     *   - documents (metadata; file fisik harus di-download terpisah)
     *   - consents (riwayat persetujuan)
     *
     * PII di-decrypt (NIK, NPWP, bank_account) supaya user bisa baca,
     * SEMUA hash & secret di-redact.
     */
    public function exportUserData(int $userId): array
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray() ?: [];
        // Redact sensitive
        unset(
            $user['password_hash'],
            $user['twofa_secret'],
        );

        $employee = $db->table('employees')->where('user_id', $userId)
            ->where('deleted_at IS NULL')->get()->getRowArray() ?: [];
        if (! empty($employee)) {
            try {
                $enc = Services::encryption();
                $employee['nik'] = $enc->decrypt($employee['nik_encrypted'] ?? null);
                $employee['npwp'] = $enc->decrypt($employee['npwp_encrypted'] ?? null);
                $employee['bank_account'] = $enc->decrypt($employee['bank_account_encrypted'] ?? null);
                unset(
                    $employee['nik_encrypted'],
                    $employee['nik_hash'],
                    $employee['npwp_encrypted'],
                    $employee['bank_account_encrypted'],
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Decrypt PII saat export gagal: ' . $e->getMessage());
            }
        }

        $empId = (int) ($employee['id'] ?? 0);

        $payslips = $empId > 0
            ? $db->table('payslips')->select('id, slip_number, payroll_period_id, gross_earning, take_home_pay, pph21_amount, created_at')
                ->where('employee_id', $empId)
                ->orderBy('id', 'DESC')->limit(120)->get()->getResultArray()
            : [];

        $attendances = $empId > 0
            ? $db->table('attendances')->select('id, work_date, clock_in_at, clock_out_at, status, late_minutes, work_minutes')
                ->where('employee_id', $empId)
                ->orderBy('work_date', 'DESC')->limit(365)->get()->getResultArray()
            : [];

        $leaves = $empId > 0
            ? $db->table('leaves')->where('employee_id', $empId)
                ->orderBy('id', 'DESC')->limit(200)->get()->getResultArray()
            : [];

        $overtimes = $empId > 0
            ? $db->table('overtimes')->where('employee_id', $empId)
                ->orderBy('id', 'DESC')->limit(200)->get()->getResultArray()
            : [];

        $documents = $empId > 0 && $db->tableExists('employee_documents')
            ? $db->table('employee_documents')->select('id, doc_type, title, file_mime, file_size, expires_at, created_at')
                ->where('employee_id', $empId)
                ->where('deleted_at IS NULL')
                ->orderBy('id', 'DESC')->get()->getResultArray()
            : [];

        $consents = $this->consentHistory($userId);

        // Audit log akses
        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: 'privacy.data.export',
                entity: 'user',
                entityId: $userId,
                description: 'User download seluruh data pribadi (UU PDP Pasal 8b)',
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return [
            'export_meta' => [
                'generated_at' => $now,
                'policy_version' => self::CURRENT_POLICY_VERSION,
                'note' => 'Export ini adalah salinan data pribadi Anda sesuai UU PDP. ' .
                          'Field hash/secret tidak dimasukkan untuk keamanan.',
            ],
            'user'        => $user,
            'employee'    => $employee,
            'payslips'    => $payslips,
            'attendances' => $attendances,
            'leaves'      => $leaves,
            'overtimes'   => $overtimes,
            'documents'   => $documents,
            'consents'    => $consents,
        ];
    }

    // ─── Right to erasure — deletion request workflow ─────────────

    /**
     * User submit permintaan penghapusan data. HR review terpisah.
     */
    public function requestDeletion(int $userId, ?int $companyId, ?string $reason = null): int
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('data_deletion_requests')) {
            throw new \RuntimeException('Tabel data_deletion_requests belum di-migrate');
        }

        // Cegah duplikasi: kalau ada PENDING dari user yang sama, return ID-nya
        $pending = $db->table('data_deletion_requests')
            ->where('user_id', $userId)
            ->where('status', 'PENDING')
            ->get()->getRowArray();
        if ($pending) return (int) $pending['id'];

        $now = date('Y-m-d H:i:s');
        $db->table('data_deletion_requests')->insert([
            'user_id'    => $userId,
            'company_id' => $companyId,
            'reason'     => $reason,
            'status'     => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: 'privacy.deletion.request',
                entity: 'data_deletion_request',
                entityId: $id,
                description: "User mengajukan penghapusan data (UU PDP Pasal 8f)",
                severity: 'WARNING',
                metadata: ['reason' => $reason],
            );
        } catch (\Throwable $e) {}

        return $id;
    }

    /**
     * HR approve permintaan: ubah status & catat reviewer + note.
     * Anonimisasi tidak otomatis dijalankan — caller harus invoke
     * `processApproved()` saat siap (mis. setelah karyawan resign +
     * masa retensi pajak terlewati).
     */
    public function reviewDeletion(int $requestId, int $reviewerUserId, bool $approve, ?string $note = null): void
    {
        $db = \Config\Database::connect();
        $row = $db->table('data_deletion_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Request {$requestId} tidak ditemukan");
        if ($row['status'] !== 'PENDING') {
            throw new \RuntimeException("Request sudah di-{$row['status']}, tidak bisa di-review ulang");
        }
        $now = date('Y-m-d H:i:s');
        $db->table('data_deletion_requests')->where('id', $requestId)->update([
            'status'      => $approve ? 'APPROVED' : 'REJECTED',
            'reviewed_by' => $reviewerUserId,
            'reviewed_at' => $now,
            'review_note' => $note,
            'updated_at'  => $now,
        ]);

        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: $approve ? 'privacy.deletion.approve' : 'privacy.deletion.reject',
                entity: 'data_deletion_request',
                entityId: $requestId,
                description: ($approve ? 'Setuju' : 'Tolak') . ' permintaan penghapusan data user #' . $row['user_id'],
                severity: 'CRITICAL',
                metadata: ['note' => $note],
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Anonimisasi PII untuk user yang request-nya sudah APPROVED.
     * Tidak hapus baris finansial — hanya overwrite field yang mengandung
     * PII (nama, NIK, NPWP, alamat, telepon, foto).
     *
     * Implementasi sengaja konservatif & manual: tidak invoke saat approve
     * supaya HR bisa memilih tanggal cleanup (mis. setelah masa retensi
     * pajak 5 tahun terlewati).
     *
     * @return array<string,int> jumlah row yang dianonimkan per tabel
     */
    public function processApproved(int $requestId): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('data_deletion_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $row) throw new \InvalidArgumentException("Request {$requestId} tidak ditemukan");
        if ($row['status'] !== 'APPROVED') {
            throw new \RuntimeException("Request belum APPROVED (status: {$row['status']})");
        }
        $userId = (int) $row['user_id'];
        $stats = ['users' => 0, 'employees' => 0];

        $now = date('Y-m-d H:i:s');
        $anon = "Anonim_{$userId}";

        $db->transStart();

        // Anonim users
        $db->table('users')->where('id', $userId)->update([
            'name'        => $anon,
            'email'       => "anon_{$userId}@deleted.local",
            'avatar_path' => null,
            'is_active'   => 0,
            'updated_at'  => $now,
        ]);
        $stats['users'] = 1;

        // Anonim employees (kalau ada)
        $emp = $db->table('employees')->where('user_id', $userId)->get()->getRowArray();
        if ($emp) {
            $db->table('employees')->where('id', $emp['id'])->update([
                'first_name'  => $anon,
                'last_name'   => '',
                'full_name'   => $anon,
                'email'       => "anon_{$userId}@deleted.local",
                'phone'       => null,
                'address'     => null,
                'home_lat'    => null,
                'home_lng'    => null,
                'avatar_path' => null,
                'nik_encrypted' => null,
                'nik_hash'      => null,
                'npwp_encrypted' => null,
                'bank_account_encrypted' => null,
                'emergency_contact_name'  => null,
                'emergency_contact_phone' => null,
                'is_active'   => 0,
                'updated_at'  => $now,
            ]);
            $stats['employees'] = 1;
        }

        $db->table('data_deletion_requests')->where('id', $requestId)->update([
            'status'       => 'COMPLETED',
            'completed_at' => $now,
            'updated_at'   => $now,
        ]);

        $db->transComplete();

        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: 'privacy.deletion.complete',
                entity: 'user',
                entityId: $userId,
                description: 'PII dianonimkan (record finansial dipertahankan untuk retensi pajak)',
                severity: 'CRITICAL',
                metadata: $stats,
            );
        } catch (\Throwable $e) {}

        return $stats;
    }
}