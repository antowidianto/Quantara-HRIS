# HRIS Mobile App — Module Recommendation

> Rekomendasi modul untuk aplikasi mobile (Android + iOS) berdasarkan
> codebase HRIS existing.
> **Audience:** product manager + tech lead saat scoping mobile project.
> **Update:** 26 Juli 2026 — keputusan stack diubah ke Flutter.
> Detail eksekusi: [MOBILE_FLUTTER_MIGRATION.md](MOBILE_FLUTTER_MIGRATION.md).
> Keputusan arsitektur: `docs/decisions/0002-mobile-flutter-migration.md`.

---

## 1. Filosofi Mobile-First

Mobile app HRIS **bukan miniaturisasi** seluruh fitur web. Tugasnya:

1. **Employee self-service** untuk hal yang frequency-tinggi atau time-sensitive (clock-in di lokasi, ajukan cuti pas dibutuhkan, lihat slip gaji habis transfer).
2. **Approver workflow** supaya manager tidak harus buka laptop untuk approve permintaan kecil.
3. **Notifikasi real-time** untuk event yang butuh respons cepat (approval pending, kontrak akan habis, cuti ditolak).

Modul yang **tetap di desktop** karena UI table/form berat:
- Master data (Branches, Departments, Positions, Shifts, dst.)
- Payroll period setup + calculate + bank export
- Bulk import/export xlsx
- Reporting & analytics dashboard
- User & role management
- BPJS rates configuration

---

## 2. Tier 1 — Wajib Ada di MVP (4–6 minggu)

Modul ini **80% pemakaian harian** karyawan. Tanpa ini, mobile app tidak punya value proposition.

### 2.1 Authentication

- Login email + password
- 2FA (TOTP + backup codes) — sudah ada di backend
- Refresh token device tracking — sudah ada (`B5`), mobile app jadi 1 device ID terpisah
- Biometric unlock (Face ID / fingerprint) untuk re-login cepat tanpa input password tiap kali
- "Sign out this device" + "Sign out all devices" (endpoint sudah ada)

**Backend yang dipakai:** `/api/v1/auth/login`, `/api/v1/auth/2fa/verify`, `/api/v1/auth/refresh`, `/api/v1/auth/sessions/*`.

### 2.2 Attendance / Presensi

Modul **paling kritis** untuk mobile karena harus di-akses di lokasi kerja.

- **Clock-in / Clock-out** dengan:
  - GPS validasi (radius dari `WorkLocation` master data)
  - Selfie wajib (sudah ada `AntiCheatService`)
  - Offline buffer: kalau no signal, simpan lokal + sync saat online (idempotency key)
- **Riwayat presensi bulan berjalan** (kalender view dengan badge late/absent)
- **Request shift change** atau koreksi attendance
- **Notifikasi** kalau lupa clock-out atau clock-in di luar radius
- **Catatan:** liveness detection + face match (audit B9) wajib untuk anti-buddy-punching

**Backend:** `/api/v1/attendance/clock-in`, `/clock-out`, `/api/v1/attendance/me`, `/api/v1/attendance-changes`.

### 2.3 Leave / Cuti

Tinggi-frequency request, biasanya butuh urgent (sakit dadakan, urusan keluarga).

- **Saldo cuti** per leave_type (annual, sick, maternity, paternity, dll.)
- **Ajukan cuti baru** dengan date picker (wajib aware long sick leave UU 13/2003 — calculator sudah ada di `LongSickLeaveCalculator`)
- **Half-day leave** support (calculator sudah di Sprint Final / C10)
- **Riwayat pengajuan** + status (pending/approved/rejected/cancelled)
- **Cancel** request yang masih pending atau approved (sebelum tanggal mulai)
- **Notifikasi** saat di-approve/reject
- **Conflict warning** kalau overlap dengan leave existing (model `LeaveRequestModel::overlappingFor()` sudah ada)

**Backend:** `/api/v1/leaves`, `/leaves/{id}/cancel`, `/api/v1/leave-balances/me`, `/api/v1/leave-types`.

### 2.4 Approval Inbox

Untuk **manager / supervisor / HR**. Tanpa ini, request akan menumpuk karena mobile-only worker tidak akan buka web.

- **Daftar pending approval** (leave, overtime, JIC, MPP, transfer, business trip, reimbursement)
- **Bulk action**: approve all leave dari 1 tim
- **Detail view per request** dengan attachment (dokumen, foto)
- **Approve / reject dengan note**
- **Delegasi approval** (kalau approver cuti — `ApprovalDelegationResolver` sudah ada)
- **Push notification** saat ada request baru yang butuh approval

**Backend:** `/api/v1/approvals/pending`, `/api/v1/{module}/{id}/approve`, `/api/v1/{module}/{id}/reject`. Sebagian endpoint sudah ada per modul, sebagian perlu unify.

