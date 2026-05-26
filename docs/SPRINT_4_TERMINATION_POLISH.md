# Sprint 4 — Termination & Polish (Selesai)

**Tanggal selesai:** 26 Mei 2026
**Cakupan:** 4 item dari `docs/LIFECYCLE_AUDIT_2026.md` (OFF-5, OFF-1, CC-4, CC-5)
**Hasil:** 557 tes hijau (532 Sprint 3 + 25 baru), tidak ada regresi

---

## Ringkasan Item yang Dikerjakan

| Item | Deskripsi | Status |
|------|-----------|--------|
| OFF-5 | PHK formal workflow (multi-step + bipartite + auto severance + PHK letter) | ✅ Backend full + 12 endpoint |
| OFF-1 | Exit interview digital dengan analytics dashboard | ✅ Backend + 8 endpoint + analytics aggregator |
| CC-4 | Employee 360 view aggregator endpoint | ✅ 12 section di 1 round-trip |
| CC-5 | Compliance reports siap cetak (8 report types) | ✅ Cache-aware generator + CSV export |

---

## Yang Dibuat

### Database

**Migration 54** (`2024-01-01-000054_TerminationAndPolishSprint4.php`):

- Tabel baru `termination_requests` — formal PHK workflow:
  - `termination_type` (UNILATERAL / MUTUAL / CONTRACT_END / RETIREMENT / DEATH / DISABILITY)
  - `reason_code` — slug dari `SeveranceCalculator::REASONS`
  - `bipartite_held_at`, `bipartite_outcome`, `bipartite_minutes`,
    `tripartite_required` — negosiasi bipartite/tripartite tracking
  - `severance_id` (FK ke severance_calculations) — auto-create saat APPROVED
  - `phk_letter_id` (FK ke generated_contracts) — auto-generate saat EXECUTED
  - Multi-step approver tracking: `hr_director_*`, `bod_*`
  - Status flow: `DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR
    → PENDING_BOD → APPROVED → EXECUTED` (atau REJECTED / CANCELLED)
  - Reference number: `PHK-{YEAR}-{5digit}`

- Tabel baru `exit_interviews` — wawancara terstruktur:
  - Link ke `resignation_requests` ATAU `termination_requests` (mutually exclusive)
  - `mode` (IN_PERSON / VIDEO / PHONE / WRITTEN / SELF_FILL)
  - `would_rehire` (YES / NO / CONDITIONAL / UNKNOWN)
  - `primary_reason` + `secondary_reasons_json`
  - `satisfaction_score` (1-10), `recommend_score` (1-10 NPS)
  - 6 Likert scores (1-5): compensation, management, environment,
    career_growth, work_life_balance, team_culture
  - Open-ended: most_liked, most_disliked, suggestions, additional_notes
  - Status: `DRAFT → COMPLETED` atau `SKIPPED`

- Tabel baru `compliance_report_runs` — cache hasil generate:
  - `report_type` ENUM (8 types)
  - `period_year`, `period_month`
  - `parameters_json`, `result_json`
  - `expires_at` — TTL default 24 jam
  - `export_format` (JSON / CSV / XLSX / PDF)

- 9 permissions baru:
  - `termination.view`, `.manage`, `.approve`, `.execute`
  - `exit_interview.view`, `.manage`
  - `employee.view_360`
  - `compliance.report.view`, `.export`

- Extend `contract_templates.contract_type` ENUM dengan `PHK_LETTER`
  (counterpart dari `PAKLARING` & `OFFER_LETTER` yang sudah ada)

### Services baru (4 files)

**`backend/app/Libraries/TerminationService.php`** — PHK workflow orchestrator
- `create($data, $userId)` — DRAFT dengan validasi reason_code
- `update($id, $data, $userId)` — hanya DRAFT yang bisa diedit
- `submit($id, $userId)` — DRAFT → next stage (cek `requiresBipartite()`)
- `recordBipartite($id, $userId, $outcome, $minutes)` — track outcome
  AGREED / DISAGREED / PENDING_TRIPARTITE
