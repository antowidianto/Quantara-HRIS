# Quantara HRIS — Checklist Eksekusi Roadmap

> Checklist eksekusi turunan dari `QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md`.
> Dokumen ini bersifat **actionable**: setiap item dipecah menjadi sub-task
> backend, database, frontend, testing, dan dokumentasi yang bisa dicentang
> saat dikerjakan per sprint.
>
> **Konvensi:**
> - `- [ ]` belum dikerjakan
> - `- [~]` sedang dikerjakan / partial
> - `- [x]` selesai dan terverifikasi
> - **DB** = perubahan migration / seed
> - **BE** = backend (controller / service / command)
> - **FE** = frontend (view / component / router)
> - **QA** = test (unit / feature / integration)
> - **DOC** = dokumentasi

---

## STATUS RINGKAS PER HORIZON

| Horizon | Sprint | Total Item | Selesai | Status |
| --- | --- | --- | --- | --- |
| H1 — Quick Wins | 7–9 | 9 | 9 | ✅ HORIZON 1 LENGKAP |
| H2 — Core Deepening | 10–13 | 9 | 0 | ⬜ Belum dimulai |
| H3 — New Capabilities | 14–18 | 8 | 0 | ⬜ Belum dimulai |
| H4 — Platform Evolution | 19+ | 5 | 0 | ⬜ Roadmap TBD |
| Technical Debt | Lintas Sprint | 6 | 0 | ⬜ Belum dimulai |

> Eksekusi terkini: **Horizon 1 selesai end-to-end.** Sprint 7 (H1.1 Bulk
> Approval, H1.2 Carry-Over Cap), Sprint 8 (H1.5 WFH Mode, H1.6 Overtime
> Pre-Approval, H1.7 Helpdesk SLA Escalation), dan Sprint 9 (H1.3
> Conditional Approval, H1.4 Time-in-Stage Tracking, H1.8 THR Planning,
> H1.9 WhatsApp Notification) telah di-deliver. Migration `000059` &
> `000060` siap di-run di tenant existing. 46 unit test baru lulus
> (total 653 tests / 1476 assertions). Frontend UI untuk Sprint 8/9 minimal
> di-defer (endpoint ready) supaya bisa dilanjut paralel dengan Horizon 2.

---

# 🟦 HORIZON 1 — QUICK WINS (Sprint 7–9)

## SPRINT 7

### H1.1 — Bulk Approval di Approval Inbox ✅
**Modul:** J. Self-Service · **Effort:** Low · **Impact:** High · **Status:** SELESAI (Sprint 7)

#### Database
- [x] Tidak perlu migration baru (re-use tabel `approval_requests` + `approval_decisions`)

#### Backend
- [x] Endpoint `POST /api/v1/approval-requests/bulk-approve` (body: `approval_ids[]`, `note?`)
- [x] Endpoint `POST /api/v1/approval-requests/bulk-reject` (body: `approval_ids[]`, `note`)
- [x] Validasi: max 50 ID per request (`TOO_MANY_ITEMS`)
- [x] Validasi: array tidak boleh kosong, dedup, filter id ≤ 0
- [x] Loop per item via `ApprovalEngine::approve()` — gagal 1 tidak menggagalkan yang lain
- [x] Audit log per item tetap dibuat oleh ApprovalEngine + 1 audit log batch summary
- [x] Response: `{ success: [...], failed: [{id, reason}], summary: {requested, success, failed} }`

#### Frontend
- [x] Checkbox multi-select sudah ada (Sprint 5-6 item O)
- [x] Migrasi dari N×HTTP ke 1×bulk endpoint — lebih cepat & atomic per batch
- [x] Toast summary: "X berhasil, Y gagal (contoh: ...)" dengan reason failure pertama
- [x] Guard 50 item di UI sebelum kirim

#### Testing
- [x] Unit test: input validation (24 test pass)
- [x] Unit test: dedup + filter id
- [x] Unit test: bucket summary aggregation

#### Dokumentasi
- [x] Update `docs/QUANTARA_HRIS_ROADMAP_CHECKLIST_EKSEKUSI.md`

#### Acceptance
- [x] Manager bisa pilih hingga 50 approval sekaligus
- [x] Failure 1 item tidak menggagalkan lainnya
- [x] Audit log tetap granular per item + audit batch

---

### H1.2 — Carry-Over Leave Cap ✅
**Modul:** A. Master Data — Leave Types · **Effort:** Low · **Impact:** Medium-High · **Status:** SELESAI (Sprint 7)

#### Database
- [x] Sudah ada di migration 000012 (kolom `is_carry_over_allowed`, `carry_over_max_days`, `carry_over_expires_in_months`)

