<?php

namespace App\Libraries\Signing;

use Config\Database;

/**
 * H3.7 — PSrE Signing Service.
 *
 * Layer abstraksi untuk integrasi dengan Penyelenggara Sertifikasi
 * Elektronik (PSrE) tersertifikasi Kominfo: PrivyID, Vida, Peruri.
 *
 * Tanggung jawab:
 *  1. Membuat envelope (record + hash dokumen + daftar signer).
 *  2. Mengirim envelope ke PSrE provider via adapter.
 *  3. Menerima callback (webhook) dari PSrE saat signer selesai sign,
 *     update status signer + envelope, append event log.
 *  4. Menyediakan fallback wet-sign (upload scan) jika PSrE flow gagal.
 *
 * Adapter network call disederhanakan: hanya MOCK_DEV yang functional
 * untuk demo/dev. Production butuh kontrak komersial dengan vendor &
 * konfig API key. Pure helpers di akhir untuk unit-test.
 */
class PsreSigningService
{
    public const PROVIDERS = ['PRIVYID', 'VIDA', 'PERURI', 'MOCK_DEV'];

    public const DOCUMENT_TYPES = [
        'CONTRACT',
        'CONTRACT_AMENDMENT',
        'OFFER_LETTER',
        'SEVERANCE',
        'TRANSFER_LETTER',
        'OTHER',
    ];

    public const SIGNER_ROLES = [
        'EMPLOYEE',
        'HR',
        'DIRECT_SUPERVISOR',
        'DIRECTOR',
        'LEGAL',
        'WITNESS',
    ];

    /** Status terminal — tidak boleh transition lebih lanjut. */
    public const TERMINAL_STATUSES = ['COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED'];

    /**
     * Buat envelope baru. Tidak otomatis kirim ke PSrE — perlu
     * `send()` setelah signers di-attach.
     *
     * @param array{
     *   document_type: string,
     *   subject: string,
     *   document_path: string,
     *   reference_table?: ?string,
     *   reference_id?: ?int,
     *   provider_id?: ?int,
     *   expires_at?: ?string,
     * } $payload
     */
    public function createEnvelope(int $companyId, int $userId, array $payload): int
    {
        if (! in_array($payload['document_type'] ?? '', self::DOCUMENT_TYPES, true)) {
            throw new \InvalidArgumentException('document_type tidak valid');
        }
        if (empty($payload['document_path'])) {
            throw new \InvalidArgumentException('document_path wajib diisi');
        }
        $hash = self::hashDocumentPure($payload['document_path']);

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'         => $companyId,
            'provider_id'        => $payload['provider_id'] ?? null,
            'document_type'      => $payload['document_type'],
            'reference_table'    => $payload['reference_table'] ?? null,
            'reference_id'       => $payload['reference_id']    ?? null,
            'subject'            => mb_substr((string) $payload['subject'], 0, 255),
            'document_path'      => $payload['document_path'],
            'document_hash'      => $hash,
            'status'             => 'DRAFT',
            'expires_at'         => $payload['expires_at'] ?? null,
            'created_by_user_id' => $userId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $db->table('signing_envelopes')->insert($row);
        $envelopeId = (int) $db->insertID();

        $this->logEvent($envelopeId, null, 'CREATED', null, ['user_id' => $userId]);
        return $envelopeId;
    }

    /**
     * Tambah signer ke envelope (sebelum send). Order otomatis di-set
     * incremental kalau tidak diisi.
     */
    public function addSigner(int $envelopeId, array $payload): int
    {
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) {
            throw new \RuntimeException('Envelope tidak ditemukan');
        }
        if ($envelope['status'] !== 'DRAFT') {
            throw new \RuntimeException('Hanya envelope DRAFT yang bisa ditambah signer');
        }
        if (! in_array($payload['signer_role'] ?? '', self::SIGNER_ROLES, true)) {
            throw new \InvalidArgumentException('signer_role tidak valid');
        }
        if (empty($payload['full_name']) || empty($payload['email'])) {
            throw new \InvalidArgumentException('full_name dan email wajib');
        }
        if (! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('email tidak valid');
        }

        $maxOrder = (int) $db->table('signing_envelope_signers')
            ->selectMax('sign_order')
            ->where('envelope_id', $envelopeId)
            ->get()->getRow('sign_order');

        $now = date('Y-m-d H:i:s');
        $row = [
            'envelope_id' => $envelopeId,
            'signer_role' => $payload['signer_role'],
            'sign_order'  => $payload['sign_order'] ?? ($maxOrder + 1),
            'employee_id' => isset($payload['employee_id']) ? (int) $payload['employee_id'] : null,
            'user_id'     => isset($payload['user_id'])     ? (int) $payload['user_id']     : null,
            'full_name'   => mb_substr((string) $payload['full_name'], 0, 200),
            'email'       => mb_substr((string) $payload['email'], 0, 200),
            'national_id' => $payload['national_id'] ?? null,
            'status'      => 'PENDING',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('signing_envelope_signers')->insert($row);
        return (int) $db->insertID();
    }

