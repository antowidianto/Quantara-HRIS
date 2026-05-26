# Sprint 3 — OD Loop (Selesai)

**Tanggal selesai:** 26 Mei 2026
**Cakupan:** 3 item Organizational Development dari `docs/LIFECYCLE_AUDIT_2026.md`
**Hasil:** 532 tes hijau (511 Sprint 2 + 21 baru), tidak ada regresi

---

## Ringkasan Item yang Dikerjakan

| Item | Deskripsi | Status |
|------|-----------|--------|
| OD-1 | Performance review → JIC auto-generate hook | ✅ Field `recommendation_action_id` di `performance_reviews` |
| OD-2 | IDP framework (Individual Development Plan) | ✅ Backend full + 10 endpoint API + auto-bootstrap dari review |
| CC-3 | Approval delegation wiring untuk MPP | ✅ ApprovalDelegationResolver + integration di ManpowerService |

---

## Yang Dibuat

### Database
**Migration 53** (`2024-01-01-000053_ODLoopSprint3.php`):

- Tabel baru `individual_development_plans` — IDP per karyawan per cycle:
  - `gaps_json`, `target_competencies_json`, `recommended_trainings_json`,
    `action_items_json` (semua flexible JSON arrays)
  - Status flow: DRAFT → ACTIVE → COMPLETED (atau CANCELLED)
  - `progress_percent` auto-computed dari action_items
  - Link ke `cycle_id` (performance_cycles) & `review_id` (performance_reviews)
- Field baru di `performance_reviews`:
  - `recommendation_action_id` — FK ke job_information_changes (OD-1 hook)
- 3 permission baru: `idp.view`, `idp.manage`, `idp.view_own`

### Services baru

**`backend/app/Libraries/IdpService.php`** — Individual Development Plan
- `createFromReview($reviewId, $userId)` — auto-bootstrap IDP DRAFT dari
  performance_reviews.improvements field. Idempotent — kalau review sudah
  punya IDP, return existing
- `create($data, $userId)` — manual create IDP standalone (tanpa review)
- `update($idpId, $data, $userId)` — update dengan rule per status:
  - DRAFT: semua field boleh edit
  - ACTIVE: hanya progress (action_items.status) + employee_notes
  - COMPLETED/REVIEWED: hanya manager_notes
- `finalize($idpId)` — DRAFT → ACTIVE, validasi minimum 1 action item
- `complete($idpId)` — ACTIVE → COMPLETED dengan optional manager notes
- `cancel($idpId, $reason)` — DRAFT/ACTIVE → CANCELLED dengan reason ≥5 char
- `computeProgress($actionItems)` — pure helper hitung % dari status
  COMPLETED / total
- `parseImprovementsToGaps($text)` — parse text bullet/numbered list jadi
  structured gaps[] entries

**`backend/app/Libraries/ApprovalDelegationResolver.php`** — pure helper
untuk module yang tidak pakai ApprovalEngine (MPP, JIC, Resignation, dll)
- `isOnDelegation($userId, $module)` — cek apakah user sedang delegasi
- `getActiveDelegate($userId, $module)` — get delegate aktif (prefer
  module-specific, fallback ke wildcard NULL)
- `resolveCanApprove($expectedUserId, $companyId, $module)` — daftar
  user_id yang sah approve (original + delegate)
- `findDelegatorsFor($delegateUserId, $module)` — list user yang
  mendelegasikan ke delegate (untuk inbox query)
- `canApproveOnBehalfOf($actorId, $expectedId, $companyId, $module)` —
  guard cek di approve handler

### Controller baru

**`backend/app/Controllers/Api/V1/IdpController.php`** — 10 endpoint:
- `GET /idp` — list cross-employee (HR dashboard, dengan filter)
- `GET /idp/my` — list IDP karyawan login
- `POST /idp` — create manual standalone
- `POST /idp/from-review/(:num)` — auto-bootstrap dari review (idempotent)
- `GET /idp/(:num)` — detail (karyawan ybs auto-allowed)
- `PUT /idp/(:num)` — update (rules per status)
- `DELETE /idp/(:num)` — soft-delete
- `POST /idp/(:num)/finalize` — DRAFT → ACTIVE
- `POST /idp/(:num)/complete` — ACTIVE → COMPLETED
- `POST /idp/(:num)/cancel` — DRAFT/ACTIVE → CANCELLED

### Modifikasi

- `backend/app/Config/Services.php` — register `idp()` & `approvalDelegationResolver()`
- `backend/app/Config/Routes.php` — registrasi 10 IDP endpoint
- `backend/app/Libraries/PerformanceService.php` — `finalizeReview()` sekarang
  auto-bootstrap IDP DRAFT dari `improvements` field. Best-effort — kegagalan
  IDP tidak block finalize. Karyawan/manager bisa lanjut edit IDP & finalize
  via `/idp/{id}/finalize`.
- `backend/app/Libraries/ManpowerService.php` — `approveStep()` sekarang
  validate caller via `ApprovalDelegationResolver::canApproveOnBehalfOf()`.
  Kalau approver yang seharusnya (`expected_approver_user_id`) sedang cuti
  & punya delegasi aktif untuk module='MPP' atau wildcard, delegate-nya
  boleh approve mewakili. Kalau caller bukan original/delegate → 422 dengan
  pesan jelas. Comment di-prefix `[DELEGATED dari user #X]` untuk audit trail.