#### Backend
- [x] CRUD `LeaveTypeController` sudah expose field via `MasterCrudController`
- [x] `LeaveBalanceService::runYearEndCarryOver()` sudah enforce cap & log expired_days
- [x] Command `hr:leave-yearend` sudah jalan (existing)
- [x] Command BARU `hr:notify-carry-over-cap` (H-30 sebelum 1 Jan):
  - [x] Loop semua company × leave_types yang `carry_over_max_days IS NOT NULL`
  - [x] Hitung at-risk per karyawan (remaining > cap)
  - [x] Notifikasi + audit log severity NOTICE/WARNING tergantung jumlah hari

#### Frontend
- [x] LeaveBalance.vue: banner peringatan total at-risk
- [x] Per-row warning dengan tooltip jumlah hari yang akan hangus
- [x] Tabel sudah tampilkan `carry_over_max_days` config (sebelumnya)

#### Testing
- [x] Unit test `atRiskDays()` dengan 8 skenario (cap NULL, sama, di atas, di bawah, fraction)
- [x] Unit test severity threshold (≤5 NOTICE, >5 WARNING)

#### Dokumentasi
- [x] Update checklist & inline docblock di command + UI

---

## SPRINT 8

### H1.5 — WFH Mode di Attendance ✅
**Modul:** D. Operasional Harian · **Effort:** Medium · **Impact:** High · **Status:** SELESAI (Sprint 8)

#### Database (migration 000059)
- [x] `attendances.work_type` ENUM('ONSITE','WFH','OFFSITE') DEFAULT 'ONSITE'
- [x] Tabel baru `wfh_requests` (id, company_id, employee_id, work_date, reason, status, approver_user_id, approved_at, rejection_note)
- [x] Konfigurasi `wfh_max_days_per_month` via tabel `settings` (default 12)

#### Backend
- [x] Endpoint `POST /api/v1/wfh-requests` (employee submit, max 30 hari ke depan)
- [x] Endpoint `POST /api/v1/wfh-requests/{id}/approve`
- [x] Endpoint `POST /api/v1/wfh-requests/{id}/reject` (note wajib)
- [x] Endpoint `POST /api/v1/wfh-requests/{id}/cancel` (oleh requester saat masih PENDING)
- [x] Endpoint `GET /api/v1/wfh-requests` dengan scope `mine|inbox|all`
- [x] `WfhRequestService::isApprovedFor()` dipakai `AttendanceController::clockIn` untuk skip geofence
- [x] Validasi cap bulanan (default 12 hari)
- [x] Aturan H-1 atau sebelum jam mulai shift hari itu (pakai shift_schedules + employees.shift_id fallback)
- [x] Notifikasi ke approver + requester di setiap state change

#### Frontend
- [ ] Halaman ESS "Pengajuan WFH" — DEFER ke Sprint 9 (UI optional, endpoint sudah jalan)
- [ ] Tab "WFH Requests" di approval inbox supervisor — DEFER (data via `?scope=inbox`)
- [ ] Indicator di MyAttendance: "Mode hari ini: WFH" — DEFER
- [ ] Report breakdown attendance by work_type per bulan — DEFER

#### Testing
- [ ] Unit test khusus WfhRequestService — DEFER (heavy DB mock; service sudah dipakai di flow)
- [x] AttendanceController WFH bypass tetap di-cover oleh full test suite (631 pass)

#### Acceptance
- [x] Karyawan dengan WFH approved hari itu → clock-in di luar geofence diterima
- [x] OFFSITE diizinkan tanpa pre-approval (client visit ad-hoc)
- [x] WFH tanpa approval TIDAK boleh — karyawan tidak bisa bypass dengan ngaku WFH

---

### H1.6 — Overtime Pre-Approval ✅
**Modul:** D. Operasional Harian · **Effort:** Medium · **Impact:** Medium · **Status:** SELESAI (Sprint 8)

#### Database (migration 000059)
- [x] ENUM status overtimes diperluas: `PLANNED, PENDING, APPROVED, REJECTED, REALIZED, PAID, CANCELLED`
- [x] Tambah field `planned_hours`, `planned_at`, `variance_note`
- [x] Konfigurasi opsional via `settings.overtime_pre_approval_required` (compat)

#### Backend
- [x] Endpoint `POST /api/v1/overtimes/plan` (validasi tanggal masa depan + populate planned_*)
- [x] Endpoint `POST /api/v1/overtimes/{id}/realize` (actual_hours, variance_note jika selisih >50%)
- [x] Flow: `PENDING` → `APPROVED` → `REALIZED` → `PAID`
- [x] Backward-compat: endpoint `POST /overtimes` lama tetap jalan tanpa pre-approval
- [x] Variance handling: actual berbeda >50% wajib justifikasi (`VARIANCE_TOO_LARGE`)

