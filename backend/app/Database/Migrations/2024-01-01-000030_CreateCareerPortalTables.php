<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Career Portal — public-facing job board yang terhubung ke pipeline
 * Recruitment & Onboarding existing.
 *
 * Yang dibuat:
 *   1. Tabel `career_pages` — branding & landing per perusahaan
 *      (slug, hero, about, color, kontak, sosial media).
 *   2. Tabel `application_track_tokens` — pelamar bisa cek status
 *      tanpa register, pakai email + token random.
 *   3. Extend `companies` dengan `career_slug` (URL public).
 *   4. Extend `job_postings` dengan field publish + filter (kota,
 *      arrangement WFO/WFH/HYBRID, tags JSON, requirement experience,
 *      jenjang pendidikan, application form).
 *   5. Extend `candidates` dengan field standar HR Indonesia
 *      (NIK / KTP, NPWP, BPJS, KK, agama, status nikah,
 *      kewarganegaraan, pendidikan terakhir, link sosmed,
 *      flag `applied_via_career`, jawaban form tambahan).
 *   6. Tambah permission baru `careers.manage` untuk pengaturan
 *      landing page karier (auto-grant ke role 1, 2).
 */
class CreateCareerPortalTables extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. career_pages (branding per company) ─────────────────
        if (! $db->tableExists('career_pages')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1,
                    'comment' => 'Aktifkan portal karier'],
                'headline'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Tagline besar di hero, mis. "Bangun Karier Bersama Kami"'],
                'about_html'      => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Profil singkat perusahaan untuk pelamar (HTML allowed terbatas)'],
                'benefits_html'   => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Benefit & culture (HTML)'],
                'hero_image_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'logo_path'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Override logo perusahaan untuk tampilan public'],
                'brand_color'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => '#0F62FE',
                    'comment' => 'Warna utama tombol & aksen (HEX)'],
                'contact_email'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Email recruiter untuk pertanyaan kandidat'],
                'contact_phone'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'instagram_url'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'linkedin_url'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'website_url'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'tiktok_url'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],

                // Form additional questions (JSON), mis. portfolio link,
                // expected start date, dst.
                'extra_questions' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Pertanyaan tambahan default untuk form apply [{key,label,type,required}]'],

                // Pengumuman / notice di top page (mis. "Buka 5 lowongan")
                'announcement'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('company_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('career_pages');
        }

        // ─── 2. application_track_tokens ────────────────────────────
        if (! $db->tableExists('application_track_tokens')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'candidate_id' => ['type' => 'INT', 'unsigned' => true],
                'token'        => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Random 32-byte hex; dikirim ke email pelamar'],
                'expires_at'   => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Default 90 hari dari create'],
                'last_used_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('token');
            $forge->addKey(['company_id', 'candidate_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('application_track_tokens');
        }

        // ─── 3. Extend companies dengan career_slug ────────────────
        if ($db->tableExists('companies')) {
            $cols = $db->getFieldNames('companies');
            $add = [];
            if (! in_array('career_slug', $cols, true)) {
                $add['career_slug'] = [
                    'type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Slug URL public mis. "pt-quantara"',
                ];
            }
            if ($add) {
                $forge->addColumn('companies', $add);
                // Tambah unique index supaya slug tidak bentrok antar tenant
                try {
                    $db->query('ALTER TABLE `companies` ADD UNIQUE KEY `companies_career_slug_unique` (`career_slug`)');
                } catch (\Throwable $e) {
                    log_message('warning', 'Could not add unique index on companies.career_slug: ' . $e->getMessage());
                }
            }
        }

        // ─── 4. Extend job_postings dengan field publish & filter ──
        if ($db->tableExists('job_postings')) {
            $cols = $db->getFieldNames('job_postings');
            $add = [];
            if (! in_array('is_published_to_career', $cols, true)) {
                $add['is_published_to_career'] = [
                    'type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => 'Tampilkan di public career portal',
                ];
            }
            if (! in_array('career_published_at', $cols, true)) {
                $add['career_published_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! in_array('city', $cols, true)) {
                $add['city'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Kota penempatan (bisa beda dari branch)'];
            }
            if (! in_array('work_arrangement', $cols, true)) {
                $add['work_arrangement'] = [
                    'type' => 'ENUM',
                    'constraint' => ['WFO','WFH','HYBRID'],
                    'default' => 'WFO',
                    'comment' => 'Pengaturan kerja',
                ];
            }
            if (! in_array('min_education', $cols, true)) {
                $add['min_education'] = [
                    'type' => 'VARCHAR', 'constraint' => 16, 'null' => true,
                    'comment' => 'SMA/SMK/D3/S1/S2/S3 minimal',
                ];
            }
            if (! in_array('min_experience_years', $cols, true)) {
                $add['min_experience_years'] = ['type' => 'TINYINT', 'unsigned' => true, 'null' => true];
            }
            if (! in_array('tags', $cols, true)) {
                $add['tags'] = ['type' => 'JSON', 'null' => true,
                    'comment' => 'Tags untuk filter di portal, mis. ["Engineering","Remote-Friendly"]'];
            }
            if (! in_array('benefits', $cols, true)) {
                $add['benefits'] = ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Benefit ringkas yang tampil di public job detail'];
            }
            if (! in_array('apply_form_schema', $cols, true)) {
                $add['apply_form_schema'] = ['type' => 'JSON', 'null' => true,
                    'comment' => 'Override pertanyaan tambahan per posting (kalau null pakai dari career_pages)'];
            }
            if (! in_array('public_slug', $cols, true)) {
                $add['public_slug'] = ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true,
                    'comment' => 'Slug URL detail job, mis. "senior-backend-engineer-2024"'];
            }
            if ($add) {
                $forge->addColumn('job_postings', $add);
            }
            // Index untuk query public listing (status + published)
            try {
                $db->query('CREATE INDEX `idx_jobs_career_public` ON `job_postings` (`company_id`, `is_published_to_career`, `status`)');
            } catch (\Throwable $e) {
                // Already exists probably — ignore
            }
        }

        // ─── 5. Extend candidates dengan field standar HR Indonesia ──
        if ($db->tableExists('candidates')) {
            $cols = $db->getFieldNames('candidates');
            $add = [];
            // Identitas Indonesia
            if (! in_array('nik', $cols, true)) {
                $add['nik'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                    'comment' => 'NIK KTP (16 digit)'];
            }
            if (! in_array('npwp', $cols, true)) {
                $add['npwp'] = ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'NPWP (15-16 digit, format atau tanpa)'];
            }
            if (! in_array('kk_number', $cols, true)) {
                $add['kk_number'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                    'comment' => 'No KK (16 digit)'];
            }
            if (! in_array('bpjs_kesehatan_no', $cols, true)) {
                $add['bpjs_kesehatan_no'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true];
            }
            if (! in_array('bpjs_tk_no', $cols, true)) {
                $add['bpjs_tk_no'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true];
            }
            if (! in_array('religion', $cols, true)) {
                $add['religion'] = ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'ISLAM/KRISTEN/KATOLIK/HINDU/BUDHA/KONGHUCU/LAINNYA'];
            }
            if (! in_array('marital_status', $cols, true)) {
                $add['marital_status'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true,
                    'comment' => 'BELUM_KAWIN/KAWIN/CERAI_HIDUP/CERAI_MATI'];
            }
            if (! in_array('nationality', $cols, true)) {
                $add['nationality'] = ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'WNI'];
            }
            if (! in_array('birth_place', $cols, true)) {
                $add['birth_place'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Tempat lahir (sesuai KTP)'];
            }
            if (! in_array('city', $cols, true)) {
                $add['city'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Kota domisili saat ini'];
            }
            if (! in_array('province', $cols, true)) {
                $add['province'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
            }
            // Pendidikan terakhir
            if (! in_array('last_education_level', $cols, true)) {
                $add['last_education_level'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true,
                    'comment' => 'SMA/SMK/D1/D2/D3/D4/S1/S2/S3'];
            }
            if (! in_array('last_education_institution', $cols, true)) {
                $add['last_education_institution'] = ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true];
            }
            if (! in_array('last_education_major', $cols, true)) {
                $add['last_education_major'] = ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true];
            }
            if (! in_array('last_education_year', $cols, true)) {
                $add['last_education_year'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
            }
            if (! in_array('gpa', $cols, true)) {
                $add['gpa'] = ['type' => 'DECIMAL', 'constraint' => '4,2', 'null' => true,
                    'comment' => 'IPK 0.00-4.00'];
            }
            // Sosmed & portfolio
            if (! in_array('linkedin_url', $cols, true)) {
                $add['linkedin_url'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
            }
            if (! in_array('portfolio_url', $cols, true)) {
                $add['portfolio_url'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
            }
            // Career portal flags
            if (! in_array('applied_via_career', $cols, true)) {
                $add['applied_via_career'] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => 'Apakah melamar via public career portal'];
            }
            if (! in_array('applied_at', $cols, true)) {
                $add['applied_at'] = ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Timestamp submission dari career portal'];
            }
            if (! in_array('applicant_ip', $cols, true)) {
                $add['applicant_ip'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Untuk anti-spam / abuse tracking'];
            }
            if (! in_array('cover_letter', $cols, true)) {
                $add['cover_letter'] = ['type' => 'TEXT', 'null' => true];
            }
            if (! in_array('extra_answers', $cols, true)) {
                $add['extra_answers'] = ['type' => 'JSON', 'null' => true,
                    'comment' => 'Jawaban pertanyaan tambahan dari form'];
            }
            // Quick access path (lebih praktis dibanding harus join candidate_documents)
            if (! in_array('cv_path', $cols, true)) {
                $add['cv_path'] = ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true];
            }
            if (! in_array('photo_path', $cols, true)) {
                $add['photo_path'] = ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true];
            }
            if (! in_array('ktp_path', $cols, true)) {
                $add['ktp_path'] = ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true];
            }
            if (! in_array('ijazah_path', $cols, true)) {
                $add['ijazah_path'] = ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true];
            }
            if ($add) {
                $forge->addColumn('candidates', $add);
            }
            // Index NIK supaya HR mudah cari kandidat duplikat lintas posting
            try {
                $db->query('CREATE INDEX `idx_candidates_nik` ON `candidates` (`company_id`, `nik`)');
            } catch (\Throwable $e) {
                // ignore if exists
            }
        }

        // ─── 6. Permission ─────────────────────────────────────────
        $now = date('Y-m-d H:i:s');
        $newPermissions = [
            ['slug' => 'careers.manage', 'name' => 'Manage Career Portal',
                'group' => 'recruitment'],
        ];
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Auto-grant ke role 1 (super) & 2 (HR admin)
        foreach ([1, 2] as $roleId) {
            $perm = $db->table('permissions')->where('slug', 'careers.manage')->get()->getRowArray();
            if (! $perm) continue;
            $exists = $db->table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', (int) $perm['id'])
                ->countAllResults();
            if ($exists > 0) continue;
            $db->table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => (int) $perm['id'],
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if ($db->tableExists('application_track_tokens')) {
            $this->forge->dropTable('application_track_tokens', true);
        }
        if ($db->tableExists('career_pages')) {
            $this->forge->dropTable('career_pages', true);
        }
        // Drop kolom-kolom yang ditambah
        $dropCandidate = [
            'nik','npwp','kk_number','bpjs_kesehatan_no','bpjs_tk_no',
            'religion','marital_status','nationality','birth_place','city','province',
            'last_education_level','last_education_institution','last_education_major',
            'last_education_year','gpa','linkedin_url','portfolio_url',
            'applied_via_career','applied_at','applicant_ip','cover_letter',
            'extra_answers','cv_path','photo_path','ktp_path','ijazah_path',
        ];
        if ($db->tableExists('candidates')) {
            $cols = $db->getFieldNames('candidates');
            foreach ($dropCandidate as $c) {
                if (in_array($c, $cols, true)) {
                    try { $this->forge->dropColumn('candidates', $c); } catch (\Throwable $e) {}
                }
            }
        }
        $dropJobs = [
            'is_published_to_career','career_published_at','city','work_arrangement',
            'min_education','min_experience_years','tags','benefits',
            'apply_form_schema','public_slug',
        ];
        if ($db->tableExists('job_postings')) {
            $cols = $db->getFieldNames('job_postings');
            foreach ($dropJobs as $c) {
                if (in_array($c, $cols, true)) {
                    try { $this->forge->dropColumn('job_postings', $c); } catch (\Throwable $e) {}
                }
            }
        }
        if ($db->tableExists('companies')) {
            $cols = $db->getFieldNames('companies');
            if (in_array('career_slug', $cols, true)) {
                try { $this->forge->dropColumn('companies', 'career_slug'); } catch (\Throwable $e) {}
            }
        }
        // Permission cleanup
        $perm = $db->table('permissions')->where('slug', 'careers.manage')->get()->getRowArray();
        if ($perm) {
            $db->table('role_permissions')->where('permission_id', (int) $perm['id'])->delete();
            $db->table('permissions')->where('id', (int) $perm['id'])->delete();
        }
    }
}