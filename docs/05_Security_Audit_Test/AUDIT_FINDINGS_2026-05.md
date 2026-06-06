# HRIS — Audit Findings & Improvement Plan
> Laporan analisis menyeluruh terhadap aplikasi Quantara-HRIS  
> Tanggal: 28 Mei 2026 · Branch: `main` @ `330a7dd`

> **Status eksekusi (28 Mei 2026):**
> - ✅ **Sprint A** (Stabilization & Safety Net) — selesai. Lihat `docs/SPRINT_A_HARDENING.md`
> - ✅ **Sprint B** (Architecture Phase 1) — fondasi Model + ADR. Lihat `docs/SPRINT_B_ARCHITECTURE.md` dan `docs/decisions/0001-architecture-baseline.md`
> - ✅ **Sprint C** (Compliance Calculators Phase 1) — long sick leave, tenure quota, PPh21 correction. Lihat `docs/SPRINT_C_COMPLIANCE.md`
> - ✅ **Sprint D Phase 1** (Performance) — Circuit Breaker library (D6). Lihat `docs/SPRINT_D_PERFORMANCE.md`.
> - ✅ **Sprint B+1 / D+1** — 5 Model lanjutan (A1 lengkap 10/10) + LookupCache (D4) + CircuitBreaker factory di Services. Lihat `docs/SPRINT_B_PLUS_ONE.md`.
> - ✅ **Sprint E Phase 1** — Frontend test (E3) + bundle analyzer/code-splitting (D5). Lihat `docs/SPRINT_E_FRONTEND.md`.
> - ✅ **Sprint F + G** — DevOps hardening (G2 prod compose, G7 deploy script + guide, G4 backup/DR) dan dokumentasi (I5 ARCHITECTURE, CONTRIBUTING.md). Lihat `docs/SPRINT_F_G_DEVOPS_DOCS.md`.
> - ✅ **Sprint Final** — JKP wiring (C7), THR multi-contract (C2), half-day leave (C10), JobQueue (D7), Telemetry/Sentry (G5). Lihat `docs/SPRINT_FINAL_2026-05.md`.
> - Carry-over (butuh > 1 sprint): A2/A3 controller+route refactor, A8 god-component, E1/E4 integration+E2E test, G6 secret mgmt, I2 doc konsolidasi, C1 admin UI ter_rates/bpjs_rates.
>
> **Test count progression backend:** 754 → 758 (A) → 762 (B) → 825 (C) → 838 (D1) → 847 (B+1/D+1) → **899** (Final).
> **Test count frontend:** 3 → **25** (E1).
> **Total test:** **924** (899 backend + 25 frontend) — **+169 dari baseline awal 755**.

---

## 1. Ringkasan Eksekutif

Quantara-HRIS adalah aplikasi yang **sangat ambisius dan secara fungsional matang** (CodeIgniter 4 + Vue 3, multi-tenant, kepatuhan UU Ketenagakerjaan & UU PDP, 30+ modul, ~349 unit test). Tim sudah melakukan banyak pekerjaan benar: PHPStan + ESLint clean, CI matrix PHP 8.1–8.3 × Node 18–20, 2FA + backup codes, password policy R1–R6, magic-byte file validation, throttle bucket per endpoint, dan dokumentasi sprint yang rapi.

Namun, kematangan **arsitektur dan code quality** belum sebanding dengan kematangan fitur. Aplikasi sudah **mencapai titik di mana hutang teknik mulai melambatkan velocity** dan menambah risiko regresi:

- **343 file PHP** di backend, total **~4.2 MB code**, dengan controller terbesar mencapai **1.504 baris** (`JicController`) dan service layer mencapai **1.186 baris** (`RecruitmentService`).
- **0 folder `Models/`** — semua akses data lewat `Database::connect()->table(...)` langsung. Tercatat **491 panggilan `Database::connect()`** hanya di Controllers.
- **172 file Vue/JS** di frontend, dengan view terbesar **1.517 baris** (`EmployeeModal.vue`) dan **1.503 baris** (`PipelineBoard.vue`).
- **71 migrasi** di satu folder `app/Database/Migrations/` dengan tanggal `2024-01-01-XXXXXX` palsu (sequencing buatan), beberapa bertema sangat besar (`Horizon2/3/4`, `SecurityHardening`, `Sprint*Polish`).
- **0 Feature/Integration test**; ~30 file `backend/scripts/test-*.php` adalah smoke test manual yang **tidak masuk CI**.
- **Frontend test** baru 1 file (`usePwa.spec.js`); coverage komponen view ≈ 0%.

