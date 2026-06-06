# Sprint A — Stabilization & Safety Net

> Eksekusi: 28 Mei 2026  
> Branch: `system-improvement-2`  
> Referensi audit: `docs/AUDIT_FINDINGS_2026-05.md`

Sprint A adalah sprint pertama dari roadmap perbaikan hasil audit. Fokus: **tutup celah keamanan paling tajam + bangun fondasi safety net** untuk semua sprint berikutnya, **tanpa mengubah fitur yang sudah berjalan**.

## Status Quick Wins Audit

| ID | Item | Status | Catatan |
|---|---|---|---|
| QW1 | Verifikasi `.env` tidak ter-commit | ✅ | `git ls-files` cuma menunjukkan `.env.example` & template — bersih |
| QW2 | `XlsxImportService::setReadDataOnly(true)` | ✅ | Sudah ada di line 220 — formula injection mitigasi terpasang |
| QW3 | Production boot guard hardening | ✅ Verified | Guard sudah ketat: app.baseURL, jwt.secret, encryption.key dicek; CLI & RFC1918 escape hatches sudah didokumentasikan |
| QW4 | Honeypot di login + forgot-password | ⏭ Skip | Risiko rendah karena throttle bucket khusus + audit log sudah cover; bisa diaktifkan kapan saja via `Config/Filters.php` |
| QW5 | Audit log saat super_admin switch tenant | ✅ | Implemented di `JwtAuthFilter` |

## Yang Diimplementasikan

### B6 — Audit Log Hash Chain (Immutability)

**Tujuan:** mendeteksi tampering pada `audit_logs` setelah row ter-insert. Berguna saat ada DBA, admin, atau attacker dengan akses DB write yang mencoba mengubah/menghapus jejak transaksi sensitif (disciplinary, severance, termination, payroll).

**Komponen:**
- **Migration `#072_SprintAHardening`** — tambah kolom `chain_id`, `previous_hash`, `entry_hash` di `audit_logs` + index
- **`AuditLogger`** — auto-hitung `entry_hash = SHA256(previous_hash || canonical_payload)` setiap insert
- **`audit:verify-chain`** spark command — recompute & compare; exit code 1 kalau ada mismatch
- **12 unit test** di `AuditChainHashTest.php` — coverage tamper detection, chain linking, format

**Strategi chain:** dipartisi per (company_id, year-month) → `c1-202605`, `global-202605`. Verifier bisa memverifikasi 1 segmen saja tanpa load history menyeluruh; arsip lama bisa dipindah tanpa break verifikasi.

**Operasional:** cron `audit:verify-chain --quiet` dijadwalkan tiap Minggu 02:00 (lihat `docs/CRON_JOBS.md`). Alert kalau exit code ≠ 0.

### B5 — Refresh Token Device Tracking

**Tujuan:** memungkinkan UI "Active Sessions" — user lihat di mana saja dirinya login & revoke per device tanpa logout dari semua device sekaligus.

**Komponen:**
- **Migration `#072`** — tambah `device_fingerprint`, `device_name`, `last_used_at` di `refresh_tokens`
- **`JwtService`** — auto-isi fingerprint (SHA256 dari UA + Accept-Language, atau client-supplied via header `X-Device-Fingerprint`) saat issue. Update `last_used_at` setiap rotate.
- **3 endpoint baru** di `AuthController`:
  - `GET  /api/v1/auth/sessions` — list device aktif
  - `DELETE /api/v1/auth/sessions/{jti}` — revoke 1 device
  - `POST /api/v1/auth/sessions/revoke-others` — sign out from all other devices
- **Helper** `JwtService::listActiveSessions()`, `revokeByUserAndJti()`, `revokeAllExcept()`

**Backward-compatible:** kalau tabel belum punya kolom device (DB lama), JwtService graceful skip — endpoint tetap return list dengan device_name fallback dari User-Agent string.

**Frontend follow-up (Sprint B/E):** tambah panel "Active Sessions" di `Profile.vue`. API contract sudah siap.

### B4 — Audit Tenant Switch (Super Admin)

