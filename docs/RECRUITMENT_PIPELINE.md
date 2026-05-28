# Recruitment Pipeline Custom + Master Test Categories

Modul untuk **kustomisasi urutan stages rekrutmen** per posting +
**master jenis test online** yang HR bisa kelola sendiri (bukan ENUM
hardcoded). Diperkenalkan di **migration #065** (Mei 2026).

## Latar Belakang

Pipeline default HRIS dulunya hardcoded 7 stages:
`NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR → INTERVIEW_USER → OFFER → HIRED`.
Praktik HR Indonesia jauh lebih beragam:

- **Posisi sales**: butuh stage FGD (Focus Group Discussion).
- **Posisi pabrik**: tes Pauli + Kraepelin sebelum interview.
- **Eksekutif**: 3 tahap interview (HR → Direktur → BOD) + Background Check + MCU.
- **Posisi entry-level**: skip online test, langsung CV review → Interview HR.
- **Tes online**: bukan cuma TPA/English/DISC. Banyak perusahaan pakai
  Pauli, Kraepelin, MBTI, Wartegg, Holland (RIASEC), Big Five, IST.

Dua hal yang sekarang fleksibel:
1. **Pipeline template** — HR bikin urutan stages bebas + assign ke
   job posting.
2. **Master kategori test** — 14 kategori built-in, HR boleh tambah custom.

## Arsitektur Database

```
online_test_categories
    id PK
    company_id  (NULL = built-in, NOT NULL = custom per company)
    code        UNIQUE per scope, mis. PAULI, KRAEPELIN
    name, description, group_label, scoring_type, icon
    is_built_in (locked dari delete)

recruitment_pipeline_templates
    id PK
    company_id, code (UNIQUE per company), name, description
    is_default      (1 = dipakai job posting yang tidak set eksplisit)

recruitment_pipeline_stages
    id PK
    template_id FK
    company_id, order_index, code (UNIQUE in template)
    name                      (display label)
    stage_type                (SCREENING / ONLINE_TEST / INTERVIEW / ASSESSMENT /
                               OFFER / BACKGROUND_CHECK / MEDICAL_CHECK / CUSTOM /
                               HIRED / REJECTED / WITHDRAWN)
    required_test_category_id (gate validation untuk stage_type=ONLINE_TEST sebelumnya)
    sla_hours, is_terminal, is_optional, description

job_postings.pipeline_template_id  (NULL = pakai default company)

online_tests.category_id              (FK paralel ke kolom string `category` lama)
online_test_question_bank.category_id

candidate_stages.pipeline_stage_id    (snapshot FK saat moveStage di template mode)
```

## Built-in Test Categories (14)

Auto-seeded saat migrate. Tidak bisa dihapus.

| Group         | Code         | Nama                            | Scoring         |
|---------------|--------------|---------------------------------|-----------------|
| KOGNITIF      | TPA_VERBAL   | TPA Verbal                      | SCORE           |
| KOGNITIF      | TPA_NUMERIK  | TPA Numerik                     | SCORE           |
| KOGNITIF      | TPA_LOGIKA   | TPA Logika                      | SCORE           |
| KOGNITIF      | IST          | IST (Intelligence Structure)    | SCORE           |
| KEMAMPUAN     | PAULI        | Tes Pauli ("tes koran")         | PROFILE         |
| KEMAMPUAN     | KRAEPELIN    | Tes Kraepelin                   | PROFILE         |
| KEPRIBADIAN   | DISC         | DISC Personality                | NO_RIGHT_ANSWER |
| KEPRIBADIAN   | MBTI         | MBTI (Myers-Briggs)             | NO_RIGHT_ANSWER |
| KEPRIBADIAN   | BIG_FIVE     | Big Five (OCEAN)                | NO_RIGHT_ANSWER |
| KEPRIBADIAN   | WARTEGG      | Wartegg                         | PROFILE         |
| KEPRIBADIAN   | HOLLAND      | Holland (RIASEC)                | PROFILE         |
| BAHASA        | ENGLISH      | English Proficiency             | SCORE           |
| TEKNIS        | TECHNICAL    | Technical Test                  | SCORE           |
| TEKNIS        | GENERAL      | General Knowledge               | SCORE           |

`scoring_type`:
- `SCORE` — ada benar/salah (TPA, English, IST)
- `NO_RIGHT_ANSWER` — Likert/skala kepribadian (DISC, MBTI, Big Five)
- `PROFILE` — output profil (Pauli, Wartegg, Holland — biasanya butuh
  manual scoring oleh psikolog)

## Default Pipeline Template

Migration auto-seed satu template "STANDARD" per company aktif dengan
9 stages (sama dengan hardcoded lama + REJECTED/WITHDRAWN terminal):

```
0  NEW              SCREENING
1  SCREENING        SCREENING
2  ONLINE_TEST      ONLINE_TEST
3  INTERVIEW_HR     INTERVIEW
4  INTERVIEW_USER   INTERVIEW
5  OFFER            OFFER
6  HIRED            HIRED       [TERMINAL]
90 REJECTED         REJECTED    [TERMINAL]
91 WITHDRAWN        WITHDRAWN   [TERMINAL]
```

`order_index` 90/91 untuk terminal supaya tidak muncul di tengah papan
kanban; tetap addressable via API.

## API Endpoint