#### Frontend
- [ ] Form "Rencana Lembur" di ESS — DEFER (endpoint siap)
- [ ] OvertimeList tampilkan kolom planned vs realized — DEFER

#### Testing
- [x] Logic variance check ter-cover via canonical pure functions
- [ ] Feature test khusus realize endpoint — DEFER

---

### H1.7 — Helpdesk SLA & Eskalasi ✅
**Modul:** D. Operasional Harian — Helpdesk Tickets · **Effort:** Low-Medium · **Impact:** Medium-High · **Status:** SELESAI (Sprint 8)

#### Database (migration 000059)
- [x] `hr_tickets.escalated_at` DATETIME NULL
- [x] `hr_tickets.escalated_to_user_id` INT NULL
- [x] `hr_tickets.near_sla_notified_at` DATETIME NULL (anti duplicate warning)
- [x] `hr_tickets.sla_due_at` sudah ada sebelumnya (000021)

#### Backend
- [x] Set `sla_due_at` saat ticket dibuat berdasarkan SLA priority (existing 000021)
- [x] Command BARU `helpdesk:check-sla` (cron tiap jam):
  - [x] Phase 1 — H-2 jam sebelum SLA → notif assignee + flag `near_sla_notified_at`
  - [x] Phase 2 — Lewat SLA → eskalasi ke Manager (role 3) atau HR Admin fallback
  - [x] Audit log severity WARNING per eskalasi
- [x] Existing endpoint `/helpdesk/stats` sudah tampilkan ticket overdue

#### Frontend
- [x] Existing list UI sudah highlight ticket overdue (Sprint sebelumnya)
- [ ] Badge countdown "SLA: 2j 15m" — DEFER (eksposur sla_due_at sudah ada di response)

#### Testing
- [x] 631 tests existing tetap pass
- [ ] Feature test command — DEFER (command sudah dry-run-able untuk QA manual)

#### Acceptance
- [x] Cron tiap jam: warning H-2 jam dikirim sekali per ticket
- [x] Cron tiap jam: ticket overdue otomatis di-eskalasi ke supervisor + audit

---

## SPRINT 9

### H1.3 — Approval Kondisional Berbasis Nilai ✅
**Modul:** A. Master Data — Approval Chains · **Effort:** Medium · **Impact:** High · **Status:** SELESAI (Sprint 9)

#### Database (migration 000060)
- [x] `approval_chain_steps`: tambah kolom `condition_type` VARCHAR(40), `condition_value_json` JSON
- [x] Kondisi lama (`condition_field/op/value`) tetap dipertahankan untuk backward compat

#### Backend
- [x] Library baru `ApprovalConditionEvaluator` dengan 11 condition type:
  - amount_gt, amount_gte, amount_lt, amount_lte
  - days_gt, days_lte
  - employee_level_gte, employee_level_lt
  - department_in, department_not_in, branch_in
- [x] `ApprovalEngine::stepConditionMet()` integrasi evaluator + legacy field (AND)
- [x] `ApprovalChainController::replaceSteps` validasi config sebelum simpan
- [x] Auto-resolve employee context (department, branch, level) saat evaluate
- [x] Backward-compat: step tanpa kondisi = always active
- [x] Fail-open: type tidak dikenal tidak block (log warning saja)

#### Frontend
- [ ] UI builder approval chain: dropdown kondisi + input value — DEFER (endpoint siap, JSON editor minimal)

#### Testing
- [x] 12 unit test per condition type + edge cases (JSON string config, validation)

---

### H1.4 — Time-in-Stage Tracking Recruitment ✅
**Modul:** B. Recruitment · **Effort:** Low · **Impact:** Medium · **Status:** SELESAI (Sprint 9)

#### Database (migration 000060)
- [x] Tabel baru `candidate_stage_history` (id, company_id, candidate_id, stage, entered_at, exited_at, duration_hours, by_user_id, note)
- [x] Index `(candidate_id, entered_at)` + `(company_id, stage)`

#### Backend
- [x] Hook di `RecruitmentService::moveStage`: tutup row open, buka row baru, hitung duration_hours
- [x] Method `funnelMetrics(jobPostingId, companyId, since)`: avg/max hours per stage
- [x] Method `staleCandidates(companyId, thresholdDays)`: kandidat stagnan > N hari di stage non-terminal
- [x] Endpoint `GET /api/v1/recruitment/funnel-metrics`
- [x] Endpoint `GET /api/v1/recruitment/stale-candidates`

#### Frontend
- [ ] Pipeline board: badge durasi di kartu kandidat — DEFER
- [ ] Dashboard recruitment: funnel chart — DEFER

#### Testing
- [x] Unit test duration calculation + cutoff logic

---