Penilaian: kualitas **fitur 8.5/10**, kualitas **arsitektur 5/10**, kualitas **testing 4/10**, kualitas **docs 9/10**. Aplikasi **bisa go-live**, tapi sebelum scaling tim atau menambah modul baru, hutang arsitektur perlu dilunasi agar tidak terus menumpuk bunga.

---

## 2. Klasifikasi Masalah

Setiap temuan diberi:
- **Severity**: 🔴 Critical · 🟠 High · 🟡 Medium · 🟢 Low
- **Effort**: S (≤1 hari) · M (1–5 hari) · L (1–4 minggu) · XL (>1 bulan)
- **Impact**: keamanan / kebenaran / performa / maintainability / DX

---

### 🅐 ARSITEKTUR & DESIGN

| # | Severity | Masalah | Effort |
|---|---|---|---|
| A1 | 🔴 | **Tidak ada layer Model**. Folder `backend/app/Models/` kosong. 491 panggilan `Database::connect()` langsung dari controllers berarti SQL, validasi, dan business rule menyebar di mana-mana. Tidak ada single source of truth untuk skema, kolom yang boleh di-update, atau scope multi-tenant. | XL |
| A2 | 🔴 | **God Controllers**. Top 10 controllers 700–1.500 baris: `JicController` (1504), `BonusController` (1191), `LeaveController` (1059), `OrgStructureController` (934), `OnlineTestController` (911), `AttendanceController` (886), `ManpowerController` (780). Satu controller seringkali punya 20+ method action + helper privat. Sulit di-test, sulit di-review, dan setiap perubahan kecil punya blast radius besar. | L–XL |
| A3 | 🔴 | **Routes file monolitik 1.053 baris** dengan konvensi posisi-sensitif (`/picker` harus di atas `/(:num)`) yang rapuh dan rawan regresi saat ada developer baru menambah route. Tidak ada modular grouping per domain. | M |
| A4 | 🟠 | **Inkonsistensi otorisasi**. `PermissionFilter` ada dan berfungsi, tapi **tidak satu pun route di `Routes.php` yang memanggil `permission:xxx`**. Cek izin terjadi ad-hoc di dalam controller (`tenant()->hasPermission(...)`), sehingga audit kebijakan akses harus membaca tiap method, bukan satu file routing. | M |
| A5 | 🟠 | **Service layer raksasa**. `RecruitmentService` 1.186 baris, `CareerService` 1.061, `TerminationService` 1.033, `PayrollService` 819, `OnlineTestService` 809. Satu service memikul terlalu banyak responsibility (ATS, interview, scoring, hiring, semua di `RecruitmentService`). Sebagian sudah dipecah (`CandidateScoringService`, dll) tapi pemecahan belum konsisten. | L |
| A6 | 🟠 | **`ApiController::validateOrFail()` memakai `$response->send(); return null;`**. Pola ini menyebabkan controller harus selalu `if (! $valid) return null;` (mudah di-skip) dan menabrak siklus response CodeIgniter karena send() di-fire di dalam filter chain. Idiomatis CI4 adalah throw `ValidationException` atau return Response dari method. | S |
| A7 | 🟠 | **Encryption dipanggil per-request via `Services::encryption()->decrypt(...)` di controller** (lihat `EmployeeController::show`). Setiap request menampilkan employee = 3× decrypt operation. Tidak ada attribute cast atau opsi proyeksi (kalau caller hanya butuh nama + dept, decrypt NIK/NPWP tidak perlu). | M |
| A8 | 🟡 | **Frontend "God Components"**. `EmployeeModal.vue` 1.517 baris, `PipelineBoard.vue` 1.503, `CareerApply.vue` 1.417, `MppList.vue` 1.185. Pemisahan ke composable + child component minim, sehingga state, validation, API call, dan template menumpuk. | L |
| A9 | 🟡 | **Master CRUD duplikatif**. `Branches.vue`, `Departments.vue`, `JobTitles.vue`, `Positions.vue`, `LeaveTypes.vue`, `Shifts.vue`, `WorkLocations.vue`, `CostCenters.vue`, `OrganizationRoles.vue`, `OnlineTestCategories.vue`, `RecruitmentPipelines.vue` — banyak yang mengulang pattern (modal + form + list + filter + delete confirm) walaupun sudah ada `SimpleMaster.vue`. Adopsi `SimpleMaster` belum konsisten. | M |
| A10 | 🟡 | **Tidak ada API contract / DTO formal**. Bentuk JSON response ditentukan ad-hoc di controller (`select(...)` builder + handcrafted array di response). `docs/openapi.yaml` ada tapi tidak ada generator yang memastikan code ↔ spec sinkron. | M |

