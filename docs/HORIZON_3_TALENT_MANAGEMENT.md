# Horizon 3 — Talent Management Cluster (Sprint 14 + 17)

Dokumen ini meringkas implementasi Horizon 3 dari roadmap
`QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md`, fokus **Talent Management**:
3 modul yang berdiri sendiri sebagai foundation HR strategis (kompetensi,
succession planning, internal mobility) — beda dengan Horizon 2 yang
deepen modul existing.

## Status Cluster

| ID | Fitur | Sprint | Status | Service |
|----|-------|--------|--------|---------|
| H3.1 | Modul Kompetensi & Library Jabatan | 14 | ✅ Backend done | `CompetencyService` |
| H3.2 | Succession Planning | 14 | ✅ Backend done | `SuccessionService` |
| H3.3 | Flexible Benefits (Cafeteria Plan) | 15 | ⬜ Defer (butuh design lebih lanjut) | — |
| H3.4 | Integrasi ERP / Accounting Journal | 15 | ⬜ Defer (butuh vendor mapping) | — |
| H3.5 | Predictive HR Analytics | 16 | ⬜ Defer (butuh Python ML service) | — |
| H3.6 | Talent Pool & Internal Marketplace | 17 | ✅ Backend done | `TalentPoolService` |
| H3.7 | Digital Signing PSrE | 18 | ⬜ Defer (butuh kontrak vendor PrivyID/Vida) | — |
| H3.8 | Open API & Developer Portal | 18 | ⬜ Defer (perlu OAuth server lengkap) | — |

3 dari 8 item di-deliver sebagai backend-only. 5 item di-defer karena
butuh dependency eksternal (vendor / ML infra / OAuth server) atau
design product yang belum mature.

## Schema Changes

Migration `2024-01-01-000062_Horizon3TalentManagement` (idempotent):

**Competency cluster:**
- `competencies` — master taxonomy (CORE/LEADERSHIP/FUNCTIONAL/TECHNICAL/BEHAVIORAL)
- `competency_levels` — descriptor 1-5 per competency
- `position_competency_profiles` — required level + weight + is_critical per posisi
- `employee_competency_assessments` — penilaian aktual karyawan dengan
  evidence + assessor_type (SELF/SUPERVISOR/PEER/HR/CERTIFICATION) +
  valid_until (untuk certification expire)
- ALTER `idps` + `competency_id` (link IDP ke competency yang dikembangkan)
- ALTER `trainings` + `competency_id` (tag training catalog)

**Succession cluster:**
- `critical_positions` — posisi kunci dengan criticality HIGH/MEDIUM/LOW +
  min_successor_count (default 2) + risk_factor + incumbent_employee_id
- `succession_candidates` — mapping employee × critical position dengan
  readiness ENUM (READY_NOW / 1Y / 2Y / 3Y_PLUS / NOT_READY) + gap_score

**Talent Pool cluster:**
- `employee_skills` — skill self-declared dengan proficiency
  BEGINNER/INTERMEDIATE/ADVANCED/EXPERT + is_validated flag
- `employee_aspirations` — career goal dengan timeline IMMEDIATE/SHORT/MID/LONG
  + visibility (SUPERVISOR/HR/COMPANY)
- `internal_job_postings` — posting internal sebelum public, status
  DRAFT/OPEN/CLOSED/CANCELLED/PROMOTED_TO_PUBLIC + min_tenure_months
- `internal_applications` — lamaran internal dengan status
  SUBMITTED/IN_REVIEW/INTERVIEW/OFFER/ACCEPTED/REJECTED/WITHDRAWN

**Permissions baru** (auto-grant via migration):
- `competency.view`, `competency.manage`, `competency.assess_self`
- `succession.view`, `succession.manage`
- `talent_pool.view`, `talent_pool.manage`, `internal_job.apply`

## H3.1 — Competency Library & Gap Analysis

### Pure formula: `calcGapScore`

Bobot weighted distance dari level current ke target:

```
gap_score = (Σ(target − current) × weight) / (Σ target × weight) × 100
readiness_pct = 100 − gap_score
```

- 0 = fully ready (no gap)
- 100 = max gap (semua kompetensi current = 0, target = 5)
- `missing_critical` = count kompetensi `is_critical=1` yang current < target

### Per-criterion scoring

- Latest assessment per `competency_id` dipakai (skip kalau `valid_until` < today untuk certification)
- Multiple assessor type (self/supervisor/peer/HR/certification) — semua
  di-stack di `employee_competency_assessments` untuk audit; gap analysis
  pakai yang terbaru saja per competency

### Recommendation training

Filter `trainings` yang `competency_id` masuk ke gap > 0, ranking by
**impact score = gap × weight**, status PUBLISHED, end_date >= today.
HR dashboard bisa pakai output ini untuk auto-suggest training plan.

## H3.2 — Succession Planning

### Coverage Ratio

```
covered_count = jumlah critical_positions dengan ≥ min_successor_count
                successor aktif berstatus READY_NOW atau READY_1Y
coverage_pct = covered_count / total × 100
```

Breakdown by criticality (HIGH/MEDIUM/LOW) dengan list `gaps`
(positions yang under-covered + shortage).

### Readiness Auto-Compute

Saat HR `nominate(criticalPositionId, employeeId)`, service auto-call
`gapAnalysis(employee, target_position)` dari CompetencyService → map
gap_score ke readiness bucket via `readinessFromGap()`:

| Gap Score | Readiness |
|-----------|-----------|
| 0-9 | READY_NOW |
| 10-24 | READY_1Y |
| 25-44 | READY_2Y |
| 45-69 | READY_3Y_PLUS |
| 70-100 | NOT_READY |

### Validasi

- Incumbent tidak bisa jadi successor untuk posisi sendiri
- Cross-tenant nomination ditolak (`company_id` employee != company_id position)
- Idempotent: re-nominate update `last_reviewed_at` dan recompute readiness

## H3.6 — Talent Pool & Internal Marketplace

### Skill Matching Score

Pure function dengan partial credit + surplus bonus:

```
Untuk tiap required skill:
  - tidak ada di candidate → 0 contribution
  - level < min → partial = (level / min) × weight
  - level = min → full = weight
  - level > min → bonus = weight × (1 + (surplus × 0.1)), max 1.5×

score = Σ contributions / Σ weights × 100, capped at 100
```

Output juga return `matched_skills`, `missing_skills` untuk explainability.

### 9-Box Grid

```
                ┌──────────────┬────────────┬─────────────┐
   potential 4-5│ 7 Latent     │ 8 High Pot │ 9 Star      │
                ├──────────────┼────────────┼─────────────┤
   potential 3  │ 4 Effective  │ 5 Core     │ 6 High Prof │
                ├──────────────┼────────────┼─────────────┤
   potential 1-2│ 1 Underperf  │ 2 Limited  │ 3 Solid     │
                └──────────────┴────────────┴─────────────┘
                  perf 1-2       perf 3       perf 4-5
```

- Performance = avg `final_score` dari `performance_reviews` rescaled (÷ 20 → 0-5)
- Potential = avg `current_level` dari `employee_competency_assessments`
- Karyawan yang tidak punya data performance maupun potential di-skip

### Internal Job Posting Workflow

1. HR buat `internal_job_posting` dengan `min_tenure_months` (default 12)
   dan optional FK ke `job_postings` public
2. Karyawan apply via `POST /internal-job-postings/:id/apply` — service
   validate tenure + status OPEN + belum apply sebelumnya
3. HR review applications + (TODO) flow `ACCEPTED → JIC otomatis` untuk
   rotasi internal (link `internal_applications.jic_id`)
4. Kalau slot tidak terisi internal, posting di-promote `PROMOTED_TO_PUBLIC`
   dan publish ke career portal (re-use existing `job_postings`)

### Recommend Internal Candidates