- `approveStep($id, $userId, $stage, $note)` — HR_DIRECTOR / BOD approve
- `reject($id, $userId, $stage, $reason)` — REJECT di stage tertentu
- `cancel($id, $userId, $reason)` — sebelum EXECUTED
- `execute($id, $userId)` — final step: auto-create severance DRAFT,
  generate PHK letter, set employees.resign_date, trigger offboarding tasks,
  auto-create MPP DRAFT (kalau bukan PHK efisiensi kolektif)
- `requiresBipartite($row)` — pure helper, FALSE untuk MUTUAL,
  CONTRACT_END, RETIREMENT, DEATH, DISABILITY, atau reason_code di
  NO_BIPARTITE_REASONS (VIOLATION_GROSS, LONG_ABSENT, dll)
- `requiresBodApproval($row)` — TRUE kalau preview pesangon > 100jt
  (BOD_THRESHOLD_DEFAULT, future bisa override via settings)
- `previewSeverance($row)` — pakai `SeveranceCalculator` untuk decision
- `nextReferenceNumber($db, $companyId)` — format `PHK-{YEAR}-{5digit}`

**`backend/app/Libraries/ExitInterviewService.php`** — exit interview manager
- `create($data, $userId)` — link ke resignation/termination, idempotent
- `update($id, $data, $userId)` — hanya DRAFT, validasi range score
- `complete($id, $userId)` — wajib would_rehire & primary_reason terisi
- `skip($id, $userId, $reason)` — status SKIPPED kalau karyawan refuse
- `analytics($companyId, $from, $to)` — aggregate untuk HR dashboard:
  - would_rehire breakdown
  - primary_reasons (sorted desc by count)
  - avg satisfaction & recommend
  - avg per Likert dimension
  - by department breakdown
- `computeNps($scores)` — pure helper. NPS = %promoter (9-10) -
  %detractor (0-6). Range -100 sampai 100.
- `PRIMARY_REASON_OPTIONS` — 13 reason codes baseline (BETTER_OFFER,
  CAREER_GROWTH, WORK_LIFE_BALANCE, MANAGEMENT_ISSUE, dll)

**`backend/app/Libraries/Employee360Service.php`** — aggregator profile lengkap
- `build($employeeId)` — return 12 section di 1 method:
  1. profile (basic info)
  2. employment (join date, contract, probation, supervisor chain)
  3. compensation (basic_salary, fixed_allowance, bank/NPWP masked)
  4. leave_summary (saldo + 5 last leaves)
  5. attendance (month-to-date stats)
  6. payroll_ytd (YTD gross, net, PPh21, BPJS)
  7. performance (last review + active goals + current cycle)
  8. training (completed last year + active enrollments)
  9. assets (assigned + history count)
  10. disciplinary (active count + highest level + recent)
  11. documents (contract & document count)
  12. lifecycle (onboarding/offboarding progress + active resign/termination)
- Setiap section di-isolate dengan try-catch defensive
- `humanizeTenure($months)` — pure helper untuk format "2 tahun 3 bulan"

**`backend/app/Libraries/ComplianceReportService.php`** — generator laporan kepatuhan
- `generate($companyId, $reportType, $year, $month, $parameters, $userId, $forceRefresh)` —
  cache-aware, default TTL 24 jam
- 8 report generators:
  1. `WLKP_ANNUAL` — Wajib Lapor Ketenagakerjaan (UU 7/1981): HC, gender,
     age groups, WNA count, hires/exits per year, dept breakdown
  2. `BPJS_MONTHLY` — list aktif + estimasi iuran (Kesehatan & TK)
     untuk reconciliation e-Dabu/SiPP
  3. `PKWT_EXPIRING` — PKWT akan habis dalam X hari (default 60)
  4. `PHK_LIST` — gabungan resignation (voluntary) + termination
     dalam periode dengan reason breakdown
  5. `AUDIT_SUMMARY` — count audit log per severity & module
  6. `PROBATION_OVERDUE` — PKWTT lewat probation_end_date tapi belum
     diputuskan (HIRE-1)
  7. `BPJS_OVERDUE` — delegate ke `OffboardingService::findBpjsRegisterOverdue()`
  8. `TURNOVER_SUMMARY` — turnover rate, retention, monthly trend
- `toCsv($rows, $columns)` — convert ke CSV untuk export download