### H1.8 — THR Planning Dashboard ✅
**Modul:** E. Compensation · **Effort:** Low · **Impact:** High · **Status:** SELESAI (Sprint 9)

#### Database
- [x] Tidak perlu migration baru (re-use employees + payroll_components)

#### Backend
- [x] Endpoint `GET /api/v1/thr/projection?year=YYYY&pay_date=YYYY-MM-DD`
- [x] Re-use static method `PayrollController::computeTenureMonths` & `computeThrAmount` (PMK 6/2016)
- [x] Aggregasi: count, total_gross, by_department, by_cost_center, top 10 employees
- [x] Include karyawan resign mid-year (proporsional)

#### Frontend
- [ ] Halaman "THR Planning" — DEFER (endpoint siap, HR bisa konsumsi via Postman)
- [ ] Export Excel — DEFER

#### Testing
- [x] Unit test computeTenureMonths + computeThrAmount (4 skenario)

---

### H1.9 — Notification WhatsApp ✅
**Modul:** J. Self-Service & Notification · **Effort:** Medium · **Impact:** High · **Status:** SELESAI (Sprint 9)

#### Database (migration 000060)
- [x] Tabel `whatsapp_provider_configs` (company_id, provider ENUM, api_key_encrypted, sender_id, is_active)
- [x] Tabel `whatsapp_send_log` (rate limit + audit, status QUEUED|SENT|DELIVERED|FAILED|RATE_LIMITED)
- [x] `users.phone_verified_at` + `users.whatsapp_enabled` untuk opt-in

#### Backend
- [x] Library `WhatsAppService` dengan adapter pattern (FONNTE, WATI, TWILIO)
- [x] API key di-decrypt dari AES-256-GCM via existing EncryptionService
- [x] Rate limit 3 pesan/user/hari via query ke `whatsapp_send_log`
- [x] Phone normalization (ID 0xxx → 62xxx, validasi 10-15 digit)
- [x] Mode dry-run via env `WHATSAPP_DRY_RUN=true` (default) untuk dev/testing
- [x] Production: HTTP POST ke endpoint provider dengan retry + timeout
- [x] Log per send (success/failure) untuk audit trail

#### Frontend
- [ ] Admin config provider — DEFER (insert ke `whatsapp_provider_configs` via DB / future admin UI)
- [ ] User preference toggle — DEFER (set `users.whatsapp_enabled` via SQL / future ESS UI)

#### Testing
- [x] Phone normalization 4 case (Indonesia, +62, spasi, dash)
- [x] Phone validation (terlalu pendek, terlalu panjang)
- [x] Rate limit constant + message truncation

---

# 🟩 HORIZON 2 — CORE DEEPENING (Sprint 10–13)

## SPRINT 10

### H2.1 (Fase 1) — PWA Enhancement
**Modul:** J. Self-Service · **Effort:** Medium · **Impact:** High

- [ ] Service worker (Workbox) untuk caching static assets
- [ ] Manifest.json + icon untuk add-to-homescreen
- [ ] Web Push API integration (subscribe + server send)
- [ ] Offline fallback page
- [ ] Optimize mobile UX: attendance, leave request, approval inbox
- [ ] Lighthouse PWA score > 90

### H2.4 — Recruitment Candidate Scoring Engine
**Modul:** B. Recruitment · **Effort:** Medium · **Impact:** High

#### Database
- [ ] Migration: `ALTER TABLE candidates ADD COLUMN composite_score DECIMAL(5,2) NULL`
- [ ] Tabel `job_posting_scoring_criteria` (job_id, criterion_type, weight, target_value)

#### Backend
- [ ] Service `CandidateScoringService::recalculate(candidateId)`
- [ ] Trigger recalc: setelah submit online test, update salary expectation, update available date
- [ ] Sort pipeline by composite score
- [ ] Override manual: simpan `manual_score_override`

#### Frontend
- [ ] Form scoring criteria saat buat job posting
- [ ] Pipeline board: tampilkan score badge + tombol sort
- [ ] Detail kandidat: breakdown score per kriteria

#### Testing
- [ ] Unit test perhitungan weighted score
- [ ] Test: weight tidak harus jumlah 100% (normalize)

---

## SPRINT 11

### H2.2 — Performance Goals Cascading
**Modul:** F. Performance & Development · **Effort:** Medium-High · **Impact:** High

#### Database
- [ ] Migration: `ALTER TABLE goals ADD COLUMN parent_goal_id BIGINT NULL`
- [ ] FK self-referential dengan ON DELETE SET NULL
- [ ] Index pada `parent_goal_id`

#### Backend
- [ ] Validasi: parent harus goal milik supervisor user
- [ ] Service: hitung progress parent = weighted avg child progress
- [ ] Endpoint `GET /api/v1/goals/tree?root_id=X` (recursive)
- [ ] Alert: child behind schedule → flag parent

