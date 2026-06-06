# Career Portal — Public Job Board terhubung ke Pipeline ATS & Onboarding

Dokumen ini menjelaskan modul **Career Portal**: web karier publik per
perusahaan yang terhubung langsung ke `RecruitmentService` (ATS pipeline)
dan `OnboardingService` (employee onboarding). Disesuaikan dengan kondisi
HR Indonesia (NIK/KTP, NPWP, BPJS, KK, agama, status nikah, dll).

## Latar Belakang

Recruitment / ATS module sebelumnya hanya bisa diakses dari dalam (HR
yang input kandidat manual). Dengan Career Portal, perusahaan bisa:

1. Mempunyai halaman karier publik (mis. `/careers/pt-quantara`) tanpa
   build website terpisah.
2. Pelamar apply lewat form yang sudah disesuaikan untuk HR Indonesia
   — NIK, NPWP, BPJS, agama, status nikah, pendidikan terakhir, IPK,
   dst. Data ini akan langsung ter-carry-over ke data employee saat
   diterima, jadi onboarding cepat.
3. Pelamar bisa cek status lamaran via token (tanpa register) sehingga
   recruiter tidak banjir email "lamaran saya gimana?"
4. Ketika HR klik **Hire** di pipeline kandidat:
   - Data Indonesia (NIK→ktp_number, NPWP, BPJS, KK, agama, dst)
     ter-copy ke row `employees`.
   - Dokumen (CV, KTP, ijazah, foto) ter-copy dari `candidate_documents`
     ke `employee_documents`.
   - Onboarding 12 task otomatis ter-trigger via `applyTemplate` (sudah
     ada di module Onboarding existing).

## Arsitektur Singkat

```
[Pelamar publik]
    │
    │ 1. Browse → /careers/{company-slug}
    │ 2. Apply  → POST /careers/{slug}/jobs/{job_slug}/apply
    │ 3. Track  → GET  /careers/track/{token}
    ▼
[CareerPublicController]  ←── PUBLIC, no JWT ──→  [CareerService]
    │                                                  │
    │ submit application                               │
    ▼                                                  ▼
[candidates] (stage NEW)  ──────────►  [candidate_stages]  +  [candidate_documents]
    │                                                  +  [application_track_tokens]
    │                                                  +  notif HR
    │
    │ HR review di /recruitment/jobs/{id}
    │ Move stage NEW → SCREENING → INTERVIEW → OFFER → HIRED
    ▼
[RecruitmentService::convertToEmployee]
    │
    ├─► [employees]  (carry-over NIK, NPWP, BPJS, agama, dst)
    ├─► [employee_documents]  (copy dokumen kandidat)
    └─► [employee_tasks] via OnboardingService::applyTemplate
                ─────────► 12 onboarding tasks otomatis
```

## Apa yang Berubah

### Database (migration `2024-01-01-000030_CreateCareerPortalTables`)

**2 tabel baru:**

| Tabel | Fungsi |
|---|---|
| `career_pages` | Branding & landing page per company: headline, about HTML, benefits HTML, brand color, kontak, sosmed, extra questions form |
| `application_track_tokens` | Token random hex untuk pelamar cek status (TTL 90 hari) |

**Extend tabel existing:**

| Tabel | Field baru |
|---|---|
| `companies` | `career_slug` (URL public unik) |
| `job_postings` | `is_published_to_career`, `career_published_at`, `city`, `work_arrangement` (WFO/WFH/HYBRID), `min_education`, `min_experience_years`, `tags` (JSON), `benefits`, `apply_form_schema`, `public_slug` |
| `candidates` | NIK, NPWP, KK, BPJS Kesehatan/TK, agama, status nikah, kewarganegaraan, tempat lahir, kota, provinsi, pendidikan (level, institusi, jurusan, tahun, IPK), LinkedIn, portfolio, applied_via_career, applied_at, applicant_ip, cover_letter, extra_answers (JSON), cv_path, photo_path, ktp_path, ijazah_path |

**Permission baru:**
- `careers.manage` (auto-grant role 1 super, role 2 HR admin)

### Backend

#### `CareerService` (`Services::career()`)