### Controllers baru (4 files)

**`backend/app/Controllers/Api/V1/TerminationController.php`** — 12 endpoint:
```
GET    /terminations                          — list dengan filter
GET    /terminations/options                  — types + statuses + reasons
POST   /terminations                          — create DRAFT
GET    /terminations/(:num)                   — detail + severance preview
PUT    /terminations/(:num)                   — update DRAFT
DELETE /terminations/(:num)                   — soft-delete (DRAFT only)
POST   /terminations/(:num)/submit            — DRAFT → next stage
POST   /terminations/(:num)/bipartite         — record outcome + minutes
POST   /terminations/(:num)/approve           — HR_DIRECTOR / BOD
POST   /terminations/(:num)/reject            — HR_DIRECTOR / BOD
POST   /terminations/(:num)/cancel            — sebelum EXECUTED
POST   /terminations/(:num)/execute           — APPROVED → EXECUTED
GET    /terminations/(:num)/letter?format=html|pdf — render surat PHK
```

**`backend/app/Controllers/Api/V1/ExitInterviewController.php`** — 9 endpoint:
```
GET    /exit-interviews                       — list dengan filter
GET    /exit-interviews/options               — modes, rehire, primary_reasons
GET    /exit-interviews/analytics             — aggregate dashboard + eNPS
POST   /exit-interviews                       — create (link resign/term)
GET    /exit-interviews/(:num)                — detail
PUT    /exit-interviews/(:num)                — update DRAFT
DELETE /exit-interviews/(:num)                — soft-delete
POST   /exit-interviews/(:num)/complete       — DRAFT → COMPLETED
POST   /exit-interviews/(:num)/skip           — DRAFT → SKIPPED
```

**`backend/app/Controllers/Api/V1/Employee360Controller.php`** — 1 endpoint:
```
GET    /employees/(:num)/360                  — full 360 view
```

Authorization rules:
- HR/manager dengan `employee.view_360`
- Karyawan ybs auto-allowed (transparency)
- Supervisor/manager bisa lihat 360 dari direct report-nya
- Audit log severity NOTICE setiap akses (UU PDP)

**`backend/app/Controllers/Api/V1/ComplianceReportController.php`** — 5 endpoint:
```
GET   /compliance-reports/types               — list available reports
GET   /compliance-reports/runs                — history hasil generate
POST  /compliance-reports/generate            — trigger generate
GET   /compliance-reports/runs/(:num)         — load cached result
GET   /compliance-reports/runs/(:num)/csv     — download CSV (throttle:export)
```

### Modifikasi

- `backend/app/Config/Services.php` — register 4 service baru: `termination()`,
  `exitInterview()`, `employee360()`, `complianceReport()`
- `backend/app/Config/Routes.php` — registrasi 27 endpoint baru di group
  authenticated. CSV export pakai filter `throttle:export` (sama bucket
  dengan endpoint export berat lain).

### Tests

- `backend/tests/Unit/Sprint4TerminationPolishTest.php` — 25 unit tests / 64 assertions
  - `TerminationService::requiresBipartite()` rules (7 tests)
  - Status enum sanity (3 tests)
  - `ExitInterviewService::computeNps()` formula (7 tests)
  - Constants sanity (3 tests)
  - `Employee360Service::humanizeTenure()` (5 tests)

---

## Cara Pakai

### PHK Formal Workflow (OFF-5)

Skenario: Karyawan ID #42 mau di-PHK dengan alasan efisiensi setelah
pertemuan bipartite gagal.

1. **HR initiate PHK draft:**
   ```
   POST /api/v1/terminations
   {
     "employee_id": 42,
     "termination_type": "UNILATERAL",
     "reason_code": "EFFICIENCY_LOSS",
     "reason_detail": "Penurunan revenue Q1 2026 mengharuskan pengurangan headcount...",
     "planned_termination_date": "2026-08-31"
   }
   ```
   → Sistem auto-generate `reference_number: PHK-2026-00001`, status DRAFT.

2. **Submit → karena UNILATERAL + EFFICIENCY_LOSS bukan pelanggaran berat,
   masuk PENDING_BIPARTITE:**
   ```
   POST /api/v1/terminations/{id}/submit
   ```

