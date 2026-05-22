<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Services;

/**
 * CareerPortalSeeder — sample data untuk demo Career Portal.
 *
 *  - Set company.career_slug (kalau kosong)
 *  - Buat / update career_pages dengan branding default
 *  - Buat 2 sample job posting status OPEN + dipublish ke career portal
 *
 * Idempotent: aman dijalankan ulang. Tidak menyentuh data existing kalau
 * sudah ada.
 */
class CareerPortalSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;
        $svc = Services::career();
        $now = date('Y-m-d H:i:s');

        $companies = $db->table('companies')->where('is_active', 1)->where('deleted_at IS NULL')->get()->getResultArray();
        if (empty($companies)) {
            echo "  [skip] tidak ada company aktif\n";
            return;
        }

        foreach ($companies as $company) {
            $cid = (int) $company['id'];
            // 1. Set career_slug kalau belum ada
            if (empty($company['career_slug'])) {
                $slug = $svc->slugifyCompany((string) $company['name'], $cid);
                $db->table('companies')->where('id', $cid)->update(['career_slug' => $slug]);
                echo "  [set]  company #{$cid} career_slug = {$slug}\n";
                $company['career_slug'] = $slug;
            }

            // 2. Setup career_pages dengan branding
            $existing = $db->table('career_pages')->where('company_id', $cid)->get()->getRowArray();
            $page = [
                'company_id'    => $cid,
                'is_active'     => 1,
                'headline'      => 'Bangun Karier Bersama Kami',
                'about_html'    => '<p>Kami adalah perusahaan yang berkomitmen mengembangkan talenta '
                    . 'terbaik Indonesia. Bergabunglah dengan tim kami untuk berkarier di lingkungan kerja '
                    . 'yang kolaboratif, inklusif, dan terus berkembang.</p>',
                'benefits_html' => '<ul>'
                    . '<li>BPJS Kesehatan & Ketenagakerjaan</li>'
                    . '<li>THR & bonus tahunan</li>'
                    . '<li>Cuti tahunan 12 hari + cuti bersama</li>'
                    . '<li>Reimbursement medis & tunjangan transport</li>'
                    . '<li>Pelatihan & sertifikasi</li>'
                    . '<li>Kesempatan pengembangan karier</li>'
                    . '</ul>',
                'brand_color'   => '#0F62FE',
                'announcement'  => 'Kami sedang membuka beberapa posisi baru. Yuk daftar!',
                'extra_questions' => json_encode([
                    [
                        'key' => 'why_us',
                        'label' => 'Mengapa Anda tertarik bergabung dengan kami?',
                        'type' => 'textarea',
                        'required' => false,
                    ],
                    [
                        'key' => 'available_overtime',
                        'label' => 'Bersedia lembur jika dibutuhkan?',
                        'type' => 'select',
                        'options' => ['Ya, fleksibel', 'Hanya weekday', 'Tidak'],
                        'required' => false,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'updated_at'    => $now,
            ];
            if ($existing) {
                $db->table('career_pages')->where('id', (int) $existing['id'])->update($page);
                echo "  [upd]  career_page company #{$cid}\n";
            } else {
                $page['created_at'] = $now;
                $db->table('career_pages')->insert($page);
                echo "  [new]  career_page company #{$cid}\n";
            }

            // 3. Sample 2 job posting yang dipublish (kalau belum ada)
            $sampleJobs = [
                [
                    'title' => 'Software Engineer',
                    'description' => "<p>Kami mencari Software Engineer berbakat untuk membangun produk web modern menggunakan stack PHP/Vue/MySQL.</p>"
                        . "<h4>Tanggung jawab:</h4>"
                        . "<ul><li>Mendesain & mengembangkan fitur backend dan frontend</li>"
                        . "<li>Code review dan mentoring junior</li>"
                        . "<li>Kolaborasi dengan tim Product, Design, QA</li></ul>",
                    'requirements' => "<ul>"
                        . "<li>Min S1 Teknik Informatika / setara</li>"
                        . "<li>Pengalaman 2+ tahun PHP/Vue.js</li>"
                        . "<li>Familiar dengan REST API, Git, MySQL</li>"
                        . "<li>Komunikasi baik dalam Bahasa Indonesia & Inggris</li>"
                        . "</ul>",
                    'employment_type' => 'PKWTT',
                    'salary_min' => 8000000,
                    'salary_max' => 15000000,
                    'salary_visible' => 1,
                    'headcount' => 2,
                    'city' => 'Jakarta',
                    'work_arrangement' => 'HYBRID',
                    'min_education' => 'S1',
                    'min_experience_years' => 2,
                    'tags' => ['Engineering','Hybrid','Full-time'],
                    'benefits' => 'BPJS, THR, bonus, training, fleksibel WFH 2 hari/minggu',
                ],
                [
                    'title' => 'HR Generalist',
                    'description' => "<p>Bergabung dengan tim People & Culture kami sebagai HR Generalist. "
                        . "Anda akan menangani end-to-end employee lifecycle: rekrutmen, onboarding, "
                        . "payroll administration, employee relations, hingga offboarding.</p>",
                    'requirements' => "<ul>"
                        . "<li>Min S1 Psikologi / Manajemen SDM</li>"
                        . "<li>Pengalaman 1-3 tahun di HR Generalist / HR Operations</li>"
                        . "<li>Familiar dengan UU Ketenagakerjaan & PPh21</li>"
                        . "<li>Detail-oriented, empati tinggi</li>"
                        . "</ul>",
                    'employment_type' => 'PKWTT',
                    'salary_min' => 6000000,
                    'salary_max' => 9000000,
                    'salary_visible' => 1,
                    'headcount' => 1,
                    'city' => 'Jakarta',
                    'work_arrangement' => 'WFO',
                    'min_education' => 'S1',
                    'min_experience_years' => 1,
                    'tags' => ['HR','People','Full-time'],
                    'benefits' => 'BPJS, THR, makan siang, training profesional HR',
                ],
            ];

            $svcRecruit = Services::recruitment();
            foreach ($sampleJobs as $sample) {
                $exists = $db->table('job_postings')
                    ->where('company_id', $cid)
                    ->where('title', $sample['title'])
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
                if ($exists > 0) {
                    echo "  [skip] job '{$sample['title']}' sudah ada\n";
                    continue;
                }
                $ref = $svcRecruit->nextJobReferenceNumber($cid);
                $publicSlug = $svc->slugifyJob($sample['title'], $ref, $cid);
                $insert = array_merge($sample, [
                    'company_id'      => $cid,
                    'reference_number'=> $ref,
                    'public_slug'     => $publicSlug,
                    'status'          => 'OPEN',
                    'opened_at'       => date('Y-m-d'),
                    'is_published_to_career' => 1,
                    'career_published_at' => $now,
                    'tags'            => json_encode($sample['tags'], JSON_UNESCAPED_UNICODE),
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $db->table('job_postings')->insert($insert);
                $jobId = (int) $db->insertID();
                echo "  [new]  job_posting #{$jobId} {$ref} '{$sample['title']}' (slug: {$publicSlug})\n";
            }

            $publicUrl = '/careers/' . $company['career_slug'];
            echo "\n  ✓ Career portal company #{$cid} ready: {$publicUrl}\n";
        }
    }
}