---

### 🅑 KEAMANAN

| # | Severity | Masalah | Effort |
|---|---|---|---|
| B1 | 🔴 | **`backend/.env` dan `backend/.env.backup` tampak ada di working directory** (4.398 byte). `.gitignore` mengecualikan, tapi `.env.backup` adalah pola berbahaya — file backup secret rentan ter-commit secara tidak sengaja. **Verifikasi `git ls-files | grep .env` bersih sebelum push publik**. | S |
| B2 | 🔴 | **`Config/Cors.php` kosong (`allowedOrigins => []`)**, lalu `ApiCorsFilter` punya **fallback dev: jika allowed list kosong, `localhost`/`127.0.0.1` dengan port apa pun di-allow**. Di production, kalau `cors.allowedOrigins` lupa di-set, behavior fallback ini tidak triggered (origin akan production HTTPS), jadi aman. **Tapi konfigurasi ganda (`Config/Cors.php` + env `cors.allowedOrigins` + `ApiCorsFilter`) membuat developer rancu** mana yang jadi truth. | S |
| B3 | 🟠 | **CSRF tidak aktif**. Filter alias ada (`'csrf' => CSRF::class`) tapi tidak applied di global maupun per-route. Karena auth lewat JWT Bearer (bukan cookie), risiko CSRF rendah, tapi **endpoint multipart upload (avatar, file upload, import xlsx) tetap perlu mitigasi** (misalnya cek `Origin`/`Referer`). | S |
| B4 | 🟠 | **Login & 2FA terlalu generous**. `JwtAuthFilter` membuat super_admin (`role_id === 1`) atau user dengan `company_id = 0` bisa **override `X-Company-Id` header bebas**. Audit log untuk tenant-switch ada di rencana tapi belum diimplementasikan (`IMPROVEMENT_CHECKLIST.md` Sprint 4 item belum selesai). | M |
| B5 | 🟠 | **Refresh token tidak terikat device**. Saat user logout, semua refresh token user di-revoke (lihat `AuthController::logout`). Tidak bisa "sign out other devices only". Tabel `refresh_tokens` belum punya `device_fingerprint`, `last_used_at`. | M |
| B6 | 🟠 | **Audit log tidak immutable**. Tidak ada tanda hash chain / signed log. Admin dengan akses DB bisa edit `audit_logs` tanpa jejak. Untuk modul HR yang touching disciplinary, severance, termination, ini risiko. | M |
| B7 | 🟡 | **Tidak ada Content-Security-Policy ketat**. `ContentSecurityPolicy.php` config CI4 ada tapi tidak ada bukti header CSP active di response API. `ApiSecurityHeadersFilter` perlu di-review. | S |
| B8 | 🟡 | **Encryption key rotation**. Command `RotateEncryption` ada (di `app/Commands`), tapi tidak ada SOP, tidak ada test backup-restore, tidak ada multi-key support test. | M |
| B9 | 🟡 | **`AttendanceController::clockIn` selfie**: anti-cheat ada (`AntiCheatService`) tapi tidak ada **liveness detection**, **face match ke foto profil**, atau **EXIF GPS strip** sebelum simpan. | L |
| B10 | 🟢 | **Honeypot config ada tapi tidak applied** ke endpoint sensitif (login, forgot-password). | S |

---

### 🅒 KEBENARAN BISNIS (CORRECTNESS)

