# Project Structure

Monorepo dengan tiga aplikasi terpisah yang berbagi domain & API contract.

```
hris-project-1/
├── backend/                       # CodeIgniter 4 REST API
├── frontend/                      # Vue 3 SPA (PWA-ready)
├── mobile/                        # React Native + TypeScript
├── docs/                          # 80+ markdown spec + ADR + openapi
├── scripts/                       # setup-dev, switch-env, deploy
├── docker-compose.yml             # Dev stack
├── docker-compose.prod.yml        # Prod stack (TLS, healthcheck, limits)
├── README.md · SETUP.md · CONTRIBUTING.md
└── .github/workflows/             # ci.yml + mobile-ci.yml + dependabot.yml
```

## Backend (`backend/`)

CodeIgniter 4 default layout, dengan beberapa folder tambahan domain-spesifik.

```
backend/
├── app/
│   ├── Controllers/               # ~95 controllers (Api/V1, Auth, Public, ...)
│   ├── Libraries/                 # ~96 services & helpers (PayrollService, AuditLogger, ...)
│   ├── Models/                    # Models + BaseModel (multi-tenant + encryption casts)
│   ├── Database/Migrations/       # Numbered migrations (~75)
│   ├── Database/Seeds/            # InitialSeeder + demo seeders
│   ├── Commands/                  # Spark commands: cron, audit, cleanup (~24)
│   ├── Filters/                   # JWT, Permission, RateLimit, Idempotency, ...
│   ├── Constants/                 # ApprovalStatus, AttendanceStatus, ErrorCode, ...
│   ├── Notifications/             # In-app + email + push templates
│   ├── Traits/                    # Reusable controller/model traits
│   ├── Validation/                # Custom rules (NIK, NPWP, magic-byte)
│   ├── Views/                     # PDF/email templates (dompdf + PHPMailer)
│   └── Config/                    # CI4 config files
├── tests/Unit/                    # ~53 files, ~761 test methods
├── env-templates/                 # local/staging/production .env templates
├── public/                        # index.php (web entry)
├── writable/                      # cache, logs, uploads, sqlite (gitignored)
├── composer.json · phpunit.xml.dist · phpstan.neon.dist · pint.json
└── Dockerfile · Dockerfile.dev
```

**Layering rule (kode baru):**

```
Controller → Service (Libraries/) → Model (BaseModel::scoped) → DB
            ↘ AuditLogger (mandatory di mutation)
            ↘ NotificationService (in-app/email/push)
```

Controller tipis (parsing request + auth + response shaping). Business logic di Service (`Libraries/`). DB lewat Model. Audit + notif lewat service-level call, bukan di controller langsung kalau bisa.

## Frontend (`frontend/`)

```
frontend/
├── src/
│   ├── api/                       # axios client + endpoint modules
│   ├── components/                # Reusable UI (SimpleMaster, FormField, ...)
│   ├── composables/               # useConfirm, useDarkMode, useApi, ...
│   ├── layouts/                   # AppLayout, AuthLayout
│   ├── locales/                   # i18n (id, en)
│   ├── router/                    # Vue Router + permission guard
│   ├── stores/                    # Pinia (auth, ui)
│   ├── views/                     # ~141 view components (per modul)
│   ├── assets/                    # static images / icons
│   ├── App.vue · main.js
├── public/                        # static + service worker root
├── env-templates/                 # local/staging/production
├── vite.config.js · vitest.config.js · tailwind.config.js
├── .eslintrc.cjs · .prettierrc.json
├── nginx.conf                     # SPA fallback + gzip + cache
└── Dockerfile · Dockerfile.dev
```

**Convention**: View per route di `views/`. Reusable building blocks di `components/`. Logic non-UI (data fetching, derived state) di `composables/`. Strings lewat `useI18n()` (`@/locales`), bukan hardcoded.

## Mobile (`mobile/`)

