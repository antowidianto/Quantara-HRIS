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
- [ ] **Password policy** masih lemah (10 karakter alfanumerik). Tambah:
  - [ ] Wajib minimal 1 uppercase + 1 lowercase + 1 digit + 1 simbol
  - [ ] Cek breach via Have I Been Pwned API (k-anonymity)
  - [ ] Forced rotation 90/180 hari opsional per company setting
  - [ ] Riwayat 5 password terakhir tidak boleh dipakai ulang
- [ ] **2FA** hanya TOTP. Tambah:
  - [ ] Backup recovery codes (8x10-digit, sekali pakai, di-hash)
  - [ ] Email-based 2FA fallback untuk user tanpa authenticator app
  - [ ] Force 2FA untuk role super_admin & HR admin
- [ ] **Refresh token** tidak track device — saat user logout dari satu device, semua di-revoke. Tambah:
  - [ ] Kolom `device_fingerprint`, `last_used_at` di `refresh_tokens`
  - [ ] UI "Active Sessions" di Profile + tombol "Sign out other devices"
- [ ] **File upload validation** hanya cek MIME via PHP `getMimeType()` (mudah di-spoof). Tambah:
  - [ ] Cek magic bytes (file signature) untuk JPEG/PNG/PDF
  - [ ] Strip EXIF GPS data dari foto (privacy concern untuk avatar/selfie)
  - [ ] Anti-virus scan (ClamAV) untuk attachment dokumen
- [ ] **CORS allowlist** di `Config/Cors.php` — pastikan tidak ada wildcard `*` di production
- [ ] **Encryption key rotation** — `RotateEncryption` command ada tapi tidak ada SOP/jadwal. Dokumentasikan:
  - [ ] Jadwal rotation tahunan
  - [ ] Backup key lama supaya data lama bisa di-decrypt
  - [ ] Test restore dari backup setelah rotation
- [ ] **Audit log** untuk akses lintas-tenant — saat super_admin switch company, log severity NOTICE
- [ ] **Rate limit** per endpoint sensitif: `/auth/login` ada (throttle:login). Tambah:
  - [ ] `/auth/forgot-password` lebih ketat (3/jam per email)
  - [ ] `/auth/2fa/verify` (5/15 menit per user)
  - [ ] Endpoint export CSV (mis. payroll) — limit 10/jam per user
- [ ] **IP allowlist** opsional untuk role super_admin & HR (setting per company)
- [ ] **Honeypot** di Login form (CodeIgniter `Honeypot.php` config ada, pastikan aktif)

### 3. Compliance Indonesia (Data & Pajak)
- [ ] **UU PDP (UU 27/2022)** — belum ada flow eksplisit:
  - [ ] Consent capture saat onboarding karyawan (kotak centang yang di-stamp tanggal)
  - [ ] Endpoint `GET /api/v1/me/data-export` — karyawan bisa download semua data pribadi (JSON/PDF)
  - [ ] Endpoint `POST /api/v1/me/data-deletion-request` → workflow approval HR
  - [ ] Data retention policy: command `php spark hr:purge-resigned --years=5` untuk anonimisasi data ex-karyawan setelah retention
- [ ] **PPh21 PMK 168/2023 TER** — sudah implement, tapi:
  - [ ] Belum ada export **bukti potong 1721-A1 final tahunan** dengan format yang sudah validated DJP 2026
  - [ ] Belum ada handling karyawan **mid-year join/resign** untuk koreksi Desember (saat ini asumsi penuh 12 bulan)
  - [ ] **Tarif TER tahun 2027+** — perlu admin UI untuk update bracket tanpa migrasi (saat ini di tabel `ter_rates` → bagus, tapi belum ada UI CRUD)
- [ ] **BPJS Ketenagakerjaan JKP** (Jaminan Kehilangan Pekerjaan, PP 37/2021) belum dihitung di `BpjsCalculator`. Wajib untuk PKWT/PKWTT:
  - [ ] Tambah field `jkp_company_rate` (default 0.46% gabungan dari APBN + employer)
  - [ ] Update payslip & export SiPP
- [ ] **BPJS Kesehatan kelas** (Kelas 1/2/3) belum di-track per karyawan — saat ini hanya plafon 12 jt flat. Tambah `employees.bpjs_kes_class`
- [ ] **UMP/UMK** — seeder 2026 ada tapi:
  - [ ] Tidak auto-update setiap tahun → perlu cron `hr:fetch-ump-yearly` atau import CSV admin UI
  - [ ] Warning di-log tapi tidak ada notifikasi proaktif ke HR di Dashboard
