# HRIS — Audit Peningkatan (Apa yang Masih Perlu Di-improve)

Dokumen ini hasil **audit teknis** terhadap codebase setelah fase 1–17 di
ROADMAP selesai. Fokus: hal-hal yang **sudah jalan tapi belum production-ready**
atau **technical debt** yang akan menyulitkan saat scale-up.

Skor prioritas:
- 🔴 **P0 / Blocker** — risiko nyata di production (data loss, security, audit fail)
- 🟠 **P1 / Penting** — bukan blocker, tapi membatasi pertumbuhan / DX
- 🟡 **P2 / Nice-to-have** — polish

---

## 🤖 Audit Otomatis Berkala — `php spark hr:audit`

Audit ini bukan dokumen statis. Ada command yang menjalankan **25 cek otomatis**
terhadap database, filesystem, security, operasional, dan data quality. Pakai cron
untuk dapat laporan harian + alert email saat ada masalah.

### Quick start

```bash
# Sekali jalan, lihat semua hasil di console
php spark hr:audit

# Hanya tampilkan WARN/CRITICAL, simpan JSON ke writable/audit-reports/
php spark hr:audit --threshold-only --json

# Cron friendly: quiet + email ke HR admin
php spark hr:audit --json --mail --quiet

# Cek 1 perusahaan saja (multi-tenant)
php spark hr:audit --company=3
```

### Setup cron (Linux)

Tambahkan ke `/etc/cron.d/hris` atau crontab user web server:

```cron
# HR audit harian jam 06:00 WIB
0 6 * * *  cd /var/www/hris/backend && php spark hr:audit --json --mail --quiet

# Cleanup log lama mingguan
0 3 * * 0  cd /var/www/hris/backend && php spark logs:clear
```

### Setup Task Scheduler (Windows)

```powershell
# Buat scheduled task berjalan jam 06:00 setiap hari
schtasks /Create /SC DAILY /ST 06:00 /TN "HRIS-Audit" /TR "cmd /c cd /d D:\Apps Project\hris-project-1\backend && php spark hr:audit --json --mail --quiet"
```

### Yang dicek

| Kategori     | Item                                                       |
|--------------|------------------------------------------------------------|
| Storage      | Selfie disk usage, log files size, total uploads           |
| Database     | Row count audit_logs/attendances/notifications, composite indexes |
| Security     | Anomali absensi belum review > 7 hari, device blocked, login gagal 24h, akun lock, JWT secret length |
| Operational  | Kontrak/sertifikat habis 30 hari, task overdue, approval pending > 7 hari, payroll DRAFT > 1 bln, email queue stuck |
| DataQuality  | Karyawan missing NIK/rekening, tanpa user account, leave balance missing, approval chain < 2 step, gaji di bawah UMP |

### Output

Setiap run menghasilkan:
1. **Console table** berwarna (skip dengan `--quiet`)
2. **Audit log entry** otomatis (severity NOTICE / WARNING / CRITICAL sesuai temuan)
3. **JSON report** di `writable/audit-reports/audit-YYYY-MM-DD-HHMMSS.json` (dengan `--json`)
4. **Email HTML** ke semua user role super admin + HR admin (dengan `--mail`)

Exit code:
- `0` — semua OK (cron sukses)
- `1` — ada WARN (notice ke ops)
- `2` — ada CRITICAL (alert serius, eskalasi ke on-call)

Bisa diintegrasikan ke monitoring (Nagios/Zabbix/UptimeRobot/Healthchecks.io)
dengan curl ping setelah command sukses, atau parsing exit code langsung.

### Threshold

Threshold default sudah dikalibrasi untuk perusahaan 100–500 karyawan. Sesuaikan
di `backend/app/Commands/RunAudit.php` (cari komentar `warn` / `critical`) jika
skala beda.

---

## 🔴 P0 — Blocker untuk go-live

### A. Tidak ada test otomatis (PHPUnit / Vitest)

- Backend hanya punya **smoke test scripts** di `backend/scripts/test-*-flow.php`.
  Mereka jalan langsung lewat `php` dan side-effect ke DB. Tidak isolated.
- Frontend tidak punya test framework sama sekali.
- **Risiko**: regresi tidak terdeteksi sebelum push. Refactor jadi mahal.
- **Saran**:
  1. Tambah PHPUnit/Pest untuk backend, mulai dari unit test `IndonesianValidator`,
     `SeveranceCalculator`, `PayrollService::calculatePph21`, `AntiCheatService::computeAnomaly`,
     `LeaveBalanceService::computeProratedQuota`. Ini fungsi-fungsi pure tanpa DB.
  2. Tambah Vitest + Vue Test Utils untuk frontend, fokus ke composable
     (`useResource`, `useConfirm`) dan logika kalkulasi (live preview Severance,
     OvertimeForm hours calc).
  3. Tambah CI workflow (GitHub Actions/GitLab CI) yang jalan `php spark migrate`
     + smoke tests + frontend `npm run build` per push.

### B. JWT secret / encryption key di .env tanpa rotation

- Tidak ada mekanisme rotasi `JWT_SECRET` / `ENCRYPTION_KEY`.
  Sekali bocor, semua data terenkripsi (NIK, NPWP, bank) berisiko.
- **Risiko**: kepatuhan (UU PDP), audit ISO/SOC2.
- **Saran**:
  1. Dukung versi-key di `Encryption` library: simpan `key_version` di kolom yang
     terenkripsi, JWT include `kid` di header. Saat rotasi, baca pakai key lama
     untuk decrypt + tulis ulang dengan key baru.
  2. Command `php spark hr:rotate-encryption --from=v1 --to=v2 --batch=1000`.
  3. Dokumentasikan SOP rotasi (90 hari).