### 2.5 Payslip

Karyawan check slip habis transfer gaji.

- **List payslip** per bulan (dari period terbaru)
- **Detail payslip**:
  - Earning breakdown (basic, overtime, THR, bonus, reimbursement)
  - Deduction breakdown (BPJS Kesehatan/JHT/JP, JKP gov subsidy, PPh21 TER, loan, absent, late)
  - Take-home pay highlighted
- **Download PDF** (cache ke storage mobile untuk offline access)
- **Compare** dengan bulan sebelumnya (delta + visual)
- **Notifikasi** saat payslip baru di-publish

**Backend:** `/api/v1/payslips/me`, `/api/v1/payslips/{id}` (sudah ada `PayslipModel::listForEmployee()`).

### 2.6 Profile & Settings

- View employee profile lengkap (Employee 360 sudah ada — tampilkan ringkas saja di mobile)
- Edit field non-sensitif (phone, emergency contact, alamat domisili)
- Field sensitif (NIK, NPWP, bank account) **read-only** atau via verifikasi (workflow review HR)
- Upload avatar baru
- Ubah password
- Manage 2FA + backup codes
- Theme preference (dark mode — `useDarkMode` sudah ada)
- Language switcher (id / en)
- Push notification preferences per kategori

**Backend:** `/api/v1/me`, `/api/v1/employees/{id}` (subset), `/api/v1/me/avatar`, `/api/v1/auth/password/change`, `/api/v1/auth/2fa/*`.

---

## 3. Tier 2 — Sprint 2 (4 minggu setelah MVP)

Modul ini **enhance employee experience** dan masuk akal di mobile, tapi bukan blocker MVP.

### 3.1 Overtime Request

- Ajukan lembur (work_date, jam, alasan, perkiraan amount)
- Riwayat overtime + status approval
- Total overtime hours bulan berjalan (vs limit company per PP 35/2021)
- Notifikasi saat di-approve (dengan info masuk payroll period kapan)

**Backend:** `/api/v1/overtimes/me`, `/api/v1/overtimes` (POST). Model `OvertimeRequestModel` sudah ada.

### 3.2 Reimbursement

Karyawan submit struk dari mobile (foto langsung dari kamera).

- Capture foto struk → upload + OCR auto-extract (vendor, total, date) — `OcrService` sudah ada
- Edit hasil OCR sebelum submit
- Multi-receipt per request
- Track status reimbursement
- Notifikasi saat approved + jadwal disbursement

**Backend:** `/api/v1/reimbursements/me`, `/api/v1/reimbursements` (POST + multipart upload).

### 3.3 Business Trip / Perjalanan Dinas

- Ajukan business trip (destination, date, perdiem)
- Approval workflow
- Saat trip aktif: clock-in di lokasi tujuan (bypass radius office)
- Submit laporan + struk perjalanan setelah trip

**Backend:** `/api/v1/business-trips/me`. `BusinessTripService` sudah ada.

### 3.4 Helpdesk / Internal Ticket

- Submit ticket ke IT, HR, Finance, GA
- Track status ticket
- Chat / komentar di ticket
- Notifikasi saat di-respond
- SLA countdown (sudah ada `CheckHelpdeskSla` cron)

**Backend:** `/api/v1/helpdesk-tickets/me`. Modul sudah ada.

### 3.5 Notifications Center

- In-app notification list (read/unread)
- Filter per kategori (approval, attendance, payroll, training, dst.)
- Settings preferensi push
- Snooze / mute per kategori

**Backend:** `/api/v1/notifications/me`. Sudah ada.

---

## 4. Tier 3 — Sprint 3+ (Optional Enhancement)

Modul ini "nice to have" — adopsi tergantung user feedback dari MVP. Banyak yang **tidak cocok** di mobile dan harus tetap di web.

### 4.1 Performance & Goals

- View OKR / KPI yang assigned
- Update progress goal
- Submit self-review
- Lihat 360° feedback dari peer (anonymized)
- Calendar review meeting

**Catatan:** form review yang panjang (essay 500+ char) tetap lebih nyaman di desktop. Mobile app tampilkan list + summary, tap "edit" → buka WebView atau redirect ke web.

### 4.2 Training / Learning

- Training catalog (yang assigned + yang available)
- Enroll training
- Track progress training (kalau ada e-learning module)
- Sertifikat completion download
- Reminder upcoming training

### 4.3 Career / Recruitment Internal

- Internal job marketplace (`TalentPoolService` H3.6)
- Apply ke posisi internal
- Lihat status aplikasi internal

**Catatan:** career portal external (publik) tetap di web — kandidat eksternal pakai browser.

### 4.4 Attendance Approver Quick Actions

- Approve attendance correction batch dari list
- Lihat tim presensi hari ini (heatmap mini)