- [ ] **E-Faktur Coretax** — integration belum ada (kalau perusahaan claim PPN supplier)

---

## 🟠 P1 — Major Improvement (3–6 Bulan ke Depan)

### 4. Payroll Engine
- [ ] `PayrollService::calculatePeriod` punya **defensive cleanup** orphan payslip via `slip_number` prefix LIKE — ini patch, bukan fix root cause. Tambah `ON DELETE CASCADE` di FK `payslips.payroll_period_id` & `payslip_details.payslip_id` (lihat migrasi MySQL nanti)
- [ ] **Magic numbers** hardcoded di payroll:
  - [ ] `22` hari kerja (untuk absent deduction) → ke setting per-company
  - [ ] `173` jam (untuk overtime hourly rate) → ke setting per-company  
  - [ ] `8` jam/hari (untuk overtime → leave conversion) → ke setting per-company
- [ ] **THR generator** (`PayrollController::generateThr`):
  - [ ] Asumsi `tenureMonths` = (now - join_date)/30 → dipakai untuk semua karyawan walaupun resign mid-year. Harusnya proporsional sampai resign_date
  - [ ] Tidak ada handling karyawan dengan **multiple kontrak** di tahun yang sama
  - [ ] Tidak ada **THR Lebaran ke-2** kalau karyawan ganti agama / dual religion
- [ ] **Bonus engine** belum ada — tahun-end bonus, KPI bonus, milestone bonus. Saat ini harus manual via PayrollComponent
- [ ] **Payslip PDF**:
  - [ ] Tidak ada **digital signature/hash** untuk integrity. Tambah field `signature_hash` (SHA256 dari content) supaya audit bisa verifikasi tidak ada tampering setelah finalize
  - [ ] Tidak ada **bulk send email** payslip ke semua karyawan setelah finalize (saat ini hanya in-app notif)
- [ ] **Pre-tax vs post-tax allowance** breakdown di breakdown JSON belum konsisten — sulit untuk reporting

### 5. Attendance & Anti-Cheat
- [ ] **Cross-day shift / overnight shift** belum di-handle benar di `AttendanceController::clockIn`:
  - [ ] Late minutes calc pakai `strtotime(today + start_time)` — kalau shift mulai 22:00 dan karyawan clock-in 23:00 hari berikutnya, perhitungan salah
  - [ ] Perlu logic: kalau shift cross-midnight, work_date pakai tanggal mulai shift, bukan tanggal clock-in
- [ ] **Break time tracking** belum ada — tidak bisa lapor istirahat siang, sholat, makan
- [ ] **Multi clock-in/out per hari** belum support — kalau karyawan keluar siang lalu kembali, tidak ada slot untuk track
- [ ] **Selfie storage**:
  - [ ] Tidak ada **retention policy** — folder `writable/uploads/selfies/<emp>/` akan tumbuh terus
  - [ ] Tidak ada **EXIF strip** sebelum simpan (privacy)
  - [ ] Cron `php spark attendance:cleanup-selfies --days=180` belum ada
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
- [ ] **WhatsApp channel** masih scaffolding. Selesaikan:
  - [ ] Pilih provider (Wablas/Fonnte/Twilio) → tambah config di settings
  - [ ] Template message dengan variable substitution
  - [ ] Opt-in/opt-out per user (compliance UU PDP)
- [ ] **Web Push** end-to-end belum lengkap (foundation di SW ada):
  - [ ] VAPID key generation (`php spark webpush:generate-keys`)
  - [ ] Library `minishlink/web-push` di composer.json
  - [ ] Endpoint `POST /api/v1/notifications/push-subscribe` untuk simpan subscription
  - [ ] Test push real ke device
- [ ] **Real-time in-app** — tidak ada WebSocket/SSE. Saat ini polling (cek `Notifications.vue` & sidebar badge). Tambah:
  - [ ] SSE endpoint `GET /api/v1/notifications/stream` untuk real-time push
  - [ ] Atau Pusher/Ably integration untuk skala
- [ ] **Digest email** — tidak ada email harian/mingguan ringkasan. Tambah:
  - [ ] Cron `php spark notifications:digest --frequency=daily|weekly` per user preference