3. **Setelah pertemuan bipartite, record outcome:**
   ```
   POST /api/v1/terminations/{id}/bipartite
   {
     "outcome": "AGREED",
     "minutes": "Pertemuan bipartite tanggal 26 Mei 2026 dihadiri karyawan, supervisor, HR..."
   }
   ```
   → Status → `PENDING_HR_DIRECTOR`. Kalau DISAGREED → `tripartite_required = 1`,
   status tetap PENDING_BIPARTITE (HR escalate manual ke disnaker).

4. **HR Director approve (preview pesangon < 100jt → langsung APPROVED):**
   ```
   POST /api/v1/terminations/{id}/approve
   { "stage": "HR_DIRECTOR", "note": "Sesuai rekomendasi rapat manajemen" }
   ```
   → Kalau preview > 100jt, status → `PENDING_BOD`, BOD harus approve dulu.

5. **HR execute final step:**
   ```
   POST /api/v1/terminations/{id}/execute
   ```
   → Otomatis:
   - Create severance_calculations DRAFT dengan multiplier sesuai PP 35/2021
   - Generate PHK letter (linked di `phk_letter_id`)
   - Set employees.resign_date = planned_termination_date
   - Trigger offboarding tasks dari template OFFBOARD
   - Auto-create MPP DRAFT pengganti (kalau bukan efisiensi kolektif)
   - Audit log severity CRITICAL

6. **Download surat PHK:**
   ```
   GET /api/v1/terminations/{id}/letter?format=pdf
   ```

### Exit Interview (OFF-1)

Skenario: HR conduct exit interview setelah resignation #15 disetujui.

1. **Create interview session:**
   ```
   POST /api/v1/exit-interviews
   {
     "resignation_id": 15,
     "mode": "IN_PERSON"
   }
   ```

2. **HR isi data setelah sesi:**
   ```
   PUT /api/v1/exit-interviews/{id}
   {
     "would_rehire": "YES",
     "primary_reason": "BETTER_OFFER",
     "secondary_reasons": ["WORK_LIFE_BALANCE"],
     "satisfaction_score": 8,
     "recommend_score": 9,
     "score_compensation": 4,
     "score_management": 5,
     "score_career_growth": 3,
     "most_liked": "Tim yang supportive...",
     "most_disliked": "Beban kerja overtime tidak sustainable",
     "suggestions": "Pertimbangkan flexible working hours..."
   }
   ```

3. **Finalize:**
   ```
   POST /api/v1/exit-interviews/{id}/complete
   ```
   → Validasi: would_rehire dan primary_reason wajib terisi.

4. **HR dashboard:**
   ```
   GET /api/v1/exit-interviews/analytics?date_from=2026-01-01&date_to=2026-12-31
   ```
   → Return summary: total_completed, would_rehire breakdown, primary
   reasons (sorted), avg satisfaction, avg recommend, eNPS, by_department.

### Employee 360 View (CC-4)

```
GET /api/v1/employees/42/360
```

Return 12 section dalam 1 round-trip. UI tinggal tab antara section.
Bank account & NPWP di-mask supaya tidak fully expose lewat endpoint
ini (HR yang butuh full data harus akses payroll detail dengan audit
trail terpisah).

### Compliance Reports (CC-5)

```
POST /api/v1/compliance-reports/generate
{
  "report_type": "WLKP_ANNUAL",
  "year": 2026
}
```

Return cached result (kalau ada) atau generate fresh + insert ke
`compliance_report_runs`. TTL 24 jam.

```
GET /api/v1/compliance-reports/runs/{id}/csv
```

Download CSV (throttle:export).

8 report types:
- `WLKP_ANNUAL` — yearly report Kemnaker
- `BPJS_MONTHLY` — bulanan reconciliation
- `PKWT_EXPIRING` — kontrak akan habis (param `days_ahead`)
- `PHK_LIST` — daftar PHK + resignation per periode
- `AUDIT_SUMMARY` — log severity per module
- `PROBATION_OVERDUE` — probation belum diputuskan
- `BPJS_OVERDUE` — register overdue
- `TURNOVER_SUMMARY` — turnover rate + monthly trend

---

## Verifikasi