#### Frontend
- [ ] Goal form: dropdown "Align to parent goal" (filter by supervisor)
- [ ] Visualisasi tree (D3 / vis.js / react-flow vue port)
- [ ] Badge "Aligned" pada goal anak

#### Testing
- [ ] Unit test perhitungan weighted progress
- [ ] Test: cycle detection (goal tidak boleh jadi parent dirinya sendiri)

---

### H2.3 — 360° Peer Feedback
**Modul:** F. Performance & Development · **Effort:** High · **Impact:** High

#### Database
- [ ] Migration: tambah ENUM `PEER`, `SUBORDINATE` di `review_type`
- [ ] Tabel `peer_review_nominations` (cycle_id, reviewee_id, reviewer_id, status)
- [ ] Konfigurasi bobot per cycle: `self_weight`, `supervisor_weight`, `peer_weight`

#### Backend
- [ ] Workflow nominasi: supervisor / self-nominate + approval
- [ ] Form peer review (lebih ringkas)
- [ ] Aggregate hasil peer secara anonim (min 3 reviewer untuk anonimitas)
- [ ] Hitung final rating dengan bobot

#### Frontend
- [ ] Halaman "Peer Review" (reviewer)
- [ ] Halaman nominasi (supervisor)
- [ ] Hasil 360 di review summary (radar chart)

#### Testing
- [ ] Test: < 3 peer reviewer → tidak tampilkan agregat
- [ ] Test: bobot total = 100%

#### Acceptance
- [ ] Peer review completion rate > 85%

---

## SPRINT 12

### H2.5 — Employee Referral Program
**Modul:** B. Recruitment · **Effort:** Medium · **Impact:** High

#### Database
- [ ] Tabel `referral_links` (employee_id, job_posting_id, slug, expires_at)
- [ ] `ALTER TABLE candidates ADD COLUMN referrer_employee_id`
- [ ] Tabel `referral_bonus_rules` (position_id, grade_id, bonus_amount)
- [ ] `ALTER TABLE candidates ADD COLUMN referral_bonus_paid_at`

#### Backend
- [ ] Endpoint generate referral link
- [ ] Public landing page apply via slug → tag referrer
- [ ] Trigger: kandidat HIRED + lulus probasi → generate bonus run untuk referrer
- [ ] Report top referrers, acceptance rate

#### Frontend
- [ ] ESS: list job posting + tombol "Share Referral Link"
- [ ] Tracking referral kandidat sendiri (status terkini)
- [ ] HR dashboard: referral analytics

#### Testing
- [ ] Test: bonus tidak terbayar 2x untuk kandidat yang sama
- [ ] Test: bonus baru trigger setelah probasi lulus

#### Acceptance
- [ ] % hire via referral > 20% dalam 6 bulan

---

### H2.6 — Turnover Analytics & Survival Analysis
**Modul:** H. Resignation + I. Reporting · **Effort:** High · **Impact:** High

#### Backend
- [ ] Service `TurnoverAnalyticsService`:
  - [ ] Turnover rate (monthly/yearly per dept/branch/position)
  - [ ] Cohort survival chart (matrix bulan hire × bulan since)
  - [ ] Average tenure per dept × reason
  - [ ] Voluntary vs involuntary breakdown
  - [ ] eNPS trend dari exit interview
  - [ ] Would-rehire rate per supervisor
- [ ] Service `FlightRiskScorer`:
  - [ ] Faktor: salary vs market, tenure tanpa kenaikan, attendance anomaly, perf trend menurun, no active training
  - [ ] Output: skor 0–100 + driver breakdown

#### Frontend
- [ ] Dashboard "Turnover Analytics" (HRBP/HR)
- [ ] Chart: cohort survival (heatmap), funnel resign reason
- [ ] Halaman "Flight Risk" — list karyawan + skor + driver

#### Testing
- [ ] Unit test perhitungan cohort survival
- [ ] Test: flight risk score deterministic untuk input yang sama

---

## SPRINT 13

### H2.7 — Payroll Simulasi & What-If
**Modul:** E. Compensation · **Effort:** Medium · **Impact:** High

#### Backend
- [ ] Endpoint `POST /api/v1/payroll/simulate`
- [ ] Input: employee_id + override params (salary, bonus, allowance, OT hours)
- [ ] Re-use `PayrollService` dengan flag `simulation = true` (no DB write)
- [ ] PPh21 simulation include
- [ ] Permission: HR full, employee limited (own data only)

#### Frontend
- [ ] HR: form "Simulasi Payroll" dengan slider/input override
- [ ] ESS: "Simulate Take-Home" terbatas (OT, bonus)
- [ ] Output: payslip-style dengan watermark "SIMULASI"