### 4.5 Onboarding Buddy / Probation

Untuk karyawan baru:
- Checklist onboarding (sudah ada di backend)
- Orientation materials (PDF, video)
- Probation goals tracking
- 30/60/90-day check-in form

### 4.6 Chat / Messaging Internal

**TIDAK direkomendasikan** dibangun dari nol. Pakai integrasi WhatsApp Business / MS Teams / Slack via webhook (`ProcessWebhooks` cron sudah ada). Mobile HRIS link → app chat existing.

---

## 5. Yang Sengaja TIDAK Masuk Mobile

| Modul                       | Alasan tidak di mobile                                  |
|-----------------------------|---------------------------------------------------------|
| Master data CRUD            | Banyak field, paling baik di tabel desktop              |
| Payroll calculate + finalize| Operasi mass + bank export, butuh konfirmasi multi-step |
| Bonus run calculate         | Sama: bulk operasi enterprise                           |
| Bulk import xlsx            | File picker mobile clunky, file kadang besar            |
| User & role management      | Admin task, low frequency                               |
| Reporting & analytics dashboard | Butuh chart wide + drill-down                       |
| BPJS / TER rates config     | Setting tahunan, tidak perlu mobile                     |
| Webhook & integration setup | Admin/dev task                                          |
| Org structure designer      | Butuh layar lebar untuk drag-drop tree                  |
| MPP request creation lengkap| Form panjang justification — tampilkan list + status saja di mobile |

---

## 6. Architecture Recommendation

### 6.1 Pilihan Stack

| Stack                         | Pro                                                  | Con                                              |
|-------------------------------|------------------------------------------------------|--------------------------------------------------|
| **React Native + TypeScript** | Talent pool JS/TS besar | Dua ekosistem JavaScript berbeda (Vue dan React), bridge/plugin migration risk |
| **Flutter**                   | UI konsisten, native compilation, stateful hot reload, single codebase | Perlu upskilling Dart dan rebuild UI |
| **Native iOS (Swift) + Native Android (Kotlin)** | Best perf + native UX                                | 2× effort, 2× maintenance                       |
| **Capacitor + Vue 3 (reuse Vue dari web)** | Reuse codebase + composable web              | UX kurang native, navigation transitions terasa beda |

**Keputusan:** **Flutter** menjadi stack aktif. Alasan:
- Satu codebase Android+iOS dengan widget dan rendering yang konsisten.
- Pemisahan UI, view model, repository, dan service cocok untuk domain HRIS
  yang besar serta mudah diuji.
- Dukungan resmi untuk stateful hot reload, build Android/iOS, navigation,
  offline-first architecture, dan integrasi Firebase.
- Arsip dan riwayat Git React Native dipakai sebagai referensi behavior saat
  diperlukan, bukan dihidupkan kembali atau diterjemahkan per komponen.

Stack aktif: Riverpod (state + DI), `go_router` (navigation), Dio (HTTP),
`flutter_secure_storage` (secret), storage lokal (cache/outbox), dan
`firebase_messaging` (push).

### 6.2 API Strategy

Backend **tidak butuh perubahan major** — API v1 sudah RESTful + JWT-based. Yang perlu di-tambah:

1. **Mobile-friendly endpoints** dengan response yang slim (jangan kirim 50 field employee kalau hanya butuh 5 untuk list).
   - Implementasi: `?fields=id,full_name,avatar` query param atau `Accept: application/vnd.hris+json; profile=mobile` header.
2. **Pagination + filter lebih agresif** untuk list (employee 5K row, mobile butuh 20 per page max).
3. **Push notification endpoint:**
   - `POST /api/v1/devices/register` — pair device_token (FCM) ke user
   - `DELETE /api/v1/devices/{id}` — unregister saat logout
4. **Offline sync support:**
   - Idempotency key di POST endpoint critical (clock-in, leave create) — sebagian sudah ada (`JobQueue::enqueue` pattern)
   - Conflict resolution kalau client edit offline dan server berubah duluan
5. **Bandwidth optimization:**
   - GZIP wajib (pasti sudah di nginx)
   - Image thumbnail variant (avatar 64×64 untuk list, 256×256 untuk detail) — dipublish lewat `/api/v1/media/{id}?size=thumb`

### 6.3 Security Considerations

- **Certificate pinning** untuk anti-MITM
- **Jailbreak/root detection** + tolak app run di device compromised (untuk modul attendance, anti-cheat)
- **Encrypted local storage** (iOS Keychain, Android EncryptedSharedPreferences) untuk JWT + refresh token
- **App attestation** (Apple App Attest, Google Play Integrity API) saat clock-in supaya backend tahu request dari official app, bukan dari emulator/Frida hook
- **Auto-lock** setelah X menit idle, butuh re-auth biometric
- **Wipe local data** saat "Sign out all devices" event diterima (push notif silent)