```
mobile/
├── src/
│   ├── api/                       # axios singleton + interceptors (refresh, idempotency, X-Company-Id)
│   ├── components/                # Shared UI primitives
│   ├── features/                  # ~16 feature modules (auth, attendance, leave, approval, payslip, profile, devices, overtime, reimbursement, business-trip, helpdesk, notifications, performance, training, tasks, settings)
│   ├── i18n/                      # ID/EN
│   ├── lib/                       # MMKV outbox, Keychain wrapper, native helpers
│   ├── store/                     # Zustand (auth)
│   ├── theme/                     # Light/dark via useColorScheme()
│   ├── config.ts · MainTabs.tsx · MoreMenu.tsx
├── android/ · ios/                # Native projects
├── native-config/                 # Manifest + Info.plist additions (FCM, biometric, location)
├── scripts/init-native.sh
├── tsconfig.json (strict) · .eslintrc.js · .prettierrc.js
├── jest.setup.js · babel.config.js · metro.config.js
├── package.json · App.tsx · index.js
```

**Convention per feature module** (`src/features/<name>/`): screens, hooks, types, API binding co-located. Path aliases via babel-plugin-module-resolver: `@/`, `@api/`, `@features/`, `@components/`, `@theme/`, `@store/`.

## Docs (`docs/`)

- `openapi.yaml` — single source of truth API contract (live spec juga di `GET /api/v1/openapi.json`)
- `ARCHITECTURE.md`, `DEPLOYMENT.md`, `DOCKER.md`, `ENVIRONMENTS.md` — overview + ops
- `AUDIT.md`, `DATA_PRIVACY.md`, `BACKUP_DR.md` — compliance & reliability
- `CRON_JOBS.md` — daftar scheduled task (`spark` commands)
- `MOBILE_APP_PLAN.md`, `PWA.md` — mobile + offline strategy
- `decisions/NNNN-<slug>.md` — Architecture Decision Records (ADR)
- `SPRINT_*.md` — sprint logs & progress
- Per-modul docs: `PAYROLL_*`, `ATTENDANCE_*`, `LEAVE_*`, `RECRUITMENT_*`, `BONUS_ENGINE.md`, dst.

ADR wajib untuk perubahan arsitektural lintas modul atau penetapan pattern baru. Format: Konteks → Keputusan → Konsekuensi → Acceptance test.

## Naming & File Conventions

- **Branches**: `feat|fix|chore|refactor|docs|test/<scope>-<deskripsi>` (kebab-case).
- **Commits**: `<type>(<scope>): <ringkas>` (Conventional-style, imperative ≤ 72 char).
- **Backend tests**: `tests/Unit/<Modul>Test.php`, method `it_does_something()` atau `testDoesSomething()`.
- **Frontend tests**: `__tests__/` co-located atau `tests/` di root, `*.spec.js` / `*.test.js`.
- **Mobile tests**: `__tests__/` co-located, gunakan `@testing-library/react-native`.
- **Migrations**: numbered prefix CI4 (`YYYY-MM-DD-HHMMSS_*.php`), satu tabel/perubahan per file.
- **Spec files (`.kiro/specs/<feature>/`)**: `requirements.md`, `design.md`, `tasks.md`, `bugfix.md` — kebab-case feature name.
- **Env files**: jangan commit `.env` atau `production.env`. Hanya `.env.example` dan `*.env.example` di env-templates yang ke-commit.

## What Goes Where (cheat sheet)

| Mau ubah ini …                              | Edit di sini                                                |
|---------------------------------------------|-------------------------------------------------------------|
| Endpoint REST baru                          | `backend/app/Controllers/Api/V1/` + Service di `Libraries/` |
| Tabel baru / kolom baru                     | `backend/app/Database/Migrations/` (+ Model update)         |
| Business rule payroll/leave/attendance      | `backend/app/Libraries/<Service>.php` (bukan Controller)    |
| Cron / scheduled job                        | `backend/app/Commands/` + daftar di `docs/CRON_JOBS.md`     |
| Halaman web baru                            | `frontend/src/views/` + route di `frontend/src/router/`     |
| Komponen reusable                           | `frontend/src/components/` + composable kalau perlu logic   |
| Screen mobile baru                          | `mobile/src/features/<name>/` (co-located screen+hook+type) |
| API contract                                | `docs/openapi.yaml` (+ live di `GET /api/v1/openapi.json`)  |
| Keputusan arsitektural                      | `docs/decisions/NNNN-<slug>.md` (ADR)                       |
| Spec workflow (requirements/design/tasks)   | `.kiro/specs/<feature-name>/`                               |
