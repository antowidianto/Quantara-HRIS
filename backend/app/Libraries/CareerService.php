<?php

namespace App\Libraries;

use Config\Services;

/**
 * CareerService
 *
 * Logika domain Career Portal — public-facing job board yang terhubung
 * ke pipeline `RecruitmentService` & `OnboardingService` existing.
 *
 * Tanggung jawab:
 *  1. Generate / validate company `career_slug` & job `public_slug`.
 *  2. Generate `application_track_tokens` untuk pelamar self-service
 *     (cek status, tanpa register).
 *  3. Resolve career page + published jobs untuk public renderer.
 *  4. Submit application: create candidate + initial stage NEW + token,
 *     audit + notify HR.
 *  5. Helper: validasi NIK/NPWP/KK (delegasi ke IndonesianValidator),
 *     anti-spam (rate limit per IP via cache).
 */
class CareerService
{
    public const TOKEN_TTL_DAYS = 90;
    public const RATE_LIMIT_PER_IP_PER_HOUR = 5;

    public const EDUCATION_LEVELS = [
        'SMA','SMK','D1','D2','D3','D4','S1','S2','S3',
    ];
    public const RELIGIONS = [
        'ISLAM','KRISTEN','KATOLIK','HINDU','BUDHA','KONGHUCU','LAINNYA',
    ];
    public const MARITAL_STATUSES = [
        'BELUM_KAWIN','KAWIN','CERAI_HIDUP','CERAI_MATI',
    ];

    /**
     * Generate slug dari nama company (lowercase, dash-separated).
     * Memastikan unik dengan menambah suffix angka.
     */
    public function slugifyCompany(string $name, ?int $excludeCompanyId = null): string
    {
        $base = $this->slugify($name);
        if ($base === '') $base = 'company';
        $candidate = $base;
        $i = 2;
        $db = \Config\Database::connect();
        while (true) {
            $builder = $db->table('companies')->where('career_slug', $candidate);
            if ($excludeCompanyId) $builder->where('id !=', $excludeCompanyId);
            $exists = $builder->countAllResults();
            if ($exists === 0) return $candidate;
            $candidate = $base . '-' . $i;
            $i++;
            if ($i > 100) {
                // Fallback random
                return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            }
        }
    }

    /**
     * Generate slug dari title job + reference number.
     * Unik per company.
     */
    public function slugifyJob(string $title, string $referenceNumber, int $companyId, ?int $excludeJobId = null): string
    {
        $base = $this->slugify($title);
        if ($base === '') $base = 'lowongan';
        // Append reference suffix supaya 2 lowongan dengan title sama tetap distinct
        $refSuffix = strtolower(preg_replace('/[^a-z0-9]+/i', '', $referenceNumber));
        $candidate = $base . '-' . $refSuffix;
        $i = 2;
        $db = \Config\Database::connect();
        while (true) {
            $builder = $db->table('job_postings')
                ->where('company_id', $companyId)
                ->where('public_slug', $candidate);
            if ($excludeJobId) $builder->where('id !=', $excludeJobId);
            $exists = $builder->countAllResults();
            if ($exists === 0) return $candidate;
            $candidate = $base . '-' . $refSuffix . '-' . $i;
            $i++;
            if ($i > 50) {
                return $base . '-' . $refSuffix . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }
        }
    }

    private function slugify(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        $v = trim((string) $v, '-');
        // Limit length
        if (mb_strlen($v) > 80) $v = mb_substr($v, 0, 80);
        return $v ?: '';
    }

