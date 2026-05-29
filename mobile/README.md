# HRIS Mobile App

React Native + TypeScript app untuk Tier 1 MVP HRIS sesuai
`docs/MOBILE_APP_PLAN.md` di repo root.

## Tier 1 Modules (MVP)

| Modul         | Path                                  | Status |
|---------------|---------------------------------------|--------|
| Auth          | `src/features/auth/`                  | ✅ scaffold |
| Attendance    | `src/features/attendance/`            | ✅ scaffold |
| Leave         | `src/features/leave/`                 | ✅ scaffold |
| Approvals     | `src/features/approvals/`             | ✅ scaffold |
| Payslip       | `src/features/payslip/`               | ✅ scaffold |
| Profile       | `src/features/profile/`               | ✅ scaffold |
| Device push   | `src/features/devices/register.ts`    | ✅ helper (FCM bind di-deferred) |

## Architectural choices

- **State**: Zustand (auth) + react-query (server state). Tidak pakai
  Redux supaya boilerplate ringan.
- **Storage**:
  - Keychain (`react-native-keychain`) untuk JWT access + refresh token
  - MMKV (`react-native-mmkv`) untuk preferensi non-rahasia + outbox
- **Networking**: axios instance dengan interceptor untuk
  - JWT bearer injection
  - Refresh-on-401 single-flight
  - `Idempotency-Key` UUID v4 auto-injection untuk POST critical
  - Multi-tenant header `X-Company-Id` (super admin)
- **Offline**: outbox pattern di MMKV. Clock-in/out + leave create
  saat offline disimpan lokal, replay otomatis saat NetInfo bilang
  online atau saat AppState jadi active.
- **Navigation**: bare React state. Tidak pakai @react-navigation untuk
  Tier 1 supaya bundle ringan; flow sederhana 5 tab + nested stack
  Login↔2FA cukup di-state-machine.
- **Theming**: built-in light/dark mengikuti `useColorScheme()`.

## Setup awal (dev workflow)

```bash
cd mobile
cp .env.example .env       # edit API_BASE_URL, dll.

# Install dependency JS
npm install

# Android
npm run android

# iOS (perlu macOS + CocoaPods)
cd ios && pod install && cd ..
npm run ios
```

## Native config yang harus dilakukan manual

Karena mobile app ini scaffold dari source code (bukan via
`npx react-native init`), beberapa file native perlu di-generate
sekali saat developer pertama melakukan setup. Pola standar RN 0.76:

1. **Android** — buat `android/` folder (gradle, MainActivity, dll.)
   dengan `npx @react-native-community/cli init HrisMobile --template react-native-template-typescript --skip-install` lalu pindahkan file `android/`
   dan `ios/` ke folder ini, atau ikuti
   <https://reactnative.dev/docs/0.76/integration-with-existing-apps>
   untuk merge ke project existing.

2. **iOS** — sama, butuh `ios/HrisMobile.xcworkspace` + Podfile.

3. **Permissions** — tambahkan ke `AndroidManifest.xml`:
   ```
   <uses-permission android:name="android.permission.INTERNET" />
   <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
   <uses-permission android:name="android.permission.CAMERA" />
   <uses-permission android:name="android.permission.USE_BIOMETRIC" />
   ```
   Dan ke `Info.plist` (iOS):
   - `NSCameraUsageDescription`
   - `NSLocationWhenInUseUsageDescription`
   - `NSFaceIDUsageDescription`

4. **react-native-config** — Android: pasang plugin di
   `android/app/build.gradle`; iOS: include via Pods.

5. **Firebase / FCM** — kalau mau push notif:
   - Buat project Firebase
   - Download `google-services.json` → `android/app/`
   - Download `GoogleService-Info.plist` → `ios/HrisMobile/`
   - Install `@react-native-firebase/app` + `@react-native-firebase/messaging`
   - Panggil `registerDeviceToken(fcmToken)` dari `src/features/devices/register.ts`
     setelah login berhasil.

## API contract

Backend menyediakan `GET /api/v1/openapi.json` (lihat
`backend/app/Controllers/Api/V1/OpenApiController.php`). Generate
TypeScript client (opsional, tidak dipakai langsung di MVP):

```bash
npm run codegen:api
```

## Testing

Unit test pakai Jest:

```bash
npm test
```

Strategi test:
- API client: mock axios, verifikasi header injection (Authorization,
  Idempotency-Key, X-Company-Id)
- Outbox: in-memory MMKV mock, verifikasi enqueue/sweep behavior
- Model serializer di tiap feature `api.ts`

## Build production

Lihat `docs/MOBILE_APP_PLAN.md` section 9 — distribusi via Play Store /
App Store atau enterprise MDM (Microsoft Intune).

## Backend prerequisite

Sudah disiapkan di backend:
- ✅ Migration `device_tokens` + `idempotency_keys` (#074)
- ✅ `POST /devices/register` + `GET /devices` + `DELETE /devices/{id}`
- ✅ `IdempotencyFilter` di pattern `/attendance/clock-*`, `/leaves`, dll.
- ✅ `PushNotifier` (FCM HTTP v1 adapter)
- ✅ `OpenAPI 3.0` spec di `/openapi.json`
- ✅ `?fields=` slim response helper (`FieldsHelper`)

## Roadmap selanjutnya

| Sprint | Deliverable |
|--------|-------------|
| M0 (now) | ✅ Scaffold + Tier 1 screens |
| M1     | Native config (Android+iOS), CI/CD, biometric login |
| M2     | Settings dalam (2FA, password change, notif prefs), forgot password |
| M3     | PDF download via react-native-blob-util |
| M4     | Tier 2: Overtime, Reimbursement (foto+OCR), Business trip, Helpdesk |
| M5     | Beta release + bug fix + UAT |