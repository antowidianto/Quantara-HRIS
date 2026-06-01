<?php

namespace App\Libraries\Recruitment;

use Config\Database;
use Config\Services;

/**
 * OfferService — manage candidate offer letters (Sprint 2).
 *
 * Workflow:
 *   1. HR create DRAFT offer di stage OFFER candidate (lewat `create()`)
 *   2. HR review breakdown gaji + tunjangan + probation period, render preview
 *   3. HR `send()` → status SENT, response_deadline = +7 hari, kandidat dapat
 *      notifikasi (email + in-app)
 *   4. Kandidat respond via UI portal candidate (`recordResponse()`):
 *      - ACCEPTED → auto-progress candidate ke stage HIRED + kirim notifikasi
 *        ke HR untuk lanjut hire convertion
 *      - REJECTED → status REJECTED + alasan tercatat
 *      - Belum respond H+7 → cron auto-set EXPIRED
 *   5. HR boleh `withdraw()` kalau kandidat belum respond (mis. salah salary)
 *
 * Konteks Indonesia:
 *   - Offer letter lazim untuk posisi staff & atas. Untuk posisi entry/operator,
 *     biasanya HR langsung kirim PKWT/PKWTT begitu ACCEPTED verbal.
 *   - Probation max 3 bulan untuk PKWTT (UU 13/2003 Pasal 60). Kalau kandidat
 *     PKWT → tidak ada probation (PP 35/2021 Pasal 12).
 *   - PPh21 tidak dipotong dari offer langsung; tertulis sebagai gross. Tax
 *     treatment ditentukan saat hire convertion.
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item REC-1.
 */
class OfferService
{
    public const STATUSES = ['DRAFT', 'SENT', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'WITHDRAWN'];
    public const DEFAULT_RESPONSE_WINDOW_DAYS = 7;

