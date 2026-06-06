# HRIS — Catatan Peningkatan

Dokumen ini merangkum perubahan yang dilakukan pada iterasi peningkatan
sistem HRIS, fokus pada **audit logging menyeluruh** untuk setiap transaksi
CRUD plus beberapa peningkatan khas konteks Indonesia.

## Ringkasan

| Area | Sebelum | Sesudah |
|---|---|---|
| Audit log | Hanya menyimpan `action`, `entity_id`, before/after mentah | Tambah `severity`, `module`, `description` Bahasa, `changes` (diff bersih), `request_id`, `success`, `error_code`, `duration_ms`, `source` |
| Audit filter | Mencatat sekedar method+path untuk write op | Set `request_id` per-request, log otomatis untuk semua write op + semua HTTP error (status >= 400) |
| Sanitisasi | Field rahasia di-redact | Field rahasia di-redact + field PII (NIK/NPWP/HP/email) di-mask |
| Validasi data | Generic | Tambah validator NIK, NPWP, BPJS Kesehatan, BPJS Ketenagakerjaan, telepon Indonesia, kode pos, PTKP |
| UMP/UMK | Tidak ada | Tabel `regional_minimum_wages`, seeder UMP 2026 untuk 38 provinsi + UMK kota besar, warning otomatis ke audit log saat gaji di bawah UMP/UMK |
| Hari libur | Manual | Seeder libur nasional Indonesia 2026 (SKB 3 Menteri) |
| Pengingat kontrak | Tidak ada | Command `php spark hr:check-expiry` untuk PKWT/probation/dokumen yang akan berakhir |
| Audit UI | Hanya tabel polos | Filter modul/severity/sukses, statistik, modal detail dengan diff side-by-side, jejak transaksi multi-step (request_id), export CSV |

## File Baru

```
backend/app/Database/Migrations/2024-01-01-000007_EnhanceAuditAndIndonesianMasters.php
backend/app/Database/Seeds/IndonesianMastersSeeder.php
backend/app/Libraries/IndonesianValidator.php
backend/app/Validation/IndonesianRules.php
backend/app/Commands/CheckContractExpiry.php
docs/IMPROVEMENTS.md
```

## File Diperbarui

```
backend/app/Libraries/AuditLogger.php          (rewrite — backward compatible)
backend/app/Filters/AuditFilter.php             (request_id + global hook)
backend/app/Config/Filters.php                  (audit jadi global filter)
backend/app/Config/Validation.php               (registrasi IndonesianRules)
backend/app/Config/Routes.php                   (3 endpoint audit baru)
backend/app/Controllers/Api/V1/AuditLogController.php   (filter, detail, stats, export CSV)
backend/app/Controllers/Api/V1/MasterCrudController.php (logCreate/Update/Delete + deskripsi)
backend/app/Controllers/Api/V1/EmployeeController.php   (validator ID + UMP warning)
backend/app/Controllers/Api/V1/LeaveController.php
backend/app/Controllers/Api/V1/OvertimeController.php
backend/app/Controllers/Api/V1/AttendanceController.php
backend/app/Controllers/Api/V1/AuthController.php       (failure logs + lock event)
backend/app/Controllers/Api/V1/PayrollPeriodController.php (calc/finalize success+failure)
backend/app/Controllers/Api/V1/SettingController.php
frontend/src/views/Admin/AuditLogs.vue                    (rewrite — diff viewer)
```

## Audit Log — API Baru

`AuditLogger` sekarang menyediakan API yang lebih ekspresif sambil tetap
kompatibel dengan signature lama:

```php
use Config\Services;

// shorthand
Services::audit()->forModule('LEAVE')->logCreate('leave', $id, $insert,
    "Pengajuan cuti tahunan: {$emp['full_name']} 3 hari");

Services::audit()->forModule('EMPLOYEE')->logUpdate('employee', $id,
    $existing, $update);

Services::audit()->forModule('PAYROLL')->logDelete('payroll_period', $id, $existing);

Services::audit()->forModule('LEAVE')->logApprove('leave', $id, $leave);
Services::audit()->forModule('LEAVE')->logReject('leave', $id, $leave, $reason);

// failure / critical
Services::audit()->forModule('AUTH')
    ->logFailure('auth.login', null, null, 'INVALID_CREDENTIALS',
        "Login gagal: {$email}");

Services::audit()->forModule('AUTH')
    ->logCritical('auth.login.locked', 'user', $userId,
        "Akun dikunci setelah 5 percobaan: {$email}");

// API umum dengan named args
Services::audit()->forModule('PAYROLL')->log(
    action: 'payroll_period.calculate',
    entity: 'payroll_period',
    entityId: $id,
    after: $summary,
    description: "Menghitung payroll periode #{$id}",
    severity: 'NOTICE'
);
```

### Diff otomatis

`logUpdate` dan `log()` (jika `before`+`after` keduanya disuplai) akan
menghasilkan field `changes` berisi hanya selisih:

```json
{
  "basic_salary": { "from": 5000000, "to": 5500000 },
  "department_id": { "from": 3, "to": 4 }
}
```

Field PII otomatis di-masking di before/after dan di diff.

### Request ID

Setiap request masuk diberi `request_id` (UUID v4). Header
`X-Request-Id` diterima jika dikirim klien dan diecho balik di response.
Semua audit log dalam satu request berbagi `request_id` yang sama, jadi
operasi multi-step (mis. `payroll.finalize` yang menulis ke beberapa
tabel) bisa di-trace utuh.