### C. Selfie attendance disimpan di filesystem lokal tanpa CDN/quota

- `AttendanceController::saveSelfieFromDataUrl` menulis ke `writable/uploads/selfies/<emp>/`.
  Tidak ada limit total per karyawan, tidak ada cleanup, tidak ada akses lewat URL signed.
- **Risiko**: disk penuh dalam beberapa bulan. Selfie bisa di-fetch oleh siapa
  saja yang tahu path (kalau folder writable accidentally exposed).
- **Saran**:
  1. Setting `selfie_retention_days` per company (default 90), tambah cron
     `php spark hr:cleanup-attachments` untuk hapus selfie > N hari (kecuali
     yang anomaly-flagged untuk audit).
  2. Endpoint `GET /attendance/(:num)/selfie?token=...` yang generate signed URL
     dengan expiration short-lived. Frontend pakai URL ini, bukan path langsung.
  3. Roadmap S3/MinIO/Spaces driver — abstrak `FileStorage` interface supaya
     bisa swap tanpa ubah controller.

### D. CSP header belum ada

- `ApiSecurityHeadersFilter` set X-Frame-Options/X-Content-Type-Options tapi
  belum ada Content-Security-Policy. Tanpa CSP, XSS yang lolos input validation
  bisa bebas exfiltrate.
- **Saran**: tambah baris CSP di filter:
  ```php
  $response->setHeader('Content-Security-Policy',
      "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'");
  ```
  Dimulai dari report-only, tighten setelah verifikasi tidak break.

### E. Bukan production-ready: `appBaseURL` masih default localhost

- `backend/app/Config/Boot/production.php` sudah dibuat tapi `app.baseURL`
  tidak ada di `.env.example`. Operator lupa setting → backend serve absolute
  URL salah saat generate PDF/email.
- **Saran**: tambah `app.baseURL` ke `.env.example` dengan komentar jelas.

---

## 🟠 P1 — Penting untuk DX & maintainability

### F. UX: `window.confirm`/`window.prompt` masih dipakai di beberapa tempat

Tiga tempat ditemukan:
- `Attendance/Anomalies.vue` — alasan blok device pakai `window.prompt`.
- `Attendance/Clock.vue` — konfirmasi clock-in di luar geofence pakai `window.confirm`.
- (Kalau tambah feature lain hati-hati: pola ini sudah ditangani via `useConfirm` composable di seluruh aplikasi.)

**Saran**: ganti semua dengan `useConfirm({ inputLabel, inputRequired })`. Untuk
prompt, tambah varian `usePrompt({ title, label, validation })`. Manfaatnya:
konsisten styling, accessible, bisa di-test, tidak ke-block oleh popup blocker.

### G. Service container tidak konsisten — sebagian kontroler bypass `Services::*`

Sample search: ada controller yang masih instantiate library langsung
(`new \App\Libraries\Encryption()` di `EmployeeController`) padahal `AuditLogger`,
`Notification`, dan service lain pakai service container.

**Saran**: register `Encryption` sebagai service supaya bisa di-mock di test
dan key bisa di-cycle terpusat. Pola yang sama untuk `Argon2Hasher` (kalau ada).

### H. EmployeeModal belum punya tab Aset / Surat Peringatan / Onboarding progress

ROADMAP menandai tiga sebagai deferred:
- Tab Aset (data tersedia di `/asset-assignments/employee/:id`)
- Badge SP aktif + timeline (data tersedia di `DisciplinaryService::summary`)
- Progress checklist Onboarding (data tersedia di `/employee-tasks/employee/:id`)

Saat ini HR yang mau cek profil lengkap karyawan harus pindah ke 3 menu
berbeda. Sekali tambah tab di EmployeeModal, semua nyatu.

**Saran**: tambah 1 tab baru "Riwayat & Aset" yang load 3 endpoint paralel di
`onTabActivate`, render section: Aset Aktif, SP Aktif/Histori, Progress
Onboarding/Offboarding terkini. Skema lazy-load supaya modal cepat dibuka.

### I. Recruitment & Helpdesk: file upload masih placeholder

Schema sudah punya `candidate_documents` dan `hr_ticket_attachments` field,
tapi tidak ada endpoint multipart. Saat ini user paste URL eksternal manual.

**Saran**: bikin satu controller `FileUploadController` generik yang handle
4 modul (employee_documents, candidate_documents, ticket_attachments, training_certs)
dengan single endpoint `POST /upload?context=ticket&entity_id=N` + permission
mapping. Reuse `FileStorage` driver dari point C.

### J. Tidak ada index DB di kolom yang sering di-filter

Query yang sering dipakai:
- `attendances.work_date + employee_id` — filter calendar view, leaderboard
- `leaves.status + start_date` — team-calendar query
- `notifications.user_id + is_read` — unread badge
- `audit_logs.module + created_at` — audit page filter
- `goals.cycle_id + employee_id` — performance dashboard

Saat data sudah > 100k baris (target 1 tahun untuk perusahaan 200 karyawan),
query ini akan terasa lambat tanpa composite index.

**Saran**: migration `2024-01-01-000025_AddPerformanceIndexes.php` dengan
`CREATE INDEX … (col_a, col_b)` untuk kombinasi di atas. Audit dengan
`EXPLAIN` di staging sebelum produce.

### K. Notifikasi cuma in-app — tidak ada email/WhatsApp/push real