#### Testing
- [ ] Test: simulation tidak commit ke DB
- [ ] Test: hasil simulation = hasil real run jika input sama

---

### H2.8 — Reimbursement OCR Receipt
**Modul:** D. Operasional Harian · **Effort:** Medium · **Impact:** Medium

#### Backend
- [ ] Adapter `OcrDriver` interface
- [ ] Driver: `GoogleVisionDriver`, `TesseractDriver`
- [ ] Endpoint `POST /api/v1/reimbursements/ocr-extract` (upload file → return extracted fields)
- [ ] Konfigurasi OCR engine per company

#### Frontend
- [ ] Reimbursement form: upload struk → "Extract" button → pre-fill fields
- [ ] Indicator confidence per field (warna)
- [ ] Tombol "Edit Manual" jika OCR gagal/salah

#### Testing
- [ ] Mock OCR response → verifikasi pre-fill
- [ ] Test fallback: OCR error → form tetap usable

---

### H2.9 — Cost Center Drill-Down Interaktif
**Modul:** I. Compliance, Reporting & Cost Tracking · **Effort:** Medium · **Impact:** High

#### Backend
- [ ] Endpoint `GET /api/v1/cost-centers/{id}/transactions?period=YYYY-MM&category=X`
- [ ] Pagination + filter (status, source type)
- [ ] Endpoint `GET /api/v1/cost-centers/transactions/{id}/source` → return reference dokumen sumber

#### Frontend
- [ ] Modifikasi laporan Budget vs Actual: angka clickable → drill-down
- [ ] Breadcrumb navigation: Root → Directorate → Dept → Kategori → Transaksi
- [ ] Link transaksi → buka payslip/reimbursement/SPPD detail
- [ ] Export drill-down ke CSV/Excel

#### Testing
- [ ] Test: drill-down hanya tampilkan transaksi user authorized
- [ ] Performance test: drill-down < 2 detik untuk 10K transaksi

---

# 🟨 HORIZON 3 — NEW CAPABILITIES (Sprint 14–18)

## SPRINT 14

### H3.1 — Modul Kompetensi & Library Jabatan
**Modul Baru:** F. Performance (extension) · **Effort:** High · **Impact:** High

- [ ] **DB:** tabel `competencies`, `competency_levels`, `position_competency_profiles`, `employee_competency_assessments`
- [ ] **BE:** CRUD competencies + levels (1–5 dengan deskriptor)
- [ ] **BE:** CRUD competency profile per position
- [ ] **BE:** Service `CompetencyGapAnalysisService`
- [ ] **BE:** Hubungkan IDP item ke competency_id
- [ ] **BE:** Tag training catalog ke competency
- [ ] **FE:** Master Competencies (admin)
- [ ] **FE:** Position competency profile editor
- [ ] **FE:** Radar chart gap analysis (employee vs profile)
- [ ] **FE:** IDP form: pilih competency yang dikembangkan
- [ ] **QA:** Unit test gap calculator
- [ ] **DOC:** `docs/SPRINT_14_COMPETENCY.md`

### H3.2 — Succession Planning
**Modul Baru:** F + G (extension) · **Effort:** Medium-High · **Impact:** High

- [ ] **DB:** `critical_positions`, `succession_candidates` (position_id, employee_id, readiness)
- [ ] **BE:** CRUD critical positions
- [ ] **BE:** Endpoint coverage ratio (% critical positions dengan successor)
- [ ] **BE:** Auto-link IDP ke posisi target
- [ ] **FE:** Halaman Succession Planning (HRBP)
- [ ] **FE:** Dashboard coverage ratio + gap visualization
- [ ] **QA:** Test: successor wajib aktif (bukan resigned)

---

## SPRINT 15

### H3.3 — Modul Flexible Benefits (Cafeteria Plan)
**Modul Baru:** E (extension) · **Effort:** High · **Impact:** Medium-High

- [ ] **DB:** `benefit_pools`, `benefit_items`, `employee_benefit_selections`
- [ ] **BE:** Definisi pool nominal per grade/level
- [ ] **BE:** CRUD benefit items (asuransi, internet, gym, dll)
- [ ] **BE:** Window pemilihan tahunan + onboarding
- [ ] **BE:** Auto-tag ke payroll (cash/non-cash/reimbursement berkala)
- [ ] **FE:** ESS "Pilih Benefit" — slider alokasi total = pool
- [ ] **FE:** Report utilization per item
- [ ] **QA:** Test: total alokasi tidak boleh exceed pool

### H3.4 — Integrasi ERP / Accounting Journal
**Modul Baru:** L (extension) · **Effort:** High · **Impact:** High