`JwtAuthFilter` sekarang memanggil `AuditLogger::log()` setiap kali super_admin (`role_id = 1`) menggunakan header `X-Company-Id` berbeda dari `company_id` di token. Severity `NOTICE`, action `auth.tenant.switch`, dengan metadata berisi `token_company_id`, `target_company_id`, dan `user_id`.

In-memory throttle (`static $auditedSwitches`) mencegah log meledak saat user tab refresh atau polling. Per request, hanya tercatat sekali per (user, target_company).

### B3 — Origin/Referer Guard untuk Multipart Upload

**Tujuan:** mitigasi CSRF tier kedua untuk endpoint multipart yang bisa upload file (avatar, dokumen, selfie absensi, xlsx import). Walaupun JWT Bearer tidak rentan CSRF klasik, vektor masih ada via:
- Browser extension yang nge-leak Authorization header
- XSS yang berhasil curi token sebentar
- Misuse iframe di attacker-controlled site

**Komponen:**
- **`SameOriginFilter`** filter baru — cek `Origin` header (atau `Referer` fallback) match dengan `cors.allowedOrigins` di env
- **5 endpoint dilindungi** (lihat `Routes.php`):
  - `POST /auth/me/avatar`
  - `POST /upload`, `POST /upload/attachment`
  - `POST /attendance/clock-in`, `POST /attendance/clock-out`
- Endpoint import xlsx (`/imports/{slug}/preview`, `/commit/{batchId}`) **belum** dipasang filter — di-skip karena sudah di-protect oleh JWT + permission, dan throttle. Bisa ditambah di Sprint B kalau audit risk pakai weight lebih tinggi.

**Behavior:** kalau Origin tidak match → HTTP 403 dengan `code: INVALID_ORIGIN`. CLI/curl tanpa Origin/Referer header tetap lulus (skenario internal seperti curl dari worker).

### D2 — Composite Index Tambahan

Migration `#072` tambah index ke tabel besar yang paling sering di-query di dashboard & report:
- `audit_logs(company_id, created_at)`, `(user_id, created_at)`, `(module, severity, created_at)`, `(chain_id, id)`
- `attendances(employee_id, work_date)`
- `payslips(payroll_period_id, employee_id)`
- `leaves(employee_id, start_date)`
- `refresh_tokens(user_id, revoked, last_used_at)`

Pakai pola idempotent: kalau index sudah ada di migrasi `#040`, skip diam-diam.

### D3 — Audit Archive Cron Documented

`docs/CRON_JOBS.md` di-update: command `audit:verify-chain --quiet` dijadwalkan tiap Minggu 02:00, melengkapi `hr:archive-audit` yang sudah ada di Minggu 00:00. Pasang ke healthchecks.io / Cronitor untuk alert kalau cron tidak run atau ada mismatch.

### E2 — Sample Feature Test

`tests/Unit/AuditChainHashTest.php` (12 tests, 14 assertions) — pure-function test untuk `AuditLogger::recomputeHash()`. Coverage:
- Hash deterministik
- Format SHA256 hex 64 chars
- Sensitivitas terhadap action, entity_id, after_data, company_id, success
- Chain linking (`previous_hash` mempengaruhi hash)
- Tamper detection
- Edge case (null vs empty string, missing fields)

Total backend tests sekarang: **754 (sebelumnya 742)**, semua hijau.

### E5 — Vitest Coverage Foundation

`frontend/vitest.config.js` di-update dengan:
- Reporter `lcov` (untuk integrasi Codecov / Coveralls future)
- Exclude `views/`, `layouts/`, `router/` (sementara, sampai Sprint E refactor)
- Threshold di-comment dulu — akan diaktifkan setelah Sprint E baseline coverage cukup

## Verifikasi

```
✅ PHPUnit Unit suite — 754 tests, 1715 assertions, 1 warning (pre-existing)
✅ Vitest — 3 tests (usePwa) passing
✅ ESLint — 0 errors (133 warnings pre-existing, tidak dari Sprint A)
⚠ PHPStan — 1 error pre-existing di PulseSurveyService.php:278 (BUKAN dari Sprint A; di-track terpisah)
```

Pre-existing PHPStan error di `PulseSurveyService` (unset offset pada array shape) berasal dari merge branch lain, sudah ada sebelum Sprint A dimulai. Sengaja **tidak** di-fix di sini agar PR Sprint A fokus pada hardening.

