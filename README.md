# Quantara — HRIS

Sistem **Human Resource Information System** untuk perusahaan Indonesia: payroll patuh PMK 168/2023 (PPh 21 TER), compliance UU 13/2003 + UU Cipta Kerja + PP 35/2021, multi business unit, multi-channel notification (in-app + email + FCM; Web Push VAPID beta), keamanan berlapis (2FA TOTP, password policy R1–R6, magic-byte upload validation, rate limit per endpoint, audit log immutable dengan hash chain).

Tiga aplikasi terintegrasi:

- **Backend** — CodeIgniter 4 REST API
- **Frontend** — Vue 3 SPA (PWA-ready, offline queue)
- **Mobile** — React Native 0.76 (Tier 1–3 modules)

[![CI](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/ci.yml/badge.svg)](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/ci.yml)
[![Mobile CI](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/mobile-ci.yml/badge.svg)](https://github.com/antowidianto/Quantara-HRIS/actions/workflows/mobile-ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-777BB4?logo=php)
![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.5-EF4223?logo=codeigniter)
![Vue](https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vue.js)
![Vite](https://img.shields.io/badge/Vite-5-646CFF?logo=vite)
![Tailwind](https://img.shields.io/badge/Tailwind-3-06B6D4?logo=tailwindcss)
![React Native](https://img.shields.io/badge/React%20Native-0.76-61DAFB?logo=react)

---

## Tech Stack

| Layer    | Pilihan                                                              |
|----------|----------------------------------------------------------------------|
| Backend  | CodeIgniter 4.5, PHP 8.1+ (CI matrix: 8.1 / 8.2 / 8.3)                |
| Frontend | Vue 3.5 + Vite 5 + Pinia 2 + Vue Router 4 + Tailwind 3 + Heroicons    |
| Mobile   | React Native 0.76 + TypeScript 5 + Zustand + react-query 5            |
| Database | MySQL 8 / MariaDB 10.6+ (SQLite untuk dev cepat)                      |
| Auth     | JWT (access + refresh, single-flight refresh) + 2FA TOTP + 8 backup codes |
| Mail     | SMTP (PHPMailer) + queue + exponential backoff                        |
| Cache    | Redis (production) / File handler (dev)                               |
| Push     | FCM HTTP v1; Web Push subscription tersedia, delivery VAPID berstatus beta dan memerlukan dependency opsional `minishlink/web-push` |
| PDF      | dompdf 2                                                              |
| Excel    | phpoffice/phpspreadsheet 2                                            |
| Charts   | Chart.js 4 + vue-chartjs                                              |
| Testing  | PHPUnit 10 + PHPStan 1.12 + Pint, Vitest 2 + ESLint + Playwright, Jest 29 (mobile) |
| CI       | GitHub Actions (backend matrix, frontend matrix, mobile pipeline)     |
| Container | Docker Compose (dev + prod), multi-stage Dockerfile                  |
| AI Assistant | Mock, Gemini, Groq, OpenAI, Anthropic, atau provider custom dengan API Chat Completions kompatibel OpenAI |

---

## Folder Structure

```
hris-project-1/
├── backend/                       # CodeIgniter 4 REST API
│   ├── app/
│   │   ├── Controllers/           # 122 controllers (Api/V1, Auth, Public, dst)
│   │   ├── Libraries/             # 200 services & helpers (ApprovalEngine, PayrollService, ...)
│   │   ├── Models/                # 19 model files (multi-tenant + encryption casts)
│   │   ├── Database/Migrations/   # 145 migrations
│   │   ├── Commands/              # 42 spark commands (cron, audit, outbox, cleanup)
│   │   ├── Filters/               # JWT, Permission, RateLimit, Idempotency, ...
│   │   ├── Constants/             # ApprovalStatus, AttendanceStatus, ErrorCode, ...
│   │   ├── Notifications/         # In-app + email + push templates
│   │   └── Validation/            # Custom rules (NIK, NPWP, magic-byte)
│   ├── scripts/                   # Database migration, test, and refactor scripts
│   ├── tests/Unit/                # 120 unit/integration policy test files
│   ├── env-templates/             # local/staging/production .env templates
│   ├── Dockerfile                 # Production multi-stage build
│   └── Dockerfile.dev             # Dev build
│
├── frontend/                      # Vue 3 SPA (PWA)
│   ├── src/
│   │   ├── views/                 # 170 view components
│   │   ├── components/            # Reusable UI (SimpleMaster, FormField, ...)
│   │   ├── composables/           # useConfirm, useDarkMode, useApi, ...
│   │   ├── locales/               # i18n (Bahasa Indonesia + English)
│   │   ├── stores/                # Pinia (auth, ui, ...)
│   │   ├── router/                # Vue Router + permission guard
│   │   └── api/                   # axios client + endpoint modules
│   ├── env-templates/             # local/staging/production .env templates
│   ├── Dockerfile                 # Production (multi-stage + nginx)
│   ├── Dockerfile.dev             # Dev build
│   └── nginx.conf                 # SPA fallback + gzip + cache headers
│
├── mobile/                        # React Native + TypeScript
│   ├── src/
│   │   ├── features/              # 16 feature modules (auth, attendance, leave, ...)
│   │   ├── api/                   # axios + interceptors (refresh, idempotency, X-Company-Id)
│   │   ├── store/                 # Zustand (auth)
│   │   ├── theme/                 # Light/dark via useColorScheme()
│   │   ├── i18n/                  # ID/EN
│   │   └── lib/                   # MMKV outbox, Keychain wrapper
│   ├── android/                   # Native Android project
│   ├── ios/                       # Native iOS project
│   ├── native-config/             # Manifest + Info.plist additions
│   └── scripts/                   # init-native.sh
│
├── adms-middleware/               # ZKTeco/ADMS push middleware
│   └── index.js                   # Forwarder to backend API
│
├── scripts/
│   ├── setup-dev.ps1              # Onboarding Windows (idempotent)
│   ├── setup-dev.sh               # Onboarding Linux/macOS/WSL
│   ├── switch-env.ps1/.sh/.cmd    # Toggle local / staging / production env
│   └── deploy.sh                  # Zero-downtime deploy + rollback
│
├── docs/                          # 80+ markdown spec
│   ├── openapi.yaml               # OpenAPI 3.0 spec
│   ├── ARCHITECTURE.md
│   ├── DEPLOYMENT.md
│   ├── DATA_PRIVACY.md            # UU PDP compliance
│   ├── PWA.md                     # Service worker + offline queue
│   ├── MOBILE_APP_PLAN.md
│   ├── decisions/0001-…           # Architecture Decision Records
│   └── …                          # Sprint logs + per-modul detail
│
├── docker-compose.yml             # Dev stack (mysql + redis + mailhog + backend + frontend)
├── docker-compose.prod.yml        # Production stack (nginx TLS + cron + resource limits)
├── SETUP.md                       # Detail dev setup (Windows + SQLite quick start)
├── CONTRIBUTING.md                # Branch / commit / PR / review SLA
└── .github/
    ├── workflows/ci.yml           # Backend + frontend pipeline
    ├── workflows/mobile-ci.yml    # Mobile pipeline
    └── dependabot.yml             # Auto-update deps weekly
```

---

## Fitur

### Compliance Indonesia
- **UU 13/2003 + UU Cipta Kerja + PP 35/2021** — cuti tahunan/besar/melahirkan/haid/penting, lembur multi-tier
- **PMK 168/2023 (PPh 21 TER)** — Tarif Efektif Rata-rata bulanan + progresif Desember
- **PP 37/2021 (BPJS JKP)** — 0.46% × min(salary, 5 jt), eligibility check PKWT/PKWTT
- **BPJS Kesehatan & Ketenagakerjaan** — JHT, JP, JKK, JKM, JKP, kelas Kesehatan
- **THR (PMK 6/2016)** — proporsional, handle resign mid-year
- **Pesangon (PHK)** — 17 alasan PHK dengan multiplier sesuai tabel UU
- **UU 27/2022 (UU PDP)** — consent flow, data export, deletion request, retensi pajak 5 tahun
- **UMP/UMK** — reference per provinsi/kota dengan warning kalau gaji < UMK

### Multi Business Unit
- Banyak perusahaan (tenant) dalam satu instalasi
- Konfigurasi payroll, cuti, kehadiran per company
- Custom field per company (settings table)
- Branding & logo per company
- Multi-tenant scoping otomatis di semua endpoint via `BaseModel::scoped()` + `PrivacyScope`

### Modul Utama
Auth & RBAC · Master Data (Company, Branch, Department, Position, Job Grade, Work Location, Cost Center) · Employee Management (data pribadi, kontrak PKWT/PKWTT, dokumen, riwayat jabatan) · Attendance (clock-in/out + selfie + geofence + cross-day shift, anti-cheat) · Shift & Roster (template, pattern, matrix view, generator) · Leave Management (annual/sick/maternity/special, half-day, balance prorata, anniversary carry-over) · Overtime (request + approval, perhitungan PP 35/2021) · Payroll (komponen, slip gaji, BPJS, PPh21 TER, THR, bank export, ePPT-21) · Reimbursement & Loans · Bonus Engine (formula DSL + gross-up) · Reports & Analytics · Audit Log (severity, request_id, diff, sanitization PII, hash chain) · HR Helpdesk / Tickets dengan SLA · Recruitment & Career Portal (public landing + apply + offer self-service) · Training & Sertifikasi · Performance Management (OKR, KPI, Review cycle) · Manpower Planning (MPP) + Job Information Change (JIC) · Asset Management (assignment, BAST) · Disciplinary (Surat Peringatan) · Resignation, Severance/PHK, Employee Transfer (mutasi) · Business Trip / SPPD · Anniversary & Birthday Widget · Onboarding/Offboarding Tasks · Notifications · PWA (offline queue, install prompt, service worker).

### Approval Governance

- Generic approval engine dengan **workflow snapshot** dan `chain_version_hash`, sehingga urutan step, kondisi, fallback, dan approver asli tetap dapat diaudit walaupun konfigurasi organisasi berubah.
- Prinsip **four-eyes / maker-checker**, larangan self-approval, tenant scoping, delegasi berbatas waktu/modul, idempotent submit, dan mode fail-closed untuk transaksi material.
- Domain adapter tersedia untuk Leave, Overtime, Attendance Change, Reimbursement, Loan, Business Trip, Bonus, Payroll Adjustment, Compensation Review, Training Registration, Timesheet, Candidate Offer, Generated Contract, dan Asset Transaction.
- Finalisasi status, saldo, attendance, jadwal cicilan, budget, atau side effect domain lain berjalan atomik dalam transaksi yang sama dengan keputusan final.
- Notification dan webhook setelah commit dikirim melalui transactional outbox dengan stable event ID, exponential retry, serta reclaim lease worker yang stale.
- Inbox Approval dan Delegation UI telah mencakup modul-modul baru; status offer, kontrak, aset, compensation review, dan bonus mengikuti lifecycle approval yang sama.

Panduan kontrol, tier risiko, matriks approver, dan status rollout tersedia di `docs/02_Business_Features/APPROVAL_GOVERNANCE_FMCG_INDONESIA.md`.

### Penyempurnaan UI Master Data

- Shift Assignment, Shift Pattern, dan Task Template memiliki loading state saat simpan/hapus untuk mencegah submit ganda.
- Form master Approval Chain, Allowance/Natura, Benefit Policy, Contract Template, Organization Role, dan Recruitment Pipeline diperjelas dengan label dialog dinamis, placeholder konsisten, serta atribut tombol dan ARIA yang lebih ramah screen reader.
- Terjemahan Bahasa Indonesia dan English ditambahkan untuk Allowance/Natura, Benefit Policy, dan Recruitment Pipeline.

### HR AI Copilot

Selain driver bawaan, backend mendukung provider pihak ketiga dengan endpoint **OpenAI-compatible Chat Completions** melalui `AI_ASSISTANT_DRIVER=custom`. Konfigurasikan `AI_ASSISTANT_BASE_URL`, `AI_ASSISTANT_API_KEY`, dan `AI_ASSISTANT_MODEL`; `AI_ASSISTANT_CA_BUNDLE` bersifat opsional. Jangan commit API key asli ke repository. Respons Markdown di frontend dirender dengan escaping HTML untuk mengurangi risiko injeksi konten.

### Security
- JWT dengan rotation refresh token, single-flight refresh
- Argon2id password hashing + history check (5 password terakhir)
- Password policy R1–R6: panjang, kompleksitas, common blocklist, user-specific, forced rotation
- 2FA TOTP + 8 backup recovery code (single-use, hashed)
- File upload magic-byte validation (anti spoofed MIME)
- Rate limit per endpoint sensitif (login, forgot-password, 2FA, reset, export)
- CORS whitelist, CSP headers, HSTS, X-Frame-Options, Permissions-Policy
- SQL injection prevention (query builder + parameter binding)
- XSS protection
- Field sensitif (NIK, NPWP, rekening) di-encrypt at rest, transparant via `BaseModel::$encryptedFields`
- Audit log immutable dengan hash chain — `php spark audit:verify-chain` untuk verify integrity
- Idempotency-Key wajib di endpoint mutation kritis (clock-in/out, leave create, payslip mutation)
- Webhook outbox dengan HMAC signature + replay protection

### Mobile App
React Native 0.76 dengan **16 feature module**:

| Tier | Modul                                                                     |
|------|---------------------------------------------------------------------------|
| 1    | Auth (login + 2FA), Attendance, Leave, Approvals, Payslip, Profile, Devices |
| 2    | Overtime, Reimbursement, Business Trip, Helpdesk                          |
| 3    | Notifications (in-app + push), Performance, Training, Tasks, Settings (theme, locale, DND, push prefs) |

Highlights:
- Outbox MMKV untuk clock-in/out + leave saat offline, replay otomatis di NetInfo online / AppState active
- Keychain untuk JWT, MMKV untuk preferensi non-rahasia
- Biometric login (Face ID / Touch ID / Fingerprint)
- FCM push notification + deep link
- Light/dark theme mengikuti `useColorScheme()`
- i18n Bahasa Indonesia + English

Lihat `mobile/README.md` dan `docs/MOBILE_APP_PLAN.md` untuk arsitektur detail.

---

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

**Terminal 1 — Backend (CodeIgniter 4)**
```bash
cd backend
composer install
cp .env.example .env       # atau pakai SQLite default (lihat SETUP.md)
php spark migrate
php spark db:seed InitialSeeder
php spark serve --port 8080
```

**Terminal 2 — Frontend (Vue 3 + Vite)**
```bash
cd frontend
npm install
cp .env.example .env       # set VITE_API_BASE_URL=http://localhost:8080
npm run dev
```

**Terminal 3 — Mobile (opsional, butuh Android SDK / Xcode)**
```bash
cd mobile
npm install
cp .env.example .env       # set API_BASE_URL=http://10.0.2.2:8080 (Android emulator)
npm run android            # atau: npm run ios
```

Buka `http://localhost:5173`.

**Default login:** `admin@hris.local` / `Admin@12345` (wajib ganti setelah login pertama)

### Cara dengan Docker (full stack)

```bash
docker compose up -d
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
```

Service yang nyala:

| URL                          | Service                              |
|------------------------------|--------------------------------------|
| `http://localhost:5173`      | Frontend (Vite dev, hot reload)      |
| `http://localhost:8080`      | Backend (CodeIgniter)                |
| `http://localhost:8025`      | MailHog UI (catch outgoing email)    |
| `localhost:3306`             | MySQL 8                              |
| `localhost:6379`             | Redis 7                              |

### Memproses approval outcome outbox

Keputusan approval menyimpan event notification/webhook di transactional outbox. Jalankan command berikut secara berkala melalui cron/scheduler agar event yang sudah committed dipublikasikan:

```bash
cd backend
php spark approval-outbox:process --batch=50
```

Worker aman dijalankan berulang; event menggunakan ID stabil, retry eksponensial, dan lease untuk mengambil kembali job worker yang stale.

---

## Switch Environment (local / staging / production)

Backend & frontend punya env-templates per environment. Pakai script untuk swap `.env` dengan aman (auto-backup ke `.env.backup`).

```powershell
# Windows
.\scripts\switch-env.ps1 local        # development di laptop
.\scripts\switch-env.ps1 staging      # connect ke server staging
.\scripts\switch-env.ps1 production   # connect ke server live
.\scripts\switch-env.ps1 status       # lihat env aktif sekarang
```

```bash
# Linux / macOS
bash scripts/switch-env.sh local
bash scripts/switch-env.sh status
```

`production.env` template terisolasi dari git (`.gitignore` block file `production.env`, hanya `production.env.example` yang ke-commit). Sebelum aktifkan environment production, isi placeholder `<REPLACE_*>` di `backend/env-templates/production.env`.

---

## Testing & Quality

```bash
# Backend
cd backend
composer test            # PHPUnit (120 unit/integration policy test files)
composer stan            # PHPStan (level 0 saat ini, target naik bertahap)
composer format          # Pint auto-format
composer ci              # migration, permission, route, tenant/security checks, PHPStan + PHPUnit

# Frontend
cd frontend
npm run lint             # ESLint
npm run lint:fix         # ESLint + Prettier auto-fix
npm test                 # Vitest
npm run build            # Production build (cek bundle size)

# Mobile
cd mobile
npm run tsc              # TypeScript type-check
npm run lint             # ESLint
npm test                 # Jest
```

Detail static analysis & coverage policy ada di `CONTRIBUTING.md`.

---

## CI Pipeline

| Workflow                       | Trigger                                         | Jobs                                                                   |
|--------------------------------|-------------------------------------------------|------------------------------------------------------------------------|
| `.github/workflows/ci.yml`     | push / PR ke `main`, `master`, `develop`        | Backend matrix PHP 8.1/8.2/8.3 (PHPStan + PHPUnit) · Frontend matrix Node 18/20 (ESLint + Vitest + build) |
| `.github/workflows/mobile-ci.yml` | push / PR yang menyentuh `mobile/**`          | TypeScript check + ESLint + Jest. Android Gradle dry-run di tag `mobile-*` |
| `.github/dependabot.yml`       | Mingguan                                        | Auto-update composer + npm + GitHub Actions                            |

---

## Documentation

- **`docs/openapi.yaml`** — OpenAPI 3.0 spec (paste ke [editor.swagger.io](https://editor.swagger.io)). Backend juga expose `GET /api/v1/openapi.json` (live spec dari controller).
- **`docs/ARCHITECTURE.md`** — overview stack & data flow
- **`docs/DEPLOYMENT.md`** — production deployment (Nginx + PHP-FPM + Cron + SSL)
- **`docs/DOCKER.md`** — dev & prod compose detail
- **`docs/ENVIRONMENTS.md`** — multi-environment workflow
- **`docs/AUDIT.md`** — audit log architecture & hash chain
- **`docs/DATA_PRIVACY.md`** — UU PDP compliance
- **`docs/PWA.md`** — service worker + offline queue
- **`docs/MOBILE_APP_PLAN.md`** — mobile roadmap & arsitektur
- **`docs/02_Business_Features/APPROVAL_GOVERNANCE_FMCG_INDONESIA.md`** — governance approval, tier risiko, matriks approver, transactional outbox, dan status adapter domain
- **`docs/CRON_JOBS.md`** — daftar scheduled task
- **`docs/BACKUP_DR.md`** — backup & disaster recovery
- **`docs/decisions/`** — Architecture Decision Records (ADR)
- **`docs/IMPROVEMENT_CHECKLIST.md`** — sprint backlog & progress
- **80+ docs lain** untuk modul-specific (Payroll, Attendance, Recruitment, Bonus Engine, dll)

---

## Production Deployment

Quick path:

```bash
# Build & push image lewat CI (atau manual)
docker build -t registry.example.com/hris/backend:1.0.0 -f backend/Dockerfile backend/
docker build -t registry.example.com/hris/frontend:1.0.0 -f frontend/Dockerfile frontend/

# Deploy
export HRIS_VERSION=1.0.0
export HRIS_REGISTRY=registry.example.com/hris
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d

# Migrate (sekali per release)
docker compose -f docker-compose.prod.yml exec backend php spark migrate
```

`docker-compose.prod.yml` bedanya dengan dev: image self-contained (no source mount), nginx sebagai TLS terminator, MySQL/Redis bind ke `127.0.0.1`, healthcheck wajib di semua service, resource limit konkret, log rotation `json-file` 20m × 10 file.

Untuk zero-downtime + rollback pakai `scripts/deploy.sh`. Detail di `docs/DEPLOYMENT.md`.

---

## Berkontribusi

Lihat `CONTRIBUTING.md` — branch & commit convention, PR template, review SLA, code style, dan checklist mandatory sebelum merge.

---

## Lisensi

Proprietary — Internal use.
