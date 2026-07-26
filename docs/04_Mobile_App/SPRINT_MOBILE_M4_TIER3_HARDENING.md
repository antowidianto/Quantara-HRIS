# Sprint M4 — Tier 3 Lite + Hardening + Polish

> **Arsip React Native:** catatan ini mendokumentasikan implementasi legacy.
> Feature baru dan migrasi mengikuti `MOBILE_FLUTTER_MIGRATION.md`.

> Sprint mobile keempat. Fokus: tutup gap dari MOBILE_APP_PLAN Tier 3
> (modul read-mostly), pasang gerbang produksi (idle auto-lock,
> minimum-version check, error boundary), dan tarik i18n keluar dari
> komentar jadi pemakaian aktif.
> **Update:** 28 Mei 2026.

---

## 1. Konteks

Setelah M0-M3 selesai (Tier 1 MVP + Tier 2 + integration layer), tiga
hal yang masih membuat app belum siap di-distribusikan ke karyawan:

1. **Tier 3 belum ada di mobile** padahal API backend sudah siap.
   Performance/Goals, Training, Onboarding-Offboarding tasks adalah
   kandidat read-mostly yang justru mudah di-mobile-kan.
2. **Gerbang produksi belum lengkap** — tidak ada idle auto-lock
   (audit security gap), tidak ada minimum-version gate (begitu API
   v2 keluar, mobile lama akan crash), tidak ada error boundary
   (runtime exception langsung crash app).
3. **i18n stub belum dipakai** — semua label hard-coded id-only.

Sprint ini menutup ketiganya tanpa membuka migration baru. Cukup satu
endpoint backend tambahan, sisanya kode mobile + tests.

---

## 2. Yang ditambahkan

### 2.1 Tier 3 read-mostly modules

#### Performance (`mobile/src/features/performance/`)

- `api.ts` — `PerformanceApi.my()`, `checkIn(goalId, input)`,
  `acknowledgeReview(reviewId)`. Tipe `PerformanceGoal` &
  `PerformanceReview` mirror payload backend.
- `PerformanceScreen.tsx` — daftar goals dengan progress bar +
  modal check-in (progress 0–100, note ≤ 280 char), daftar reviews
  dengan tombol "Setuju & acknowledge" untuk status SUBMITTED /
  FINALIZED.
- Tidak menyediakan create-goal atau submit-review (essay panjang →
  tetap di web).

**Backend dipakai:** `GET /performance/my`,
`POST /goals/{id}/check-in`, `POST /performance-reviews/{id}/acknowledge`.
Semua sudah ada di Routes.php, tidak butuh migration baru.

#### Training (`mobile/src/features/training/`)

- `api.ts` — `TrainingApi.catalog()` (POSTED), `myRegistrations()`
  (filter `?registrant=me`), `register(id)`, `cancelRegistration(regId)`,
  `myCertificates()`.
- `TrainingScreen.tsx` — toggle Catalog vs Riwayat, register/cancel
  flow, plus daftar sertifikat di bawah.

**Backend dipakai:** `GET /trainings`, `POST /trainings/{id}/register`,
`POST /training-registrations/{id}/cancel`, `GET /certificates`.

#### Tasks (`mobile/src/features/tasks/`)

- `api.ts` — `TasksApi.my({ type, status })`, `complete(id, note?)`.
  Auto-unwrap `data` array dari paginated response.
- `TasksScreen.tsx` — checklist onboarding/offboarding dengan toggle
  jenis, due-date highlight kalau overdue, tombol "Tandai selesai".

**Backend dipakai:** `GET /employee-tasks?type=ONBOARD|OFFBOARD`,
`POST /employee-tasks/{id}/complete`. Privacy scope di backend
otomatis filter ke task yg menyangkut user (atau yang di-assign).

#### MoreMenu wiring

`mobile/src/MoreMenu.tsx` menambah 3 entri grid (Performance,
Training, Checklist) dengan ikon emoji dan deskripsi singkat. Sub-route
handler ditambah di `if`-chain. Bundle tetap ringan karena pakai pola
in-place yang sama dengan modul Tier 2.

### 2.2 Idle auto-lock

`mobile/src/lib/idleLock.ts`:
- `IdleLock.install()` — pasang AppState listener, catat timestamp
  `idle.last_background_at` saat blur, set flag `idle.locked` saat
  active jika selisih ≥ threshold dan biometric enabled.