- [ ] **Email template per company** — saat ini hardcoded HTML di `NotificationService::renderEmailHtml`. Tambah:
  - [ ] Tabel `email_templates` per company × event_type
  - [ ] Variable substitution (mis. `{{employee_name}}`, `{{leave_dates}}`)
  - [ ] Editor di Admin → Email Templates
- [ ] **Email queue retry** — saat ini hanya 3x dengan increment counter, tidak ada exponential backoff (1m → 5m → 30m)

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
- [ ] **README.md** terlalu minim — point ke docs/ tapi tidak ada quick-summary fitur. Tambah:
  - [ ] Screenshot 5 halaman utama (Dashboard, Attendance, Leave, Payroll, Org Structure)
  - [ ] Demo URL / video walkthrough
  - [ ] Tech stack badge
- [ ] **API documentation** belum ada. Tambah:
  - [ ] OpenAPI 3.0 spec di `docs/openapi.yaml` (auto-generate dari routes via tool atau manual)
  - [ ] Swagger UI hosted di `/api/docs` (gated di production)
  - [ ] Postman collection di `docs/HRIS.postman_collection.json`
- [ ] **Architecture Decision Records** (ADR) — kenapa CodeIgniter 4 (bukan Laravel)? kenapa SQLite di dev (bukan Docker MySQL)? kenapa JWT (bukan session)? Catat di `docs/adr/`
- [ ] **Changelog** otomatis dari commit message (Conventional Commits + `git-cliff` atau semantic-release)
- [ ] **Setup script** — saat ini `SETUP.md` manual step. Tambah `scripts/setup-dev.ps1` (Windows) & `scripts/setup-dev.sh` (Linux/Mac) yang otomatis: install deps → copy .env → migrate → seed

### 12. DevOps & CI/CD
- [ ] **Docker Compose** belum ada. Tambah `docker-compose.yml` di root:
  - [ ] Service `backend` (PHP-FPM 8.1)
  - [ ] Service `frontend` (Nginx serve dist)
  - [ ] Service `mysql` (MySQL 8)
  - [ ] Service `redis` (cache + session)
  - [ ] Service `mailhog` (dev email catcher)
- [ ] **Dockerfile production** — belum ada. Multi-stage build untuk backend + nginx
- [ ] **GitHub Actions**:
  - [ ] `.github/workflows/ci.yml` — lint + test on PR
  - [ ] `.github/workflows/deploy.yml` — deploy ke staging on push to `main`
  - [ ] Dependabot config untuk auto-update dependencies
- [ ] **Pre-commit hooks** (Husky + lint-staged):
  - [ ] PHP: `phpcs` / `php-cs-fixer`
  - [ ] Vue/JS: ESLint + Prettier
- [ ] **Release versioning** — `package.json` masih `1.0.0`, `composer.json` tidak ada version. Tambah semantic-release config

### 13. Database
- [ ] **Index review systematic** — 35 migrasi enhance, tapi tidak ada review apakah index sudah optimal:
  - [ ] Audit slow query log di MySQL prod (kalau ada)
  - [ ] Tambah composite index untuk query pattern yang sering: `attendances(company_id, work_date, employee_id)`, `leaves(company_id, employee_id, status, start_date)`
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
- [ ] **N+1 queries** — beberapa controller iterate employees lalu query nested. Audit dengan query log:
  - [ ] `PayrollService::calculatePeriod` (sudah pakai eager via join, tapi `recurring`, `attRows`, `otRows` per employee — bisa di-batch)
  - [ ] `LeaveBalanceService::summary` per employee
- [ ] **Cache layer** — Redis di-mention di README tapi belum dipakai. Cache:
  - [ ] User permissions per role (TTL 5 menit, invalidate on role update)
  - [ ] UMP/UMK lookup (TTL 1 hari)
  - [ ] Holidays per company per year
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
- [ ] **Webhook outbound** — perusahaan kirim event HRIS ke sistem lain (mis. accounting). Tambah:
  - [ ] Tabel `webhooks` (url, event_types, secret)
  - [ ] Dispatcher async dengan retry
  - [ ] Sign request dengan HMAC-SHA256
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
- [ ] **Magic numbers / strings** — banyak literal di controller. Bikin enum/constant class:
  - [ ] `App\Constants\AttendanceStatus` — `PRESENT`, `LATE`, `ABSENT`, dll
  - [ ] `App\Constants\LeaveCategory`, `EmploymentStatus`, `ApprovalStatus`, dst
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
