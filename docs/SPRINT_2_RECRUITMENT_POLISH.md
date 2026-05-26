# Sprint 2 — Recruitment Polish (Selesai + Polish Pass 26 Mei)

**Tanggal selesai:** 26 Mei 2026
**Cakupan:** 3 item Recruitment/Hire dari `docs/LIFECYCLE_AUDIT_2026.md`
**Hasil:** 511 tes hijau (493 Sprint 1 + 18 baru), tidak ada regresi

---

## Ringkasan Item yang Dikerjakan

| Item | Deskripsi | Status |
|------|-----------|--------|
| REC-1 | Offer letter management (DRAFT → SENT → ACCEPTED/REJECTED) | ✅ Backend full + API |
| HIRE-1 | Probation auto-tracking sesuai UU 13/2003 Pasal 60 | ✅ Backend full + API + cron |
| ONB-1 | 30/60/90-day onboarding milestones | ✅ Diintegrasi via task template |

---

## Yang Dibuat

### Database
**Migration 52** (`2024-01-01-000052_RecruitmentPolishSprint2.php`):

- Tabel baru `candidate_offers` — track offer letter dengan workflow lengkap
  (DRAFT/SENT/ACCEPTED/REJECTED/EXPIRED/WITHDRAWN), breakdown gaji + tunjangan
  + joining bonus + benefits, response_deadline (default H+7), link ke
  generated_contracts (untuk OFFER_LETTER template), signed PDF path
- 4 field baru di `employees`:
  - `probation_status` (ENUM: ONGOING/PASSED/FAILED/EXTENDED/NA)
  - `probation_decision_at`, `probation_decision_by`, `probation_decision_note`
- 4 permission baru: `recruitment.offer.view/.manage/.respond`, `probation.manage`

### Services baru

**`backend/app/Libraries/OfferService.php`** — orchestrator offer letter
- `nextOfferNumber($companyId)` — generate `OFR-<YEAR>-<5digit>` unik
- `create($data, $userId)` — create DRAFT dengan validasi:
  - Candidate harus stage OFFER atau INTERVIEW_USER
  - Tidak boleh duplikasi offer aktif (DRAFT/SENT) per kandidat
  - basic_salary > 0
  - probation_months 0-3 (UU 13/2003 Pasal 60)
  - PKWT tidak boleh ada probation (PP 35/2021 Pasal 12)
- `update(id, $data, $userId)` — update DRAFT (SENT tidak boleh, harus
  withdraw + buat baru untuk audit trail)
- `send(id, $userId, $note)` — DRAFT → SENT, kirim notifikasi kandidat
- `recordResponse(id, decision, ...)` — record ACCEPTED/REJECTED, kalau
  ACCEPTED auto-progress ke HIRED + notifikasi HR
- `withdraw(id, $userId, $reason)` — HR withdraw (DRAFT/SENT only)
- `expire(id)` — auto-expire offer yang lewat deadline
- `findExpiredOffers($companyId)` — scanner untuk cron

**`backend/app/Libraries/ProbationService.php`** — orchestrator probation
- `applyDefaults($employeeId)` — auto-set `probation_end_date = join_date +
  3 bulan` saat hire untuk PKWTT, NA untuk non-PKWTT
- `decide($employeeId, $decision, ...)` — record PASS/FAIL/EXTEND:
  - PASSED → karyawan jadi tetap
  - FAILED → set resign_date today + reason 'PROBATION_FAIL: ...', HR
    perlu lanjut ke Severance untuk hitung kompensasi PP 35/2021
  - EXTENDED → validasi total tidak >3 bulan dari join_date (UU 13/2003
    Pasal 60 strict), reset status ke ONGOING dengan end_date baru
- `findExpiringSoon($companyId)` — list probation akan berakhir H+14
- `findExpiredWithoutDecision($companyId)` — list yang lewat tanpa keputusan
- `autoPassExpired($companyId)` — auto-PASS sesuai UU (kalau lewat 3 bulan
  tanpa keputusan tertulis, otomatis jadi karyawan tetap)

### Controller baru

**`backend/app/Controllers/Api/V1/OfferController.php`** — 9 endpoint:
- `GET /candidates/{id}/offers` — list per kandidat
- `POST /candidates/{id}/offers` — create DRAFT
- `GET /offers` — list cross-candidate (HR dashboard)
- `GET /offers/{id}` — detail
- `PUT /offers/{id}` — update DRAFT
- `DELETE /offers/{id}` — soft-delete DRAFT only
- `POST /offers/{id}/send` — DRAFT → SENT
- `POST /offers/{id}/respond` — record ACCEPTED/REJECTED (HR proxy)
- `POST /offers/{id}/withdraw` — withdraw

**`backend/app/Controllers/Api/V1/ProbationController.php`** — 3 endpoint:
- `GET /probation/expiring` — H+14 reminder list
- `GET /probation/expired` — overdue list
- `POST /employees/{id}/probation/decide` — record decision