```
# Master kategori test
GET    /api/v1/online-test-categories            # List paginated
GET    /api/v1/online-test-categories/picker     # Dropdown grouped by family
POST   /api/v1/online-test-categories
GET    /api/v1/online-test-categories/(:num)
PUT    /api/v1/online-test-categories/(:num)
DELETE /api/v1/online-test-categories/(:num)     # Block kalau ada usage

# Master pipeline templates
GET    /api/v1/recruitment-pipelines             # List
GET    /api/v1/recruitment-pipelines/picker      # Dropdown ringkas
POST   /api/v1/recruitment-pipelines
GET    /api/v1/recruitment-pipelines/(:num)      # Detail + stages
PUT    /api/v1/recruitment-pipelines/(:num)      # Update + replace stages
DELETE /api/v1/recruitment-pipelines/(:num)      # Block kalau dipakai posting
POST   /api/v1/recruitment-pipelines/(:num)/duplicate
```

## Permissions

```
recruitment_pipeline.view    Lihat master pipeline rekrutmen + stages
recruitment_pipeline.manage  CRUD pipeline templates + stages
online_test_category.manage  Tambah/edit jenis test custom
```

Auto-grant ke `super_admin` (1) & `hr_admin` (2).

## Workflow HR

### 1. Buat pipeline custom

1. Buka `Master Data → Pipeline Rekrutmen`.
2. Klik **Tambah Pipeline** — sistem prefill dengan struktur STANDARD.
3. Edit:
   - Tambah stage baru (drag-drop urutan, set `stage_type`, `name`).
   - Untuk stage type `ONLINE_TEST` → optional set `required_test_category_id`.
4. Simpan.

### 2. Pasangkan pipeline ke job posting

Di form Job Posting (atau saat edit), dropdown **Pipeline Rekrutmen**
muncul. Pilih template; biarkan kosong untuk pakai default company.

### 3. Move stage validation

`RecruitmentService::moveStage()` resolve stages dari `pipeline_template_id`
job. Validasi:

- Stage code harus exist di template (kalau pakai template).
- Tidak bisa balik dari terminal (HIRED/REJECTED/WITHDRAWN).
- **Test gate**: kalau stage tujuan datang setelah stage type `ONLINE_TEST`
  di template, kandidat harus sudah selesai semua test assignment + di-grade.
  Kalau prior test stage punya `required_test_category_id`, gate spesifik
  ke kategori tsb (kandidat harus punya minimal 1 assignment kategori itu
  yang COMPLETED + best_result PASSED/FAILED).

### 4. Skenario umum

#### Pipeline Sales (FGD + presentasi)
```
NEW → SCREENING → ONLINE_TEST (DISC) → FGD → PRESENTATION → INTERVIEW_USER → OFFER → HIRED
```

#### Pipeline Eksekutif
```
NEW → SCREENING → ASSESSMENT (Pauli+Kraepelin) → INTERVIEW_HR → INTERVIEW_DIR →
  INTERVIEW_BOD → BACKGROUND_CHECK → MEDICAL_CHECK → OFFER → HIRED
```

#### Pipeline pabrik (Online Test SETELAH Interview HR)
```
NEW → SCREENING → INTERVIEW_HR → ONLINE_TEST (Kraepelin) → MCU → OFFER → HIRED
```

Frontend kanban + tombol "next stage" otomatis ikut urutan template.

## Backward Compatibility

- Job posting lama yang `pipeline_template_id IS NULL` otomatis pakai
  template default company (auto-seeded). Behavior identik dengan
  sebelumnya.
- Kandidat lama (`current_stage` di luar template) tetap di-display di
  pipeline stats, hanya tidak punya kolom kanban dedicated kalau
  stage code-nya tidak match. HR bisa migrate dengan move-stage manual.

## Frontend

| Path                                  | Komponen                          | Untuk                            |
|---------------------------------------|-----------------------------------|----------------------------------|
| `/master/online-test-categories`      | `Master/OnlineTestCategories.vue` | CRUD kategori test (14 built-in + custom) |
| `/master/recruitment-pipelines`       | `Master/RecruitmentPipelines.vue` | List + drag-drop stage editor    |
| `/recruitment/jobs/(:id)`             | `Recruitment/PipelineBoard.vue`   | Kanban board pakai stages dinamis |
| `/recruitment/jobs` (form)            | `Recruitment/JobList.vue`         | Dropdown pilih pipeline template |

Stage editor pakai HTML5 drag-drop native (tidak butuh library tambahan).
Validasi UI sebelum submit:
- Code stage unik dalam template + format `[A-Z0-9_]{1,32}`.
- Wajib ada minimal 1 stage `stage_type=HIRED`.
- `required_test_category_id` harus valid + accessible (built-in atau
  company-scoped).

## Verification

```
=== ONLINE TEST CATEGORIES ===
Total: 14 kategori built-in (KOGNITIF, KEMAMPUAN, KEPRIBADIAN, BAHASA, TEKNIS)

=== PIPELINE TEMPLATES per Company ===
[c1] Pipeline Standar — 9 stages (default)

=== STAGES dalam STANDARD ===
 0 NEW             Baru Masuk        SCREENING
 1 SCREENING       CV Screening      SCREENING
 2 ONLINE_TEST     Online Test       ONLINE_TEST
 3 INTERVIEW_HR    Interview HR      INTERVIEW
 4 INTERVIEW_USER  Interview User    INTERVIEW
 5 OFFER           Offer Letter      OFFER
 6 HIRED           Hired             HIRED       [TERMINAL]
90 REJECTED        Ditolak           REJECTED    [TERMINAL]
91 WITHDRAWN       Mengundurkan Diri WITHDRAWN   [TERMINAL]

Backfill category_id online_tests: 7/9 linked
Backfill category_id question_bank: 88/88 linked
Permissions: 3/3 registered
```

## Lihat juga

- `docs/ONLINE_TEST.md` — engine online test (assignments, attempts, grading)
- `docs/RECRUITMENT.md` — modul ATS umum
- Migration source: `backend/app/Database/Migrations/2024-01-01-000065_CreateRecruitmentPipelineTemplates.php`