| # | Severity | Masalah | Effort |
|---|---|---|---|
| C1 | 🔴 | **Auto-update bracket TER PPh 21**. PMK 168/2023 disimpan di tabel `ter_rates`, tapi tidak ada **admin UI CRUD** untuk update saat tarif berubah (mis. tahun pajak 2027+). Tim harus migrate setiap kali. | M |
| C2 | 🔴 | **THR — multi-kontrak satu tahun belum di-handle**. `IMPROVEMENT_CHECKLIST.md` mencatat ini sebagai defer. Karyawan yang resign lalu dipekerjakan ulang dalam tahun yang sama akan punya tenure perhitungan salah. | M |
| C3 | 🟠 | **Long sick leave (UU 13/2003 Pasal 153)** tidak di-handle: bayar 100% bulan 1–4, 75% bulan 5–8, 50% bulan 9–12, 25% bulan 13+. Saat ini SICK leave > 14 hari tidak auto-flag dan payroll tidak memotong proporsional. | L |
| C4 | 🟠 | **Cuti besar (long-service leave)** untuk PKWT 6 tahun → 1 bulan di tahun ke-7 belum auto-grant. Leave_type ada, logic-nya tidak ada. | M |
| C5 | 🟠 | **Quota cuti berbasis tenure** (5 thn = +2 hari, 10 thn = +5 hari) belum support. | M |
| C6 | 🟠 | **PPh 21 koreksi Desember mid-year join/resign** belum benar (lihat `IMPROVEMENT_CHECKLIST` P0 item 3). | L |
| C7 | 🟡 | **JKP wiring placeholder** — `BpjsCalculator::compute()` sudah hitung JKP tapi `PayrollService` belum sepenuhnya integrasi (masuk ke breakdown payslip & export SiPP). | M |
| C8 | 🟡 | **BPJS Kesehatan kelas iuran** — kolom `bpjs_kes_class` ada tapi rate per kelas belum dipisah. | M |
| C9 | 🟡 | **UMP/UMK auto-update** — seeder 2026 ada, tapi tidak ada cron `hr:fetch-ump-yearly` atau import CSV/scraper. Setiap tahun perlu maintenance manual. | M |
| C10 | 🟡 | **Half-day leave** sudah ada (MORNING/AFTERNOON), tapi attendance tidak split jadi setengah hadir + setengah cuti, sehingga payroll deduction & laporan attendance salah. | M |

---

### 🅓 PERFORMA & SKALABILITAS

| # | Severity | Masalah | Effort |
|---|---|---|---|
| D1 | 🟠 | **N+1 query pattern** muncul di banyak action (mis. `EmployeeController::show` melakukan 3× decrypt + multiple lookups). Tidak ada eager loading konvensi karena tanpa Models. Ini akan terasa di list `/api/v1/employees?per_page=100`. | M |
| D2 | 🟠 | **Composite indexes**. Migration #040 `AddCompositeIndexes` ada, tapi tabel besar lain (audit_logs, attendance, payslips, payslip_details, leaves) perlu audit ulang dengan EXPLAIN pada query yang dipakai dashboard & report. | M |
| D3 | 🟠 | **Audit log archive**. `ArchiveAuditLogs` command ada tapi belum dijadwalkan otomatis. Tabel `audit_logs` di production akan tumbuh cepat (setiap mutation tercatat). | S |
| D4 | 🟡 | **Query panel/master tidak menggunakan cache**. `CacheService` ada tapi banyak lookup yang stabil (UMP/UMK, holidays, role permissions, BPJS rates, leave types) tetap di-query setiap request. | M |
| D5 | 🟡 | **Frontend bundle**. View 1.500-line akan menghasilkan chunk besar, eager load di route saat dibuka. Tidak ada bukti analisis bundle (`rollup-plugin-visualizer`). PWA cache berbasis service worker ada (lihat `composables/usePwa.js`), tapi strategi cache assets perlu validasi. | M |
| D6 | 🟡 | **Tidak ada query timeout / circuit breaker** untuk external service (WhatsApp, OCR, AI Copilot). Saat provider lambat, request HRIS bisa menggantung. | M |
| D7 | 🟡 | **`payroll-periods/(:num)/calculate` & `bonus-runs/(:num)/calculate`** kemungkinan operasi blocking sync di HTTP request. Untuk perusahaan 5.000+ karyawan, ini bisa timeout. Idealnya di-queue (job worker) + WebSocket/polling status. | L |

---

### 🅔 TESTING & QA

| # | Severity | Masalah | Effort |
|---|---|---|---|
| E1 | 🔴 | **0 Feature/Integration test**. Folder `backend/tests/` hanya berisi `Unit/`. README claim "349 tests" semuanya unit, padahal banyak business rule lintas-tabel (JIC workflow, MPP→Recruitment→Onboarding, payroll calculate→finalize→bank export) yang **tidak pernah di-test end-to-end di CI**. | L |
| E2 | 🔴 | **Smoke test scripts (`backend/scripts/test-*.php`) tidak masuk CI**. ~30 file ad-hoc PHP yang dijalankan manual oleh developer. Risiko dropped quality saat developer baru tidak tahu harus run apa. | M |
| E3 | 🟠 | **Frontend test ≈ 0%**. Hanya `usePwa.spec.js`. 80+ view dan 10+ composable tanpa unit test. | L |
| E4 | 🟠 | **Tidak ada E2E test** (Cypress / Playwright). Skenario kritis (login → clock-in → ajukan cuti → approve → lihat saldo) tidak diverifikasi otomatis. | L |
| E5 | 🟠 | **Coverage report belum di-generate**. CI tidak collect coverage badges, sehingga tim tidak punya feedback regresi cakupan. | S |
| E6 | 🟡 | **Test data fixture**. Seeder ada (`InitialSeeder`) tapi tidak ada factory pattern (`fakerphp/faker` di-require tapi tidak terstruktur). | M |
| E7 | 🟡 | **PHPStan masih level 0**. Sudah baseline clean, tapi level 0 sangat permisif. Naikan bertahap ke level 5–6 untuk menangkap nullable & generic issue. | L |