### Cron command

**`backend/app/Commands/CheckProbationDeadline.php`** (`hr:check-probation`)
- Daily scan probation berakhir H-14 (reminder), H-7 (urgent), expired (auto-pass)
- Notifikasi ke supervisor langsung + HR Manager (role 2)
- Email kalau urgent (H-7 atau lewat)
- Audit log severity sesuai (NOTICE/WARNING/CRITICAL)
- Crontab: `0 8 * * * php spark hr:check-probation`

### Modifikasi

- `backend/app/Config/Services.php` — register `offer()` & `probation()`
- `backend/app/Config/Routes.php` — registrasi 12 route baru
- `backend/app/Libraries/RecruitmentService.php` — `convertToEmployee()`
  panggil `Services::probation()->applyDefaults()` setelah employee insert
  (best-effort, kegagalan tidak block hire)
- `backend/app/Database/Seeds/TaskTemplatesSeeder.php` — tambah 3 task
  milestone di template ONBOARD_DEFAULT:
  - `MILESTONE_30D` (H+30) — 30-Day Check-in
  - `MILESTONE_60D` (H+60) — 60-Day Review
  - `MILESTONE_90D` (H+90) — 90-Day Evaluation (Probation Decision)

### Tests

- `backend/tests/Unit/OfferValidationTest.php` — 18 unit tests / 25 assertions
  - Employment status validation
  - Probation months 0-3 (UU 13/2003 Pasal 60)
  - PKWT cannot have probation (PP 35/2021 Pasal 12)
  - Basic salary positive
  - Decision validation (ACCEPTED/REJECTED)
  - Withdraw reason min 5 char
  - Allowances normalization
  - Constants sanity (DEFAULT_RESPONSE_WINDOW_DAYS, STATUSES)

---

## Cara Pakai (HR Manual)

### Offer letter workflow

1. **Stage OFFER** — kandidat sudah lewat interview user, masuk pipeline OFFER
2. **Create offer DRAFT**:
   ```
   POST /api/v1/candidates/123/offers
   {
     "basic_salary": 10000000,
     "employment_status": "PKWTT",
     "probation_months": 3,
     "proposed_join_date": "2026-06-01",
     "joining_bonus": 5000000,
     "allowances": [
       {"label": "Transport", "amount": 500000, "is_taxable": true},
       {"label": "Lunch", "amount": 1000000, "is_taxable": false}
     ],
     "benefits": {"bpjs_kesehatan_class": 1, "extra_leave_days": 2},
     "response_deadline": "2026-05-25"
   }
   ```
3. **HR review** dan adjust kalau perlu (`PUT /offers/{id}`)
4. **Send ke kandidat** (`POST /offers/{id}/send`):
   - Status DRAFT → SENT
   - Notifikasi email + in-app ke kandidat dengan link portal
5. **Kandidat respond** (lewat portal candidate atau HR proxy):
   - ACCEPTED → auto-progress kandidat ke HIRED, HR dapat notifikasi siap hire
   - REJECTED → status REJECTED, alasan tercatat
6. **Negosiasi**: kalau kandidat minta perubahan, HR `withdraw` offer lama
   dengan reason, lalu buat offer baru. Audit trail jelas.

### Probation flow (PKWTT)

1. **Saat hire convertion** dengan employment_status=PKWTT:
   - `probation_end_date` auto-set = join_date + 3 bulan
   - `probation_status` = 'ONGOING'
2. **H-14 sebelum end_date**:
   - Cron `hr:check-probation` kirim reminder ke supervisor + HR
3. **HR/supervisor decide** sebelum end_date:
   ```
   POST /api/v1/employees/123/probation/decide
   {
     "decision": "PASSED" | "FAILED" | "EXTENDED",
     "note": "...",
     "new_end_date": "2026-08-01"  // hanya untuk EXTENDED
   }
   ```
   - PASSED: karyawan resmi tetap
   - FAILED: resign_date di-set today, lanjut Severance untuk kompensasi
   - EXTENDED: validate total ≤ 3 bulan dari join_date, reset ONGOING
4. **Kalau lewat tanpa keputusan**:
   - Cron auto-PASS dengan audit CRITICAL
   - Sesuai UU 13/2003 Pasal 60: otomatis jadi karyawan tetap

### Onboarding milestones (ONB-1)

Setelah hire, sistem otomatis generate 3 task milestone untuk supervisor:
- **30-Day Check-in** (H+30): cek adaptasi awal, blocker, training need
- **60-Day Review** (H+60): assessment performa, 2-way feedback, set goal
- **90-Day Evaluation** (H+90): probation decision (PASS/FAIL/EXTEND)

Task ini muncul di OnboardingBoard sebagai task SUPERVISOR mandatory.

---

## Verifikasi

