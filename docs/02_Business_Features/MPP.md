# Manpower Planning (MPP)

Modul untuk mengelola **pengajuan kebutuhan SDM** (request for headcount) yang
melibatkan tiga peran:
- **Line Manager / N+1** sebagai pengaju
- **HRD Manager / Direksi** sebagai approver
- **Talent Acquisition (TA)** sebagai eksekutor (buat lowongan, hiring, sampai onboarding)

## Konsep MPN vs MPP

- **MPN (Manpower Need)** — kebutuhan karyawan tahunan yang sudah masuk
  RKAP / budget perusahaan. MPN biasanya disusun di akhir tahun untuk
  perencanaan tahun depan.
- **MPP (Manpower Planning Request)** — pengajuan aktual yang muncul di
  HRIS, ada **dua tipe**:
  - `BUDGET` — kebutuhan yang sudah ada di MPN (approval ringan)
  - `UNBUDGET` — kebutuhan di luar rencana, butuh approval lebih tinggi
    (Direksi) dan justifikasi tertulis

Modul ini berfokus pada MPP. MPN tahunan bisa diturunkan ke MPP per
posisi saat dibutuhkan, atau di-track terpisah di luar HRIS (mis.
spreadsheet RKAP).

## Alur Lengkap

```
┌─────────────────────────────────────────────────────────────────┐
│  N+1 (Line Manager)                                             │
│   1. Buat MPP — tipe BUDGET / UNBUDGET, qty, justifikasi        │
│   2. Submit                                                      │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│  HRD Manager (step 1) — approve / reject                        │
│  Direksi (step 2, hanya UNBUDGET)                               │
└─────────────────────────────────────────────────────────────────┘
                              ↓ APPROVED
┌─────────────────────────────────────────────────────────────────┐
│  Talent Acquisition                                             │
│   3. Buat job_posting dari MPP (bisa di-split per cabang)       │
│   4. Edit detail lowongan                                        │
│   5. Publish ke Career Portal                                    │
└─────────────────────────────────────────────────────────────────┘
                              ↓ HIRING
┌─────────────────────────────────────────────────────────────────┐
│  Pelamar apply via Career Portal → masuk ATS pipeline           │
│   NEW → SCREENING → INTERVIEW_HR → INTERVIEW_USER → OFFER       │
│       → HIRED                                                    │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│  TA: Hire kandidat (convert candidate → employee)                │
│   - employees row baru                                           │
│   - user account auto-create                                     │
│   - Onboarding tasks otomatis di-trigger                         │
│   - MPP.hired_count + 1                                          │
└─────────────────────────────────────────────────────────────────┘
                              ↓ FILLED (saat hired_count == headcount)
┌─────────────────────────────────────────────────────────────────┐
│  HR/TA: Close MPP (manual)                                      │
└─────────────────────────────────────────────────────────────────┘
```

## State Machine

```
DRAFT ──submit──▶ SUBMITTED ──approve──▶ APPROVED ──create-posting──▶ HIRING
                       │                                                  │
                       │                                                  │ hire candidates
                       │                                                  ▼
                       │                                              FILLED
                       │                                                  │
                       ├── reject ──▶ REJECTED                            │
                       │                                                  │ manual close
                       │                                                  ▼
                       └── cancel ──▶ CANCELLED                       CLOSED
```

Transisi:
| Dari | Ke | Trigger | Permission |
|------|------|---------|-----------|
| DRAFT | SUBMITTED | `POST /mpp/{id}/submit` | requester (mpp.submit) |
| DRAFT | (deleted) | `DELETE /mpp/{id}` | requester |
| DRAFT, SUBMITTED | CANCELLED | `POST /mpp/{id}/cancel` | requester / approver |
| SUBMITTED | APPROVED | `POST /mpp/{id}/approve` (semua step PASSED) | mpp.approve |
| SUBMITTED | REJECTED | `POST /mpp/{id}/reject` | mpp.approve |
| APPROVED | HIRING | `POST /mpp/{id}/create-posting` (otomatis) | mpp.fulfill |
| HIRING | FILLED | candidate HIRED + converted (otomatis dari hook) | sistem |
| HIRING, FILLED | CLOSED | `POST /mpp/{id}/close` | mpp.approve / mpp.fulfill |

## Approval Workflow