---

### 🅕 UI / UX & AKSESIBILITAS

| # | Severity | Masalah | Effort |
|---|---|---|---|
| F1 | 🟠 | **i18n struktur sudah ada** (`locales/en.js`, `locales/id.js`) tapi banyak view masih hardcoded teks Indonesia. Konsistensi adopsi perlu di-audit. | M |
| F2 | 🟠 | **Mobile-first compliance**. Inbox approval, attendance clock, leave form harus enak di mobile (PWA). Beberapa view 1500-line cenderung desktop-first. | L |
| F3 | 🟡 | **Aksesibilitas (WCAG)**. Tidak ada audit eksplisit. ARIA label, keyboard navigation, kontras warna untuk dark mode (composable `useDarkMode.js` ada) belum diverifikasi. | L |
| F4 | 🟡 | **Loading state & skeleton UI** belum konsisten. Sebagian view pakai spinner, sebagian flash empty state. | M |
| F5 | 🟡 | **Error UX**. `vue-toastification` aktif, tapi pesan error backend (mis. `VALIDATION_ERROR`, `COMPANY_REQUIRED`) tidak selalu di-translate ke pesan user-friendly. | M |

---

### 🅖 DEVOPS & OPERATIONAL

| # | Severity | Masalah | Effort |
|---|---|---|---|
| G1 | 🟠 | **Production boot guard ada** (`Config/Boot/production.php`) tapi **escape hatch via private IP** terbuka. Berguna saat staging, berbahaya saat production di-host di environment yang RFC1918 IP juga reachable (mis. AWS VPC peering). Boot guard harus juga cek hostname publik. | S |
| G2 | 🟠 | **Tidak ada `docker-compose.prod.yml`** padahal dirujuk di komentar `docker-compose.yml`. Production deployment masih butuh manual setup nginx + php-fpm + cron. | M |
| G3 | 🟠 | **Cron jobs ≥ 15 spark commands** (lihat `app/Commands/`) — `CheckCertExpiry`, `CheckBpjsCompliance`, `CheckAnniversary`, `CheckProbationDeadline`, `CheckOfferExpiry`, `CheckHelpdeskSla`, `CheckMppOverdue`, `RunLeaveAnniversaryCarry`, `RunLeaveYearEnd`, `NotifyCarryOverCap`, `ProcessEmailQueue`, `ProcessWebhooks`, `CleanupAttachments`, `ArchiveAuditLogs`, `WarmCache`, `RotateEncryption`, `ApplyJic`, `ApplyTransfers`, `DeactivateResigned`, `SyncPermissions`, `RunAudit`. **Tidak ada satu file `cron.txt`/dokumentasi konsolidasi** dengan jadwal sah & retry policy + monitoring. `docs/CRON_JOBS.md` ada — perlu verifikasi keterkaitan dengan SOP deployment. | S |
| G4 | 🟠 | **Backup & disaster recovery**. Tidak ada doc backup MySQL (cadence, retention, restore drill) maupun storage `writable/uploads/` (penting karena dokumen kontrak, payslip, KTP). | M |
| G5 | 🟡 | **Monitoring/observability**. `docs/MONITORING.md` ada, tapi tidak terlihat instrumentasi konkret (tracing/metrics, error reporting Sentry, APM). `ExceptionLogger` ada — perlu dipastikan terhubung ke external sink. | M |
| G6 | 🟡 | **Secret management**. `.env` plain text di filesystem. Belum ada doc untuk Vault / AWS Secrets Manager / SOP rotasi. | M |
| G7 | 🟡 | **Deployment script**. `setup-dev.ps1`/`setup-dev.sh` ada untuk dev. **Tidak ada `deploy.sh` production** (zero-downtime, rollback, run migration aman). | M |
| G8 | 🟡 | **`docs/ENVIRONMENTS.md`** sudah dibuat (multi env templates), tapi `scripts/switch-env.*` perlu dipastikan idempotent dan tidak mungkin overwrite production secret. | S |

---

### 🅗 DEPENDENSI & SUPPLY CHAIN