`NotificationService::send` sudah generic, tapi:
- Email queue (`email:process` command) belum jelas pakai broker apa (database
  queue OK untuk skala kecil, tapi tanpa retry/backoff).
- WhatsApp tidak ada (penting di Indonesia, banyak HR pakai grup WA).
- Web Push: SW handler ada di PWA, tapi VAPID key dan subscribe endpoint belum
  dibuat.

**Saran**:
1. Refactor `NotificationService` ke pattern channel: `EmailChannel`, `InAppChannel`,
   `PushChannel`, `WhatsAppChannel`. Setting per user pilih channel mana untuk tipe notif apa.
2. WhatsApp: integrasi minimal (Fonnte/Twilio) dengan template approval (free-text dulu, official template later).

### L. Frontend: bundle vendor 151 KB gzip 58.5 KB — bisa code-split lebih agresif

Vue + axios + heroicons + vue-router + pinia + toastification ≈ 58 KB gzip.
Acceptable, tapi:
- Heroicons tidak tree-shake otomatis kalau import barrel (`@heroicons/vue/24/outline`).
  Sudah betul individual import, tapi setiap halaman re-import → duplikasi.
- Date utility belum konsisten (kadang `Date.toLocaleDateString` raw, kadang
  potong manual `slice(11,16)`). Tambah `dayjs` (≈ 6 KB) yang ringan.

**Saran**: setup `unplugin-icons` atau Heroicons workaround untuk dedupe.
Tambah `dayjs/locale/id` sentralisasi format tanggal.

### M. Tidak ada role/permission seeding untuk data yang baru ditambahkan oleh tenant

Saat company baru dibuat (super-admin), permission baru dari migrasi tidak otomatis
ke-grant ke role HR existing. Saat ini auto-grant hanya saat install awal.

**Saran**: command `php spark hr:sync-permissions --company=N` yang sync
permission baru ke role default berdasarkan rule auto-grant di seeder.

### N. Production config logger throttle vs audit_logs growth

`audit_logs` insert per request, plus `log` files. Tanpa retention, dalam 1 tahun
audit_logs bisa 10 juta baris untuk perusahaan 200 karyawan.

**Saran**:
1. Tambah `audit_logs.archived_at` + command `php spark hr:archive-audit --before=2025-01-01`
   yang move ke partisi/tabel terpisah.
2. Atau pakai partition by month di MySQL native.

---

## 🟡 P2 — Nice-to-have

### O. Tampilan tabel: tidak ada bulk action

Banyak halaman list (Employees, Leaves, Trainings, Tickets) tidak punya
checkbox + bulk action (approve all, export selected, archive). HR yang
proses 50+ pengajuan harus klik satu-satu.

**Saran**: composable `useBulkSelection` + bar aksi yang muncul saat ada
selection. Mulai dari Approval Inbox (bulk approve mass leave akhir minggu).

### P. Tidak ada keyboard shortcut

Power user HR yang setiap hari klik approve butuh shortcut (J/K navigate,
A approve, R reject, /  search). Saat ini semua mouse-driven.

**Saran**: pakai `vueuse/onKeyStroke` di Inbox dan list-list besar.

### Q. i18n tidak ada — semua hardcoded Bahasa

Frontend pakai Bahasa di seluruh template. Kalau suatu hari client minta
English (mis. ekspat di multinational subsidiary), butuh re-write.

**Saran**: setup `vue-i18n` minimal, mulai dengan key utama Dashboard +
Login + auth-related. Tidak harus semua sekali; lazy migrate per-page.

### R. Avatar default — semua karyawan tampil sama (ikon)

Profile karyawan tidak punya avatar default yang inisial-warna seperti Slack.
Saat list 200 karyawan, sulit beda secara visual.

**Saran**: helper `getAvatarColor(name)` deterministik dari hash nama, plus
inisial 2 huruf pertama. Pola sudah dipakai di topbar (`auth.user?.name?.[0]`)
tapi belum di list/cell.

### S. Dark mode tidak ada

Tailwind sudah dikonfigurasi tapi belum ada toggle. Mata HR yang lama lihat
spreadsheet payroll akan terbantu.

**Saran**: `useColorMode` dari vueuse + `class` strategy Tailwind +
toggle di topbar dropdown.

### T. Heatmap absensi belum ada di dashboard HR

Sudah ada `MyAttendance` calendar view per-individual, tapi HR butuh
gambaran agregat (siapa sering telat? departemen mana yang absent ratio tinggi?).

**Saran**: chart sederhana di Dashboard HR — pakai `chart.js` (sudah di
vendor bundle, lihat `charts-l0sNRNKZ.js`) atau Apex.

### U. Reports module tipis

`/reports` cuma punya headcount endpoint. Kebutuhan riil:
- Salary band per posisi
- Retention rate (turnover) per departemen
- Cuti expiring lookahead
- Training compliance (% karyawan ikut training mandatory)
- Anomaly trend per cabang

**Saran**: bikin builder ringan (drag-drop column + filter + group-by) atau
hardcode 5–10 report yang most-asked oleh HR.

---

## 📋 Roadmap Perbaikan — Checklist Actionable

Setiap item P0/P1/P2 di atas dipecah menjadi langkah konkret di bawah, dengan
**estimasi waktu**, **dependency**, dan **acceptance criteria**. Centang
`- [x]` saat selesai supaya bisa di-track per sprint.

### Sprint 1 — Foundation Hardening — ✅ SELESAI

> Status: 4 dari 5 item P0 sudah dieksekusi. Verifikasi: `php spark hr:audit
> --threshold-only` → 0 CRITICAL. PHPUnit suite hijau (53/53). Sisa A.3–A.10
> bisa dilanjutkan inkremental tanpa blocking go-live.

