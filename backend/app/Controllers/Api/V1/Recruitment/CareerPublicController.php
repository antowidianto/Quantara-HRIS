<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CareerPublicController — endpoint untuk public career portal.
 *
 * SEMUA endpoint di sini PUBLIC (no JWT). Lihat Routes.php — definisikan
 * di luar grup yang punya filter 'jwt'.
 *
 * Endpoint:
 *   GET  /careers/{slug}                       → landing page company
 *   GET  /careers/{slug}/jobs                  → list lowongan + filter
 *   GET  /careers/{slug}/jobs/{job_slug}       → detail lowongan
 *   POST /careers/{slug}/jobs/{job_slug}/apply → submit lamaran
 *   POST /careers/upload                       → upload CV/KTP/foto/ijazah (no auth, max 5MB, MIME ketat)
 *   GET  /careers/track/{token}                → cek status lamaran
 *
 * Tidak menggunakan TenantContext (no auth) — resolusi tenant lewat slug.
 */
class CareerPublicController extends BaseController
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    private const ALLOWED_DOC_KINDS = ['cv', 'ktp', 'ijazah', 'photo', 'other'];

    /**
     * GET /careers/{slug} — landing data (company, page, jobs, stats)
     */
    public function landing(?string $slug = null)
    {
        $slug = $this->normalizeSlug($slug);
        if (! $slug) return $this->fail('Slug perusahaan tidak valid', 400);

        $data = Services::career()->getPublicLanding($slug);
        if (! $data) {
            return $this->fail('Career portal tidak ditemukan atau belum aktif', 404);
        }
        return $this->ok($data);
    }

    /**
     * GET /careers/{slug}/jobs — public list (dengan filter q, city, etc.)
     */
    public function listJobs(?string $slug = null)
    {
        $slug = $this->normalizeSlug($slug);
        if (! $slug) return $this->fail('Slug perusahaan tidak valid', 400);

        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $filters = [
            'q'                => (string) ($this->request->getGet('q') ?? ''),
            'city'             => (string) ($this->request->getGet('city') ?? ''),
            'employment_type'  => (string) ($this->request->getGet('employment_type') ?? ''),
            'work_arrangement' => (string) ($this->request->getGet('work_arrangement') ?? ''),
        ];
        $jobs = Services::career()->getPublishedJobs((int) $company['id'], $filters);
        return $this->ok([
            'company' => ['id' => (int) $company['id'], 'name' => $company['name'], 'career_slug' => $slug],
            'jobs'    => $jobs,
            'filters' => $filters,
        ]);
    }

    /**
     * GET /careers/{slug}/jobs/{jobSlug} — detail lowongan public
     */
    public function jobDetail(?string $slug = null, ?string $jobSlug = null)
    {
        $slug = $this->normalizeSlug($slug);
        $jobSlug = $this->normalizeSlug($jobSlug);
        if (! $slug || ! $jobSlug) return $this->fail('Slug tidak valid', 400);

        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $job = Services::career()->getPublicJobDetail((int) $company['id'], $jobSlug);
        if (! $job) return $this->fail('Lowongan tidak ditemukan', 404);

        $page = Services::career()->getOrCreateCareerPage((int) $company['id']);
        // Resolve form schema: prefer job-level kalau ada, fallback ke page level
        $extraQuestions = ! empty($job['apply_form_schema'])
            ? $job['apply_form_schema']
            : (! empty($page['extra_questions']) ? (is_string($page['extra_questions'])
                ? json_decode($page['extra_questions'], true) : $page['extra_questions']) : null);

        return $this->ok([
            'company' => [
                'id' => (int) $company['id'],
                'name' => $company['name'],
                'career_slug' => $slug,
                'logo_path' => $company['logo_path'] ?? null,
                'brand_color' => $page['brand_color'] ?? '#0F62FE',
            ],
            'job' => $job,
            'extra_questions' => $extraQuestions,
        ]);
    }

    /**
     * GET /careers/{slug}/options
     *
     * Opsi form kandidat:
     * - prioritas dari tabel master lokal hasil sync online
     * - fallback ke data tenant existing kalau master belum terisi
     */
    public function formOptions(?string $slug = null)
    {
        $slug = $this->normalizeSlug($slug);
        if (! $slug) return $this->fail('Slug perusahaan tidak valid', 400);

        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        $db = \Config\Database::connect();
        $locations = [];

        if ($db->tableExists('career_master_locations')) {
            $rows = $db->table('career_master_locations')
                ->select('province, city, postal_code')
                ->where('is_active', 1)
                ->orderBy('province', 'ASC')
                ->orderBy('city', 'ASC')
                ->orderBy('postal_code', 'ASC')
                ->get()->getResultArray();

            foreach ($rows as $row) {
                $province = trim((string) ($row['province'] ?? ''));
                $city = trim((string) ($row['city'] ?? ''));
                $postal = trim((string) ($row['postal_code'] ?? ''));
                if ($province === '' || $city === '' || $postal === '') continue;
                $locations[$province][$city] ??= [];
                if (! in_array($postal, $locations[$province][$city], true)) {
                    $locations[$province][$city][] = $postal;
                }
            }
        }

        if (empty($locations) && $db->tableExists('candidates')) {
            $rows = $db->table('candidates')
                ->select('province, city, postal_code')
                ->where('company_id', (int) $company['id'])
                ->where('deleted_at IS NULL')
                ->groupStart()
                    ->where('province IS NOT NULL')
                    ->orWhere('city IS NOT NULL')
                    ->orWhere('postal_code IS NOT NULL')
                ->groupEnd()
                ->get()->getResultArray();

            foreach ($rows as $row) {
                $province = trim((string) ($row['province'] ?? ''));
                $city = trim((string) ($row['city'] ?? ''));
                $postal = trim((string) ($row['postal_code'] ?? ''));
                if ($province === '' && $city === '' && $postal === '') continue;
                $provinceKey = $province !== '' ? $province : 'Lainnya';
                $cityKey = $city !== '' ? $city : 'Lainnya';
                $locations[$provinceKey][$cityKey] ??= [];
                if ($postal !== '' && ! in_array($postal, $locations[$provinceKey][$cityKey], true)) {
                    $locations[$provinceKey][$cityKey][] = $postal;
                }
            }
        }

        if (empty($locations) && $db->tableExists('job_postings')) {
            $jobCities = $db->table('job_postings')
                ->select('city')
                ->where('company_id', (int) $company['id'])
                ->where('deleted_at IS NULL')
                ->where('city IS NOT NULL')
                ->groupBy('city')
                ->get()->getResultArray();
            foreach ($jobCities as $row) {
                $city = trim((string) ($row['city'] ?? ''));
                if ($city === '') continue;
                $locations['Lainnya'][$city] ??= [];
            }
        }

        $locationOptions = [];
        ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($locations as $province => $cities) {
            ksort($cities, SORT_NATURAL | SORT_FLAG_CASE);
            $cityOptions = [];
            foreach ($cities as $city => $postalCodes) {
                sort($postalCodes, SORT_NATURAL);
                $cityOptions[] = [
                    'city' => $city,
                    'postal_codes' => array_values($postalCodes),
                ];
            }
            $locationOptions[] = [
                'province' => $province,
                'cities' => $cityOptions,
            ];
        }

        $institutions = [];
        if ($db->tableExists('career_master_institutions')) {
            $institutionRows = $db->table('career_master_institutions')
                ->select('name')
                ->where('is_active', 1)
                ->orderBy('name', 'ASC')
                ->limit(5000)
                ->get()->getResultArray();
            foreach ($institutionRows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') $institutions[] = $name;
            }
        }

        if (empty($institutions) && $db->tableExists('candidates')) {
            $institutionRows = $db->table('candidates')
                ->select('last_education_institution')
                ->where('company_id', (int) $company['id'])
                ->where('deleted_at IS NULL')
                ->where('last_education_institution IS NOT NULL')
                ->groupBy('last_education_institution')
                ->orderBy('last_education_institution', 'ASC')
                ->get()->getResultArray();
            foreach ($institutionRows as $row) {
                $name = trim((string) ($row['last_education_institution'] ?? ''));
                if ($name !== '') $institutions[] = $name;
            }
        }

        return $this->ok([
            'locations' => $locationOptions,
            'institutions' => array_values(array_unique($institutions)),
        ]);
    }

    /**
     * POST /careers/{slug}/jobs/{jobSlug}/apply
     * Submit lamaran. Body JSON / multipart: profile + path file (sudah
     * di-upload via /careers/upload).
     */
    public function apply(?string $slug = null, ?string $jobSlug = null)
    {
        $slug = $this->normalizeSlug($slug);
        $jobSlug = $this->normalizeSlug($jobSlug);
        if (! $slug || ! $jobSlug) return $this->fail('Slug tidak valid', 400);

        $company = $this->resolveCompanyOrFail($slug);
        if (! $company) return $this->response;

        // Sprint 21+ — apply WAJIB login candidate. Kalau tidak ada
        // Authorization header, kembalikan 401 dengan code khusus supaya
        // frontend bisa redirect ke /careers/{slug}/login.
        $candidateAccount = $this->resolveCandidateFromHeader((int) $company['id']);
        if (! $candidateAccount) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'  => 'error',
                'code'    => 'CANDIDATE_LOGIN_REQUIRED',
                'message' => 'Silakan login atau buat akun terlebih dahulu untuk melamar.',
            ]);
        }

        $db = \Config\Database::connect();
        $job = $db->table('job_postings')
            ->where('company_id', (int) $company['id'])
            ->where('public_slug', $jobSlug)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $job) return $this->fail('Lowongan tidak ditemukan', 404);

        $body = $this->request->getJSON(true);
        if (! is_array($body)) $body = $this->request->getPost();
        if (! is_array($body)) $body = [];

        // Auto-fill email/full_name dari account kalau body tidak sertakan,
        // supaya kandidat tidak perlu input ulang.
        if (empty($body['email']))     $body['email']     = $candidateAccount['email'];
        if (empty($body['full_name'])) $body['full_name'] = $candidateAccount['full_name'];
        if (empty($body['phone']) && ! empty($candidateAccount['phone'])) {
            $body['phone'] = $candidateAccount['phone'];
        }

        $applicantIp = $this->request->getIPAddress();

        try {
            $result = Services::career()->submitApplication(
                (int) $company['id'],
                (int) $job['id'],
                $body,
                $applicantIp,
                (int) $candidateAccount['id'],
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'Career apply error: ' . $e->getMessage());
            return $this->fail('Terjadi kesalahan, silakan coba lagi.', 500);
        }

        // Track URL — frontend route public
        $trackUrl = '/careers/' . $slug . '/track/' . $result['token'];

        return $this->ok([
            'candidate_id'      => $result['candidate_id'],
            'token'             => $result['token'],
            'track_url'         => $trackUrl,
            'job'               => $result['job'],
            'message'           => 'Terima kasih! Lamaran Anda telah kami terima. '
                . 'Tim HR akan menghubungi via email yang Anda daftarkan.',
        ], 'Application received', 201);
    }

    /**
     * POST /careers/upload?kind=cv|ktp|ijazah|photo|other
     * Upload file lampiran (TANPA auth). Validasi MIME + size.
     * Return path yang harus disertakan dalam payload apply.
     */
    public function upload()
    {
        $kind = strtolower((string) ($this->request->getGet('kind') ?? 'other'));
        if (! in_array($kind, self::ALLOWED_DOC_KINDS, true)) {
            return $this->fail('Jenis dokumen tidak dikenal. Gunakan: ' . implode(', ', self::ALLOWED_DOC_KINDS), 400);
        }

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File tidak valid: ' . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->fail('Ukuran file melebihi batas 5 MB', 413);
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            return $this->fail('Tipe file tidak diizinkan. Allowed: PDF, JPG, PNG, WebP, DOC/DOCX', 415);
        }
        // Untuk foto: PDF/DOC tidak masuk akal — extra check
        if ($kind === 'photo' && ! str_starts_with($mime, 'image/')) {
            return $this->fail('Pas foto harus berupa gambar (JPG/PNG/WebP)', 415);
        }

        $ext = pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: 'bin';
        $ext = preg_replace('/[^A-Za-z0-9]+/', '', strtolower($ext));

        $relativePath = sprintf(
            'careers/%s/%s/%s_%s.%s',
            date('Y-m'),
            $kind,
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
            $ext,
        );

        $storage = Services::fileStorage();
        $bytes = file_get_contents($file->getTempName());
        if ($bytes === false || ! $storage->put($relativePath, $bytes)) {
            return $this->fail('Gagal menyimpan file', 500);
        }

        // Audit (NOTICE) — tidak ada user context, jadi tetap log untuk visibility.
        try {
            Services::audit()->forModule('CAREER')->log(
                action: 'career.upload',
                entity: 'attachment',
                description: "Public upload {$kind}: {$file->getClientName()} ("
                    . round($file->getSize() / 1024) . ' KB)',
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {
            // ignore
        }

        return $this->ok([
            'path'          => $relativePath,
            'kind'          => $kind,
            'mime'          => $mime,
            'size'          => $file->getSize(),
            'original_name' => $file->getClientName(),
        ], 'File terupload', 201);
    }

    /**
     * GET /careers/track/{token} — cek status lamaran via token.
     * Token hex 64 char yang dikirim saat apply.
     */
    public function track(?string $token = null)
    {
        $token = trim((string) $token);
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return $this->fail('Token tidak valid', 400);
        }

        $data = Services::career()->resolveTrackToken($token);
        if (! $data) {
            return $this->fail('Token tidak ditemukan atau sudah kadaluarsa', 404);
        }

        // Sembunyikan field internal yang sensitif (nik full, gaji)
        $cand = $data['candidate'];
        $maskedNik = ! empty($cand['nik']) ? str_pad(substr((string) $cand['nik'], -4), 16, '*', STR_PAD_LEFT) : null;

        return $this->ok([
            'candidate' => [
                'full_name'    => $cand['full_name'],
                'email'        => $this->maskEmail($cand['email'] ?? ''),
                'current_stage'=> $cand['current_stage'],
                'applied_at'   => $cand['applied_at'] ?? $cand['created_at'],
                'nik_masked'   => $maskedNik,
            ],
            'job' => [
                'reference_number' => $data['job']['reference_number'] ?? null,
                'title'            => $data['job']['title'] ?? null,
                'employment_type'  => $data['job']['employment_type'] ?? null,
                'city'             => $data['job']['city'] ?? null,
                'work_arrangement' => $data['job']['work_arrangement'] ?? null,
            ],
            'stages' => array_map(static fn($s) => [
                'stage'        => $s['stage'],
                'result'       => $s['result'],
                'scheduled_at' => $s['scheduled_at'] ?? null,
                'completed_at' => $s['completed_at'] ?? null,
                // Feedback hanya tampil kalau result final (PASSED/FAILED)
                'feedback'     => in_array($s['result'], ['PASSED','FAILED','SKIPPED'], true)
                    ? $s['feedback'] : null,
                'created_at'   => $s['created_at'],
            ], $data['stages']),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function normalizeSlug(?string $slug): ?string
    {
        if (! $slug) return null;
        $s = strtolower(trim($slug));
        // Allow alnum + dash, max 100
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $s)) return null;
        return $s;
    }

    /**
     * Resolve candidate account dari Authorization Bearer header.
     * Pastikan account aktif & terikat ke company yang sama dengan slug
     * di URL — supaya tidak bisa submit lamaran ke perusahaan lain pakai
     * token yang dikeluarkan untuk perusahaan A.
     */
    private function resolveCandidateFromHeader(int $companyId): ?array
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (! $header || ! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        $payload = Services::candidateAuth()->validateAccess(trim($m[1]));
        if (! $payload) return null;
        if ((int) ($payload['company_id'] ?? 0) !== $companyId) return null;

        $db = \Config\Database::connect();
        $row = $db->table('candidate_accounts')
            ->where('id', (int) $payload['sub'])
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        return $row ?: null;
    }

    private function resolveCompanyOrFail(string $slug): ?array
    {
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
        // Cek juga career_pages.is_active
        $page = Services::career()->getOrCreateCareerPage((int) $row['id']);
        if ((int) $page['is_active'] !== 1) {
            $this->response = $this->fail('Career portal sedang dinonaktifkan', 404);
            return null;
        }
        return $row;
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $localMasked = strlen($local) > 2
            ? substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2))
            : $local;
        return $localMasked . '@' . $domain;
    }

    // ─── Response helpers (mirip ApiController, tanpa tenant) ──

    protected function ok($data = null, string $message = 'OK', int $status = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function fail(string $message, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'status'  => 'error',
            'message' => $message,
        ]);
    }
}
