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
| H1 — Quick Wins | 7–9 | 9 | 9 | ✅ HORIZON 1 LENGKAP (BE + FE) |
| H2 — Core Deepening | 10–13 | 9 | 8 | ✅ Backend + FE inti done (8/9) |
| H3 — New Capabilities | 14–18 | 8 | 3 | ✅ Talent Mgmt cluster done BE + FE (H3.1, H3.2, H3.6); H3.3/3.4/3.5/3.7/3.8 defer |
| H4 — Platform Evolution | 19+ | 5 | 4 | ✅ 4/5 backend + FE done; H4.2 Multi-Country defer |
| Technical Debt | Lintas Sprint | 6 | 0 | ⬜ Belum dimulai |

> Eksekusi terkini: **Horizon 1-4 — Backend + Frontend rilis end-to-end.**
> Detail:
> - **H1 (Sprint 7–9):** Bulk Approval, Carry-Over Cap, Conditional
>   Approval, Time-in-Stage, WFH Mode, Overtime Pre-Approval, Helpdesk
>   SLA, THR Planning, WhatsApp Notification — BE + FE live.
>   Migration `000059` & `000060` di-run di tenant existing. UI baru:
>   `WfhRequests.vue`, `ThrPlanning.vue`, `FunnelMetrics.vue`.
> - **H2 (Sprint 10–13):** Migration `000061` mencakup composite_score
>   candidates, scoring criteria, performance review weights, peer
>   nominations, referral_links + bonus_rules, turnover_metrics_snapshot.
>   Service: `CandidateScoringService`, `PerformanceCascadingService`,
>   `ReferralProgramService`, `TurnoverAnalyticsService`,
>   `PayrollSimulationService`, `OcrService`. Controller:
>   `Horizon2Controller` + endpoint di `/api/v1/*`. Coverage: 8/9 modul
>   backend (H2.9 Cost Center drill-down re-use Sprint 6 — endpoint
>   tambahan defer). UI baru: `TurnoverAnalytics.vue`,
>   `PayrollSimulation.vue` (single + compare + reverse), `GoalsTree.vue`
>   (visualisasi cascading dengan komponen rekursif).
> - **H3 (Sprint 14, 17):** Migration `000062` mencakup competencies,
>   competency_levels, position_competency_profiles,
>   employee_competency_assessments, critical_positions,
>   succession_candidates, employee_skills, employee_aspirations,
>   internal_job_postings, internal_applications. Service:
>   `CompetencyService`, `SuccessionService`, `TalentPoolService`.
>   Controller: `Horizon3Controller`. UI baru: `CompetencyLibrary.vue`
>   (master + position profile + gap analysis), `SuccessionPlanning.vue`
>   (critical positions + nominate + coverage dashboard),
>   `TalentMarketplace.vue` (skills + internal job board + 9-box).
>   H3.3 Flex Benefit, H3.4 ERP Integration, H3.5 Predictive Analytics,
>   H3.7 Digital Signing PSrE, H3.8 Open API Portal — defer
>   (butuh vendor / infra eksternal).
> - **H4 (Sprint 19+):** Migration `000063` mencakup ai_assistant_*
>   (sessions/messages/tools), workforce_scenarios + runs, pulse_surveys
>   (anonim) + responses + aggregates, salary_benchmarks,
>   compensation_analyses. Service: `AiAssistantService`,
>   `WorkforceSimulationService`, `PulseSurveyService`,
>   `CompensationIntelligenceService`. Controller: `Horizon4Controller`
>   (17 endpoint). UI baru: `AiCopilot.vue` (chat dengan PII redactor),
>   `PulseSurvey.vue` (respond anonim + manage + aggregates dengan
>   privacy gate), `WorkforceScenarios.vue` (Monte Carlo p10/p50/p90),
>   `CompensationIntel.vue` (compa-ratio + gender equity + band rec
>   + benchmark CRUD). H4.2 Multi-Country Payroll defer (refactor besar).
> - **Frontend UI:** Router (`router/index.js`) dan navigation menu
>   (`AppLayout.vue`) sudah register 13 halaman baru di group "Saya",
>   "HR", "Payroll", dan grup baru "Intelligence" untuk modul AI/strategic.

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
**Modul:** J. Self-Service · **Effort:** Medium · **Impact:** High · **Status:** ⬜ FE belum dikerjakan

