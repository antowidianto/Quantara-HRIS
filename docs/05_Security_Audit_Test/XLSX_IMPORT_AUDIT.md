# Audit: Fitur Bulk Upload `.xlsx`

> **Versi**: 2026-05-28 (revisi setelah Migration #071)
> **Status saat ini**: ✅ **Foundation aktif.** 20 modul sudah
> wired end-to-end. Semua kandidat prioritas tinggi sudah ter-cover.
> Modul lain tinggal tambah entri di `ImportRegistry`.
> **Library**: `phpoffice/phpspreadsheet ^2.1` sudah dipakai oleh
> `App\Libraries\Import\XlsxImportService`.

Dokumen ini me-list **semua fitur yang punya input data** dan **layak diberi opsi
bulk upload `.xlsx`** supaya HR / admin tidak harus input satu-per-satu lewat
form. Prioritas dihitung dari frekuensi data masuk + jumlah baris per batch
yang biasa dipakai HR di lapangan.

---

## 1. Ringkasan Status

| Aspek | Status |
|---|---|
| Library backend (`phpoffice/phpspreadsheet`) | ✅ Aktif via `XlsxImportService` |
| Library frontend (`xlsx` / `sheetjs` / `exceljs`) | ❌ Tidak perlu — backend yang parse, frontend kirim multipart |
| MIME `xlsx` di `Config/Mimes.php` | ✅ Terdaftar |
| Validator file signature (PK\\x03\\x04) | ✅ `FileSignatureValidator` mendukung ZIP-based (xlsx/docx/pptx) |
| Endpoint generic upload (`/upload/attachment`) | ✅ Ada di `FileUploadController` (untuk lampiran biasa) |
| Endpoint import data dari xlsx | ✅ **Ada** — `/api/v1/imports/<resource>/{template,preview,commit,history}` |
| Audit trail per batch | ✅ Tabel `import_batches` (Migration #067) |
| Output xlsx (export laporan) | ⚠️ Enum `compliance_report_runs.export_format` mengizinkan `XLSX`, tapi writer-nya belum di-implement |

**Yang sudah aktif (Migration #067–#071):**

- Foundation library + controller generik
- 6 modul master inti (Batch 1): Departments, Branches, Job Titles, Job Grades, Positions, Employees
- 8 modul master tambahan (Batch 2): Allowance Types, Payroll Components, Leave Types, Assets, Cost Centers, Organization Structures, Work Locations, Shifts
- 3 modul transactional (Batch 3): Leave Balances (saldo cuti awal), Shift Schedules (roster bulanan), Attendances (backfill)
- 1 modul payroll (Batch 4): Bonus Recipients (upload penerima + nominal per run)
- 2 modul tambahan (Batch 5): Loans (pinjaman/kasbon), Goals (performance KPI)
- Tombol "Import Excel" muncul di toolbar masing-masing view (gated by permission `<resource>.bulk_import`)
- Fitur baru: composite_unique validation, upsert_on_conflict (roster/bonus), datetime coercion, auto-inject fields (attendances/bonus), employee snapshot (bonus)

**Yang masih manual (form per-record):** semua modul lain — bisa di-extend kapan saja dengan menambah entri di `App\Libraries\Import\ImportRegistry`.

---

## 2. Kandidat Bulk Upload `.xlsx` (Prioritas Tinggi)

Fitur dengan **volume data besar** dan **dataset awal yang biasanya datang dari
spreadsheet HR lama** (Excel HRIS, file Excel Direktorat, dll.) — ini yang paling
kerasa kalau diberi import xlsx.

| # | Fitur | View / Endpoint | Use case import xlsx |
|---|---|---|---|
| 1 | **Master Karyawan** | `Employees/EmployeeList.vue` → `POST /employees` | Onboarding awal saat go-live: HR upload data semua karyawan existing dari file Excel HR lama (NIK, nama, tgl lahir, posisi, gaji pokok, tunjangan, BPJS no, NPWP, dst.). Volume bisa ratusan-ribuan baris. |
| 2 | **Master Department / Position / Branch / Job Grade / Job Title** | `Master/*.vue` → `POST /departments` dst. | Setup awal company. Sering ada >50 dept + >100 posisi yang lebih cepat di-upload ketimbang ditik satu-satu. |
| 3 | **Allowance / Tunjangan & Komponen Payroll** | `Master/Allowances.vue`, `Master/PayrollComponents.vue` | Setup awal: kategori tunjangan + nominal default per job grade, biasanya disiapkan FAD di Excel. |
| 4 | **Roster / Shift Schedule (bulanan)** | `Roster/RosterMatrix.vue` → `POST /roster-schedules` | Schedule shift bulanan untuk pabrik/operasional yang punya 200-1000 karyawan dengan rotasi pagi/sore/malam. Shift planner biasanya kerja di Excel dulu. |
| 5 | **Attendance Adjustment / Backfill** | `Attendance/AttendanceList.vue` (admin) | Saat migrasi dari mesin absen lama (fingerprint export ke Excel) → upload bulk ke `attendances` table. Volume tinggi (1 baris × hari × karyawan). |
| 6 | **Saldo Cuti Awal (Opening Balance)** | `Leaves/LeaveBalance.vue` → migration / one-off | Saat go-live: HR perlu upload sisa saldo cuti tahun berjalan setiap karyawan dari sistem lama. |
| 7 | **Loan / Pinjaman Karyawan** | `Payroll/Loans.vue` (jika ada) → `POST /loans` | Migrasi data pinjaman aktif (cicilan koperasi, KPR perusahaan) saat migrasi sistem. |
| 8 | **Asset (IT, kendaraan, ID card, dll.)** | `HR/Assets.vue` → `POST /assets` | Perusahaan dengan inventory IT besar biasanya punya master asset di Excel — laptop, monitor, kendaraan, perabot. |
| 9 | **Online Test — Question Bank** | `Master/OnlineTestQuestionsBank.vue` (jika ada) | Soal psikotes / aptitude / case study biasanya di-author di Word/Excel dulu sebelum di-upload ke bank. |
| 10 | **Org Structure (Master Jabatan Kunci)** | `Master/OrganizationRoles.vue` → `POST /organization-structures` | Saat setup company, struktur 30-100 role + penjabat lebih cepat upload. |

---

## 3. Kandidat Bulk Upload `.xlsx` (Prioritas Menengah)

Fitur dengan input rutin tapi volume per-batch lebih kecil. Bagus untuk
**operasi monthly / quarterly**.

| # | Fitur | View / Endpoint | Use case import xlsx |
|---|---|---|---|
| 11 | **Bonus / Insentif Bulanan** | `Payroll/BonusSchemeList.vue` | HR Compensation kalkulasi bonus achievement di Excel, lalu upload nominal per karyawan untuk periode payroll tertentu. |
| 12 | **Reimbursement Batch** | `Payroll/Reimbursements.vue` | Untuk perusahaan yang masih kumpulkan klaim manual lalu rekap di Excel sebelum di-input ke HRIS. |
| 13 | **Overtime Bulk Approval** | `Attendance/Overtime.vue` | Form rekap lembur dari spv pabrik biasanya Excel — upload ke pengajuan lembur sekaligus. |
| 14 | **Business Trip / SPPD batch** | `Travel/TripList.vue` | Untuk perjalanan dinas grup (mis. tim training 30 orang ke Bali) — upload daftar peserta + nominal sekaligus. |
| 15 | **Performance Review (Goals / KPI)** | `Performance/CycleDetail.vue` | Cascade goal tahunan biasanya di-define manager di Excel dulu. |
| 16 | **IDP — Individual Development Plan** | `HR/IdpDashboard.vue` | Rencana pengembangan setahun lebih cepat di-bulk upload daripada satu-satu. |
| 17 | **Training Schedule / Participant List** | `Training/TrainingList.vue` | Daftar peserta training (50-200 orang per batch) biasanya sudah ada di Excel. |
| 18 | **Disciplinary Records (SP) Migrasi** | `HR/Disciplinary.vue` | One-off saat migrasi data SP/teguran historis dari sistem lama. |
| 19 | **Manpower Planning (MPP) Bulk** | `Recruitment/MppList.vue` | Saat setup planning tahunan (RKAP), HR Strategic upload semua MPP budget setahun. |
| 20 | **Cost Center / Mapping Karyawan ke Cost Center** | `Master/CostCenters.vue` | Re-org/restructure: ratusan karyawan pindah cost center sekaligus. |

---

## 4. Kandidat Prioritas Rendah (atau tidak disarankan)

Fitur yang sebaiknya **tetap input form per-record**, karena alasan:

- ada lifecycle approval per-record yang kompleks (resignation, termination, contract sign)
- data sensitif (signature, foto, dokumen) yang tidak bisa di-template-kan
- volume sedikit / one-time entry

| Fitur | Alasan tidak prioritas |
|---|---|
| Resignation, Termination, Severance | Lifecycle approval kompleks per-record. Severance hitung otomatis di backend, tidak boleh dimanipulasi via xlsx. |
| Offer / Contract Sign | Butuh tanda tangan + audit trail. |
| Generated Contract | Output, bukan input. |
| Approval Inbox / Delegations | Bukan data master, tapi event-driven. |
| Notifications, Audit Logs | Append-only system table. |
| Helpdesk Tickets | Self-service per ticket. |
| Career Apply / Candidate Profile | User-driven (kandidat eksternal). |
| WhatsApp / Email Settings | Konfigurasi tunggal per company. |

---

## 5. Yang Sudah Mendukung Upload File (Tapi BUKAN Import Data Tabular)

Komponen ini sudah ada — perlu tidak rancu antara **upload file (lampiran)**
dengan **import data (.xlsx parse → DB)**.

| Komponen | Fungsi | Format yang diterima |
|---|---|---|
| `FileUploader.vue` (`/upload/attachment`) | Lampiran general (struk, KTP, sertifikat, dokumen) | image/*, pdf, doc/docx, xls/xlsx (sebagai attachment, **tidak diparse**) |
| `OcrReceiptExtract.vue` | OCR struk reimbursement (foto/PDF) | image/*, PDF |
| `ContractSign.vue` (foto signature) | Upload tanda tangan | png/jpg/webp |
| `CandidateProfile.vue` (CV upload) | Lampiran CV pelamar | pdf, docx |

> Note: `Config/Mimes.php` sudah mengizinkan upload `.xlsx` sebagai attachment, tapi backend cuma simpan file-nya — tidak parse jadi DB row.

---

## 6. Rekomendasi Implementasi (jika mau diaktifkan)

Pola yang konsisten + aman untuk semua import xlsx:

### a. Endpoint pattern

```
GET    /<resource>/import-template        → download template kosong (.xlsx)
POST   /<resource>/import-preview         → upload .xlsx, parse, return preview JSON + validation errors
POST   /<resource>/import-commit          → confirm + insert (idempotent via `import_batch_id`)
GET    /<resource>/import-history          → list batch sebelumnya + status
```

### b. Komponen frontend reusable

`<ExcelImporter>` di `frontend/src/components/` — props: resource path, columns
schema, sample template URL. Render flow:

1. Tombol **Download Template**
2. Drop area / file picker (`accept=".xlsx"`)
3. Preview tabel (mark error rows merah)
4. Tombol **Confirm & Import**
5. Result: jumlah inserted / skipped / failed + link download error report

### c. Backend service

`App\Libraries\XlsxImportService`:

```php
public function parse(string $tmpPath, array $schema): array;
public function validate(array $rows, array $rules): array;  // returns [valid, errors]
public function commit(array $rows, callable $persistFn, int $userId): array; // returns [inserted, batch_id]
public function generateTemplate(array $schema): string;     // returns tmp xlsx path
```

Pakai `\PhpOffice\PhpSpreadsheet\IOFactory::load()` (sudah ter-install).

### d. Kontrol & audit

- **File size limit**: 10 MB / 5000 rows max (config-able).
- **Validation**: schema-driven (required, regex, FK exists, unique).
- **Idempotency**: simpan `import_batches` table dengan `id`, `module`, `user_id`,
  `total_rows`, `inserted`, `skipped`, `errors_json`, `committed_at`. Re-upload
  file yang sama → return existing batch.
- **Audit log**: action `<module>.bulk_import`, severity `NOTICE`, after =
  `{batch_id, inserted, skipped}`.
- **Permission**: butuh permission khusus per modul (`employees.bulk_import`,
  `attendance.bulk_import`, dst.) supaya tidak default ke semua HR.

### e. Storage

Simpan file aslinya di `writable/imports/<company_id>/<batch_id>.xlsx` selama
30 hari (untuk recovery / audit), lalu auto-cleanup via cron `hr:cleanup-imports`.

---

## 7. Status Implementasi (per 2026-05-28)

| Sprint | Modul | Status |
|---|---|---|
| ✅ 1 | `XlsxImportService` + `ExcelImporter` component (foundation) | **Done** (Migration #067) |
| ✅ 1 | Master Karyawan | **Done** (employees) |
| ✅ 2 | Master Department/Position/Branch | **Done** |
| ✅ 2 | Master Job Title / Job Grade | **Done** |
| ✅ 3 | Saldo Cuti Awal | **Done** (leave_balances) |
| ✅ 3 | Roster Bulanan | **Done** (shift_schedules, upsert) |
| ✅ 3 | Attendance Backfill | **Done** (attendances, auto is_corrected) |
| ✅ 4 | Allowance / Payroll Components | **Done** (tombol Import di view, resource sudah ada dari Batch 2) |
| ✅ 4 | Bonus Bulanan | **Done** (bonus_recipients, upsert + snapshot) |
| ✅ 5 | Asset (tombol Import di view) | **Done** (resource sudah ada dari Batch 2) |
| ✅ 5 | Loan / Pinjaman Karyawan | **Done** (loans) |
| ✅ 5 | Performance Goals / KPI | **Done** (goals) |

---

## 8. Cara Pakai (Saat Ini)

### a. Sebagai user HR

1. Buka master view yang punya tombol **"Import Excel"** (Department, Branch, Job Title, Job Grade, Position, Employee).
2. Klik tombol → dialog terbuka.
3. **Download Template** untuk dapat file `.xlsx` siap pakai dengan:
   - Header berwarna biru + bold
   - 1-2 sample row sebagai contoh
   - Sheet "Petunjuk" dengan list kolom + tipe + format
4. Edit di Excel/Google Sheets (save-as `.xlsx`), lalu drag-drop kembali.
5. Backend parse + validate → tampilkan **Preview** (50 row pertama) + tab **Errors** kalau ada.
6. Kalau bersih → klik **Commit** → semua baris di-insert dalam 1 transaksi.
7. Klik **"Lihat Riwayat Import"** untuk audit trail (siapa upload, kapan, jumlah inserted).

### b. Sebagai developer — menambah resource baru

Tinggal tambahkan satu method baru di `App\Libraries\Import\ImportRegistry`:

```php
private static function allowances(): array
{
    return [
        'slug'       => 'allowances',
        'label'      => 'Master Tunjangan',
        'permission' => 'allowances.bulk_import',  // tambah di migration permissions
        'table'      => 'allowance_types',
        'columns'    => [
            ['header' => 'Code', 'field' => 'code', 'type' => 'string', 'required' => true,
             'unique_in_table' => true, 'unique_in_batch' => true],
            ['header' => 'Name', 'field' => 'name', 'type' => 'string', 'required' => true],
            // dst...
        ],
        'sample_row' => [
            ['MEAL', 'Tunjangan Makan'],
        ],
        'notes_md' => "Setup awal jenis tunjangan...",
    ];
}
```

Lalu pasang ke array `all()`:

```php
'allowances' => self::allowances(),
```

Kalau permission baru, tambah migration kecil yang seed ke tabel `permissions` (lihat Migration #067 sebagai contoh).

Frontend tinggal pakai `<ExcelImporter resource="allowances" @committed="..." />` atau set prop `import-resource="allowances"` di `<SimpleMaster>`.

### c. Endpoint API

```
GET    /api/v1/imports/resources                   → list resource yg available + columns
GET    /api/v1/imports/<slug>/template             → download .xlsx template
POST   /api/v1/imports/<slug>/preview              → upload + parse + validate (multipart/form-data, field "file")
POST   /api/v1/imports/<slug>/commit/<batch_id>    → commit batch ke tabel target
GET    /api/v1/imports/<slug>/history              → list batch sebelumnya untuk resource ini
GET    /api/v1/imports/batches/<id>                → detail batch + preview rows + errors_list
POST   /api/v1/imports/batches/<id>/cancel         → soft-cancel batch yg masih PREVIEW
```

Permission per-resource: `<slug>.bulk_import` (mis. `employees.bulk_import`).

### d. Limit & safety

- Max file size: **10 MB**
- Max rows: **5000** per batch
- Idempotency: file dengan `sha256` sama → return batch existing, tidak duplikasi
- Field encrypted (`npwp_encrypted`, `bank_account_encrypted`) di-handle khusus saat commit
- Kolom `key_version` ikut di-set sesuai active encryption version
- Audit log per commit: action `<slug>.bulk_import`, severity NOTICE
- File asli disimpan di `writable/imports/<company_id>/batch-<id>.xlsx` untuk audit/recovery

---

## 9. Lihat juga

- `backend/composer.json` — `phpoffice/phpspreadsheet ^2.1`
- `backend/app/Database/Migrations/2024-01-01-000067_CreateImportBatches.php`
- `backend/app/Libraries/Import/ImportRegistry.php` — daftar resource + schema
- `backend/app/Libraries/Import/XlsxImportService.php` — pipeline parse/validate/commit/template
- `backend/app/Controllers/Api/V1/ImportController.php` — generic dispatcher
- `backend/app/Libraries/FileSignatureValidator.php` — validator signature ZIP-based
- `backend/app/Config/Mimes.php` — daftar MIME xlsx
- `frontend/src/components/ExcelImporter.vue` — UI dialog (download template, preview, commit, history)
- `frontend/src/components/SimpleMaster.vue` — bisa render tombol Import via prop `import-resource`
- `frontend/src/views/Master/{Departments,Branches,JobTitles,JobGrades,Positions}.vue` — referensi pemakaian
- `frontend/src/views/Employees/EmployeeList.vue` — referensi pemakaian di view custom
