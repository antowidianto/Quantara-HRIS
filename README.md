# HRIS Project — Production-Ready Indonesian HRIS

Sistem Human Resource Information System (HRIS) yang patuh pada peraturan Kemenaker terbaru, mendukung **multi business unit**, payroll siap pakai, notifikasi in-app & email, dan keamanan berlapis.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | CodeIgniter 4 (PHP 8.1+) |
| Frontend | Vue 3 + Vite + Tailwind CSS |
| Database | MySQL 8.x |
| Auth | JWT (Access + Refresh tokens) |
| Mail | SMTP (configurable) |
| Cache | File / Redis |

## Folder Structure

```
hris-project-1/
├── backend/         # CodeIgniter 4 API
├── frontend/        # Vue 3 + Vite + Tailwind SPA
├── docs/            # Documentation
├── docker/          # Docker compose for local dev (optional)
└── README.md
```

## Features

### Compliance Kemenaker (UU Cipta Kerja & PP 35/2021, PMK 168/2023)
- Cuti tahunan minimal 12 hari (auto-accrual)
- Cuti besar, cuti melahirkan (3 bulan), cuti haid, cuti penting (menikah, keluarga meninggal, dll)
- Lembur sesuai PP 35/2021 (perhitungan jam pertama 1.5x, jam berikutnya 2x; weekend/libur formula khusus)
- THR sesuai PMK
- PPh 21 dengan skema **TER (Tarif Efektif Rata-rata)** PMK 168/2023
- BPJS Kesehatan & BPJS Ketenagakerjaan (JHT, JP, JKK, JKM)
- UMP/UMK reference per provinsi/kota

### Multi Business Unit
- Banyak perusahaan (tenant) dalam satu instalasi
- Konfigurasi payroll, cuti, kehadiran per business unit
- Custom field per business unit
- Branding & logo per business unit

### Modul Utama
- Authentication & RBAC
- Master Data (Company, Branch, Department, Position, Job Grade)
- Employee Management (data pribadi, kontrak, dokumen, riwayat jabatan)
- Attendance (clock-in/out, shift, geofence, foto)
- Leave Management (berbagai tipe, approval workflow)
- Overtime (request & approval, perhitungan otomatis)
- Payroll (komponen, slip gaji, BPJS, PPh21 TER, THR)
- Loan / Cash Advance (kasbon)
- Reimbursement
- Notifications (in-app + email)
- Reports & Analytics
- Audit Log

### Security
- JWT dengan rotation refresh token
- Argon2id password hashing
- Rate limiting per endpoint
- CSRF protection (untuk endpoint stateful)
- CORS whitelist
- Input validation di semua endpoint
- SQL injection prevention (query builder + parameter binding)
- XSS protection (output encoding)
- Security headers (CSP, HSTS, X-Frame-Options, dll)
- Encrypted sensitive fields (NIK, NPWP, rekening) at rest
- Audit log untuk semua aksi sensitif
- 2FA (TOTP) opsional
- Account lockout setelah N gagal login
- Strong password policy

## Quick Start

### Backend
```bash
cd backend
composer install
cp .env.example .env
# edit .env (DB credentials, JWT secret, SMTP)
php spark migrate
php spark db:seed InitialSeeder
php spark serve --port 8080
```

### Frontend
```bash
cd frontend
npm install
cp .env.example .env
# set VITE_API_BASE_URL
npm run dev
```

Buka `http://localhost:5173`. Default admin akan dibuat oleh seeder (lihat output seeder).

## Deployment Production
Lihat `docs/DEPLOYMENT.md` untuk panduan lengkap (Nginx, PHP-FPM, supervisor untuk queue, sertifikat SSL).

## Lisensi
Proprietary — Internal use.