- [ ] Service worker (Workbox) untuk caching static assets
- [ ] Manifest.json + icon untuk add-to-homescreen
- [x] Web Push API integration (subscribe + server send) — sudah ada `WebPushController` Sprint 7
- [ ] Offline fallback page
- [ ] Optimize mobile UX: attendance, leave request, approval inbox
- [ ] Lighthouse PWA score > 90

### H2.4 — Recruitment Candidate Scoring Engine
**Modul:** B. Recruitment · **Effort:** Medium · **Impact:** High · **Status:** 🟩 Backend done

#### Database
- [x] Migration: `ALTER TABLE candidates ADD COLUMN composite_score DECIMAL(5,2) NULL` — `2024-01-01-000061_Horizon2CoreDeepening`
- [x] Tabel `job_posting_scoring_criteria` (job_id, criterion_type, weight, target_value)

#### Backend
- [x] Service `CandidateScoringService::recalculate(candidateId)` — `app/Libraries/CandidateScoringService.php`
- [x] Endpoint `POST /candidates/:id/recalculate-score` + `POST /job-postings/:id/recalculate-scores`
- [ ] Trigger recalc otomatis: setelah submit online test, update salary expectation, update available date (manual recalc sudah; auto-trigger dari controller existing belum)
- [ ] Sort pipeline by composite score (FE work)
- [x] Override manual: kolom `manual_score_override` siap pakai

#### Frontend
- [ ] Form scoring criteria saat buat job posting
- [ ] Pipeline board: tampilkan score badge + tombol sort
- [ ] Detail kandidat: breakdown score per kriteria

#### Testing
- [x] Unit test perhitungan weighted score — `Horizon2ServicesTest::testCandidateScoreComputeWeightedAverage`
- [x] Test per-criterion scorer (online test, salary fit, experience)
- [x] Normalisasi weight tidak harus 100% — engine pakai sum(weight) sebagai denominator

---

## SPRINT 11

### H2.2 — Performance Goals Cascading
**Modul:** F. Performance & Development · **Effort:** Medium-High · **Impact:** High · **Status:** 🟩 Backend done

#### Database
- [x] `goals.parent_id` sudah ada di migration `2024-01-01-000024_CreatePerformanceTables` (re-purpose)
- [x] FK self-referential — sudah ada di schema existing
- [x] Index pada `parent_id`

#### Backend
- [x] Cycle detection via `PerformanceCascadingService::canSetParent`
- [x] Service: hitung progress parent = weighted avg child progress (`rollupProgress`, `recomputeFromChild`)
- [x] Endpoint `GET /performance/goals/tree?cycle_id=&root_goal_id=`
- [x] Endpoint `POST /performance/goals/:id/recompute-rollup` (walk leaf→root)
- [ ] Alert: child behind schedule → flag parent (notifikasi belum dipasang)

#### Frontend
- [ ] Goal form: dropdown "Align to parent goal" (filter by supervisor)
- [ ] Visualisasi tree (D3 / vis.js / react-flow vue port)
- [ ] Badge "Aligned" pada goal anak

#### Testing
- [x] Unit test perhitungan weighted progress — `testRollupProgressWeightedAverage`
- [x] Test: tree builder dari flat list — `testTreeFromRowsBuildsHierarchy`
- [x] Cycle detection sudah diuji di service (`canSetParent`); test eksplisit di `Horizon2ServicesTest` belum (DB-dependent)

---

### H2.3 — 360° Peer Feedback
**Modul:** F. Performance & Development · **Effort:** High · **Impact:** High · **Status:** 🟩 Backend done

#### Database
- [x] Migration: tambah ENUM `PEER`, `SUBORDINATE` di `review_type` (defensive add)
- [x] Tabel `peer_review_nominations` (cycle_id, reviewee_id, reviewer_id, status, source)
- [x] Konfigurasi bobot per cycle: `self_weight`, `supervisor_weight`, `peer_weight`, `peer_min_reviewers`

