# Recruitment / ATS (Applicant Tracking System)

Dokumen ini menjelaskan modul **Recruitment / ATS** yang ditambahkan di
iterasi roadmap fase 4 item #11.

## Latar Belakang

Modul rekrutmen melengkapi siklus HR end-to-end: dari pembukaan posisi,
intake kandidat, pipeline interview, sampai konversi otomatis ke karyawan
dengan trigger onboarding tasks. Tanpa modul ini, perusahaan biasanya
tracking pakai spreadsheet yang sulit di-share antar HR/manager dan rentan
data hilang.

## Apa yang Berubah

### Database

Migration `2024-01-01-000020_CreateRecruitmentTables.php` — 4 tabel:

**`job_postings`** — lowongan kerja:
- `reference_number` unik per company (`JOB-<YEAR>-<5digit>`)
- `title`, `department_id`, `position_id`, `branch_id`
- `employment_type` (PKWT/PKWTT/MAGANG/OUTSOURCING/PARTTIME/CONTRACT)
- `description`, `requirements`
- `salary_min`, `salary_max`, `salary_visible` (untuk public job board)
- `headcount` — auto-FILLED saat headcount terpenuhi
- `status` lifecycle: DRAFT → OPEN → PAUSED → CLOSED → FILLED
- `hiring_manager_id`, `public_url_slug`, `notes`

**`candidates`** — pelamar:
- Profile: `full_name`, `email`, `phone`, `birth_date`, `gender`, `address`
- Pengalaman: `current_position`, `current_company`, `years_experience`, `expected_salary`
- `available_from`, `source` (WEBSITE/LINKEDIN/JOBSTREET/REFERRAL/WALK_IN/OTHER)
- `referral_employee_id` (kalau referral karyawan internal)
- `current_stage` (NEW/SCREENING/INTERVIEW_HR/INTERVIEW_USER/OFFER/HIRED/REJECTED/WITHDRAWN)
- `rating` (1-5 stars), `rejection_reason`
- `converted_employee_id`, `converted_at` (kalau sudah hire)

**`candidate_stages`** — riwayat per tahapan:
- `stage`, `result` (PENDING/PASSED/FAILED/SKIPPED)
- `scheduled_at`, `completed_at` untuk interview
- `interviewer_id`, `feedback`, `rating`

**`candidate_documents`** — file upload:
- `document_type` (CV/KTP/IJAZAH/TRANSKRIP/SERTIFIKAT/PORTFOLIO/OTHER)
- `file_name`, `file_path`, `mime_type`, `file_size`

3 permission baru dengan auto-grant terdiferensiasi:
- `recruitment.view` — role 1, 2, 3
- `recruitment.manage` — role 1, 2, 3 (manager bisa request posisi)
- `recruitment.hire` — role 1, 2 (HR/admin saja yang convert)

### Backend

#### Library `RecruitmentService` (`Services::recruitment()`)

Method | Output / Fungsi
--- | ---
`nextJobReferenceNumber($coId)` | Generate `JOB-YEAR-XXXXX` unik
`nextEmployeeNumber($coId)` | Generate `EMP-YEAR-XXXXX` saat hire
`moveStage($candId, $newStage, $params)` | Pindah stage dengan tutup stage lama (PASSED) + buka stage baru (PENDING)
`reject($candId, $reason)` | Stage → REJECTED, simpan alasan, tutup stage lama (FAILED)
`convertToEmployee($candId, $overrides)` | Atomic: clone candidate → row `employees`, link `converted_employee_id`, trigger ONBOARD_DEFAULT
`pipelineStats($coId, $jobId?)` | Counter per stage untuk dashboard kanban

**Validasi penting**:
- Stage transition dari TERMINAL (HIRED/REJECTED/WITHDRAWN) hanya boleh ke TERMINAL lain (mis. WITHDRAWN → REJECTED tidak masalah, tapi REJECTED → SCREENING dilarang).
- `convertToEmployee` hanya untuk stage HIRED, tidak boleh double-convert.
- Saat convert, `full_name` di-split jadi `first_name` + `last_name` agar kompatibel dengan schema employees lama.