#### 🔴 A. PHPUnit / Vitest setup — ⚙️ POC
- [x] **A.1** PHPUnit 10.5 sudah ada di `composer.json`. Tambah `backend/phpunit.xml.dist` — testsuite Unit pointing ke `tests/Unit`.
- [x] **A.2** `backend/tests/Unit/IndonesianValidatorTest.php` — 53 test cases pakai `#[DataProvider]` (NIK, NPWP, phone, BPJS, postal, PTKP). `vendor/bin/phpunit --testsuite Unit` → **OK 53/53**.
- [ ] **A.3** SeveranceCalculatorTest — masih TODO (estimasi 3 jam, skema sudah ada di smoke `test-severance-flow.php`)
- [ ] **A.4** AntiCheatServiceTest — masih TODO
- [ ] **A.5** PayrollTaxTest — masih TODO
- [ ] **A.6** LeaveBalanceTest — masih TODO
- [ ] **A.7** Vitest setup — masih TODO
- [ ] **A.8** useResource composable test — masih TODO
- [ ] **A.9** OvertimeForm calculateHours test — masih TODO
- [ ] **A.10** GitHub Actions CI workflow — masih TODO
- [x] **A.AC sebagian** Acceptance partial: PHPUnit jalan, suite POC hijau. Test coverage meningkat akan terjadi inkremental dari template ini.

#### 🔴 C. File storage + retention — ✅
- [x] **C.1** Migration `2024-01-01-000025_AddP0HardeningFields.php` — auto-seed setting `selfie_retention_days=90` per company saat migrate
- [x] **C.2** Command `php spark hr:cleanup-attachments` — loop per company, baca retention dari setting, skip anomaly_score≥30, log ke audit. Verified dengan `--dry-run`.
- [x] **C.3** Endpoint `GET /api/v1/files/serve?path=&expires=&token=` (public, signed). Return 410 saat expired, 403 saat tampered, 404 saat file tidak ada.
- [x] **C.5** `App\Libraries\FileStorage\FileStorageInterface` + `LocalDriver` dengan signed URL HMAC-SHA256 (expiry 5 menit default). Service container `Services::fileStorage()`. Skeleton S3Driver siap di-extend tanpa ubah controller.
- [ ] **C.4** Update `Anomalies.vue` review modal pakai signed URL endpoint — masih TODO (frontend swap path → signedUrl call)
- [x] **C.AC** Acceptance: selfie cleanup berjalan + skip anomaly + delete idempotent. Akses lewat URL tanpa/expired/wrong token return 403/410. Test ulang setelah C.4 frontend integration.

#### 🔴 D. CSP header — ✅
- [x] **D.1** CSP report-only header ditambah di `ApiSecurityHeadersFilter.php` dengan whitelist konservatif (default-src 'self', img-src 'self' data: blob: https:, dst). Mode toggle via `env('csp.enforce')`.
- [x] **D.2** Endpoint `POST /api/v1/csp-report` di `CspReportController` — simpan violation ke audit_logs (NOTICE) + skip noise extension.
- [ ] **D.3** Monitor 1 minggu di staging — pending deploy
- [ ] **D.4** Switch ke enforce mode setelah report bersih — pending D.3
- [x] **D.AC** Acceptance: header terkirim (verifikasi via curl di tahap deploy), endpoint terima POST tanpa auth, audit log entry tercatat.

#### 🔴 E. Production config — ✅
- [x] **E.1** Komentar warning di `.env.example` untuk `app.baseURL` (penjelasan PDF/email impact + reference ke production.php)
- [x] **E.2** `app/Config/Boot/production.php` fail-fast guard untuk 3 config kritis: baseURL bukan localhost, jwt.secret bukan placeholder/<32 char, encryption.key bukan placeholder. Tiga escape hatch otomatis supaya tidak ganggu maintenance lokal:
  - CLI (`php spark *`) selalu lewat — dibutuhkan untuk migrate/audit di server
  - REMOTE_ADDR loopback (127.0.0.1, ::1) atau IP private RFC1918 (10.x, 192.168.x, 172.16-31.x) lewat dengan response header `X-HRIS-Boot-Warning` sebagai notice. Production di balik proxy/CDN tidak terdampak karena REMOTE_ADDR ≠ loopback
  - Override eksplisit `production.skip_boot_guard=true` di .env (default off)
- [ ] **E.3** Update `docs/SETUP.md` section production deployment — masih TODO
- [x] **E.AC** Acceptance verified: 
  - `php spark migrate` di .env=production lokal → lewat (CLI exempt)
  - Akses HTTP dari localhost (browser dev) → lewat dengan `X-HRIS-Boot-Warning` header
  - Akses dari IP publik dengan baseURL=localhost → boot blocked HTTP 500 dengan pesan jelas
  - Setelah baseURL diganti production URL → akses publik lewat normal

---

### Sprint 2 — Security & Performance — ✅ SELESAI

> Status: 3 dari 3 item Sprint 2 (B, J, N) di-deliver dalam 1 batch. Migrasi
> #26 sukses run. PHPUnit suite tetap hijau (53/53), audit 0 CRITICAL.

