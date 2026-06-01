<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * CareerController — admin endpoint untuk pengaturan Career Portal.
 *
 * Authorization:
 *   - `careers.manage` (auto-grant role 1, 2)
 *   - `recruitment.manage` boleh publish/unpublish job
 *
 * Endpoint:
 *   GET    /career-page                  — settings career page (per current company)
 *   PUT    /career-page                  — update settings
 *   POST   /career-page/regenerate-slug  — regenerate company slug
 *   GET    /career-page/preview-url      — return URL untuk preview public portal
 *   POST   /job-postings/(:num)/publish  — publish ke career portal
 *   POST   /job-postings/(:num)/unpublish — unpublish
 *   GET    /career-applicants            — list pelamar yang masuk via career
 */
class CareerController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    /**
     * GET /career-page — settings career page untuk current company.
     * Otomatis generate slug perusahaan kalau belum ada.
     */
    public function show()
    {
        $companyId = $this->companyId();
        if ($companyId === 0) return $this->fail('Company context required', 400);

        $db = \Config\Database::connect();
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $company) return $this->fail('Company not found', 404);

        // Auto-generate slug kalau belum ada
        if (empty($company['career_slug'])) {
            $newSlug = Services::career()->slugifyCompany((string) $company['name'], $companyId);
            $db->table('companies')->where('id', $companyId)->update(['career_slug' => $newSlug]);
            $company['career_slug'] = $newSlug;
        }

        $page = Services::career()->getOrCreateCareerPage($companyId);
        // Decode JSON
        if (! empty($page['extra_questions']) && is_string($page['extra_questions'])) {
            $page['extra_questions'] = json_decode($page['extra_questions'], true) ?: [];
        }

        // Stats untuk dashboard pengaturan
        $stats = [
            'published_jobs' => (int) $db->table('job_postings')
                ->where('company_id', $companyId)
                ->where('is_published_to_career', 1)
                ->where('status', 'OPEN')
                ->where('deleted_at IS NULL')
                ->countAllResults(),
            'total_applicants_via_career' => (int) $db->table('candidates')
                ->where('company_id', $companyId)
                ->where('applied_via_career', 1)
                ->where('deleted_at IS NULL')
                ->countAllResults(),
            'applicants_last_30d' => (int) $db->table('candidates')
                ->where('company_id', $companyId)
                ->where('applied_via_career', 1)
                ->where('applied_at >=', date('Y-m-d', strtotime('-30 days')))
                ->where('deleted_at IS NULL')
                ->countAllResults(),
        ];

        return $this->ok([
            'company' => [
                'id'          => (int) $company['id'],
                'name'        => $company['name'],
                'career_slug' => $company['career_slug'],
                'logo_path'   => $company['logo_path'] ?? null,
            ],
            'page' => $page,
            'stats' => $stats,
            'public_url' => '/careers/' . $company['career_slug'],
        ]);
    }

    /**
     * PUT /career-page — update settings.
     */
    public function update()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if ($companyId === 0) return $this->fail('Company context required', 400);

        $data = $this->getJsonInput();
        $db = \Config\Database::connect();

        // Update career_slug di companies (kalau diubah)
        if (isset($data['career_slug']) && is_string($data['career_slug'])) {
            $newSlug = strtolower(trim($data['career_slug']));
            // Validasi slug
            if ($newSlug !== '' && ! preg_match('/^[a-z0-9][a-z0-9-]{2,99}$/', $newSlug)) {
                return $this->fail(
                    'Slug hanya boleh huruf kecil, angka, dan dash. Min 3 karakter, awali dengan huruf/angka.',
                    422,
                );
            }
            if ($newSlug !== '') {
                $exists = $db->table('companies')
                    ->where('career_slug', $newSlug)
                    ->where('id !=', $companyId)
                    ->countAllResults();
                if ($exists > 0) return $this->fail('Slug ini sudah dipakai perusahaan lain', 422);
                $db->table('companies')->where('id', $companyId)->update(['career_slug' => $newSlug]);
            }
        }

        // Update career_pages
        $page = Services::career()->getOrCreateCareerPage($companyId);

        $update = array_intersect_key($data, array_flip([
            'is_active','headline','about_html','benefits_html',
            'hero_image_path','logo_path','brand_color',
            'contact_email','contact_phone',
            'instagram_url','linkedin_url','website_url','tiktok_url',
            'announcement',
        ]));
        if (isset($update['is_active'])) $update['is_active'] = (int) (bool) $update['is_active'];
        if (isset($update['brand_color'])) {
            $bc = trim((string) $update['brand_color']);
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $bc)) {
                return $this->fail('Brand color harus format HEX (mis. #0F62FE)', 422);
            }
            $update['brand_color'] = strtoupper($bc);
        }
        // Sanitize HTML — basic strip script tags
        foreach (['about_html','benefits_html'] as $h) {
            if (isset($update[$h])) {
                $update[$h] = $this->sanitizeHtml((string) $update[$h]);
            }
        }

        // Extra questions JSON
        if (array_key_exists('extra_questions', $data)) {
            $eq = $data['extra_questions'];
            if (is_array($eq)) {
                // Validasi tiap item: butuh key, label, type
                $valid = [];
                foreach ($eq as $q) {
                    if (! is_array($q)) continue;
                    $key = trim((string) ($q['key'] ?? ''));
                    $label = trim((string) ($q['label'] ?? ''));
                    $type = (string) ($q['type'] ?? 'text');
                    if ($key === '' || $label === '') continue;
                    if (! in_array($type, ['text','textarea','number','date','select','checkbox','url'], true)) {
                        $type = 'text';
                    }
                    $entry = [
                        'key'      => preg_replace('/[^a-z0-9_]/i', '_', strtolower($key)),
                        'label'    => mb_substr($label, 0, 200),
                        'type'     => $type,
                        'required' => ! empty($q['required']),
                    ];
                    if ($type === 'select' && isset($q['options']) && is_array($q['options'])) {
                        $entry['options'] = array_values(array_filter(array_map('strval', $q['options'])));
                    }
                    $valid[] = $entry;
                }
                $update['extra_questions'] = json_encode($valid, JSON_UNESCAPED_UNICODE);
            } elseif ($eq === null) {
                $update['extra_questions'] = null;
            }
        }

        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('career_pages')->where('id', (int) $page['id'])->update($update);

        Services::audit()->forModule('CAREER')->logUpdate(
            'career_page',
            (int) $page['id'],
            $page,
            $update,
            'Update pengaturan Career Portal',
        );

        return $this->ok(null, 'Career page updated');
    }

    /**
     * POST /career-page/regenerate-slug — regen slug company dari nama.
     */
    public function regenerateSlug()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $companyId = $this->companyId();
        if ($companyId === 0) return $this->fail('Company context required', 400);

        $db = \Config\Database::connect();
        $company = $db->table('companies')->where('id', $companyId)->get()->getRowArray();
        if (! $company) return $this->fail('Company not found', 404);

        $newSlug = Services::career()->slugifyCompany((string) $company['name'], $companyId);
        $db->table('companies')->where('id', $companyId)->update(['career_slug' => $newSlug]);

        Services::audit()->forModule('CAREER')->log(
            action: 'career.regen_slug',
            entity: 'company',
            entityId: $companyId,
            after: ['career_slug' => $newSlug],
            description: "Regen career slug → {$newSlug}",
            severity: 'NOTICE',
        );

        return $this->ok(['career_slug' => $newSlug, 'public_url' => '/careers/' . $newSlug]);
    }

    /**
     * POST /job-postings/{id}/publish — toggle ke career portal.
     */
    public function publishJob($id = null)
    {
        if (! $this->canManageJob()) return $this->fail('Forbidden', 403);
        $jobId = (int) $id;
        $job = $this->loadJobOrFail($jobId);
        if (! $job) return $this->response;

        if ($job['status'] !== 'OPEN') {
            return $this->fail('Hanya posting status OPEN yang bisa dipublish ke career portal. Buka dulu posting.', 422);
        }

        $db = \Config\Database::connect();
        // Generate slug kalau belum ada
        $update = [
            'is_published_to_career' => 1,
            'career_published_at'    => date('Y-m-d H:i:s'),
            'updated_by'             => $this->userId(),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];
        if (empty($job['public_slug'])) {
            $update['public_slug'] = Services::career()->slugifyJob(
                (string) $job['title'],
                (string) $job['reference_number'],
                (int) $job['company_id'],
                $jobId,
            );
        }
        $db->table('job_postings')->where('id', $jobId)->update($update);

        // Auto-generate company slug juga kalau belum ada
        $company = $db->table('companies')->where('id', (int) $job['company_id'])->get()->getRowArray();
        if (empty($company['career_slug'])) {
            $newCompanySlug = Services::career()->slugifyCompany(
                (string) $company['name'],
                (int) $company['id'],
            );
            $db->table('companies')->where('id', (int) $company['id'])->update(['career_slug' => $newCompanySlug]);
            $company['career_slug'] = $newCompanySlug;
        }

        Services::audit()->forModule('CAREER')->log(
            action: 'job.publish_career',
            entity: 'job_posting',
            entityId: $jobId,
            after: $update,
            description: "Publish posting {$job['reference_number']} ke career portal",
            severity: 'NOTICE',
        );

        $publicSlug = $update['public_slug'] ?? $job['public_slug'];

        // Sprint 15+ — webhook outbound `recruitment.posting_published`.
        // Berguna untuk integrasi ke job board external (LinkedIn, Glints,
        // Kalibrr) supaya posting auto-mirror.
        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::POSTING_PUBLISHED,
            (int) $job['company_id'],
            [
                'job_posting_id'   => $jobId,
                'reference_number' => $job['reference_number'] ?? null,
                'title'            => $job['title'] ?? null,
                'employment_type'  => $job['employment_type'] ?? null,
                'department_id'    => isset($job['department_id']) ? (int) $job['department_id'] : null,
                'position_id'      => isset($job['position_id']) ? (int) $job['position_id'] : null,
                'branch_id'        => isset($job['branch_id']) ? (int) $job['branch_id'] : null,
                'salary_min'       => isset($job['salary_min']) ? (float) $job['salary_min'] : null,
                'salary_max'       => isset($job['salary_max']) ? (float) $job['salary_max'] : null,
                'salary_visible'   => (bool) ($job['salary_visible'] ?? false),
                'public_url_path'  => '/careers/' . $company['career_slug'] . '/jobs/' . $publicSlug,
                'public_slug'      => $publicSlug,
                'company_slug'     => $company['career_slug'] ?? null,
                'published_by'     => $this->userId(),
                'published_at'     => date('c'),
            ],
        );

        return $this->ok([
            'is_published_to_career' => 1,
            'public_slug'            => $publicSlug,
            'public_url'             => '/careers/' . $company['career_slug']
                . '/jobs/' . $publicSlug,
        ], 'Posting dipublish ke career portal');
    }

    /**
     * POST /job-postings/{id}/unpublish
     */
    public function unpublishJob($id = null)
    {
        if (! $this->canManageJob()) return $this->fail('Forbidden', 403);
        $jobId = (int) $id;
        $job = $this->loadJobOrFail($jobId);
        if (! $job) return $this->response;

        $db = \Config\Database::connect();
        $db->table('job_postings')->where('id', $jobId)->update([
            'is_published_to_career' => 0,
            'updated_by' => $this->userId(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Services::audit()->forModule('CAREER')->log(
            action: 'job.unpublish_career',
            entity: 'job_posting',
            entityId: $jobId,
            description: "Unpublish posting {$job['reference_number']} dari career portal",
            severity: 'NOTICE',
        );

        return $this->ok(null, 'Posting di-unpublish dari career portal');
    }

    /**
     * GET /career-applicants — list pelamar yang masuk via career portal.
     * Filter: q, stage, job_id, days (default 30).
     */
    public function applicants()
    {
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || $tenant->hasPermission('recruitment.view'))) {
            return $this->fail('Forbidden', 403);
        }

        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $stage = strtoupper((string) ($this->request->getGet('stage') ?? ''));
        $jobId = (int) ($this->request->getGet('job_id') ?? 0);
        $days = (int) ($this->request->getGet('days') ?? 30);

        $b = $db->table('candidates c')
            ->select('c.id, c.full_name, c.email, c.phone, c.current_stage, c.applied_at,'
                . ' c.last_education_level, c.years_experience, c.expected_salary, c.city,'
                . ' c.cv_path, c.applicant_ip, c.rating,'
                . ' jp.title AS job_title, jp.reference_number AS job_ref')
            ->join('job_postings jp', 'jp.id = c.job_posting_id')
            ->where('c.applied_via_career', 1)
            ->where('c.deleted_at IS NULL');
        $tenant->applyScope($b, 'c.company_id');
        if ($q !== '') {
            $b->groupStart()
                ->like('c.full_name', $q)
                ->orLike('c.email', $q)
                ->orLike('c.current_position', $q)
                ->groupEnd();
        }
        if ($stage !== '') $b->where('c.current_stage', $stage);
        if ($jobId) $b->where('c.job_posting_id', $jobId);
        if ($days > 0) {
            $b->where('c.applied_at >=', date('Y-m-d 00:00:00', strtotime("-{$days} days")));
        }

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('c.applied_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('careers.manage');
    }

    private function canManageJob(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('careers.manage')
            || $t->hasPermission('recruitment.manage');
    }

    private function loadJobOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('job_postings')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Job posting not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    /**
     * Strip script/iframe/embed dan event handler. Cukup untuk konten
     * about_html / benefits_html yang user-controlled tapi terbatas.
     */
    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|embed|object|link|style|meta)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|embed|object|link|style|meta)\b[^>]*/?>#i', '', $html);
        // Strip on* event attributes
        $html = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html);
        // Strip javascript: protocol
        $html = preg_replace('/javascript\s*:/i', '', $html);
        return $html;
    }
}