| # | Severity | Masalah | Effort |
|---|---|---|---|
| H1 | 🟠 | **Versi dependency open range** di `composer.json` & `package.json` (^4.5, ^4.7, ^6.10, dll). Untuk audit pinpoint, gunakan composer.lock & package-lock.json yang ada (sudah). Tapi tambahkan **Dependabot already configured** (`.github/dependabot.yml`) — pastikan SLA review PR Dependabot < 7 hari. | S |
| H2 | 🟠 | **`firebase/php-jwt` 6.x** — wajib pastikan versi terbaru (CVE 2023-jwt audit). | S |
| H3 | 🟡 | **`dompdf/dompdf`** historically punya beberapa CVE. Pastikan disable PHP eval, set chroot. | S |
| H4 | 🟡 | **`phpoffice/phpspreadsheet`** untuk import xlsx — formula injection & XXE. Sudah ada `XlsxImportService`, tapi pastikan setting `setReadDataOnly(true)` dan `setLoadSheetsOnly` digunakan. | S |
| H5 | 🟢 | **Frontend** — Vue 3.5, Vite 5, Pinia 2, axios 1.7, dayjs 1.11, chart.js 4.4 → semua aktif & sehat. Tidak ada library abandoned terdeteksi. | – |

---

### 🅘 DOKUMENTASI

| # | Severity | Masalah | Effort |
|---|---|---|---|
| I1 | 🟢 | **Dokumentasi sangat lengkap** (50+ file `.md` di `docs/`, ~1 MB). Ini kekuatan. | – |
| I2 | 🟡 | **Banyak doc Sprint-based** (SPRINT_1, SPRINT_2, ... HORIZON_2/3/4) yang **mencatat history**, bukan **referensi current behavior**. Onboarding developer baru akan baca dan bingung mana yang masih berlaku. Perlu **konsolidasi ke "single canonical guide" per modul**. | M |
| I3 | 🟡 | **`openapi.yaml` ada tapi tidak ada generator otomatis** dari kode. Spec rentan stale. | M |
| I4 | 🟢 | **`README.md` & `SETUP.md`** sudah jelas. | – |
| I5 | 🟡 | **Diagram arsitektur high-level** belum ada (data flow auth, multi-tenant, queue). | S |

---

## 3. Quick Wins (≤ 1 hari setiap item)

Lakukan dulu ini untuk dampak besar dengan effort kecil:

1. **Verifikasi `git ls-files | findstr /i .env`** kosong (B1).
2. **Tambah `permission:xxx` filter di `Routes.php`** untuk endpoint write yang sudah punya cek manual di controller — pindahkan ke filter (A4).
3. **Naikkan `Config/Boot/production.php` boot guard**: tambahkan whitelist hostname publik, jangan loose RFC1918 (G1).
4. **Tambah `setReadDataOnly(true)` di `XlsxImportService`** kalau belum (H4).
5. **Tambah index missing** di `audit_logs(company_id, created_at)`, `attendances(employee_id, work_date)`, `payslips(payroll_period_id, employee_id)` jika belum ada (D2).
6. **Schedule `php spark hr:archive-audit-logs` di cron** monthly (D3).
7. **Tambah `npm run test:coverage` + Vitest threshold** di CI (E5).
8. **Hapus / move `scripts/test-*.php`** ke `tests/Feature/` (start migration; E2).
9. **Honeypot active** di login + forgot-password form (B10).
10. **`AuditLogger::log()` saat super_admin switch X-Company-Id** (B4 partial).

---

## 4. Roadmap Perbaikan Bertahap

### ✅ Sprint A (selesai 28 Mei 2026) — Stabilization & Safety Net
**Lihat:** `docs/SPRINT_A_HARDENING.md`. Total 754 → 758 tests passing.
- [x] Quick Wins #1–6, #9, #10 — verifikasi env, hardening XlsxImport, boot guard, indexes, archive cron, super_admin tenant switch audit
- [x] **Audit immutability** hash chain `previous_hash + entry_hash` di `audit_logs` + command `audit:verify-chain` + 12 unit test (B6)
- [x] **Refresh token device tracking** — kolom `device_fingerprint`, `device_name`, `last_used_at` + 3 endpoint `auth/sessions[*]` (B5)
- [x] **Origin/Referer guard untuk multipart upload** — `SameOriginFilter` di-pasang ke avatar/upload/clock-in/clock-out (B3)
- [x] **Composite indexes tambahan** di audit_logs, attendances, payslips, leaves, refresh_tokens (D2)
- [x] **Vitest coverage foundation** — config lcov reporter siap, threshold di-comment menunggu Sprint E (E5)
- [x] 1 sample test sebagai precedent feature test (`AuditChainHashTest.php`) (E2 partial)
- [ ] Migrasi 30+ smoke script ke `tests/Feature/` (massal) — carry-over Sprint B+1
- [ ] Setup Playwright E2E — carry-over Sprint B+1
- [ ] QW4 Honeypot — di-skip; throttle bucket sudah cover

