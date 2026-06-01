<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CandidateAuthController — endpoint auth pelamar (kandidat) di Career Portal.
 *
 * SEMUA endpoint di sini PUBLIC kecuali yang explicit pakai
 * filter `candidatejwt` (logout, me, change-password). Auth pakai
 * CandidateAuthService → JWT terpisah dari user internal.
 *
 * Routes (lihat Routes.php):
 *   POST /careers/(:slug)/account/register
 *   POST /careers/(:slug)/account/login
 *   POST /careers/(:slug)/account/forgot-password
 *   POST /careers/account/reset-password
 *   POST /careers/account/refresh
 *   POST /careers/account/logout                (filter: candidatejwt)
 *   GET  /careers/account/me                    (filter: candidatejwt)
 *   POST /careers/account/change-password       (filter: candidatejwt)
 *
 * Tenant resolution: slug company → company_id (sama pola dengan
 * CareerPublicController). Setelah login, semua endpoint kandidat
 * pakai company_id dari JWT claim.
 */
class CandidateAuthController extends BaseController
{
    use \App\Traits\DispatchesWebhooks;

    // ─── Public endpoints (slug-scoped) ─────────────────────────────

    public function register(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        try {
            $tokens = Services::candidateAuth()->register(
                (int) $company['id'],
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['full_name'] ?? ''),
                isset($body['phone']) ? (string) $body['phone'] : null,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Candidate register failed: ' . $e->getMessage());
            return $this->fail('Pendaftaran gagal, silakan coba lagi.', 500);
        }

        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::CANDIDATE_REGISTERED,
            (int) $company['id'],
            [
                'candidate_account_id' => $tokens['account']['id'],
                'email'                => $tokens['account']['email'],
                'full_name'            => $tokens['account']['full_name'],
                'company_slug'         => $company['career_slug'] ?? null,
                'registered_at'        => date('c'),
            ],
        );

