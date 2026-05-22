# Job Information Change (JIC) — Promosi / Demosi / Mutasi

Modul untuk mencatat dan memproses perubahan informasi pekerjaan karyawan
(promosi, demosi, mutasi, perubahan upah, perubahan status kepegawaian)
sesuai ketentuan ketenagakerjaan Indonesia.

## Dasar Hukum

| Pasal / Aturan                        | Implementasi |
|---------------------------------------|--------------|
| **UU 13/2003 jo. UU 6/2023 Pasal 31** | Hak karyawan atas pengembangan kompetensi & kesempatan promosi yang setara. |
| **KUHPerdata 1320 (asas konsensual)** | DEMOSI yang menurunkan posisi/upah memerlukan **persetujuan tertulis karyawan**, kecuali sebagai konsekuensi disipliner setelah SP3 sah. |
| **PP 35/2021**                        | Aturan PKWT/PKWTT — perubahan status kepegawaian dicatat sebagai amandemen kontrak. |
| **UU Pengupahan & PP 36/2021**        | Upah baru tidak boleh di bawah UMP/UMK tanpa dasar PKB/PP yang sah. |
| **Kepmenakertrans 100/2004**          | Setiap perubahan jabatan & upah yang material wajib dicatat dalam SK / amandemen kontrak. |

## Workflow

```
DRAFT → PENDING_SUPERVISOR → PENDING_DEPT_HEAD → PENDING_HR
      → [PENDING_BOD?] → [PENDING_EMPLOYEE_CONSENT?] → APPROVED
      → IMPLEMENTED  (dijalankan saat effective_date)
```

| Stage                     | Approver                                  |
|---------------------------|-------------------------------------------|
| `PENDING_SUPERVISOR`      | `employees.supervisor_id` atau `manager_id` dari karyawan target |
| `PENDING_DEPT_HEAD`       | `departments.manager_employee_id` dari dept lama atau baru |
| `PENDING_HR`              | User dengan permission `jic.approve` atau `jic.manage` |
| `PENDING_BOD`             | User dengan permission `jic.manage` (super admin / direksi) — kondisional |
| `PENDING_EMPLOYEE_CONSENT`| Karyawan ybs (cek `employees.user_id`) — kondisional |
| `APPROVED`                | Tunggu effective_date jatuh tempo |
| `IMPLEMENTED`             | Sudah di-apply ke `employees` + amandemen kontrak |

### Kapan Butuh BOD?

`JicService::requiresBodApproval()`:

- `change_type = DEMOTION` (apa pun reason — sensitif legal)
- `change_type = PROMOTION` ke tier `MANAGER` atau `ASST_MANAGER`
- `|salary_change_pct| > 25%` (perubahan upah signifikan)

### Kapan Butuh Consent Karyawan?

`JicService::requiresEmployeeConsent()`:

- `change_type = DEMOTION` **dan** `reason_category != DISCIPLINARY`

Konsep: demosi yang **bukan** konsekuensi pelanggaran tertulis (SP) tidak
boleh dipaksakan sepihak. Karyawan boleh menolak; jika menolak, JIC otomatis
berstatus `REJECTED`.

## Klasifikasi Otomatis

`JicService::classify()` menentukan `change_type` berdasar perbandingan
posisi lama vs baru:

| Kondisi                                                 | change_type        |
|---------------------------------------------------------|--------------------|
| Tier baru > tier lama                                    | `PROMOTION`        |
| Tier baru < tier lama                                    | `DEMOTION`         |
| Tier sama, salary up ≥ 5%                                | `PROMOTION` (merit)|
| Tier sama, salary down ≥ 5%                              | `DEMOTION`         |
| Tier sama, ΔΔsalary < 5%, dept/branch/posisi berubah    | `LATERAL`          |
| Tier sama, ΔΔsalary < 5%, hanya salary                  | `SALARY_ADJUSTMENT`|
| Tier sama, employment_status berubah, ΔΔsalary < 5%     | `STATUS_CHANGE`    |

Tier hierarchy: `STAFF (1) → SUPERVISOR (2) → ASST_MANAGER (3) → MANAGER (4)`.

Caller boleh meng-override dengan field `change_type_override` di payload create.

## Cek UMP

Saat draft dibuat, sistem otomatis cek upah baru terhadap UMP rujukan:

1. Lookup di `settings`:
   - `ump.<province_code>` (mis. `ump.dki_jakarta`) — paling spesifik
   - `payroll.minimum_wage` — fallback per company
   - `ump.default` — fallback umum
2. Status:
   - `PASS` — upah baru ≥ UMP
   - `FAIL` — upah baru < UMP
   - `OVERRIDE` — HR set `ump_override_reason` (sah hanya berdasar PKB/PP)
   - `NOT_APPLICABLE` — UMP rujukan tidak ada di settings

Submit akan ditolak jika `ump_check_status = FAIL` dan tidak ada
`ump_override_reason`. Saat submit dengan override, status otomatis berubah
ke `OVERRIDE`.

## Dokumen Pendukung Wajib

`JicService::validateSupportingDocuments()`:

| reason_category | Field wajib                |
|-----------------|----------------------------|
| `DISCIPLINARY`  | `disciplinary_record_id`   |
| `PERFORMANCE`   | `performance_review_id`    |
| `MERIT`         | `performance_review_id`    |
| `COMPETENCY`    | `training_certificate_id`  |
| `RESTRUCTURE`   | `mpp_id` (dianjurkan)      |

## Apply (Implementasi)

Saat `effective_date` jatuh tempo, JIC otomatis di-apply oleh cron:

```bash
php spark hr:apply-jic                  # apply semua due
php spark hr:apply-jic --dry-run        # preview
php spark hr:apply-jic --company 1
php spark hr:apply-jic --id 42          # paksa apply 1 record
```