| Method | Output / Fungsi |
|---|---|
| `slugifyCompany($name, $excludeId?)` | Generate slug unik untuk company (mis. "PT Quantara HR" → `pt-quantara-hr`) |
| `slugifyJob($title, $ref, $companyId, $excludeId?)` | Slug per posting (mis. `software-engineer-job202600002`) |
| `getOrCreateCareerPage($companyId)` | Idempoten — auto-create row branding default kalau belum ada |
| `getPublicLanding($slug)` | Resolve company by slug → return `{company, page, jobs, stats}` |
| `getPublishedJobs($companyId, $filters)` | Filter: q, city, employment_type, work_arrangement |
| `getPublicJobDetail($companyId, $jobSlug)` | Detail untuk public page (sembunyikan field internal) |
| `submitApplication($companyId, $jobId, $data, $ip)` | Atomic: insert candidate + stage NEW + dokumen + token → notify HR |
| `createTrackToken($candId, $coId)` | Random hex 64 char, expiry 90 hari |
| `resolveTrackToken($token)` | Return `{candidate, job, stages}` (masking sensitive fields di controller) |
| `educationMeetsMinimum($applicant, $required)` | Compare jenjang pendidikan |

**Validasi yang diterapkan saat submit:**
- Nama lengkap, email, phone, CV wajib
- Email format valid
- NIK 16 digit (kalau diisi)
- KK 16 digit (kalau diisi)
- Pendidikan minimum sesuai posting
- Dedup: email + job_posting_id sudah ada → tolak
- Rate-limit per IP per jam (5 lamaran)

#### `CareerPublicController` (PUBLIC, no JWT)

Endpoint:

| Method | Path | Fungsi |
|---|---|---|
| GET    | `/careers/{slug}`                         | Landing data (company, page, jobs, stats) |
| GET    | `/careers/{slug}/jobs`                    | List jobs + filter |
| GET    | `/careers/{slug}/jobs/{jobSlug}`          | Detail job |
| POST   | `/careers/{slug}/jobs/{jobSlug}/apply`    | Submit lamaran |
| POST   | `/careers/upload?kind=cv\|ktp\|ijazah\|photo` | Upload dokumen (5 MB, MIME ketat) |
| GET    | `/careers/track/{token}`                  | Cek status lamaran |

Response disensor: email di-mask (`bu**@example.com`), NIK di-mask
(`************1234`), feedback recruiter hanya tampil kalau stage final.

#### `CareerController` (admin, auth + permission)

| Method | Path | Permission | Fungsi |
|---|---|---|---|
| GET    | `/career-page`                  | (anyone in company) | Settings + stats |
| PUT    | `/career-page`                  | `careers.manage` | Update branding, slug, sosmed, extra questions |
| POST   | `/career-page/regenerate-slug`  | `careers.manage` | Regen slug dari nama company |
| POST   | `/job-postings/{id}/publish`    | `careers.manage` / `recruitment.manage` | Publish posting OPEN ke career portal |
| POST   | `/job-postings/{id}/unpublish`  | `careers.manage` / `recruitment.manage` | Unpublish |
| GET    | `/career-applicants`            | `recruitment.view` | List pelamar via career |

#### Extension `RecruitmentService::convertToEmployee`

Saat HR klik **Hire**, service sekarang:

1. **Carry-over data Indonesia** ke `employees` (kalau kolom ada di
   schema):
   - `birth_place` / `place_of_birth`
   - `religion`, `marital_status` (di-mapping ke kode PTKP: BELUM_KAWIN
     → TK0, KAWIN → K0, dst.)
   - `nationality`, `nik` / `ktp_number`, `npwp`, `kk_number`
   - `bpjs_kesehatan_no`, `bpjs_tk_no`
   - `city`, `province`
   - Pendidikan: `last_education_*`, `gpa`
2. **Copy dokumen** dari `candidate_documents` ke `employee_documents`
   via private method `copyCandidateDocsToEmployee()` (handle nama kolom
   beda: `doc_type` vs `document_type`, `file_mime` vs `mime_type`).
3. **Trigger ONBOARD_DEFAULT** seperti sebelumnya — 12 task otomatis.
4. **Auto-complete onboarding tasks** lewat
   `OnboardingService::autoCompleteFromCandidate()` — tasks yang datanya
   sudah lengkap dari Career Portal ditandai COMPLETED otomatis (lihat
   bagian berikut).

#### `OnboardingService::autoCompleteFromCandidate`

Method baru di `OnboardingService` yang dipanggil setelah
`applyTemplate()`. Mark tasks PENDING jadi COMPLETED bila datanya
sudah dikumpulkan saat lamaran. Ini menghemat waktu HR ~30-50% dari 12
tasks default.