### ✅ Sprint B (selesai 28 Mei 2026) — Architecture Refactor (Phase 1)
**Lihat:** `docs/SPRINT_B_ARCHITECTURE.md` & `docs/decisions/0001-architecture-baseline.md` (ADR). Total tests 758 → 762.
- [x] **Layer Model — 5 dari 10 tabel inti** sudah dibuat: `BaseModel` + `CompanyModel`, `BranchModel`, `DepartmentModel`, `UserModel`, `EmployeeModel` (A1, partial)
- [x] **`BaseModel` foundation** — auto multi-tenant scope, encrypt/decrypt, anti tenant-jump, soft delete + timestamps
- [x] **`EmployeeModel::findByNik()`** — hash lookup tanpa decrypt seluruh row + 4 unit test
- [x] **ADR 0001 Architecture Baseline** — pattern, size limits (controller ≤500, service ≤800), migration plan untuk A2/A3/A4/A5
- [ ] **5 Model lanjutan** (Position, JobGrade, LeaveRequest, OvertimeRequest, Payslip) — carry-over Sprint B+1
- [ ] **Pecah `JicController`** — carry-over Sprint C (butuh feature test infra dulu)
- [ ] **Modularisasi `Routes.php`** — carry-over Sprint C (risk regresi tinggi tanpa integration test)
- [ ] **Refactor `RecruitmentService`** — carry-over Sprint C
- [ ] **PHPStan naik level 4** — carry-over Sprint C+
- [ ] **Activate `permission:xxx` filter** — carry-over Sprint B+1 (perlu audit per-method)

### ✅ Sprint C (selesai 28 Mei 2026) — Compliance Calculators Phase 1
**Lihat:** `docs/SPRINT_C_COMPLIANCE.md`. Total tests 762 → 825 (+63 dari Sprint C).

Pendekatan: pure-function calculator dulu (testable, tanpa break controller existing), wiring ke PayrollService/LeaveBalanceService di Sprint C+1.
- [x] **`LongSickLeaveCalculator`** (Pasal 153) — phase 1-4 progressive payment + PHK eligibility detection (C3)
- [x] **`TenureLeaveQuotaCalculator`** — cuti besar 6-year cycle + bonus quota tenure-based (C4 + C5)
- [x] **`Pph21YearEndCorrection`** — classify FULL/MID_JOIN/MID_RESIGN/PARTIAL + adjustment compute (C6)
- [x] **63 unit test baru** — coverage progressive percentage, tenure milestones, scenario classification, edge cases
- [ ] **Wiring calculator ke `PayrollService::calculatePeriod`** — carry-over Sprint C+1 (perlu integration test infra)
- [ ] **Wiring ke `LeaveBalanceService`** untuk auto-grant cuti besar — carry-over Sprint C+1
- [ ] **JKP wiring lengkap** ke payslip + SiPP export (C7) — carry-over
- [ ] **Admin UI CRUD `ter_rates` & `bpjs_rates`** (C1, C8, C9) — carry-over Sprint C+2
- [ ] **THR multi-kontrak** (C2) — carry-over Sprint C+2
- [ ] **Half-day leave** split di attendance & payroll (C10) — carry-over Sprint C+2

### ✅ Sprint D Phase 1 (selesai 28 Mei 2026) — Performance & Scale
**Lihat:** `docs/SPRINT_D_PERFORMANCE.md`. Total tests 825 → 838 (+13 dari CircuitBreakerTest).
- [x] **Circuit breaker library** (`CircuitBreaker` + `CircuitOpenException`) — pattern 3-state CLOSED/OPEN/HALF_OPEN, persisten via cache, isolasi per-service, fail-fast saat provider down (D6)
- [x] **13 unit test** — state machine penuh, threshold clamping, snapshot, reset, isolasi multi-service, exception passthrough
- [ ] **Wiring `CircuitBreaker` ke WhatsApp/OCR/AI/webhook outbound services** — carry-over Sprint D+1 (factory di `Services::circuitBreaker()`)
- [ ] **EXPLAIN audit** query top 50 endpoints — carry-over Sprint D+1 (perlu Models lengkap dulu) (D1, D2)
- [ ] **Cache layer untuk lookup stabil** (UMP, holidays, role_permissions, BPJS rates) — carry-over Sprint D+1 (D4)
- [ ] **Bundle analysis frontend** — carry-over Sprint E (sekalian dengan A8 god-component decomposition) (D5)
- [ ] **Async job queue** untuk payroll/bonus calculate — carry-over Sprint D+2 (perlu ADR keputusan teknis dulu) (D7)