    /**
     * Generate offer_number unik. Format: OFR-<YEAR>-<5digit>
     */
    public function nextOfferNumber(int $companyId): string
    {
        $db = Database::connect();
        $year = date('Y');
        $prefix = "OFR-{$year}-";
        $count = $db->table('candidate_offers')
            ->where('company_id', $companyId)
            ->like('offer_number', $prefix, 'after')
            ->countAllResults();
        $tries = 0;
        while ($tries < 50) {
            $seq = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
            $candidate = $prefix . $seq;
            $exists = $db->table('candidate_offers')
                ->where('company_id', $companyId)
                ->where('offer_number', $candidate)
                ->countAllResults();
            if ($exists === 0) return $candidate;
            $count++; $tries++;
        }
        return $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Buat offer DRAFT untuk kandidat. Validasi:
     *   - Candidate harus stage OFFER (atau di atas — kalau HR create offer
     *     setelah lewat OFFER, return error supaya tidak bingung audit trail)
     *   - Tidak ada offer aktif (DRAFT/SENT) lain untuk kandidat ini
     *   - basic_salary > 0
     *
     * @param array $data ['candidate_id', 'basic_salary', 'employment_status',
     *                     'proposed_join_date', 'probation_months',
     *                     'allowances' (optional), 'joining_bonus', 'benefits',
     *                     'response_deadline', 'internal_notes']
     */
    public function create(array $data, int $userId): array
    {
        $db = Database::connect();
        $candidateId = (int) ($data['candidate_id'] ?? 0);
        if ($candidateId <= 0) {
            throw new \InvalidArgumentException('candidate_id wajib');
        }

        $cand = $db->table('candidates')
            ->where('id', $candidateId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $cand) {
            throw new \InvalidArgumentException("Candidate #{$candidateId} tidak ditemukan");
        }

        // Stage harus OFFER (atau lebih tinggi — tapi HR biasanya buat
        // offer di stage OFFER. Kalau sudah HIRED, offer tidak relevan)
        if (! in_array($cand['current_stage'], ['OFFER', 'INTERVIEW_USER'], true)) {
            throw new \RuntimeException(
                "Offer hanya bisa dibuat di stage OFFER atau INTERVIEW_USER. "
                . "Stage saat ini: {$cand['current_stage']}"
            );
        }

        // Tidak boleh duplikasi offer aktif untuk candidate yang sama
        $activeExists = $db->table('candidate_offers')
            ->where('candidate_id', $candidateId)
            ->whereIn('status', ['DRAFT', 'SENT'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($activeExists > 0) {
            throw new \RuntimeException(
                'Sudah ada offer aktif (DRAFT/SENT) untuk kandidat ini. '
                . 'Withdraw offer lama sebelum buat baru.'
            );
        }

        $basicSalary = (float) ($data['basic_salary'] ?? 0);
        if ($basicSalary <= 0) {
            throw new \InvalidArgumentException('basic_salary harus > 0');
        }

        $employmentStatus = strtoupper((string) ($data['employment_status'] ?? 'PKWTT'));
        $validStatus = ['PKWTT', 'PKWT', 'PROBATION', 'MAGANG', 'OUTSOURCING'];
        if (! in_array($employmentStatus, $validStatus, true)) {
            throw new \InvalidArgumentException(
                'employment_status harus salah satu: ' . implode(', ', $validStatus)
            );
        }

        // Probation: max 3 bulan untuk PKWTT (UU 13/2003 Pasal 60).
        // PKWT tidak boleh ada probation (PP 35/2021 Pasal 12).
        $probationMonths = (int) ($data['probation_months'] ?? 0);
        if ($probationMonths < 0 || $probationMonths > 3) {
            throw new \InvalidArgumentException(
                'probation_months antara 0 dan 3 (UU 13/2003 Pasal 60).'
            );
        }
        if ($employmentStatus === 'PKWT' && $probationMonths > 0) {
            throw new \InvalidArgumentException(
                'PKWT tidak boleh ada masa percobaan (PP 35/2021 Pasal 12). '
                . 'Set probation_months = 0 atau ganti employment_status ke PKWTT.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'         => (int) $cand['company_id'],
            'candidate_id'       => $candidateId,
            'offer_number'       => $this->nextOfferNumber((int) $cand['company_id']),
            'job_posting_id'     => (int) ($cand['job_posting_id'] ?? 0) ?: null,
            'position_id'        => isset($data['position_id']) ? (int) $data['position_id'] : null,
            'department_id'      => isset($data['department_id']) ? (int) $data['department_id'] : null,
            'branch_id'          => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'employment_status'  => $employmentStatus,
            'proposed_join_date' => $data['proposed_join_date'] ?? null,
            'probation_months'   => $probationMonths,
            'basic_salary'       => $basicSalary,
            'allowances_json'    => $this->normalizeAllowances($data['allowances'] ?? []),
            'joining_bonus'      => (float) ($data['joining_bonus'] ?? 0),
            'benefits_json'      => isset($data['benefits']) && is_array($data['benefits'])
                ? json_encode($data['benefits'], JSON_UNESCAPED_UNICODE)
                : null,
            'currency'           => strtoupper((string) ($data['currency'] ?? 'IDR')),
            'status'             => 'DRAFT',
            'response_deadline'  => $data['response_deadline'] ?? null,
            'internal_notes'     => $data['internal_notes'] ?? null,
            'created_by'         => $userId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $db->table('candidate_offers')->insert($insert);
        $offerId = (int) $db->insertID();

        try {
            Services::audit()->forModule('RECRUITMENT')->logCreate(
                'candidate_offer', $offerId, $insert,
                "Buat offer DRAFT {$insert['offer_number']} untuk {$cand['full_name']} "
                . "({$employmentStatus}, basic " . number_format($basicSalary, 0, ',', '.') . ")"
            );
        } catch (\Throwable $e) {}

        return $this->loadOffer($offerId);
    }

    /**
     * Normalisasi allowances input. Format expected:
     *   [['label' => 'Transport', 'amount' => 500000, 'type' => 'fixed', 'is_taxable' => true], ...]
     * Skip empty rows. Throws kalau format invalid.
     */
    private function normalizeAllowances(array $input): ?string
    {
        if (empty($input)) return null;
        $clean = [];
        foreach ($input as $a) {
            if (! is_array($a)) continue;
            $label = trim((string) ($a['label'] ?? ''));
            $amount = (float) ($a['amount'] ?? 0);
            if ($label === '' || $amount <= 0) continue;
            $clean[] = [
                'label'      => $label,
                'amount'     => $amount,
                'type'       => strtolower((string) ($a['type'] ?? 'fixed')),
                'is_taxable' => (bool) ($a['is_taxable'] ?? true),
            ];
        }
        return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Update offer DRAFT. Kalau status sudah SENT, tidak boleh edit
     * komponen utama (salary/proposed_join_date) — HR harus withdraw &
     * buat offer baru supaya audit trail jelas.
     */
    public function update(int $offerId, array $data, int $userId): array
    {
        $db = Database::connect();
        $offer = $this->loadOffer($offerId);
        if ($offer['status'] !== 'DRAFT') {
            throw new \RuntimeException(
                "Offer tidak bisa diedit di status {$offer['status']}. Hanya DRAFT yang bisa diedit. "
                . "Untuk perubahan setelah SENT, withdraw offer & buat baru."
            );
        }

        $update = [];
        foreach (['position_id', 'department_id', 'branch_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f] !== null ? (int) $data[$f] : null;
            }
        }
        foreach (['proposed_join_date', 'response_deadline', 'internal_notes', 'currency'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (array_key_exists('basic_salary', $data)) {
            $bs = (float) $data['basic_salary'];
            if ($bs <= 0) throw new \InvalidArgumentException('basic_salary harus > 0');
            $update['basic_salary'] = $bs;
        }
        if (array_key_exists('joining_bonus', $data)) {
            $update['joining_bonus'] = (float) $data['joining_bonus'];
        }
        if (array_key_exists('employment_status', $data)) {
            $es = strtoupper((string) $data['employment_status']);
            if (! in_array($es, ['PKWTT', 'PKWT', 'PROBATION', 'MAGANG', 'OUTSOURCING'], true)) {
                throw new \InvalidArgumentException('employment_status invalid');
            }
            $update['employment_status'] = $es;
        }
        if (array_key_exists('probation_months', $data)) {
            $pm = (int) $data['probation_months'];
            if ($pm < 0 || $pm > 3) {
                throw new \InvalidArgumentException('probation_months antara 0 dan 3 (UU 13/2003 Pasal 60).');
            }
            // Cross-check ke employment_status final
            $finalES = $update['employment_status'] ?? $offer['employment_status'];
            if ($finalES === 'PKWT' && $pm > 0) {
                throw new \InvalidArgumentException('PKWT tidak boleh ada probation (PP 35/2021 Pasal 12).');
            }
            $update['probation_months'] = $pm;
        }
        if (array_key_exists('allowances', $data)) {
            $update['allowances_json'] = $this->normalizeAllowances($data['allowances'] ?? []);
        }
        if (array_key_exists('benefits', $data)) {
            $update['benefits_json'] = is_array($data['benefits'])
                ? json_encode($data['benefits'], JSON_UNESCAPED_UNICODE)
                : null;
        }

        if (empty($update)) {
            throw new \InvalidArgumentException('Tidak ada perubahan');
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('candidate_offers')->where('id', $offerId)->update($update);

        try {
            Services::audit()->forModule('RECRUITMENT')->logUpdate(
                'candidate_offer', $offerId, $offer, $update
            );
        } catch (\Throwable $e) {}

        return $this->loadOffer($offerId);
    }

    /**
     * Send offer ke kandidat: status DRAFT → SENT.
     * Kirim notifikasi via email & in-app dengan link ke portal candidate.
     */
    public function send(int $offerId, int $userId, ?string $note = null): array
    {
        $db = Database::connect();
        $offer = $this->loadOffer($offerId);
        if ($offer['status'] !== 'DRAFT') {
            throw new \RuntimeException("Hanya offer DRAFT yang bisa di-send (current: {$offer['status']})");
        }
        if ((float) $offer['basic_salary'] <= 0) {
            throw new \RuntimeException('basic_salary harus > 0');
        }

        $now = date('Y-m-d H:i:s');
        $deadline = $offer['response_deadline']
            ?? date('Y-m-d', strtotime("+" . self::DEFAULT_RESPONSE_WINDOW_DAYS . " days"));

        $db->table('candidate_offers')->where('id', $offerId)->update([
            'status'            => 'SENT',
            'sent_at'           => $now,
            'sent_by'           => $userId,
            'response_deadline' => $deadline,
            'updated_at'        => $now,
        ]);

        // Notifikasi ke kandidat (kalau ada candidate_account)
        $this->notifyCandidate($offer['candidate_id'], $offerId, 'offer.sent',
            'Penawaran kerja untuk Anda',
            "Anda menerima penawaran kerja {$offer['offer_number']}. "
            . "Silakan review & respond sebelum {$deadline}."
            . ($note ? "\n\nCatatan dari HR: {$note}" : '')
        );

        try {
            Services::audit()->forModule('RECRUITMENT')->log(
                action: 'offer.send',
                entity: 'candidate_offer',
                entityId: $offerId,
                description: "Send offer {$offer['offer_number']} ke kandidat (deadline {$deadline})",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->loadOffer($offerId);
    }

    /**
     * Record response dari kandidat (ACCEPTED/REJECTED).
     *
     * Kalau ACCEPTED:
     *   - Status offer → ACCEPTED
     *   - Candidate auto-progress ke HIRED stage (kalau belum)
     *   - Notifikasi HR untuk lanjut hire convertion
     *
     * Kalau REJECTED:
     *   - Status offer → REJECTED + alasan tercatat
     *   - Candidate stage tidak diubah (HR yang putuskan apakah re-engage atau reject)
     */
    public function recordResponse(
        int $offerId,
        string $decision,
        int $userId,
        ?string $responseNote = null,
        ?string $rejectionReason = null,
    ): array {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['ACCEPTED', 'REJECTED'], true)) {
            throw new \InvalidArgumentException('decision harus ACCEPTED atau REJECTED');
        }

        $db = Database::connect();
        $offer = $this->loadOffer($offerId);
        if ($offer['status'] !== 'SENT') {
            throw new \RuntimeException(
                "Offer tidak bisa di-respond di status {$offer['status']}. "
                . "Hanya SENT yang bisa direspon."
            );
        }

        // Cek deadline — kalau lewat, auto-set EXPIRED dulu
        if ($offer['response_deadline'] && strtotime($offer['response_deadline'] . ' 23:59:59') < time()) {
            $this->expire($offerId, 'system');
            throw new \RuntimeException(
                "Offer sudah expired (deadline {$offer['response_deadline']}). "
                . "Hubungi HR untuk re-issue offer baru."
            );
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'         => $decision,
            'responded_at'   => $now,
            'response_note'  => $responseNote,
            'updated_at'     => $now,
        ];
        if ($decision === 'REJECTED' && $rejectionReason) {
            $update['rejection_reason'] = $rejectionReason;
        }
        $db->table('candidate_offers')->where('id', $offerId)->update($update);

        // Auto-progress candidate ke HIRED kalau ACCEPTED
        if ($decision === 'ACCEPTED') {
            $cand = $db->table('candidates')->where('id', $offer['candidate_id'])->get()->getRowArray();
            if ($cand && $cand['current_stage'] !== 'HIRED') {
                try {
                    Services::recruitment()->moveStage(
                        (int) $offer['candidate_id'],
                        'HIRED',
                        ['feedback' => "Offer {$offer['offer_number']} diterima kandidat. " . ($responseNote ?: '')],
                    );
                } catch (\Throwable $e) {
                    log_message('warning', 'Auto-progress to HIRED after offer accepted failed: ' . $e->getMessage());
                }
            }

            // Notifikasi HR untuk lanjut hire convertion
            $this->notifyHr($offer['company_id'], 'offer.accepted',
                'Offer diterima — siap di-hire',
                "Kandidat menerima offer {$offer['offer_number']}. "
                . "Silakan klik tombol 'Hire' di pipeline untuk convert ke employee."
            );
        }

        try {
            Services::audit()->forModule('RECRUITMENT')->log(
                action: 'offer.' . strtolower($decision),
                entity: 'candidate_offer',
                entityId: $offerId,
                description: "Offer {$offer['offer_number']} {$decision}"
                    . ($responseNote ? ': ' . mb_substr($responseNote, 0, 100) : ''),
                severity: $decision === 'REJECTED' ? 'WARNING' : 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->loadOffer($offerId);
    }

    /**
     * Record response dari kandidat sendiri (Candidate Portal — Option C).
     *
     * Beda dari `recordResponse()`:
     *   - Caller = candidate account (bukan user internal)
     *   - Audit log pakai source = CANDIDATE_PORTAL + metadata berisi
     *     candidate_account_id, IP, user_agent → trail forensic kalau ada
     *     dispute "saya nggak pernah accept".
     *   - Tidak butuh permission internal (`recruitment.offer.respond`),
     *     tapi WAJIB ownership check di caller (offer.candidate_account_id
     *     harus sama dengan account yang login).
     *
     * Sisanya identik dengan recordResponse(): validasi status SENT,
     * cek deadline, auto-progress ke HIRED kalau ACCEPTED, notify HR.
     *
     * @throws \RuntimeException kalau status bukan SENT atau deadline lewat
     */
    public function recordResponseByCandidate(
        int $offerId,
        string $decision,
        int $candidateAccountId,
        ?string $responseNote = null,
        ?string $rejectionReason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['ACCEPTED', 'REJECTED'], true)) {
            throw new \InvalidArgumentException('decision harus ACCEPTED atau REJECTED');
        }

        $db = Database::connect();
        $offer = $this->loadOffer($offerId);
        if ($offer['status'] !== 'SENT') {
            throw new \RuntimeException(
                "Offer tidak bisa di-respond di status {$offer['status']}. "
                . "Hanya SENT yang bisa direspon."
            );
        }
        if ($offer['response_deadline'] && strtotime($offer['response_deadline'] . ' 23:59:59') < time()) {
            $this->expire($offerId, 'system');
            throw new \RuntimeException(
                "Offer sudah expired (deadline {$offer['response_deadline']}). "
                . "Hubungi HR untuk re-issue offer baru."
            );
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'         => $decision,
            'responded_at'   => $now,
            'response_note'  => $responseNote,
            'updated_at'     => $now,
        ];
        if ($decision === 'REJECTED' && $rejectionReason) {
            $update['rejection_reason'] = $rejectionReason;
        }
        $db->table('candidate_offers')->where('id', $offerId)->update($update);

        if ($decision === 'ACCEPTED') {
            $cand = $db->table('candidates')->where('id', $offer['candidate_id'])->get()->getRowArray();
            if ($cand && $cand['current_stage'] !== 'HIRED') {
                try {
                    Services::recruitment()->moveStage(
                        (int) $offer['candidate_id'],
                        'HIRED',
                        ['feedback' => "Offer {$offer['offer_number']} diterima kandidat (self-service via portal). " . ($responseNote ?: '')],
                    );
                } catch (\Throwable $e) {
                    log_message('warning', 'Auto-progress to HIRED after candidate self-accept failed: ' . $e->getMessage());
                }
            }
            $this->notifyHr($offer['company_id'], 'offer.accepted',
                'Offer diterima oleh kandidat (self-service)',
                "Kandidat {$offer['candidate_name']} menerima offer {$offer['offer_number']} via Career Portal. "
                . "Silakan klik tombol 'Hire' di pipeline untuk convert ke employee."
            );
        } else {
            // REJECTED — kabari HR juga supaya bisa re-engage atau cari kandidat lain
            $this->notifyHr($offer['company_id'], 'offer.rejected',
                'Offer ditolak oleh kandidat',
                "Kandidat {$offer['candidate_name']} menolak offer {$offer['offer_number']} via Career Portal."
                . ($rejectionReason ? "\n\nAlasan: {$rejectionReason}" : '')
            );
        }

        // Audit dengan trail kandidat — source CANDIDATE_PORTAL supaya gampang
        // di-filter saat investigasi dispute.
        try {
            Services::audit()
                ->forModule('RECRUITMENT')
                ->withSource('CANDIDATE_PORTAL')
                ->log(
                    action: 'offer.' . strtolower($decision),
                    entity: 'candidate_offer',
                    entityId: $offerId,
                    description: "Offer {$offer['offer_number']} {$decision} oleh kandidat (self-service)"
                        . ($responseNote ? ': ' . mb_substr($responseNote, 0, 100) : ''),
                    severity: $decision === 'REJECTED' ? 'WARNING' : 'NOTICE',
                    metadata: [
                        'actor'                => 'candidate',
                        'candidate_account_id' => $candidateAccountId,
                        'candidate_name'       => $offer['candidate_name'] ?? null,
                        'candidate_email'      => $offer['candidate_email'] ?? null,
                        'ip_address'           => $ipAddress,
                        'user_agent'           => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                        'rejection_reason'     => $decision === 'REJECTED' ? $rejectionReason : null,
                    ],
                );
        } catch (\Throwable $e) {
            log_message('warning', 'Audit candidate offer response failed: ' . $e->getMessage());
        }

        return $this->loadOffer($offerId);
    }

    /**
     * HR withdraw offer (mis. salah salary). Hanya allowed dari DRAFT/SENT.
     */
    public function withdraw(int $offerId, int $userId, string $reason): array
    {
        if (strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Alasan withdraw minimum 5 karakter');
        }
        $db = Database::connect();
        $offer = $this->loadOffer($offerId);
        if (! in_array($offer['status'], ['DRAFT', 'SENT'], true)) {
            throw new \RuntimeException("Offer tidak bisa di-withdraw di status {$offer['status']}");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('candidate_offers')->where('id', $offerId)->update([
            'status'         => 'WITHDRAWN',
            'response_note'  => '[WITHDRAWN by HR] ' . $reason,
            'responded_at'   => $now,
            'updated_at'     => $now,
        ]);

        try {
            Services::audit()->forModule('RECRUITMENT')->log(
                action: 'offer.withdraw',
                entity: 'candidate_offer',
                entityId: $offerId,
                description: "Withdraw offer {$offer['offer_number']}: {$reason}",
                severity: 'WARNING',
            );
        } catch (\Throwable $e) {}

        return $this->loadOffer($offerId);
    }

    /**
     * Expire offer (dipanggil cron / system saat lewat deadline).
     * `actor` parameter untuk dibedakan dari HR action.
     */
    public function expire(int $offerId, string $actor = 'system'): void
    {
        $db = Database::connect();
        $offer = $db->table('candidate_offers')->where('id', $offerId)->get()->getRowArray();
        if (! $offer || $offer['status'] !== 'SENT') return;

        $now = date('Y-m-d H:i:s');
        $db->table('candidate_offers')->where('id', $offerId)->update([
            'status'     => 'EXPIRED',
            'updated_at' => $now,
        ]);

        try {
            Services::audit()->forModule('RECRUITMENT')->log(
                action: 'offer.expire',
                entity: 'candidate_offer',
                entityId: $offerId,
                description: "Auto-expire offer {$offer['offer_number']} ({$actor})",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        // Notifikasi HR
        $this->notifyHr((int) $offer['company_id'], 'offer.expired',
            'Offer kadaluarsa tanpa respon',
            "Offer {$offer['offer_number']} kadaluarsa pada {$offer['response_deadline']} tanpa respon kandidat."
        );
    }

    /**
     * Cari offer yang SENT tapi sudah lewat deadline. Cron command pakai ini.
     *
     * @return array<int, int> Daftar offer ID
     */
    public function findExpiredOffers(?int $companyId = null): array
    {
        $db = Database::connect();
        $today = date('Y-m-d');
        $b = $db->table('candidate_offers')
            ->select('id')
            ->where('status', 'SENT')
            ->where('response_deadline IS NOT NULL')
            ->where('response_deadline <', $today)
            ->where('deleted_at IS NULL');
        if ($companyId) $b->where('company_id', $companyId);
        $rows = $b->get()->getResultArray();
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * Load offer dengan join ke candidate untuk display.
     */
    public function loadOffer(int $offerId): array
    {
        $db = Database::connect();
        $row = $db->table('candidate_offers o')
            ->select('o.*, c.full_name AS candidate_name, c.email AS candidate_email, '
                . 'c.current_stage AS candidate_stage, c.user_id AS candidate_user_id, '
                . 'p.name AS position_name, d.name AS department_name, b.name AS branch_name')
            ->join('candidates c', 'c.id = o.candidate_id')
            ->join('positions p', 'p.id = o.position_id', 'left')
            ->join('departments d', 'd.id = o.department_id', 'left')
            ->join('branches b', 'b.id = o.branch_id', 'left')
            ->where('o.id', $offerId)
            ->where('o.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            throw new \InvalidArgumentException("Offer #{$offerId} tidak ditemukan");
        }

        // Decode JSON fields untuk client
        $row['allowances'] = $row['allowances_json']
            ? (json_decode($row['allowances_json'], true) ?: [])
            : [];
        $row['benefits'] = $row['benefits_json']
            ? (json_decode($row['benefits_json'], true) ?: [])
            : [];

        // Hitung total compensation untuk display ringkas
        $totalAllowance = 0;
        foreach ($row['allowances'] as $a) {
            $totalAllowance += (float) ($a['amount'] ?? 0);
        }
        $row['total_monthly_compensation'] = (float) $row['basic_salary'] + $totalAllowance;

        return $row;
    }

    /**
     * Build context untuk render template OFFER_LETTER (untuk preview HTML
     * sebelum send, atau snapshot di generated_contracts).
     */
    public function buildLetterContext(array $offer): array
    {
        $db = Database::connect();
        $cand = $db->table('candidates')->where('id', $offer['candidate_id'])->get()->getRowArray() ?: [];
        $company = $db->table('companies')->where('id', $offer['company_id'])->get()->getRowArray() ?: [];

        return [
            'offer' => [
                'number'             => $offer['offer_number'],
                'sent_at'            => $offer['sent_at'],
                'response_deadline'  => $offer['response_deadline'],
                'basic_salary'       => (float) $offer['basic_salary'],
                'basic_salary_id'    => 'Rp ' . number_format((float) $offer['basic_salary'], 0, ',', '.'),
                'joining_bonus'      => (float) $offer['joining_bonus'],
                'allowances'         => is_string($offer['allowances_json'] ?? null)
                    ? (json_decode($offer['allowances_json'], true) ?: [])
                    : [],
                'employment_status'  => $offer['employment_status'],
                'proposed_join_date' => $offer['proposed_join_date'],
                'probation_months'   => (int) $offer['probation_months'],
            ],
            'candidate' => [
                'full_name'  => $cand['full_name'] ?? '',
                'email'      => $cand['email'] ?? '',
                'phone'      => $cand['phone'] ?? '',
            ],
            'company' => $company,
        ];
    }

    private function notifyCandidate(int $candidateId, int $offerId, string $type, string $title, string $message): void
    {
        try {
            $db = Database::connect();
            $cand = $db->table('candidates')->where('id', $candidateId)->get()->getRowArray();
            if (! $cand || empty($cand['user_id'])) return;

            // Kandidat punya user account terpisah (candidate_user_id),
            // notifikasi via NotificationService dengan flag email = true.
            Services::notification()->send(
                (int) $cand['user_id'],
                (int) $cand['company_id'],
                $type,
                $title,
                $message,
                ['offer_id' => $offerId, 'candidate_id' => $candidateId],
                '/candidate/offers/' . $offerId,
                true, // email
            );
        } catch (\Throwable $e) {
            log_message('warning', 'Offer notify candidate failed: ' . $e->getMessage());
        }
    }

    private function notifyHr(int $companyId, string $type, string $title, string $message): void
    {
        try {
            $db = Database::connect();
            $hrUsers = $db->table('users u')
                ->select('u.id')
                ->join('role_permissions rp', 'rp.role_id = u.role_id', 'left')
                ->join('permissions p', 'p.id = rp.permission_id', 'left')
                ->where('u.company_id', $companyId)
                ->where('u.is_active', 1)
                ->whereIn('p.slug', ['recruitment.offer.manage', 'recruitment.manage'])
                ->groupBy('u.id')
                ->get()->getResultArray();
            $notifier = Services::notification();
            foreach ($hrUsers as $u) {
                $notifier->send(
                    (int) $u['id'],
                    $companyId,
                    $type,
                    $title,
                    $message,
                    [],
                    null,
                    false,
                );
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Offer notify HR failed: ' . $e->getMessage());
        }
    }
}