    /**
     * Buat token track untuk kandidat (random 32-byte hex).
     * @return string token (panjang 64 hex char)
     */
    public function createTrackToken(int $candidateId, int $companyId): string
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $expiry = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_DAYS . ' days'));
        // Loop sampai dapat token yang unik (sangat kecil collision di hex 64)
        $tries = 0;
        while ($tries < 5) {
            $token = bin2hex(random_bytes(32));
            $exists = $db->table('application_track_tokens')->where('token', $token)->countAllResults();
            if ($exists === 0) {
                $db->table('application_track_tokens')->insert([
                    'company_id'   => $companyId,
                    'candidate_id' => $candidateId,
                    'token'        => $token,
                    'expires_at'   => $expiry,
                    'created_at'   => $now,
                ]);
                return $token;
            }
            $tries++;
        }
        throw new \RuntimeException('Tidak bisa generate token unik (collision)');
    }

    /**
     * Resolve token → candidate + job. Hanya yang belum expired.
     */
    public function resolveTrackToken(string $token): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('application_track_tokens')
            ->where('token', $token)
            ->get()->getRowArray();
        if (! $row) return null;
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null; // expired
        }
        // Update last_used_at (best-effort)
        $db->table('application_track_tokens')
            ->where('id', (int) $row['id'])
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);

        $cand = $db->table('candidates')->where('id', (int) $row['candidate_id'])->get()->getRowArray();
        if (! $cand) return null;
        $job = $db->table('job_postings')->where('id', (int) $cand['job_posting_id'])->get()->getRowArray();
        $stages = $db->table('candidate_stages')
            ->where('candidate_id', (int) $cand['id'])
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        return [
            'candidate' => $cand,
            'job'       => $job,
            'stages'    => $stages,
        ];
    }

    /**
     * Get atau auto-create career_pages row untuk company.
     * Disebut juga pada read public sehingga selalu ada baseline.
     */
    public function getOrCreateCareerPage(int $companyId): array
    {
        $db = \Config\Database::connect();
        $row = $db->table('career_pages')->where('company_id', $companyId)->get()->getRowArray();
        if ($row) return $row;
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'  => $companyId,
            'is_active'   => 1,
            'brand_color' => '#0F62FE',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('career_pages')->insert($insert);
        $insert['id'] = (int) $db->insertID();
        return $insert;
    }

    /**
     * Resolve public landing data berdasar company slug.
     * Return null kalau company tidak ada / inactive / portal off.
     */
    public function getPublicLanding(string $companySlug): ?array
    {
        $db = \Config\Database::connect();
        $company = $db->table('companies')
            ->where('career_slug', $companySlug)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $company) return null;

        $page = $this->getOrCreateCareerPage((int) $company['id']);
        if ((int) $page['is_active'] !== 1) return null;

        $jobs = $this->getPublishedJobs((int) $company['id']);
        $stats = [
            'open_jobs'   => count($jobs),
            'departments' => count(array_unique(array_filter(array_column($jobs, 'department_name')))),
            'cities'      => count(array_unique(array_filter(array_column($jobs, 'city')))),
        ];

        return [
            'company' => $this->publicCompanyView($company),
            'page'    => $this->publicPageView($page),
            'jobs'    => $jobs,
            'stats'   => $stats,
        ];
    }

    /**
     * Get published jobs (public). Filter optional q (search), city,
     * employment_type, work_arrangement.
     */
    public function getPublishedJobs(int $companyId, array $filters = []): array
    {
        $db = \Config\Database::connect();
        $b = $db->table('job_postings jp')
            ->select('jp.id, jp.reference_number, jp.title, jp.public_slug, jp.employment_type,'
                . ' jp.city, jp.work_arrangement, jp.min_education, jp.min_experience_years,'
                . ' jp.salary_min, jp.salary_max, jp.salary_visible, jp.headcount,'
                . ' jp.opened_at, jp.closed_at, jp.career_published_at, jp.tags,'
                . ' d.name AS department_name, p.name AS position_name, b.name AS branch_name')
            ->join('departments d', 'd.id = jp.department_id', 'left')
            ->join('positions p', 'p.id = jp.position_id', 'left')
            ->join('branches b', 'b.id = jp.branch_id', 'left')
            ->where('jp.company_id', $companyId)
            ->where('jp.is_published_to_career', 1)
            ->where('jp.status', 'OPEN')
            ->where('jp.deleted_at IS NULL');

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $b->groupStart()
                ->like('jp.title', $q)
                ->orLike('jp.description', $q)
                ->orLike('jp.tags', $q)
                ->groupEnd();
        }
        if (! empty($filters['city'])) {
            $b->where('jp.city', $filters['city']);
        }
        if (! empty($filters['employment_type'])) {
            $b->where('jp.employment_type', strtoupper((string) $filters['employment_type']));
        }
        if (! empty($filters['work_arrangement'])) {
            $b->where('jp.work_arrangement', strtoupper((string) $filters['work_arrangement']));
        }

        $rows = $b->orderBy('jp.career_published_at', 'DESC')->get()->getResultArray();
        // Decode tags JSON
        foreach ($rows as &$r) {
            if (! empty($r['tags']) && is_string($r['tags'])) {
                $decoded = json_decode($r['tags'], true);
                $r['tags'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['tags'] = [];
            }
        }
        return $rows;
    }

    /**
     * Get detail published job (untuk public detail page).
     * Return null kalau tidak ditemukan atau tidak published.
     */
    public function getPublicJobDetail(int $companyId, string $jobSlug): ?array
    {
        $db = \Config\Database::connect();
        $job = $db->table('job_postings jp')
            ->select('jp.*,'
                . ' d.name AS department_name, p.name AS position_name, b.name AS branch_name')
            ->join('departments d', 'd.id = jp.department_id', 'left')
            ->join('positions p', 'p.id = jp.position_id', 'left')
            ->join('branches b', 'b.id = jp.branch_id', 'left')
            ->where('jp.company_id', $companyId)
            ->where('jp.public_slug', $jobSlug)
            ->where('jp.is_published_to_career', 1)
            ->where('jp.status', 'OPEN')
            ->where('jp.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $job) return null;

        // Decode JSON fields
        foreach (['tags','apply_form_schema'] as $jsonCol) {
            if (! empty($job[$jsonCol]) && is_string($job[$jsonCol])) {
                $job[$jsonCol] = json_decode($job[$jsonCol], true) ?: null;
            }
        }
        // Sembunyikan field internal yang tidak perlu untuk public
        unset(
            $job['hiring_manager_id'],
            $job['notes'],
            $job['created_by'],
            $job['updated_by'],
            $job['deleted_at'],
        );
        return $job;
    }

    /**
     * Submit lamaran via career portal.
     * Return [candidate_id, token] yang dikirim ke email pelamar.
     *
     * @throws \RuntimeException kalau rate-limit / job sudah closed / data invalid
     */
    public function submitApplication(int $companyId, int $jobId, array $data, ?string $applicantIp = null): array
    {
        $db = \Config\Database::connect();
        $job = $db->table('job_postings')
            ->where('id', $jobId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $job) {
            throw new \RuntimeException('Lowongan tidak ditemukan');
        }
        if ((int) $job['is_published_to_career'] !== 1 || $job['status'] !== 'OPEN') {
            throw new \RuntimeException('Lowongan ini sudah tidak menerima lamaran baru');
        }

        // Rate-limit per IP per hour (best-effort, in-memory cache)
        if ($applicantIp) {
            $this->enforceRateLimit($applicantIp);
        }

        // De-duplikasi: kalau email sudah pernah apply ke job yang sama dan
        // belum di-reject, tolak dengan pesan jelas (HR umumnya tidak suka
        // duplikasi pelamar di pipeline).
        if (! empty($data['email'])) {
            $existing = $db->table('candidates')
                ->where('company_id', $companyId)
                ->where('job_posting_id', $jobId)
                ->where('email', $data['email'])
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if ($existing) {
                throw new \RuntimeException(
                    'Email ini sudah melamar untuk posisi yang sama. Cek status di halaman Lacak Lamaran.'
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'job_posting_id'  => $jobId,
            'full_name'       => trim((string) ($data['full_name'] ?? '')),
            'email'           => $this->normalizeEmail($data['email'] ?? null),
            'phone'           => $this->normalizePhone($data['phone'] ?? null),
            'birth_place'     => $data['birth_place'] ?? null,
            'birth_date'      => $data['birth_date'] ?? null,
            'gender'          => $this->normalizeEnum($data['gender'] ?? null, ['M','F','O']),
            'nik'             => $this->normalizeDigits($data['nik'] ?? null, 16),
            'npwp'            => $this->normalizeDigits($data['npwp'] ?? null),
            'kk_number'       => $this->normalizeDigits($data['kk_number'] ?? null, 16),
            'bpjs_kesehatan_no' => $this->normalizeDigits($data['bpjs_kesehatan_no'] ?? null),
            'bpjs_tk_no'      => $this->normalizeDigits($data['bpjs_tk_no'] ?? null),
            'religion'        => $this->normalizeEnum($data['religion'] ?? null, self::RELIGIONS),
            'marital_status'  => $this->normalizeEnum($data['marital_status'] ?? null, self::MARITAL_STATUSES),
            'nationality'     => ! empty($data['nationality']) ? strtoupper((string) $data['nationality']) : 'WNI',
            'address'         => $data['address'] ?? null,
            'city'            => $data['city'] ?? null,
            'province'        => $data['province'] ?? null,
            'current_position'=> $data['current_position'] ?? null,
            'current_company' => $data['current_company'] ?? null,
            'years_experience'=> isset($data['years_experience']) ? max(0, (int) $data['years_experience']) : null,
            'expected_salary' => isset($data['expected_salary']) ? max(0, (float) $data['expected_salary']) : null,
            'available_from'  => $data['available_from'] ?? null,
            'last_education_level'       => $this->normalizeEnum($data['last_education_level'] ?? null, self::EDUCATION_LEVELS),
            'last_education_institution' => $data['last_education_institution'] ?? null,
            'last_education_major'       => $data['last_education_major'] ?? null,
            'last_education_year'        => isset($data['last_education_year']) && $data['last_education_year']
                ? (int) $data['last_education_year'] : null,
            'gpa'             => isset($data['gpa']) && $data['gpa'] !== '' && $data['gpa'] !== null
                ? round((float) $data['gpa'], 2) : null,
            'linkedin_url'    => $this->cleanUrl($data['linkedin_url'] ?? null),
            'portfolio_url'   => $this->cleanUrl($data['portfolio_url'] ?? null),
            'cover_letter'    => $data['cover_letter'] ?? null,
            'cv_path'         => $data['cv_path'] ?? null,
            'photo_path'      => $data['photo_path'] ?? null,
            'ktp_path'        => $data['ktp_path'] ?? null,
            'ijazah_path'     => $data['ijazah_path'] ?? null,
            'extra_answers'   => isset($data['extra_answers']) && is_array($data['extra_answers'])
                ? json_encode($data['extra_answers'], JSON_UNESCAPED_UNICODE) : null,
            'source'          => 'WEBSITE',
            'current_stage'   => 'NEW',
            'applied_via_career' => 1,
            'applied_at'      => $now,
            'applicant_ip'    => $applicantIp,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        // Validasi minimal
        if ($insert['full_name'] === '') {
            throw new \RuntimeException('Nama lengkap wajib diisi');
        }
        if (empty($insert['email'])) {
            throw new \RuntimeException('Email wajib diisi');
        }
        if (! filter_var($insert['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Format email tidak valid');
        }
        if (empty($insert['phone'])) {
            throw new \RuntimeException('Nomor HP/WhatsApp wajib diisi');
        }
        // CV mandatory
        if (empty($insert['cv_path'])) {
            throw new \RuntimeException('CV wajib diunggah');
        }
        // NIK kalau diisi harus 16 digit
        if (! empty($insert['nik']) && strlen($insert['nik']) !== 16) {
            throw new \RuntimeException('NIK harus 16 digit');
        }
        if (! empty($insert['kk_number']) && strlen($insert['kk_number']) !== 16) {
            throw new \RuntimeException('Nomor KK harus 16 digit');
        }
        // Min education check
        if (! empty($job['min_education']) && ! empty($insert['last_education_level'])) {
            if (! $this->educationMeetsMinimum($insert['last_education_level'], $job['min_education'])) {
                throw new \RuntimeException(
                    'Pendidikan minimum untuk posisi ini: ' . $job['min_education']
                );
            }
        }

        $db->transStart();
        $db->table('candidates')->insert($insert);
        $candidateId = (int) $db->insertID();

        // Initial stage NEW
        $db->table('candidate_stages')->insert([
            'company_id'   => $companyId,
            'candidate_id' => $candidateId,
            'stage'        => 'NEW',
            'result'       => 'PENDING',
            'feedback'     => 'Lamaran masuk via Career Portal',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Insert ke candidate_documents kalau ada path
        $docMap = [
            'CV'      => $insert['cv_path'],
            'KTP'     => $insert['ktp_path'],
            'IJAZAH'  => $insert['ijazah_path'],
            'OTHER'   => $insert['photo_path'], // pas foto
        ];
        foreach ($docMap as $type => $path) {
            if (empty($path)) continue;
            $title = $type === 'OTHER' ? 'Pas Foto' : $type;
            $db->table('candidate_documents')->insert([
                'company_id'   => $companyId,
                'candidate_id' => $candidateId,
                'document_type'=> $type,
                'file_name'    => $title . ' - ' . $insert['full_name'],
                'file_path'    => $path,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $token = $this->createTrackToken($candidateId, $companyId);
        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new \RuntimeException('Gagal menyimpan lamaran, silakan coba lagi');
        }

        // Audit log (NOTICE) — pakai company context manual karena public tanpa user
        try {
            Services::audit()->forModule('CAREER')->log(
                action: 'application.submit',
                entity: 'candidate',
                entityId: $candidateId,
                description: "Lamaran baru via career portal: {$insert['full_name']} → job #{$jobId} ({$job['title']})",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {
            // ignore audit failure
        }

        // Notifikasi ke hiring manager / HR
        $this->notifyHrNewApplicant($companyId, (int) $jobId, $candidateId, $insert['full_name'], (string) $job['title']);

        return [
            'candidate_id' => $candidateId,
            'token'        => $token,
            'job'          => [
                'id' => (int) $job['id'],
                'title' => $job['title'],
                'reference_number' => $job['reference_number'],
            ],
        ];
    }

    /**
     * Compare 2 education level. Return true jika $applicant >= $required.
     */
    public function educationMeetsMinimum(string $applicant, string $required): bool
    {
        $rank = array_flip(self::EDUCATION_LEVELS);
        if (! isset($rank[$applicant]) || ! isset($rank[$required])) return true;
        return $rank[$applicant] >= $rank[$required];
    }

    private function publicCompanyView(array $company): array
    {
        return [
            'id'           => (int) $company['id'],
            'name'         => $company['name'],
            'legal_name'   => $company['legal_name'] ?? $company['name'],
            'career_slug'  => $company['career_slug'] ?? null,
            'logo_path'    => $company['logo_path'] ?? null,
            'city'         => $company['city'] ?? null,
            'province'     => $company['province'] ?? null,
            'website'      => null,
        ];
    }

    private function publicPageView(array $page): array
    {
        $extra = $page['extra_questions'] ?? null;
        if (is_string($extra)) $extra = json_decode($extra, true) ?: null;
        return [
            'is_active'        => (int) $page['is_active'],
            'headline'         => $page['headline'] ?? null,
            'about_html'       => $page['about_html'] ?? null,
            'benefits_html'    => $page['benefits_html'] ?? null,
            'hero_image_path'  => $page['hero_image_path'] ?? null,
            'logo_path'        => $page['logo_path'] ?? null,
            'brand_color'      => $page['brand_color'] ?? '#0F62FE',
            'contact_email'    => $page['contact_email'] ?? null,
            'contact_phone'    => $page['contact_phone'] ?? null,
            'instagram_url'    => $page['instagram_url'] ?? null,
            'linkedin_url'     => $page['linkedin_url'] ?? null,
            'website_url'      => $page['website_url'] ?? null,
            'tiktok_url'       => $page['tiktok_url'] ?? null,
            'announcement'     => $page['announcement'] ?? null,
            'extra_questions'  => $extra,
        ];
    }

    private function normalizeEmail($email): ?string
    {
        if (empty($email)) return null;
        return strtolower(trim((string) $email));
    }

    private function normalizePhone($phone): ?string
    {
        if (empty($phone)) return null;
        $digits = preg_replace('/[^0-9+]/', '', (string) $phone);
        return $digits ?: null;
    }

    /**
     * Buang non-digit, optional check exact-length.
     */
    private function normalizeDigits($value, ?int $exactLength = null): ?string
    {
        if (empty($value)) return null;
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') return null;
        if ($exactLength !== null && strlen($digits) !== $exactLength) {
            return $digits; // kembalikan as-is untuk caller validate
        }
        return $digits;
    }

    private function normalizeEnum($value, array $allowed): ?string
    {
        if (empty($value)) return null;
        $u = strtoupper((string) $value);
        return in_array($u, $allowed, true) ? $u : null;
    }

    private function cleanUrl($url): ?string
    {
        if (empty($url)) return null;
        $u = trim((string) $url);
        if (! preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        return filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
    }

    /**
     * Rate-limit per IP per hour. Pakai cache file (default driver di CI4).
     * @throws \RuntimeException kalau melewati limit
     */
    private function enforceRateLimit(string $ip): void
    {
        $cache = \Config\Services::cache();
        $key = 'career_apply_' . md5($ip);
        $count = (int) ($cache->get($key) ?? 0);
        if ($count >= self::RATE_LIMIT_PER_IP_PER_HOUR) {
            throw new \RuntimeException(
                'Terlalu banyak lamaran dari IP yang sama. Silakan tunggu 1 jam dan coba lagi.'
            );
        }
        $cache->save($key, $count + 1, 3600);
    }

    private function notifyHrNewApplicant(int $companyId, int $jobId, int $candidateId, string $fullName, string $jobTitle): void
    {
        $db = \Config\Database::connect();
        // Cari hiring_manager_id; fallback ke user HR (role 2) di company tsb
        $job = $db->table('job_postings')->where('id', $jobId)->get()->getRowArray();
        $targetUserIds = [];
        if (! empty($job['hiring_manager_id'])) {
            $targetUserIds[] = (int) $job['hiring_manager_id'];
        }
        // Tambah HR utama
        $hr = $db->table('users')
            ->where('company_id', $companyId)
            ->where('role_id', 2)
            ->where('is_active', 1)
            ->orderBy('id', 'ASC')
            ->limit(3)
            ->get()->getResultArray();
        foreach ($hr as $u) $targetUserIds[] = (int) $u['id'];
        $targetUserIds = array_unique(array_filter($targetUserIds));

        foreach ($targetUserIds as $uid) {
            try {
                Services::notification()->send(
                    $uid,
                    $companyId,
                    'recruitment.new_applicant',
                    'Pelamar baru via Career Portal',
                    "{$fullName} melamar untuk {$jobTitle}",
                    ['candidate_id' => $candidateId, 'job_id' => $jobId],
                    null,
                    true,
                );
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
}