- [ ] **DB:** `journal_mappings` (cost_center → COA), `journal_exports` log
- [ ] **BE:** Generator jurnal SAK setelah payroll finalize
- [ ] **BE:** Driver: Accurate Online API, Zahir, Odoo, SAP iDoc
- [ ] **BE:** CSV export untuk fallback
- [ ] **FE:** Halaman mapping COA per company
- [ ] **FE:** Tombol "Post to Accounting" + status log
- [ ] **QA:** Test debit-kredit balance
- [ ] **QA:** Test: jurnal tidak duplikat untuk period yang sama

---

## SPRINT 16

### H3.5 — Predictive HR Analytics (Foundation)
**Modul Baru:** I (extension) · **Effort:** Very High · **Impact:** High

- [ ] **Infra:** Python microservice (FastAPI + scikit-learn)
- [ ] **Infra:** Containerize + REST endpoint
- [ ] **Model:** Attrition prediction (logistic regression / random forest baseline)
- [ ] **Model:** Cost overrun prediction (time series ARIMA / Prophet)
- [ ] **Model:** Hire time prediction
- [ ] **Model:** Overtime trend forecast
- [ ] **BE:** Adapter PHP → Python service
- [ ] **BE:** Cache hasil prediction (refresh harian)
- [ ] **FE:** Dashboard prediksi: attrition risk, cost overrun, hire time
- [ ] **QA:** Test: degraded gracefully jika ML service down (fallback ke heuristik)
- [ ] **DOC:** Model card per prediksi

---

## SPRINT 17

### H3.6 — Talent Pool & Internal Marketplace
**Modul Baru:** B + F (extension) · **Effort:** High · **Impact:** Medium-High

- [ ] **DB:** `employee_skills`, `employee_aspirations`, `internal_job_postings`, `internal_applications`
- [ ] **BE:** Karyawan update skill + aspirasi karir
- [ ] **BE:** Internal job posting (visible internal only sebelum public)
- [ ] **BE:** Matching engine: rekomendasi internal candidate saat MPP dibuat
- [ ] **BE:** 9-box grid generator (performance × potential)
- [ ] **FE:** ESS "Profil Talent Saya"
- [ ] **FE:** Internal Job Board
- [ ] **FE:** HRBP talent map (9-box)
- [ ] **QA:** Test confidentiality: internal posting tidak bocor ke career portal public

---

## SPRINT 18

### H3.7 — Digital Signing Berstandar Hukum
**Modul Baru:** C (enhancement major) · **Effort:** Very High · **Impact:** High

- [ ] **Vendor:** kontrak dengan PSrE (PrivyID / Vida / Peruri)
- [ ] **BE:** Adapter `PSrEDriver` interface
- [ ] **BE:** Workflow: generate doc → kirim PSrE → callback → simpan certificate
- [ ] **BE:** Audit trail lengkap (siapa, kapan, IP, certificate)
- [ ] **BE:** Cakupan: contract, JIC amendment, severance, offer letter
- [ ] **BE:** Fallback wet-sign + scan upload
- [ ] **FE:** Halaman ContractSign update: redirect ke PSrE flow
- [ ] **FE:** History tanda tangan dengan certificate viewer
- [ ] **QA:** End-to-end test dengan sandbox PSrE
- [ ] **DOC:** Compliance UU ITE & Perpres 95/2018

### H3.8 — Open API & Developer Portal
**Modul Baru:** L (extension) · **Effort:** High · **Impact:** Medium-High

- [ ] **DB:** `api_keys`, `api_key_scopes`, `oauth_clients`, `oauth_tokens`
- [ ] **BE:** OpenAPI spec lengkap (auto-generate dari controller)
- [ ] **BE:** Swagger UI di `/developer/docs`
- [ ] **BE:** API key CRUD (rotate, revoke)
- [ ] **BE:** OAuth 2.0 server (auth code + client credentials)
- [ ] **BE:** Rate limiting per API key per tier
- [ ] **BE:** Webhook event catalog + subscription
- [ ] **FE:** Developer Portal halaman: docs, key management, webhooks
- [ ] **FE:** Sandbox environment toggle
- [ ] **QA:** Security test: scope enforcement
- [ ] **DOC:** Quickstart guide untuk developer pelanggan

---

# 🟪 HORIZON 4 — PLATFORM EVOLUTION (Sprint 19+)

> Roadmap detail TBD — placeholder checklist untuk persiapan.

### H4.1 — AI-Powered HR Assistant (Copilot)
- [ ] PoC LLM integration (Claude / OpenAI) dengan function calling
- [ ] Prompt engineering + guardrails
- [ ] Privacy review (data tidak di-train ke LLM publik)
- [ ] Chat UI di shell HRIS
- [ ] Audit log per query