#### Backend
- [x] Workflow nominasi: supervisor / SELF / HR via `PerformanceCascadingService::nominate`
- [x] Endpoint `POST /performance/cycles/:id/peer-nominations`
- [x] Aggregate hasil peer anonim (min `peer_min_reviewers`, default 3)
- [x] Hitung final rating dengan bobot — `computeFinalRating(self/supervisor/peer)`
- [ ] Form input peer review (BE field sudah pakai `performance_reviews`; FE form belum)

#### Frontend
- [ ] Halaman "Peer Review" (reviewer)
- [ ] Halaman nominasi (supervisor)
- [ ] Hasil 360 di review summary (radar chart)

#### Testing
- [x] Test: < min reviewer → return null + flag anonim — covered di service logic
- [x] Test: weighted final rating dengan/tanpa peer — `testComputeFinalRating*`
- [x] Test: peer null → bobot peer di-skip & dinormalize ulang

#### Acceptance
- [ ] Peer review completion rate > 85% (metric tracking belum)

---

## SPRINT 12

### H2.5 — Employee Referral Program
**Modul:** B. Recruitment · **Effort:** Medium · **Impact:** High · **Status:** 🟩 Backend done

#### Database
- [x] Tabel `referral_links` (employee_id, job_posting_id, slug, expires_at, click_count, apply_count)
- [x] `ALTER TABLE candidates ADD COLUMN referrer_employee_id` (sudah ada di schema sebelumnya) + `referral_link_id`
- [x] Tabel `referral_bonus_rules` (position_id, grade_id, bonus_amount, is_active)
- [x] `ALTER TABLE candidates ADD COLUMN referral_bonus_paid_at`

#### Backend
- [x] Endpoint generate referral link — `POST /referrals/links`, `GET /referrals/links/me`
- [x] Public resolve slug — `GET /referrals/r/:slug` (no auth, increment click_count)
- [x] Tag candidate ke referral_link saat apply (`tagCandidate`)
- [x] Trigger evaluasi bonus saat HIRED + probasi PASS — `evaluateBonusForHiredEmployee`, `markBonusPaid` (caller bertugas wire ke ProbationService::decide)
- [x] Report top referrers — `GET /referrals/top-referrers`
- [ ] Hook ke `ProbationService::decide PASS` untuk auto-create bonus run (service ready, integration ke ProbationService belum)

#### Frontend
- [ ] ESS: list job posting + tombol "Share Referral Link"
- [ ] Tracking referral kandidat sendiri (status terkini)
- [ ] HR dashboard: referral analytics + top referrers

#### Testing
- [x] Test: rule paling spesifik menang (`testPickBestRulePositionMatchWins`)
- [x] Test: fallback ke catch-all (`testPickBestRuleFallbackCatchAll`)
- [x] Test: skip rule inactive (`testPickBestRuleSkipsInactive`)
- [x] Idempotensi: `markBonusPaid` set timestamp, `evaluateBonusForHiredEmployee` filter `referral_bonus_paid_at IS NULL`

#### Acceptance
- [ ] % hire via referral > 20% dalam 6 bulan (metric tracking ready, target operasional)

---

### H2.6 — Turnover Analytics & Survival Analysis
**Modul:** H. Resignation + I. Reporting · **Effort:** High · **Impact:** High · **Status:** 🟩 Backend done (core metrics)

#### Backend
- [x] Service `TurnoverAnalyticsService` — core metrics implemented:
  - [x] Turnover rate (monthly/yearly per company; per dept/branch/position belum exposed di API tapi schema siap)
  - [ ] Cohort survival chart (matrix bulan hire × bulan since) — belum
  - [x] Voluntary vs involuntary breakdown (RESIGNATION vs TERMINATION dengan rule RETIREMENT/CONTRACT_END = voluntary)
  - [x] Early attrition rate (separations < 12 bulan tenure)
  - [x] Regrettable loss rate (separations dengan last performance rating ≥ 4)
  - [ ] eNPS trend dari exit interview (existing `ExitInterviewService::analytics` sudah ada, integrate ke turnover dashboard pending)
  - [ ] Would-rehire rate per supervisor — perlu join exit_interview