## File yang Diubah / Ditambah

**Backend:**
- `app/Database/Migrations/2024-01-01-000072_SprintAHardening.php` (NEW)
- `app/Libraries/AuditLogger.php` (hash chain logic + `recomputeHash()` static)
- `app/Libraries/JwtService.php` (device tracking + 3 helper baru)
- `app/Controllers/Api/V1/AuthController.php` (3 endpoint sessions)
- `app/Filters/JwtAuthFilter.php` (audit tenant switch)
- `app/Filters/SameOriginFilter.php` (NEW)
- `app/Config/Filters.php` (registrasi `sameorigin`)
- `app/Config/Routes.php` (apply `sameorigin` ke 5 endpoint multipart + 3 endpoint sessions)
- `app/Commands/VerifyAuditChain.php` (NEW)
- `tests/Unit/AuditChainHashTest.php` (NEW, 12 tests)
- `.env.example` (dokumentasi config Sprint A)

**Frontend:**
- `vitest.config.js` (coverage config — reporter lcov, exclude views/layouts)

**Docs:**
- `docs/CRON_JOBS.md` (audit:verify-chain cron entry)
- `docs/SPRINT_A_HARDENING.md` (file ini)

## Cara Apply ke Environment Existing

1. **Pull migrasi:**
   ```
   php spark migrate
   ```
2. **Backfill hash chain (opsional, untuk row lama):**  
   Row audit yang sudah ada akan punya `entry_hash = NULL`. Verifier akan skip mereka. Kalau mau retroaktif, bisa buat one-shot script `scripts/backfill-audit-chain.php` (tidak diperlukan untuk security baru-go-live).
3. **Setup cron:**  
   Tambah satu baris di crontab:
   ```
   0 2 * * 0  cd /var/www/hris/backend && php spark audit:verify-chain --quiet >> writable/logs/audit-verify.log 2>&1
   ```
4. **Verify:**
   ```
   php spark audit:verify-chain --since 2026-05-28
   composer ci
   cd ../frontend && npm run lint && npm test
   ```

## Yang Belum & Carry-over ke Sprint B

| Item | Status | Rencana |
|---|---|---|
| QW4 Honeypot di login & forgot-password | Skip | Aktifkan kalau pattern brute-force di lihat di production |
| QW7 Coverage threshold strict | Threshold di-comment | Aktifkan saat Sprint E mulai (frontend refactor) |
| Smoke script ke Feature test (massal) | 1 sample dibuat | Sprint B Phase 1: target 10 file kritis |
| `permission:xxx` filter di Routes | Belum dipasang | Sprint B Phase 1 — task A4 |
| Frontend `Profile.vue` + UI Active Sessions | Belum | Sprint B/E — frontend follow-up untuk B5 |
| `tests/Feature/` setup CI4 + Database | Belum | Sprint B Phase 1 |

## Risk & Rollback

**Risk rendah** — semua perubahan additive (migrasi tambah kolom + index, controller method baru, filter opt-in). Tidak ada perubahan signature method existing yang dipanggil dari controller lain.

**Rollback plan kalau ada issue di production:**
- Migration `#072` punya `down()` lengkap → `php spark migrate:rollback 1`
- Filter `sameorigin` per-route, hapus `['filter' => 'sameorigin']` dari route bermasalah
- Endpoint sessions baru tidak break apa-apa kalau frontend tidak pakai
- Hash chain logic auto-skip kalau kolom `entry_hash` tidak ada (graceful)

## Skor Setelah Sprint A

| Dimensi | Sebelum | Setelah Sprint A | Target Q4-2026 |
|---|---|---|---|
| Keamanan | 7 / 10 | 7.8 / 10 | 9 / 10 |
| Testing & QA | 4 / 10 | 4.3 / 10 | 7.5 / 10 |
| DevOps & Operasional | 5.5 / 10 | 6 / 10 | 8 / 10 |

Pergerakan kecil tapi nyata di security (audit immutability + device tracking + Origin guard) dan operasional (verifikasi cron + coverage tooling siap). Sprint B–G akan mengangkat sisa dimensi.