**Crontab disarankan** (00:30 WIB tiap hari):

```cron
30 0 * * * cd /path/to/backend && php spark hr:apply-jic >> writable/logs/jic.log 2>&1
```

HR juga bisa apply manual lebih awal lewat tombol "Apply Sekarang" di
halaman detail (perlu permission `jic.implement` atau `jic.manage`).

`JicService::apply()` mengeksekusi:

1. Update `employees`: position_id, department_id, branch_id,
   work_location_id, job_grade_id, employment_status, basic_salary,
   supervisor_id, manager_id (hanya field non-null).
2. Insert `employee_contracts` baru sebagai amandemen untuk
   `PROMOTION`/`DEMOTION`/`STATUS_CHANGE` (nomor `AMD/{YYYY}/{MM}/{####}`).
3. Recompute `employee_allowances` lewat `AllowanceService::recomputeEmployee()`.
4. Set `status = IMPLEMENTED`, isi `implemented_at` & `implemented_by`.
5. Notifikasi karyawan via in-app + email/WA channel.

Idempotent: kalau status sudah `IMPLEMENTED`, panggilan ulang tidak
melakukan side-effect.

## Permission

| Slug             | Untuk                                                    |
|------------------|----------------------------------------------------------|
| `jic.view`       | Lihat semua JIC di company                              |
| `jic.create`     | Buat draft JIC                                          |
| `jic.approve`    | Approve di tahap PENDING_HR                             |
| `jic.manage`     | Override semua (cancel APPROVED, BOD review, dst)      |
| `jic.implement`  | Apply manual ke records (HR/admin)                      |

Default-grant ke role 1 (super admin) & 2 (HR admin) lewat migration.
Karyawan biasa selalu bisa lihat JIC mereka sendiri (filter `as=mine`).

## API Endpoints

```
GET    /api/v1/jic/options              — daftar enum change_type & reason
GET    /api/v1/jic                      — list (filter: status, change_type, employee_id, effective_from/to, as=mine|inbox)
POST   /api/v1/jic                      — create draft
GET    /api/v1/jic/:id                  — detail
PUT    /api/v1/jic/:id                  — edit (DRAFT only)
DELETE /api/v1/jic/:id                  — soft-delete (DRAFT only)
POST   /api/v1/jic/:id/submit           — DRAFT → PENDING_SUPERVISOR + auto JIC number
POST   /api/v1/jic/:id/approve          — approve di stage saat ini
POST   /api/v1/jic/:id/reject           — reject (note ≥ 10 char)
POST   /api/v1/jic/:id/consent          — karyawan AGREED/REJECTED (PENDING_EMPLOYEE_CONSENT)
POST   /api/v1/jic/:id/cancel           — initiator/HR
POST   /api/v1/jic/:id/implement        — manual apply (effective_date harus ≤ hari ini)
```

## Frontend

Halaman utama: `/hr/jic` (`frontend/src/views/HR/JobInformationChange.vue`).

Fitur:

- List dengan filter status / tipe / karyawan / tanggal efektif / `as`.
- `as=inbox` — JIC yang menunggu aksi user (supervisor / dept head / HR / BOD).
- Form create/edit dengan classify preview, UMP override warning, dokumen
  pendukung dinamis berdasar `reason_category`.
- Detail modal dengan side-by-side comparison Lama vs Baru, compliance
  panel, approval trail dengan timeline icon.
- Modal terpisah untuk reject (note wajib), cancel, dan employee consent
  (radio "Saya Setuju / Saya Tolak" + signed_path opsional).

Sidebar entry di group HR: "Promosi / Demosi (JIC)" — visible kalau user
punya `jic.view`.

## Notifikasi

| Event                  | Penerima                              |
|------------------------|---------------------------------------|
| `jic.pending` (PENDING_SUPERVISOR) | Supervisor langsung           |
| `jic.pending` (PENDING_DEPT_HEAD)  | Dept head (manager dari old/new dept) |
| `jic.pending` (PENDING_HR)         | Semua user dengan `jic.approve` |
| `jic.pending` (PENDING_BOD)        | Semua user dengan `jic.manage` |
| `jic.consent_required`             | Karyawan ybs                  |
| `jic.approved`                     | Karyawan ybs                  |
| `jic.rejected`                     | Karyawan ybs + initiator      |
| `jic.implemented`                  | Karyawan ybs                  |

## Audit Trail

Semua aksi JIC tercatat di `audit_logs` dengan module `JIC`:

- `jic.create` (severity NOTICE)
- `jic.update` (NOTICE)
- `jic.delete` (NOTICE)
- `jic.submit` (NOTICE)
- `jic.approve` (NOTICE)
- `jic.reject` (WARNING)
- `jic.consent` (WARNING) — metadata berisi decision
- `jic.cancel` (WARNING)
- `jic.implement` (WARNING) — metadata berisi `contract_id`

## Test

Smoke test: `php scripts/test-jic-flow.php` — verifikasi 57+ case meliputi
schema, classify, UMP check, consent rules, BOD rules, supporting docs,
snapshot, JIC number generation, dan end-to-end flow promosi (apply ke
employees + amandemen kontrak + idempotency).

## Limitasi / TODO

- [ ] Bulk JIC untuk reorganisasi massal (saat ini satu karyawan per draft).
- [ ] Template SK HTML — saat ini `sk_html` masih kosong; integrasi dengan
      `ContractRenderer` untuk auto-render.
- [ ] Auto-revert saat HR cancel APPROVED yang sudah `implemented`
      (saat ini tidak revert otomatis ke posisi lama).
- [ ] Notifikasi WhatsApp untuk consent karyawan (sekarang in-app only).