#### Controller `RecruitmentController`

17 endpoint:

| Endpoint | Aksi |
|---|---|
| `GET /job-postings` | List dengan filter q/status |
| `POST /job-postings` | Create posting (default DRAFT) |
| `GET /job-postings/(:num)` | Detail + pipeline stats per stage |
| `PUT /job-postings/(:num)` | Update field |
| `DELETE /job-postings/(:num)` | Soft-delete |
| `POST /job-postings/(:num)/open` | DRAFT/PAUSED → OPEN |
| `POST /job-postings/(:num)/close` | OPEN/PAUSED/FILLED → CLOSED |
| `GET /candidates` | List dengan filter q/stage/job_posting_id |
| `POST /candidates` | Create candidate (auto-create initial stage NEW) |
| `GET /candidates/(:num)` | Detail + history stages + documents |
| `PUT /candidates/(:num)` | Update profil |
| `DELETE /candidates/(:num)` | Soft-delete (kecuali sudah jadi employee) |
| `POST /candidates/(:num)/move-stage` | Pindah stage dengan params |
| `POST /candidates/(:num)/reject` | Reject dengan alasan |
| `POST /candidates/(:num)/hire` | Convert ke employee + trigger onboarding |
| `GET /recruitment/pipeline-stats` | Aggregate stats |

Audit log per aksi (modul `RECRUITMENT`).

### Frontend

**`Recruitment/JobList.vue`** — list posting dengan:
- Filter cari/status, badge status berwarna 5-tier
- Counter kandidat per posting
- Action per row: Detail, Buka, Tutup, Edit, Hapus
- Click row → masuk pipeline kanban
- Form modal create/edit dengan field lengkap (salary range, headcount, deskripsi, persyaratan)

**`Recruitment/PipelineBoard.vue`** — kanban Trello-style:
- 6 kolom: Baru → Screening → Interview HR → Interview User → Offer → Hired
- Card kandidat dengan: nama, posisi, expected salary, rating bintang
- Tombol di-card: pindah ke stage berikutnya, reject
- Click card → detail modal dengan riwayat stage timeline + aksi
- **Add candidate modal** dengan field profil lengkap
- **Reject modal** dengan validasi alasan ≥ 5 char
- **Hire modal** dengan input gaji pokok + tanggal bergabung,
  preview "Onboarding tasks (12 task default) otomatis dibuat"

Sidebar: Group "HR" → "Recruitment".

Router:
- `/recruitment/jobs` → JobList
- `/recruitment/jobs/:id` → PipelineBoard

## Workflow Skenario

### Skenario 1 — Recruit dari awal sampai hire

1. HR buka **Recruitment** → klik **Posting Baru**.
2. Isi judul "Senior Backend Developer", PKWTT, headcount 1, salary 8-12jt, deskripsi & persyaratan.
3. Save → status DRAFT. Klik tombol **Buka** → status OPEN.
4. Click row → masuk PipelineBoard. Kosong kandidat.
5. Klik **Tambah Kandidat** → isi profil A. Save → muncul di kolom "🆕 Baru".
6. Setelah review CV, klik panah biru di card → otomatis pindah ke "📋 Screening".
7. Atur jadwal interview HR via detail modal: pindah ke "🗣️ Interview HR" dengan `scheduled_at = besok 10:00`.
8. Setelah interview, pindah ke "👥 Interview User" untuk technical test.
9. Pass technical → "💰 Offer". Setelah negosiasi, klik **Hire** di detail modal.
10. Modal Hire: konfirmasi gaji (default expected_salary), tanggal bergabung. Konfirmasi → sistem:
    - Move stage ke HIRED
    - Convert: clone profile ke `employees` baru dengan auto-generate EMP-YEAR-XXXXX
    - Trigger `ONBOARD_DEFAULT` → 12 task otomatis dibuat (welcome meeting, kontrak, BPJS, setup laptop, dll)
    - Posting status FILLED kalau headcount terpenuhi.