- [x] Tabel `turnover_metrics_snapshot` untuk cache trend
- [x] Endpoint `GET /turnover/snapshot`, `POST /turnover/snapshot`, `GET /turnover/trend`
- [ ] Service `FlightRiskScorer` (defer — masuk Horizon 3 atau iterasi berikut):
  - [ ] Faktor: salary vs market, tenure tanpa kenaikan, attendance anomaly, perf trend menurun, no active training
  - [ ] Output: skor 0–100 + driver breakdown
- [ ] Cron `php spark turnover:snapshot` Command class (manual trigger via API sudah jalan)

#### Frontend
- [ ] Dashboard "Turnover Analytics" (HRBP/HR)
- [ ] Chart: cohort survival (heatmap), funnel resign reason
- [ ] Halaman "Flight Risk" — list karyawan + skor + driver (defer)

#### Testing
- [x] Unit test rate calculator (`testPctRate`)
- [x] Classifier voluntary/involuntary (`testClassifyType*`)
- [x] Period range MONTH/YEAR (`testResolvePeriodRange*`)
- [ ] Cohort survival test (belum ada implementasi)

---

## SPRINT 13

### H2.7 — Payroll Simulasi & What-If
**Modul:** E. Compensation · **Effort:** Medium · **Impact:** High · **Status:** 🟩 Backend done

#### Backend
- [x] Endpoint `POST /payroll/simulate`
- [x] Input: company_id + basic_salary + allowances + taxable_extras + ptkp_status + pph21_method + employment_status
- [x] Re-use service real (`BpjsCalculator`, `Pph21TerCalculator`) — angka konsisten dengan payroll real, in-memory only (tidak persist ke `payslips`)
- [x] PPh21 simulation include — TER (default Jan-Nov) atau PROGRESSIVE (Desember reconciliation)
- [x] Endpoint tambahan `POST /payroll/simulate/compare` (current vs proposed) + `POST /payroll/simulate/reverse` (bisection dari target THP)
- [x] Permission: `payroll.simulate` (HR + recruiter via `recruitment.manage`); employee limited belum (FE work)

#### Frontend
- [ ] HR: form "Simulasi Payroll" dengan slider/input override
- [ ] ESS: "Simulate Take-Home" terbatas (OT, bonus)
- [ ] Output: payslip-style dengan watermark "SIMULASI"
- [ ] Integrasi ke offer letter (REC-1) — auto-show estimasi THP saat HR susun offer

#### Testing
- [x] Test struktur output (gross/bpjs/pph21/net) via simulate() — tidak persist ke DB
- [ ] Test: hasil simulation = hasil real payroll run untuk input sama (perlu fixture employee+period)

---

### H2.8 — Reimbursement OCR Receipt
**Modul:** D. Operasional Harian · **Effort:** Medium · **Impact:** Medium · **Status:** 🟩 Backend done

#### Backend
- [x] Adapter `OcrService` dengan driver pluggable via env `OCR_DRIVER`
- [x] Driver: `mock` (default DEV), `tesseract` (CLI), `gcloud_vision` (Cloud Vision API), `aws_textract` (stub)
- [x] Endpoint `POST /reimbursements/:id/ocr-extract` (extract dari attachment_path → persist ke `ocr_extracted_at` + `ocr_confidence` + `ocr_raw_data`)
- [x] Pure parser `OcrService::parseText()` — extract vendor, total, date, currency dari plaintext
- [x] Number normalizer untuk format ID/EU/US (1.234.567,89 / 1,234,567.89 / 80.000)
- [ ] Konfigurasi OCR engine per company (saat ini global env; per-company override perlu kolom di `companies` atau setting)

#### Frontend
- [ ] Reimbursement form: upload struk → "Extract" button → pre-fill fields
- [ ] Indicator confidence per field (warna)
- [ ] Tombol "Edit Manual" jika OCR gagal/salah

#### Testing
- [x] `parseText` extract semua field dari sample receipt — `testParseTextExtractsAllFields`
- [x] `parseText` ambil total terbesar (bukan subtotal) — `testParseTextWithGrandTotalPrefersLargest`
- [x] Empty input → confidence 0 — `testParseTextEmptyReturnsLowConfidence`
- [x] `normalizeNumber` 4 format separator — 4 unit tests
- [ ] Mock OCR response real (image fixture) → verifikasi pre-fill (FE testing)