    /**
     * Kirim envelope ke PSrE provider. Idempotent: panggil ulang setelah
     * SENT akan no-op.
     */
    public function send(int $envelopeId): array
    {
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) {
            throw new \RuntimeException('Envelope tidak ditemukan');
        }
        if ($envelope['status'] !== 'DRAFT') {
            return ['status' => $envelope['status'], 'message' => 'Already sent or terminal'];
        }
        $signerCount = (int) $db->table('signing_envelope_signers')
            ->where('envelope_id', $envelopeId)->countAllResults();
        if ($signerCount === 0) {
            throw new \RuntimeException('Envelope belum punya signer');
        }

        // Adapter dispatch — saat ini stub MOCK_DEV. Real adapter akan
        // POST ke PSrE API dan dapat envelope_external_id.
        $externalId = 'MOCK-' . strtoupper(bin2hex(random_bytes(6)));
        $now = date('Y-m-d H:i:s');
        $db->table('signing_envelopes')->where('id', $envelopeId)->update([
            'envelope_external_id' => $externalId,
            'status'               => 'SENT',
            'sent_at'              => $now,
            'updated_at'           => $now,
        ]);
        $this->logEvent($envelopeId, null, 'SENT', null, [
            'external_id' => $externalId,
            'signers'     => $signerCount,
        ]);