### Skenario 2 — Reject kandidat di tengah proses

1. Setelah interview HR, kandidat tidak fit. Klik tombol **❌** di card atau di detail modal.
2. Reject modal → isi alasan minimal 5 char ("Pengalaman kurang relevan dengan stack kami").
3. Save → stage REJECTED, tercatat di history dengan alasan. Card hilang dari kanban (terhitung di counter "X ditolak").

### Skenario 3 — Manager request pembukaan posisi

Role 3 (manager) punya `recruitment.manage` tapi tidak `recruitment.hire`. Manager bisa:
- Buat posting DRAFT
- Edit deskripsi & persyaratan
- Tambah & move kandidat di pipeline
- Reject kandidat

Tapi tidak bisa **Hire** (button disabled, atau API return 403). HR/admin harus konfirmasi sebelum convert.

### Skenario 4 — Auto-trigger onboarding

Saat klik **Hire**, dibalik layar terjadi:
1. `OvertimeController::approve` style logic — atomic insert ke `employees` + update `candidates.converted_employee_id`
2. `Services::onboarding()->applyTemplate(empId, null, 'ONBOARD', joinDate)` dipanggil
3. Service iterasi 12 task di template ONBOARD_DEFAULT, generate `employee_tasks` rows dengan:
   - HR/Supervisor/IT/Karyawan auto-resolved sebagai assignee
   - Due date dihitung dari joinDate + offset_days per task
   - Notifikasi otomatis ke setiap assignee
4. HR bisa langsung lihat progress di **HR → Onboarding/Offboarding** tab Onboarding.

## Verifikasi

Smoke test `backend/scripts/test-recruitment-flow.php` lulus 30/30:

```
1. Schema check (4 tabel + 3 permissions)
2. Generators: JOB-YEAR-XXXXX & EMP-YEAR-XXXXX format
3. Create job posting → status OPEN
4. Create candidate → stage NEW + initial stage row
5. Move stages: NEW → SCREENING → INTERVIEW_HR → OFFER → HIRED
   (5 stages history tercatat)
6. pipelineStats: stats[HIRED]=1, stats[NEW]=0, 8 stage keys
7. convertToEmployee: employee dibuat dengan format EMP-YEAR-XXXXX,
   onboarding tasks > 0 (auto-trigger), job FILLED
8. Re-convert → exception "sudah di-convert"
9. Reject candidate dengan alasan, lalu test:
   - Convert REJECTED → exception "HIRED only"
   - Move REJECTED → SCREENING → exception "stage final"
```

## Migrasi Database

```bash
cd backend
php spark migrate
```

Tidak ada seeder default (job postings dibuat HR sesuai kebutuhan
perusahaan).

## Integrasi dengan Modul Lain

- **Onboarding** (modul #7): convertToEmployee otomatis trigger ONBOARD_DEFAULT
  via `Services::onboarding()`. Tasks 12 default langsung muncul di
  OnboardingBoard untuk karyawan baru.
- **Contracts** (modul existing): setelah hire, HR bisa generate kontrak
  PKWT/PKWTT lewat `ContractTemplateController::generate` dengan employee_id baru.
- **Audit Log**: setiap aksi (create posting, move stage, hire, reject)
  tercatat di modul `RECRUITMENT`.

## Roadmap Selanjutnya

- **Public job board**: route public tanpa auth `/jobs/{slug}` untuk kandidat
  apply via form (perlu CAPTCHA + email verification).
- **Email integration**: kirim email otomatis saat invite interview / reject /
  offer letter (saat ini hanya notif in-app).
- **Document upload**: UI upload CV/KTP via form (saat ini schema sudah ada,
  perlu endpoint POST + multipart handler).
- **Interview scheduling**: integrasi calendar untuk slot interview, reminder otomatis.
- **Bulk import candidates**: CSV import untuk ATS migration.
- **Talent pool**: simpan kandidat REJECTED yang berkualitas untuk re-engage di posting lain.