Langkah approval di-generate otomatis saat submit, sesuai `request_type`:

```php
// app/Libraries/ManpowerService.php
DEFAULT_APPROVAL_STEPS = [
    'BUDGET'   => [['step' => 1, 'approver_role' => 'HRD_MANAGER']],
    'UNBUDGET' => [
        ['step' => 1, 'approver_role' => 'HRD_MANAGER'],
        ['step' => 2, 'approver_role' => 'DIRECTOR'],
    ],
];
```

Setiap step disimpan di `manpower_request_approvals` dengan status
`PENDING`. Saat user dengan `mpp.approve` mengeksekusi `approve`,
service mencari step PENDING dengan urutan terkecil dan menutup-nya.
Bila step terakhir, MPP otomatis pindah ke `APPROVED`.

Pada reject, semua step PENDING sisa di-mark `SKIPPED`.

## Permissions

| Slug | Deskripsi | Default role |
|------|-----------|--------------|
| `mpp.view` | Lihat list & detail MPP | super, hr, manager |
| `mpp.submit` | Buat & submit pengajuan | super, hr, manager |
| `mpp.approve` | Approve / reject step | super, hr |
| `mpp.fulfill` | Buat lowongan dari MPP | super, hr |

Tenant-scope diterapkan via `tenant()->applyScope()` pada semua query
list. User non-superadmin hanya melihat MPP perusahaannya.

## Data Model

### `manpower_requests`
Header pengajuan. Field penting:
- `reference_number` — `MPP-<YEAR>-<5digit>`, unik per company
- `request_type` — `BUDGET` / `UNBUDGET`
- `reason` — `NEW_POSITION`, `REPLACEMENT`, `EXPANSION`, `SEASONAL`, `OTHER`
- `status` — state machine di atas
- `headcount` — jumlah orang yang dibutuhkan
- `hired_count` — di-sync otomatis saat candidate HIRED
- `proposed_title`, `job_description`, `requirements`, `salary_min/max` —
  carry-over ke `job_postings` saat fulfill
- `justification` — wajib untuk UNBUDGET

### `manpower_request_approvals`
Jejak approval per step. Field penting:
- `step` — urutan (1, 2, ...)
- `approver_role` — hint role yang diharapkan (informasi, bukan enforcement)
- `decision` — `PENDING` / `APPROVED` / `REJECTED` / `SKIPPED`
- `approver_user_id`, `decided_at`, `comment`

### `manpower_request_postings`
Pivot N:M antara MPP dan `job_postings`. Satu MPP boleh menghasilkan
beberapa lowongan, mis. headcount 5 di-split menjadi 2 lowongan
(Jakarta 3 + Bandung 2).

## Hook ke Recruitment

`RecruitmentController::hireCandidate()` memanggil
`ManpowerService::syncForJobPosting($jobPostingId)` setelah convert
candidate ke employee. Service mencari semua MPP yang tertaut posting
tersebut via pivot, lalu meng-update `hired_count` dan auto-promote
status ke `FILLED` bila `hired_count >= headcount`.

Best-effort: kalau sync gagal, hire candidate tetap sukses (hire
adalah operasi kritis, MPP sync adalah housekeeping).

## Hook ke Resignation (Auto-create MPP)

`ResignationController::approve()` di tahap **HR approve** memanggil
`ManpowerService::autoCreateForResignation()` setelah set
`employees.resign_date`. Hasilnya:

- MPP DRAFT terbuat otomatis dengan:
  - `request_type` = `BUDGET`
  - `reason` = `REPLACEMENT`
  - `replacement_for_employee_id` = ID employee resigner
  - `department_id`, `position_id`, `branch_id` carry-over dari profile
  - `target_start_date` = tanggal efektif berhenti (`resign_date`)
  - `proposed_title` = nama posisi resigner (atau "Pengganti {nama}")
  - `headcount` = 1
- HRD dapat **review & submit** MPP via halaman Manpower Planning, atau
  **cancel** kalau memang posisi mau dirombak / ditiadakan.
- Idempoten: kalau resignation di-approve ulang (mis. setelah cancel
  HR), tidak duplikasi — ada cek `replacement_for_employee_id` aktif.
- Best-effort: kegagalan auto-create tidak membatalkan approval
  resignation.

## Integrasi ke Org Structure (Bagan)

