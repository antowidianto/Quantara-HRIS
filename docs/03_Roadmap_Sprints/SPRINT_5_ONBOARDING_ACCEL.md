# Sprint 5 — Onboarding Acceleration via MPP Planning

## Latar belakang masalah

Sebelum sprint ini, ada tiga sumber friksi yang konsisten muncul saat
onboarding karyawan baru:

1. **Job grade, atasan langsung, manager kepegawaian** diisi manual saat
   profil karyawan dibuka oleh HR setelah hire. Akibatnya:
   - HR harus cari informasi tersebut padahal yang tahu adalah requestor
     (line manager / kepala department).
   - Salah input atau lupa input → karyawan baru menggantung di approval
     workflow (cuti, lembur, attendance) selama 1–2 hari.
2. **Saldo cuti** tidak ter-generate otomatis. Karyawan baru harus minta
   ke HR untuk dibukakan, atau saldo baru muncul saat HR mengakses
   halaman leave balance pertama kali. Lebih buruk lagi, cuti yang
   gender-specific (mis. cuti melahirkan untuk wanita, cuti paternity
   untuk pria) tidak terfilter — semua tipe terbuka untuk semua orang.
3. **Asset (laptop, HP, ID card, kendaraan)** dialokasikan saat
   onboarding hari-H. IT belum tahu kebutuhan spesifik karyawan baru
   (laptop developer butuh RAM lebih besar, ID card area produksi vs
   kantor, dst). Karyawan baru menunggu beberapa hari untuk dapat alat
   kerja → produktivitas hari pertama nol.

## Solusi

Memindahkan keputusan-keputusan tersebut ke **fase MPP submission** oleh
requestor. Saat candidate di-hire, semua field auto-apply ke profil
karyawan, saldo cuti auto-seed dengan filter gender, asset
auto-pre-allocate. Onboarding tinggal mencentang penyerahan fisik.

```
[MPP] requestor isi: job_grade, supervisor, manager, planned_assets
   ↓ (approve)
[Job Posting] copy field di atas + planned_assets → job_posting_assets
   ↓ (publish, recruit)
[Candidate HIRED → Employee]
   ├── employees.job_grade_id, supervisor_id, manager_id auto-set
   ├── leave_balances seeded per leave_types × gender_restriction
   └── asset_assignments PRE_ALLOCATED (BAST belum keluar)
       ↓ (onboarding)
[HR/IT klik "Konfirmasi Serah Terima"]
   └── pre_allocated 1 → 2 (handover done)
```

## Yang ditambahkan

### Schema (migration `000056_MppOnboardingPlanning`)

| Tabel                       | Kolom baru                              |
| --------------------------- | --------------------------------------- |
| `manpower_requests`         | `job_grade_id`, `proposed_supervisor_employee_id`, `proposed_manager_employee_id` |
| `manpower_request_assets`   | tabel baru — list planned asset per MPP |
| `job_postings`              | `job_grade_id`, `default_supervisor_employee_id`, `default_manager_employee_id` |
| `job_posting_assets`        | tabel baru — mirror dari MPP plan       |
| `asset_assignments`         | `pre_allocated` (1=reserved, 2=handover done), `source_mpp_id` |
| `employees`                 | `initial_balances_seeded_at` (idempotent flag) |

Semua perubahan idempotent (cek `tableExists` & `getFieldNames` sebelum
modifikasi).

### Library `App\Libraries\OnboardingPlanService`

Pure helpers (tested, no DB):
- `genderMatchesLeaveType($empGender, $restriction): bool`
- `validatePlannedAssetRow($row): array<string>`
- `normalizeGenderForEmployee($candidateGender): ?string`

DB-aware orchestrators:
- `syncMppPlannedAssets($mppId, $plans, $userId)` — replace strategy
- `syncFromMpp($mppId, $jobPostingId, $userId)` — copy fields & assets
  dari MPP ke posting
- `applyPlanningToEmployee($employeeId, $jobPostingId, $overrides)` —
  set job_grade_id / supervisor_id / manager_id ke employee row dengan
  prioritas: overrides → posting → mpp
- `seedInitialLeaveBalances($employeeId, $year=null, $force=false)` —
  generate `leave_balances` per `leave_types`, filter by
  `gender_restriction` vs `employees.gender`