| Task key | Kondisi auto-complete | Note |
|---|---|---|
| `SUBMIT_DOCS` | `employee_documents` ≥ 1 row (CV/KTP/ijazah/foto sudah ter-copy dari candidate) | Yang asli sudah upload public |
| `NPWP_VERIFY` | `candidate.npwp` ≥ 15 digit | Format NPWP lama 15 atau baru 16 (NIK-based) |
| `BPJS_REGISTER` | `candidate.bpjs_kesehatan_no` atau `bpjs_tk_no` terisi | Pelamar sudah punya BPJS dari kerja sebelumnya — HR tinggal transfer kepesertaan via SiPP & e-Dabu |
| `SETUP_HRIS` | `autoCreateUserAccount()` berhasil | Auto-create user account: insert ke `users` dengan password sementara, role 4 (employee), `must_change_password=1`, link `employee_id`. Audit log NOTICE saat issue credential |

#### Pengiriman credential ke karyawan baru

Saat akun HRIS auto-create berhasil, **dua jalur** kirim credential
diaktifkan secara paralel supaya HR fleksibel:

1. **Email otomatis** (primary) — `OnboardingService::queueWelcomeEmail`
   enqueue ke `email_queue` dengan subject "Selamat datang di [Company] —
   Akun HRIS Anda". Body HTML berisi:
   - Sapa nama karyawan
   - Email login + password sementara dalam box monospace
   - Tombol "Login ke HRIS" yang resolve dari env `app.frontendUrl`
     (fallback `app.baseURL`)
   - Warning kuning: "wajib ganti password saat login pertama"
   - Footer: instruksi kalau bukan kandidat yang melamar
   
   Email dikirim ke `candidate.email` (sama dengan email lamaran).
   Worker `email:process` (cron) yang actually send via SMTP. Retry
   policy: 30s → 2m → 10m → 1h → 6h, max 5x. Password TIDAK disimpan
   plain text di `email_queue.body_html` setelah email kirim — baris
   queue di-set `status='SENT'` tapi body masih ada untuk debugging.
   Best practice: jalankan cleanup `email:process --purge-after=7d`.

2. **Modal one-time view ke HR** (backup) — response endpoint
   `POST /candidates/{id}/hire` mengembalikan `temp_password` sekali ini
   saja. Frontend `PipelineBoard.vue` buka modal credential setelah hire
   sukses dengan:
   - Notice hijau: "Email otomatis dikirim ke [email]"
   - Notice kuning backup: "kalau email tidak sampai, kasih manual"
   - Email + password (font-mono, tombol copy individual)
   - Tombol "Salin semua" untuk paste ke WA/Telegram
   - Counter "X onboarding task auto-completed"

Kalau email gagal terkirim (SMTP belum dikonfig, network issue, dst),
HR masih punya credential di response — tinggal kirim manual via
WhatsApp / Lark / dll. Audit log mencatat `user.auto_create` tapi
**TIDAK menyimpan password plain text**.

Tasks **TIDAK** auto-complete (tetap PENDING — perlu HR/karyawan input):
- `WELCOME_MEET` (atasan langsung wajib bertemu fisik)
- `CONTRACT_SIGN` (HR generate dari template + tanda tangan)
- `EMPLOYEE_HANDBOOK` (karyawan baca sendiri)
- `SETUP_LAPTOP` (IT provisioning fisik)
- `BANK_ACCOUNT` (karyawan submit nomor rekening untuk payroll)
- `INDUCTION` (HR jadwalkan & jalankan)
- `TRAINING_ROLE` (supervisor define & deliver)
- `PROBATION_REVIEW` (3 bulan kemudian)

Hasil: dari 12 task default, **4 task auto-complete** kalau semua data
diisi pelamar di Career Portal. HR fokus pada 8 task yang memang butuh
interaksi fisik atau keputusan manusia.

### Frontend

**Public (no auth) — di `/careers/{slug}`:**

| File | Halaman |
|---|---|
| `frontend/src/layouts/CareerPublicLayout.vue` | Wrapper public (header brand, footer, brand-color CSS variable) |
| `frontend/src/views/Career/CareerHome.vue`    | Landing: hero, about, benefits, list jobs + filter |
| `frontend/src/views/Career/CareerJobDetail.vue` | Detail posting + tombol Apply |
| `frontend/src/views/Career/CareerApply.vue`   | **Form 4 langkah** (Profil → Identitas → Pendidikan & Pengalaman → Dokumen + Konfirmasi) — full Indonesian fields |
| `frontend/src/views/Career/CareerSuccess.vue` | Success page + tampilkan token tracking |
| `frontend/src/views/Career/CareerTrack.vue`   | Cek status via token, timeline tahapan |

