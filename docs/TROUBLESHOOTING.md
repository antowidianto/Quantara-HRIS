# HRIS — Troubleshooting & Fix Notes

Catatan perbaikan untuk error-error yang muncul saat coba menambah data via web.

## Ringkasan akar masalah yang sudah diperbaiki

| # | Gejala | Akar masalah | Fix |
|---|---|---|---|
| 1 | `FOREIGN KEY constraint failed` saat super admin create payroll period / branch / department | Super admin login dengan `users.company_id = NULL`. `companyId()` me-return 0, lalu insert pakai `company_id=0` yang melanggar FK ke `companies.id`. | `ApiController::companyId()` sekarang baca body/header `X-Company-Id` untuk super admin. `requireCompanyId()` melempar 422 yang jelas kalau super admin lupa pilih perusahaan. `MasterCrudController`, `PayrollPeriodController`, `EmployeeController` dipindah ke `requireCompanyId()`. |
| 2 | `UNIQUE constraint failed: permissions.slug / users.email / roles.id / ...` setelah `db:seed InitialSeeder` ulang | Seeder lama tidak idempotent; selalu `INSERT` tanpa cek existing. | `InitialSeeder` ditulis ulang idempotent — tiap insert dicek dulu via slug/code/email. |
| 3 | `near ")": syntax error` pada `INSERT INTO leave_types ()` | SQLite + `insertBatch()` quirk saat array of rows ada nilai `null` (`description`, `max_days`). | `seedDemoCompany()` sekarang insert per-row pakai `insert()` bukan `insertBatch()`. |
| 4 | `no such function: YEAR` di `/reports/leave-summary` | `YEAR(l.start_date)` tidak dikenal di SQLite (MySQL-only). | `ReportController::leaveSummary()` ganti pakai range `start_date >= 'YYYY-01-01' AND <= 'YYYY-12-31'`. |
| 5 | `table audit_logs has no column named severity` | Migrasi #007 belum dijalankan tapi `AuditFilter` sudah pakai kolom baru. | `AuditLogger` dan `AuditFilter` deteksi schema otomatis (`getFieldData`) dan hanya menulis kolom yang ada. Kompatibel dengan DB lama. |
| 6 | `Object of class CodeIgniter\HTTP\Response could not be converted to string` saat OPTIONS preflight ke endpoint yang 404 | `set404Override` mengembalikan Response object — beberapa kondisi internal CI4 mencoba string-cast nya. | Ganti dengan `$resp->send(); exit;` agar pasti tertulis dan keluar. |
| 7 | `Error connecting to the database: target machine actively refused it` (MySQL) | Service MySQL lokal tidak nyala. | Bukan kode — start MySQL service, atau pakai SQLite seperti yang sudah Anda lakukan. |
| 8 | `error_exception.php: No such file or directory` saat error CLI | View error_exception.php memang tidak ada di app/Views/errors/cli (skenario CI4 default). | Tidak fatal — hanya muncul saat error lain sudah terjadi. Akar masalah di error sebelumnya yang harus diperbaiki. |

## Cara reset penuh (rekomendasi)

Karena schema sudah evolve dan ada data residu, paling bersih:

```bash
cd backend

# 1. (opsional kalau pakai SQLite) hapus file DB lama
del writable\database.sqlite      # Windows
# atau: rm writable/database.sqlite

# 2. jalankan migrasi ulang dari awal
php spark migrate:rollback   # turunkan dulu kalau pakai MySQL
php spark migrate            # naikkan semua sampai #007

# 3. seed master data
php spark db:seed InitialSeeder
php spark db:seed IndonesianMastersSeeder
```

Login default:
- Email: `admin@hris.local`
- Password: `Admin@12345` (wajib ganti pas login pertama)

## Super admin: cara membuat data

Karena super admin (`role_id=1`) tidak terikat company tertentu, untuk operasi
tulis (create branch, department, employee, dst.) **wajib menentukan target
company**. Dua cara:

1. **Header HTTP** — recommended untuk SPA:
   ```
   X-Company-Id: 1
   ```
   Tambahkan di `frontend/src/api/client.js` interceptor saat super admin
   sudah memilih company aktif.

2. **Body JSON** — sertakan `company_id` di payload create:
   ```json
   POST /api/v1/branches
   {
     "code": "JKT-01",
     "name": "Cabang Jakarta Pusat",
     "company_id": 1
   }
   ```

Kalau super admin lupa pilih company, API akan menolak dengan:
```json
{
  "status": "error",
  "code": "COMPANY_REQUIRED",
  "message": "Super admin harus memilih perusahaan target. Sertakan company_id di body atau header X-Company-Id."
}
```

User non-super-admin tidak terdampak — `company_id` mereka diambil otomatis
dari profil mereka.

## Saran improvement frontend (belum diimplementasi)

Untuk pengalaman lebih halus, tambahkan **company switcher** di header SPA
khusus super admin:

1. Saat login, frontend fetch `/companies` dan tampilkan dropdown.
2. Saat super admin pilih company, simpan ke pinia store + localStorage.
3. Di `api/client.js` interceptor, tambahkan:
   ```js
   if (auth.user?.role_id === 1 && auth.activeCompanyId) {
     config.headers['X-Company-Id'] = auth.activeCompanyId
   }
   ```
4. Kalau tidak ada active company, tampilkan banner "Pilih company dulu" sebelum
   user bisa create master data.

## Verifikasi cepat setelah fix

```bash
# 1. Backend lint
php -l backend/app/Libraries/AuditLogger.php
php -l backend/app/Controllers/Api/V1/ApiController.php

# 2. Run server
cd backend && php spark serve --port 8080

# 3. Frontend
cd frontend && npm run dev

# 4. Smoke test login
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@hris.local","password":"Admin@12345"}'

# Akan return tokens + must_change_password=true.
```

## Akhirnya — perilaku yang sekarang diharapkan

- **User biasa** (HR Admin/Manager/Employee): bisa create data karena
  `tenant->getCompanyId()` me-return company mereka.
- **Super admin** dengan `X-Company-Id` header: bisa create data target.
- **Super admin tanpa context**: dapat error 422 jelas, bukan FK violation.
- **Audit log**: tidak akan crash tabel meski belum migrasi #007.
- **`db:seed` ulang**: aman, skip data yang sudah ada.
- **`/reports/leave-summary`**: bekerja di MySQL & SQLite.