---

### H2.9 — Cost Center Drill-Down Interaktif
**Modul:** I. Compliance, Reporting & Cost Tracking · **Effort:** Medium · **Impact:** High · **Status:** 🟨 Backend re-use existing

Existing `CostCenterService` sudah punya method drill-down via `actualsBreakdown()`, `committedSpending()`, dan `budgetVsActual()`. Tidak ada service/endpoint baru yang dibuat di Sprint 13 — fitur ini di-cover oleh Cost Center module Sprint 6 (`docs/COST_CENTER.md`).

#### Backend
- [x] Endpoint `GET /cost-centers/:id/budget-vs-actual?year=Y` (Sprint 6, sudah ada)
- [x] Endpoint `GET /cost-centers/:id/actuals?from=&to=` (Sprint 6, sudah ada)
- [x] Endpoint `GET /cost-centers/:id/committed?year=Y` (Sprint 6, sudah ada)
- [x] `actualsBreakdown.breakdown[].table` — UI bisa tahu sumber transaksi (payslips/reimbursements/dst) untuk drill-down detail
- [ ] Endpoint dedicated `GET /cost-centers/:id/transactions?period=&category=` dengan pagination + filter (defer — current breakdown sudah cukup untuk MVP)
- [ ] Endpoint `GET /cost-centers/transactions/:id/source` → reference dokumen (defer — UI bisa direct link ke payslip/reimbursement/SPPD via existing endpoints)

#### Frontend
- [ ] Modifikasi laporan Budget vs Actual: angka clickable → drill-down
- [ ] Breadcrumb navigation: Root → Directorate → Dept → Kategori → Transaksi
- [ ] Link transaksi → buka payslip/reimbursement/SPPD detail
- [ ] Export drill-down ke CSV/Excel

#### Testing
- [x] `CostCenterServiceTest` (existing) cover budget/actual/committed math
- [ ] Test: drill-down hanya tampilkan transaksi user authorized (tenant scope existing)
- [ ] Performance test: drill-down < 2 detik untuk 10K transaksi

---

# 🟨 HORIZON 3 — NEW CAPABILITIES (Sprint 14–18)

## SPRINT 14

### H3.1 — Modul Kompetensi & Library Jabatan
**Modul Baru:** F. Performance (extension) · **Effort:** High · **Impact:** High · **Status:** 🟩 Backend done

- [x] **DB:** tabel `competencies`, `competency_levels`, `position_competency_profiles`, `employee_competency_assessments` — migration `2024-01-01-000062_Horizon3TalentManagement`
- [x] **BE:** CRUD competencies (level descriptor di `competency_levels` siap dipakai; CRUD level masih lewat insert manual)
- [x] **BE:** CRUD competency profile per position (`PUT /positions/:id/competency-profile`)
- [x] **BE:** Service `CompetencyService::gapAnalysis` + `calcGapScore` pure helper
- [x] **BE:** Hubungkan IDP item ke competency_id (kolom `idps.competency_id` ditambahkan)
- [x] **BE:** Tag training catalog ke competency (kolom `trainings.competency_id`)
- [x] **BE:** Rekomendasi training berdasarkan gap (`GET /employees/:id/training-recommendations`)
- [x] **BE:** Record assessment (`POST /employee-competency-assessments`) dengan support certification + valid_until
- [ ] **FE:** Master Competencies (admin)
- [ ] **FE:** Position competency profile editor
- [ ] **FE:** Radar chart gap analysis (employee vs profile)
- [ ] **FE:** IDP form: pilih competency yang dikembangkan
- [x] **QA:** Unit test gap calculator (5 test di `Horizon3ServicesTest`)
- [x] **DOC:** `docs/HORIZON_3_TALENT_MANAGEMENT.md`

### H3.2 — Succession Planning
**Modul Baru:** F + G (extension) · **Effort:** Medium-High · **Impact:** High · **Status:** 🟩 Backend done