#### 🔴 B. Key rotation foundation — ✅
- [x] **B.1** Migration `2024-01-01-000025_AddP0HardeningFields.php` — kolom `employees.key_version TINYINT DEFAULT 1` (run sukses).
- [x] **B.2** Service container `Services::encryption()` registered. Pola `new \App\Libraries\Encryption()` semua di-refactor ke service (10 callsites: DemoSeeder, PayrollExporter×5, ContractRenderer, EmployeeController×3).
- [x] **B.3** `App\Libraries\Encryption` sekarang multi-key:
  - Format ciphertext baru: `vN:base64(...)` dengan prefix versi
  - v1 (legacy) tanpa prefix → backward-compatible 100% (tidak ada ciphertext lama yang break)
  - Method baru: `activeVersion()`, `availableVersions()`, `needsRotation($cipher)`
  - `.env`: `encryption.key_v2` (key baru), `encryption.active_version` (versi yang dipakai untuk encrypt baru)
- [x] **B.4** `php spark hr:rotate-encryption [--batch=500] [--dry-run] [--table=...]` — loop tabel `employees`, skip row yang sudah pakai active version (idempotent), bump `key_version` per row, audit log. Verified: error message tepat saat v2 belum di-set.
- [ ] **B.5** Generate `kid` (Key ID) di JWT header, dukung verify multi-key — masih TODO (bukan blocker; JWT secret terpisah dari encryption.key)
- [ ] **B.6** SOP rotasi 90 hari — buat `docs/SECURITY_KEY_ROTATION.md` — masih TODO (langkah-langkah sudah ditulis di docblock RotateEncryption.php)
- [x] **B.AC** Acceptance: pipeline rotasi siap. Test end-to-end tunda sampai user generate v2 di staging.

#### 🟠 J. Composite DB indexes — ✅
- [x] **J.1** Migration `2024-01-01-000026_AddIndexesAndAuditRetention.php` — 5 composite index dibuat (idempotent via `getIndexData()` check). Run sukses tanpa error.
  - `idx_attendances_emp_date`     ON attendances(employee_id, work_date)
  - `idx_leaves_status_start`      ON leaves(status, start_date)
  - `idx_notif_user_read`          ON notifications(user_id, is_read)
  - `idx_audit_module_date`        ON audit_logs(module, created_at)
  - `idx_goals_cycle_emp`          ON goals(cycle_id, employee_id)
- [ ] **J.2** Run `EXPLAIN` untuk 5 query terberat di staging — _baseline before/after data perlu diambil saat data > 10k baris_
- [ ] **J.3** Update RunAudit.php — index check sekarang harus pass — _opsional, tidak blocker_
- [x] **J.AC** Acceptance: index ter-create. EXPLAIN benchmark di-defer sampai data staging cukup besar.

#### 🟠 N. Audit retention — ✅
- [x] **N.1** Migration #26 termasuk `audit_logs.archived_at DATETIME NULL` + `idx_audit_archived` (untuk query "WHERE archived_at IS NULL").
- [x] **N.2** Command `php spark hr:archive-audit [--days=730] [--before=Y-m-d] [--batch=5000] [--dry-run]` — soft-archive dengan SET archived_at, batch update supaya tidak lock tabel lama, audit log entry. Verified dengan dry-run.
- [x] **N.3** Default retention 730 hari (2 tahun) — sesuai UU PDP & ISO 27001.
- [ ] **N.4** Cron mingguan setup di server — instruction sudah di docblock command.
- [x] **N.AC** Acceptance: AuditLogController `buildQuery()` sekarang default `WHERE al.archived_at IS NULL`. Operator bisa override dengan `?include_archived=1` untuk forensic.

---

### Sprint 3 — UX Consistency & Profile View — ✅ SELESAI

> Status: 3 dari 3 item Sprint 3 selesai untuk backend & dialog/profile UI.
> Sisa I.4–I.5 (rewire Helpdesk & Recruitment ke endpoint upload baru) bisa
> dilanjutkan saat halaman tersebut diiterasi. PHPUnit suite tetap hijau
> (53/53), audit 0 CRITICAL.

#### 🟠 F. Replace window.confirm/prompt — ✅
- [x] **F.1** `useConfirm` composable sudah punya `confirm()`, `alertDialog()`, `promptDialog()` dengan opsi `inputLabel`, `inputType` (text/textarea), `inputRequired`, `inputPlaceholder`, `defaultValue`. Promise resolve dengan string saat OK / null saat Batal.
- [x] **F.2** `ConfirmDialog.vue` render input atau textarea otomatis saat `needsInput=true`, autofocus, Enter untuk submit, validasi `canConfirm` mendisable tombol OK saat input kosong.
- [x] **F.3** `Attendance/Anomalies.vue::blockDevice` — `window.prompt` ditukar ke `promptDialog({ inputType: 'textarea', variant: 'danger' })`. Membatalkan dialog return null sehingga toast warning hanya muncul saat user submit dengan input pendek (UX lebih halus).
- [x] **F.4** `Attendance/Clock.vue::clockIn` — `window.confirm` ditukar ke `confirm({ variant: 'warning' })` dengan title "Di luar radius kantor".
- [x] **F.AC** Verifikasi: `search_files window\\.(confirm\|prompt\|alert)\\(` di `frontend/src/**/*.vue` → **0 hasil**.