### Tests

- `backend/tests/Unit/IdpAndDelegationTest.php` — 21 unit tests / 35 assertions
  - `IdpService::computeProgress()` rules (8 tests)
  - `IdpService::parseImprovementsToGaps()` rules (8 tests)
  - IDP statuses constant sanity
  - ApprovalDelegationResolver core logic (4 tests, mirror without DB)

---

## Cara Pakai

### Auto-bootstrap IDP dari review

1. Manager submit `performance_reviews` dengan `improvements` field text
   (`POST /performance-reviews/{id}/submit`):
   ```
   {
     "goal_scores": { "1": 85, "2": 70 },
     "competency_scores": { "communication": 75, "ownership": 80 },
     "strengths": "...",
     "improvements": "- Komunikasi tim perlu ditingkatkan\n- Time management\n- Public speaking",
     "development_plan": "..."
   }
   ```
2. Karyawan acknowledge (`POST /performance-reviews/{id}/acknowledge`)
3. HR finalize (`POST /performance-reviews/{id}/finalize`)
   - Sistem otomatis bootstrap IDP DRAFT dengan 3 gap entries dari
     `improvements` field (parsed bullet list)
4. Manager review IDP DRAFT, isi `target_competencies`, `action_items` dengan
   deadline & owner, lalu finalize:
   ```
   POST /api/v1/idp/{idpId}/finalize
   ```
5. Karyawan track progress dengan update `action_items[].status` ke COMPLETED
   selama plan period berjalan
6. Manager `complete()` IDP di akhir periode

### Approval delegation wiring (MPP)

Skenario: HR Manager (user #5) cuti tahunan 1 minggu, MPP yang seharusnya
dia approve di-delegasikan ke Asst HR Manager (user #7).

1. HR Manager set delegasi via UI / API:
   ```
   POST /api/v1/approval-delegations
   {
     "delegate_to_user_id": 7,
     "module": "MPP",
     "date_from": "2026-06-01",
     "date_to": "2026-06-08",
     "reason": "Cuti tahunan"
   }
   ```
2. MPP submitted ke approver `HRD_MANAGER` (resolved ke user #5 dari
   organization_structures)
3. User #7 (delegate) bisa approve:
   ```
   POST /api/v1/manpower-requests/{id}/approve
   ```
   - Backend cek: caller adalah delegate aktif user #5 untuk module='MPP'
   - Approve berhasil, comment di-prefix `[DELEGATED dari user #5]`
4. User #5 (original) tetap boleh approve juga (delegasi tidak menghilangkan
   kewenangan)
5. Random user (#9) yang bukan delegate akan ditolak dengan pesan yang jelas

---

## Verifikasi

```bash
# Backend tests
cd backend
vendor/bin/phpunit --no-coverage
# 532 tests, 1229 assertions, OK

# Audit call scanner
php scripts/find_bad_audit_calls.php
# Tidak ada panggilan shorthand keliru. Aman.

# Migration baru
php spark migrate
# Run migration 53: ODLoopSprint3
```

---

## Belum Dikerjakan (di-defer)

**Frontend UI** untuk Sprint 3 tidak masuk scope karena prioritas wiring
backend. API sudah siap pakai dari Postman / curl untuk testing & integrasi
awal:
- Halaman HR → IDP Dashboard (list, filter cycle/employee, action button)
- Halaman karyawan → My IDP (list + detail + update progress action items)
- Toast banner di MppList saat HR cuti, tampilkan "Anda menerima delegasi
  approval dari user X" di inbox approver

**OD-1 closing-the-loop full** — saat ini hanya field `recommendation_action_id`
di `performance_reviews`. Logic auto-create JIC dari recommendation belum
diimplementasi. Manager bisa input `recommendation` & `recommended_increase_percent`
saat submit review, tapi sistem belum auto-translate jadi `job_information_changes`
DRAFT. Ini bisa di-tambahkan dengan pattern serupa IDP (auto-create di
`PerformanceService::finalizeReview` atau endpoint terpisah `POST /reviews/{id}/generate-jic`).

**ApprovalDelegationResolver wiring untuk module lain**:
- ResignationController (Supervisor & HR approval)
- JicController (Supervisor → Dept Head → HR → BOD)
- EmployeeTransferController
- LeaveController (sudah pakai ApprovalEngine, jadi sudah otomatis dapat
  delegation lewat `ApprovalEngine::expandWithDelegates()` — tidak perlu wiring)

Untuk tambahin wiring ke 4 module di atas, follow pattern yang sudah dipakai
di `ManpowerService::approveStep()` — fetch `expected_approver_user_id` (kalau
schema-nya support), call `Services::approvalDelegationResolver()->canApproveOnBehalfOf()`
sebelum proses approve. Comment delegasi di-prefix `[DELEGATED dari user #X]`.

Sprint berikutnya:
- **Sprint 4** — Termination workflow (OFF-5), exit interview digital
  (OFF-1), Employee 360 view (CC-4), compliance reports (CC-5)