        return [
            'status'              => 'SENT',
            'envelope_external_id' => $externalId,
        ];
    }

    /**
     * Callback dari PSrE: signer telah menyelesaikan tanda tangan.
     * Update signer status, append event log, dan jika semua signer
     * sudah SIGNED → mark envelope COMPLETED.
     *
     * Untuk MOCK_DEV: dipanggil dari controller endpoint testing.
     */
    public function recordSignerEvent(
        int $envelopeId,
        int $signerId,
        string $eventType,
        array $context = []
    ): array {
        if (! in_array($eventType, ['SIGNED', 'DECLINED', 'VIEWED', 'EXPIRED'], true)) {
            throw new \InvalidArgumentException('event_type tidak valid');
        }

        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) {
            throw new \RuntimeException('Envelope tidak ditemukan');
        }
        if (in_array($envelope['status'], self::TERMINAL_STATUSES, true)) {
            throw new \RuntimeException('Envelope sudah pada status terminal: ' . $envelope['status']);
        }

        $signer = $db->table('signing_envelope_signers')
            ->where('id', $signerId)->where('envelope_id', $envelopeId)
            ->get()->getRowArray();
        if (! $signer) {
            throw new \RuntimeException('Signer tidak ditemukan');
        }

        $now = date('Y-m-d H:i:s');

        if ($eventType === 'SIGNED') {
            $db->table('signing_envelope_signers')
                ->where('id', $signerId)
                ->update([
                    'status'                  => 'SIGNED',
                    'signed_at'               => $now,
                    'sign_ip'                 => $context['ip']                       ?? null,
                    'sign_user_agent'         => $context['user_agent']               ?? null,
                    'certificate_fingerprint' => $context['certificate_fingerprint']  ?? null,
                    'updated_at'              => $now,
                ]);
        } elseif ($eventType === 'DECLINED') {
            $db->table('signing_envelope_signers')->where('id', $signerId)->update([
                'status'          => 'DECLINED',
                'declined_reason' => $context['reason'] ?? null,
                'updated_at'      => $now,
            ]);
        } elseif ($eventType === 'EXPIRED') {
            $db->table('signing_envelope_signers')->where('id', $signerId)->update([
                'status'     => 'EXPIRED',
                'updated_at' => $now,
            ]);
        }

        $this->logEvent(
            $envelopeId,
            $signerId,
            $eventType,
            $context['ip'] ?? null,
            $context,
            $context['user_agent'] ?? null,
        );

        // Re-evaluate envelope status
        $newStatus = $this->reevaluateEnvelopeStatus($envelopeId);
        return [
            'envelope_id' => $envelopeId,
            'signer_id'   => $signerId,
            'event'       => $eventType,
            'envelope_status' => $newStatus,
        ];
    }

    /**
     * Hitung ulang status envelope berdasarkan agregasi signer.
     */
    public function reevaluateEnvelopeStatus(int $envelopeId): string
    {
        $db = Database::connect();
        $signers = $db->table('signing_envelope_signers')
            ->select('status')
            ->where('envelope_id', $envelopeId)
            ->get()->getResultArray();
        $statuses = array_column($signers, 'status');
        $newStatus = self::aggregateEnvelopeStatusPure($statuses);

        $upd = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
        if ($newStatus === 'COMPLETED') {
            $upd['completed_at'] = date('Y-m-d H:i:s');
        }
        $db->table('signing_envelopes')->where('id', $envelopeId)->update($upd);

        if ($newStatus === 'COMPLETED') {
            $this->logEvent($envelopeId, null, 'COMPLETED', null, []);
        }
        return $newStatus;
    }

    /**
     * Cancel envelope (oleh creator atau admin). Hanya boleh sebelum
     * COMPLETED.
     */
    public function cancel(int $envelopeId, ?string $reason = null): void
    {
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) return;
        if (in_array($envelope['status'], self::TERMINAL_STATUSES, true)) {
            throw new \RuntimeException('Envelope sudah pada status terminal');
        }
        $db->table('signing_envelopes')->where('id', $envelopeId)->update([
            'status'     => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logEvent($envelopeId, null, 'CANCELLED', null, ['reason' => $reason]);
    }

    /**
     * Fallback wet-sign: jika flow PSrE gagal, user upload scan tanda
     * tangan basah sebagai bukti. Envelope di-mark COMPLETED dengan
     * flag `fallback_wet_sign=1`.
     */
    public function recordWetSignFallback(int $envelopeId, string $scanPath): void
    {
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) return;
        $db->table('signing_envelopes')->where('id', $envelopeId)->update([
            'fallback_wet_sign' => 1,
            'wet_sign_path'     => $scanPath,
            'status'            => 'COMPLETED',
            'completed_at'      => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        $this->logEvent($envelopeId, null, 'FALLBACK_WET_SIGN', null, ['path' => $scanPath]);
    }

    /**
     * Audit trail per envelope (events + signers).
     */
    public function audit(int $envelopeId): array
    {
        $db = Database::connect();
        $envelope = $db->table('signing_envelopes')->where('id', $envelopeId)->get()->getRowArray();
        if (! $envelope) return [];
        $signers = $db->table('signing_envelope_signers')
            ->where('envelope_id', $envelopeId)->orderBy('sign_order', 'ASC')
            ->get()->getResultArray();
        $events  = $db->table('signing_envelope_events')
            ->where('envelope_id', $envelopeId)->orderBy('event_at', 'ASC')
            ->get()->getResultArray();
        return [
            'envelope' => $envelope,
            'signers'  => $signers,
            'events'   => $events,
        ];
    }

    // ─── Internal logging ─────────────────────────────────────────────

    private function logEvent(
        int $envelopeId,
        ?int $signerId,
        string $eventType,
        ?string $ip = null,
        array $metadata = [],
        ?string $userAgent = null
    ): void {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('signing_envelope_events')->insert([
            'envelope_id'  => $envelopeId,
            'signer_id'    => $signerId,
            'event_type'   => $eventType,
            'event_at'     => $now,
            'ip_address'   => $ip,
            'user_agent'   => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'metadata_json'=> $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at'   => $now,
        ]);
    }

    // ─── PURE HELPERS (untuk unit test) ───────────────────────────────

    /**
     * Hitung SHA-256 dari path file. Pure dalam arti deterministic;
     * untuk dev/test, file tidak harus ada → fallback ke hash dari
     * string path.
     */
    public static function hashDocumentPure(string $path): string
    {
        if (is_file($path)) {
            return (string) hash_file('sha256', $path);
        }
        return hash('sha256', $path);
    }

    /**
     * Aggregasi status envelope dari kumpulan status signer.
     * Aturan:
     *  - Semua DECLINED → FAILED
     *  - Ada DECLINED + ada SIGNED → FAILED
     *  - Semua SIGNED → COMPLETED
     *  - Ada >= 1 SIGNED + >= 1 PENDING → PARTIALLY_SIGNED
     *  - Semua EXPIRED → EXPIRED
     *  - Semua PENDING → SENT
     *  - Default → SENT
     *
     * @param string[] $statuses
     */
    public static function aggregateEnvelopeStatusPure(array $statuses): string
    {
        if ($statuses === []) {
            return 'SENT';
        }
        $declined = 0;
        $signed   = 0;
        $pending  = 0;
        $expired  = 0;
        foreach ($statuses as $s) {
            switch ((string) $s) {
                case 'DECLINED': $declined++; break;
                case 'SIGNED':   $signed++;   break;
                case 'PENDING':  $pending++;  break;
                case 'EXPIRED':  $expired++;  break;
            }
        }
        $total = count($statuses);
        if ($declined > 0) {
            return 'FAILED';
        }
        if ($signed === $total) {
            return 'COMPLETED';
        }
        if ($expired === $total) {
            return 'EXPIRED';
        }
        if ($signed > 0 && $pending > 0) {
            return 'PARTIALLY_SIGNED';
        }
        return 'SENT';
    }

    /**
     * Validasi payload envelope (untuk request input). Pure.
     *
     * @return string[] error list
     */
    public static function validateEnvelopePayloadPure(array $payload): array
    {
        $errors = [];
        if (! in_array($payload['document_type'] ?? '', self::DOCUMENT_TYPES, true)) {
            $errors[] = 'document_type tidak valid';
        }
        if (empty($payload['subject']) || mb_strlen((string) $payload['subject']) < 3) {
            $errors[] = 'subject minimal 3 karakter';
        }
        if (empty($payload['document_path'])) {
            $errors[] = 'document_path wajib';
        }
        if (! empty($payload['expires_at'])) {
            $ts = strtotime((string) $payload['expires_at']);
            if ($ts === false) {
                $errors[] = 'expires_at format tanggal tidak valid';
            } elseif ($ts < time()) {
                $errors[] = 'expires_at sudah lewat';
            }
        }
        return $errors;
    }
}