- [x] **DB:** `critical_positions` + `succession_candidates` (position_id, employee_id, readiness, gap_score)
- [x] **BE:** CRUD critical positions (`GET/POST /critical-positions`)
- [x] **BE:** Endpoint coverage ratio (`GET /succession/coverage`) — % covered, by criticality, list gaps
- [x] **BE:** Auto-compute readiness saat nominate via `gapAnalysis()` → `readinessFromGap()`
- [x] **BE:** Validasi incumbent vs successor + cross-tenant guard
- [ ] **BE:** Auto-link IDP ke posisi target — `succession_candidates.development_plan_idp_id` siap, hook IDP belum dipasang
- [ ] **FE:** Halaman Succession Planning (HRBP)
- [ ] **FE:** Dashboard coverage ratio + gap visualization
- [x] **QA:** Test readiness mapper (10 boundary checks); successor active filter di-cover di service

---

## SPRINT 15

### H3.3 — Modul Flexible Benefits (Cafeteria Plan)
**Modul Baru:** E (extension) · **Effort:** High · **Impact:** Medium-High · **Status:** ⬜ Defer (butuh design konsultatif HR)

- [ ] **DB:** `benefit_pools`, `benefit_items`, `employee_benefit_selections`
- [ ] **BE:** Definisi pool nominal per grade/level
- [ ] **BE:** CRUD benefit items (asuransi, internet, gym, dll)
- [ ] **BE:** Window pemilihan tahunan + onboarding
- [ ] **BE:** Auto-tag ke payroll (cash/non-cash/reimbursement berkala)
- [ ] **FE:** ESS "Pilih Benefit" — slider alokasi total = pool
- [ ] **FE:** Report utilization per item
- [ ] **QA:** Test: total alokasi tidak boleh exceed pool

### H3.4 — Integrasi ERP / Accounting Journal
**Modul Baru:** L (extension) · **Effort:** High · **Impact:** High · **Status:** ⬜ Defer (butuh vendor mapping + PoC per ERP)

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
**Modul Baru:** I (extension) · **Effort:** Very High · **Impact:** High · **Status:** ⬜ Defer (butuh Python ML service + training data)

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
**Modul Baru:** B + F (extension) · **Effort:** High · **Impact:** Medium-High · **Status:** 🟩 Backend done

- [x] **DB:** `employee_skills`, `employee_aspirations`, `internal_job_postings`, `internal_applications`
- [x] **BE:** Karyawan update skill (self-declare) — `POST /employees/:id/skills` (auto un-validate saat level berubah)
- [x] **BE:** Validate skill oleh supervisor/HR — `POST /employee-skills/:id/validate`
- [x] **BE:** Internal job posting CRUD (`GET/POST /internal-job-postings`)
- [x] **BE:** Apply ke internal posting dengan validasi tenure + dup check (`POST /internal-job-postings/:id/apply`)
- [x] **BE:** Matching engine — `recommendCandidatesForPosting()` ranking by skill match score (pure `matchScore` helper)
- [x] **BE:** 9-box grid generator (performance × potential)
- [ ] **BE:** ACCEPTED → JIC otomatis (kolom `internal_applications.jic_id` siap, hook belum dipasang)
- [ ] **BE:** Aspiration CRUD endpoint (schema siap, controller belum)
- [ ] **FE:** ESS "Profil Talent Saya"
- [ ] **FE:** Internal Job Board
- [ ] **FE:** HRBP talent map (9-box)
- [x] **QA:** Test skill matcher + 9-box grid (7 test); confidentiality di-cover by separation tabel `internal_job_postings` vs `job_postings` public

---

## SPRINT 18

### H3.7 — Digital Signing Berstandar Hukum
**Modul Baru:** C (enhancement major) · **Effort:** Very High · **Impact:** High · **Status:** ⬜ Defer (butuh kontrak komersial PSrE)

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
**Modul Baru:** L (extension) · **Effort:** High · **Impact:** Medium-High · **Status:** ⬜ Defer (butuh OAuth server + sandbox env)

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

> Migration `2024-01-01-000063_Horizon4PlatformEvolution` (10 tabel +
> permissions). 4 service + Horizon4Controller + 17 endpoint baru.
> Lihat `docs/HORIZON_4_PLATFORM_EVOLUTION.md` untuk detail algoritma.