`OrgStructureController::index` mengembalikan field `open_mpps[]` per
department, berisi MPP yang masih DRAFT/SUBMITTED/APPROVED/HIRING di
department tersebut. Frontend menampilkan-nya sebagai **slot rekrutmen**
di bawah daftar posisi di kartu department dengan badge status berwarna.

User dengan akses Org Structure bisa:
- **Drag-and-drop** MPP slot antar department (endpoint
  `POST /org-structure/move-mpp`). Hanya berlaku saat MPP belum punya
  job_postings (DRAFT/SUBMITTED/APPROVED). MPP yang sudah HIRING terkunci
  ke department asal.
- **Klik** MPP slot → redirect ke `/recruitment/mpp?ref=...` untuk lihat
  detail + lifecycle.

Total MPP aktif di-tampilkan sebagai stat di header Org Structure
(`stats.total_open_mpps`).

## API Endpoint

Semua endpoint di-prefix `/api/v1`:

| Method | Path | Deskripsi |
|--------|------|-----------|
| GET    | `/manpower-requests` | List dengan filter (status, type, dept, mine) |
| POST   | `/manpower-requests` | Create (DRAFT). Set `submit_now: true` untuk langsung submit. |
| GET    | `/manpower-requests/{id}` | Detail + approvals + postings + remaining HC |
| PUT    | `/manpower-requests/{id}` | Update DRAFT (hanya pengaju) |
| DELETE | `/manpower-requests/{id}` | Hapus DRAFT |
| POST   | `/manpower-requests/{id}/submit` | DRAFT → SUBMITTED |
| POST   | `/manpower-requests/{id}/approve` | Approve step saat ini |
| POST   | `/manpower-requests/{id}/reject` | Reject + alasan (min 5 chars) |
| POST   | `/manpower-requests/{id}/cancel` | Cancel (DRAFT/SUBMITTED) |
| POST   | `/manpower-requests/{id}/create-posting` | Buat job_postings dari MPP |
| POST   | `/manpower-requests/{id}/close` | Close manual (HIRING/FILLED) |
| GET    | `/manpower-requests/stats` | Aggregate per status |
| GET    | `/manpower-requests/inbox` | Pengajuan SUBMITTED (untuk approver) |

## Frontend

Halaman utama: `frontend/src/views/Recruitment/MppList.vue`
- Lifecycle stepper visual (DRAFT → SUBMITTED → ... → CLOSED) dengan
  dot per status
- Stats cards di atas (count + headcount per status)
- Filter status, type, "hanya pengajuan saya"
- Modal form create / edit DRAFT dengan opsi "Simpan & Ajukan"
- Modal detail dengan approval timeline + linked postings
- Modal "Buat Lowongan" untuk TA, dengan auto-redirect ke pipeline board

Akses via sidebar: **HR & Workforce → Manpower Planning (MPP)**, atau
tombol "Manpower Planning" di header Recruitment / Lowongan Kerja.

## Skenario Demo

1. **Login sebagai manager** (role 3 — punya `mpp.submit`):
   - Klik **Manpower Planning** → **Pengajuan Baru**
   - Isi: Senior Backend Developer, BUDGET, REPLACEMENT, qty 1
   - Klik **Simpan & Ajukan** → status berubah jadi SUBMITTED

2. **Login sebagai HR** (role 2 — punya `mpp.approve`, `mpp.fulfill`):
   - Buka **Manpower Planning** → klik MPP yang masih SUBMITTED
   - Klik **Approve** di footer detail → status APPROVED
   - Klik **Buat Lowongan** → modal terbuka dengan field pre-filled
   - Klik **Buat Lowongan** lagi → job_postings DRAFT terbuat
   - Modal konfirmasi muncul → klik **Buka** untuk lanjut ke pipeline

3. **Di Recruitment / Pipeline**:
   - Edit posting bila perlu, lalu **Open** + **Publish ke Career Portal**
   - Pelamar apply via halaman public `/careers/{slug}/jobs/{jobSlug}/apply`
   - HR proses pipeline NEW → ... → HIRED
   - **Hire Candidate** → employee row terbuat, onboarding auto-trigger
   - Buka MPP detail lagi → `hired_count` sudah `1/1`, status `FILLED`

4. **Close MPP** (opsional):
   - Klik **Close MPP** di footer detail untuk menutup tracking