### Sprint E (4 minggu) — Frontend Refactor
- [ ] Pecah `EmployeeModal.vue`, `PipelineBoard.vue`, `CareerApply.vue`, `MppList.vue`, `JobInformationChange.vue` masing-masing ke 3–5 child component + composable (A8)
- [ ] **Standardisasi pakai `SimpleMaster.vue`** untuk semua master CRUD sederhana. Hapus duplikasi. (A9)
- [ ] **Migrasi semua hardcoded text → i18n** (F1)
- [ ] **Audit aksesibilitas** dengan axe-core di Playwright (F3)
- [ ] **Konsistensi loading state + skeleton** (F4)
- [ ] **Vitest coverage frontend ≥ 40%** (E3)

### Sprint F (3 minggu) — DevOps & Operational Hardening
- [ ] **`docker-compose.prod.yml` + `Dockerfile` production** (multi-stage, non-root, healthcheck, secrets via env) (G2)
- [ ] **`deploy.sh` zero-downtime + rollback + DB migration safety** (G7)
- [ ] **Dokumentasi backup & DR**: MySQL dump cadence, `writable/uploads/` rsync ke S3, restore drill quarterly (G4)
- [ ] **Sentry / OpenTelemetry instrumentasi** (G5)
- [ ] **Secret management migration**: `.env` → Vault atau AWS Secrets Manager untuk production (G6)
- [ ] **`docs/CRON_JOBS.md` direvisi jadi single canonical** + monitoring per cron (alert kalau tidak run) (G3)

### Sprint G (ongoing) — Documentation & DX
- [ ] **Konsolidasi doc Sprint*.md → MODULE_*.md** referensi current state (I2)
- [ ] **Generator OpenAPI dari kode** (PHPDoc/attribute → openapi.yaml) (I3)
- [ ] **Diagram arsitektur** (Mermaid) di `docs/ARCHITECTURE.md` (I5)
- [ ] **CONTRIBUTING.md**: standar branch, commit, PR review checklist
- [ ] **`docs/DECISIONS/`** (ADR per keputusan arsitektur)

---

## 5. Skor Per Dimensi (Saat Ini → Target)

| Dimensi | Sekarang | Target Q4-2026 |
|---|---|---|
| Fitur (functional coverage) | 8.5 / 10 | 9 / 10 |
| Arsitektur & Code Quality | 5 / 10 | 8 / 10 |
| Keamanan | 7 / 10 | 9 / 10 |
| Compliance Indonesia | 7.5 / 10 | 9.5 / 10 |
| Performa & Skalabilitas | 6 / 10 | 8 / 10 |
| Testing & QA | 4 / 10 | 7.5 / 10 |
| UI/UX & A11y | 6.5 / 10 | 8 / 10 |
| DevOps & Operasional | 5.5 / 10 | 8 / 10 |
| Dokumentasi | 9 / 10 | 9 / 10 |

---

## 6. Catatan Akhir

- **Jangan terjebak menulis fitur baru** sebelum Sprint A & B selesai. Velocity tim akan terus turun selama hutang teknik (god controllers, no models, no integration tests) menumpuk.
- **Buat policy code-review**: setiap PR dengan controller > 500 baris atau view > 600 baris **wajib** punya rencana refactor di issue tracker.
- **Lock-in arsitektur target di ADR**: tetapkan layer Model wajib, controller maksimal 300 baris, service-per-aggregate, di-document di `docs/DECISIONS/0001-architecture-baseline.md`. Saat seluruh tim setuju, tegakkan via PHPStan custom rule + ESLint custom rule.
- **Naikkan PHPStan ke level 6** dan **ESLint plugin-vue ke `vue3-recommended`** di akhir Sprint B.
- **Continuous benchmark**: tambah micro-benchmark untuk `PayrollService::calculatePeriod`, `BpjsCalculator::compute`, `Pph21TerCalculator::calculateMonthlyTer` supaya regresi performa terdeteksi.

Aplikasi ini punya **fondasi fitur yang kuat dan dokumentasi yang luar biasa**. Kalau hutang arsitektur dilunasi dalam 2–3 sprint ke depan, codebase ini bisa naik kelas dari "powerful internal tool" ke "shippable enterprise product".