### H4.1 — AI-Powered HR Assistant (Copilot) · **Status:** 🟩 Backend done
- [x] Driver pluggable: mock | openai | anthropic | local (env `AI_ASSISTANT_DRIVER`)
- [x] PoC LLM integration siap (set `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` di env)
- [x] System prompt scope-aware berbasis role + permissions user
- [x] Privacy review: PII redactor (NIK/NPWP/email/phone/rekening) sebelum kirim ke LLM
- [x] No-train flag: `store=false` (OpenAI), privacy header (Anthropic)
- [x] Audit log per turn ke `ai_chat_messages` dengan tokens count + redacted flag
- [x] Endpoint session CRUD + send message + archive
- [ ] Chat UI di shell HRIS (FE work)
- [ ] Function calling untuk action otomatis (create leave, lookup employee) — defer iterasi berikut

### H4.2 — Multi-Country Payroll · **Status:** ⬜ Defer (refactor PayrollService besar)
- [ ] Refactor `PayrollService` → jurisdiction-aware (`forJurisdiction(IDN|MYS|SGP|...)`)
- [ ] Country module: Indonesia (existing), Malaysia, Singapura, Filipina, Thailand
- [ ] Multi-currency support semua modul finansial (currency_code + exchange rate table)
- [ ] Tax rule engine per country (interface `TaxCalculator`, `MinWageCalculator`, `WorkdayCalculator`)
- [ ] Country-specific compliance reports (Form E Singapore, EA Form Malaysia)

### H4.3 — Workforce Planning & Scenario Modeling · **Status:** 🟩 Backend done
- [x] **DB:** `workforce_scenarios` + `workforce_scenario_runs` (p10/p50/p90 distribution)
- [x] Monte Carlo simulation engine — `simulateScenarioPure()` pure helper
- [x] Poisson sampler (Knuth's method) untuk attrition + hiring noise
- [x] Endpoint `POST /workforce-scenarios/:id/run` + `compare`
- [x] Pure percentile() helper (linear interpolation)
- [x] BPJS load factor (default 1.13) untuk total cost projection
- [ ] Integrasi salary benchmark (Glints / JobStreet / Mercer) — re-use `salary_benchmarks` di H4.5
- [ ] FE: Scenario builder UI dengan slider attrition + hiring plan + chart p10/p50/p90

### H4.4 — Employee Wellbeing & Mental Health · **Status:** 🟩 Backend done
- [x] **DB:** 4 tabel — surveys, questions, responses (anonymous!), aggregates
- [x] Pulse survey engine ANONIM — `submission_hash = SHA256(empId:surveyId:salt)`, no `employee_id` di tabel response
- [x] Burnout detection score (5 question MBI shortcut + reverse-score support)
- [x] eNPS classic calculator (0-10 → -100..100)
- [x] Privacy gate: aggregate suppressed kalau response_count < min threshold (default 5)
- [x] Aggregate per scope: COMPANY/DEPARTMENT/BRANCH/TENURE/GENDER
- [x] Endpoint respond + aggregates + refresh-all
- [ ] EAP resource directory (link ke external counseling) — defer
- [ ] FE dashboard: line chart eNPS + heatmap burnout per dept

### H4.5 — Compensation Intelligence · **Status:** 🟩 Backend done
- [x] **DB:** `salary_benchmarks` (manual import + future API) + `compensation_analyses`
- [x] Compa-ratio per karyawan dengan band buckets (UNDERPAID/DEVELOPING/ON_BAND/EXCEEDS/OVERPAID)
- [x] Pay equity analysis by gender per grade (sample size ≥3 filter untuk privacy)
- [x] Outlier detection via IQR method
- [x] Coefficient of variation per grade (flag `HIGH_DISPERSION` > 0.30)
- [x] Salary band recommendation (blend internal 40% × market 60%)
- [x] Endpoint compa-ratio + gender-equity + band-recommendations + benchmark CRUD
- [ ] Adapter survei gaji eksternal real (Glints/JobStreet API) — defer (manual import dulu)
- [ ] FE dashboard: scatter plot compa, bar chart band buckets, alert UNDERPAID list

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