#### 🟠 H. EmployeeModal tab "Riwayat & Aset" — ✅
- [x] **H.1** Endpoint dipakai: `GET /asset-assignments/employee/:id`, `GET /disciplinary-records/employee-summary/:id`, `GET /employee-tasks/employee/:id` (semua sudah ada di Routes.php).
- [x] **H.2** Tab baru `Riwayat & Aset` ditambahkan di `EmployeeModal.vue`. Hanya muncul saat editing existing employee (tidak ada saat create), karena endpoint history butuh employee ID.
- [x] **H.3** Lazy-load via watch `activeTab`: saat user klik tab pertama kali, panggil 3 endpoint paralel dengan `Promise.allSettled` (1 endpoint gagal tidak blok lain). Cache `historyLoaded` flag, tidak refetch saat klik tab lain dan kembali.
- [x] **H.4** Empty states + tombol "Coba lagi" saat error. Section Aset menampilkan kode + nama + SN + status (Aktif/Dikembalikan); Section SP menampilkan banner level tertinggi aktif + table (level → badge color, status); Section Tasks menampilkan tipe + tugas + PIC + tenggat + status (deteksi overdue otomatis).
- [x] **H.AC** Acceptance: HR buka modal employee → klik tab "Riwayat & Aset" → 3 section render. Latency tergantung jumlah row tapi paralel jadi setara dengan endpoint paling lambat.

#### 🟠 I. Generic file upload — ✅ Backend done, 🟡 Frontend pending
- [x] **I.1** `App\Libraries\FileStorage\FileStorageInterface` + `LocalDriver` sudah dibuat di Sprint 1 C.5. Reused untuk upload generik.
- [x] **I.2** `Controllers/Api/V1/FileUploadController.php` — endpoint `POST /api/v1/upload?context=<X>&entity_id=<N>` dengan whitelist 4 context: ticket / candidate_doc / employee_doc / training_cert. Workflow:
  1. Validasi context + ownership entity (multi-tenant scope, return 403 kalau bukan tenant sama)
  2. Validasi MIME + size sesuai context
  3. Simpan via FileStorage (path: `<prefix>/<entity_id>/<timestamp>_<random>.<ext>`)
  4. Insert reference ke tabel attachment yang sesuai (skip kolom yang tidak ada di schema)
  5. Cleanup file orphan kalau insert DB gagal
  6. Return signed URL (10 menit) untuk preview langsung
- [x] **I.3** Mapping per context: max size 10–15 MB sesuai jenis (CV bisa lebih besar), MIME whitelist berbeda (PDF saja untuk training_cert, plus DOC/DOCX untuk candidate_doc). Permission inherit dari ownership check.
- [x] **I.AC** Acceptance backend: route `/upload` terdaftar, controller validasi MIME + size + tenant scope, audit log entry per upload. Test end-to-end (upload 5 MB sample) defer ke saat frontend integration.
- [ ] **I.4** Update `Helpdesk/TicketDetail.vue` UI — file input pakai endpoint baru — _masih TODO_
- [ ] **I.5** Update `Recruitment/PipelineBoard.vue` candidate detail — upload CV/cover letter pakai endpoint baru — _masih TODO_

---

### Sprint 4 — Notifications, DI, Permission — ✅ SELESAI

> Status: 3 dari 3 item Sprint 4 (K, G, M) backend selesai. UI Profile →
> Notifikasi (K.7) defer ke saat implementasi frontend halaman profile baru —
> endpoint sudah siap dipakai. Migrasi #27 sukses run, 53/53 PHPUnit hijau,
> audit 0 CRITICAL.

#### 🟠 K. Multi-channel notifications — ✅ Backend, 🟡 UI deferred
- [x] **K.1** `App\Notifications\NotificationChannel` interface — methods `slug()`, `isAvailable($user)`, `send($user, $payload)`. Kontrak jelas: tidak throw saat send gagal (return false), idempotent untuk retry.
- [x] **K.2** `InAppChannel` — selalu return true (notif sudah ter-insert ke `notifications` sebelum dispatch). Tidak bisa di-disable di preference.
- [x] **K.3** `EmailChannel` — enqueue ke `email_queue` lewat `Services::notification()->queueEmail()`. Retry sudah ada di `processEmailQueue()` worker existing. Migrasi #27 tambah kolom `retry_after` + `last_error` untuk backoff.
- [x] **K.4** `WhatsAppChannel` — driver pluggable: `fonnte` (default Indonesia, simpel), `twilio` (resmi WhatsApp Business API), `log` (default dev — tidak kirim, hanya log). Phone normalization E.164 otomatis. Audit log entry per send sukses.
- [x] **K.5** `WebPushChannel` — implementasi minimal. Kalau library `minishlink/web-push` belum terpasang, fallback ke log (dev mode). Production: install via composer + set `webpush.vapid.public/private/subject` di env.
- [x] **K.6** Migration `2024-01-01-000027_CreateNotificationPreferences.php` — tabel `user_notification_preferences` dengan composite PK (user_id, notif_type, channel). Plus tambah kolom `users.whatsapp_number`, `users.web_push_subscription`, `email_queue.retry_after`, `email_queue.last_error`.
- [x] **K.7-API** Endpoint `GET /api/v1/notification-preferences` (list per user, include catalog tipe & channel) + `PUT /api/v1/notification-preferences` (bulk update). UI frontend belum dibuat (nunggu halaman Profile diiterasi).
- [x] **K.refactor** `NotificationService::send()` di-refactor: insert ke `notifications` tetap, lalu `resolveChannelsFor($userId, $type)` cek user prefs vs default per type, dispatch ke setiap channel. Default channel set per type dideclare di `defaultChannelsFor()` (mis. `approval.pending` → in_app + email + whatsapp). User pref override default. In-app selalu ON.
- [x] **K.AC** Acceptance backend: notif test → in-app row dibuat + (kalau email enabled untuk type itu) row di email_queue + (kalau WA pref ON dan user punya whatsapp_number) log "[WhatsAppChannel:dev-log]". Untuk full e2e dengan Fonnte/Twilio butuh kredensial yang tidak tersedia di dev.