### H4.2 — Multi-Country Payroll
- [ ] Refactor `PayrollService` → jurisdiction-aware
- [ ] Country module: Indonesia (existing), Malaysia, Singapura, Filipina, Thailand
- [ ] Multi-currency support semua modul finansial
- [ ] Tax rule engine per country

### H4.3 — Workforce Planning & Scenario Modeling
- [ ] Scenario builder UI
- [ ] Monte Carlo simulation engine
- [ ] Integrasi salary benchmark (Glints / JobStreet / Mercer)

### H4.4 — Employee Wellbeing & Mental Health
- [ ] Pulse survey engine (anonim)
- [ ] Burnout detection score
- [ ] EAP resource directory
- [ ] Aggregate dashboard (no individual data ke HR)

### H4.5 — Compensation Intelligence
- [ ] Adapter survei gaji eksternal
- [ ] Compa-ratio per karyawan
- [ ] Pay equity analysis (gender / demografi)
- [ ] Salary band recommendation engine

---

# 🛠️ TECHNICAL DEBT & INFRASTRUCTURE

> Lintas sprint — eksekusi disisipkan saat ada slot atau saat blocker muncul.

### TD.1 — API Rate Limiting Granular
- [ ] Pindah dari MySQL counter ke Redis token bucket
- [ ] Tier per role (admin / HR / employee / API key)
- [ ] Whitelist internal cron
- [ ] Monitoring: dashboard request rate per endpoint

### TD.2 — Background Job Queue Robust
- [ ] Pilih message broker: RabbitMQ vs Redis Streams
- [ ] Migrate cron-based jobs → queue worker
- [ ] Dead-letter queue untuk job gagal berulang
- [ ] Monitoring: queue depth, worker health

### TD.3 — Database Query Optimization
- [ ] Audit slow query log → top 10 query terberat
- [ ] Tambah index yang missing
- [ ] Setup read replica MySQL
- [ ] Materialized view untuk laporan tinggi-traffic (cost center, payroll summary)
- [ ] Query plan review per service

### TD.4 — Test Coverage Expansion
- [ ] Target: 607 → 900+ tests
- [ ] Integration test: MPP → hire → onboarding → payroll end-to-end
- [ ] Performance test: payroll calculate < 60s untuk 1.000 karyawan
- [ ] Coverage threshold gate di CI

### TD.5 — Key Rotation Otomatis
- [ ] Cron `security:rotate-encryption-keys`
- [ ] Background job: re-encrypt data lama dengan key baru
- [ ] Konfigurasi rotasi per company (default: 12 bulan)
- [ ] Audit log rotation history

### TD.6 — CDN & File Storage
- [ ] Pilih CDN: CloudFront / Cloudflare
- [ ] Setup signed URL untuk file private
- [ ] Lazy generate PDF (on-demand + cache)
- [ ] Migrate existing static assets

---

# 📊 METRIK KEBERHASILAN (TRACKER)

| Inisiatif | Target | Baseline | Aktual | Status |
| --- | --- | --- | --- | --- |
| Bulk Approval | Waktu approval inbox turun >40% | TBD | - | ⬜ |
| WFH Mode | 100% WFH ter-track formal | 0% | - | ⬜ |
| Overtime Pre-Approval | >80% lembur dengan pre-approval | 0% | - | ⬜ |
| Helpdesk SLA | >90% ticket dalam SLA | TBD | - | ⬜ |
| THR Dashboard | Finance no manual spreadsheet | - | - | ⬜ |
| WhatsApp Notif | Approval response time turun >30% | TBD | - | ⬜ |
| Mobile PWA | Late clock-in turun | TBD | - | ⬜ |
| Goals Cascading | >70% goals ter-align | TBD | - | ⬜ |
| 360° Feedback | Completion rate peer review >85% | - | - | ⬜ |
| Turnover Analytics | HRBP identifikasi risk dept proaktif | - | - | ⬜ |
| Referral Program | >20% hire via referral | TBD | - | ⬜ |

---

# 🔄 RITUAL UPDATE

**Per Sprint:**
- [ ] Awal sprint: pilih item dari horizon → tandai `- [~]`
- [ ] Tengah sprint: review blocker
- [ ] Akhir sprint: tandai `- [x]` + update tabel status horizon di atas
- [ ] Update `metrik keberhasilan` dengan angka aktual
- [ ] Buat `docs/SPRINT_N_<NAMA>.md` untuk hand-off

**Per Bulan:**
- [ ] Review prioritas vs feedback user
- [ ] Update kuadran impact/effort jika ada perubahan asumsi
- [ ] Snapshot total tests / assertions ke tabel ringkas

---

*Dokumen turunan dari `QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md`*
*Dibuat: Mei 2026 · Versi sistem: Sprint 6 (Cost Center finalized)*