Untuk satu internal posting, ranking karyawan eligible (tenure check)
by skill match score terhadap profile competency posisi target. Output
include `match_score`, `matched_count`, `missing_count`.

## Endpoint Lengkap

Semua di-route lewat `Horizon3Controller` (auth: filter `jwt`):

```
# H3.1 Competency Library
GET    /api/v1/competencies?category=&is_active=
POST   /api/v1/competencies
GET    /api/v1/positions/{id}/competency-profile
PUT    /api/v1/positions/{id}/competency-profile
GET    /api/v1/employees/{id}/competency-gap?target_position_id=
GET    /api/v1/employees/{id}/training-recommendations?limit=
POST   /api/v1/employee-competency-assessments

# H3.2 Succession Planning
GET    /api/v1/critical-positions
POST   /api/v1/critical-positions
POST   /api/v1/critical-positions/{id}/successors
GET    /api/v1/critical-positions/{id}/successors
POST   /api/v1/succession-candidates/{id}/recompute-readiness
GET    /api/v1/succession/coverage

# H3.6 Talent Pool
GET    /api/v1/employees/{id}/skills
POST   /api/v1/employees/{id}/skills
POST   /api/v1/employee-skills/{id}/validate
GET    /api/v1/internal-job-postings?status=
POST   /api/v1/internal-job-postings
POST   /api/v1/internal-job-postings/{id}/apply
GET    /api/v1/internal-job-postings/{id}/applications
GET    /api/v1/internal-job-postings/{id}/recommended-candidates?limit=
GET    /api/v1/talent-pool/9-box
```

## Testing

Pure-function tests di `tests/Unit/Horizon3ServicesTest.php`
(15 tests, 50 assertions) — semua pass:

- 5 test gap analysis (perfect/partial/critical/missing/empty)
- 1 test slug normalizer
- 1 test readiness mapper (10 boundary checks)
- 7 test skill matcher + slug + box grid + label

Full suite: 695 tests pass (sebelumnya 680, +15). Tidak ada regresi.

Run: `cd backend && vendor/bin/phpunit --filter Horizon3Services`.

## Gap Backend → Frontend

Semua endpoint sudah JSON-serializable; FE tinggal konsumsi. UI yang
perlu dibuat (defer ke Sprint FE berikutnya):

1. **Master Competencies** — admin CRUD + level descriptor editor
2. **Position Competency Profile** — drag drop competencies dengan
   target level + weight slider
3. **Employee Gap Radar** — radar chart current vs target per competency
4. **IDP Form Update** — dropdown competency yang akan dikembangkan
5. **Succession Dashboard** — heatmap coverage by criticality + gap list
6. **Skill Matrix Editor** — ESS karyawan input skill + supervisor validate
7. **Internal Job Board** — list + apply button + cover letter textarea
8. **9-Box Grid** — clickable matrix dengan drilldown ke list karyawan per box

## Defer Notes

5 item Horizon 3 lainnya (H3.3-3.5, H3.7-3.8) di-defer dengan alasan:

- **H3.3 Flexible Benefits**: butuh consultative design dengan HR head
  + integrasi ke `payroll_components` + UI alokasi nominal yang non-trivial
- **H3.4 ERP Integration**: butuh mapping COA per company, vendor adapter
  (Accurate/Zahir/Odoo/SAP) yang berbeda-beda — eksplor PoC dulu
- **H3.5 Predictive Analytics**: butuh Python microservice + ML model
  + monitoring drift — masuk Horizon 4 atau standalone initiative
- **H3.7 PSrE Digital Signing**: butuh kontrak komersial PrivyID/Vida/Peruri
  — UU ITE-compliant, sertifikat berbayar
- **H3.8 Open API + Developer Portal**: design OAuth scope + sandbox env
  + rate-limit tier yang lengkap; perlu produk decision

Ketika item-item di atas siap dieksekusi, schema + service yang sudah
ada sekarang (employees, payroll, contracts) tinggal di-extend.