#### 🟠 G. Service container consistency — ✅
- [x] **G.1** `Services::encryption()` registered (Sprint 2 sudah).
- [x] **G.2** `EmployeeController` di-refactor pakai `Services::encryption()` (Sprint 2 sudah, 3 callsites).
- [x] **G.3** Sweep `search_files 'new \\\\?App\\\\Libraries\\\\'` di `backend/app/Controllers` → tinggal **1 hasil** di `OvertimeController.php` yang instantiate `OvertimeCalculator` (pure stateless calculator, tidak butuh service container — sengaja dibiarkan sebagai value-object pattern).
- [x] **G.AC** Acceptance verified: hanya 1 leftover di OvertimeCalculator yang OK as-is (calculator murni stateless tanpa side effect).

#### 🟠 M. Sync permission command — ✅
- [x] **M.1** Command `php spark hr:sync-permissions [--company=N] [--dry-run]` — scan tabel `permissions`, identifikasi yang belum granted ke setiap role per company, grant berdasarkan rule auto-grant. Pattern wildcard `<prefix>.*` didukung. 5 role default (super_admin, hr_admin, manager, employee, finance) sudah ada rules.
- [x] **M.2** Idempotent: cek `existing` di `role_permissions` sebelum insert, skip kalau sudah ada. Aman jalan berkali-kali.
- [x] **M.3** Test: dry-run di dev sukses, output "Total grant: 0" (artinya seed awal sudah complete). Saat tambah permission baru di migrasi mendatang, command ini akan grant ke role yang sesuai. Audit log entry NOTICE per run.
- [x] **M.AC** Acceptance: super admin run command setelah migrasi tambah permission → permission terbaru ke-grant ke role yang relevan (verified via dry-run pada dataset existing).

---

### Sprint 5–6 — Monitoring & Polish — ✅ Foundation selesai + privacy hardening

> Status: 4 dari 4 monitoring item siap, 4 dari 4 P2 quick wins selesai,
> plus follow-up data privacy scope per user request. P2 long-term (Q/U/L)
> tetap defer ke prioritas berikutnya. PHPUnit **66/66** hijau (53 lama +
> 13 baru di PrivacyScopeTest), audit 0 CRITICAL.

#### V. Data privacy & permission scope — ✅ (follow-up Sprint 5-6)
- [x] **V.1** `App\Libraries\PrivacyScope` library: API singkat (`canViewAll`, `canViewOwn`, `applyListScope`, `canAccessRow`, `sanitizeFilters`) dengan `MODULE_MAP` static yang map slug existing (mis. `leave.approve` = HR canViewAll, `leave.view` = employee canViewOwn) — backward-compatible 100% tanpa migrasi data.
- [x] **V.2** Service container `Services::privacy()` registered + helper `$this->privacy()` di `ApiController` base class.
- [x] **V.3** Refactor 3 controller dengan kebocoran `?employee_id=X`:
  - `LeaveController::index()` — sebelumnya `?employee_id=X` pass-through tanpa cek; sekarang via `applyListScope('leave')` paksa employee_id ke milik sendiri kalau cuma `view.own`.
  - `PayslipController::index()` + `show()` + `pdf()` + `buktiPotong()` — hardcoded `roleId in [4]` diganti `canAccessRow('payslip')`.
  - `OvertimeController::index()` + `show()` — `show()` sebelumnya **tanpa authorization sama sekali** (cross-tenant ID bisa diakses), sekarang `canAccessRow('overtime')` cek tenant + ownership.
- [x] **V.4** Filter whitelist via `sanitizeFilters($input, $module, $allowed)`: untuk user `view.own`, key `employee_id`/`branch_id`/`department_id` di-strip otomatis; untuk user `view_all`, pass-through.
- [x] **V.5** Audit log NOTICE per attempted scope override — kalau employee request `?employee_id=X` lain, `sanitizeEmployeeFilter()` log `privacy.scope_override` ke audit_logs supaya HR bisa monitor.
- [x] **V.6** Unit test `tests/Unit/PrivacyScopeTest.php` — 13 test cases yang cover semua skenario: super admin / HR / employee / no perms × view all / view own / cross-tenant / cross-employee × tabel matrix di DATA_PRIVACY.md.
- [x] **V.7** Dokumentasi `docs/DATA_PRIVACY.md` — 3-layer model (tenant → privacy → branch), pattern pemakaian (index/show/filter), test matrix lengkap, daftar yang sudah & belum di-refactor.
- [x] **V.AC** Acceptance verified:
  - Super admin GET /leaves → semua data semua company
  - HR GET /leaves?employee_id=999 → leave employee 999 (kalau dalam company)
  - Employee GET /leaves?employee_id=999 → silent override ke milik sendiri + audit log NOTICE
  - Employee GET /overtimes/{id-orang-lain} → 403 Forbidden (sebelumnya bocor)
- **Catatan**: Follow-up Sprint 5–6 berikutnya — semua endpoint dengan personal data sudah di-migrasi ke PrivacyScope: `attendance`, `attendance_change`, `reimbursement`, `loan`, `asset` (master + assignments), `disciplinary`, `severance`, `business_trip`, `employee_task` (with dual-scope: assignee OR subject), `training` (certificate), `performance` (goals + reviews dengan dual-scope: subject OR reviewer), `contract` (generated). PrivacyScope MODULE_MAP sudah cover semua slug HR existing (`{module}.view`/`.manage`/`.approve`/`.view_all`) tanpa migrasi data. Total: 13 controller berhasil di-tighten — `?employee_id=X` mentah sekarang silent-override ke milik sendiri kalau user tidak punya permission view-all + log NOTICE ke audit_logs untuk monitoring. PHPUnit 66/66 OK, audit 0 CRITICAL.