UI Detail Audit Log akan menampilkan log lain yang `request_id`-nya sama
sebagai "jejak transaksi".

### Severity & Module

- `DEBUG` — request HTTP biasa (write op)
- `INFO` — operasi normal (default)
- `NOTICE` — perubahan signifikan (delete, approve, password change)
- `WARNING` — kegagalan terkontrol (validasi, gagal login, gaji di bawah UMP)
- `CRITICAL` — kegagalan sistem / event keamanan (akun dikunci, payroll finalize gagal)

Module otomatis dideteksi dari prefix `action` (mis. `leave.create` → `LEAVE`)
atau bisa di-set manual via `forModule('...')`.

## Validasi Indonesia

Daftar rule baru yang siap dipakai di controller:

| Rule | Validasi |
|---|---|
| `valid_nik` | 16 digit angka, format tanggal lahir valid (perempuan +40) |
| `valid_npwp` | 15 atau 16 digit angka |
| `valid_phone_id` | +62/62/08/8, 10–13 digit setelah normalisasi |
| `valid_bpjs_kesehatan` | 13 digit angka |
| `valid_bpjs_ketenagakerjaan` | 11 digit angka (KPJ) |
| `valid_postal_id` | 5 digit angka |
| `valid_ptkp` | TK0..TK3, K0..K3, HB0..HB3 |

Contoh:

```php
$this->validateOrFail($data, [
    'nik'   => 'permit_empty|valid_nik',
    'npwp'  => 'permit_empty|valid_npwp',
    'phone' => 'permit_empty|valid_phone_id',
]);
```

Pesan error rule sudah dalam Bahasa Indonesia.

## UMP/UMK

Tabel `regional_minimum_wages` menyimpan UMP per provinsi dan UMK per
kabupaten/kota. Seeder `IndonesianMastersSeeder` mengisi UMP 2026 untuk
38 provinsi dan UMK contoh untuk kota industri besar (Bekasi, Karawang,
Tangerang, Surabaya, dll).

> Angka di seeder adalah baseline awal dan **wajib** diverifikasi dengan
> SK Gubernur resmi tahun berjalan oleh admin perusahaan.

Pencarian UMP/UMK pakai helper:

```php
use App\Libraries\IndonesianValidator;

$ump = IndonesianValidator::findMinimumWage('Jawa Barat', 'Bekasi', 2026);
// ['amount' => 5558444, 'level' => 'UMK', 'reference' => '...', 'year' => 2026]
```

Saat membuat karyawan baru dengan status PKWT/PKWTT/HARIAN dan
basic_salary di bawah UMP/UMK kota/provinsinya, sistem akan mencatat
log `WARNING` `employee.below_minimum_wage` ke audit log (tidak
memblokir, agar fleksibel untuk kasus part-time / probation).

## Hari Libur

Seeder mengisi 16 hari libur nasional 2026 (SKB 3 Menteri) ke tabel
`holidays` dengan `company_id NULL` (berlaku untuk semua tenant).
Cuti bersama tidak ikut diisi default — tambahkan manual sesuai
kebijakan perusahaan.

## Command HR

```bash
# Cek kontrak/probation/dokumen yang berakhir 30 hari ke depan
php spark hr:check-expiry

# Custom rentang
php spark hr:check-expiry --days 14

# Dry-run (tampilkan saja, tidak kirim notifikasi)
php spark hr:check-expiry --dry-run
```

Disarankan dijalankan harian via cron:

```cron
0 7 * * *  cd /path/to/backend && php spark hr:check-expiry >> writable/logs/expiry.log 2>&1
```

Notifikasi in-app dikirim ke role 1/2/3 (super admin, admin, HR).

## Cara Migrasi Database Existing

```bash
cd backend
php spark migrate
php spark db:seed IndonesianMastersSeeder
```

Migrasi `EnhanceAuditAndIndonesianMasters` bersifat idempotent dan aman
dijalankan beberapa kali (cek kolom & indeks sebelum membuat).

## Endpoint Audit Log Baru

| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/v1/audit-logs` | List dengan filter module, severity, success, search, dll |
| GET | `/api/v1/audit-logs/stats` | Ringkasan untuk dashboard (per modul/severity, top users, jumlah kegagalan) |
| GET | `/api/v1/audit-logs/(:num)` | Detail satu log + log terkait (request_id sama) |
| GET | `/api/v1/audit-logs/export` | Export CSV (max 50.000 baris, BOM UTF-8 supaya Excel happy) |

Filter yang didukung di `index` & `export`:

`start_date`, `end_date`, `module`, `severity`, `entity`, `action`,
`user_id`, `request_id`, `success` (1/0), `search` (cari di description,
action, entity, email, nama).

## Halaman Audit Log (Frontend)

Halaman di `/admin/audit-logs` sekarang menampilkan:

- 4 kartu ringkasan (periode, modul teratas, jumlah kegagalan, user teraktif)
- Filter komprehensif (tanggal, modul dropdown, severity, status sukses/gagal, entitas, search)
- Tabel dengan badge severity berwarna, indikator sukses/gagal, hitungan field berubah
- Tombol Detail → modal yang menampilkan diff side-by-side per field
- Modal juga menampilkan log lain pada request_id yang sama
- Tombol Export CSV

## Kompatibilitas

Semua perubahan kompatibel dengan kode lama:

- `Services::audit()->log($action, $entity, $id, $before, $after, $metadata)` masih jalan.
- Kolom `audit_logs` lama tetap ada; kolom baru semua nullable / bawa default.
- Migrasi mengecek existence sebelum menambah kolom/indeks.