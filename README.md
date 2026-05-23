# HRIS — Production-Ready Indonesian HRIS

Sistem Human Resource Information System (HRIS) yang patuh pada peraturan Kemenaker terbaru, mendukung **multi business unit**, payroll siap pakai, notifikasi multi-channel, dan keamanan berlapis (2FA, password policy, file magic-byte validation, rate limit per endpoint, audit log lengkap).

[![CI](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/ci.yml/badge.svg)](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.5-EF4223?logo=codeigniter)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?logo=vue.js)
![Vite](https://img.shields.io/badge/Vite-5-646CFF?logo=vite)
![Tailwind](https://img.shields.io/badge/Tailwind-3-06B6D4?logo=tailwindcss)
![Tests](https://img.shields.io/badge/tests-349%20passing-brightgreen)

## Tech Stack

| Layer    | Technology                                      |
|----------|-------------------------------------------------|
| Backend  | CodeIgniter 4 (PHP 8.1+)                        |
| Frontend | Vue 3 + Vite + Tailwind CSS + Pinia             |
| Database | MySQL 8 / MariaDB 10.6+ (SQLite untuk dev)      |
| Auth     | JWT (Access + Refresh) + 2FA TOTP + Backup Codes |
| Mail     | SMTP (PHPMailer + queue + exponential backoff)  |
| Cache    | Redis (production) / File (dev)                 |
| Push     | Web Push (VAPID + minishlink/web-push)          |
| PDF      | dompdf                                          |
| Excel    | phpoffice/phpspreadsheet                        |

## Folder Structure

```
hris-project-1/
├── backend/             # CodeIgniter 4 API (65+ controllers, 39 migrations, 30+ libraries)
│   ├── app/
│   ├── tests/Unit/      # 312 tests, PHPStan level 0 (clean)
│   ├── Dockerfile       # Production multi-stage build
│   └── Dockerfile.dev   # Development build
├── frontend/            # Vue 3 + Vite SPA (90+ views, PWA-ready)
│   ├── src/
│   ├── Dockerfile       # Production (multi-stage + nginx)
│   ├── Dockerfile.dev   # Development build
│   └── nginx.conf       # SPA fallback + gzip + cache headers
├── scripts/
│   ├── setup-dev.ps1    # Onboarding script Windows
│   └── setup-dev.sh     # Onboarding script Linux/macOS
├── docs/
│   ├── openapi.yaml     # OpenAPI 3.0 spec (Swagger-compatible)
│   ├── IMPROVEMENT_CHECKLIST.md  # Sprint roadmap & progress
│   ├── DEPLOYMENT.md    # Production deployment guide
│   └── …                # 35+ feature-specific docs
├── docker-compose.yml   # Dev stack (mysql + redis + mailhog + backend + frontend)
└── .github/
    ├── workflows/ci.yml # CI pipeline (PHPUnit + PHPStan + ESLint + Vitest + build)
    └── dependabot.yml   # Auto-update deps weekly
```

## Features

### Compliance Indonesia
- **UU 13/2003 + UU Cipta Kerja + PP 35/2021**: Cuti tahunan, cuti besar, cuti melahirkan/haid/penting, lembur multi-tier
- **PMK 168/2023 (PPh 21 TER)**: Tarif Efektif Rata-rata bulanan + progresif Desember
- **PP 37/2021 (BPJS JKP)**: 0.46% × min(salary, 5jt), eligibility check PKWT/PKWTT
- **BPJS Kesehatan & Ketenagakerjaan**: JHT, JP, JKK, JKM, JKP, kelas Kesehatan
- **THR (PMK 6/2016)**: Proporsional, handle resign mid-year
- **Pesangon (PHK)**: 17 alasan PHK dengan multiplier per tabel UU
- **UU 27/2022 (UU PDP)**: Consent flow + data export + deletion request + retensi pajak 5 thn
- **UMP/UMK**: Reference per provinsi/kota dengan warning kalau gaji < UMK

### Multi Business Unit
- Banyak perusahaan (tenant) dalam satu instalasi
- Konfigurasi payroll, cuti, kehadiran per company
- Custom field per company (settings table)
- Branding & logo per company
- Multi-tenant scoping otomatis di semua endpoint (PrivacyScope)

### Modul Utama (30+ modul)
- Auth & RBAC (roles, permissions, 2FA, password policy)
- Master Data (Company, Branch, Department, Position, Job Grade, Work Location)
- Employee Management (data pribadi, kontrak PKWT/PKWTT, dokumen, riwayat jabatan)
- Attendance (clock-in/out + selfie + geofence + cross-day shift, anti-cheat)
- Shift & Roster (template, pattern, matrix view, generator)
- Leave Management (annual/sick/maternity/special, half-day, balance prorata)
- Overtime (request + approval, perhitungan PP 35/2021)
- Payroll (komponen, slip gaji, BPJS, PPh21 TER, THR, bank export, ePPT-21)
- Reimbursement & Loans
- Reports & Analytics (headcount, attendance heatmap, payroll summary)
- Audit Log (severity, request_id, diff, sanitization PII)
- HR Helpdesk / Tickets
- Recruitment & Career Portal (public landing + apply)
- Training & Sertifikasi (budget, registrations, expiry)
- Performance Management (OKR, KPI, Review cycle)
- Manpower Planning (MPP) + Job Information Change (JIC)
- Asset Management (assignment, BAST)
- Disciplinary (Surat Peringatan)
- Resignation, Severance/PHK, Employee Transfer (mutasi)
- Business Trip / SPPD
- Anniversary & Birthday Widget
- Onboarding/Offboarding Tasks (template + checklist)
- Notifications (in-app + email + Web Push, template per company, exponential backoff)
- PWA (offline queue, install prompt, service worker)

### Security
- JWT dengan rotation refresh token
- Argon2id password hashing + history check (5 password terakhir)
- Password policy R1-R6: panjang, kompleksitas, common blocklist, user-specific, forced rotation
- 2FA TOTP + 8 backup recovery codes (single-use, hashed)
- File upload magic-byte validation (anti spoofed MIME)
- Rate limit per endpoint sensitif (login, forgot-password, 2FA, reset, export)
- CORS whitelist
- Input validation di semua endpoint
- SQL injection prevention (query builder + parameter binding)
- XSS protection + CSP headers
- Security headers (HSTS, X-Frame-Options, Permissions-Policy)
- Encrypted sensitive fields (NIK, NPWP, rekening) at rest
- Audit log untuk semua aksi sensitif (immutable)

## Quick Start

### Cara cepat (setup script)

```powershell
# Windows
.\scripts\setup-dev.ps1
```

```bash
# Linux / macOS / WSL
bash scripts/setup-dev.sh
```

Script akan: cek deps → install backend (composer + .env + migrate + seed) → install frontend (npm + .env). Idempotent, aman dijalankan ulang.

### Cara manual

**Backend** (Terminal 1)
```bash
cd backend
composer install
cp .env.example .env
# edit .env (DB credentials, JWT secret, SMTP) — atau pakai SQLite default
php spark migrate
php spark db:seed InitialSeeder
php spark serve --port 8080
```

**Frontend** (Terminal 2)
```bash
cd frontend
npm install
cp .env.example .env
# set VITE_API_BASE_URL=http://localhost:8080
npm run dev
```

Buka `http://localhost:5173`.

**Default login**: `admin@hris.local` / `Admin@12345` (wajib ganti setelah login pertama)

### Cara dengan Docker (full stack)

```bash
docker compose up -d
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
```

Service yang nyala:
- `http://localhost:5173` — Frontend (Vite dev server, hot reload)
- `http://localhost:8080` — Backend (CodeIgniter)
- `http://localhost:8025` — MailHog UI (catch outgoing email)
- MySQL `localhost:3306`, Redis `localhost:6379`

## Testing & Quality

```bash
# Backend
cd backend
composer test          # PHPUnit (312 tests)
composer stan          # PHPStan static analysis (level 0, clean)
composer ci            # both

# Frontend
cd frontend
npm run lint           # ESLint (0 errors)
npm test               # Vitest
npm run build          # Production build
```

**Coverage saat ini** (lihat `docs/IMPROVEMENT_CHECKLIST.md` untuk roadmap):

| Library | Tests | Status |
|---|---|---|
| OvertimeCalculator | 22 | ✅ |
| SeveranceCalculator | 30 | ✅ |
| LeaveBalanceService | 16 | ✅ |
| BpjsCalculator | 25 | ✅ |
| ThrCalculator | 20 | ✅ |
| PayrollSettings + ShiftTimeHelper | 28 | ✅ |
| PasswordPolicy + 2FA + FileSignature | 54 | ✅ |
| EmailTemplate + EmailQueueBackoff | 20 | ✅ |
| Privacy + Pph21Ter mapping | 32 | ✅ |
| Constants (Attendance/Employment/Approval/ErrorCode/WebhookEvent) | 18 | ✅ |
| CacheService | 8 | ✅ |
| WebhookSigner (HMAC + replay protection) | 11 | ✅ |
| **Total** | **349** | **all green** |

## Documentation

- **`docs/openapi.yaml`** — OpenAPI 3.0 spec, paste ke [editor.swagger.io](https://editor.swagger.io) untuk interactive docs
- **`docs/IMPROVEMENT_CHECKLIST.md`** — Roadmap sprint + progress (Sprint 1-9 done)
- **`docs/DEPLOYMENT.md`** — Production deployment (Nginx + PHP-FPM + Cron + SSL)
- **`docs/SETUP.md`** — Detail setup development
- **`docs/AUDIT.md`** — Audit logging architecture
- **`docs/PWA.md`** — Progressive Web App
- **`docs/DATA_PRIVACY.md`** — UU PDP compliance
- **35+ docs lain** untuk modul-specific (Payroll, Attendance, Recruitment, dll)

## Deployment Production

Lihat `docs/DEPLOYMENT.md` untuk panduan Nginx + PHP-FPM, atau gunakan Dockerfile production:

```bash
# Build images
docker build -t hris-backend:latest -f backend/Dockerfile backend/
docker build --build-arg VITE_API_BASE_URL=https://api.hris.example.com \
             -t hris-frontend:latest -f frontend/Dockerfile frontend/

# Run (dengan reverse proxy nginx + ssl di depan)
docker run -d -p 9000:9000 --env-file backend/.env.production \
    -v hris_writable:/var/www/html/writable hris-backend:latest
docker run -d -p 80:80 hris-frontend:latest
```

## Contributing

- Branch dari `main`, PR dengan judul Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`)
- CI wajib hijau sebelum merge (PHPStan + PHPUnit + ESLint + Vitest + build)
- Tambah unit test untuk pure-function library baru
- Migrasi pakai pola **additive-only** (jangan rename/drop kolom existing)

## License

Proprietary — Internal use.