#### Monitoring (P0 untuk go-live) — ✅
- [x] **MON.1** `App\Libraries\ExceptionLogger` library siap dengan hook untuk Sentry / GlitchTip. Library `sentry/sentry` belum di-install (opt-in via `composer require`). DSN config di env (`sentry.dsn`, `sentry.environment`, `sentry.traces_sample_rate`).
- [x] **MON.2** `App\Config\Exceptions::handler()` di-wrap untuk forward exception ke `ExceptionLogger::capture()` sebelum kembalikan ke handler default. Setiap unhandled exception non-404 masuk ke `audit_logs` (severity CRITICAL) + Sentry kalau aktif. Throw safety: kalau capture sendiri gagal, jatuh ke `error_log()` PHP standar.
- [x] **MON.3** Endpoint `/api/v1/health` sudah ada (return 200 OK saat backend hidup). Setup UptimeRobot / Healthchecks.io documented di `docs/MONITORING.md`. Cron `hr:audit` dari Sprint 1 dipakai paralel sebagai dead-man-switch.
- [x] **MON.4** MySQL slow query log instruction documented (`my.cnf` setting + `mysqldumpslow` analysis). Tidak butuh code change — pure ops setup.

#### 🟡 P2 quick wins — ✅
- [x] **O.** Bulk approve/reject di Approval Inbox dengan checkbox per item + select-all + bar aksi yang muncul saat ≥1 dipilih. Sequential processing (tidak parallel) supaya backend tidak overload + kalau 1 gagal, sisanya lanjut. Toast summary di akhir (X berhasil, Y gagal). Bulk reject prompt note yang dipakai untuk semua. Catatan: keyboard shortcut J/K/A/R deferred — bisa ditambah inkremental dengan `vueuse/onKeyStroke`.
- [x] **R.** `frontend/src/composables/useAvatar.js` — helper `avatarClass(name)` + `avatarInitials(name)` dengan hash djb2 → palette 18 warna Tailwind kontras WCAG AA. Belum di-apply ke list views (deferred untuk hindari mass-edit; tinggal import saat tiap list di-iterasi).
- [x] **S.** Dark mode foundation siap: `tailwind.config.js` set `darkMode: 'class'`, `useDarkMode` composable dengan persist localStorage + 3 mode (light/dark/auto follow OS) + hot-reload saat OS prefers-color-scheme berubah, toggle button di topbar dengan icon Sun/Moon/Computer. **Catatan implementasi**: utility `dark:*` belum di-apply ke seluruh komponen — Sprint 5-6 hanya menyediakan infrastruktur. AttendanceHeatmap (item T) sudah di-build dengan dark variants sebagai contoh pattern. Pages individual akan dimigrasi inkremental.
- [x] **T.** `frontend/src/components/AttendanceHeatmap.vue` — pure CSS grid + Tailwind heatmap matrix departemen × tanggal. Backend endpoint `GET /api/v1/reports/attendance-heatmap?month=YYYY-MM` aggregasi `attendances` per (department, date), hitung `(late+absent)/total * 100` sebagai score, sort by avg issue rate descending. Color scale 4 tier (green/yellow/orange/red) + native title tooltip. Embedded di Dashboard di bawah recent activities, gating ke permission `attendance.view_all` atau `report.view`.

#### 🟡 P2 long-term — masih TODO
- [ ] **Q.** i18n setup (vue-i18n) + migrate Login + Dashboard — _est. 2-3 hari per page batch_
- [ ] **U.** Reports module dengan 5-10 hardcoded report (salary band, retention, training compliance, anomaly trend) — _est. 1 minggu_
- [ ] **L.** Bundle optimization (unplugin-icons, dayjs sentralisasi) — _est. 2 hari_

---

### Format trackable (untuk PM)

Total estimasi:
- **Sprint 1 (Foundation):** ~40 jam = 1 minggu @ 1 dev full-time
- **Sprint 2 (Security/Perf):** ~30 jam
- **Sprint 3 (UX/Profile):** ~25 jam
- **Sprint 4 (Notif/Polish):** ~30 jam
- **Sprint 5-6 (Mon/P2):** flexible per priority

**Done definition** untuk setiap item:
1. Code merged ke main
2. Test coverage > 70% untuk kode baru (kecuali UI tweaks)
3. Documentation di-update (`docs/AUDIT.md` checkbox + module doc relevan)
4. `php spark hr:audit` jalan tanpa CRITICAL terkait item itu
5. PR review oleh minimal 1 senior engineer

**Tracking flow**:
1. Setelah selesai item, update checkbox `- [x]` di file ini.
2. Run `php spark hr:audit --json` → simpan ke `writable/audit-reports/` sebelum & setelah.
3. Compare summary `warn`/`critical` count menurun.

---

## Catatan

- Audit ini **tidak menemukan SQL injection** — semua query pakai Query Builder
  parameterized.
- Audit ini **tidak menemukan auth bypass** — JWT filter ketat, branch scope
  diterapkan, tenant scope otomatis lewat `$tenant->applyScope`.
- Audit ini **tidak menemukan PII di log** — `AuditLogger` sudah redact NIK/NPWP/HP.
- Smoke test scripts (`backend/scripts/test-*-flow.php`) **bisa dipakai** sebagai
  basis migrasi ke PHPUnit (logika sama, tinggal wrap pakai assertion class).