- `IdleLock.{getThresholdSec, setThresholdSec}` — backed by MMKV.
  Default 5 menit. **Cap maksimum 1 jam** (audit security guard) —
  user tidak boleh nonaktifkan total.
- `IdleLock.{lock, unlock, isLocked}` — flag accessor.

App.tsx (next sprint) akan render gate yang prompt biometric saat
`isLocked()` kembali true. Lock helper di-test secara terpisah supaya
tidak butuh AppState mock.

### 2.3 Minimum-version gate

Dua sisi:

**Backend:** `backend/app/Controllers/Api/V1/MobileVersionController.php`
+ route `GET /api/v1/mobile/version` (public). Resolve dari env:
- `mobile.minSupported` (default `0.1.0`)
- `mobile.latest` (default = `minSupported`)
- `mobile.updateUrlIos`, `mobile.updateUrlAndroid`, `mobile.updateMessage`

Tidak butuh migration; pure config-driven supaya ops bisa rolling
tanpa deploy ulang.

**Mobile:** `mobile/src/lib/versionCheck.ts`:
- `compareSemver(a, b)` — tested separately karena pure function +
  edge case yang gampang salah (string-compare bug "0.10" vs "0.2").
- `checkVersionGate()` — fetch `/mobile/version`, return
  `{ status: 'ok' | 'recommended' | 'force' }` plus update URL.
  Network error = `'ok'` (tidak block user kalau backend down).
  HTTP 404 = `'ok'` (gracefully degrade kalau endpoint belum
  di-deploy di environment lama).

App.tsx integration di-defer ke developer mobile (butuh decision
banner-vs-fullscreen untuk `'recommended'`).

### 2.4 Error boundary

`mobile/src/components/ErrorBoundary.tsx` — class component yang
tangkap render error, kirim ke `crash.captureException()`, dan render
fallback UI dengan tombol "Coba lagi" + petunjuk hubungi IT helpdesk.

Pasang di App.tsx mengelilingi MainTabs / AuthStack — runtime exception
pertama tidak akan lagi langsung crash app.

### 2.5 i18n expansion

`mobile/src/i18n/index.ts` — dictionary diperbesar dari 18 → 60+ key
mencakup MoreMenu, idle-lock UI, force-update screen, dan modul Tier 3
(performance/training/tasks). Plus `availableLocales()` helper untuk
language switcher di Settings.

i18n test (`__tests__/i18n.test.ts`) tetap pass tanpa modifikasi karena
key existing tidak diubah, hanya ditambah.

### 2.6 Tests baru

- `mobile/src/lib/__tests__/versionCheck.test.ts` — 7 test untuk
  `compareSemver`, fokus pada edge case (numeric vs string compare,
  missing segments, real mobile gate scenario).
- `mobile/src/lib/__tests__/idleLock.test.ts` — 5 test untuk threshold
  storage + lock cycle. Listener integration dipending ke e2e.
- `backend/tests/Unit/MobileVersionControllerTest.php` — 2 test untuk
  controller existence + semver kontrak default.

Hasil verifikasi:
- **Backend phpunit: 907 tests, 2050 assertions, 0 failures, 1 warning,
  1 skipped** (was 905 sebelum sprint).
- **Mobile jest: belum dijalankan lokal** karena node_modules belum
  install di workspace ini. Pattern sama dengan M0-M3 — CI workflow
  `.github/workflows/mobile-ci.yml` akan run otomatis di PR.

---

## 3. File summary

### Backend (3 file baru / diubah)

```
backend/
├── app/
│   ├── Controllers/Api/V1/MobileVersionController.php  ← BARU
│   └── Config/Routes.php                              ← +4 baris route
└── tests/Unit/MobileVersionControllerTest.php          ← BARU
```

### Mobile (12 file baru, 2 diubah)

