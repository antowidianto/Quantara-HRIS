# Sprint M3 — Integration Layer + DevOps

> Penutup integration layer untuk handover ke developer mobile.
> Sprint ini melengkapi M2 dengan push notification orchestration,
> badge counter di tabs, biometric quickUnlock di Boot flow, GitHub
> Actions CI workflow, dan native config templates siap-merge.
> **Update:** 28 Mei 2026.

---

## 1. Yang ditambahkan di sprint ini

### 1.1 Push notification orchestration

`mobile/src/features/notifications/push.ts` — module dengan 3 export:

- `getFcmToken()` — stub yang baca dari MMKV. Production replace dengan
  `messaging().getToken()` dari `@react-native-firebase/messaging`.
- `usePushSync()` hook — auto-call `registerDeviceToken()` saat user
  login, dan `unregisterDeviceToken()` saat sign-out.
- `handleBackgroundPush()` — placeholder untuk Firebase
  `setBackgroundMessageHandler()`.

Hook `usePushSync()` sudah di-wire di `App.tsx` Boot component, jadi
flow auto-register device token aktif begitu Firebase native config
selesai.

### 1.2 Badge counter di tab bar

`MainTabs.tsx` sekarang menampilkan badge merah dengan jumlah:
- Tab **Inbox**: jumlah pending approval (poll tiap 60 detik)
- Tab **Lainnya**: jumlah notifikasi unread (poll tiap 60 detik)

Implementasi pakai `useQuery` react-query dengan `refetchInterval`,
cache 30 detik supaya tidak spam request. Badge max display 99+.
Accessible-label di-update dengan badge count.

### 1.3 Biometric integration ke Boot flow

`App.tsx` Boot effect baru: kalau user ada token di Keychain dan flag
`Biometric.isEnabled()` aktif, prompt sidik jari/Face ID sebelum
hydrate user. Cancel biometric → `signOut()` → kembali ke login screen.

Pattern aman: tidak menyimpan password tambahan; biometric hanya gate
ke refresh token yang sudah ter-protected `WHEN_UNLOCKED` di Keychain.

### 1.4 GitHub Actions CI workflow

`.github/workflows/mobile-ci.yml`:
- Trigger: push/PR yang menyentuh `mobile/**`
- Job `verify`:
  - Setup Node 20 + cache npm
  - `npm ci`
  - `npm run tsc` (TypeScript check)
  - `npm run lint` (continue-on-error sampai eslint config selesai)
  - `npm test` (jest)
- Job `android-build-check`:
  - Trigger hanya pada tag `mobile-*`
  - Setup Java 17 + Gradle dry-run (continue-on-error sampai
    `init-native.sh` selesai jalan)

Native build sungguhan (APK/IPA) di-defer ke runner berkapasitas besar
(self-hosted GitHub Actions / Bitrise / Codemagic).

### 1.5 Native config templates

Dua file di `mobile/native-config/` siap di-merge developer:

- **`android-manifest-additions.xml`** — semua `<uses-permission>` yang
  dibutuhkan (INTERNET, GPS fine+coarse, CAMERA, BIOMETRIC,
  POST_NOTIFICATIONS, VIBRATE, READ_EXTERNAL_STORAGE legacy +
  READ_MEDIA_IMAGES Android 13+). Plus contoh activity config dengan
  `exported="true"` untuk Android 12+ + FileProvider untuk camera URI.
  Catatan penting: `ACCESS_BACKGROUND_LOCATION` sengaja TIDAK dipakai
  supaya tidak kena scrutiny Play Store review.

- **`info-plist-additions.plist`** — usage descriptions ramah-user untuk
  Camera, Location, PhotoLibrary, FaceID. Plus `UIBackgroundModes`
  untuk push notification + ATS exception untuk localhost dev.

### 1.6 Outbox unit test

`mobile/src/api/__tests__/outbox.test.ts` — 4 test (enqueue, list
ordering, remove, idempotency uniqueness). Akan run otomatis di CI
setelah deps install.

---

## 2. Total file yang ditambahkan di seluruh serial sprint

```
backend/
├── app/
│   ├── Commands/PurgeIdempotencyKeys.php
│   ├── Controllers/Api/V1/{DeviceController, OpenApiController}.php
│   ├── Database/Migrations/...074_CreateDeviceTokensAndIdempotency.php
│   ├── Filters/IdempotencyFilter.php
│   ├── Libraries/{PushNotifier, FieldsHelper}.php
│   └── Models/{DeviceTokenModel, IdempotencyKeyModel}.php
└── tests/Unit/IdempotencyKeyModelTest.php

mobile/
├── App.tsx, index.js, app.json, package.json
├── tsconfig.json, babel.config.js, metro.config.js
├── jest.setup.js, .env.example, .gitignore
├── README.md
├── scripts/init-native.sh
├── native-config/{android-manifest-additions.xml, info-plist-additions.plist}
└── src/
    ├── config.ts, MainTabs.tsx, MoreMenu.tsx
    ├── theme/{index.ts, ThemeProvider.tsx}
    ├── store/{auth.ts, secureStorage.ts, mmkv.ts}
    ├── api/{client.ts, outbox.ts, __tests__/outbox.test.ts}
    └── features/
        ├── auth/         (api + biometric + forgot + Login + 2FA + Forgot + AuthStack)
        ├── attendance/   (api + geo + AttendanceScreen)
        ├── leave/        (api + List + Create + index)
        ├── approvals/    (api + ApprovalsScreen)
        ├── payslip/      (api + PayslipScreen)
        ├── profile/      (ProfileScreen)
        ├── overtime/     (api + OvertimeScreen)
        ├── reimbursement/(api + ReimbursementScreen)
        ├── business-trip/(api + BusinessTripScreen)
        ├── helpdesk/     (api + HelpdeskScreen)
        ├── notifications/(api + NotificationsScreen + push.ts)
        ├── settings/     (api + ChangePassword + 2FAManage + Settings agg)
        └── devices/      (register helper)

.github/workflows/mobile-ci.yml

docs/
├── SPRINT_MOBILE_M0_SCAFFOLD.md
├── SPRINT_MOBILE_M1_TIER2.md
├── SPRINT_MOBILE_M2_FINAL.md
└── SPRINT_MOBILE_M3_INTEGRATION.md  ← dokumen ini
```

