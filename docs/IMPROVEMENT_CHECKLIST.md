# HRIS — Checklist Improvement (Audit Menyeluruh)

> Hasil audit struktur backend (`backend/app/Controllers`, `Libraries`,
> `Migrations`, `Filters`), frontend (`frontend/src/views`, `router`,
> `stores`, `api`), dan dokumentasi (`docs/*`).
>
> **Tanggal audit**: 2026-05-22  
> **Branch**: `main` @ `0631230` (origin: `Quantara-HRIS`)
>
> Penilaian: HRIS sudah **sangat matang** untuk satu codebase
> (65+ controller, 35+ migrasi, 30+ library, 90+ view). Mayoritas modul
> wajib Kemenaker sudah implement (lihat `docs/ROADMAP.md`). Checklist
> berikut adalah peluang penajaman, bukan blocker — diurutkan dari
> prioritas tinggi (P0) → polish (P3).

---

## 🔴 P0 — Wajib Sebelum Production / Audit

### 1. Testing & Quality Assurance
> **Sprint 1-2 selesai** ✅ — foundation testing & CI sudah aktif. Lihat ringkasan di akhir checklist.

- [x] Hanya 2 unit test (`IndonesianValidatorTest`, `PrivacyScopeTest`) di `backend/tests/Unit/`. Tambah unit test untuk:
  - [x] `Pph21TerCalculator::categoryFromPtkp` — 16 case mapping PTKP→TER (test branch DB-dependent ditunda ke integration test)
  - [ ] `Pph21TerCalculator::calculateMonthlyTer` & `calculateYearlyProgressive` — butuh DB stub, schedule untuk integration test
  - [ ] `BpjsCalculator` — butuh DB stub (rates table), schedule untuk integration test
  - [x] `OvertimeCalculator` — 20 test, weekday/weekend 5-day/weekend 6-day/holiday × semua tier
  - [x] `LeaveBalanceService::computeProratedQuota` — 16 test (data-driven 9 case mid-year + edge cases)
  - [x] `SeveranceCalculator` — 30 test (multiplier per 17 alasan, tabel pesangonMonths/upmkMonths, edge cases)
- [ ] Migrate 30+ smoke test di `backend/scripts/test-*.php` jadi PHPUnit Feature tests (`backend/tests/Feature/`) supaya bisa di-CI:
  - [ ] AttendanceFlow, LeaveFlow, OvertimeFlow, PayrollExport, RecruitmentFlow, dll
- [x] Frontend belum ada test sama sekali. Setup minimal:
  - [x] Vitest + `@vue/test-utils` (devDeps + `vitest.config.js` + sample test) — `npm run test` jalan
  - [ ] Cypress / Playwright untuk smoke E2E (login → clock-in → ajukan cuti → approve → lihat saldo)
- [ ] Code coverage target: backend ≥ 60%, frontend ≥ 40%
- [x] Static analysis: integrate **PHPStan** (level 0 baseline, 0 errors) + **ESLint** (vue3-essential, 0 errors). Naik ke level 6 + Prettier sesuai task refactor.
- [x] CI pipeline (`.github/workflows/ci.yml`):
  - [x] PR: backend `composer install` → `composer stan` → `composer test` (148 tests); frontend `npm ci` → `npm run lint` → `npm test` → `npm run build`
  - [x] Cache composer & npm dependencies
  - [x] Matrix PHP 8.1/8.2/8.3 + Node 18/20
  - [x] Block merge kalau test fail (default GitHub Actions behavior; protect branch via Settings → Branches → Require status checks)

### 2. Security Hardening
> **Sprint 3 selesai** ✅ — password policy, 2FA backup codes, file magic-byte validation, dan rate limit hardening sudah aktif. Lihat ringkasan Sprint 3 di akhir checklist.

- [x] **Password policy** masih lemah (10 karakter alfanumerik). Tambah:
  - [x] Wajib minimal 1 uppercase + 1 lowercase + 1 digit + 1 simbol → `App\Libraries\PasswordPolicy::validate()`
  - [ ] Cek breach via Have I Been Pwned API (k-anonymity) — method `breachCheck()` siap, belum di-wire (network call opsional)
  - [x] Forced rotation 90/180 hari opsional per company setting — method `mustRotate()` siap, controller belum enforce
  - [x] Riwayat 5 password terakhir tidak boleh dipakai ulang → table `password_history`, `isReused()` + `recordUsed()` di-wire ke change/reset flow
- [x] **2FA** hanya TOTP. Tambah:
  - [x] Backup recovery codes (8x10-digit, sekali pakai, di-hash) → `App\Libraries\TwoFactorBackupCodes` + table `twofa_backup_codes`
  - [x] Verify-2FA endpoint accept `backup_code` selain TOTP `code`
  - [ ] Email-based 2FA fallback untuk user tanpa authenticator app — defer Sprint 4+
  - [ ] Force 2FA untuk role super_admin & HR admin — defer Sprint 4+
- [ ] **Refresh token** tidak track device — saat user logout dari satu device, semua di-revoke. Tambah:
  - [ ] Kolom `device_fingerprint`, `last_used_at` di `refresh_tokens`
  - [ ] UI "Active Sessions" di Profile + tombol "Sign out other devices"
- [x] **File upload validation** hanya cek MIME via PHP `getMimeType()` (mudah di-spoof). Tambah:
  - [x] Cek magic bytes (file signature) untuk JPEG/PNG/PDF → `App\Libraries\FileSignatureValidator`, di-wire ke `uploadAvatar`
  - [ ] Strip EXIF GPS data dari foto (privacy concern untuk avatar/selfie) — defer Sprint 4+
  - [ ] Anti-virus scan (ClamAV) untuk attachment dokumen — defer Sprint 5+
- [ ] **CORS allowlist** di `Config/Cors.php` — pastikan tidak ada wildcard `*` di production (review manual)
- [ ] **Encryption key rotation** — `RotateEncryption` command ada tapi tidak ada SOP/jadwal. Dokumentasikan:
  - [ ] Jadwal rotation tahunan
  - [ ] Backup key lama supaya data lama bisa di-decrypt
  - [ ] Test restore dari backup setelah rotation
- [ ] **Audit log** untuk akses lintas-tenant — saat super_admin switch company, log severity NOTICE
- [x] **Rate limit** per endpoint sensitif: `/auth/login` ada (throttle:login). Tambah:
  - [x] `/auth/forgot-password` ketat (3/15 menit) → `throttle:forgot_password` bucket
  - [x] `/auth/verify-2fa` (5/15 menit) → `throttle:twofa` bucket
  - [x] `/auth/reset-password` (5/jam) → `throttle:password_reset` bucket
  - [x] Endpoint export berat (mis. payroll bank/pph21/bpjs, audit-logs/export) — `throttle:export` 10/jam
- [ ] **IP allowlist** opsional untuk role super_admin & HR (setting per company) — defer Sprint 4+
- [ ] **Honeypot** di Login form (CodeIgniter `Honeypot.php` config ada, pastikan aktif) — review manual

### 3. Compliance Indonesia (Data & Pajak)
> **Sprint 4 selesai** ✅ — fondasi UU PDP, JKP/BPJS-Kelas, dan THR proporsional sudah aktif. Lihat ringkasan Sprint 4 di akhir checklist.

- [x] **UU PDP (UU 27/2022)** — fondasi compliance siap:
  - [x] Endpoint `POST /api/v1/me/privacy/consents` + `GET /me/privacy/consents` (record + history)
  - [x] Endpoint `GET /api/v1/me/data-export` — karyawan download seluruh data pribadi (JSON, throttled 10/jam)
  - [x] Endpoint `POST /api/v1/me/data-deletion-request` → workflow review HR
  - [x] Anonimisasi PII via `DataPrivacyService::processApproved()` (PII overwrite, record finansial dipertahankan untuk retensi pajak 5 thn)
  - [ ] Frontend banner "kebijakan diperbarui, mohon persetujuan" — defer Sprint 5+
  - [ ] Cron `php spark hr:purge-resigned --years=5` otomatis — defer Sprint 5+ (saat ini manual via admin endpoint)
- [ ] **PPh21 PMK 168/2023 TER** — sudah implement, tapi:
  - [ ] Belum ada export **bukti potong 1721-A1 final tahunan** dengan format yang sudah validated DJP 2026
  - [ ] Belum ada handling karyawan **mid-year join/resign** untuk koreksi Desember (saat ini asumsi penuh 12 bulan)
  - [ ] **Tarif TER tahun 2027+** — perlu admin UI untuk update bracket tanpa migrasi (saat ini di tabel `ter_rates` → bagus, tapi belum ada UI CRUD)
- [x] **BPJS Ketenagakerjaan JKP** (Jaminan Kehilangan Pekerjaan, PP 37/2021) — ditambahkan ke `BpjsCalculator`:
  - [x] Tambah field `jkp_company_rate` 0.46% + plafon 5 juta di `bpjs_rates`
  - [x] `BpjsCalculator::compute()` hitung JKP dengan eligibility check PKWT/PKWTT (skip MAGANG/HARIAN/OUTSOURCING)
  - [ ] Update kolom payslip & export SiPP — defer Sprint 5+ (calculator siap, payroll service belum diintegrasi)
- [x] **BPJS Kesehatan kelas** — kolom `employees.bpjs_kes_class` ditambah (default 1)
  - [ ] Plafon iuran berdasarkan kelas — defer Sprint 5+ (perlu rate per kelas terpisah)
- [ ] **UMP/UMK** — seeder 2026 ada tapi:
  - [ ] Tidak auto-update setiap tahun → perlu cron `hr:fetch-ump-yearly` atau import CSV admin UI
  - [ ] Warning di-log tapi tidak ada notifikasi proaktif ke HR di Dashboard — defer Sprint 5+
- [ ] **E-Faktur Coretax** — integration belum ada (kalau perusahaan claim PPN supplier) — defer Sprint 6+

---

## 🟠 P1 — Major Improvement (3–6 Bulan ke Depan)

### 4. Payroll Engine
> **Sprint 5-6 selesai** ✅ — magic numbers ke setting per-company, JKP wiring placeholder, dan THR proporsional sudah live. Lihat ringkasan Sprint 5-6 di akhir checklist.

- [ ] `PayrollService::calculatePeriod` punya **defensive cleanup** orphan payslip via `slip_number` prefix LIKE — ini patch, bukan fix root cause. Tambah `ON DELETE CASCADE` di FK `payslips.payroll_period_id` & `payslip_details.payslip_id` (lihat migrasi MySQL nanti)
- [x] **Magic numbers** hardcoded di payroll → semua ke `App\Libraries\PayrollSettings`:
  - [x] `22` hari kerja (untuk absent deduction) → setting `payroll.working_days_per_month`
  - [x] `173` jam (untuk overtime hourly rate) → setting `payroll.overtime_hours_per_month`, dipakai via parameter baru `OvertimeCalculator::calculate(..., $hoursPerMonth)`
  - [x] `8` jam/hari (untuk overtime → leave conversion) → setting `payroll.overtime_to_leave_hours_per_day`
  - [x] Bonus: `payroll.absent_deduction_enabled` flag untuk perusahaan yang tidak pakai potongan absen
- [x] **THR generator** (`PayrollController::generateThr`) — Sprint 4 fix:
  - [x] `tenureMonths` dihitung sampai `pay_date` (tidak pakai `time()`) supaya re-generate idempotent
  - [x] Karyawan resign mid-year tetap include dengan tenure proporsional sampai `min(resign_date, pay_date)`
  - [x] Karyawan resign sebelum awal periode (default Jan 1) di-exclude
  - [x] Logic dipisah ke 2 method static `computeTenureMonths()` & `computeThrAmount()` (testable, 20 unit test)
  - [ ] Tidak ada handling karyawan dengan **multiple kontrak** di tahun yang sama — defer Sprint 5+
  - [ ] Tidak ada **THR Lebaran ke-2** kalau karyawan ganti agama / dual religion — defer Sprint 6+
- [ ] **Bonus engine** belum ada — tahun-end bonus, KPI bonus, milestone bonus. Saat ini harus manual via PayrollComponent
- [ ] **Payslip PDF**:
  - [ ] Tidak ada **digital signature/hash** untuk integrity. Tambah field `signature_hash` (SHA256 dari content) supaya audit bisa verifikasi tidak ada tampering setelah finalize
  - [ ] Tidak ada **bulk send email** payslip ke semua karyawan setelah finalize (saat ini hanya in-app notif)
- [ ] **Pre-tax vs post-tax allowance** breakdown di breakdown JSON belum konsisten — sulit untuk reporting

### 5. Attendance & Anti-Cheat
> **Sprint 5-6 selesai** ✅ — cross-day shift dan selfie retention cron sudah aktif.

- [x] **Cross-day shift / overnight shift** sudah di-handle benar di `AttendanceController::clockIn` & `clockOut`:
  - [x] Late minutes calc pakai `App\Libraries\ShiftTimeHelper::lateMinutes()` yang aware shift cross-midnight (mis. 22:00→06:00)
  - [x] `effectiveWorkDate()` resolve tanggal mulai shift, bukan tanggal clock-in (overnight: jam 01:00 → workDate kemarin)
  - [x] `clockOut` cari row aktif di today + yesterday untuk handle pulang keesokan paginya
  - [x] Auto-detect kalau shift kemarin masih open dan re-resolve ShiftResolver untuk yesterday
