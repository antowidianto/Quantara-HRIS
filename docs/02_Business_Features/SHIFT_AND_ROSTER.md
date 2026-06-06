# Shift & Roster — Panduan Pengembang & Admin

Dokumen ini menjelaskan modul **Shift, Pola Shift, Aturan Shift, dan Roster**
yang ditambahkan di iterasi roadmap fase 1 item #1.

## Mengapa modul ini ada

Sebelum perubahan, `shifts` hanya scoped ke `company_id` dan `shift_schedules`
hanya menyimpan jadwal hari-per-hari. Akibatnya HR harus input jadwal manual
dan tidak ada cara mengatur "shift kantor untuk cabang Jakarta" vs "3 shift
pabrik untuk cabang Bekasi". Sekarang kita punya:

| Konsep | Tabel | Tujuan |
|---|---|---|
| **Shift** | `shifts` | Definisi jam kerja. Bisa terikat ke branch / work_location / department, punya `is_default`, warna, dan working_days. |
| **Aturan Shift** | `shift_assignments` | Pemetaan shift ke scope (mirror `approval_assignments`). |
| **Pola Shift** | `shift_patterns` | Pola siklus N hari (5-2, 4-2, 3 shift pabrik 21 hari). |
| **Pemetaan Pola** | `shift_pattern_assignments` | Apply pola ke karyawan / scope, dengan `anchor_date` & rentang aktif. |
| **Jadwal Harian** | `shift_schedules` | Override eksplisit per karyawan per tanggal (sudah ada sebelumnya). |
| **Default Karyawan** | `employees.default_shift_id` | Override per orang. |

## Resolver: bagaimana sistem memilih shift?

`App\Libraries\ShiftResolver` memutuskan shift untuk `(employee, date)` dengan
prioritas (semakin atas = semakin tinggi):

1. **`shift_schedules`** — override eksplisit per hari.
2. **`employees.default_shift_id`** — default per-orang.
3. **`shift_pattern_assignments`** — pola siklus aktif yang paling spesifik
   (employee → grade → department → branch → work_location).
4. **`shift_assignments`** — rule scope yang paling spesifik.
5. **`shifts.is_default = 1`** untuk scope/company.
6. Shift aktif pertama di company (fallback).

`AttendanceController::clockIn` sudah memakai resolver ini, jadi karyawan tidak
perlu lagi tergantung pada `shift_schedules` untuk mendapatkan perhitungan
late yang benar.

## Endpoint Backend

```
GET    /api/v1/shifts                          # filter: branch_id, work_location_id, department_id, is_default, is_active
POST   /api/v1/shifts                          # field tambahan: branch_id, work_location_id, department_id, is_default, color, working_days
PUT    /api/v1/shifts/(:id)
DELETE /api/v1/shifts/(:id)

GET    /api/v1/shift-assignments               # filter: shift_id, branch_id, department_id, is_active
GET    /api/v1/shift-assignments/preview       # ?employee_id=&date=  → trace resolver untuk debug
GET    /api/v1/shift-assignments/(:id)
POST   /api/v1/shift-assignments
PUT    /api/v1/shift-assignments/(:id)
DELETE /api/v1/shift-assignments/(:id)

GET    /api/v1/shift-patterns
GET    /api/v1/shift-patterns/(:id)            # menyertakan pattern_data + assignments
POST   /api/v1/shift-patterns
PUT    /api/v1/shift-patterns/(:id)
DELETE /api/v1/shift-patterns/(:id)

GET    /api/v1/shift-pattern-assignments       # filter: pattern_id, employee_id
POST   /api/v1/shift-pattern-assignments
DELETE /api/v1/shift-pattern-assignments/(:id)

GET    /api/v1/roster                          # matrix Employee × Date
POST   /api/v1/roster/generate                 # apply pattern ke shift_schedules massal
POST   /api/v1/roster/bulk-update              # multi-cell upsert dari editor
POST   /api/v1/roster/clear                    # delete shift_schedules dalam rentang
```

### Format `pattern_data`

Array sepanjang `cycle_days`. Slot ke-`i` (0-based) = hari ke-(`i + 1`)
dalam siklus.

```json
{
  "cycle_days": 7,
  "pattern_data": [
    { "shift_id": 1, "is_off": false, "is_holiday": false, "label": "Sen" },
    { "shift_id": 1, "is_off": false, "is_holiday": false, "label": "Sel" },
    { "shift_id": 1, "is_off": false, "is_holiday": false, "label": "Rab" },
    { "shift_id": 1, "is_off": false, "is_holiday": false, "label": "Kam" },
    { "shift_id": 1, "is_off": false, "is_holiday": false, "label": "Jum" },
    { "shift_id": null, "is_off": true,  "is_holiday": false, "label": "Sab" },
    { "shift_id": null, "is_off": true,  "is_holiday": false, "label": "Min" }
  ]
}
```

Saat resolver perlu menentukan shift di tanggal `D`, sistem hitung
`(D - anchor_date) mod cycle_days` lalu pakai slot di indeks tsb.