**Total**: ~50 mobile source files + 4 sprint docs + 1 CI workflow + 9 backend files. README root juga di-update untuk include mobile di tech stack.

---

## 3. End-to-end checklist

### Backend
- [x] Migration #074 (device_tokens + idempotency_keys)
- [x] Models (DeviceTokenModel, IdempotencyKeyModel)
- [x] Controllers (DeviceController, OpenApiController)
- [x] Libraries (PushNotifier FCM HTTP v1, FieldsHelper slim response)
- [x] Filter (IdempotencyFilter 5-case state machine)
- [x] Cron command (PurgeIdempotencyKeys)
- [x] Services & Routes & Filters wiring
- [x] Unit tests (IdempotencyKeyModelTest)
- [x] All 905 tests passing (no regression)

### Mobile - Foundation
- [x] React Native 0.76 + TypeScript 5.6 scaffold
- [x] Path alias (`@/`, `@api/`, `@features/`, dst.)
- [x] Theme (light/dark via useColorScheme)
- [x] Storage (Keychain + MMKV)
- [x] Auth store (Zustand)
- [x] API client (axios + JWT + refresh-on-401 + idempotency-key + tenant)
- [x] Offline outbox (auto-replay saat online)
- [x] Jest setup dengan native module mocks

### Mobile - Tier 1 (MVP)
- [x] Auth (Login + 2FA + Forgot + Biometric helper)
- [x] Attendance (Clock-in/out + GPS + Selfie + Outbox)
- [x] Leave (saldo + ajukan + cancel)
- [x] Approvals inbox
- [x] Payslip (list + detail + open PDF)
- [x] Profile (view + logout)

### Mobile - Tier 2
- [x] Overtime
- [x] Reimbursement (foto struk + upload + OCR)
- [x] Business Trip
- [x] Helpdesk
- [x] Notifications (in-app + push registration)

### Mobile - Settings dalam
- [x] Change password
- [x] 2FA management (enable/disable + biometric toggle)

### Mobile - Integration & DevOps
- [x] Push orchestration hook (usePushSync)
- [x] Badge counter di tabs
- [x] Biometric quickUnlock di Boot flow
- [x] GitHub Actions CI workflow
- [x] Native config templates (Android + iOS)
- [x] Native init helper script
- [x] OpenAPI spec untuk codegen

### Documentation
- [x] mobile/README.md
- [x] 4 sprint docs (M0, M1, M2, M3)
- [x] Root README.md update (mention mobile di tech stack)

---

## 4. Yang masih harus dikerjakan oleh developer mobile

Cuma 3 hal core yang butuh build machine real:

1. **Run `bash mobile/scripts/init-native.sh`** — generate android/+ios/
2. **Merge `native-config/*` ke AndroidManifest.xml + Info.plist**
3. **Setup Firebase**:
   - Buat project Firebase Console
   - Download `google-services.json` → `android/app/`
   - Download `GoogleService-Info.plist` → `ios/HrisMobile/`
   - `npm install @react-native-firebase/app @react-native-firebase/messaging`
   - Replace stub `getFcmToken()` di `src/features/notifications/push.ts`
     dengan `messaging().getToken()`

Plus polish:
- App icon + splash screen branding
- PDF download via `react-native-blob-util`
- Date picker UI replace TextInput
- Deep link routing dari push notif
- Crash reporting wiring (Sentry / Crashlytics)
- App store assets (screenshots, listing copy)

---

## 5. Estimasi sisa effort hingga production

| Aktivitas                                | Estimate |
|------------------------------------------|----------|
| Native init + permission config + Firebase setup | 1 minggu |
| Polish (PDF, date picker, icons, deep link) | 1 minggu |
| QA pass Tier 1 modules di device          | 1 minggu |
| QA pass Tier 2 modules                    | 1 minggu |
| UAT + bug fix                             | 2 minggu |
| App store / MDM submission                | 1 minggu |
| **Total**                                 | **~7 minggu** |

Catatan: estimasi naik 1 minggu dari M2 final untuk akomodasi proper
icon/splash design + integration testing 2-platform menyeluruh.

---

## 6. Verifikasi akhir

- ✅ Backend phpunit: **905 tests, 2046 assertions, 0 failures**
  (no regression sepanjang 4 sprint mobile)
- ✅ Mobile struktur lengkap (~50 source files + native-config templates)
- ✅ Backend semua endpoint mobile sudah ada di Routes.php
- ✅ Idempotency-Key auto applied di clock-in/out, leave, overtime,
  reimbursement, business-trips, approval decisions
- ✅ CI workflow ready
- ✅ Dokumentasi handover lengkap

**Status: closeout sprint mobile. Siap di-handover ke mobile developer untuk fase implementasi native + production polish.**