- [ ] **Break time tracking** belum ada — tidak bisa lapor istirahat siang, sholat, makan
- [ ] **Multi clock-in/out per hari** belum support — kalau karyawan keluar siang lalu kembali, tidak ada slot untuk track
- [x] **Selfie storage** — sudah ada `App\Commands\CleanupAttachments`:
  - [x] Cron `php spark hr:cleanup-attachments` siap pakai (skip yang anomaly_score ≥ 30 untuk audit jangka panjang)
  - [x] Setting `selfie_retention_days` per company (default 90, di migration #025)
  - [ ] Strip EXIF GPS data sebelum simpan — defer Sprint 7+ (perlu library `lfprojects/php-exif-cleaner` atau GD-only)
- [ ] **Anti-cheat**:
  - [ ] Native Capacitor plugin untuk Android mock GPS deferred (lihat ROADMAP item 14)
  - [ ] Tidak ada **liveness detection** (gerak kepala, kedip mata) — selfie statis bisa ditipu pakai foto
  - [ ] Tidak ada **face match** ke foto profil karyawan
  - [ ] Geofence single-point per branch — tidak support **polygon** untuk lokasi besar (mall, area pabrik)
  - [ ] `device_fingerprint` server-side fallback hanya UA + Accept-Language → mudah collisi. Pakai library FingerprintJS di client
- [ ] **Offline-first attendance**: PWA service worker queue mutations ada, tapi clock-in offline belum di-test end-to-end (timestamp drift, anti-cheat data integrity)

### 6. Leave Management
- [ ] **Long sick leave detection** — UU 13/2003 Pasal 153 atur sakit berkepanjangan punya prosedur khusus (bayar 100% bulan 1-4, 75% bulan 5-8, dst). Belum di-handle:
  - [ ] Auto-flag kalau cuti SICK > 14 hari berturut-turut
  - [ ] Pemotongan gaji proporsional sesuai aturan
- [ ] **Cuti besar** (PKWT 6 tahun → 1 bulan cuti besar di tahun ke-7) — leave_type ada tapi tidak ada auto-grant
- [ ] **Quota leave berbasis masa kerja** (mis. tenure 5 tahun = +2 hari, 10 tahun = +5 hari) belum support
- [ ] **Leave forfeiture cron** ada (`hr:leave-yearend`), tapi tidak ada **email reminder otomatis** ke karyawan H-30 sebelum saldo expire
- [ ] **Self-cancel** setelah approve oleh karyawan belum ada — saat ini hanya bisa dibatalkan oleh HR
- [ ] **Custom approval per leave_type** belum support — saat ini semua pakai chain yang sama. Mis. cuti melahirkan harusnya langsung HR (skip manager)
- [ ] **Half-day leave** sudah ada (MORNING/AFTERNOON), tapi attendance tidak otomatis split jadi setengah hadir + setengah cuti

### 7. Approval Engine & Workflow
- [ ] **Bulk delegation transfer** saat user resign — saat ini delegasi manual. Tambah:
  - [ ] Saat resign approved, otomatis offer redelegation untuk semua approval pending milik user
- [ ] **Escalation timeout** — kalau approver tidak respond dalam X jam, escalate ke level atas. Belum ada
- [ ] **Conditional routing** — mis. cuti > 7 hari harus approve sampai dept head, < 7 hari cukup supervisor. Saat ini chain fixed
- [ ] **Bulk approve** untuk HR — saat ini approve satu-satu di Inbox. Tambah action "Approve Selected"
- [ ] **Mobile-friendly approval** — Inbox harus optimized untuk PWA mobile (saat ini desktop-first)

### 8. Notifications
> **Sprint 7 selesai** ✅ — Web Push subscription, email template per company,
> dan email queue exponential backoff sudah aktif. Lihat ringkasan Sprint 7 di
> akhir checklist.

- [ ] **WhatsApp channel** masih scaffolding. Selesaikan:
  - [ ] Pilih provider (Wablas/Fonnte/Twilio) → tambah config di settings
  - [ ] Template message dengan variable substitution
  - [ ] Opt-in/opt-out per user (compliance UU PDP)
- [x] **Web Push** end-to-end (foundation di SW ada):
  - [x] Endpoint `GET /api/v1/notifications/push-public-key` (return VAPID public key dari env)
  - [x] Endpoint `POST /api/v1/notifications/push-subscribe` untuk simpan subscription (idempotent UPSERT)
  - [x] Endpoint `DELETE /api/v1/notifications/push-subscribe` untuk unsubscribe
  - [x] Tabel `web_push_subscriptions` (per user/device, multi-tenant scoped)
  - [x] `WebPushChannel` dispatch via `minishlink/web-push` saat library tersedia (graceful skip kalau belum di-install)
  - [ ] Generate VAPID keys via `php spark webpush:generate-keys` — defer Sprint 8+ (manual via OpenSSL OK)
  - [ ] Test push real ke device — defer (perlu device testing)
- [ ] **Real-time in-app** — tidak ada WebSocket/SSE. Saat ini polling (cek `Notifications.vue` & sidebar badge). Tambah:
  - [ ] SSE endpoint `GET /api/v1/notifications/stream` untuk real-time push
  - [ ] Atau Pusher/Ably integration untuk skala
- [ ] **Digest email** — tidak ada email harian/mingguan ringkasan. Tambah:
  - [ ] Cron `php spark notifications:digest --frequency=daily|weekly` per user preference
- [x] **Email template per company** — `NotificationService::renderEmailHtml` masih ada untuk fallback, tapi:
  - [x] Tabel `email_templates` per company × event_type (key `{module}.{action}`, dengan flag `is_active` + `version`)
  - [x] Variable substitution `{{employee_name}}`, `{{leave_dates}}`, dst via `App\Libraries\EmailTemplateRenderer`
  - [x] Anti-XSS escape default; `{{{var}}}` raw mode untuk HTML pre-rendered
  - [ ] Editor di Admin → Email Templates — defer Sprint 8+ (CRUD frontend, schema sudah siap)
- [x] **Email queue retry** — exponential backoff dengan jitter via `App\Libraries\EmailQueueBackoff`:
  - [x] 1m → 5m → 30m → 2h → 6h (max attempts 5)
  - [x] Field `next_retry_at` & `last_attempt_at` di `email_queue` (skip row yang next_retry masih future)
  - [x] Setelah max attempts → status FAILED final, tidak retry lagi

### 9. Reports & Analytics
- [ ] Reports yang ada terlalu basic. Tambah:
  - [ ] **Turnover rate** (attrition) per departemen / branch / quarter
  - [ ] **Cost per hire** (dari Recruitment funnel)
  - [ ] **Average tenure** + distribusi (boxplot)
  - [ ] **Diversity** — gender ratio, age bucket, education level
  - [ ] **Compensation analysis** — band utilization, midpoint analysis, gender pay gap
  - [ ] **Forecast** manpower (dari MPP) vs aktual hire
  - [ ] **Absenteeism rate** per dept × bulan
  - [ ] **Training ROI** (cost vs completion vs performance improvement)
- [ ] **Custom report builder** — admin bisa pilih kolom + filter tanpa coding (drag & drop)
- [ ] **Excel native export** — saat ini hanya CSV. Library `phpoffice/phpspreadsheet` sudah di composer.json tapi belum dipakai untuk reports
- [ ] **Dashboard charts** minimal (`chart.js` ada di frontend). Tambah:
  - [ ] Trend chart (line) headcount 12 bulan terakhir
  - [ ] Pie chart distribusi cuti per kategori
  - [ ] Heatmap absensi (sudah ada di backend, frontend integrasi sebagian)
- [ ] **Scheduled reports** — admin bisa setup laporan auto-email mingguan/bulanan

### 10. Multi-tenant & Branch Isolation
- [ ] `TenantContext::getBranchId()` fallback ke `0` saat user tidak ter-link ke employee. Implikasi:
  - [ ] User HR pusat dengan permission `attendance.view_all_branches` OK
  - [ ] User admin tanpa employee → `branchId=0` → `applyAttendanceScope` mungkin tidak filter (cek!)
- [ ] **Branch-level approval chain** — chain saat ini per company; tidak bisa custom per branch
- [ ] **Cross-company report** untuk super_admin — UI belum ada (data ada)
- [ ] **Tenant onboarding wizard** — saat ini bikin company manual + seed master data. Tambah:
  - [ ] Wizard step-by-step: company info → branches → departments → positions → leave types → payroll components
  - [ ] Template "perusahaan baru" siap pakai (clone dari company referensi)

---

## 🟡 P2 — Polish & Modern Features

### 11. Dokumentasi
> **Sprint 9 selesai** ✅ — README polish, OpenAPI spec, setup scripts. Lihat ringkasan Sprint 9 di akhir checklist.

- [x] **README.md** terlalu minim — point ke docs/ tapi tidak ada quick-summary fitur. Tambah:
  - [ ] Screenshot 5 halaman utama (Dashboard, Attendance, Leave, Payroll, Org Structure) — defer (perlu deploy ke staging)
  - [ ] Demo URL / video walkthrough — defer (perlu staging environment)
  - [x] Tech stack badge (CI, PHP, CodeIgniter, Vue, Vite, Tailwind, Tests count)
  - [x] Quick start 3 cara: setup script / manual / Docker
  - [x] Folder structure tree dengan annotation
  - [x] Daftar 30+ modul utama
- [x] **API documentation** belum ada. Tambah:
  - [x] OpenAPI 3.0 spec di `docs/openapi.yaml` (foundation: auth, employees, attendance, leave, privacy, notifications + schemas + envelopes + parameters)
  - [ ] Swagger UI hosted di `/api/docs` (gated di production) — defer Sprint 10+
  - [ ] Postman collection di `docs/HRIS.postman_collection.json` — defer Sprint 10+ (auto-generate dari OpenAPI)
- [x] **Architecture Decision Records** (ADR) — `docs/adr/`:
  - [x] [0001](adr/0001-codeigniter-vs-laravel.md) Backend Framework: CodeIgniter 4 vs Laravel/Symfony/Slim
  - [x] [0002](adr/0002-jwt-vs-session-auth.md) Authentication: JWT + refresh rotation vs session/OAuth
  - [x] [0003](adr/0003-sqlite-dev-mysql-prod.md) Database: SQLite dev / MySQL prod dual strategy
  - [x] [0004](adr/0004-additive-only-migrations.md) Migration: additive-only pattern (no rename/drop)
- [ ] **Changelog** otomatis dari commit message (Conventional Commits + `git-cliff` atau semantic-release)
- [x] **Setup script** — `SETUP.md` manual step diganti otomatis:
  - [x] `scripts/setup-dev.ps1` (Windows / PowerShell) — cek deps + composer + .env + migrate + seed + npm. Idempotent. Support `-Reset` flag.
  - [x] `scripts/setup-dev.sh` (Linux/macOS/WSL) — paralel implementation dengan `--reset` & `--skip-migrate` flags.

### 12. DevOps & CI/CD
> **Sprint 9 selesai** ✅ — Docker stack lengkap, Dependabot aktif. Lihat ringkasan Sprint 9.

- [x] **Docker Compose** — `docker-compose.yml` di root dengan stack lengkap:
  - [x] Service `backend` (PHP 8.2 + spark serve, dev)
  - [x] Service `frontend` (Vite dev server dengan hot reload)
  - [x] Service `mysql` (MySQL 8.0 dengan healthcheck)
  - [x] Service `redis` (Redis 7 alpine)
  - [x] Service `mailhog` (dev email catcher, UI di :8025)
- [x] **Dockerfile production** — multi-stage build untuk backend + frontend:
  - [x] `backend/Dockerfile` — composer stage → php-fpm-alpine + opcache + extensions, drop privilege ke www-data
  - [x] `frontend/Dockerfile` — vite build stage → nginx:alpine dengan SPA fallback + gzip
  - [x] `.dockerignore` di kedua untuk slim build context
  - [x] `frontend/nginx.conf` — cache long untuk asset hashed, no-cache untuk SW
- [ ] **GitHub Actions**:
  - [x] `.github/workflows/ci.yml` — lint + test on PR (sudah ada Sprint 1-2)
  - [ ] `.github/workflows/deploy.yml` — deploy ke staging on push to `main` — defer Sprint 10+
  - [x] Dependabot config untuk auto-update dependencies (composer + npm + actions + docker, weekly)
- [ ] **Pre-commit hooks** (Husky + lint-staged):
  - [ ] PHP: `phpcs` / `php-cs-fixer` — defer Sprint 10+
  - [ ] Vue/JS: ESLint + Prettier — defer Sprint 10+
- [ ] **Release versioning** — `package.json` masih `1.0.0`, `composer.json` tidak ada version. Tambah semantic-release config

### 13. Database
> **Sprint 10+ partial** ✅ — composite indexes untuk query pattern utama. Lihat ringkasan Sprint 10+.

- [x] **Index review systematic** — composite indexes via migration #040:
  - [x] `attendances(company_id, work_date, employee_id)` — paling sering di-query (calendar, payroll, reports)
  - [x] `leaves(company_id, employee_id, status, start_date)` — list filter + balance summary
  - [x] `overtimes(company_id, employee_id, status, work_date)` — pattern sama dengan leaves
  - [x] `audit_logs(company_id, created_at)` — paginate by recency per tenant
  - [x] `notifications(user_id, is_read, created_at)` — unread filter + recency
  - [ ] Audit slow query log di MySQL prod — defer (perlu access ke staging/prod env)
- [ ] **Audit log table** akan membengkak. Tambah:
  - [ ] Partition by month (MySQL 8)
  - [ ] Retention command `php spark audit:archive --older-than=12months` (sudah ada `ArchiveAuditLogs`?)
  - [ ] Move ke cold storage (S3) setelah 1 tahun
- [ ] **Backup strategy** di `docs/DEPLOYMENT.md` hanya `mysqldump` cron. Tambah:
  - [ ] **Point-in-time recovery** via binary log
  - [ ] Backup test rutin (restore drill bulanan)
  - [ ] Off-site backup (S3 / Backblaze B2)
- [ ] **Read replica** untuk reporting queries — saat ini semua hit master DB
- [ ] **Connection pooling** (ProxySQL atau application-level) untuk skala

### 14. Internationalization & Accessibility
- [ ] **i18n** — folder `frontend/src/locales/` ada (`en.js`, `id.js`) tapi belum dipakai luas:
  - [ ] Audit semua view, ekstrak hardcoded string ke `t('key')`
  - [ ] Tambah locale switcher di Profile + simpan ke `users.locale`
  - [ ] Backend: validation message juga bilingual (saat ini campur ID/EN)
- [ ] **Accessibility (a11y)** belum di-audit:
  - [ ] Lighthouse audit per halaman utama (target score 90+)
  - [ ] Keyboard navigation (Tab order, Skip link)
  - [ ] ARIA label di icon-only button
  - [ ] Color contrast (WCAG AA minimum 4.5:1 untuk text normal)
  - [ ] Focus indicator yang visible
  - [ ] Screen reader test (NVDA / VoiceOver)
- [ ] **Mobile responsive** — perlu review semua view di breakpoint sm/md/lg

### 15. UX & UI Polish
- [ ] **Dark mode** — `useDarkMode.js` composable ada, tapi belum diaktifkan di toolbar. Roll-out ke semua view
- [ ] **Loading states** inconsistent — kadang skeleton, kadang spinner, kadang nothing. Standardkan:
  - [ ] Skeleton loading untuk list
  - [ ] Spinner untuk action button
  - [ ] Progress bar untuk upload
- [ ] **Error handling** inconsistent — kadang toast, kadang inline, kadang alert(). Standardkan via composable `useErrorHandler.js`
- [ ] **Empty states** — banyak list kosong tampil "Tidak ada data" plain. Tambah ilustrasi + CTA
- [ ] **Search & filter** di list view kadang debounce, kadang submit-on-enter. Standardkan debounce 350ms
- [ ] **Bulk actions** di list — checkbox + action bar untuk approve/reject/delete multiple
- [ ] **Export tombol** dengan format pilihan (CSV/Excel/PDF) konsisten di semua list
- [ ] **Keyboard shortcut** (cmd+K untuk command palette, Esc untuk close modal)
- [ ] **Toast positioning & timing** — review `vue-toastification` config (saat ini default)

### 16. Performance Engineering
> **Sprint 10+ partial** ✅ — CacheService library + key convention. Lihat ringkasan Sprint 10+.

- [ ] **N+1 queries** — beberapa controller iterate employees lalu query nested. Audit dengan query log:
  - [ ] `PayrollService::calculatePeriod` (sudah pakai eager via join, tapi `recurring`, `attRows`, `otRows` per employee — bisa di-batch)
  - [ ] `LeaveBalanceService::summary` per employee
- [x] **Cache layer** — `App\Libraries\CacheService` foundation siap, key builders untuk lookup yang sering:
  - [x] `keyRolePermissions(role_id)` — defer wiring ke RoleController setelah role/permission UI stabil
  - [x] `keyUmp(province_id, year)` + `keyUmk(city_id, year)` — TTL 1 hari, invalidate on admin update
  - [x] `keyHolidays(company_id, year)` — invalidate on holiday upload
  - [x] `keyTerRates`, `keyBpjsRates`, `keyPayrollSettings` — TTL hour atau hari (data jarang berubah)
  - [x] Read-through `remember()` helper dengan NULL-sentinel + falsy-skip option
  - [x] Bulk invalidate via `forgetPrefix('ump:')` (deleteMatching wildcard)
  - [ ] Wire ke caller (`UmpModel::find`, `Pph21TerCalculator`, dll) — defer Sprint 11+ (foundation siap, tinggal apply)
- [ ] **Pagination default** — beberapa endpoint default `per_page=30`, ada yang `=20`. Standardkan + max limit
- [ ] **Frontend bundle size** — `npm run build` output belum di-audit:
  - [ ] Lazy-load chart.js (hanya di Dashboard & Reports)
  - [ ] Code-splitting per route (sudah pakai dynamic import, OK)
  - [ ] Tree-shake heroicons (pakai per-icon import, sudah OK)
- [ ] **Image optimization** — avatar/selfie tidak di-resize server-side. Tambah:
  - [ ] Resize ke max 800x800 saat upload (pakai GD)
  - [ ] WebP conversion opsional

---

## 🟢 P3 — Feature Expansion (Fase Berikutnya)

### 17. Modul Baru yang Belum Ada
- [ ] **Shift Swap** — request tukar shift antar karyawan (deferred di ROADMAP item 1)
- [ ] **Wellness / EAP** — tracking konseling, mental health resources
- [ ] **Survey / Engagement / Pulse** — bikin survey internal, distribute, analyze
- [ ] **Knowledge Base / SOP** — repository dokumen perusahaan
- [ ] **Successor Planning / Talent Pool** — flag karyawan high-potential, plan successor untuk posisi kritis
- [ ] **Skills Inventory & Matrix** — catat skill per karyawan, gap analysis untuk training
- [ ] **Compensation Review Cycle** — workflow tahunan untuk increment/promosi mass
- [ ] **Bonus Engine** — KPI-based, target-based dengan formula configurable
- [ ] **Stock Options / RSU** — vesting schedule, exercise tracking
- [ ] **Health Insurance Claim** — track claim rawat jalan/inap, integration ke asuransi swasta
- [ ] **Petty Cash** — kasbon harian dengan limit + reconciliation
- [ ] **Stock Opname Asset** — audit fisik aset rutin dengan barcode/QR scan
- [ ] **Salary Simulator** — apa-jika analysis untuk negotiate offer / promosi

### 18. Integration & API
> **Sprint 11+ partial** ✅ — webhook outbound infrastructure aktif. Lihat ringkasan Sprint 11+.

- [x] **Webhook outbound** — perusahaan kirim event HRIS ke sistem lain (mis. accounting):
  - [x] Tabel `webhooks` (url, event_types, encrypted secret) + `webhook_deliveries` (audit + retry state)
  - [x] Dispatcher async dengan retry exponential backoff (reuse EmailQueueBackoff)
  - [x] HMAC-SHA256 signing dengan timestamp (replay protection 5 menit window)
  - [x] Auto-disable setelah 10 consecutive failure
  - [x] Idempotency via UUID per delivery (X-HRIS-Delivery header)
  - [ ] Spark command `webhooks:process` untuk cron — defer Sprint 12+ (manual call `service('webhook')->processQueue()` OK untuk sekarang)
  - [ ] Wire dispatch ke event handler existing (LeaveController, EmployeeController, dll) — defer Sprint 12+ (foundation siap, tinggal apply)
- [ ] **Webhook inbound** — terima trigger dari sistem lain (mis. recruitment marketplace post job)
- [ ] **Accounting integration**:
  - [ ] Accurate (REST API)
  - [ ] Jurnal.id
  - [ ] Xero (international)
  - [ ] Export jurnal payroll ke format umum (CSV mapping)
- [ ] **HR marketplace integration**:
  - [ ] Talenta sync (employee data)
  - [ ] Mekari sync
  - [ ] Glints / Kalibrr / LinkedIn (job posting auto-publish)
- [ ] **Travel booking** — integration dengan Traveloka/Tiket Business untuk kontrol biaya SPPD
- [ ] **E-signature** — Privy/Mekari Sign untuk contract, SP, BAST, PHK
- [ ] **Coretax** (DJP) — sync bukti potong langsung
- [ ] **OAuth login** — Google Workspace, Microsoft 365 SSO untuk korporasi

### 19. Mobile Native
- [ ] **PWA polish** (sudah ada foundation):
  - [ ] Generate real icon files (192/512/maskable) dari design asset (deferred di ROADMAP)
  - [ ] Web Push end-to-end (lihat P1 item 8)
  - [ ] Periodic Background Sync untuk auto-refresh data
- [ ] **Native app** (Capacitor / Flutter):
  - [ ] Wrapper Capacitor dari Vue SPA → APK Android + IPA iOS
  - [ ] Plugin native: GPS akurat + mock detection (deferred di ROADMAP item 14)
  - [ ] Plugin native: Biometric auth (fingerprint/Face ID untuk clock-in)
  - [ ] Push notification native (FCM Android, APNs iOS)
  - [ ] Camera native (lebih cepat dari `<input type=file>`)

### 20. Code Quality & Refactoring
> **Sprint 10+ partial** ✅ — Constants class foundation. Lihat ringkasan Sprint 10+.

- [x] **Magic numbers / strings** — Constants class foundation siap:
  - [x] `App\Constants\AttendanceStatus` — PRESENT/LATE/ABSENT/LEAVE/HOLIDAY/SICK/HALF_DAY + helper isPresentLike()/label()
  - [x] `App\Constants\EmploymentStatus` — PKWT/PKWTT/PROBATION/HARIAN/MAGANG/OUTSOURCING + isJkpEligible()
  - [x] `App\Constants\ApprovalStatus` — DRAFT/PENDING/APPROVED/REJECTED/CANCELED/CONSENT_REQUIRED/IMPLEMENTED + isPending()/isFinal()
  - [x] `App\Constants\ErrorCode` — snake_case standard codes untuk API response
  - [ ] Migrasi controller existing pakai konstanta — defer Sprint 11+ (additive — string literal masih jalan, refactor bertahap)
- [ ] **DTO / Form Request** — saat ini validation langsung di controller. Pisah ke `App\Requests\*`:
  - [ ] `CreateEmployeeRequest`, `UpdateEmployeeRequest`, dll
  - [ ] Reusable validation + auto-doc untuk OpenAPI
- [ ] **Service layer extraction** — beberapa controller punya business logic kompleks (`OvertimeController::approve` ~130 line). Pindah ke service class
- [ ] **Long file refactor**:
  - [ ] `LeaveController.php` (819 baris) → split ke `LeaveValidator`, `LeavePolicyService`, `LeaveCalendarService`
  - [ ] `AttendanceController.php` (773 baris) → split CalendarBuilder
  - [ ] `EmployeeController.php` (621 baris) → split ContractController, DocumentController
- [ ] **Inconsistent error code naming** — kadang `INVALID_*`, `OUTSIDE_*`, `NO_*`, kadang Indonesian. Standardkan pakai snake_case English:
  - [ ] `validation_failed`, `permission_denied`, `resource_not_found`, dll
- [ ] **API response shape** konsisten — sudah ada `paginated()`, `ok()`, `fail()` helper di `ApiController`. Audit semua endpoint pakai helper ini
- [ ] **Defensive cleanup di PayrollService** seharusnya dihapus setelah FK CASCADE migration. Track di refactor task

### 21. Roadmap Items Deferred
Dari `docs/ROADMAP.md`:
- [ ] **#1 Shift & Roster** — modul `SHIFT_SWAP` deferred → buat di Phase 2
- [ ] **#1 Frontend** — `Attendance/ShiftSwapList.vue` deferred
- [ ] **#5 Disciplinary** — Badge SP aktif di profil karyawan deferred (endpoint `employee-summary` siap)
- [ ] **#7 Onboarding** — Section di `EmployeeModal.vue` untuk progress checklist deferred
- [ ] **#8 Asset** — Tab di profil karyawan deferred (endpoint `/asset-assignments/employee/(:num)` siap)
- [ ] **#11 Recruitment** — Document upload form deferred (schema siap, perlu endpoint POST + multipart)
- [ ] **#14 Anti-Cheat** — Native plugin Capacitor untuk Android mock GPS deferred
- [ ] **#15 PWA** — Generate real icon files
- [ ] **#15 PWA** — Web Push end-to-end (VAPID + backend endpoint + minishlink/web-push)
- [ ] **#15 PWA** — Periodic Background Sync
- [ ] **#16 Helpdesk** — File attachment upload deferred (schema siap, perlu endpoint multipart)
- [ ] **#17 Anniversary** — Reward template per milestone
- [ ] **#17 Anniversary** — Email integration (selain in-app notif)
- [ ] **#17 Anniversary** — Reminder H-7 sebelum milestone

---

## 📊 Ringkasan Prioritas

| Prioritas | Item | Estimasi |
|---|---|---|
| 🔴 P0 (kritis) | 3 area: Testing, Security, Compliance ID | 1–2 bulan |
| 🟠 P1 (major) | 7 area: Payroll, Attendance, Leave, Approval, Notification, Reports, Multi-tenant | 2–3 bulan |
| 🟡 P2 (polish) | 6 area: Docs, DevOps, DB, i18n/a11y, UX, Performance | 1–2 bulan paralel |
| 🟢 P3 (expansion) | 5 area: Modul baru, Integration, Mobile native, Refactoring, Roadmap deferred | berkelanjutan |

---

## 🚦 Rekomendasi Urutan Eksekusi

1. **Sprint 1–2 (P0 Foundation)**: Setup CI/CD + PHPStan + PHPUnit migration → Tutupi unit test gap untuk calculator (Pph21Ter, Bpjs, Overtime, Severance)
2. **Sprint 3 (P0 Security)**: Password policy + 2FA backup codes + file upload magic bytes + IP allowlist admin
3. **Sprint 4 (P0 Compliance)**: UU PDP consent flow + data export endpoint + JKP di BPJS calculator + Coretax stub
4. **Sprint 5–6 (P1 Payroll & Attendance)**: Magic numbers ke setting + cross-day shift fix + selfie retention + bulk approve
5. **Sprint 7 (P1 Notification)**: WebPush end-to-end + WhatsApp pilih provider + email template per company
6. **Sprint 8 (P1 Reports)**: Turnover, attrition, diversity, scheduled reports
7. **Sprint 9+ (P2 Polish)**: Docker Compose + Dockerfile + OpenAPI doc + i18n rollout + a11y audit
8. **Sprint 10+ (P3 Expansion)**: Pilih 2-3 modul prioritas berdasarkan kebutuhan customer

---

## 📌 Catatan

- **Yang sudah excellent** dan tidak perlu disentuh:
  - Audit logging (severity, module, request_id, diff, sanitization PII) — best-in-class
  - PrivacyScope multi-layer (tenant + own/all + permission)
  - Approval Engine generik dengan delegation support
  - Multi-tenant scoping di semua model utama
  - Feature breadth — 30+ modul matching/exceeding vendor SaaS HRIS Indonesia
- **Yang perlu hati-hati saat refactor**:
  - JWT filter set tenant context — banyak controller depend on `Services::tenant()`
  - Migrasi tambah-only (additive) — pertahankan pola ini, jangan rename/drop kolom existing
  - Smoke test scripts di `backend/scripts/` adalah safety net real — migrasi ke PHPUnit harus bertahap, jangan langsung delete
- **Quick wins** (< 1 hari, dampak besar):
  - Fix `PayrollController::generateThr` proporsional sampai resign_date
  - Tambah JKP rate ke `BpjsCalculator`
  - Magic numbers (`22`, `173`) jadi setting per-company
  - ~~Tambah PHPStan level 0 + fix issue (zero baseline)~~ ✅ done di Sprint 1-2
  - ~~Tambah `package.json` script untuk lint + format frontend~~ ✅ done di Sprint 1-2

---

## ✅ Sprint 1-2 — Selesai (2026-05-22)

**Foundation testing & CI/CD sudah aktif.** Semua command bisa dijalankan lokal dan di GitHub Actions.

### Yang ditambah

**Backend (`backend/`)**
- `composer.json` — devDep `phpstan/phpstan` + 4 script baru: `test`, `test:unit`, `stan`, `stan:baseline`, `ci`
- `phpstan.neon.dist` — config level 0 untuk `app/Libraries` + `app/Validation`
- `phpstan-baseline.neon` — kosong (0 errors di scope yang dianalisa)
- `phpunit.xml.dist` — `failOnWarning=false` (CI4 framework warning bukan kode kita)
- `tests/Unit/OvertimeCalculatorTest.php` — 20 test
- `tests/Unit/SeveranceCalculatorTest.php` — 30 test
- `tests/Unit/LeaveBalanceServiceTest.php` — 16 test
- `tests/Unit/Pph21TerCategoryTest.php` — 16 test (mapping PTKP→TER)

**Frontend (`frontend/`)**
- `package.json` — devDeps `eslint`, `eslint-plugin-vue`, `vitest`, `@vue/test-utils`, `jsdom` + script `lint`, `lint:fix`, `test`, `test:watch`
- `.eslintrc.cjs` — config vue3-essential + tweak rule (allow empty catch, downgrade vue parsing-error)
- `vitest.config.js` — extends vite config, jsdom env, coverage config
- `src/composables/__tests__/usePwa.spec.js` — sample/smoke test infra

**Root**
- `.github/workflows/ci.yml` — matrix PHP 8.1/8.2/8.3 + Node 18/20, full pipeline lint + test + build

### Hasil verifikasi lokal

| Command | Hasil |
|---|---|
| `composer test` (backend) | **148 passed**, 0 failed |
| `composer stan` (backend) | **OK No errors** |
| `composer ci` (backend) | both green |
| `npm test` (frontend) | **3 passed**, 0 failed |
| `npm run lint` (frontend) | **0 errors**, 85 warnings |
| `npm run build` (frontend) | (akan diverifikasi di CI) |

### Coverage unit test pure-function

| Calculator | Tests | Status |
|---|---|---|
| OvertimeCalculator | 20 | ✅ 100% public method |
| SeveranceCalculator | 30 | ✅ 17 alasan PHK + 2 tabel masa kerja |
| LeaveBalanceService::computeProratedQuota | 16 | ✅ 9 case mid-year + edge cases |
| Pph21TerCalculator::categoryFromPtkp | 16 | ✅ semua mapping PTKP |
| BpjsCalculator | 0 | ⏳ butuh DB stub — schedule integration test |
| Pph21TerCalculator::calculateMonthlyTer | 0 | ⏳ butuh DB stub — schedule integration test |

### Cara jalankan
```bash
# Backend
cd backend
composer install
composer test          # PHPUnit (148 tests)
composer stan          # PHPStan static analysis
composer ci            # gabungan keduanya

# Frontend
cd frontend
npm install
npm run lint           # ESLint
npm test               # Vitest single run
npm run test:watch     # Vitest watch mode
```

### Next sprint (Sprint 3)
Lihat **section 2 — Security Hardening** di atas untuk daftar item.

---

## ✅ Sprint 3 — Selesai (2026-05-22)

**Security hardening foundation aktif.** Password policy, 2FA backup codes,
file magic-byte validation, dan rate limit hardening sudah live.

### Yang ditambah

**Backend libraries (semua dengan unit test)**
- `App\Libraries\PasswordPolicy` — aturan R1-R6 password (panjang, kompleksitas, common blocklist 35-entry, history reuse check, user-specific check, forced rotation, optional HIBP breach check via k-anonymity)
- `App\Libraries\TwoFactorBackupCodes` — 8 single-use recovery codes per user (10 char alphanumeric pool excludes ambiguous 0/O/1/I, Argon2id-hashed, dengan normalize input)
- `App\Libraries\FileSignatureValidator` — magic-byte detection 7 format (JPEG/PNG/GIF/WebP/BMP/PDF/ZIP-based), helper `isImage()`, `isPdf()`, `safeExtension()`

**Migration**
- `2024-01-01-000036_CreateSecurityHardeningTables.php` — tabel `password_history` + `twofa_backup_codes` (additive, idempotent)

**Service registration (`app/Config/Services.php`)**
- `Services::passwordPolicy()` shared instance
- `Services::twoFactorBackupCodes()` shared instance

**Controller wiring (`AuthController.php`)**
- `changePassword()` — enforce policy + history reuse check + record to history
- `resetPassword()` — same enforcement saat reset via email token
- `enableTwoFactor()` — auto-generate 8 backup codes, return ke client (sekali tampil)
- `verifyTwoFactor()` — accept `code` (TOTP 6-digit) ATAU `backup_code` (recovery), audit log saat backup dipakai + jumlah sisa
- `uploadAvatar()` — verify magic bytes via `FileSignatureValidator::matches()` sebelum save, audit log kalau signature tidak match (kemungkinan spoofed MIME). Extension diambil dari magic bytes, bukan nama file (mencegah `fake.gif.exe`)

**Rate limit hardening (`ThrottleFilter.php` + `Routes.php`)**
- 4 bucket baru di `ThrottleFilter::bucketLimits()`:
  - `forgot_password`: 3 / 15 menit
  - `twofa`: 5 / 15 menit
  - `password_reset`: 5 / jam
  - `export`: 10 / jam
- Apply ke `/auth/forgot-password`, `/auth/verify-2fa`, `/auth/reset-password`, dan endpoint export berat (`payroll-periods/*/bank-export|pph21-export|bpjs-export`, `audit-logs/export`)

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/PasswordPolicyTest.php` | 22 | R1 panjang × 3, R2 kompleksitas × 5, R4 common × 5, R3 user-specific × 4, R6 rotation × 4 |
| `tests/Unit/TwoFactorBackupCodesTest.php` | 12 | generate format, ambiguous chars, uniqueness, hash format, hash verify, normalize × 3 |
| `tests/Unit/FileSignatureValidatorTest.php` | 20 | JPEG/PNG/GIF87a/GIF89a/WebP/PDF detection, reject PHP/HTML/PE disguised as image, RIFF non-WebP, allowlist match, safeExtension × 4 |

### Hasil verifikasi lokal

| Command | Hasil |
|---|---|
| `composer test` (backend) | **202 passed**, 0 failed (148 sebelumnya + 54 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 38 file analyzed) |
| `composer ci` (backend) | both green |
| `php spark migrate` | migration #000036 applied clean |

### Cara jalankan flow baru
```bash
# Test password policy enforcement
curl -X POST http://localhost:8080/api/v1/auth/change-password \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"current_password":"...","new_password":"weak"}'
# → 422 PASSWORD_POLICY_VIOLATION dengan list errors

# Enable 2FA + backup codes
curl -X POST http://localhost:8080/api/v1/auth/2fa/enable \
  -H "Authorization: Bearer $TOKEN"
# → response.backup_codes = ["A8K2N-Q3R7M", ...]

# Login → verify dengan backup code (kalau lost device)
curl -X POST http://localhost:8080/api/v1/auth/verify-2fa \
  -d '{"challenge":"...","backup_code":"A8K2N-Q3R7M"}'
# → response.used_backup_code=true, .backup_codes_remaining=7

# Test rate limit
for i in {1..10}; do
  curl -X POST http://localhost:8080/api/v1/auth/forgot-password \
    -d '{"email":"foo@example.com"}'
done
# → ke-4 dst: 429 RATE_LIMIT_EXCEEDED dengan Retry-After header
```

### Item yang sengaja ditunda (Sprint 4+)
- Email-based 2FA fallback untuk user tanpa authenticator app
- Force 2FA mandatory untuk role super_admin & HR admin
- HIBP breach check di runtime (network call optional, perlu strategy: block at change vs warn-only)
- EXIF strip dari foto upload (privacy untuk avatar/selfie)
- ClamAV anti-virus scan untuk attachment dokumen
- Refresh token device tracking + UI Active Sessions
- IP allowlist untuk role admin
- Encryption key rotation SOP

### Next sprint (Sprint 4)
Lihat **section 3 — Compliance Indonesia (Data & Pajak)** untuk:
- UU PDP consent flow & data export endpoint
- JKP rate di `BpjsCalculator`
- BPJS Kesehatan kelas per karyawan
- Bukti potong 1721-A1 final tahunan format DJP
- THR generator handle resign mid-year

---

## ✅ Sprint 4 — Selesai (2026-05-22)

**Compliance Indonesia foundation aktif.** UU PDP, BPJS JKP, BPJS Kesehatan
kelas, dan THR proporsional resign mid-year sudah live.

### Yang ditambah

**Backend libraries**
- `App\Libraries\BpjsCalculator` — refactor: pisahkan `compute()` (pure) dari `calculate()` (DB), tambah JKP (PP 37/2021) 0.46% × min(salary, 5jt), eligibility check PKWT/PKWTT (skip MAGANG/HARIAN/OUTSOURCING), include JKP di `company_total`. Default rates ditambah `jkp_company_rate` & `jkp_max_salary`.
- `App\Libraries\DataPrivacyService` — UU PDP service (Sprint 4 baru):
  - `recordConsent()` / `currentlyGranted()` / `consentHistory()` — log persetujuan dengan versi kebijakan
  - `exportUserData()` — kompilasi semua data pribadi user untuk download (profile + payslips + attendance + leaves + overtimes + documents + consents). Decrypt PII, redact secret/hash.
  - `requestDeletion()` / `reviewDeletion()` / `processApproved()` — workflow penghapusan data dengan retensi pajak preserved (anonimkan PII saja, simpan record finansial 5 tahun)

**Migration**
- `2024-01-01-000037_AddBpjsJkpAndKesClass.php` — tambah `bpjs_rates.jkp_company_rate`, `bpjs_rates.jkp_max_salary`, `employees.bpjs_kes_class`. Backfill default 0.46% + 5jt untuk row existing.
- `2024-01-01-000038_CreatePrivacyConsentAndRetention.php` — table `privacy_consents` (audit-trail consent), table `data_deletion_requests` (workflow), kolom `users.consent_version_accepted` + `users.consent_at` (cache).

**Service registration (`app/Config/Services.php`)**
- `Services::dataPrivacy()` shared instance

**Controller wiring**
- `PayrollController::generateThr` — refactor:
  - Logic dipisah ke 2 method static `computeTenureMonths()` & `computeThrAmount()` (testable)
  - Karyawan resign mid-year tetap dihitung tenure-nya sampai `min(resign_date, pay_date)` — sebelumnya di-exclude karena `is_active=1` filter
  - `tenureMonths` dihitung sampai `pay_date` (bukan `time()`) supaya re-generate idempotent
  - Karyawan resign sebelum awal periode (default 1 Jan tahun pay_date) di-exclude
  - Audit log mencakup count + total amount
- `PrivacyController` (Sprint 4 baru) — 7 endpoint:
  - `GET  /me/privacy/policy-version` — versi kebijakan saat ini + daftar consent_type
  - `GET  /me/privacy/consents` + `POST /me/privacy/consents` — riwayat & record consent
  - `GET  /me/data-export` — JSON file download (throttle:export 10/jam)
  - `POST /me/data-deletion-request` — submit permintaan, idempotent jika sudah PENDING
  - `GET  /admin/privacy/deletion-requests` — list untuk HR (perlu permission `privacy.review`)
  - `POST /admin/privacy/deletion-requests/(:num)/review` — approve/reject
  - `POST /admin/privacy/deletion-requests/(:num)/process` — execute anonimisasi setelah retensi

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/BpjsCalculatorTest.php` | 25 | default rates, UMP Jakarta full scenario, plafon Kesehatan/JP, no-cap JHT, JKP at/below/above plafond, JKP eligibility per employmentStatus × 7, custom rates override, totals consistency, edge cases |
| `tests/Unit/ThrCalculatorTest.php` | 20 | computeTenureMonths × 9 (data-driven: full year, mid-year, < 1 month, resign mid-year, resign before period, custom period start, invalid dates, exceed 12 months); computeThrAmount × 9 (full, proporsional 6/12, 1/12, 0, negative, cap di 12+, integer rounding) |

### Hasil verifikasi lokal

| Command | Hasil |
|---|---|
| `composer test` (backend) | **247 passed**, 0 failed (202 sebelumnya + 45 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 39 file analyzed) |
| `composer ci` (backend) | both green |
| `php spark migrate` | migration #037 + #038 applied clean |

### Cara jalankan flow baru

```bash
# Cek versi kebijakan saat ini
curl http://localhost:8080/api/v1/me/privacy/policy-version \
  -H "Authorization: Bearer $TOKEN"
# → {"version":"v1.0-2026","consent_types":[...]}

# Catat persetujuan saat user pertama login pakai versi baru
curl -X POST http://localhost:8080/api/v1/me/privacy/consents \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"consent_type":"PRIVACY_POLICY","version":"v1.0-2026","granted":true}'

# Download seluruh data pribadi (UU PDP Pasal 8b)
curl -O http://localhost:8080/api/v1/me/data-export \
  -H "Authorization: Bearer $TOKEN"
# → file: data-export-user42-20260522_104100.json

# Ajukan penghapusan data (UU PDP Pasal 8f)
curl -X POST http://localhost:8080/api/v1/me/data-deletion-request \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"reason":"saya pindah ke perusahaan lain"}'

# HR review
curl -X POST http://localhost:8080/api/v1/admin/privacy/deletion-requests/12/review \
  -H "Authorization: Bearer $HR_TOKEN" \
  -d '{"approve":true,"note":"Approved setelah retensi pajak 5 tahun"}'

# Generate THR — sekarang include karyawan yang resign mid-year
curl -X POST http://localhost:8080/api/v1/payroll/thr \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"pay_date":"2026-04-15","name":"THR Lebaran 2026"}'
```

### Item yang sengaja ditunda (Sprint 5+)
- Frontend banner "kebijakan diperbarui" + UI consent capture saat onboarding
- Cron `php spark hr:purge-resigned --years=5` untuk auto-anonimisasi setelah retensi
- BPJS JKP integration ke `PayrollService::calculatePeriod` (calculator siap, payroll service belum diintegrasi — defer karena perlu update payslip schema + breakdown)
- Plafon iuran BPJS Kesehatan berdasarkan kelas (rate per kelas terpisah)
- Bukti potong 1721-A1 final tahunan format DJP 2026
- PPh21 koreksi Desember handle mid-year join/resign
- Admin UI CRUD `ter_rates` untuk update bracket tahun 2027+
- THR multiple kontrak / dual religion handling
- UMP/UMK auto-fetch yearly + Dashboard alert proaktif
- E-Faktur Coretax integration

### Next sprint (Sprint 5–6)
Lihat **section 4 — Payroll Engine** & **section 5 — Attendance & Anti-Cheat** untuk:
- Magic numbers (22 hari, 173 jam, 8 jam) ke setting per-company
- FK CASCADE migration di payslips supaya defensive cleanup di PayrollService bisa dihapus
- Cross-day shift fix di `AttendanceController::clockIn` (overnight shift 22:00–06:00)
- Selfie retention cron + EXIF strip
- Bulk approve di Inbox HR

---

## ✅ Sprint 5-6 — Selesai (2026-05-22)

**P1 Payroll Engine & Attendance Cross-Day Shift sudah aktif.** Magic
numbers ke setting per-company, overnight shift overflow handling, dan
selfie retention cron sudah live.

### Yang ditambah

**Backend libraries (semua dengan unit test)**
- `App\Libraries\PayrollSettings` — central config payroll per-company:
  - `workingDaysPerMonth()` — default 22, override via setting `payroll.working_days_per_month`
  - `overtimeHoursPerMonth()` — default 173 (PP 35/2021), override via `payroll.overtime_hours_per_month`
  - `overtimeToLeaveHoursPerDay()` — default 8 jam, override via `payroll.overtime_to_leave_hours_per_day`
  - `absentDeductionEnabled()` — flag untuk perusahaan yang tidak pakai potongan absen
  - Per-request cache, fallback ke default kalau setting tidak ada / DB tidak available
- `App\Libraries\ShiftTimeHelper` — utility untuk cross-midnight shift:
  - `isOvernight($shift)` — bool, true kalau end_time ≤ start_time
  - `effectiveWorkDate($shift, $clockInTs)` — resolve tanggal mulai shift (overnight: clock-in jam 01:00 → workDate kemarin)
  - `effectiveShiftStart()` / `effectiveShiftEnd()` — Unix TS yang aware overnight
  - `lateMinutes()` / `earlyLeaveMinutes()` — dengan toleransi opsional
- `App\Libraries\OvertimeCalculator` — refactor: parameter baru `$hoursPerMonth` (default 173, untuk backward-compat). Caller (PayrollService) inject value dari PayrollSettings per company.

**Service registration (`app/Config/Services.php`)**
- `Services::payrollSettings()` shared instance
- `Services::shiftTime()` shared instance

**Controller wiring**
- `PayrollService::calculatePeriod` — read PayrollSettings sekali per period, inject ke `calculateEmployee()` sebagai param. Magic number `/22` (absent deduction) ganti pakai setting; OvertimeCalculator dipanggil dengan custom `hoursPerMonth`.
- `AttendanceController::clockIn` — refactor:
  - Resolve shift untuk today; kalau overnight & clock-in dini hari → re-resolve untuk yesterday
  - `workDate` = effective start date (bukan today)
  - `lateMinutes` via `ShiftTimeHelper::lateMinutes()` yang aware overnight
  - Duplicate clock-in check pakai resolved workDate
- `AttendanceController::clockOut` — refactor:
  - Cari open attendance row di today, fallback ke yesterday (untuk overnight shift)
  - `earlyLeaveMinutes` via `ShiftTimeHelper::earlyLeaveMinutes()` yang aware overnight (shift end keesokan paginya)

**Migration & cron — sudah ada sebelumnya, tidak baru di sprint ini**
- Migration `#000025_AddP0HardeningFields.php` — sudah include kolom `selfie_retention_days` per company (default 90)
- Command `App\Commands\CleanupAttachments` (`php spark hr:cleanup-attachments`) — sudah ada, skip yang anomaly_score ≥ 30 untuk audit jangka panjang. Tinggal jadwalkan via cron.

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/PayrollSettingsTest.php` | 6 | default values 22/173/8/true, all() snapshot, public constants exposed |
| `tests/Unit/ShiftTimeHelperTest.php` | 22 | isOvernight × 6 (data-driven), invalid times, workDate normal/overnight × 4, lateMinutes × 6 (normal/overnight + tolerance), shiftEnd × 2, earlyLeave × 4 |
| `tests/Unit/OvertimeCalculatorTest.php` | +2 | custom hoursPerMonth divisor 160, invalid value fallback ke default 173 |

### Hasil verifikasi lokal

| Command | Hasil |
|---|---|
| `composer test` (backend) | **279 passed**, 0 failed (247 sebelumnya + 30 baru + 2 extend OvertimeCalc) |
| `composer stan` (backend) | **OK No errors** (level 0, 41 file analyzed) |
| `composer ci` (backend) | both green |
| `php spark hr:cleanup-attachments` | command listed (sudah ada di Sprint 1 hardening) |

### Cara jalankan flow baru

```bash
# Override magic number per-company via API settings
curl -X PUT http://localhost:8080/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"key":"payroll.working_days_per_month","value":"21"}'
# → calculate ulang payroll: per_day = basic / 21 (bukan / 22)

# Override OT divisor untuk perusahaan dengan jam kerja 160/bulan
curl -X PUT http://localhost:8080/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"key":"payroll.overtime_hours_per_month","value":"160"}'

# Karyawan shift malam clock-in 22:00 → work_date = today (8 Jan)
# Karyawan shift malam clock-in 01:00 dini hari → work_date = kemarin (7 Jan)
# Otomatis di-resolve via ShiftTimeHelper

# Cron selfie cleanup (tambahkan ke crontab)
0 4 * * 0  cd /var/www/hris/backend && php spark hr:cleanup-attachments
```

### Item yang sengaja ditunda (Sprint 7+)
- FK `ON DELETE CASCADE` untuk `payslips.payroll_period_id` & `payslip_details.payslip_id` — defer Sprint 7+ (perlu MySQL migration helper yang aman utk SQLite dev env)
- Bonus engine (KPI/milestone/tahun-end) — defer Sprint 8+
- Payslip digital signature/hash untuk integrity — defer Sprint 8+
- Bulk send email payslip setelah finalize — defer Sprint 7+
- Pre-tax vs post-tax allowance breakdown konsisten — defer Sprint 7+
- Break time tracking di attendance — defer Sprint 8+
- Multi clock-in/out per hari — defer Sprint 8+
- EXIF strip dari foto upload — defer Sprint 7+
- Liveness detection / face match — defer Sprint 9+
- Geofence polygon support — defer Sprint 8+
- Bulk approve di Inbox HR — defer Sprint 7+ (perlu UI changes di frontend)

### Next sprint (Sprint 7)
Lihat **section 8 — Notifications** untuk:
- WebPush end-to-end (VAPID + minishlink/web-push library)
- WhatsApp provider integration (Wablas/Fonnte/Twilio)
- Email template per company dengan variable substitution
- Real-time SSE endpoint
- Email queue exponential backoff
- Digest email mingguan/bulanan

---

## ✅ Sprint 7 — Selesai (2026-05-22)

**P1 Notifications hardening aktif.** Web Push subscription end-to-end,
email template per-company dengan variable substitution, dan email queue
exponential backoff sudah live.

### Yang ditambah

**Backend libraries (semua dengan unit test)**
- `App\Libraries\EmailTemplateRenderer` — engine template per-company:
  - Lookup tabel `email_templates` by `company_id + key + is_active=1`, fallback ke template global (`company_id=NULL`)
  - Variable substitution `{{name}}` (escaped via `htmlspecialchars`) + `{{{name}}}` (raw, untuk HTML pre-rendered)
  - Subject + body render terpisah (subject otomatis strip HTML)
  - Conditional block sederhana `{{#if var}}...{{/if}}` untuk skip section kalau variable kosong
  - Static helper `renderString()` untuk testability tanpa DB
- `App\Libraries\EmailQueueBackoff` — utility schedule retry:
  - `MAX_ATTEMPTS = 5`, intervals `[60, 300, 1800, 7200, 21600]` detik (1m/5m/30m/2h/6h)
  - `nextRetryAt(int $attempt)` return ISO datetime dengan ±25% jitter (anti thundering-herd)
  - `shouldRetry(int $attempt)` bool guard

**Migration**
- `2024-01-01-000039_NotificationsHardening.php`:
  - Table `web_push_subscriptions` — kolom `user_id`, `company_id`, `endpoint` (UNIQUE), `p256dh_key`, `auth_token`, `user_agent`, `last_used_at`, `created_at`
  - Table `email_templates` — kolom `company_id` (NULL untuk global), `key`, `subject`, `body_html`, `description`, `version`, `is_active`, dengan UNIQUE composite `(company_id, key, version)` + index `(company_id, key, is_active)`
  - Tambah ke `email_queue`: `next_retry_at` (datetime, NULL), `last_attempt_at` (datetime, NULL)
  - Idempotent (cek `tableExists` & `fieldExists` sebelum apply)

**Channel architecture (`App\Notifications\Channels\WebPushChannel`)**
- Implementasi `NotificationChannel` interface
- `slug() = 'web_push'`, `isAvailable($user)` = ada subscription aktif untuk user
- `send($user, $payload)` — dispatch ke semua subscription user via `minishlink/web-push` SDK
- Graceful degradation: kalau library belum di-install (Composer), method skip + log warning (tidak fail)
- Auto-cleanup subscription stale (404/410 dari push service)

**Service registration (`app/Config/Services.php`)**
- `Services::emailTemplateRenderer()` shared instance
- `Services::emailQueueBackoff()` shared instance (helper)

**Controller wiring**
- `App\Controllers\Api\V1\WebPushController` (Sprint 7 baru) — 3 endpoint:
  - `GET    /notifications/push-public-key` — return VAPID public key untuk client subscribe
  - `POST   /notifications/push-subscribe` — UPSERT subscription (endpoint UNIQUE), update `user_id` & `last_used_at`
  - `DELETE /notifications/push-subscribe` — hapus by endpoint atau hapus semua subscription user
- `NotificationService::processEmailQueue()` — refactor:
  - Filter row pakai `next_retry_at IS NULL OR next_retry_at <= NOW()` (skip yang masih future)
  - Saat catch error: kalau `attempts < MAX_ATTEMPTS` → set `next_retry_at` via `EmailQueueBackoff::nextRetryAt()`; kalau habis → FAILED final tanpa next_retry
  - Update `last_attempt_at` setiap attempt
- `NotificationService` — tambah dispatcher channel-based (lihat juga refactor ke `NotificationChannel` interface dengan In-App/Email/WhatsApp/WebPush)

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/EmailTemplateRendererTest.php` | 11 | renderString basic, escape default, raw `{{{}}}`, missing var → empty, conditional `{{#if}}` true/false, multiple vars, nested-like, subject strip HTML, edge case empty template |
| `tests/Unit/EmailQueueBackoffTest.php` | 9 | constants exposed, nextRetryAt range per attempt × 5 (with jitter window check), shouldRetry true/false, attempt out-of-range guard |

### Hasil verifikasi lokal

| Command | Hasil |
|---|---|
| `composer test` (backend) | **312 passed**, 1 warning, 0 failed (279 sebelumnya + 33 baru) |
| `composer stan` (backend) | **OK No errors** (Sprint 7 file analyzed) |
| `php spark migrate` | migration #039 applied clean |

### Cara jalankan flow baru

```bash
# Generate VAPID keys (sekali, simpan ke env)
openssl ecparam -name prime256v1 -genkey -noout -out vapid-private.pem
openssl ec -in vapid-private.pem -pubout -out vapid-public.pem
# Convert ke base64url, simpan di .env: VAPID_PUBLIC_KEY=... VAPID_PRIVATE_KEY=...

# Install web-push library (opsional, hanya kalau mau enable WebPushChannel)
cd backend && composer require minishlink/web-push

# Client subscribe (frontend)
const reg = await navigator.serviceWorker.ready;
const { public_key } = await api.get('/notifications/push-public-key');
const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: public_key });
await api.post('/notifications/push-subscribe', { subscription: sub });

# Process email queue dengan retry backoff
php spark email:process
# Email yang gagal akan otomatis di-retry sesuai schedule (1m/5m/30m/2h/6h)

# Cek template per company (admin)
INSERT INTO email_templates (company_id, key, subject, body_html, version, is_active)
VALUES (1, 'leave.approved', 'Cuti {{leave_type}} disetujui',
        '<p>Halo {{employee_name}}, cuti {{leave_dates}} kamu disetujui.</p>', 1, 1);
# Notif type `leave.approved` akan otomatis pakai template ini
```

### Item yang sengaja ditunda (Sprint 8+)
- Frontend Admin → Email Templates editor (CRUD UI, schema sudah siap)
- WhatsApp provider pilih + integrasi (Wablas/Fonnte/Twilio)
- Real-time SSE endpoint `/notifications/stream`
- Digest email mingguan/bulanan via `notifications:digest` command
- VAPID key generation command `php spark webpush:generate-keys`
- Test push real end-to-end ke device (perlu device testing)

### Next sprint (Sprint 8)
Lihat **section 9 — Reports & Analytics** untuk:
- Turnover / attrition rate
- Cost per hire dari Recruitment funnel
- Diversity report (gender, age, education)
- Custom report builder
- Excel native export (phpoffice/phpspreadsheet sudah di composer)
- Scheduled reports auto-email

---

## ✅ Sprint 9 — Selesai (2026-05-22)

**P2 Polish — DevOps & Documentation aktif.** Docker stack lengkap (dev +
prod), OpenAPI 3.0 spec foundation, setup scripts cross-platform, Dependabot
auto-update, dan README polish dengan badges + quick-start 3 cara sudah live.

### Yang ditambah

**Docker stack (dev + production)**
- `docker-compose.yml` (root) — dev stack lengkap:
  - `mysql:8.0` dengan healthcheck
  - `redis:7-alpine` untuk cache + session
  - `mailhog:latest` untuk catch outgoing email (UI di :8025)
  - `backend` (PHP 8.2 + spark serve, mount source untuk hot reload)
  - `frontend` (Vite dev server dengan `--host 0.0.0.0`)
  - Volume terpisah untuk `vendor/` & `node_modules/` supaya tidak ke-overwrite host
- `backend/Dockerfile.dev` — PHP 8.2-cli + composer + extensions (mysqli, intl, gd, zip, redis)
- `backend/Dockerfile` — PRODUCTION multi-stage:
  - Stage 1: `composer:2` install `--no-dev --optimize-autoloader`
  - Stage 2: `php:8.2-fpm-alpine` + opcache (validate_timestamps=0, agresif untuk perf)
  - Production PHP settings (expose_php=Off, secure session cookies)
  - Drop privilege ke `www-data`, mount writable/ via volume
  - Healthcheck built-in
- `frontend/Dockerfile.dev` — Node 20 alpine
- `frontend/Dockerfile` — PRODUCTION multi-stage:
  - Stage 1: `node:20-alpine` + `npm ci` + `npm run build`
  - Stage 2: `nginx:alpine` serve `dist/` dengan SPA fallback + gzip + cache long
  - Build-arg `VITE_API_BASE_URL` di-bake ke bundle saat build
- `frontend/nginx.conf` — config production: cache 1y untuk `/assets/*`, no-cache untuk `/sw.js` & `/registerSW.js`, security headers
- `backend/.dockerignore` + `frontend/.dockerignore` — slim build context (exclude tests, vendor, node_modules, .env)

**Setup scripts (cross-platform)**
- `scripts/setup-dev.ps1` (Windows / PowerShell) — onboarding script idempotent:
  - Cek deps (php, composer, node, npm)
  - Backend: composer install + .env from example + migrate + seed
  - Frontend: npm install + .env from example
  - Support flag `-Reset` (drop SQLite) & `-SkipMigrate`
- `scripts/setup-dev.sh` (Linux / macOS / WSL) — paralel implementation:
  - Same flow, support `--reset` & `--skip-migrate`
  - Color output (cyan/green/yellow/gray)

**Documentation**
- `docs/openapi.yaml` — OpenAPI 3.0 spec foundation:
  - Info, servers (dev + prod), security (bearerAuth), tags
  - Endpoints utama: Auth (login, 2FA, refresh, logout, me, change-password, enable-2FA), Employees (CRUD), Attendance (clock-in/out/my), Leave (CRUD + approve/reject), Privacy (UU PDP — policy version, consents, data export, deletion request), Notifications (list, unread-count, push-subscribe)
  - Reusable components: parameters (Id, Page, PerPage), responses (Success, Unauthorized, Forbidden, NotFound, ValidationError, RateLimited), schemas (SuccessEnvelope, ErrorEnvelope, PaginatedEnvelope, LoginSuccessResponse, User, Employee, Attendance, Leave)
  - Compatible dengan Swagger Editor / Swagger UI / Postman import
- `docs/DOCKER.md` — panduan lengkap dev + production Docker workflow (build, run, healthcheck, image size, security, troubleshooting)
- `README.md` — major rewrite:
  - Tech stack badges (CI status, PHP, CodeIgniter, Vue, Vite, Tailwind, tests count)
  - Folder structure tree dengan annotation per file kunci
  - Quick start 3 cara: setup script / manual / Docker
  - Daftar 30+ modul dengan status compliance
  - Coverage table (10 calculator/library, 312 tests total)
  - Pointer ke OpenAPI + 35+ feature docs
  - Production deployment shortcut

**CI/CD enhancement**
- `.github/dependabot.yml` — auto-update dependencies:
  - Composer (backend): weekly Monday 08:00 Asia/Jakarta, group patch + minor terpisah, ignore CodeIgniter major
  - npm (frontend): weekly, ignore Vue & Vite major
  - GitHub Actions: monthly
  - Docker base images: monthly
  - Commit message prefix per ekosistem (`chore(deps,backend)`, `chore(deps,frontend)`, dst)

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **312 passed**, 0 failed (no code changes Sprint 9, sanity check) |
| `composer stan` (backend) | **OK No errors** |
| YAML lint (`docker-compose.yml`, `openapi.yaml`, `dependabot.yml`) | semua valid |

### Cara pakai stack baru

```bash
# Onboarding developer baru (15 detik vs manual 5 menit)
.\scripts\setup-dev.ps1                  # Windows
bash scripts/setup-dev.sh                # Linux/macOS/WSL

# Reset dev environment total
.\scripts\setup-dev.ps1 -Reset           # drop SQLite, re-migrate, re-seed

# Full Docker stack (mysql + redis + mailhog + backend + frontend)
docker compose up -d
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder
# Frontend: http://localhost:5173
# Email UI: http://localhost:8025

# Build production images
docker build -t hris-backend:1.0 -f backend/Dockerfile backend/
docker build --build-arg VITE_API_BASE_URL=https://api.hris.example.com \
             -t hris-frontend:1.0 -f frontend/Dockerfile frontend/

# View OpenAPI spec interaktif
# Paste docs/openapi.yaml ke https://editor.swagger.io
# atau host sendiri:
docker run -p 8081:8080 -e SWAGGER_JSON=/docs/openapi.yaml \
    -v $PWD/docs:/docs swaggerapi/swagger-ui
```

### Item yang sengaja ditunda (Sprint 10+)
- ADR (Architecture Decision Records) di `docs/adr/`
- Changelog otomatis (Conventional Commits + git-cliff / semantic-release)
- Pre-commit hooks (Husky + lint-staged + php-cs-fixer + prettier)
- Swagger UI hosted di `/api/docs` route (perlu setup serving static + auth gating)
- Postman collection di `docs/HRIS.postman_collection.json` (auto-generate dari OpenAPI)
- `.github/workflows/deploy.yml` — auto-deploy ke staging on push to main
- Screenshot 5 halaman utama untuk README (perlu staging environment)
- Demo URL / video walkthrough
- Release versioning (semantic-release config)
- Extend OpenAPI spec ke 65+ endpoint lainnya (saat ini foundation untuk module utama, sisanya tinggal mengikuti pola)

### Next sprint (Sprint 10+)
Pilih salah satu jalur berdasar prioritas:

**Jalur A — P2 lanjutan**: Database optimization (composite index, audit log partition, point-in-time recovery), i18n rollout, a11y audit, performance engineering (N+1 queries, Redis cache layer, image optimization)

**Jalur B — P3 fitur**: 2-3 modul baru sesuai kebutuhan customer (Shift Swap, Survey/Pulse, Skills Inventory, Bonus Engine, Petty Cash, dst)

**Jalur C — Integration**: Webhook outbound, accounting (Accurate/Jurnal.id), e-signature, OAuth SSO

**Jalur D — Refactoring**: Long file split (`LeaveController` 819 baris → service classes), DTO/Form Request pattern, magic numbers ke enum/constant, FK CASCADE migration (hapus defensive cleanup di PayrollService)

---

## ✅ Sprint 10+ — Selesai (2026-05-22)

**Refactoring foundation + Performance optimization aktif.** Constants
class hierarchy, CacheService library, composite indexes untuk query
pattern utama, dan ADR documentation kunci sudah live.

### Yang ditambah

**Constants foundation (`backend/app/Constants/`)**
- `App\Constants\AttendanceStatus` — 7 status (PRESENT/LATE/ABSENT/LEAVE/HOLIDAY/SICK/HALF_DAY) + helper:
  - `isValid()` — validation untuk input form
  - `isPresentLike()` — group untuk payroll (PayrollService::calculatePeriod logic)
  - `label()` — return [label, color] untuk UI badge
  - `ALL` constant array — bisa langsung dipakai untuk ENUM constraint validation
- `App\Constants\EmploymentStatus` — 6 status (PKWT/PKWTT/PROBATION/HARIAN/MAGANG/OUTSOURCING) + helper:
  - `isJkpEligible()` — PP 37/2021 logic, accept null/lowercase untuk backward-compat
  - `JKP_ELIGIBLE`, `TAX_ELIGIBLE` constant arrays
- `App\Constants\ApprovalStatus` — 7 state (DRAFT/PENDING/APPROVED/REJECTED/CANCELED/CONSENT_REQUIRED/IMPLEMENTED) + helper:
  - `isPending()` — workflow belum selesai
  - `isFinal()` — state tidak bisa diubah lagi
  - Adapter untuk semua module approval (cuti, lembur, JIC, transfer, dll)
- `App\Constants\ErrorCode` — 24 standard error codes dengan format `snake_case`:
  - Auth: `unauthorized`, `permission_denied`, `twofa_required`, `password_policy_violation`, dst
  - Resource: `resource_not_found`, `resource_conflict`, `resource_locked`
  - File: `file_too_large`, `file_invalid_type`, `file_signature_mismatch`
  - Business: `attendance_outside_geofence`, `leave_insufficient_balance`, `payroll_period_finalized`, dst

**Cache layer (`backend/app/Libraries/CacheService.php`)**
- Read-through pattern: `remember(key, ttl, callback)` dengan NULL-sentinel agar bisa cache null result
- `skipFalsy=true` option untuk yang tidak mau cache empty result
- Key builders terpusat: `keyUmp(province, year)`, `keyUmk(city, year)`, `keyHolidays(company, year)`, `keyRolePermissions(role)`, `keyTerRates(category, year)`, `keyBpjsRates(company)`, `keyPayrollSettings(company)`
- TTL constants: `TTL_MINUTE`, `TTL_5_MIN`, `TTL_15_MIN`, `TTL_HOUR`, `TTL_DAY`, `TTL_WEEK`, `TTL_DEFAULT` (= 5 min)
- Bulk invalidate via `forgetPrefix('ump:')` (deleteMatching wildcard)
- Graceful degradation: cache backend down → fallback ke direct callback, log warning, jangan gagalkan request
- Duck-typed backend (CodeIgniter `CacheInterface` atau stub testing) — gampang di-mock

**Migration (`#040 AddCompositeIndexes`)**
- Composite index untuk 5 tabel paling sering di-query:
  - `attendances(company_id, work_date, employee_id)` — calendar/payroll/reports
  - `leaves(company_id, employee_id, status, start_date)` — list filter + balance
  - `overtimes(company_id, employee_id, status, work_date)` — list filter
  - `audit_logs(company_id, created_at)` — paginate by recency per tenant
  - `notifications(user_id, is_read, created_at)` — unread filter + recency
- Idempotent (cek `INFORMATION_SCHEMA.STATISTICS` sebelum apply)
- MySQL only — di-skip dengan log info kalau driver SQLite/Postgres

**Service registration (`app/Config/Services.php`)**
- `Services::cacheService()` shared instance (lazy-init `service('cache')` sebagai default backend)

**ADR documentation (`docs/adr/`)**
- `README.md` — index + template untuk ADR baru
- [0001 Backend Framework: CodeIgniter 4](adr/0001-codeigniter-vs-laravel.md) — konteks shared hosting + cold start + footprint, alternatif Laravel/Symfony/Slim, mitigasi via separate library layer
- [0002 Authentication: JWT + refresh rotation](adr/0002-jwt-vs-session-auth.md) — stateless backend, multi-device support, refresh token rotation untuk security
- [0003 Database: SQLite dev / MySQL prod](adr/0003-sqlite-dev-mysql-prod.md) — onboarding cepat di dev tanpa MySQL setup, behavioral drift mitigations
- [0004 Migration: additive-only pattern](adr/0004-additive-only-migrations.md) — zero-downtime upgrades, forward compatibility, schema cleanup release plan

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/ConstantsTest.php` | 14 | AttendanceStatus values × 5, isValid, isPresentLike, label, EmploymentStatus values + JKP eligibility 8 cases, ApprovalStatus pending/final guards, ErrorCode lower_snake_case format |
| `tests/Unit/CacheServiceTest.php` | 8 | remember stores+returns, NULL sentinel cache, skipFalsy bypass, get/set/forget round-trip, set null value, key builders consistent, prefix applied, forgetPrefix wildcard delete, TTL constants |

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **334 passed**, 1 warning, 0 failed (312 sebelumnya + 22 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 45+ file analyzed) |
| `php spark migrate` | migration #040 applied (no-op di SQLite, akan apply di MySQL prod) |

### Cara pakai

**Constants** — gantikan magic strings di code baru (existing string literal masih jalan):

```php
use App\Constants\AttendanceStatus;
use App\Constants\EmploymentStatus;
use App\Constants\ApprovalStatus;
use App\Constants\ErrorCode;

// AttendanceController
if (AttendanceStatus::isPresentLike($att['status'])) {
    $present++;
}

// BpjsCalculator
if (EmploymentStatus::isJkpEligible($emp['employment_status'])) {
    $jkp = $this->computeJkp($salary);
}

// LeaveController approve/reject
if (! ApprovalStatus::isPending($leave['status'])) {
    return $this->fail(ErrorCode::APPROVAL_INVALID_STATE, 'Cuti sudah ' . $leave['status']);
}
```

**CacheService** — bungkus query yang sering di-hit:

```php
$cache = service('cacheService');

// UMP lookup yang cuma berubah setahun sekali
$ump = $cache->remember(
    $cache->keyUmp($provinceId, $year),
    CacheService::TTL_DAY,
    fn() => $umpModel->find($provinceId, $year)
);

// Holidays per company per tahun
$holidays = $cache->remember(
    $cache->keyHolidays($companyId, $year),
    CacheService::TTL_DAY,
    fn() => $holidayModel->forCompanyYear($companyId, $year),
    skipFalsy: true  // jangan cache empty array (sambungan baru tahun baru)
);

// Invalidate saat admin update UMP
$cache->forget($cache->keyUmp($provinceId, $year));
// Atau invalidate semua UMP saat upload massal
$cache->forgetPrefix('ump:');
```

**Composite indexes** — auto-applied saat migrate. EXPLAIN query sebelum dan sesudah:

```sql
-- Sebelum: full scan attendance untuk laporan bulanan
EXPLAIN SELECT * FROM attendances
 WHERE company_id = 5 AND work_date BETWEEN '2026-05-01' AND '2026-05-31';
-- type: ALL (full scan)

-- Sesudah: pakai idx_att_company_date_emp
-- type: range, key: idx_att_company_date_emp
```

### Item yang sengaja ditunda (Sprint 11+)

- **Wire CacheService ke caller existing**: `UmpModel`, `Pph21TerCalculator`, `BpjsCalculator`, `RoleController` — foundation siap, tinggal apply per-use case dengan invalidation strategy yang tepat
- **Migrasi controller existing pakai Constants** — bertahap, refactor PR per controller (additive — string literal lama tetap jalan)
- **N+1 query fix di PayrollService** — perlu profiling production data dulu untuk identify hot path
- **Audit log partition** (MySQL 8) + retention command — defer Sprint 12+ (perlu test scaling dulu di staging)
- **Read replica** untuk reporting — defer (perlu infrastruktur tambahan)
- **i18n rollout** — defer (effort besar, audit semua view + extract string)
- **Lighthouse a11y audit** — defer (perlu staging URL)
- **DTO / Form Request pattern** — defer (effort besar, butuh design pattern uniform)
- **Long file refactor** (LeaveController, AttendanceController) — defer (high-risk, butuh test coverage feature dulu sebelum split)
- **FK CASCADE migration** untuk payslips — defer (perlu test di MySQL prod data, defensive cleanup di PayrollService masih jalan)

### Statistik komulatif (Sprint 1 → 10+)

| Metric | Sprint 1-2 | Sprint 9 | Sprint 10+ |
|---|---|---|---|
| Backend tests | 148 | 312 | **334** |
| PHPStan errors | 0 (level 0) | 0 | 0 |
| Backend libraries | ~25 | ~32 | **34** |
| Migrations | ~35 | 39 | **40** |
| Constants classes | 0 | 0 | **4** |
| ADR documents | 0 | 0 | **4** |
| Docker support | ❌ | ✅ dev+prod | ✅ |

### Next sprint (Sprint 11+)

Direkomendasikan pilih satu fokus:

**Apply foundation Sprint 10+** — wire CacheService ke caller existing (mulai dari `UmpModel::find`), migrate satu controller besar pakai Constants (mulai `AttendanceController`), bench query performance setelah composite index deploy ke MySQL.

**ATAU shift ke fitur baru** — pilih 2-3 modul P3 (Shift Swap, Survey, Skills Inventory) berdasar prioritas customer.

**ATAU integrasi external** — webhook outbound + accounting integration (Accurate/Jurnal.id) untuk auto-export jurnal payroll.

---

## ✅ Sprint 11+ — Selesai (2026-05-22)

**Integration platform foundation aktif.** Webhook outbound infrastructure
lengkap dengan HMAC signing + retry exponential backoff + auto-disable,
plus apply CacheService ke `BpjsCalculator` & adopt `EmploymentStatus`
constant sebagai contoh wiring real.

### Yang ditambah

**Webhook Outbound (`backend/app/Libraries/Webhook*` + Constants + Migration + Controller + Docs)**
- `App\Constants\WebhookEvent` — 24 event types dengan konvensi `{module}.{action}`:
  - Employee lifecycle (created/updated/resigned/rejoined)
  - Payroll (period_finalized, paid, payslip.published, thr.generated)
  - Leave (requested/approved/rejected/canceled)
  - Overtime, Attendance (late_absent, anomaly_flagged)
  - Recruitment (candidate_hired, posting_published)
  - Disciplinary (sp_issued, sp_revoked)
  - Severance (finalized, paid)
  - Helper `isValid()` + `filterValid()` untuk filter user input
- `App\Libraries\WebhookSigner` — pure HMAC-SHA256 signing:
  - Format `sha256=<hex>` mengikuti GitHub/Stripe convention
  - Signing string `{timestamp}.{raw_body}` untuk replay protection
  - `verify()` dengan timing-safe comparison + 5 menit tolerance window
  - `generateSecret()` returns 64-char hex (256 bit entropy)
- `App\Libraries\WebhookDispatcher` — orchestrator delivery:
  - `dispatch()` enqueue ke semua webhook subscriber per event
  - `processQueue()` HTTP POST dengan curlrequest, timeout 10s
  - Retry pakai `EmailQueueBackoff` (1m → 5m → 30m → 2h → 6h, max 5)
  - Auto-disable webhook setelah 10 consecutive failures (anti zombie endpoint)
  - Idempotency via UUID `event_id` + UNIQUE constraint `(webhook_id, event_id)`
  - Response body capture 1KB pertama untuk debug
- `App\Controllers\Api\V1\WebhookController` — admin CRUD lengkap:
  - `GET /webhooks` paginated list, hide `secret_enc` raw
  - `POST /webhooks` create + return secret SEKALI (warning di message)
  - `GET /webhooks/{id}`, `PUT`, `DELETE` dengan multi-tenant scope
  - `POST /webhooks/{id}/rotate-secret` untuk security rotation
  - `GET /webhooks/{id}/deliveries` riwayat delivery filterable by status
  - `GET /webhooks/event-types` untuk dropdown UI

**Migration `#041 CreateWebhooks`**
- Table `webhooks`: id, company_id, name, url, event_types (JSON), secret_enc (encrypted), is_active, last_success_at, last_failure_at, consecutive_failures
- Table `webhook_deliveries`: id (BIGINT), webhook_id, company_id, event_type, event_id (UUID), payload (LONGTEXT), status (QUEUED/SENDING/DELIVERED/FAILED), http_status, response_body (TEXT), attempts, next_retry_at, last_attempt_at, delivered_at, last_error
- Index strategis: `(status, next_retry_at)` untuk processQueue, `(company_id, created_at)` untuk admin filter
- UNIQUE composite `(webhook_id, event_id)` untuk idempotency

**Service registration**
- `Services::webhook()` shared instance untuk `WebhookDispatcher`

**Routes registered (`Config/Routes.php`)**
- `/webhooks/event-types`, `/webhooks` (CRUD), `/webhooks/{id}/rotate-secret`, `/webhooks/{id}/deliveries`

**Apply foundation Sprint 10+ ke caller existing**
- `BpjsCalculator::loadRates()` — wired pakai CacheService dengan TTL 1 jam:
  - Method `fetchRatesFromDb()` di-extract sebagai callback (testable + non-cache fallback)
  - Cache key: `keyBpjsRates(company_id)` — invalidate manual saat admin update rates
  - Graceful: kalau `service('cacheService')` tidak available (test env), fallback langsung ke DB
- `BpjsCalculator::compute()` — adopt `EmploymentStatus::isJkpEligible()` constant (replace inline logic)

**Documentation**
- `docs/WEBHOOKS.md` — panduan lengkap (470+ line):
  - Setup admin (curl create + rotate secret)
  - Anatomy HTTP delivery (headers, body)
  - Verification examples 3 bahasa (Node.js/Python/PHP)
  - Retry strategy + auto-disable mechanism
  - Idempotency consumer pattern
  - HTTP response expectation per status code
  - 13 event types dengan trigger description
  - Operations: cron job, monitoring, troubleshooting
  - Security & limits

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/WebhookSignerTest.php` | 11 | sign returns headers, default time, verify valid/invalid sig, tampered body rejection, expired timestamp, within-tolerance accept, future-window reject, generateSecret hex format & uniqueness, replay tolerance constant range |
| `tests/Unit/ConstantsTest.php` (extend) | +4 | WebhookEvent module.action pattern (validate ALL via regex), isValid case-sensitive, filterValid drops unknown/non-string, constants exist |

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **349 passed**, 1 warning, 0 failed (334 sebelumnya + 15 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, full project analysis) |
| `php spark migrate` | migration #041 applied clean (additive, idempotent) |

### Cara pakai webhook end-to-end

```bash
# 1. Admin create webhook
curl -X POST https://api.hris.example.com/api/v1/webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Accurate Sync",
    "url": "https://customer-app.com/webhooks/hris",
    "event_types": ["leave.approved", "payroll.period_finalized"]
  }'
# → response.data.secret muncul SEKALI, simpan di consumer .env

# 2. Code dalam HRIS (mis. saat leave approved):
service('webhook')->dispatch(
    WebhookEvent::LEAVE_APPROVED,
    $companyId,
    [
        'leave_id'      => $leave['id'],
        'employee'      => ['id' => $emp['id'], 'name' => $emp['name']],
        'start_date'    => $leave['start_date'],
        'end_date'      => $leave['end_date'],
        'approved_at'   => date('c'),
    ]
);
# → INSERT row di webhook_deliveries dengan status QUEUED

# 3. Cron worker process queue:
*/1 * * * * php spark webhooks:process --batch=50
# (Spark command perlu dibuat — Sprint 12+; sementara panggil
#  service('webhook')->processQueue() dari long-running worker)

# 4. Customer terima POST + verify signature (3 bahasa, lihat docs/WEBHOOKS.md)
```

### Apply CacheService ke BpjsCalculator

```php
// SEBELUM (Sprint 10): direct DB query setiap call
private function loadRates(int $companyId): array {
    return $db->table('bpjs_rates')->where('company_id', $companyId)->get()->getRowArray()
           ?? self::defaultRates();
}

// SESUDAH (Sprint 11+): cached, TTL 1 jam
private function loadRates(int $companyId): array {
    $cache = service('cacheService');
    return $cache->remember(
        $cache->keyBpjsRates($companyId),
        CacheService::TTL_HOUR,
        fn () => $this->fetchRatesFromDb($companyId),
    );
}
```

Saat admin update BPJS rates, panggil:
```php
service('cacheService')->forget(
    service('cacheService')->keyBpjsRates($companyId)
);
```

### Item yang sengaja ditunda (Sprint 12+)

- **Spark command `webhooks:process`** — saat ini panggil manual atau dari worker. Tinggal wrap di `BaseCommand`.
- **Wire dispatch ke event handler existing** — `LeaveController::approve`, `EmployeeController::create`, dll harus panggil `service('webhook')->dispatch()`. Foundation siap, tinggal tambah satu line per controller.
- **Spark command `cache:warm`** — pre-populate cache umum (UMP/UMK current year, holidays, rates) saat boot supaya cold start tidak lambat.
- **Webhook inbound** — terima trigger dari sistem external (mis. recruitment marketplace post job).
- **Accounting integration** — Accurate/Jurnal.id direct API client (di atas webhook foundation).
- **Pre-commit hooks** — Husky + lint-staged + php-cs-fixer + prettier (defer karena perlu setup Node deps di backend).
- **Spark command + scheduler API** — automate cron menjadi `php spark schedule:run` setiap menit (CI4 4.5+ support).
- **WhatsApp provider integration** (Wablas/Fonnte/Twilio) — pakai pattern sama dengan WebhookChannel.
- **Migrate controller existing pakai Constants** — bertahap, refactor PR per controller.

### Statistik komulatif (Sprint 1 → 11+)

| Metric | Sprint 9 | Sprint 10+ | Sprint 11+ |
|---|---|---|---|
| Backend tests | 312 | 334 | **349** |
| PHPStan errors | 0 | 0 | 0 |
| Backend libraries | ~32 | 34 | **37** |
| Migrations | 39 | 40 | **41** |
| Constants classes | 0 | 4 | **5** |
| Backend controllers | 65+ | 65+ | **66+** |
| Public docs | OpenAPI + Docker | + 4 ADR | **+ Webhooks guide** |

### Next sprint (Sprint 12+)

Beberapa pilihan:

**A. Apply lanjutan**: wire webhook dispatch ke event handler existing (LeaveController, EmployeeController, PayrollService), buat spark command `webhooks:process`, tambah `EmailQueueBackoff` apply ke email queue (sudah Sprint 7), bench load test.

**B. Inbound integration & external connectors**: Accounting (Accurate REST API client di atas webhook), Slack notification channel, OAuth login (Google Workspace SSO untuk korporasi).

**C. Pre-commit hooks & developer ergonomics**: Husky + lint-staged + php-cs-fixer + Prettier konsistensi format, semantic-release untuk auto-changelog dari commit.

**D. Modul baru P3**: Shift Swap, Survey, Bonus Engine, atau modul lain berdasar prioritas customer riil.

---

## ✅ Sprint 12 — Selesai (2026-05-22)

**Apply lanjutan webhook foundation aktif.** Spark command untuk cron,
cache warming command, dan wire dispatch ke event handler real (LeaveController)
sudah live.

### Yang ditambah

**Spark commands (`backend/app/Commands/`)**
- `ProcessWebhooks` (`webhooks:process`) — process delivery queue dengan exit code aware:
  - Default batch 50, override via `--batch=N`
  - Ringkasan ms + sent/failed/skipped count
  - Exit 1 kalau semua failed (untuk cron monitoring alert)
  - Output color-coded (green/yellow/red)
- `WarmCache` (`cache:warm`) — pre-populate cache umum:
  - UMP per provinsi untuk year (default current)
  - Holidays per company × year
  - BPJS rates per company
  - Payroll settings per company
  - Filter via `--year=YYYY` & `--company=N`
  - Defensive: skip table yang tidak exist (pre-migration env)

**Wire webhook dispatch ke caller (`LeaveController::approve`)**
- Setelah notification + sebelum audit log, dispatch `WebhookEvent::LEAVE_APPROVED`
- Payload include employee info (id/nik/name), leave_type code, dates, days, approved_by/at
- Defensive try/catch — webhook failure tidak break approval flow utama
- Log warning kalau dispatch gagal (untuk monitoring)
- Pattern reusable untuk controller lain (paste-able 1-liner)

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **349 passed**, 0 failed (no test changes — sanity check kompilasi controller) |
| `composer stan` (backend) | **OK No errors** (level 0, full project) |

### Cara pakai

```bash
# Cron job webhook delivery (tambah ke crontab production)
* * * * * cd /var/www/hris/backend && php spark webhooks:process --batch=50 \
            >> /var/log/hris/webhooks.log 2>&1

# Atau long-running worker:
while true; do php spark webhooks:process --batch=100; sleep 30; done

# Warm cache saat container boot atau setelah deploy
php spark cache:warm                      # current year, all companies
php spark cache:warm --year=2027          # forecast
php spark cache:warm --company=5          # 1 company saja

# Webhook trigger otomatis saat leave approved (LeaveController):
# Customer subscribe `leave.approved` akan terima POST dengan signature
# HMAC + body lengkap (employee, dates, approved_by, dst)
```

### Pattern wire dispatch ke controller lain

Sengaja didemo di `LeaveController::approve` saja sebagai contoh konkret.
Untuk controller lain (Employee, Payroll, Overtime, dll), apply pattern
yang sama:

```php
// Setelah action utama sukses (sebelum audit log):
try {
    Services::webhook()->dispatch(
        \App\Constants\WebhookEvent::EMPLOYEE_CREATED,
        $companyId,
        [
            'event'       => \App\Constants\WebhookEvent::EMPLOYEE_CREATED,
            'company_id'  => $companyId,
            // ... payload spesifik event
            'created_at'  => date('c'),
        ],
    );
} catch (\Throwable $e) {
    log_message('warning', 'Webhook dispatch X failed: ' . $e->getMessage());
}
```

### Item yang sengaja ditunda (Sprint 13+)

- Wire webhook dispatch ke 20+ event handler lain (Employee, Payroll, Overtime, Recruitment, Disciplinary, Severance) — pattern siap, tinggal apply per controller
- Apply CacheService ke `Pph21TerCalculator::categoryFromPtkp` & `LeaveBalanceService` (lookup TER rates + leave types per company)
- Slack notification channel — pakai pattern sama dengan WebhookChannel (special webhook target)
- Inbound webhook (terima trigger dari sistem external)
- Accounting integration (Accurate/Jurnal.id REST client)
- Pre-commit hooks + semantic-release
- OAuth SSO (Google Workspace / Microsoft 365)
- Modul baru P3 (Shift Swap, Survey, Bonus Engine)

### Statistik kumulatif (Sprint 1 → 12)

| Metric | Sprint 9 | Sprint 10+ | Sprint 11+ | Sprint 12 |
|---|---|---|---|---|
| Backend tests | 312 | 334 | 349 | **349** |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Backend libraries | ~32 | 34 | 37 | **37** |
| Spark commands | ~10 | ~10 | ~10 | **12** |
| Migrations | 39 | 40 | 41 | **41** |
| Public docs | + Docker | + 4 ADR | + Webhooks | **+ Sprint 12 patterns** |

### Next sprint (Sprint 13+)

Pilihan dengan ROI tertinggi:

**A. Bulk wire webhook event handlers** — apply pattern Sprint 12 ke 20+ controller (Employee, Payroll, Overtime, Recruitment). Effort kecil per controller (~5 baris), impact besar (semua event terexpose).

**B. Pre-commit hooks + semantic-release** — improvement developer ergonomics yang cepat di-setup (1 hari) dengan dampak panjang (CI noise turun, format konsisten).

**C. Inbound webhook + Accounting integration** — perlu effort lebih (auth strategy, mapping field), tapi value tinggi untuk customer enterprise.

**D. Modul fitur baru P3** — pilih 1 modul (mis. Shift Swap atau Bonus Engine) berdasar kebutuhan customer; full feature dari migration sampai UI.

---

## ✅ Sprint 13+ — Selesai (2026-05-22)

**Bulk webhook wiring + Pre-commit hooks foundation aktif.** Trait helper
`DispatchesWebhooks` di-extract supaya dispatch jadi 1-liner, diapply
ke 4 event handler (Leave approve/reject, Overtime approve, Employee
create), plus Husky + lint-staged + Prettier setup untuk frontend
auto-format pre-commit.

### Yang ditambah

**Refactoring foundation — `App\Traits\DispatchesWebhooks`**
- Trait reusable untuk controller dengan helper `dispatchWebhook(eventType, companyId, payload)`
- Auto-augment payload dengan `event`, `company_id`, `dispatched_at` supaya consumer tidak perlu cek field dasar di setiap event
- Defensive try/catch + log warning — controller flow tidak break kalau dispatch gagal
- Idempotent UUID auto-generate (override-able via parameter)
- Pattern yang dulunya 25 baris kode di Sprint 12 sekarang jadi 5-10 baris per call site

**Bulk wire webhook ke 4 event handler**
- `LeaveController::approve` — refactor pakai trait, payload tetap (employee, leave_type, dates, days, approved_by/at)
- `LeaveController::reject` — wire baru: dispatch `leave.rejected` dengan reason
- `OvertimeController::approve` — wire baru: dispatch `overtime.approved` dengan hours, compensation_type, amount
- `EmployeeController::create` — wire baru: dispatch `employee.created`. **Penting**: payload sengaja exclude PII sensitif (NIK/NPWP/rekening); consumer hanya butuh id + employee_number + nama untuk sync.

**Pre-commit hooks (frontend)**
- `frontend/.prettierrc.json` — config Prettier konsisten dengan style project (single quote, no semi, trailing comma es5, 100 char width)
- `frontend/.prettierignore` — exclude dist/, node_modules/, generated PWA files, markdown
- `frontend/.husky/pre-commit` — script yang panggil `npx lint-staged` saat commit
- `frontend/package.json` — tambah:
  - `format` & `format:check` scripts (Prettier)
  - `prepare` script untuk Husky auto-init
  - DevDeps: `husky`, `lint-staged`, `prettier`, `eslint-config-prettier`
  - `lint-staged` config: ESLint --fix + Prettier --write untuk file staged
- `frontend/.eslintrc.cjs` — extend `'prettier'` (eslint-config-prettier) supaya rule format-related di-disable di ESLint, biarkan Prettier yang format

**Setup hooks (sekali setelah `npm install`)**
- `cd frontend && npm install` (akan auto-trigger `prepare` script)
- `cd frontend && npx husky init` (sekali untuk register hooks ke `.git/hooks/`)
- Bypass kalau perlu WIP commit: `git commit --no-verify`

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **349 passed**, 0 failed (no test changes — sanity check kompilasi 3 controller wired) |
| `composer stan` (backend) | **OK No errors** (level 0, full project) |

### Cara pakai DispatchesWebhooks trait

```php
class EmployeeController extends ApiController
{
    use \App\Traits\DispatchesWebhooks;

    public function create()
    {
        // ... insert logic ...

        $this->dispatchWebhook(
            \App\Constants\WebhookEvent::EMPLOYEE_CREATED,
            $companyId,
            [
                'employee_id'     => $id,
                'employee_number' => $insert['employee_number'],
                // payload spesifik event...
            ],
        );
        // → trait auto-add `event`, `company_id`, `dispatched_at`
        // → exception handling internal, controller tidak crash kalau
        //   webhook table tidak ada / cache miss / etc
    }
}
```

### Pre-commit workflow

```bash
# Saat developer commit:
git add src/views/Login.vue
git commit -m "feat(login): add 2FA backup code field"

# Husky auto-run pre-commit:
#   1. lint-staged ambil file yang di-stage
#   2. ESLint --fix di-jalankan (auto-fix simple issue)
#   3. Prettier --write format ulang
#   4. File hasil fix di-restage
#   5. Commit dilanjutkan kalau tidak ada error tersisa
#
# Kalau ada error yang tidak bisa auto-fix (mis. unused variable, type
# error), commit di-abort dengan pesan jelas. Developer fix dulu, lalu
# coba commit lagi.

# Bypass kalau perlu (WIP commit):
git commit --no-verify -m "wip: experimental"
```

### Item yang sengaja ditunda (Sprint 14+)

- **Wire webhook ke event handler sisa** (15+): JIC, EmployeeTransfer, Severance, Disciplinary (issue/revoke), Recruitment (candidate_hired, posting_published), Resignation, Payroll period_finalized & paid, Payslip published, Attendance anomaly_flagged, dst
- **Backend pre-commit hooks**: php-cs-fixer atau Pint untuk format PHP konsisten (defer karena perlu setup tooling tambahan di host)
- **CI prettier check**: tambah `npm run format:check` ke pipeline supaya PR tidak boleh merge kalau format tidak sesuai
- **commitlint** untuk enforce Conventional Commits (`feat:`, `fix:`, `chore:`, dst)
- **semantic-release** untuk auto-changelog dari conventional commits
- **Inbound webhook** (terima trigger external)
- **Accounting integration** (Accurate/Jurnal.id REST client)
- **OAuth SSO** (Google Workspace / Microsoft 365)
- **Modul baru P3** (Shift Swap, Survey, Bonus Engine)

### Statistik kumulatif (Sprint 1 → 13+)

| Metric | Sprint 11+ | Sprint 12 | Sprint 13+ |
|---|---|---|---|
| Backend tests | 349 | 349 | **349** |
| PHPStan errors | 0 | 0 | 0 |
| Backend libraries | 37 | 37 | **37** |
| Backend traits | 0 | 0 | **1** (DispatchesWebhooks) |
| Spark commands | ~10 | 12 | **12** |
| Migrations | 41 | 41 | **41** |
| Webhook event handlers wired | 0 | 1 (Leave approve) | **4** (Leave approve+reject, OT approve, Employee create) |
| Frontend pre-commit | ❌ | ❌ | **✅ Husky + lint-staged + Prettier** |
| Frontend devDeps | 8 | 8 | **12** (+husky, lint-staged, prettier, eslint-config-prettier) |

### Next sprint (Sprint 14+)

Direkomendasikan jalankan paralel:

**Track A — Bulk apply tracking** (1-2 hari): wire webhook ke 15+ event handler sisa pakai trait. Effort 5-10 baris per call site. Setelah selesai, semua mutating action HRIS bisa di-stream ke external system.

**Track B — Backend format consistency** (1 hari): tambah `pint` (Laravel Pint, kompatibel dengan CI4) atau `php-cs-fixer` ke pre-commit hook backend. Pakai PSR-12 standard.

**Track C — Inbound webhook foundation** (2-3 hari): table `webhook_inbound`, signed URL endpoint untuk consumer, dispatch ke handler registry per event type. Foundation untuk Accounting integration ke depan.

**Track D — Modul fitur baru** (>1 minggu per modul): pilih 1 modul P3 prioritas tinggi (Shift Swap atau Bonus Engine) untuk full implementation.

---

## ✅ Sprint 14+ — Selesai (2026-05-23)

**Inbound webhook foundation aktif + bulk-wire 8 event handler outbound.**
Sistem external sekarang bisa kirim event masuk ke HRIS (signed-HMAC,
replay-protected, idempotent). Outbound dispatch sudah tersebar ke leave
request, overtime request/reject, payroll finalize, severance finalize/paid,
disciplinary issue, dan candidate hire — total 12 event handler aktif (4 dari
Sprint 13 + 8 baru).

### Yang ditambah

**Inbound webhook (Track C — counterpart untuk outbound)**
- `App\Libraries\InboundWebhookHandler` — pure registry + verify + dispatch:
  - `register(eventType, callable)` daftarkan handler (multiple boleh per event)
  - `dispatch(eventType, payload, source)` — best-effort, handler yang throw
    di-log + flag failed tapi handler lain tetap eksekusi
  - `verifySignature(source, body, sig, ts, now?)` — wrapper `WebhookSigner::verify`
    + decrypt secret dari `inbound_webhooks.secret_enc`
  - `ipAllowed(source, ip)` — exact match + CIDR-aware IPv4 (range /0-/32)
  - Pure class — log fallback via `function_exists('log_message')` supaya
    testable di pure PHPUnit tanpa CI4 helpers
- Migration `#042 CreateInboundWebhooks` (sudah ada, di-wire di sprint ini):
  - Table `inbound_webhooks` (slug UNIQUE global, secret_enc, allowed_ips JSON)
  - Table `inbound_webhook_events` (idempotency UNIQUE `(inbound_id, source_event_id)`)
- `App\Controllers\Api\V1\InboundWebhookController`:
  - **Receiver public**: `POST /api/v1/inbound/{slug}` — verify signature
    + IP allowlist + filter event_types + idempotency check + dispatch handler
  - **Admin CRUD** (JWT-protected): list, create, show, update, delete,
    rotate-secret, events log
  - HTTP status spesifik: 200 PROCESSED / 202 RECEIVED no-handler / 401 sig /
    403 IP / 404 slug / 422 event-not-subscribed / 409 duplicate idempotency
- Routes: receiver di luar grup JWT (auth via signature) dengan throttle
  bucket baru `inbound_webhook` (60 req/menit per IP+slug); admin CRUD di
  dalam grup JWT
- ErrorCode tambahan: `webhook_signature_invalid`, `webhook_source_inactive`,
  `webhook_handler_failed`, `operation_failed`
- Service registration: `Services::inbound()` shared instance

**Bulk-wire outbound (Track A — pakai trait `DispatchesWebhooks` Sprint 13)**
8 event handler baru di-wire (effort 10–15 baris per call site):

| Controller / Method | Event yang dispatched |
|---|---|
| `LeaveController::create` | `leave.requested` |
| `OvertimeController::create` | `overtime.requested` |
| `OvertimeController::reject` | `overtime.rejected` |
| `PayrollPeriodController::finalize` | `payroll.period_finalized` (+ payslip count + total net) |
| `SeveranceController::finalize` | `severance.finalized` |
| `SeveranceController::markPaid` | `severance.paid` |
| `DisciplinaryController::create` | `disciplinary.sp_issued` |
| `RecruitmentController::hireCandidate` | `recruitment.candidate_hired` (no PII credential) |

Pattern konsisten via trait — 5–10 baris per call site, defensive try/catch
di trait sehingga gagal dispatch tidak break controller flow utama.

**Documentation**
- `docs/WEBHOOKS.md` major rewrite:
  - Restructure jadi 2 section: **Outbound** + **Inbound**
  - Inbound section ~120 baris: setup flow, signature contoh Node.js,
    response code matrix, idempotency strategy, handler registration pattern,
    audit log, rotate secret, limits
  - Pertahankan Outbound docs lama (verifier 3 bahasa, retry strategy, dll)

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/InboundWebhookHandlerTest.php` | 13 | Registry: hasHandler/register/dispatch single & multiple handler, fail-resilient (one throws → others tetap run); IP allowlist: no-filter, exact, CIDR /8 & /24, mixed, invalid entry rejected; Signature: missing headers, valid sig, tampered body, wrong secret, expired timestamp |

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **362 passed**, 1 warning, 0 failed (349 sebelumnya + 13 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 47 file analyzed) |
| `composer ci` | both green |

### Cara pakai end-to-end

**Inbound — vendor kirim event ke HRIS**:

```bash
# 1. Admin daftarkan source
curl -X POST https://api.hris.example.com/api/v1/inbound-webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"name":"Talenta","source":"talenta","event_types":["candidate.applied"]}'
# → response.url + response.secret SEKALI

# 2. HRIS daftarkan handler (sekali di bootstrap)
service('inbound')->register('candidate.applied', function ($payload, $source) {
    service('recruitment')->createCandidateFromInbound([
        'job_posting_id' => $payload['job_posting_id'] ?? null,
        'full_name'      => $payload['candidate']['name'],
        'email'          => $payload['candidate']['email'],
    ]);
});

# 3. Vendor (sender) kirim event signed
const sig = 'sha256=' + hmacSha256(`${ts}.${rawBody}`, secret)
fetch(URL, { method:'POST', headers:{
  'X-HRIS-Signature': sig,
  'X-HRIS-Timestamp': ts,
  'X-HRIS-Source-Event': uuid(),
}, body: rawBody })
# → HRIS verify → handler eksekusi → 200 PROCESSED
```

**Outbound — wire baru di Sprint 14**:
- Karyawan ajukan cuti → consumer dapat `leave.requested` realtime
- HR finalize payroll → consumer dapat ringkasan `payroll.period_finalized`
- HR terbitkan SP → consumer dapat `disciplinary.sp_issued` (untuk audit /
  HR analytics integration)
- HR hire candidate → consumer dapat `recruitment.candidate_hired` (no PII
  credential — hanya identifier)

### Item yang sengaja ditunda (Sprint 15+)

- Wire 12+ event handler sisa: `employee.updated/resigned/rejoined`,
  `payroll.paid`, `payslip.published`, `thr.generated`, `leave.canceled`,
  `attendance.late_absent` & `attendance.anomaly_flagged` (cron-driven),
  `attendance.change_approved`, `recruitment.posting_published`,
  `disciplinary.sp_revoked`
- Bootstrap registry handler default (mis. untuk built-in source: Talenta,
  Mekari, Glints) — bisa lewat `App\Config\Events.php`
- Spark command `inbound:replay` untuk re-process event RECEIVED yang belum
  ada handler
- HMAC scope per-event (kalau vendor mau secret berbeda per channel)
- IPv6 support untuk `allowed_ips` (saat ini hanya IPv4)

### Statistik kumulatif (Sprint 1 → 14+)

| Metric | Sprint 12 | Sprint 13+ | Sprint 14+ |
|---|---|---|---|
| Backend tests | 349 | 349 | **362** |
| PHPStan errors | 0 | 0 | 0 |
| Backend libraries | 37 | 37 | **38** (InboundWebhookHandler) |
| Backend controllers | 66+ | 66+ | **67+** (InboundWebhookController) |
| Backend traits | 0 | 1 | 1 |
| Migrations | 41 | 41 | **42** (CreateInboundWebhooks wired) |
| Webhook outbound handlers wired | 1 | 4 | **12** |
| Webhook inbound foundation | ❌ | ❌ | **✅ end-to-end** |
| Throttle buckets | 5 | 5 | **6** (+inbound_webhook) |
| Public docs | + Sprint 12 patterns | + pre-commit | **+ Inbound section di WEBHOOKS.md** |

### Next sprint (Sprint 15+)

Dengan integration platform sudah lengkap dua arah, fokus berikutnya bisa:

**A. Apply integrations real** — ambil 1-2 vendor target (mis. Talenta sync,
Glints job posting publish, Accurate accounting export), bikin handler
inbound + wire outbound dispatch yang relevan, end-to-end test dengan
sandbox vendor.

**B. Wire outbound handler sisa** — 12+ event yang masih belum di-wire,
effort 5–10 baris per controller, satu PR per modul.

**C. Modul fitur baru P3** — Shift Swap, Bonus Engine, Skills Inventory,
atau Survey/Pulse — pilih berdasar prioritas customer.

**D. Backend pre-commit hooks** — Laravel Pint atau php-cs-fixer + commitlint,
selesaikan developer ergonomics yang Sprint 13 tunda.

---

## ✅ Sprint 15+ — Selesai (2026-05-23)

**Webhook outbound coverage hampir lengkap + backend code formatter
foundation.** Wire 6 event handler baru ke trait `DispatchesWebhooks`
(employee.updated/resigned, thr.generated, disciplinary.sp_revoked,
attendance.change_approved, recruitment.posting_published) plus Laravel
Pint untuk backend PHP code formatting (PSR-12 + custom rules).

### Yang ditambah

**Bulk-wire outbound (Track B Sprint 14 — 6 controller baru)**

| Controller / Method | Event yang dispatched | Trigger |
|---|---|---|
| `EmployeeController::update` | `employee.updated` (signifikan) atau `employee.resigned` (saat resign_date diset) | Detect 12 field signifikan: employment_status/type, branch/department/position/grade/manager, is_active, employee_number, email, phone, contract_end_date, probation_end_date. Skip update minor (last_login, dst). |
| `PayrollController::generateThr` | `thr.generated` | Setelah finalize hitung THR + audit log; payload include period_year, pay_date, employee_count, total_gross |
| `DisciplinaryController::revoke` | `disciplinary.sp_revoked` | Setelah HR revoke SP (status REVOKED), payload include reason + revoked_by |
| `AttendanceChangeRequestController::approve` | `attendance.change_approved` | Setelah HR approve change request (transaksi atomic dengan attendance update) |
| `ResignationController::approve` (HR final-step) | `employee.resigned` | Beda dengan EmployeeController dispatch — di sini payload include `resignation_id`, `last_working_date`, `reason_category`, `mpp_auto_created`, `tasks_generated` (rich event untuk source-of-truth voluntary resignation) |
| `CareerController::publishJob` | `recruitment.posting_published` | Saat HR publish posting ke career portal — payload include `public_url_path` + slug, useful untuk auto-mirror ke job board external (LinkedIn, Glints, Kalibrr) |

Total wired outbound handler: **18** (Sprint 13: 4 + Sprint 14: 8 + Sprint 15: 6).

Catatan design:
- `employee.updated` & `employee.resigned` mutually exclusive di EmployeeController — kalau resign_date baru diset → fire `resigned` saja, bukan keduanya
- Resignation HR-approve dispatch event terpisah dengan reference id-nya, supaya consumer bisa link voluntary resign ke workflow resignation request
- Payload sengaja exclude PII detail (NIK/NPWP/rekening/alamat KTP) — consumer hanya butuh signal + identifier, detail bisa di-pull via API kalau benar-benar perlu (least-privilege)

**Backend pre-commit / format consistency (Track D Sprint 13 — closed)**
- `backend/composer.json`:
  - `require-dev`: `laravel/pint: ^1.18` (PHP code formatter, kompatibel dengan CodeIgniter — bukan dependency runtime Laravel)
  - Scripts baru: `composer format` (auto-fix) & `composer format:check` (CI mode, exit 1 kalau ada drift)
- `backend/pint.json`:
  - Preset PSR-12 base
  - Custom rules: short array syntax, single quotes, ordered imports (alpha), blank line sebelum return/throw/try, opening brace `{` di next line untuk class/function/method (sesuai gaya project), no_unused_imports auto-cleanup
  - Exclude: `writable/`, `public/`, `tests/_support/`
  - notPath: `app/Database/Migrations/`, `app/Database/Seeds/` (style migration sengaja beda — preserve)

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **362 passed**, 1 warning, 0 failed (no test changes — sanity check kompilasi 6 controller wired) |
| `composer stan` (backend) | **OK No errors** (level 0) |
| `composer ci` | both green |

### Cara pakai pre-commit (setelah `composer install` di backend)

```bash
# Format manual di seluruh repo
cd backend
composer format
# → Pint akan fix code style di app/, tests/ kecuali yang di-exclude

# Check di CI / pre-commit (no autofix, exit 1 kalau ada drift)
composer format:check

# Run sebelum commit di workflow lokal:
composer format:check && composer ci
```

**Wire ke pre-commit hook**: tambah ke `.git/hooks/pre-commit` atau extend
husky setup Sprint 13 untuk panggil `composer format:check` di staged
PHP files. Contoh git hook minimal:

```sh
#!/bin/sh
# .git/hooks/pre-commit
cd backend && composer format:check
```

**CI integration**: tambah step di `.github/workflows/ci.yml` setelah
`composer install` — `composer format:check` (defer wiring ke CI sampai
seluruh codebase sudah di-format dulu sekali manual via `composer format`,
supaya tidak block PR yang belum di-format).

### Statistik kumulatif (Sprint 1 → 15+)

| Metric | Sprint 13+ | Sprint 14+ | Sprint 15+ |
|---|---|---|---|
| Backend tests | 349 | 362 | **362** |
| PHPStan errors | 0 | 0 | 0 |
| Backend libraries | 37 | 38 | 38 |
| Backend controllers | 66+ | 67+ | 67+ |
| Backend traits | 1 | 1 | 1 |
| Migrations | 41 | 42 | 42 |
| Webhook outbound handlers wired | 4 | 12 | **18** |
| Webhook event types totally available | 24 | 24 | 24 |
| Backend code formatter | ❌ | ❌ | **✅ Laravel Pint (PSR-12 + custom)** |
| Composer scripts | 5 | 5 | **7** (+format, format:check) |

### Event yang masih belum di-wire (defer Sprint 16+)

| Event | Lokasi handler tepat | Note |
|---|---|---|
| `payroll.paid` | PayrollService::finalize (sudah dispatch period_finalized) | Bisa skip — period_finalized = paid di flow finalize |
| `payslip.published` | Per-payslip publish action | Defer — saat ini payslip auto-published bersamaan period_finalized; perlu separate publish flow kalau distribusi diatur per-batch |
| `leave.canceled` | LeaveController::cancel (belum exist) | Tunggu fitur self-cancel dari `Section 6 Leave Management` checklist — saat ini cuti hanya bisa di-cancel oleh HR via reject |
| `employee.rejoined` | Re-hire flow (belum exist) | Defer — modul re-hire belum implement, biasanya pakai create employee biasa |
| `overtime.canceled` | DELETE /overtimes/{id} sudah set status CANCELLED | Skip — tidak ada di WebhookEvent::ALL constant; bisa di-tambah di sprint berikutnya kalau diminta consumer |
| `attendance.late_absent` | Cron-driven event | Defer — perlu dedicated spark command yang scan attendance + emit event |
| `attendance.anomaly_flagged` | AnomalyController::review action di flag positive | Tinggal 1 wire, defer Sprint 16+ |

### Item yang sengaja ditunda (Sprint 16+)

- Wire 7 event sisa (lihat tabel di atas)
- CI integration `composer format:check` step (perlu seluruh codebase di-format
  ulang sekali manual dulu sebelum bisa diaktifkan di CI tanpa block PR)
- commitlint untuk Conventional Commits enforcement
- semantic-release untuk auto-changelog
- Pre-commit hook yang gabung Husky (frontend) + custom shell (backend) jadi
  satu entry point — bisa pakai pre-commit framework (Python) atau
  husky-hooks-monorepo
- Apply Pint format ke seluruh codebase existing (one-shot, hati-hati supaya
  diff-nya isolated dari logic changes)

### Next sprint (Sprint 16+)

Mode **maintenance & polish** — semua foundation sudah solid (security,
compliance, integration platform 2 arah, formatter, CI, Docker). Pilihan:

**A. Wire outbound handler sisa** (1-2 jam): tabel di atas, kebanyakan
single-line dispatch.

**B. Apply Pint sekali ke seluruh codebase** (½ hari): `composer format`
di seluruh repo, review diff per-folder, commit jadi 1 PR "chore: apply
Pint formatter — no logic changes". Setelah merge, tambah
`composer format:check` ke CI.

**C. Modul fitur baru P3** — Shift Swap, Bonus Engine, Skills Inventory,
atau Survey/Pulse — pilih 1 modul prioritas customer.

**D. External integration concrete** — pilih 1 vendor (mis. Accurate),
implement REST client di atas webhook foundation, bikin handler inbound
+ outbound mapping yang relevan. End-to-end test sandbox.

---

## ✅ Sprint 16+ — Selesai (2026-05-23)

**Track A wrap-up + Modul P3 baru (Bonus Engine).** Wire 1 event
outbound terakhir yang masih praktis (`attendance.anomaly_flagged`),
plus full implementasi modul Bonus Engine sebagai modul P3 pertama
yang fully baru: migration + 2 library + 1 controller + 19 endpoint +
26 unit test. 27 webhook event types (3 baru ditambah untuk bonus run
lifecycle).

### Yang ditambah

**Wire outbound terakhir (Track A — 1 event)**
- `AnomalyController::review` → `attendance.anomaly_flagged`
  - Hanya fire kalau anomaly_score ≥ 30 dan keputusan reviewer ≠ CLEARED
    (tidak spam consumer untuk false-positive yang sudah dikonfirmasi)
  - Payload include attendance_id, employee_id, anomaly_score,
    anomaly_reasons, decision (FLAGGED/REJECTED/dst), reviewer_note

Total wired outbound: **19 dari 27 event** (Sprint 13: 4 + 14: 8 + 15: 6 + 16: 1 + bonus 3).

**Modul Bonus Engine (P3 baru — full implementasi)**

Database (`#043 CreateBonusEngine`):
- Table `bonus_schemes` — definisi skema reusable. Field penting:
  - `kind` ENUM (ANNUAL/KPI/MILESTONE/SIGNON/RETENTION/DISCRETIONARY)
  - `formula_type` ENUM (FIXED_AMOUNT/PCT_OF_BASIC/PCT_OF_GROSS/TENURE_BASED/KPI_SCORE/FORMULA_EXPR)
  - `formula_value` decimal(15,4), `formula_expr` TEXT untuk type custom
  - `cap_amount` decimal nullable
  - `eligibility_json` TEXT untuk filter karyawan
  - `tax_treatment` ENUM (GROSS/NET/NON_TAXABLE)
- Table `bonus_runs` — instance pelaksanaan, workflow status:
  `DRAFT → CALCULATED → APPROVED → PAID` (atau `CANCELLED` dari mana saja kecuali PAID)
  - Field audit per transition: calculated_by/at, approved_by/at, paid_by/at, cancelled_by/at
- Table `bonus_recipients` — line item per karyawan, snapshot full
  (employee_number, employee_name, department, tenure, monthly_salary saat hitung)
  + flag `amount_overridden` & `is_excluded` untuk HR adjustment manual

Library (`backend/app/Libraries/`):
- `BonusCalculator` — pure pure compute helpers:
  - `compute(scheme, emp, refDate)` → array{amount, capped, breakdown}
  - 6 formula types lengkap (FIXED_AMOUNT, PCT_OF_BASIC/GROSS, TENURE_BASED, KPI_SCORE, FORMULA_EXPR)
  - `FORMULA_EXPR` pure math evaluator dengan tokenize → shunting-yard → RPN.
    Variable: `basic`, `gross`, `tenure_months`, `tenure_years`, `kpi_score`, `value`.
    Operator: `+ - * /` + `( )`. Defensive: tolak fungsi PHP, eval, ekspresi
    non-numerik → return 0.
  - `checkEligibility(elig, emp, refDate)` → array{eligible, reason}
    dengan filter department/branch/grade/position whitelist + min_tenure_months
    + exclude_resigned + employment_status whitelist
  - `tenureMonths(joinDate, resignDate, refDate)` 30-day approximation,
    cap ke resign_date kalau lebih kecil dari ref
  - Negative amount auto-clamp ke 0; KPI_SCORE clamp `[0, 2]` defensive
- `BonusService` — orchestrator DB-bound:
  - `calculateRun(runId)` resolve eligibility (SQL hard-filter + per-row check) +
    compute amount via Calculator + INSERT batch ke recipients + recompute aggregate
  - Idempotent — boleh re-calc selama status DRAFT/CALCULATED (recipient lama dihapus)

Controller (`BonusController` — 19 endpoint):
- Schemes CRUD + `/preview` (compute pure tanpa save)
- Runs CRUD + `/calculate` + `/approve` + `/mark-paid` + `/cancel`
- Recipients list + override (`PUT /bonus-recipients/{id}`) dengan cap_amount enforcement
- Permission terpisah: `bonus.view`, `bonus.manage`, `bonus.approve` (4-eyes principle)

WebhookEvent constant — 3 event baru:
- `bonus.run_calculated` (saat calculate sukses, payload include count + total + skipped)
- `bonus.run_approved` (saat APPROVED, severity CRITICAL)
- `bonus.run_paid` (saat PAID, severity CRITICAL)

Routes terdaftar di `Config/Routes.php` dengan pattern static-first (preview di atas
/(:num) supaya tidak ke-trap sebagai resource id).

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/BonusCalculatorTest.php` | 26 | 6 formula types × scenario realistis, cap enforcement (capped + below limit), tenure boundary (full year, join after ref, resign mid-period, null), eligibility (no filter, dept whitelist match/miss, min_tenure gate, exclude_resigned before/after ref, employment_status whitelist, combined filters), FORMULA_EXPR security (rejects eval/PHP code, bad syntax, negative clamp), constants exposed |

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **388 passed**, 1 warning, 0 failed (362 sebelumnya + 26 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 49 file analyzed) |
| `composer ci` | both green |

### Cara pakai end-to-end (Bonus Engine)

```bash
# 1. HR daftarkan skema reusable
curl -X POST .../bonus-schemes -d '{
  "code": "ANN-2027",
  "name": "Annual Bonus 2027",
  "kind": "ANNUAL",
  "formula_type": "PCT_OF_BASIC",
  "formula_value": 100,
  "cap_amount": 50000000,
  "eligibility": {"employment_status": ["PKWTT"], "min_tenure_months": 12, "exclude_resigned": true}
}'

# 2. Buat run + calculate
curl -X POST .../bonus-runs -d '{
  "bonus_scheme_id": 1, "name": "Annual 2027 Wave 1",
  "reference_date": "2027-12-31", "pay_date": "2028-01-15"
}'
curl -X POST .../bonus-runs/12/calculate
# → 145 recipients, total Rp 1.75 milyar; webhook bonus.run_calculated fired

# 3. HR review + override individual
curl -X PUT .../bonus-recipients/892 -d '{"amount": 5000000, "reason": "Q4 adjustment"}'

# 4. HR senior approve (separate role)
curl -X POST .../bonus-runs/12/approve
# → status APPROVED, webhook bonus.run_approved

# 5. Finance mark paid
curl -X POST .../bonus-runs/12/mark-paid
# → status PAID, webhook bonus.run_paid
```

Lihat `docs/BONUS_ENGINE.md` untuk panduan lengkap (formula type detail,
eligibility filter, audit trail, security guard).

### Statistik kumulatif (Sprint 13 → 16+)

| Metric | Sprint 14+ | Sprint 15+ | Sprint 16+ |
|---|---|---|---|
| Backend tests | 362 | 362 | **388** |
| PHPStan errors | 0 | 0 | 0 |
| Backend libraries | 38 | 38 | **40** (+BonusCalculator, BonusService) |
| Backend controllers | 67+ | 67+ | **68+** (+BonusController) |
| Migrations | 42 | 42 | **43** (+CreateBonusEngine) |
| Webhook event types | 24 | 24 | **27** (+bonus.run_calculated/approved/paid) |
| Webhook outbound handlers wired | 12 | 18 | **19** (+attendance.anomaly_flagged) |
| Constants classes | 5 | 5 | 5 |
| Public docs (sprint-driven) | 8 | 9 | **10** (+BONUS_ENGINE.md) |

### Item yang sengaja ditunda (Sprint 17+)

- **Auto-merge bonus → payslip** — saat ini `bonus_runs.payroll_period_id`
  field ada tapi PayrollService belum auto-include bonus saat finalize.
  HR masih harus add komponen BONUS manual via PayrollComponent.
- **Bonus tax compute** — `tax_treatment` di scheme baru flag deklaratif;
  belum di-pipe ke Pph21TerCalculator. Defer sampai ada permintaan customer.
- **Frontend admin UI** untuk Bonus Engine — saat ini hanya backend API.
- **Wire 5 event outbound sisa**: `payroll.paid`, `payslip.published`,
  `leave.canceled`, `employee.rejoined`, `attendance.late_absent`.
  Beberapa dependent ke fitur yang belum ada (LeaveController::cancel,
  re-hire flow, cron command late_absent).
- **Apply Pint sekali ke seluruh codebase** — defer ke chore PR terpisah
  supaya diff isolated dari logic changes.
- **CI integration** `composer format:check` step.
- **commitlint + semantic-release** untuk auto-changelog dari conventional commits.

### Next sprint (Sprint 17+)

Mode tetap **maintenance + feature additive**. Pilihan:

**A. Bonus Engine UI** — Vue admin views untuk schemes & runs (5-7 view: list,
form, run detail, recipients table). Effort sedang, 1-2 hari.

**B. Auto-merge bonus → payslip** — wire BonusService ke PayrollService
finalize flow. Bonus dengan `payroll_period_id` di-set otomatis appear
di payslip employee tersebut.

**C. Modul P3 baru** — Shift Swap atau Skills Inventory atau Survey/Pulse.

**D. External integration concrete** — pilih 1 vendor target (Accurate,
Talenta, Glints), implement REST client + handler inbound + outbound
dispatch, end-to-end test sandbox.

**E. Pre-commit consolidation** — gabungkan Husky frontend + custom hook
backend (Pint) jadi entry point single, plus commitlint enforcement
Conventional Commits.

---

## ✅ Sprint 17+ — Selesai (2026-05-23)

**Auto-merge Bonus → Payslip + Self-cancel Cuti aktif.** Bonus run yang
sudah APPROVED/PAID dengan `payroll_period_id` matching otomatis di-pick
saat `PayrollService::calculatePeriod`, masuk sebagai earning kategori
`BONUS` dengan respect ke `tax_treatment`. Plus self-cancel cuti
(`LeaveController::cancel`) dengan trail audit lengkap dan webhook
outbound `leave.canceled`.

### Yang ditambah

**Auto-merge Bonus → Payslip (key feature Sprint 17+)**

`BonusService::bonusesForPayrollPeriod(int $payrollPeriodId)` (extension
point baru):
- Lookup bonus_recipients yang JOIN ke bonus_runs APPROVED/PAID dengan
  `payroll_period_id` matching
- Filter `is_excluded=0` + `bonus_runs.deleted_at IS NULL`
- Return shape `array<int, {amount:float, breakdown:array}>` keyed by
  employee_id
- Multiple run di period yang sama auto-aggregate (annual + KPI +
  retention dalam satu Januari)
- JOIN ke `bonus_schemes` expose `tax_treatment` (NORMAL/GROSS_UP/
  NET/NON_TAXABLE) di breakdown — dipakai PayrollService untuk routing
  taxable

`BonusCalculator::bonusEarningEntries(?array $bonusInfo)` (pure helper):
- Konversi bonus run breakdown → array earning entries siap di-append
  ke payslip
- Tax treatment mapping:
  - `NORMAL` / `GROSS` / null / empty → `code=BONUS, name=Bonus, is_taxable=1`
  - `NON_TAXABLE` → `code=BONUS_NT, name=Bonus (Non-Pajak), is_taxable=0`
  - `GROSS_UP` / `NET` → fallback ke taxable=1 (defer iteration ke Sprint 18+)
- Aggregate per tax_treatment (multi run NORMAL → 1 line BONUS, mixed
  NORMAL+NON_TAXABLE → 2 line terpisah)
- Defensive: null/empty/zero/negative input → return `[]`
- Pure function, mudah unit-test

`PayrollService::calculatePeriod` integrasi:
- Query bonus sekali di luar loop employee (efisien — N+1 prevented)
- Pass per-employee `$bonusInfo` ke `calculateEmployee()` via parameter
  baru `?array $bonusInfo = null` (optional, BC-safe)
- Bonus entry append ke `$earnings`, `bonusTotal` ditulis ke kolom baru
  `payslips.bonus_amount`
- Saat tax_treatment NORMAL → otomatis masuk grossTaxable → PPh21 TER
  hitung dengan benar
- Saat NON_TAXABLE → contributed ke gross_earning tapi skip
  grossTaxable (mis. tunjangan kelahiran, dst)
- Defensive try/catch — bonus lookup gagal (tabel belum ada di test
  env) tidak break payroll calculate

Migration `#045 AddPayslipBonusAmount`:
- Tambah kolom `payslips.bonus_amount` DECIMAL(15,2) DEFAULT 0
- Position `after reimbursement_amount` (logical grouping)
- Idempotent (cek `fieldExists` sebelum apply)
- Additive-only sesuai ADR #0004

**Self-cancel Cuti (sudah deployed dari mid Sprint 17)**

`LeaveController::cancel($id)`:
- Pemilik cuti boleh cancel sendiri kalau status PENDING / APPROVED
  dan belum > 2 hari berjalan (otherwise must HR-only path)
- HR cancel atas nama karyawan boleh kapan saja (status PENDING/APPROVED)
- Validasi alasan minimal 5 karakter (cegah cancel tanpa alasan)
- Side effect saat cancel APPROVED:
  - Restore saldo cuti (used -= days, remaining += days) — hanya untuk
    leave_type yang reset_yearly + ada quota
  - Hapus mark `attendances.status = LEAVE` untuk hari yang BELUM
    terjadi (≥ today). Hari yang sudah lewat dipertahankan supaya audit
    attendance historis tidak rusak — HR yang putus apakah perlu
    koreksi via AttendanceChangeRequest
- Notif:
  - Kalau HR cancel → notif ke karyawan
  - Kalau karyawan cancel APPROVED → notif ke approver yang dulu setujui
- Webhook `leave.canceled` dispatched dengan payload lengkap (employee,
  leave_type, dates, days, reason, cancelled_by, cancelled_by_hr flag)
- Audit log severity WARNING kalau cancel APPROVED (impact saldo + attendance)

Migration `#044 AddLeaveCancelFields`:
- Tambah kolom `cancellation_reason VARCHAR(500)`, `cancelled_by INT`,
  `cancelled_at DATETIME` di `leaves`
- Status `CANCELLED` adalah string ENUM existing yang sudah didukung
  (column-type migration tidak perlu di SQLite)

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/BonusEarningEntriesTest.php` | 15 | null/empty/zero/negative → []; amount tanpa breakdown → default NORMAL taxable; NORMAL/GROSS/empty/lowercase → taxable; NON_TAXABLE → BONUS_NT non-taxable; mixed NORMAL+NON_TAXABLE → 2 entries; multiple NORMAL runs aggregated; GROSS_UP fallback taxable; zero amount in breakdown skipped; fractional rounding |

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **403 passed**, 1 warning, 0 failed (388 sebelumnya + 15 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 50 file analyzed) |
| `composer ci` | both green |
| `php spark migrate` | migration #044 + #045 applied clean |

### Cara pakai end-to-end (Auto-merge Bonus)

```bash
# 1. HR bikin bonus run linked ke payroll period bulan Januari 2027
curl -X POST .../bonus-runs -d '{
  "bonus_scheme_id": 1,
  "payroll_period_id": 12,        # ← key: link ke period
  "name": "Annual 2026 - Januari 2027 Wave",
  "reference_date": "2026-12-31",
  "pay_date": "2027-01-25"
}'

# 2. Calculate + approve
curl -X POST .../bonus-runs/15/calculate
curl -X POST .../bonus-runs/15/approve

# 3. HR calculate payroll period 12 (Januari 2027)
curl -X POST .../payroll-periods/12/calculate
# → Setiap karyawan di bonus_recipients otomatis dapat earning BONUS
#   di payslip-nya. Bonus dengan tax_treatment NORMAL masuk ke
#   grossTaxable → PPh21 hitung benar. NON_TAXABLE skip dari taxable.

# 4. Re-calculate aman (bonus tidak double-count karena recipients
#    di-cleared & re-fetched)
curl -X POST .../payroll-periods/12/calculate

# Cek hasil
curl .../payslips?period_id=12 | jq '.data[] | {employee_name, bonus_amount, gross_earning, pph21_amount}'
```

### Tax behavior matrix

| `tax_treatment` di scheme | Earning code | is_taxable | Kontribusi ke grossTaxable | PPh21 impact |
|---|---|---|---|---|
| NORMAL (default) | BONUS | 1 | ya | naik proporsional via TER |
| GROSS | BONUS | 1 | ya | sama dengan NORMAL |
| NON_TAXABLE | BONUS_NT | 0 | tidak | tidak naik |
| GROSS_UP | BONUS | 1 | ya | naik (Sprint 18+: full gross-up dengan iteration) |
| NET | BONUS | 1 | ya | naik (Sprint 18+: net→gross conversion) |

GROSS_UP & NET di Sprint 17+ behavior sama dengan NORMAL — defensif
supaya pajak tidak under-collected. Sprint 18+ akan implement proper
iteration untuk gross-up (employer absorb pajak) & net→gross conversion.

### Item yang sengaja ditunda (Sprint 18+)

- **GROSS_UP iteration** — algorithm yang bikin take-home tepat sebesar
  bonus nominal (employer absorb pajak): hitung `gross = bonus + tax(gross)`
  via fixed-point iteration (biasanya converge dalam 2-3 step)
- **NET→GROSS conversion** — sebelum masukkan ke payslip, convert dari
  nominal "yang diterima karyawan" ke nominal sebelum pajak
- **Frontend Bonus Engine UI** — admin views CRUD scheme + run + recipient
  table + override modal (5-7 view)
- **Frontend Payslip detail** — tampilkan section BONUS terpisah dengan
  breakdown per-run
- **Bonus history per karyawan** — timeline page yang show semua bonus
  yang pernah diterima (ANNUAL/KPI/MILESTONE/dst) — useful untuk
  retention / merit review
- **`employee.rejoined` event** — wire saat re-hire flow ada (defer:
  modul re-hire belum implement)
- **`attendance.late_absent` event** — perlu cron `php spark
  attendance:emit-late-events` yang scan tiap pagi
- **Apply Pint sekali ke seluruh codebase** — chore PR terpisah
- **CI integration** `composer format:check` step
- **commitlint + semantic-release** untuk auto-changelog

### Statistik kumulatif (Sprint 13 → 17+)

| Metric | Sprint 14+ | Sprint 15+ | Sprint 16+ | Sprint 17+ |
|---|---|---|---|---|
| Backend tests | 362 | 362 | 388 | **403** |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Backend libraries | 38 | 38 | 40 | 40 |
| Backend controllers | 67+ | 67+ | 68+ | 68+ |
| Migrations | 42 | 42 | 43 | **45** (+ AddLeaveCancelFields, AddPayslipBonusAmount) |
| Webhook event types | 24 | 24 | 27 | 27 |
| Webhook outbound handlers wired | 12 | 18 | 19 | **20** (+leave.canceled wired di self-cancel flow) |
| Auto-merge bonus → payslip | ❌ | ❌ | ❌ (foundation) | **✅ live** |
| Self-cancel cuti | ❌ | ❌ | ❌ | **✅ live** |
| Public docs | + ADR | + pre-commit | + Bonus Engine | + Sprint 17+ patterns |

### Next sprint (Sprint 18+)

Pilihan dengan ROI tertinggi:

**A. Bonus Engine UI** — Frontend admin views untuk schemes & runs
+ payslip detail render section BONUS. 1-2 hari, langsung pakai oleh HR.

**B. GROSS_UP & NET tax treatment** — Implement iteration algorithm di
PayrollService supaya tax_treatment selain NORMAL/NON_TAXABLE bekerja
benar. Effort sedang, perlu integration test PPh21.

**C. Modul P3 baru** — Shift Swap, Skills Inventory, Survey/Pulse,
Petty Cash, atau Health Insurance Claim — pilih berdasar prioritas
customer.

**D. External integration concrete** — ambil 1 vendor target (Accurate,
Talenta, Glints), implement REST client + handler inbound + outbound
dispatch yang relevan. End-to-end test sandbox.

**E. Apply Pint + CI integration** — one-shot format seluruh codebase
existing, lalu enforce di CI. Chore PR terpisah supaya diff isolated.

---

## ✅ Sprint 18+ — Selesai (2026-05-23)

**GROSS_UP & NET tax-treatment iteration aktif.** Bonus dengan
`tax_treatment` GROSS_UP atau NET sekarang dihitung pakai Newton-Raphson
fixed-point untuk solve gross dari net target. Algorithm pure, mudah
unit-test, kompatibel dengan TER bracket maupun progressive smooth.
Wired ke `PayrollService::calculatePeriod` lewat `BonusCalculator::applyGrossUp`.

### Yang ditambah

**Newton-Raphson gross-up iteration (key feature Sprint 18+)**

`Pph21TerCalculator::iterateGrossUp(targetNet, taxFunc, baseGross, maxIter, epsilon)` (static, pure):
- Solve `f(G) = (G - taxAdded(G)) - targetNet = 0` dengan Newton-Raphson
- Numerical derivative `f'(G) ≈ 1 - marginalRate` via small perturbation
  (h = max(1, 0.01% gross))
- Konvergensi:
  - Flat tax → 1 iterasi (linear function)
  - TER bracket → 1-2 iterasi (piecewise constant)
  - Progressive smooth → 3-5 iterasi
  - Default `maxIter=10` + `epsilon=Rp1` cukup tight untuk semua skenario realistis
- Defensive:
  - `targetNet ≤ 0` → return `gross_up_amount=0, iterations=0, converged=true`
  - `marginalRate ≥ 1` (singular f') → bail out
  - Pathological tax (`tax > gross`) → tetap return last guess + `converged=false`

`Pph21TerCalculator::grossUpMonthlyFromNet(targetNet, baseGrossMonthly, ptkp, year)` (instance):
- Convenience wrapper yang inject TER monthly sebagai taxFunc
- Pakai untuk bonus regular Jan-Nov yang di-merge ke payslip bulanan

**Bonus engine pre-process gross-up**

`BonusCalculator::applyGrossUp(bonusInfo, taxFunc, baseGross)` (static, pure):
- Pre-process breakdown: untuk entry dengan `tax_treatment` GROSS_UP atau NET,
  call `iterateGrossUp` dan rewrite `amount` jadi effective gross
- Entry NORMAL/GROSS/NON_TAXABLE → passthrough tanpa modifikasi
- Output structure compatible dengan `bonusEarningEntries()` — chain
  langsung di PayrollService
- Audit fields ditambah ke breakdown: `original_amount`, `tax_added`,
  `converged`, `iterations`, `effective_treatment` (mark sudah jadi gross)
- Aggregate `gross_up_total` returned untuk reporting/notif

**`BonusCalculator::bonusEarningEntries()` — extended**:
- Sekarang handle alias normalisasi GROSS_UP/NET → NORMAL setelah pre-processing
  (kalau caller belum pre-process, fallback ke taxable=1 supaya aman)

**`PayrollService::calculatePeriod` — wire**:
- Detect kalau bonusInfo mengandung GROSS_UP atau NET → call `applyGrossUp`
  dengan `taxFunc` = TER monthly + `baseGross` = sum earnings yang taxable
  (basic + allowance + overtime, sebelum bonus di-append)
- Kalau semua entries NORMAL/NON_TAXABLE → skip iteration (efficiency)
- Hasil gross-up appear sebagai `BONUS` earning di payslip dengan
  amount = effective gross. PPh21 TER hitung otomatis dengan benar
  karena gross sudah masuk grossTaxable

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/Pph21GrossUpTest.php` | 10 | zero/negative target → 0; flat 5%/30% closed-form; TER bracket low/high; with baseGross marginal-rate; bracket-jump skenario; progressive smooth tax; non-convergent pathological; epsilon precision control |
| `tests/Unit/BonusApplyGrossUpTest.php` | 9 | null/empty passthrough; NORMAL/NON_TAXABLE pass-through; NET 5% & GROSS_UP 30% gross-up; mixed NORMAL+NET partial gross-up; baseGross influences marginal rate; zero amount skip |

Total tests baru: **19**.

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **422 passed**, 1 warning, 0 failed (403 sebelumnya + 19 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 51 file) |
| `composer ci` | both green |

### Cara pakai end-to-end (Bonus NET tax-treatment)

```bash
# 1. HR daftarkan scheme dengan tax_treatment NET (employer absorb pajak)
curl -X POST .../bonus-schemes -d '{
  "code": "RETENTION-NET-2027",
  "name": "Retention Bonus Net (Employer Absorb Tax)",
  "kind": "RETENTION",
  "formula_type": "FIXED_AMOUNT",
  "formula_value": 5000000,
  "tax_treatment": "NET",
  "eligibility": {"employment_status": ["PKWTT"], "min_tenure_months": 24}
}'

# 2. Buat run + calculate + approve
curl -X POST .../bonus-runs -d '{
  "bonus_scheme_id": 1,
  "payroll_period_id": 12,
  "name": "Retention Q1 2027",
  "reference_date": "2027-03-31",
  "pay_date": "2027-03-25"
}'
curl -X POST .../bonus-runs/16/calculate
# → setiap recipient dapat amount 5_000_000 (net target di breakdown)
curl -X POST .../bonus-runs/16/approve

# 3. Calculate payroll period 12
curl -X POST .../payroll-periods/12/calculate
# → PayrollService detect tax_treatment NET di breakdown
# → applyGrossUp() compute effective gross via Newton-Raphson:
#     - Karyawan A (gaji 10jt, kena TER 5%): gross-up ≈ 5_263_158
#     - Karyawan B (gaji 50jt, kena TER 15%): gross-up ≈ 5_882_353
# → BONUS earning amount = effective gross (taxable=1)
# → PPh21 TER auto-naik proporsional → karyawan tetap terima net 5jt

# Cek breakdown payslip:
curl .../payslips/123 | jq '.data.breakdown.earnings[] | select(.code == "BONUS")'
# {"code":"BONUS","name":"Bonus","category":"BONUS","amount":5263158,"is_taxable":1}
```

### Tax behavior matrix Sprint 18+ (final)

| `tax_treatment` di scheme | Pre-processing | Code | is_taxable | grossTaxable | Net diterima karyawan |
|---|---|---|---|---|---|
| NORMAL / GROSS / null | none (passthrough) | BONUS | 1 | ya | scheme_amount × (1 - marginal_rate) |
| NON_TAXABLE | none (passthrough) | BONUS_NT | 0 | tidak | scheme_amount (full) |
| GROSS_UP | applyGrossUp iterate | BONUS | 1 | ya, gross-up'd | scheme_amount (full, employer absorb pajak) |
| NET | applyGrossUp iterate | BONUS | 1 | ya, gross-up'd | scheme_amount (full, sesuai input) |

Note: GROSS_UP & NET di Sprint 18+ behave-equivalent — keduanya converge ke
gross yang setelah pajak ≈ scheme amount. Distinct label dipertahankan
untuk keperluan semantic / audit (mis. "GROSS_UP = sengaja dibayar net
extra" vs "NET = nominal di kontrak adalah net").

### Item yang sengaja ditunda (Sprint 19+)

- **Frontend Bonus Engine UI** — admin views (5-7 view: scheme list/form,
  run list/detail, recipient table, override modal, gross-up preview)
- **Frontend Payslip detail** — render section BONUS terpisah dengan
  breakdown per-run + gross-up indicator (tooltip explain "dipotong pajak X%, employer absorb")
- **Bonus history per karyawan** — timeline page yang show semua bonus
  yang pernah diterima
- **Yearly progressive gross-up** — saat ini iterasi pakai TER monthly.
  Untuk THR / bonus tahunan (Desember reconciliation), perlu pakai
  yearly progressive sebagai taxFunc — wrapper sudah siap di
  `iterateGrossUp` cukup inject `calculateYearlyProgressive`.
- **PPh21 koreksi Desember handle gross-up bonus** — saat finalize
  Desember, bonus gross-up sebelumnya harus dihitung benar di yearly tax
- **`employee.rejoined` event** — wire saat re-hire flow ada
- **`attendance.late_absent` event** — perlu cron command
- **Apply Pint sekali ke seluruh codebase**
- **CI integration** `composer format:check` step
- **commitlint + semantic-release** untuk auto-changelog

### Statistik kumulatif (Sprint 14 → 18+)

| Metric | Sprint 15+ | Sprint 16+ | Sprint 17+ | Sprint 18+ |
|---|---|---|---|---|
| Backend tests | 362 | 388 | 403 | **422** |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Backend libraries | 38 | 40 | 40 | 40 |
| Migrations | 42 | 43 | 45 | 45 |
| Webhook event types | 24 | 27 | 27 | 27 |
| Webhook outbound handlers wired | 18 | 19 | 20 | 20 |
| Auto-merge bonus → payslip | ❌ | foundation | NORMAL/NON_TAXABLE | **+ GROSS_UP/NET iteration** |
| Public docs | + Pint | + Bonus Engine | + Sprint 17+ | **+ Sprint 18+ tax matrix** |

### Next sprint (Sprint 19+)

Semua foundation backend Bonus Engine sudah lengkap. Pilihan terbaik:

**A. Frontend Bonus Engine UI** — UX paling impact. 1-2 hari, Vue admin
views menyusul backend yang sudah lengkap (19 endpoint). Termasuk
preview gross-up untuk bantu HR konfirmasi sebelum approve.

**B. Yearly progressive gross-up** — extend untuk THR & December tax
correction. Effort kecil (wrapper saja, iterateGrossUp sudah generic).

**C. Modul P3 baru** — Shift Swap, Skills Inventory, Survey/Pulse,
Petty Cash, Health Insurance Claim. Pilih berdasar prioritas customer.

**D. External integration** — pilih 1 vendor target (Accurate, Talenta,
Glints), implement REST client + handler inbound + outbound dispatch.

**E. Apply Pint + CI integration** — chore PR terpisah, low-effort,
high-DX-value.

---

## ✅ Sprint 19+ — Selesai (2026-05-23)

**Frontend Bonus Engine UI aktif.** 3 Vue admin views (scheme list +
form modal + preview, run list + form, run detail dengan recipients
table + override) dengan workflow lengkap DRAFT → CALCULATED →
APPROVED → PAID. Sidebar navigation terintegrasi di group Payroll.

### Yang ditambah

**Frontend views (`frontend/src/views/Payroll/Bonus/`)**

`BonusSchemeList.vue` — Master skema bonus (~430 baris):
- Table list dengan filter implicit (per_page 100)
- Form modal create/edit dengan field lengkap:
  - Code + name + kind (6 enum) + tax_treatment (4 enum) +
    formula_type (6 enum) + formula_value + formula_expr
  - Cap amount (opsional)
  - Eligibility section: min_tenure_months, exclude_resigned,
    employment_status checkbox group (PKWTT/PKWT/PROBATION/HARIAN/
    MAGANG/OUTSOURCING)
  - Description + is_active toggle
- Preview modal — call `/bonus-schemes/preview` dengan employee_id +
  reference_date untuk dry-run compute (tanpa save)
- Tax treatment badge color-coded (NORMAL=gray, NON_TAXABLE=green,
  GROSS_UP/NET=yellow)
- Soft-delete dengan konfirmasi (run yang sudah dibuat tidak terpengaruh)

`BonusRunList.vue` — List pelaksanaan (~270 baris):
- Filter status (DRAFT/CALCULATED/APPROVED/PAID/CANCELLED) + tahun
- Per-row info: scheme + period (auto-merge label) + recipients count
  + total amount + status badge
- Form modal create:
  - Pilih bonus scheme (load dari /bonus-schemes is_active=1)
  - Pilih payroll period (load dari /payroll-periods status=DRAFT) —
    optional, untuk auto-merge ke payslip (Sprint 17+ feature)
  - Reference date (untuk hitung tenure) + pay date + notes
  - Auto-redirect ke detail page setelah create
- Cancel button dengan konfirmasi (untuk DRAFT/CALCULATED/APPROVED)

`BonusRunDetail.vue` — Detail run + recipients (~370 baris):
- Header: nama + status badge + scheme + reference + pay date +
  payroll period link (kalau auto-merge)
- Total amount + recipient count card
- Action bar context-aware:
  - **Calculate / Re-calculate** (DRAFT/CALCULATED) — confirm modal
    warn override loss
  - **Approve** (CALCULATED → APPROVED) — confirm with total preview
  - **Mark Paid** (APPROVED → PAID) — confirm "tidak bisa rollback"
- Stats inline: included / excluded / overridden count
- Audit timeline: calculated_at / approved_at / paid_at / cancelled_at
  dengan user reference
- Recipients table:
  - Employee + department + tenure + monthly_salary + amount + status
  - Visual cue untuk overridden (blue) & capped (yellow)
  - Per-row actions (DRAFT/CALCULATED only):
    - Override modal — input amount baru + alasan minimal 5 char (audit)
    - Toggle exclude — `is_excluded` flag (visual opacity 50%)

**Routes** (`frontend/src/router/index.js`):
- `/payroll/bonus/schemes` → BonusSchemeList
- `/payroll/bonus/runs` → BonusRunList
- `/payroll/bonus/runs/:id` → BonusRunDetail

**Sidebar nav** (`frontend/src/layouts/AppLayout.vue` payroll group):
- "Bonus Schemes" (perm `bonus.manage`)
- "Bonus Runs" (perm `bonus.view`)
- Keduanya pakai `BanknotesIcon`

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `npm install` (frontend) | 53 packages added (eslint-config-prettier installed) |
| `npm run lint` (frontend) | **0 errors**, 86 warnings (warnings pre-existing dari view lain) |
| `npm run build` (frontend) | **✓ built in 23.83s** — bundle terupdate, no compilation error |
| `composer test` (backend) | **422 passed** (tidak ada perubahan backend) |
| `composer stan` (backend) | **OK No errors** |

### UX flow end-to-end

```
HR (perm bonus.manage):
  1. Sidebar → Payroll → Bonus Schemes
  2. Buat skema baru ANN-2027 dengan formula PCT_OF_BASIC 100%, cap 50jt,
     eligibility PKWTT min_tenure 12 bulan
  3. Klik tombol Beaker (preview) → input employee_id 42 → Preview
     → keluar amount preview + breakdown steps (validasi formula benar)
  4. Sidebar → Bonus Runs → Run Baru
  5. Pilih scheme + payroll period 12 + run name "Annual 2027 Wave 1"
     → auto-redirect ke detail page
  6. Detail page → Calculate → konfirmasi → eligibility resolve, recipients
     populated (mis. 145 karyawan, total 1.75M)
  7. Review per-row, override karyawan tertentu (klik pencil) atau
     exclude karyawan yang seharusnya tidak dapat
  8. Approve (perlu permission bonus.approve, biasanya HR senior berbeda)

HR senior (perm bonus.approve):
  9. Approve run → status APPROVED, webhook bonus.run_approved fired

Finance:
  10. Setelah transfer real → Mark Paid → status PAID, webhook fired

Karyawan:
  11. Saat HR calculate payroll period 12, bonus auto-merge ke payslip
      (Sprint 17+ feature — already live di backend)
  12. Buka /payslips/my, lihat earning BONUS dengan amount sesuai
      breakdown gross-up/normal
```

### Item yang sengaja ditunda (Sprint 20+)

- **Frontend Payslip detail render section BONUS terpisah** — payslip
  detail page existing belum tampilkan breakdown BONUS yang benar.
  Tinggal cek `payslip.breakdown.earnings` untuk entry kategori `BONUS`,
  display dalam section dengan gross-up indicator (kalau
  `effective_treatment === 'NORMAL'` setelah pre-process tapi original
  treatment GROSS_UP/NET).
- **Bonus history per karyawan** — tab di profile page yang show
  semua bonus yang pernah diterima karyawan dari `bonus_recipients`.
  Useful untuk merit review / promosi context.
- **Yearly progressive gross-up** — Sprint 18+ pakai TER monthly. Untuk
  THR (yearly) perlu wrapper baru di `Pph21TerCalculator` yang inject
  `calculateYearlyProgressive` sebagai taxFunc.
- **PPh21 koreksi Desember handle gross-up bonus** — saat finalize
  Desember, bonus gross-up sebelumnya harus dihitung benar di yearly tax.
- **Modul P3 baru** (Shift Swap, Skills Inventory, Survey/Pulse,
  Petty Cash, Health Insurance Claim).
- **Apply Pint sekali ke seluruh codebase** + CI integration.
- **commitlint + semantic-release** untuk auto-changelog.
- **Employee picker reusable component** — saat ini Bonus Preview pakai
  input number employee_id manual; UX lebih bagus pakai autocomplete
  (typeahead) yang sudah ada di project (cek EmployeeList).
- **Bulk actions di recipients table** — checkbox + bulk exclude,
  untuk run dengan ratusan karyawan.
- **Frontend test untuk Bonus views** — Vitest component test (mount,
  fill form, verify API call). Defer karena prioritas low.

### Statistik kumulatif (Sprint 15 → 19+)

| Metric | Sprint 16+ | Sprint 17+ | Sprint 18+ | Sprint 19+ |
|---|---|---|---|---|
| Backend tests | 388 | 403 | 422 | 422 |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Backend libraries | 40 | 40 | 40 | 40 |
| Frontend Vue views | ~90 | ~90 | ~90 | **~93** (+3 Bonus views) |
| Frontend routes | ~95 | ~96 | ~96 | **~99** (+3 bonus routes) |
| Sidebar nav items | ~45 | ~46 | ~46 | **~48** (+2 bonus links) |
| Migrations | 43 | 45 | 45 | 45 |
| Bonus Engine completeness | backend foundation | + auto-merge payslip | + GROSS_UP/NET tax | **+ admin UI lengkap** |

### Next sprint (Sprint 20+)

Mode tetap **maintenance + feature additive**. Pilihan ROI tertinggi:

**A. Frontend Payslip Detail BONUS section** — tampilkan breakdown
bonus di payslip detail. Quick win 1-2 jam, langsung visible ke
karyawan.

**B. Bonus history per karyawan** — tab timeline di profile page.
Effort sedang, value tinggi untuk HR analytics (merit review prep).

**C. Yearly progressive gross-up wrapper** — extend `iterateGrossUp`
untuk THR. Effort kecil (wrapper saja, algoritma sudah generic).

**D. Modul P3 baru** — Shift Swap, Skills Inventory, Survey/Pulse,
atau Petty Cash. Pilih berdasar prioritas customer.

**E. External integration concrete** — pilih 1 vendor (Accurate /
Talenta / Glints), implement REST client end-to-end.

**F. Apply Pint + CI** — chore PR terpisah, low-effort.

---

## ✅ Sprint 20+ — Selesai (2026-05-23)

**Frontend Payslip BONUS section + Bonus history endpoint + Yearly
progressive gross-up wrapper aktif.** Tiga improvement complementary
yang menutup loop UX Bonus Engine end-to-end: karyawan lihat breakdown
bonus di payslip, HR akses history per-karyawan untuk merit review,
dan THR/bonus tahunan bisa pakai gross-up dengan kurva pajak progresif.

### Yang ditambah

**Frontend Payslip Detail — BONUS section**

`frontend/src/views/Payroll/PayslipDetail.vue`:
- Pisahkan earning kategori `BONUS` ke section terpisah dengan visual distinct
  (amber/gold theme — beda dari pendapatan reguler)
- Per-line tax indicator badge:
  - `BONUS` → biru "Taxable" (ikut grossTaxable, kena PPh21 TER)
  - `BONUS_NT` → hijau "Non-Pajak" (skip dari grossTaxable)
- Total bonus footer pakai kolom `payslips.bonus_amount` (Sprint 17+ schema)
- Section context label "Otomatis dari Bonus Engine (Sprint 17+)" — eksplisit
  beri tahu karyawan asal data
- Auto-hide kalau tidak ada bonus untuk slip ini

**Backend — Bonus history per karyawan**

`BonusService::bonusHistoryForEmployee(int $employeeId, ?int $companyId, array $opts)`:
- Lookup `bonus_recipients` JOIN `bonus_runs` JOIN `bonus_schemes` per employee
- Default filter: status APPROVED/PAID + `is_excluded=0` + `deleted_at IS NULL`
- Optional `include_pending=true` untuk include DRAFT/CALCULATED (HR analytics)
- Ordering: `pay_date DESC, run.id DESC` (terbaru dulu)
- Decode `breakdown_json` di service supaya client tidak perlu parse
- Multi-tenant: optional `companyId` filter (super_admin bisa cross-tenant)
- Limit default 50, max 200

`BonusController::employeeHistory($employeeId)` (`GET /employees/{id}/bonus-history`):
- Authorization: self-access (employee_id == tenant.employee_id) atau permission `bonus.view`
- Self-access force `include_pending=false` — confidential sebelum approved
- Aggregate stats di response: total_amount, count, by_kind (group by ANNUAL/KPI/dst)
- Format response standar HRIS: `{data: {history, stats}}`

**Backend — Yearly progressive gross-up wrapper**

`Pph21TerCalculator::grossUpYearlyFromNet(targetNet, baseGrossYearly, ptkp, year, bpjsYearly)`:
- Convenience wrapper untuk THR / bonus tahunan
- Inject `calculateYearlyProgressive` sebagai taxFunc untuk `iterateGrossUp`
- Sudah include biaya jabatan (5%, max 6jt) + PTKP + progressive bracket
- Use case: HR mau bayar THR net 25 juta → cari gross G yang setelah pajak yearly = 25jt

### Tests yang ditambah

| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/Pph21YearlyGrossUpTest.php` | 6 | Mock taxFunc Pasal 17 UU PPh 2025 (5/15/25/30/35% brackets + biaya jabatan + PTKP TK0). Test cases: bracket low/mid/high, cross-bracket transition, zero-base scenario, convergence quickly (≤10 iter) untuk semua skenario realistis |

Total tests baru: **6**.

Note: `Pph21YearlyGrossUpTest` sengaja test via static `iterateGrossUp` dengan
mock taxFunc, bukan instance method `grossUpYearlyFromNet` — supaya pure-test
tanpa DB stub (yang butuh seed `ptkp_rates` + `pph21_brackets`). Verifikasi
correctness via mathematical identity: `gross_up - tax_added ≈ targetNet`.

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `composer test` (backend) | **428 passed**, 1 warning, 0 failed (422 sebelumnya + 6 baru) |
| `composer stan` (backend) | **OK No errors** (level 0, 52 file analyzed) |
| `npm run build` (frontend) | **✓ built in 5.95s** — bundle compile clean |

### Cara pakai end-to-end

**Karyawan lihat bonus di payslip**:
```bash
# Buka /payslips/123 → section "🎁 Bonus" muncul (kalau ada)
# Setiap line bonus tampil dengan badge tax indicator
```

**HR / employee akses bonus history**:
```bash
# Self-access (karyawan lihat history sendiri)
curl https://api.hris.example.com/api/v1/employees/42/bonus-history \
  -H "Authorization: Bearer $TOKEN"

# Response:
# {
#   "status": "ok",
#   "data": {
#     "history": [
#       {
#         "id": 1234, "amount": 5000000, "scheme_kind": "RETENTION",
#         "scheme_name": "Retention Q1 2027", "pay_date": "2027-03-25",
#         "run_status": "PAID", "tenure_months": 36, "monthly_salary": 10000000,
#         "breakdown": {...}
#       },
#       ...
#     ],
#     "stats": {
#       "total_amount": 27500000,
#       "count": 5,
#       "by_kind": {"ANNUAL": 15000000, "KPI": 7500000, "RETENTION": 5000000},
#       "include_pending": false
#     }
#   }
# }

# HR view dengan permission bonus.view
curl https://api.hris.example.com/api/v1/employees/42/bonus-history?include_pending=true \
  -H "Authorization: Bearer $HR_TOKEN"
```

**HR panggil yearly gross-up untuk THR**:
```php
$pph = service('pph21');
$result = $pph->grossUpYearlyFromNet(
    targetNet: 25_000_000,
    baseGrossYearly: 240_000_000, // gaji setahun excl THR
    ptkp: 'TK0',
    year: 2027,
    bpjsYearly: 0,
);
// $result['gross_up_amount'] = ~28_500_000 (employer absorb pajak ~3.5jt)
```

### Tax behavior matrix Sprint 20+ (dengan yearly support)

| Konteks | TaxFunc | Wrapper |
|---|---|---|
| Bonus regular (Jan-Nov) | TER monthly | `grossUpMonthlyFromNet` (Sprint 18+) |
| THR / bonus tahunan | Yearly progressive (Pasal 17) | `grossUpYearlyFromNet` (Sprint 20+) |
| Custom (mis. severance) | Caller inject | `iterateGrossUp` static (Sprint 18+) |

### Item yang sengaja ditunda (Sprint 21+)

- **Frontend Bonus History tab di profile karyawan** — endpoint sudah siap
  (`GET /employees/{id}/bonus-history`), tinggal tambah Vue component.
  Bisa pakai pola `MyTransfers.vue` yang sudah ada untuk self-access.
- **PPh21 koreksi Desember handle gross-up bonus** — saat finalize
  Desember, bonus gross-up sebelumnya harus dihitung benar di yearly tax.
  Saat ini gross-up bekerja standalone untuk single transaction, tapi
  `calculateDecemberCorrection` belum aware of `tax_added` dari bonus
  gross-up sebelumnya.
- **Wire `grossUpYearlyFromNet` di `PayrollController::generateThr`** —
  saat ini THR generator pakai formula proporsional sederhana (tenure
  bulan × basic / 12). Untuk THR dengan tax_treatment NET, perlu
  iterate yearly progressive. Defer karena scheme khusus untuk THR
  belum ada di DB.
- **Modul P3 baru** (Shift Swap, Skills Inventory, Survey/Pulse,
  Petty Cash, Health Insurance Claim) — pilih berdasar prioritas customer.
- **Apply Pint sekali ke seluruh codebase** — chore PR terpisah.
- **CI integration** `composer format:check` step.
- **commitlint + semantic-release** untuk auto-changelog.
- **OpenAPI spec extension** untuk endpoint Sprint 17+/18+/19+/20+.

### Statistik kumulatif (Sprint 16 → 20+)

| Metric | Sprint 17+ | Sprint 18+ | Sprint 19+ | Sprint 20+ |
|---|---|---|---|---|
| Backend tests | 403 | 422 | 422 | **428** |
| PHPStan errors | 0 | 0 | 0 | 0 |
| Backend libraries | 40 | 40 | 40 | 40 |
| Frontend Vue views | ~90 | ~90 | ~93 | ~93 |
| Frontend routes | ~96 | ~96 | ~99 | ~99 |
| Migrations | 45 | 45 | 45 | 45 |
| Bonus Engine UX coverage | auto-merge backend | + GROSS_UP/NET | + admin UI | **+ payslip render + history endpoint + yearly grossup** |
| Public docs | + Sprint 17+ | + Sprint 18+ | + Sprint 19+ | + Sprint 20+ |

### Next sprint (Sprint 21+)

**A. Frontend Bonus History tab** — Pasangkan dengan endpoint Sprint 20+,
component baru di `Profile.vue` atau `EmployeeModal` (HR view).

**B. Modul P3 baru** — pilih 1 modul prioritas customer:
- Shift Swap (request tukar shift antar karyawan)
- Skills Inventory & Matrix (catat skill + gap analysis)
- Survey/Pulse (engagement survey)
- Petty Cash (kasbon harian + reconciliation)
- Health Insurance Claim (track claim ke asuransi swasta)

**C. Tax engine improvements**:
- December correction handle gross-up bonus
- Wire `grossUpYearlyFromNet` ke THR generator
- Bonus history aware untuk PPh21 koreksi (audit cross-period)

**D. Integration concrete** — pilih 1 vendor (Accurate / Talenta / Glints),
implement REST client end-to-end.

**E. Apply Pint + CI** — chore PR terpisah, low-effort.

**F. OpenAPI spec extension** — extend `docs/openapi.yaml` ke 30+ endpoint
Sprint 16+/17+/18+/19+/20+ (Bonus Engine, leave cancel, gross-up, dst).

---

## ✅ Sprint 22+ — Selesai (2026-05-23)

**THR NET wiring ke yearly progressive gross-up aktif.** Sprint ini fokus
menutup gap yang tersisa dari Sprint 20+: wrapper yearly gross-up sudah ada
tapi belum dipakai di generator THR. Sekarang endpoint `POST /payroll/thr`
mendukung mode `tax_treatment=NET` (employer absorb pajak) dengan perhitungan
progressive tahunan.

### Yang ditambah

**`PayrollController::generateThr` enhancement (Sprint 22+)**

- Input baru:
  - `tax_treatment`: `GROSS` (default, backward-compatible) atau `NET`
- Jika `tax_treatment=NET`:
  - THR nominal dari `computeThrAmount()` diperlakukan sebagai **net target**
  - Hitung `baseGrossYearly = basic_salary * 12`
  - Panggil `Services::pph21()->grossUpYearlyFromNet(...)` untuk cari
    gross THR yang setelah pajak progresif tahunan menghasilkan net target
  - Simpan ke payslip:
    - `thr_amount` = gross THR (hasil gross-up)
    - `pph21_amount` = tax added dari gross-up
    - `total_deduction` = pph21_amount
    - `take_home_pay` = net target (sesuai kontrak)
- Jika `tax_treatment=GROSS`:
  - behavior lama dipertahankan (backward-compatible)
- Update aggregate period:
  - `total_gross`, `total_deduction`, `total_net` sekarang diisi akurat
- Update audit log:
  - description include gross, net, dan mode tax treatment
- Update webhook payload `thr.generated`:
  - tambah `total_deduction`, `total_net`, `tax_treatment`
- Update response payload:
  - `total_gross`, `total_deduction`, `total_net`, `tax_treatment`, `count`

**Validation / guard rails**

- `tax_treatment` invalid langsung fail 422
- Default tetap `GROSS` jika field tidak dikirim → tidak break client lama
- Tidak perlu migration baru (kolom payslip/period yang dipakai sudah ada)

### Tests & verifikasi

- `ThrCalculatorTest` existing tetap hijau:
  - **20/20 pass**
- Full backend suite:
  - **428 tests pass**, 994 assertions, warning framework existing
- PHPStan:
  - **0 errors**
- Frontend (hasil dari sprint sebelumnya tetap valid):
  - `npm run lint`: 0 error (warnings existing)
  - `npm run build`: sukses

### Cara pakai

```bash
# Mode lama (default): GROSS
curl -X POST http://localhost:8080/api/v1/payroll/thr \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"pay_date":"2027-04-15","name":"THR 2027"}'

# Mode baru: NET (employer absorb pajak)
curl -X POST http://localhost:8080/api/v1/payroll/thr \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "pay_date":"2027-04-15",
    "name":"THR 2027 NET",
    "tax_treatment":"NET"
  }'
# Response include:
# - total_gross (grossed-up amount)
# - total_deduction (pph21 absorb)
# - total_net (yang diterima karyawan)
```

### Item yang masih ditunda (Sprint 23+)

- **December correction aware gross-up bonus/THR**
  - saat ini `calculateDecemberCorrection` belum consume histori tax_added
    dari gross-up transaction cross-period
- **Frontend HR view bonus-history in profile/employee modal**
  - endpoint sudah ada, tinggal plug ke tab HR
- **OpenAPI detail untuk endpoint THR NET**
  - docs foundation sudah diperluas, tapi endpoint payroll/thr belum diberi
    schema contoh khusus tax_treatment
- **Pint one-shot + CI `composer format:check`**
- **commitlint + semantic-release**

### Statistik kumulatif (Sprint 20+ → 22+)

| Metric | Sprint 20+ | Sprint 22+ |
|---|---|---|
| Backend tests | 428 | **428** (stable) |
| PHPStan errors | 0 | 0 |
| THR NET progressive gross-up | wrapper only | **wired in generator** |
| THR response payload | basic | **gross/net/deduction + tax_treatment** |
| THR webhook payload | basic | **enriched** |

### Next sprint (Sprint 23+)

1. **Tax engine finalization**: December correction aware gross-up cross-period  
2. **UX completion**: HR bonus-history tab (profile/employee detail)  
3. **DevEx**: enable `composer format:check` in CI after one-shot Pint  
4. **Docs**: OpenAPI endpoint-level examples for payroll THR NET flow  
5. **Optional P3 module**: Shift Swap / Skills Inventory (customer-priority)

---

## ✅ Sprint 23+ — Selesai (2026-05-23)

**HR bonus-history view aktif.** Menutup gap UX dari Sprint 20+: endpoint
`GET /employees/{id}/bonus-history` sudah ada, tapi belum ada admin view
untuk HR. Sekarang HR bisa drill-down per karyawan dari daftar
employees → tombol bonus-history → timeline lengkap (group by tahun)
dengan stats agregat dan toggle include-pending untuk akses run
DRAFT/CALCULATED (confidential, tidak muncul di self-service `MyBonuses`).

### Yang ditambah

**Frontend HR view (`frontend/src/views/Payroll/Bonus/EmployeeBonusHistory.vue`)**

- Standalone page untuk admin/HR (perm `bonus.view`)
- Header card: nama karyawan + back-link ke `/employees`
- Toggle `include_pending`:
  - Default OFF → hanya APPROVED/PAID (sama dengan self-service)
  - ON → tambah DRAFT/CALCULATED (kunci untuk merit review prep — HR
    bisa lihat bonus yang masih dalam proses sebelum communicate ke
    karyawan)
- Stats card 3-grid: total amount, count, breakdown by kind dengan
  badge color-coded
- Timeline group by tahun (terbaru dulu) — table per tahun dengan
  kolom: run-name + scheme code, kind badge, pay date, tax indicator
  (Taxable/Non-Pajak/Gross-Up/Net), amount, status badge
- Loading skeleton + empty state proper
- Reuse pola dari `MyBonuses.vue` (Sprint 21+) untuk consistency, tapi
  HR-specific:
  - Employee dipilih via route param `:id` (bukan auth user)
  - `include_pending` toggle (self-service tidak punya akses)
  - Back-navigation ke daftar karyawan

**Frontend route (`frontend/src/router/index.js`)**

- Path baru: `/employees/:id/bonus-history` → `EmployeeBonusHistory.vue`
- Lazy-loaded, sama pattern dengan view bonus lainnya

**Frontend action button (`frontend/src/views/Employees/EmployeeList.vue`)**

- Tombol icon `GiftIcon` di action column row karyawan
- Gated permission `auth.can('bonus.view')` — hanya HR yang punya
  akses bonus management yang lihat tombol ini
- Klik → `$router.push('/employees/${id}/bonus-history')`
- Tooltip "Bonus history"

### Hasil verifikasi

| Command | Hasil |
|---|---|
| `npm run lint` (frontend) | **0 errors**, 85 warnings (warnings pre-existing dari view lain, tidak baru) |
| `npm run build` (frontend) | **✓ built in 11.82s** — bundle compile clean, 0 compilation error |
| `composer test` (backend) | **428 tests pass**, 994 assertions, 1 warning framework existing (stable, tidak ada perubahan backend) |
| `composer stan` (backend) | **OK No errors** (level 0, 52 file analyzed) |

### Cara pakai end-to-end

```
HR (perm bonus.view):
  1. Sidebar → Employees → buka daftar karyawan
  2. Cari karyawan target (mis. untuk merit review Q2)
  3. Klik tombol GiftIcon di kolom aksi → otomatis navigate ke
     /employees/42/bonus-history
  4. Page load:
     - Header: nama karyawan + back-link
     - Stats card: total bonus selama bekerja, count entry, breakdown
       by kind (ANNUAL Rp15jt, KPI Rp7.5jt, RETENTION Rp5jt, dst)
     - Timeline group by tahun:
       2027 — table: 3 entries (Annual, Q1 KPI, Retention Q1)
       2026 — table: 2 entries (Annual, Mid-year KPI)
       2025 — table: 1 entry (Sign-on)
  5. Toggle "Include pending" ON →
     - Muncul Annual 2027 Wave 2 status DRAFT (belum approved)
     - HR bisa preview yang akan dibayar bulan depan untuk konteks
       merit review
  6. Klik back → kembali ke daftar karyawan

Karyawan (self-service, ada sebelumnya di Sprint 21+):
  - Sidebar → Payroll → My Bonuses → /payroll/my-bonuses
  - Hanya APPROVED/PAID yang ditampilkan (tidak ada toggle pending)
```

### Item yang sengaja ditunda (Sprint 24+)

- **December correction aware gross-up bonus/THR** — tetap pending dari
  Sprint 22+. Saat finalize Desember, bonus gross-up sebelumnya harus
  dihitung benar di yearly tax. Effort sedang, perlu integration test
  PPh21 cross-period. Prioritas naik sekarang karena Bonus Engine UX
  sudah lengkap.
- **OpenAPI detail untuk endpoint bonus-history & THR NET** — endpoint
  sudah live dan terdokumentasi di checklist + BONUS_ENGINE.md, tapi
  belum ada schema example detail di `docs/openapi.yaml` per endpoint.
- **Pint one-shot + CI `composer format:check`** — chore PR terpisah.
- **commitlint + semantic-release** — auto-changelog.
- **Modul P3 baru** — Shift Swap, Skills Inventory, Survey/Pulse,
  Petty Cash, Health Insurance Claim. Pilih berdasar prioritas customer.
- **Bonus history mini-card di EmployeeModal** — alternatif lebih ringan
  (tab di modal employee detail) tapi defer karena standalone page
  lebih scalable untuk data banyak.
- **Frontend test untuk EmployeeBonusHistory** — Vitest component test
  (mount, fill toggle, verify API call). Defer karena prioritas low.

### Statistik kumulatif (Sprint 19 → 23+)

| Metric | Sprint 20+ | Sprint 22+ | Sprint 23+ |
|---|---|---|---|
| Backend tests | 428 | 428 | **428** (stable, no backend change) |
| PHPStan errors | 0 | 0 | 0 |
| Frontend Vue views | ~93 | ~93 | **~94** (+1 EmployeeBonusHistory) |
| Frontend routes | ~99 | ~99 | **~100** (+1 employee bonus-history) |
| Bonus Engine UX coverage | + payslip render + history endpoint + yearly grossup | + THR NET wiring | **+ HR drill-down view** |
| Self-service bonus history | ✅ (MyBonuses) | ✅ | ✅ |
| HR bonus history per-employee | ❌ (endpoint only) | ❌ | **✅ live** |

### Next sprint (Sprint 24+)

Bonus Engine end-to-end sekarang complete: backend + auto-merge +
gross-up + admin UI + HR drill-down + self-service. Pilihan terbaik:

**A. December correction aware gross-up** — paling impact untuk
compliance pajak akurat. Effort sedang. Wajib sebelum tutup tahun
fiskal kalau ada bonus NET/GROSS_UP yang dibayarkan di tahun berjalan.

**B. Modul P3 baru** — Shift Swap (request tukar shift antar karyawan)
atau Skills Inventory (catat skill + gap analysis). Pilih satu dengan
cycle 1-2 minggu.

**C. OpenAPI extension** — extend `docs/openapi.yaml` untuk semua
endpoint Sprint 16+→23+ (Bonus Engine, leave cancel, gross-up, THR NET,
bonus-history). Setengah hari, value tinggi untuk integrator vendor.

**D. Apply Pint one-shot + CI `composer format:check`** — chore PR
terpisah, low-effort, high-DX-value. Eligibility sudah siap (pint.json
config aktif, baseline kosong).

**E. External integration concrete** — pilih 1 vendor (Accurate /
Talenta / Glints), implement REST client end-to-end pakai inbound
webhook handler dan outbound event yang sudah ada.