        return $this->ok($tokens, 'Pendaftaran berhasil', 201);
    }

    public function login(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        $ip = $this->request->getIPAddress();
        $ua = $this->request->getHeaderLine('User-Agent');
        try {
            $tokens = Services::candidateAuth()->login(
                (int) $company['id'],
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                $ip,
                $ua,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 401);
        }
        return $this->ok($tokens, 'Login berhasil');
    }

    /**
     * Trigger forgot password — selalu return 200 supaya tidak ada
     * email enumeration. Token kita kirim via email kalau email valid.
     */
    public function forgotPassword(?string $slug = null)
    {
        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $body = $this->getBody();
        $email = (string) ($body['email'] ?? '');
        $token = Services::candidateAuth()->createPasswordResetToken((int) $company['id'], $email);

        if ($token) {
            // Kirim email dengan link: /careers/{slug}/account/reset?token=...
            // Pakai queueEmail biar konsisten dengan sistem notifikasi yang ada.
            try {
                $resetUrl = '/careers/' . ($company['career_slug'] ?? '') . '/account/reset?token=' . $token;
                $body = '<p>Klik link berikut untuk reset password akun career portal Anda.</p>'
                    . '<p><a href="' . htmlspecialchars($resetUrl) . '">Reset Password</a></p>'
                    . '<p>Link berlaku 60 menit. Kalau Anda tidak pernah meminta reset, abaikan email ini.</p>';
                Services::notification()->queueEmail(
                    (int) $company['id'],
                    (string) $email,
                    null,
                    'Reset Password — ' . ($company['name'] ?? 'Career Portal'),
                    $body,
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Reset email send failed: ' . $e->getMessage());
            }
        }

        return $this->ok(null, 'Jika email terdaftar, link reset sudah dikirim ke email Anda.');
    }

    public function resetPassword()
    {
        $body = $this->getBody();
        $token = (string) ($body['token'] ?? '');
        $newPassword = (string) ($body['password'] ?? '');
        try {
            Services::candidateAuth()->resetPassword($token, $newPassword);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Password berhasil direset. Silakan login ulang.');
    }

    public function refresh()
    {
        $body = $this->getBody();
        $token = (string) ($body['refresh_token'] ?? '');
        $ip = $this->request->getIPAddress();
        $ua = $this->request->getHeaderLine('User-Agent');
        $rotated = Services::candidateAuth()->rotate($token, $ip, $ua);
        if (! $rotated) return $this->fail('Refresh token tidak valid', 401);
        return $this->ok($rotated);
    }

    // ─── Authenticated endpoints (candidate JWT required) ──────────

    public function logout()
    {
        // Revoke current refresh token via jti dari refresh body kalau ada,
        // atau revoke all sessions kalau tidak.
        $body = $this->getBody();
        if (! empty($body['refresh_token'])) {
            try {
                $payload = (array) \Firebase\JWT\JWT::decode(
                    (string) $body['refresh_token'],
                    new \Firebase\JWT\Key(config('Jwt')->refreshSecret, config('Jwt')->algo),
                );
                if (! empty($payload['jti'])) {
                    Services::candidateAuth()->revokeRefresh((string) $payload['jti']);
                }
            } catch (\Throwable $e) {
                // ignore — invalid token, idempotent logout
            }
        } else {
            // Without refresh token, revoke all sessions for current account
            $account = $this->candidateAccount();
            if ($account) Services::candidateAuth()->revokeAllForAccount((int) $account['id']);
        }
        return $this->ok(null, 'Logged out');
    }

    public function me()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $db = \Config\Database::connect();

        // List lamaran kandidat ini
        $candidates = $db->table('candidates c')
            ->select('c.id, c.full_name, c.email, c.current_stage, c.applied_at,'
                . ' c.rating, c.rejection_reason,'
                . ' jp.id AS job_id, jp.title AS job_title, jp.reference_number,'
                . ' jp.public_slug AS job_slug, jp.work_arrangement, jp.city')
            ->join('job_postings jp', 'jp.id = c.job_posting_id')
            ->where('c.candidate_account_id', (int) $account['id'])
            ->where('c.deleted_at IS NULL')
            ->orderBy('c.applied_at', 'DESC')
            ->get()->getResultArray();

        // List test assignments yang dimiliki account ini
        $tests = $db->table('online_test_assignments ota')
            ->select('ota.id, ota.online_test_id, ota.candidate_id, ota.status,'
                . ' ota.available_from, ota.due_at, ota.max_attempts, ota.attempts_used,'
                . ' ota.best_percentage, ota.best_result, ota.completed_at,'
                . ' ot.name AS test_name, ot.category AS test_category, ot.duration_minutes,'
                . ' jp.title AS job_title')
            ->join('online_tests ot', 'ot.id = ota.online_test_id')
            ->join('job_postings jp', 'jp.id = ota.job_posting_id', 'left')
            ->where('ota.candidate_account_id', (int) $account['id'])
            ->orderBy('ota.created_at', 'DESC')
            ->get()->getResultArray();

        return $this->ok([
            'account' => [
                'id'         => (int) $account['id'],
                'email'      => $account['email'],
                'full_name'  => $account['full_name'],
                'phone'      => $account['phone'],
                'company_id' => (int) $account['company_id'],
            ],
            'applications' => $candidates,
            'tests'        => $tests,
        ]);
    }

    public function changePassword()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);
        $body = $this->getBody();
        try {
            Services::candidateAuth()->changePassword(
                (int) $account['id'],
                (string) ($body['current_password'] ?? ''),
                (string) ($body['new_password'] ?? ''),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Password berhasil diganti. Silakan login ulang.');
    }

    /**
     * GET /careers/account/applications/{id}
     *
     * Sprint 23+ — Kandidat lihat detail lamaran-nya untuk fitur "Profil
     * Saya". Hanya bisa akses lamaran yang dimiliki account ini.
     */
    public function showApplication($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $row = $this->ownedApplicationOrFail((int) $id, $account);
        if (! $row) return $this->response;

        return $this->ok($this->serializeApplication($row, $account));
    }

    /**
     * PUT /careers/account/applications/{id}
     *
     * Update lamaran. Boleh dilakukan kandidat selama belum di stage final
     * (HIRED, REJECTED, WITHDRAWN). Field yang boleh di-edit: profil
     * pribadi, identitas, payroll, kontak darurat, keluarga, skill, dll.
     * Field yang TIDAK boleh diubah: stage, rating, feedback HR, paths
     * dokumen (re-upload via /careers/upload), candidate_account_id,
     * audit fields.
     */
    public function updateApplication($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $row = $this->ownedApplicationOrFail((int) $id, $account);
        if (! $row) return $this->response;

        // Sprint 23+ — terminal stage (terutama HIRED) lock data karena
        // sudah dipakai untuk generate kontrak / employee record. HR
        // pegang full control mulai sini. REJECTED/WITHDRAWN juga locked
        // supaya tidak ada update setelah rejection (audit trail clean).
        if (in_array($row['current_stage'], ['HIRED', 'REJECTED', 'WITHDRAWN'], true)) {
            return $this->fail(
                'Lamaran sudah pada tahap final (' . $row['current_stage'] . '), tidak bisa diedit. '
                . 'Hubungi tim HR jika ada perubahan data penting.',
                422,
                'APPLICATION_LOCKED',
            );
        }

        $body = $this->getBody();
        try {
            Services::career()->updateApplicationByCandidate(
                (int) $id,
                (int) $account['id'],
                $body,
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // Re-fetch supaya response selalu fresh
        $db = \Config\Database::connect();
        $fresh = $db->table('candidates')->where('id', (int) $id)->get()->getRowArray();
        return $this->ok($this->serializeApplication($fresh, $account), 'Profil lamaran berhasil diperbarui');
    }

    // ─── Offers (Sprint 25 / Option C) ──────────────────────────────
    //
    // Kandidat self-service review + accept/reject offer-nya, mirror
    // pola yang sudah ada untuk online test. Audit trail pakai source
    // CANDIDATE_PORTAL + metadata IP/UA → trail forensic kalau ada
    // dispute "saya nggak pernah accept".
    //
    // Routes:
    //   GET  /careers/account/offers                — list offer milik account
    //   GET  /careers/account/offers/{id}           — detail offer (decoded)
    //   POST /careers/account/offers/{id}/respond   — accept/reject self-service

    /**
     * GET /careers/account/offers
     *
     * List semua offer (semua status) untuk lamaran milik kandidat ini.
     * Frontend yang highlight badge merah untuk SENT yang masih bisa
     * direspon — backend tetap return semua supaya kandidat bisa lihat
     * history (offer yang sudah expired, withdrawn, dll).
     */
    public function listOffers()
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $db = \Config\Database::connect();
        // Scope: semua offer untuk semua candidate row yang dimiliki account ini.
        // Kandidat bisa apply ke beberapa lowongan → bisa punya beberapa offer.
        $rows = $db->table('candidate_offers o')
            ->select('o.id, o.offer_number, o.status, o.basic_salary, o.currency,'
                . ' o.employment_status, o.proposed_join_date, o.probation_months,'
                . ' o.response_deadline, o.sent_at, o.responded_at,'
                . ' o.candidate_id,'
                . ' c.full_name AS candidate_name,'
                . ' jp.title AS job_title, jp.reference_number AS job_reference,'
                . ' p.name AS position_name, d.name AS department_name')
            ->join('candidates c', 'c.id = o.candidate_id')
            ->join('job_postings jp', 'jp.id = o.job_posting_id', 'left')
            ->join('positions p', 'p.id = o.position_id', 'left')
            ->join('departments d', 'd.id = o.department_id', 'left')
            ->where('c.candidate_account_id', (int) $account['id'])
            ->where('o.deleted_at IS NULL')
            ->orderBy('o.created_at', 'DESC')
            ->get()->getResultArray();

        // Hitung counter untuk dashboard widget
        $pending = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'SENT') $pending++;
        }

        return $this->ok([
            'offers'        => $rows,
            'pending_count' => $pending,
        ]);
    }

    /**
     * GET /careers/account/offers/{id}
     *
     * Detail offer untuk halaman review. Hanya offer yang status SENT,
     * ACCEPTED, REJECTED, atau EXPIRED yang boleh dilihat kandidat —
     * DRAFT internal HR tidak boleh leak.
     */
    public function showOffer($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $offer = $this->ownedOfferOrFail((int) $id, $account);
        if (! $offer) return $this->response;

        // Block DRAFT — itu masih internal HR, belum di-send
        if ($offer['status'] === 'DRAFT') {
            return $this->fail('Offer belum tersedia. Hubungi tim HR.', 404, 'OFFER_NOT_VISIBLE');
        }

        // Load full detail via service (sudah decode JSON allowances/benefits)
        try {
            $detail = \Config\Services::offer()->loadOffer((int) $offer['id']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }

        // Sanitize: jangan kirim field internal HR
        $hidden = ['internal_notes', 'created_by', 'sent_by'];
        foreach ($hidden as $h) unset($detail[$h]);

        // Tambah job context biar frontend bisa tampilkan ringkasan
        $db = \Config\Database::connect();
        $job = null;
        if (! empty($detail['job_posting_id'])) {
            $job = $db->table('job_postings')
                ->select('id, title, reference_number, public_slug, work_arrangement, city, employment_type')
                ->where('id', (int) $detail['job_posting_id'])
                ->get()->getRowArray();
        }
        $company = $db->table('companies')
            ->select('id, name, career_slug, logo_url')
            ->where('id', (int) $detail['company_id'])
            ->get()->getRowArray();

        // Flag: bisa direspon atau tidak (status SENT + deadline belum lewat)
        $canRespond = $detail['status'] === 'SENT'
            && (empty($detail['response_deadline'])
                || strtotime($detail['response_deadline'] . ' 23:59:59') >= time());

        return $this->ok([
            'offer'       => $detail,
            'job'         => $job,
            'company'     => $company,
            'can_respond' => $canRespond,
            'expired'     => $detail['status'] === 'SENT'
                && ! empty($detail['response_deadline'])
                && strtotime($detail['response_deadline'] . ' 23:59:59') < time(),
        ]);
    }

    /**
     * POST /careers/account/offers/{id}/respond
     *
     * Body: { decision: 'ACCEPTED'|'REJECTED', note: string|null,
     *         rejection_reason: string|null }
     *
     * Kandidat self-service accept/reject offer-nya. Audit + IP/UA
     * di-record via OfferService::recordResponseByCandidate().
     */
    public function respondToOffer($id = null)
    {
        $account = $this->candidateAccount();
        if (! $account) return $this->fail('Not authenticated', 401);

        $offer = $this->ownedOfferOrFail((int) $id, $account);
        if (! $offer) return $this->response;

        $body = $this->getBody();
        $decision = strtoupper((string) ($body['decision'] ?? ''));
        if (! in_array($decision, ['ACCEPTED', 'REJECTED'], true)) {
            return $this->fail('decision harus ACCEPTED atau REJECTED', 422, 'INVALID_INPUT');
        }
        $note = isset($body['note']) ? mb_substr(trim((string) $body['note']), 0, 2000) : null;
        $rejectionReason = isset($body['rejection_reason'])
            ? mb_substr(trim((string) $body['rejection_reason']), 0, 1000)
            : null;
        if ($decision === 'REJECTED' && empty($rejectionReason)) {
            return $this->fail('Alasan penolakan wajib diisi (minimal 5 karakter)', 422, 'REJECTION_REASON_REQUIRED');
        }
        if ($decision === 'REJECTED' && mb_strlen($rejectionReason) < 5) {
            return $this->fail('Alasan penolakan minimal 5 karakter', 422, 'REJECTION_REASON_TOO_SHORT');
        }

        $ip = $this->request->getIPAddress();
        $ua = $this->request->getHeaderLine('User-Agent');

        try {
            $result = Services::offer()->recordResponseByCandidate(
                (int) $offer['id'],
                $decision,
                (int) $account['id'],
                $note,
                $rejectionReason,
                $ip ?: null,
                $ua ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_INPUT');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'OFFER_BLOCKED');
        } catch (\Throwable $e) {
            log_message('error', 'Candidate offer respond failed: ' . $e->getMessage());
            return $this->fail('Gagal mencatat respon. Silakan coba lagi.', 500);
        }

        $message = $decision === 'ACCEPTED'
            ? 'Penawaran berhasil diterima. Tim HR akan menghubungi Anda untuk proses berikutnya.'
            : 'Penawaran berhasil ditolak. Terima kasih atas waktunya.';

        // Sanitize sebelum return — sama seperti showOffer
        unset($result['internal_notes'], $result['created_by'], $result['sent_by']);

        return $this->ok([
            'offer'    => $result,
            'decision' => $decision,
        ], $message);
    }

    /**
     * Helper — pastikan offer milik kandidat ini (via candidates.candidate_account_id).
     * Kandidat bisa punya banyak candidate row (apply ke banyak job),
     * jadi join candidates.candidate_account_id ke account ID.
     */
    private function ownedOfferOrFail(int $id, array $account): ?array
    {
        if ($id <= 0) {
            $this->response = $this->fail('ID offer tidak valid', 400);
            return null;
        }
        $db = \Config\Database::connect();
        $row = $db->table('candidate_offers o')
            ->select('o.*')
            ->join('candidates c', 'c.id = o.candidate_id')
            ->where('o.id', $id)
            ->where('c.candidate_account_id', (int) $account['id'])
            ->where('o.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Penawaran tidak ditemukan atau bukan milik Anda', 404);
            return null;
        }
        return $row;
    }

    /**
     * Helper — pastikan application milik account yang sedang login &
     * load row lengkap. Set $this->response untuk error supaya caller
     * tinggal `return $this->response`.
     */
    private function ownedApplicationOrFail(int $id, array $account): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('candidates')
            ->where('id', $id)
            ->where('candidate_account_id', (int) $account['id'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Lamaran tidak ditemukan atau bukan milik Anda', 404);
            return null;
        }
        return $row;
    }

    /**
     * Bentuk response shape untuk endpoint show/update application —
     * include job info + decode JSON fields.
     */
    private function serializeApplication(array $cand, array $account): array
    {
        $db = \Config\Database::connect();
        $job = $db->table('job_postings')
            ->select('id, title, reference_number, public_slug, work_arrangement, city,'
                . ' employment_type, status, min_education')
            ->where('id', (int) $cand['job_posting_id'])
            ->get()->getRowArray();

        // Decode JSON fields kalau ada
        if (isset($cand['extra_answers']) && is_string($cand['extra_answers'])) {
            $decoded = json_decode($cand['extra_answers'], true);
            $cand['extra_answers'] = is_array($decoded) ? $decoded : [];
        }
        if (isset($cand['reference_contacts']) && is_string($cand['reference_contacts'])) {
            $decoded = json_decode($cand['reference_contacts'], true);
            $cand['reference_contacts'] = is_array($decoded) ? $decoded : [];
        }

        // driving_license / vehicle_owned di DB string comma-separated;
        // convert ke array supaya frontend langsung bisa pake checkbox.
        foreach (['driving_license', 'vehicle_owned'] as $col) {
            if (! empty($cand[$col]) && is_string($cand[$col])) {
                $cand[$col] = array_values(array_filter(
                    array_map('trim', explode(',', $cand[$col])),
                ));
            } elseif (empty($cand[$col])) {
                $cand[$col] = [];
            }
        }

        // Field yang HR-only / sensitif untuk client side jangan di-expose
        // (mis. internal notes / feedback HR yang ditulis interviewer).
        $hidden = [
            'applicant_ip',           // privasi
            'rating',                 // rating internal HR
            'rejection_reason',       // boleh diliat di dashboard list, bukan di edit form
            'created_by',
            'updated_by',
            'deleted_at',
        ];
        foreach ($hidden as $h) unset($cand[$h]);

        // Flag editable supaya UI tahu apakah harus disabled
        $editable = ! in_array($cand['current_stage'], ['HIRED','REJECTED','WITHDRAWN'], true);

        return [
            'application' => $cand,
            'job'         => $job,
            'editable'    => $editable,
            'lock_reason' => $editable
                ? null
                : 'Lamaran sudah pada tahap final (' . $cand['current_stage'] . ')',
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function getBody(): array
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body)) $body = $this->request->getPost();
        return $body ?: [];
    }

    private function candidateAccount(): ?array
    {
        return $this->request->candidate ?? null;
    }

    private function resolveCompanyOrFail(?string $slug): ?array
    {
        $slug = $this->normalizeSlug($slug);
        if (! $slug) {
            $this->response = $this->fail('Slug perusahaan tidak valid', 400);
            return null;
        }
        $db = \Config\Database::connect();
        $row = $db->table('companies')
            ->where('career_slug', $slug)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Career portal tidak ditemukan', 404);
            return null;
        }
        return $row;
    }

    private function normalizeSlug(?string $slug): ?string
    {
        if (! $slug) return null;
        $s = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $s)) return null;
        return $s;
    }

    protected function ok($data = null, string $message = 'OK', int $status = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function fail(string $message, int $status = 400, ?string $code = null): ResponseInterface
    {
        $payload = ['status' => 'error', 'message' => $message];
        if ($code) $payload['code'] = $code;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }
}