# Tech Stack & Build System

## Stack

| Layer    | Pilihan                                                                  |
|----------|--------------------------------------------------------------------------|
| Backend  | CodeIgniter 4.5, PHP 8.1+ (CI matrix: 8.1 / 8.2 / 8.3, target 8.2)        |
| Frontend | Vue 3.5 + Vite 5 + Pinia 2 + Vue Router 4 + Tailwind 3 + Heroicons        |
| Mobile   | React Native 0.76 + TypeScript 5 (strict) + Zustand + react-query 5       |
| Database | MySQL 8 / MariaDB 10.6+ (SQLite untuk dev cepat di Windows)               |
| Auth     | JWT (access + refresh, single-flight refresh) + 2FA TOTP                  |
| Cache    | Redis (production) / File handler (dev)                                   |
| Mail     | SMTP via PHPMailer + queue + exponential backoff (MailHog di dev)         |
| Push     | Web Push (VAPID + minishlink/web-push) + FCM HTTP v1                      |
| PDF      | dompdf 2 — Excel: phpoffice/phpspreadsheet 2 — Charts: Chart.js 4         |
| Testing  | PHPUnit 10 + PHPStan 1.12 + Pint · Vitest 2 + ESLint · Jest 29 + RTL      |
| CI       | GitHub Actions (backend matrix, frontend matrix, mobile pipeline)         |
| Deploy   | Docker Compose (dev + prod), multi-stage Dockerfile, Nginx + PHP-FPM      |

## Key Backend Libraries

- `firebase/php-jwt` — JWT signing/verification
- `robthree/twofactorauth` — TOTP 2FA
- `ramsey/uuid` — UUID v7
- `phpmailer/phpmailer` — SMTP mailer
- `dompdf/dompdf` — PDF generator (slip gaji, kontrak, etc)
- `phpoffice/phpspreadsheet` — Excel import/export

## Conventions

- **Backend**: PHPStan level 0 saat ini (target naik ke 4–6). Type hint wajib di parameter + return. Akses DB lewat `App\Models\*` (Model + `BaseModel::scoped()`) untuk kode baru, bukan `Database::connect()->table(...)`. Controller ≤ 500 baris (target ≤ 300), Service ≤ 800 baris. Pint config di `pint.json`.
- **Frontend**: `<script setup>` Composition API. View ≤ 600 baris. Tailwind utility-first. State: Pinia untuk shared (auth, ui), composable untuk lokal. String UI lewat `t(...)` (`@/locales`).
- **Mobile**: TypeScript strict. State: Zustand (auth) + react-query (server state) — **tidak** pakai Redux. Storage: Keychain untuk JWT, MMKV untuk preferensi + outbox. Networking: pakai `src/api/client.ts` (axios singleton) — jangan bikin axios instance baru per feature. Idempotency-Key auto-injected oleh interceptor untuk POST mutation kritis. Multi-tenant header `X-Company-Id`.
- **Multi-tenant**: SEMUA query domain wajib pakai `BaseModel::scoped()` atau filter `company_id` manual. Audit log mandatory lewat `AuditLogger` di setiap mutation; jangan di-bypass. Hash chain di-verify lewat `php spark audit:verify-chain`.
- **Encryption**: Field sensitif (NIK, NPWP, rekening) di-encrypt at rest via `BaseModel::$encryptedFields` — transparant, jangan akses raw column.

## Common Commands

### Backend (`cd backend`)

```bash
composer install
cp .env.example .env

# Database
php spark migrate                       # apply migrations
php spark migrate:status                # check migration state
php spark migrate --fresh               # drop + re-run (loses data)
php spark migrate:rollback -b 0
php spark db:seed InitialSeeder         # default admin + demo data

# Dev server
php spark serve --port 8080             # http://localhost:8080

# Test & quality
composer test                           # PHPUnit (Unit suite default)
composer test:unit                      # PHPUnit Unit suite
composer stan                           # PHPStan
composer stan:baseline                  # Regenerate baseline
composer format                         # Pint auto-format
composer format:check                   # Pint dry-run
composer ci                             # stan + test (mirror CI)

# Spark commands sering dipakai
php spark audit:verify-chain            # verifikasi hash chain audit log
php spark email:queue                   # process outgoing email queue
php spark webhook:process                # process webhook outbox
# Lihat docs/CRON_JOBS.md untuk daftar lengkap scheduled task
```

### Frontend (`cd frontend`)

```bash
npm install
cp .env.example .env                    # set VITE_API_BASE_URL=http://localhost:8080

npm run dev                             # http://localhost:5173 (hot reload)
npm run dev:lan                         # bind 0.0.0.0 untuk test mobile di LAN
npm run build                           # production build → dist/
npm run build:analyze                   # build + bundle analyzer
npm run preview                         # preview production build
npm run lint                            # ESLint check
npm run lint:fix                        # ESLint auto-fix
npm run format                          # Prettier write
npm test                                # Vitest (single run)
npm run test:watch                      # Vitest watch
```

### Mobile (`cd mobile`)

```bash
npm install
cp .env.example .env                    # API_BASE_URL=http://10.0.2.2:8080 (Android emu)

npm start                               # Metro bundler
npm run android                         # build + run Android
npm run ios                             # build + run iOS (macOS only)
npm run tsc                             # TypeScript type-check (strict)
npm run lint                            # ESLint
npm test                                # Jest
```

### Docker (full stack dev)

```bash
docker compose up -d                    # mysql + redis + mailhog + backend + frontend
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
docker compose logs -f backend
docker compose down -v                  # reset (drop volumes)
```

Service URLs (dev):
- Frontend: `http://localhost:5173`
- Backend API: `http://localhost:8080` (base path `/api/v1`)
- MailHog UI: `http://localhost:8025`
- MySQL: `localhost:3306` · Redis: `localhost:6379`

### Multi-environment switching

```bash
# Windows
.\scripts\switch-env.ps1 local|staging|production|status

# Linux/macOS/WSL
bash scripts/switch-env.sh local|staging|production|status
```

Env templates di `backend/env-templates/` dan `frontend/env-templates/`. `production.env` di-gitignore — hanya `production.env.example` yang ke-commit. Auto-backup ke `.env.backup` saat switch.

### Default login (dev)

`admin@hris.local` / `Admin@12345` — wajib ganti setelah login pertama.
