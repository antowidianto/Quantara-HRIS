# Employee Transfer (Mutasi Karyawan)

Modul khusus untuk pemindahan karyawan antar **branch / department / posisi /
work location** tanpa menyentuh grade dan basic_salary. Workflow ringan
1-tier (HR approval) supaya cocok untuk operasional harian seperti rotasi
sales, mutasi cabang, atau redeploy karyawan.

## Kapan pakai modul ini vs JIC?

| Aspek | Mutasi (modul ini) | JIC (Job Information Change) |
|---|---|---|
| Perubahan grade | ❌ Tidak | ✅ Boleh (PROMOTION/DEMOTION) |
| Perubahan basic_salary | ❌ Tidak | ✅ Boleh |
| Approval | 1 tier (HR) | Supervisor → Dept Head → HR → BOD |
| UMP check | ❌ | ✅ |
| SK auto-generate | Opsional (manual upload) | ✅ |
| Employee consent | Opsional | ✅ wajib utk DEMOTION non-disipliner |
| Kompleksitas UX | Ringan, 1 form | Multi-step |

Aturan praktis: **kalau cuma pindah lokasi/dept/posisi setara, pakai Mutasi.
Kalau ada konsekuensi upah / grade, pakai JIC.**

## Database

Tabel: `employee_transfers` (migration `2024-01-01-000035_CreateEmployeeTransfers`)

Field penting:
- `transfer_type`: BRANCH | DEPARTMENT | POSITION | WORK_LOCATION | COMBINED
- `reason`: OPERATIONAL | EMPLOYEE_REQUEST | ROTATION | REORGANIZATION |
  PROMOTION_PREP | PERSONAL | OTHER
- Snapshot `old_*` (branch, department, position, work_location, manager,
  supervisor) — diambil saat submit, immutable supaya audit trail historis
  akurat
- `new_branch_id`, `new_department_id`, `new_position_id`, `new_work_location_id`
- `effective_date`: tanggal mulai berlaku
- `requires_consent` + `consent_status` + `consent_signed_path`: opsional
  untuk mutasi yang signifikan (mis. lintas pulau)
- `status`: DRAFT → SUBMITTED → APPROVED → IMPLEMENTED (atau REJECTED/CANCELLED)

## Workflow

```
DRAFT
  └─ submit() ──> SUBMITTED
                    ├─ approve() ──> APPROVED ──> implement() ──> IMPLEMENTED
                    └─ reject() ──> REJECTED

(any of DRAFT/SUBMITTED/APPROVED) ──> cancel() ──> CANCELLED
```

Pada saat **IMPLEMENTED**:
- `employees.branch_id|department_id|position_id|work_location_id` di-update
- `ReportingChainService::recomputeForCompany()` dipanggil supaya
  `manager_id` ikut menyesuaikan struktur dept tujuan
- `AllowanceService::recomputeEmployee()` dipanggil kalau branch atau
  work_location berubah, supaya tunjangan transport/makan ikut update
- Audit log `employee_transfer` (status change) + `employee` (field changes)

## Auto-apply (cron)

Command harian:

```bash
php spark hr:apply-transfers
```

Akan loop semua transfer status APPROVED dengan `effective_date <= today`
dan langsung apply. Cocok dijalankan tiap pagi 06:00 lewat cron / scheduler
OS.

Kalau perlu apply manual tanpa nunggu effective_date, HR dengan permission
`employee_transfer.implement` bisa klik **"Apply Sekarang"** di halaman
detail mutasi.

## Permissions

| Slug | Akses |
|---|---|
| `employee_transfer.view` | Lihat list & detail |
| `employee_transfer.create` | Buat draft + submit + cancel draft sendiri |
| `employee_transfer.approve` | Approve / reject SUBMITTED |
| `employee_transfer.implement` | Apply mutasi APPROVED (instan, sebelum effective_date) |

Default grant: super admin (1) + HR admin (2) dapat semua. Manager (3)
dapat `view` + `create` (boleh propose mutasi anak buah).

## API Endpoints

```
GET    /api/v1/employee-transfers                       List + filter (status, transfer_type, employee_id, date range)
GET    /api/v1/employee-transfers/options               Enum (transfer_types, reasons, statuses)
GET    /api/v1/employee-transfers/(:num)                Detail
POST   /api/v1/employee-transfers                       Create (body.submit=true → langsung SUBMITTED)
PUT    /api/v1/employee-transfers/(:num)                Update draft
DELETE /api/v1/employee-transfers/(:num)                Soft delete draft
POST   /api/v1/employee-transfers/(:num)/submit         Draft → SUBMITTED
POST   /api/v1/employee-transfers/(:num)/approve        SUBMITTED → APPROVED
POST   /api/v1/employee-transfers/(:num)/reject         SUBMITTED → REJECTED
POST   /api/v1/employee-transfers/(:num)/cancel         → CANCELLED
POST   /api/v1/employee-transfers/(:num)/implement      APPROVED → IMPLEMENTED (apply ke records)
POST   /api/v1/employee-transfers/(:num)/consent        Tandai consent karyawan
```

## Frontend

Halaman: `Mutasi Karyawan` di sidebar → **HR** → `/hr/transfers`
(`frontend/src/views/HR/EmployeeTransfers.vue`).

Fitur utama:
- List dengan filter status, tipe, karyawan
- Form create dengan toggle field tujuan dinamis sesuai transfer_type
- Detail modal dengan comparison table "Sebelum → Sesudah"
- Aksi cepat per row: Submit / Approve / Reject / Cancel / Apply

## Compliance Indonesia

UU 13/2003 jo. UU 6/2023:
- **Pasal 32**: penempatan tenaga kerja wajib memperhatikan harkat,
  martabat, hak asasi, dan keselamatan kerja
- **Asas konsensual**: mutasi yang mengubah lokasi tugas signifikan
  (mis. lintas pulau, lintas pulau, lintas kota jauh) sebaiknya dengan
  persetujuan tertulis. Modul ini menyediakan flag `requires_consent` +
  `consent_signed_path` untuk dokumentasi
- **Hak atas tunjangan baru**: kalau perpindahan branch mengubah jarak ke
  rumah / zona UMK, sistem otomatis trigger recompute allowance saat
  IMPLEMENTED

## Test

Smoke test backend: `backend/scripts/test-employee-transfer-flow.php`

Coverage:
1. Create draft → row tersimpan dengan snapshot lengkap
2. Submit → status SUBMITTED, transfer_number `MUT-YYYYMM-NNNN`
3. Approve oleh user lain → status APPROVED
4. Implement → records `employees` ter-update, status IMPLEMENTED
5. Cross-tenant block (target branch beda company) → 422
6. No-change validation (semua field tujuan sama dgn posisi sekarang) → 422
7. Approve before consent (requires_consent=1, status PENDING) → 422

```bash
cd backend && php scripts/test-employee-transfer-flow.php
```

Hasil: 15 passed, 0 failed.