- `preAllocateAssetsForEmployee($employeeId, $jobPostingId, $userId)` —
  buat `asset_assignments` dengan `pre_allocated=1` untuk planned asset
  yang punya specific `asset_id`. Yang tidak specific → intent-only,
  IT pilih unit manual.
- `confirmAssetHandover($assignmentId, $userId)` — flip
  `pre_allocated` 1 → 2 saat HR/IT konfirmasi.

### Wiring

- `Services::onboardingPlan()` di `app/Config/Services.php`.
- `ManpowerService::createJobPostingFromMpp()` panggil
  `syncFromMpp()` setelah insert posting.
- `RecruitmentService::convertToEmployee()` panggil:
  - `applyPlanningToEmployee()`
  - `seedInitialLeaveBalances()`
  - `preAllocateAssetsForEmployee()`
  Semua best-effort — kegagalan satu langkah tidak blok hire.
- `ManpowerController` accept `job_grade_id`,
  `proposed_supervisor_employee_id`, `proposed_manager_employee_id`,
  dan array `planned_assets[]` di create/update.
- Endpoint baru:
  - `GET /manpower-requests/{id}/planned-assets`
  - `PUT /manpower-requests/{id}/planned-assets`

### Frontend (`frontend/src/views/Recruitment/MppList.vue`)

Form MPP punya section baru "Rencana Onboarding":
- Picker job grade (lookup dari `/job-grades`)
- Picker supervisor (server-side employee search)
- Picker manager (server-side employee search)
- Repeater planned_assets dengan kategori, nama, brand/model
  preference, is_required, dan notes per item.

Saat edit MPP DRAFT, planned assets di-lazy-load dari endpoint
`GET /manpower-requests/{id}/planned-assets`.

## Test

`backend/tests/Unit/OnboardingPlanServiceTest.php` — 23 tests, 56
assertions, semua lulus.

```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
.......................                                           23 / 23 (100%)
OK (23 tests, 56 assertions)
```

Cakupan:
- `genderMatchesLeaveType()` — 8 test (F-F match, M-F mismatch, F-M
  mismatch, ALL match, null restriction backward compat, empty string
  restriction, empty/null employee gender blocked dari gender-specific,
  case-insensitive).
- `normalizeGenderForEmployee()` — 3 test (M/F passthrough, lowercase
  uppercased, OTHER/PREFER_NOT_TO_SAY/empty/X → null).
- `validatePlannedAssetRow()` — 11 test (valid row, category required,
  category whitelist, case-insensitive, name min/max length, name
  trimmed, all standard categories accepted, missing name, multiple
  errors collected).
- `ASSET_CATEGORIES` constant sanity — 1 test.

Full suite: 580 tests, 1349 assertions — semua lulus.

## Cara test manual (acceptance)

1. Buat MPP baru sebagai line manager:
   - Isi proposed_title, headcount, dll seperti biasa
   - Isi section "Rencana Onboarding": pilih job grade, supervisor,
     manager, tambah 2-3 planned asset (laptop, ID card, dll).
   - Submit & approve.
2. Convert MPP → Job Posting → publish ke Career Portal.
3. Kandidat apply, HR move stage sampai HIRED, lalu klik Hire.
4. Buka profil employee yang baru di-hire — verifikasi:
   - `job_grade_id`, `supervisor_id`, `manager_id` sudah terisi sesuai
     yang di-plan di MPP (atau yang di-override HR di hire form).
   - Halaman Leave Balance: saldo cuti tahunan ada, cuti melahirkan
     hanya untuk wanita, cuti paternity hanya untuk pria. Pria tidak
     mendapat cuti melahirkan dan sebaliknya.
   - Halaman Assets: ada baris `asset_assignments` dengan flag
     pre-allocated dari planned asset MPP.
5. Saat onboarding hari-H, klik "Konfirmasi Serah Terima" pada asset →
   flag pre_allocated 1 → 2.

## Catatan kompatibilitas

- Semua kolom baru nullable. Instance yang belum jalan migration 56
  tetap berfungsi normal (controller pakai `getFieldNames` cek dulu).
- MPP lama tanpa planning fields tetap bisa di-approve dan convert ke
  posting normal — `applyPlanningToEmployee()` hanya set field yang
  resolved non-null, sisanya HR tetap input manual.
- Saldo cuti existing tidak di-overwrite. `seedInitialLeaveBalances`
  pakai `LeaveBalanceService::ensureBalance` yang skip kalau row sudah
  ada.