### 6.4 Offline-First Pattern

Critical untuk modul attendance (sering kerja di lapangan no signal):

- **Read-side:** SQLite/Realm untuk cache. Sync diff dari server saat online.
- **Write-side:** Outbox pattern — simpan request lokal, replay ke API saat online (dengan idempotency key supaya tidak double-submit kalau backend sudah terima tapi response hilang).
- **Conflict:** last-write-wins kecuali untuk tabel finansial (payslip, payroll) yang read-only di mobile.

---

## 7. Roadmap Mobile App

| Sprint | Deliverable                                   | Duration |
|--------|-----------------------------------------------|----------|
| M0     | Setup Flutter project + CI + skeleton screens | 2 minggu |
| M1     | Auth + Profile (Tier 1.1, 1.6)               | 3 minggu |
| M2     | Attendance + offline sync (Tier 1.2)         | 4 minggu |
| M3     | Leave + Payslip (Tier 1.3, 1.5)              | 3 minggu |
| M4     | Approval Inbox (Tier 1.4)                    | 2 minggu |
| M5     | Beta release + bug fix + UAT                 | 3 minggu |
| **MVP** | **Total: ~4 bulan kalendar (1 mobile dev)**  |          |
| M6+    | Tier 2 modules (Overtime, Reimbursement, ...) | iterative |

---

## 8. Backend Readiness untuk Mobile

Yang sudah siap (✅) vs yang perlu disiapkan (⚠️):

| Komponen                                  | Status |
|-------------------------------------------|--------|
| JWT auth + refresh token + device tracking | ✅     |
| 2FA TOTP + backup codes                    | ✅     |
| Multi-tenant scope (X-Company-Id header)   | ✅     |
| Audit log immutable hash chain             | ✅     |
| RESTful API v1 stabil                      | ✅     |
| Rate limit per endpoint                    | ✅     |
| File storage abstraction                   | ✅     |
| OCR service (untuk reimbursement scan)     | ✅     |
| Anti-cheat attendance (selfie, GPS)        | ✅     |
| Long sick / tenure / half-day calculator   | ✅ (Sprint Final) |
| **Push notification (FCM/APNs)**           | ⚠️ Belum — perlu `DeviceToken` model + `NotificationDispatcher` updates |
| **`?fields=` query param untuk slim response** | ⚠️ Belum |
| **Idempotency key di POST critical**       | ⚠️ Sebagian (lewat `JobQueue`); perlu standardisasi |
| **OpenAPI spec generator** (untuk type-safe client mobile) | ⚠️ I3 carry-over |
| **Liveness detection + face match**        | ⚠️ B9 carry-over |
| **Cert pinning endpoint discovery**        | ⚠️ Belum |

Saran: **sebelum start mobile, tutup item ⚠️ di atas**. Total ~2 sprint backend prep work. Ini akan menghemat 50% effort mobile vs ditambal-tambal saat development.

---

## 9. Pertimbangan Bisnis

- **Distribusi:** Public (Play Store + App Store) atau MDM private? Untuk customer enterprise di Indonesia, banyak yang prefer **enterprise distribution lewat MDM** (Microsoft Intune, Workspace ONE) supaya tidak kena review Apple/Google + bisa enforce policy.
- **Pricing:** mobile biasanya **bundled gratis** dengan SaaS web (tidak charge separate). Yang charge: extension untuk modul advanced (live attendance map, fleet tracking).
- **Support:** crash reporting wajib (Sentry / Firebase Crashlytics — `Telemetry` library di Sprint Final ready untuk mobile counterpart).
- **App store compliance:** UU PDP wajib di privacy policy + data deletion flow accessible dari app.

---

## 10. Quick Reference — Mobile MVP Features

```
Tier 1 (MVP, 4-6 minggu):
  ✓ Auth (login + 2FA + biometric)
  ✓ Attendance (clock-in/out, GPS, selfie, offline)
  ✓ Leave (saldo, ajukan, riwayat)
  ✓ Approval Inbox (manager view)
  ✓ Payslip (list + detail + PDF)
  ✓ Profile (view + edit non-sensitive)

Tier 2 (Sprint 2, 4 minggu):
  ✓ Overtime request
  ✓ Reimbursement (foto + OCR)
  ✓ Business trip
  ✓ Helpdesk ticket
  ✓ Notifications center

Tier 3 (Optional):
  ✓ Performance summary
  ✓ Training
  ✓ Internal job marketplace
  ✓ Onboarding for new hires

NOT in mobile (stays in web):
  ✗ Master data CRUD
  ✗ Payroll calculate / bank export
  ✗ Bulk import
  ✗ Admin / role management
  ✗ Reporting dashboard
  ✗ Org structure designer
```