```bash
cd backend

# Backend tests — 25 baru, 0 regresi
vendor/bin/phpunit --filter Sprint4TerminationPolishTest --no-coverage
# OK (25 tests, 64 assertions)

# Full suite
vendor/bin/phpunit --no-coverage
# OK, but there were issues!  (1 pre-existing warning, bukan dari Sprint 4)
# Tests: 557, Assertions: 1293, Warnings: 1.

# Audit call scanner
php scripts/find_bad_audit_calls.php
# Tidak ada panggilan shorthand keliru. Aman.

# Migration baru
php spark migrate
# Run migration 54: TerminationAndPolishSprint4
```

---

## Belum Dikerjakan (di-defer)

**Frontend UI** untuk Sprint 4 tidak masuk scope karena prioritas wiring
backend. API sudah siap pakai dari Postman / curl. Yang perlu di-build di
sprint frontend berikutnya:

1. **HR → Termination Board** (`Termination/TerminationBoard.vue`):
   - Kanban view per status: DRAFT, PENDING_BIPARTITE,
     PENDING_HR_DIRECTOR, PENDING_BOD, APPROVED, EXECUTED
   - Form initiate PHK dengan dropdown reason_code + preview pesangon real-time
   - Form bipartite dengan upload notulen
   - Action buttons sesuai stage caller (approve/reject/execute)

2. **HR → Exit Interview Form** (`HR/ExitInterviewForm.vue`):
   - Form isi multi-step (basic info → scores → open-ended)
   - Generate self-fill link untuk karyawan kalau mode SELF_FILL
   - Analytics dashboard view dengan chart breakdown

3. **Employee Profile 360** (`Employees/EmployeeProfile360.vue`):
   - Tab navigation antar 12 section
   - Mini-cards untuk quick stats di top
   - Link cross ke modul detail (klik leave row → detail leaves, dll)

4. **HR → Compliance Reports** (`Reports/Compliance.vue`):
   - Picker report type + period
   - Generate button, view cached result inline
   - Export CSV button (per row + per report)
   - History list dengan TTL countdown

**OFF-5 enhancement** yang bisa di-tambahkan:
- Webhook outbound `termination.executed` untuk integrasi sistem external
  (mis. kirim ke payroll bank gateway saat severance.PAID)
- Bulk PHK kolektif (1 transaction multi karyawan untuk efisiensi)
- Auto-link disciplinary records: kalau employee punya SP3 ACTIVE,
  auto-suggest reason_code = VIOLATION_GROSS

**Future sprints** (Low priority dari `LIFECYCLE_AUDIT_2026.md`):
- MPP-1 Budget periods linkage
- OD-3 Succession planning
- REC-3 MCU integration
- OFF-3 Final settlement payroll merge fully (saat ini masih manual link)
- ONB-2 Buddy / mentor system

---

## Catatan Teknis

- Semua tabel baru pakai pattern soft-delete + audit timestamps konsisten
  dengan tabel existing
- Migration 54 idempotent (`tableExists` check) — aman re-run
- Permissions baru di-grant ke role 1 (super admin), 2 (HR admin), dan
  selektif role 3 (manager) untuk view-only
- Validasi UU di backend: `SeveranceCalculator::REASONS` adalah single
  source of truth untuk reason code → multiplier mapping
- PHK letter pakai pattern fallback HTML (kalau template `PHK_LETTER`
  belum ada di company), sama seperti paklaring di Sprint 1
- Audit severity:
  - `termination.execute` = CRITICAL (involve dana + kewajiban hukum)
  - `termination.approve_*`, `.submit`, `.bipartite_DISAGREED` = WARNING
  - `compliance.report_export` = NOTICE (data PII di-export)
  - `employee.view_360` = NOTICE (akses PII komprehensif, UU PDP)

---

**Disusun oleh:** Cline (AI pair programmer)
**Tanggal:** 26 Mei 2026
**Sprint sebelumnya:** `docs/SPRINT_3_OD_LOOP.md`
**Next:** Frontend UI untuk Sprint 4 (jika di-prioritaskan), atau low-priority
items dari `LIFECYCLE_AUDIT_2026.md` (MPP-1, OD-3, REC-3, dll).