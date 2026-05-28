# Master Job Titles

Master jabatan terstandarisasi untuk laporan compa-ratio, salary
benchmark, WLKP, dan analisis HR lainnya. Diperkenalkan di
**migration #064** (Mei 2026).

## Latar Belakang

Sebelumnya nama jabatan ditulis bebas di kolom `positions.name`
(mis. "Sr Backend Eng" vs "Senior Backend Engineer" vs "Backend Eng
Senior"). Akibatnya:

- Laporan **compa-ratio per jabatan** harus group by string yang
  berisik.
- **Salary benchmark** internal vs eksternal sulit di-match.
- **WLKP** wajib pakai nama jabatan yang konsisten lintas perusahaan.
- **Bonus eligibility** filter `position_ids` jadi rentan typo.

Tabel `job_titles` adalah **single source of truth** untuk label
jabatan. Satu job_title bisa dipakai banyak `positions` di department
berbeda.

## Skema Tabel

```sql
CREATE TABLE job_titles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id    INT UNSIGNED NOT NULL,
    code          VARCHAR(32)  NOT NULL,                    -- Unik per company
    name          VARCHAR(200) NOT NULL,                    -- Nama resmi
    family        VARCHAR(100) NULL,                        -- Engineering, Sales, ...
    level         ENUM('JUNIOR','MID','SENIOR','LEAD',
                       'MANAGER','DIRECTOR','EXECUTIVE'),
    description   TEXT NULL,
    is_active     TINYINT UNSIGNED DEFAULT 1,
    created_at    DATETIME, updated_at DATETIME, deleted_at DATETIME,
    UNIQUE KEY (company_id, code),
    INDEX (company_id), INDEX (family),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
```

Kolom FK baru:
- `positions.job_title_id` → master jabatan (auto-backfill dari
  positions.name yang sudah ada saat migrate).
- `employees.job_title_id` → snapshot per karyawan. Boleh berbeda
  dari `positions.job_title_id` (mis. acting role / promosi sementara).

## Auto-backfill

Migration `2024-01-01-000064` melakukan:

1. Buat tabel `job_titles`.
2. Tambah kolom `job_title_id` di `positions` & `employees`.
3. Loop semua `positions` aktif, group by `(company_id, name)` unik.
4. Generate satu `job_titles` per kombinasi unik:
   - `code` = SLUG dari name (uppercase, alphanumeric, underscore)
   - `level` = ditebak dari kata kunci nama (Direktur → EXECUTIVE,
     Manager → MANAGER, Senior → SENIOR, Junior → JUNIOR, dst)
5. Set `positions.job_title_id` = id job_title baru.
6. Propagasi ke `employees.job_title_id` lewat join positions.

Hasil verifikasi pada seed default:
```
Total: 66 job_titles
Linked positions: 66/66 (100%)
Linked employees: 69/69 (100%)
```

Migration **idempotent** — aman jalan ulang.

## Permission

```
job_title.view    — Melihat master jabatan
job_title.manage  — CRUD master jabatan
```

Auto-grant ke role `super_admin` (1) & `hr_admin` (2).

## API Endpoint

```
GET    /api/v1/job-titles                  # List paginated, search/filter
GET    /api/v1/job-titles/picker           # Dropdown — items grouped by family
GET    /api/v1/job-titles/(:num)           # Detail
GET    /api/v1/job-titles/(:num)/usage     # Posisi & employee yg pakai title ini
POST   /api/v1/job-titles                  # Create
PUT    /api/v1/job-titles/(:num)           # Update
DELETE /api/v1/job-titles/(:num)           # Soft-delete
```

### Picker response

```json
{
  "items": [...],
  "grouped": {
    "Engineering": [
      { "id": 12, "code": "BE_SR", "name": "Senior Backend Engineer",
        "level": "SENIOR", "position_count": 3, "employee_count": 5 }
    ],
    "Sales": [...]
  },
  "total": 66
}
```

`position_count` & `employee_count` membantu HR melihat dampak
sebelum rename / soft-delete sebuah title.

## UI

Halaman: `/master/job-titles` — sidebar `Master Data` group.

Component: `frontend/src/views/Master/JobTitles.vue` (pakai
`SimpleMaster` template).

Form Position (`/master/positions`) sekarang punya **dropdown
Master Jabatan** yang load dari `/job-titles/picker`. Option
dilabel `{family} — {name} ({level})` untuk grouping mental.

## Skenario Pemakaian

### 1. HR membuat posisi baru
1. Buka `Master > Posisi` → Tambah.
2. Isi kode + nama posisi (mis. "Backend Engineer Tim Platform").
3. Pilih dropdown **Master Jabatan** → "Engineering — Backend Engineer (MID)".
4. Sistem set `positions.job_title_id`. Saat HR hire karyawan ke
   posisi ini, `employees.job_title_id` otomatis inherit.

### 2. HR generate laporan compa-ratio per jabatan
Dengan `job_title_id` standar, query bisa langsung:
```sql
SELECT jt.name, AVG(e.basic_salary), COUNT(*)
FROM employees e
JOIN job_titles jt ON jt.id = e.job_title_id
GROUP BY jt.id;
```
Tidak ada lagi noise dari varian penulisan.

### 3. HR rename "Sr Backend Eng" → "Senior Backend Engineer"
1. Buka `Master > Master Jabatan` → cari "Sr Backend Eng".
2. Edit nama → simpan.
3. Semua positions & employees yang pakai title ini otomatis
   ter-update (FK by id, bukan string).

### 4. HR mau hapus jabatan duplicate
1. `GET /job-titles/{id}/usage` → cek dulu posisi mana saja yang
   masih pakai.
2. Update positions yg pakai → arahkan ke title yang benar.
3. Setelah `usage.position_count = 0`, soft-delete title.

## Catatan Implementasi

- **Tidak menyentuh `positions.name`** — kolom lama tetap free-text.
  Migration hanya menambah FK; nama tetap di posisi sebagai label
  konteks (mis. "Backend Engineer di Tim Platform" lebih spesifik
  daripada hanya "Backend Engineer").
- **Backfill heuristik** untuk `level`: bagus untuk seed default tapi
  HR sebaiknya review & koreksi via UI setelah migrate.
- **Multi-tenant**: `code` unik per `company_id`, jadi dua company
  boleh punya code yang sama. `MasterCrudController` apply tenant
  scope otomatis.
- **Soft-delete**: pakai `deleted_at` standar. Saat title dihapus,
  positions/employees yang pakai tetap point ke id-nya — boleh
  dianggap "dangling" tapi tidak block apa-apa.

## Lihat juga

- `docs/HRIS_FEATURES_AND_BUSINESS_FLOW.md` section **Master Data**
- `docs/CRON_JOBS.md` — anniversary carry-over yang juga di
  migration #064
- Migration source: `backend/app/Database/Migrations/2024-01-01-000064_CreateJobTitlesAndCarryOverConfig.php`