### Anchor date

`anchor_date` di pattern assignment menentukan hari ke-1 siklus. Untuk
pola "3 Shift Pabrik 21 hari" yang seharusnya mulai Senin minggu pertama,
set `anchor_date = 2026-05-04` (mis. Senin pertama bulan Mei).

## Permissions

Migrasi `2024-01-01-000011_EnhanceShiftAssignmentAndRoster` menambahkan
permission baru dan auto-grant ke role 1 (super admin) & 2 (HR admin):

| Slug | Untuk |
|---|---|
| `shift.view` | Lihat daftar shift |
| `shift.manage` | Buat/ubah shift |
| `shift_pattern.view` | Lihat pola shift |
| `shift_pattern.manage` | Kelola pola shift |
| `roster.view` | Akses halaman roster |
| `roster.manage` | Generate / bulk update roster |

## Halaman Frontend

| Path | Komponen | Untuk |
|---|---|---|
| `/master/shifts` | `Master/Shifts.vue` | CRUD shift dengan field branch/work_location/working_days/color |
| `/master/shift-assignments` | `Master/ShiftAssignments.vue` | Aturan pemetaan + tester preview shift |
| `/master/shift-patterns` | `Master/ShiftPatterns.vue` | Designer pola siklus (klik slot → pilih shift/off) |
| `/roster` | `Roster/RosterMatrix.vue` | Matrix Employee × Date dengan generator pola dan editor cell |

Sidebar `AppLayout.vue` sudah ditambahkan menu baru di group **HR** (Roster
Shift) dan **Master Data** (Shift Kerja, Aturan Shift, Pola Shift).

## Cara Migrasi Database Existing

```bash
cd backend
php spark migrate
php spark db:seed RosterSeeder
```

Migrasi idempotent — aman dijalankan ulang. `RosterSeeder` akan:
- Update shift existing (kasih warna & working_days).
- Buat shift `OFFICE` (default), `FACTORY_PAGI`, `FACTORY_SORE`, `FACTORY_MALAM`.
- Buat 3 pola: `P_5_2_OFFICE`, `P_3SHIFT_PABRIK`, `P_4_2`.

## Skenario Penggunaan

### Skenario 1 — Kantor pusat 5 hari kerja

1. Edit shift `OFFICE` di `/master/shifts`, set `is_default = 1`, `branch_id` kosong (company-wide).
2. Buka `/roster`. Resolver akan otomatis pakai OFFICE untuk semua karyawan
   non-pabrik karena `working_days = 1,2,3,4,5` mengisi Sen-Jum dan
   menandai Sab-Min sebagai off.

### Skenario 2 — Pabrik 3 shift rotasi mingguan

1. Buat shift `FACTORY_PAGI`, `FACTORY_SORE`, `FACTORY_MALAM` di
   `/master/shifts`, set `branch_id` ke cabang pabrik.
2. Buat pola `P_3SHIFT_PABRIK` 21 hari (Pagi×7, Sore×7, Malam×7).
3. Di `/master/shift-patterns` → klik tombol Edit → atau buat pattern
   assignment baru via API:
   ```json
   POST /shift-pattern-assignments
   {
     "pattern_id": 2,
     "department_id": 5,        // departemen produksi
     "branch_id": 2,            // cabang pabrik
     "anchor_date": "2026-05-04",
     "date_from": "2026-05-04",
     "priority": 100
   }
   ```
4. Buka `/roster` filter cabang pabrik → klik **Generate dari Pola** →
   pilih `P_3SHIFT_PABRIK`, atur tanggal → **Preview (dry run)** dulu,
   lalu **Generate**. Sistem akan isi `shift_schedules` sesuai siklus.

### Skenario 3 — Override per karyawan

Karyawan tertentu butuh shift khusus 1 hari (mis. lembur khusus):
- Buka `/roster`, klik cell yang dimaksud → pilih shift baru → Simpan.
- Cell itu akan menampilkan ring kuning sebagai indikator override
  `shift_schedules` (sumber `schedule`).

## Debugging Resolver

Pakai endpoint preview:

```http
GET /api/v1/shift-assignments/preview?employee_id=10&date=2026-05-15
```

Response:
```json
{
  "shift": { "id": 3, "code": "FACTORY_PAGI", ... },
  "source": "pattern",
  "is_off": false,
  "is_holiday": false,
  "pattern_id": 2,
  "reason": "Pola 3 Shift Pabrik (hari ke-3 dari siklus 21)"
}
```

`source` mengikuti urutan resolver di atas. Halaman
`/master/shift-assignments` punya tester ini built-in.

## Audit Log

Semua aksi roster tercatat di modul `ROSTER`:
- `roster.generate` — saat pattern di-apply ke shift_schedules.
- `roster.bulk_update` — saat editor cell men-save banyak entry.
- `roster.clear` — saat HR menghapus rentang.

Lihat di `/admin/audit-logs?module=ROSTER`.