**Admin — di `/recruitment/career-settings`:**
- `frontend/src/views/Career/CareerSettings.vue` — pengaturan branding,
  sosmed, extra questions, regen slug.
- `frontend/src/views/Recruitment/JobList.vue` — tombol icon globe untuk
  Publish / Unpublish posting + badge "🌐 Career" di kolom status.

**API client public:** `frontend/src/api/careerPublic.js` — axios instance
tanpa JWT interceptor (endpoint public).

## Workflow Skenario

### Skenario 1 — Setup awal Career Portal

1. HR login, ke **HR → Career Portal** (atau langsung
   `/recruitment/career-settings`).
2. Sistem auto-generate `career_slug` dari nama perusahaan (bisa diubah).
3. Set headline ("Bangun Karier Bersama Kami"), brand color, about HTML,
   benefits HTML, kontak email, sosmed.
4. Optional: tambah pertanyaan tambahan untuk form apply (mis. "Mengapa
   tertarik bergabung?", "Bersedia lembur?" dengan opsi).
5. Klik **Simpan**.
6. Klik tombol **↗ Buka** untuk preview public portal di tab baru.

### Skenario 2 — Publish lowongan

1. HR ke **Recruitment** → buat posting baru (atau pakai existing).
2. Klik tombol **▶ Buka** untuk set status DRAFT → OPEN.
3. Klik tombol **🌐** (icon globe biru) untuk publish ke career portal.
4. System auto-generate `public_slug` (mis. `software-engineer-job202600002`)
   + auto-generate `career_slug` perusahaan kalau belum ada.
5. Modal konfirmasi tampil: "Buka halaman public di tab baru?" → bisa
   langsung verify tampilan.
6. Posting sekarang muncul di list public `/careers/{slug}` dengan badge
   "🌐 Career" di JobList admin.

### Skenario 3 — Pelamar apply via career portal

1. Pelamar buka `https://app/careers/pt-demo-hris-indonesia`.
2. Browse list lowongan, filter by city / arrangement / search.
3. Klik posting → detail lengkap: deskripsi, requirements, benefit,
   salary range (kalau visible).
4. Klik **Lamar Sekarang** → form 4 step:
   - **Step 1 Profil**: Nama lengkap, email, HP, tanggal lahir,
     gender, status nikah, agama, kewarganegaraan.
   - **Step 2 Identitas**: NIK 16 digit (validasi), NPWP, KK, BPJS
     Kesehatan & TK, alamat, kota, provinsi.
   - **Step 3 Pendidikan & Pengalaman**: Jenjang (validasi vs min posting),
     institusi, jurusan, tahun lulus, IPK 0-4, jabatan saat ini,
     ekspektasi gaji, available_from, LinkedIn, portfolio.
   - **Step 4 Dokumen**: Upload CV (wajib), KTP, Ijazah, Pas Foto,
     cover letter, extra questions, **consent privacy**.
5. Submit → backend:
   - Validasi minimum
   - Insert ke `candidates` + initial stage NEW
   - Insert ke `candidate_documents` untuk tiap file
   - Generate `application_track_tokens` (random 64-hex, TTL 90 hari)
   - Audit log NOTICE module CAREER
   - Notif in-app ke HR + hiring_manager
6. Pelamar di-redirect ke `/careers/{slug}/success` dengan tampilan token
   pelacakan yang bisa di-copy + tombol **Lacak Lamaran Sekarang**.

### Skenario 4 — Pelamar lacak status

1. Pelamar buka link `https://app/careers/{slug}/track/{token}` (sudah
   bookmark) atau ke `/careers/{slug}/track` lalu paste token.
2. Halaman tampilkan:
   - Header status saat ini (badge berwarna + emoji per stage)
   - Posisi yang dilamar
   - Timeline lengkap setiap tahapan: tanggal, jadwal interview, status
     (Lolos/Tidak/Berjalan), feedback (kalau stage sudah final)
3. Email & NIK di-mask di response. Token expired setelah 90 hari.

### Skenario 5 — HR proses pelamar → hire (FULL FLOW)

1. HR buka **Recruitment** → klik posting yang relevan.
2. Pelamar yang masuk via career muncul di kolom **🆕 Baru** di pipeline.
3. Klik card kandidat → detail modal: profil lengkap, dokumen
   (CV/KTP/Ijazah/Foto), cover letter, jawaban extra questions.
4. Move stage: SCREENING → INTERVIEW_HR (set jadwal) → INTERVIEW_USER →
   OFFER → HIRED (12 task standar pipeline existing).
5. Klik **Hire** di card OFFER. Modal hire: konfirmasi gaji + tanggal join.
6. Service `convertToEmployee`:
   - Generate `EMP-2026-XXXXX`
   - Insert ke `employees` dengan **carry-over** NIK, NPWP, KK, BPJS,
     agama, status nikah (mapped to PTKP), tempat lahir, kota, dst.
   - Map `marital_status` → kode PTKP: `KAWIN` → `K0` (HR bisa update
     ke K1/K2/K3 setelah hire untuk update tanggungan).
   - Copy dokumen `CV/KTP/IJAZAH/foto` dari `candidate_documents` ke
     `employee_documents`.
   - Trigger `OnboardingService::applyTemplate('ONBOARD')` → 12 task
     otomatis (welcome meeting, BPJS register, kontrak PKWTT, setup
     laptop, dll).
   - Update posting status → FILLED kalau headcount sudah terpenuhi.
7. HR ke **HR → Onboarding** → progress karyawan baru sudah muncul
   dengan tasks yang ter-assign ke HR/IT/Finance/Manager sesuai role.
8. Generate kontrak via **Master → Contract Templates** dengan data
   employee yang sudah lengkap (NIK, alamat, dst sudah otomatis
   mengisi placeholder di template kontrak).

## Verifikasi

Smoke test `backend/scripts/test-career-portal-flow.php` lulus 49/49:

```
1. Schema check (career_pages, application_track_tokens + 11 kolom baru)
2. CareerService primitives (slugify, getOrCreateCareerPage)
3. Buat job posting + publish slug
4. Public landing resolve by slug
5. submitApplication: candidate + stage + 3 dokumen + token + notif
6. Validasi error: email kosong, NIK <16, dedup email, CV missing
7. Track token: resolve OK, invalid → null
8. Move stages NEW → ... → HIRED
9. convertToEmployee: 18 field carry-over (religion, marital_status PTKP,
   place_of_birth, nik, npwp, dst), 3 dokumen ter-copy ke
   employee_documents, onboarding tasks otomatis (>0 task).
```

## Migrasi Database

```bash
cd backend
php spark migrate
php spark db:seed CareerPortalSeeder
```

Seeder akan:
- Set `career_slug` semua company aktif
- Buat row `career_pages` default per company (brand color #0F62FE,
  about/benefits HTML default, 2 sample extra questions)
- Buat 2 sample job posting per company yang sudah dipublish (Software
  Engineer + HR Generalist)

Setelah seed, public URL sudah bisa diakses:
- `http://localhost:5173/careers/pt-demo-hris-indonesia`
- `http://localhost:5173/careers/richeese-factory`

## Permission & Akses

| Role | Career Settings | Publish/Unpublish | View Applicants |
|---|---|---|---|
| 1 super-admin | ✓ | ✓ | ✓ |
| 2 HR admin | ✓ | ✓ | ✓ |
| 3 manager | — | (bila punya recruitment.manage) | ✓ (bila recruitment.view) |
| 4 employee | — | — | — |

## Anti-Spam & Security

- **Rate-limit per IP**: 5 lamaran per jam (cache file CI4).
- **Dedup email per posting**: tidak boleh apply 2x ke posisi yang sama.
- **Validasi MIME + size** di upload public (5 MB, hanya PDF/JPG/PNG/WebP/DOC).
- **Random path** file (8 hex char) — tidak bisa ditebak.
- **HTML sanitize** di `about_html` / `benefits_html` di admin endpoint
  — strip `<script>`, `<iframe>`, on-event handlers, `javascript:` proto.
- **Audit log NOTICE** untuk setiap submit + upload (modul `CAREER`).
- **NIK / email mask** di response track endpoint.

## Roadmap Selanjutnya

- **Email notification ke pelamar** saat status berubah (sekarang hanya
  in-app ke HR). Butuh email queue worker.
- **CAPTCHA / hCaptcha** di form apply untuk mencegah bot lebih kuat
  daripada rate-limit IP.
- **Custom domain per company** untuk career page (mis.
  `karier.quantara.com` → resolve ke slug). Butuh wildcard DNS + nginx
  config.
- **OG image dinamis** (`<meta og:image>`) untuk preview share posting di
  WhatsApp/LinkedIn.
- **Sitemap & robots.txt** otomatis untuk SEO.
- **Analytics**: track view & apply rate per posting.
- **Talent pool**: re-engage pelamar REJECTED yang berkualitas via
  email blast saat ada posisi baru relevan.