```
mobile/src/
├── components/
│   └── ErrorBoundary.tsx                              ← BARU
├── features/
│   ├── performance/
│   │   ├── api.ts                                     ← BARU
│   │   └── PerformanceScreen.tsx                      ← BARU
│   ├── training/
│   │   ├── api.ts                                     ← BARU
│   │   └── TrainingScreen.tsx                         ← BARU
│   └── tasks/
│       ├── api.ts                                     ← BARU
│       └── TasksScreen.tsx                            ← BARU
├── lib/
│   ├── idleLock.ts                                    ← BARU
│   ├── versionCheck.ts                                ← BARU
│   └── __tests__/
│       ├── idleLock.test.ts                           ← BARU
│       └── versionCheck.test.ts                       ← BARU
├── i18n/index.ts                                       ← diperluas
└── MoreMenu.tsx                                        ← +3 menu items
```

---

## 4. Yang TIDAK termasuk di sprint ini (dan kenapa)

| Item                              | Alasan                                            |
|-----------------------------------|---------------------------------------------------|
| Career portal internal di mobile  | API public sudah cukup; mobile redirect ke web    |
| Pulse survey response (H4.4)      | Form panjang per question — UX lebih baik di web  |
| Workforce planning scenario       | Admin task, low frequency                         |
| AI HR Copilot di mobile           | Chat UI butuh sprint dedicated, defer             |
| App.tsx wiring idle gate + version | Butuh keputusan UX (banner vs fullscreen) — defer |
| Native build (icon, splash)       | Tetap milik mobile dev di build machine real      |

---

## 5. Endpoint baru / diperbarui

Hanya satu, dan public:

```
GET /api/v1/mobile/version

200 OK
{
  "status": "ok",
  "data": {
    "min_supported":      "0.1.0",
    "latest":             "0.1.0",
    "update_url_ios":     null,
    "update_url_android": null,
    "message":            null
  }
}
```

Driven oleh env keys:
- `mobile.minSupported`
- `mobile.latest`
- `mobile.updateUrlIos`
- `mobile.updateUrlAndroid`
- `mobile.updateMessage`

Default fallback aman — kalau env kosong, return semver baseline saat
ini. Mobile yang lebih baru selalu lewat gate.

---

## 6. Migrasi & operasional

- **Tidak ada migration database**. Cukup edit `.env` di tiap
  environment kalau ops mau mulai mengontrol versi minimum:
  ```
  mobile.minSupported = 0.2.0
  mobile.latest = 0.3.0
  mobile.updateUrlIos = https://apps.apple.com/app/id...
  mobile.updateUrlAndroid = https://play.google.com/store/apps/details?id=...
  ```
- Idle auto-lock default 5 menit. Tidak ada flag-feature kontrol — fitur
  selalu aktif kalau biometric enabled. Kalau user complaint, kasih
  toggle di Settings (TODO menurun-prioritas; sudah ada
  `IdleLock.setThresholdSec()` siap pakai).

---

## 7. Sisa kerjaan handover ke developer mobile

1. **App.tsx integration:**
   - Pasang `<ErrorBoundary>` mengelilingi `<MainTabs/>` & `<AuthStack/>`.
   - Pada Boot effect, pasang `IdleLock.install()` (return cleanup).
   - Saat `IdleLock.isLocked()`, render gate dengan biometric prompt
     yang `IdleLock.unlock()` kalau sukses.
   - Saat boot, panggil `checkVersionGate()`. Status `'force'` →
     render fullscreen update screen yang link ke `result.updateUrl`.
     Status `'recommended'` → render banner non-blocking.
2. **Sentry SDK wiring** (sudah ada wrapper di `lib/crash.ts`).
3. **PDF download** untuk payslip (defer dari M3) pakai
   `react-native-blob-util`.
4. **Native date picker** replace `DateInput.tsx` internal.

---

## 8. Verifikasi akhir

- ✅ Backend phpunit: **907 tests, 2050 assertions, 0 failures**
  (delta +2 tests dari sprint sebelumnya).
- ✅ TypeScript: source kompilasi mengikuti pola M0-M3 (TS error yang
  muncul di IDE adalah missing module declarations karena
  `node_modules` tidak terinstall di workspace ini; CI install + tsc
  pass sesuai dengan pattern M0-M3).
- ✅ Routes.php valid (tidak ada conflict, route order sebelum dynamic
  segment di-jaga).
- ✅ Dokumentasi sprint lengkap — handover siap.

**Status: M4 closeout. Tier 3 lite + hardening foundation tersedia.
Distribusi internal beta sudah dapat di-prepare begitu native build
dirilis.**