```bash
# Backend tests
cd backend
vendor/bin/phpunit --no-coverage
# 511 tests, 1184 assertions, OK

# Audit call scanner
php scripts/find_bad_audit_calls.php
# Tidak ada panggilan shorthand keliru. Aman.

# Migration baru
php spark migrate
# Run migration 52: RecruitmentPolishSprint2

# Re-seed task templates (kalau perlu update ONBOARD dengan milestones)
php spark db:seed TaskTemplatesSeeder

# Test cron probation
php spark hr:check-probation --dry-run
```

Setup cron harian:
```cron
0 8 * * *  cd /path/to/backend && php spark hr:check-probation >> writable/logs/probation.log 2>&1
```

---

## Cakupan Compliance UU yang Dipenuhi

- ✅ **UU 13/2003 Pasal 60** — Probation max 3 bulan untuk PKWTT, otomatis
  jadi tetap kalau lewat tanpa keputusan tertulis. Validasi probation_months
  + auto-pass cron.
- ✅ **PP 35/2021 Pasal 12** — PKWT tidak boleh ada probation. Backend reject
  payload dengan probation_months > 0 untuk employment_status=PKWT.
- ✅ **PP 35/2021 Pasal 36** — FAILED probation → wajib lanjut ke severance
  untuk kompensasi sesuai alasan PHK. ProbationService::decide() set
  resign_date + reason supaya HR aware harus lanjut ke modul Severance.
- ✅ **Best practice 30/60/90-day onboarding** — milestone task otomatis
  ter-generate untuk supervisor di template ONBOARD_DEFAULT.

---

## Polish Pass — 26 Mei 2026

Item-item yang awalnya di-defer dari Sprint 2 sekarang sudah selesai:

### Backend
- ✅ **Offer expire cron** — `php spark hr:check-offer-expiry` (file
  `backend/app/Commands/CheckOfferExpiry.php`). Daily at 23:50 disarankan.
  Output: list offer yang akan/sudah di-expire dengan days overdue. Notifikasi
  HR otomatis dari `OfferService::expire()`.
- ✅ **OFFER_LETTER seed template** — di-tambahkan `TPL-OFFER` di
  `ApprovalAndContractSeeder` dengan body lengkap (jabatan, kompensasi,
  tunjangan, masa kerja, deadline). Re-seed via:
  ```bash
  php spark db:seed ApprovalAndContractSeeder
  ```

### Frontend
- ✅ **Offer modal di PipelineBoard** — tombol "Kelola Offer" muncul di
  candidate detail saat stage = OFFER (pre-Hire). Modal full:
  - List riwayat offer per kandidat dengan status badge (DRAFT/SENT/ACCEPTED/...)
  - Form create draft dengan validasi UU (probation 0-3 bulan, PKWT no probation)
  - Allowances editor dinamis (label, amount, fixed/variable, taxable flag)
  - Total compensation preview real-time
  - Action: Kirim (DRAFT→SENT), Withdraw (DRAFT/SENT)
  - Block create kalau ada offer aktif (force HR withdraw dulu)
- ✅ **ProbationDashboard.vue** (`/hr/probation`) — HR menu baru dengan:
  - Tab "Akan Habis" (H+14 ke depan) & "Sudah Lewat"
  - Quick stats card (total expiring, overdue, aktif)
  - Decision modal dengan 3 button: PASS / EXTEND / FAIL
  - Color-coded urgency (kuning H-7, merah overdue)
  - FAIL confirmation extra (karena trigger resign_date + offboarding)
  - Banner UU 13/2003 Pasal 60 untuk overdue tab
- ✅ **Probation badge di EmployeeModal** — saat view mode, field "Akhir
  Probation" sekarang display `probation_status` badge + link ke
  `/hr/probation` kalau ONGOING.

### Total deliverable Sprint 2 + polish
- Backend: 2 services + 2 controllers + 2 commands + 1 migration + seeder
- Frontend: 1 page baru + integrasi di 2 page existing + 1 route
- Tests: 18 unit tests Sprint 2 + 25 Sprint 4 = 43 baru hijau (557 total)

---

## Belum Dikerjakan (defer ke sprint berikutnya)

Tidak ada untuk Sprint 2 — semua scope sudah lengkap.

Sprint 4 sudah selesai (lihat `docs/SPRINT_4_TERMINATION_POLISH.md`).
Item low-priority dari `LIFECYCLE_AUDIT_2026.md` yang belum dikerjakan:
- **MPP-1** Budget periods linkage
- **OD-3** Succession planning
- **REC-3** MCU integration
- **REC-2** Background check / referensi tracking
- **HIRE-3** Preboarding period (signed offer → join_date) — sebagian
  sudah ke-cover lewat onboarding tasks dengan due_offset_days, tapi
  reminder pre-join untuk IT/supervisor belum ada
- **ONB-2** Buddy / mentor system
- **CC-1** Audit log granularity untuk semua read PII (UU PDP enforcement)
