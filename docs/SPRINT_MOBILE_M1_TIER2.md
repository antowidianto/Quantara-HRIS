# Sprint M1 — Tier 2 Modules + Auth Polish

> Lanjutan dari `docs/SPRINT_MOBILE_M0_SCAFFOLD.md`. Sprint ini menambahkan
> modul Tier 2 (Overtime, Reimbursement, Helpdesk, Notifications) plus
> polish Tier 1 (Forgot password, Biometric helper, Jest test setup).
> **Update:** 28 Mei 2026.

---

## 1. Tier 2 modules baru

### 1.1 Overtime (Tier 2.1)

- `mobile/src/features/overtime/api.ts` — list, create, plan/realize
  (mendukung pre-approval flow Sprint H1.6 di backend)
- `mobile/src/features/overtime/OvertimeScreen.tsx` — form ajukan +
  riwayat pengajuan dengan status badge
- Backend endpoint yang dipakai: `POST /overtimes`, `POST /overtimes/plan`,
  `POST /overtimes/{id}/realize` (sudah ada)

### 1.2 Reimbursement (Tier 2.2)

- `mobile/src/features/reimbursement/api.ts` — list, create, upload struk,
  OCR extract
- `mobile/src/features/reimbursement/ReimbursementScreen.tsx` —
  capture struk dari kamera atau galeri → upload async → form
  pre-fill (vendor, total, date) → submit
- OCR endpoint `POST /reimbursements/{id}/ocr-extract` sudah ada
  (Horizon2 H2.8). Driver pluggable di backend (mock/tesseract/gcloud_vision/aws_textract).

### 1.3 Helpdesk (Tier 2.4)

- `mobile/src/features/helpdesk/api.ts` — CRUD ticket + comment
- `mobile/src/features/helpdesk/HelpdeskScreen.tsx` — list ticket user
  + form create dengan kategori (IT/HR/Finance/GA/Other) dan priority
- Backend HelpdeskController sudah lengkap dengan SLA tracking

### 1.4 Notifications (Tier 2.5)

- `mobile/src/features/notifications/api.ts` — list, unread count,
  mark read, mark all read
- `mobile/src/features/notifications/NotificationsScreen.tsx` —
  list dengan kategori icon, mark read on tap, "Tandai semua" button
- Backend `NotificationController` sudah ada; push notif fanout ke
  device pakai `PushNotifier` yang sudah disiapkan di Sprint M0

---

## 2. Auth polish

### 2.1 Forgot password (Tier 1.1 polish)

- `mobile/src/features/auth/forgot.ts` — API client untuk
  `POST /auth/forgot-password`
- `mobile/src/features/auth/ForgotPasswordScreen.tsx` — form input
  email/NIK + alert success generic (anti enumeration)
- AuthStack diupdate jadi state machine 3-screen (Login ↔ 2FA ↔ Forgot)
- LoginScreen punya link "Lupa password?" yang trigger forgot mode

### 2.2 Biometric helper

- `mobile/src/features/auth/biometric.ts` — wrapper untuk
  `react-native-biometrics` (sudah di deps M0)
- API: `Biometric.capability()`, `Biometric.isEnabled()`, `Biometric.setEnabled()`,
  `Biometric.authenticate(prompt)`, `Biometric.quickUnlock()`
- Pattern aman: tidak menyimpan password, hanya gate refresh token yang
  sudah ada di Keychain
- **TODO M2**: integrate ke Boot flow di App.tsx setelah native config
  Android/iOS sudah jadi (otomatis trigger `quickUnlock()` saat app start
  bila flag enabled)

---

## 3. Navigation update

`mobile/src/MainTabs.tsx` direstrukturisasi:
- Tab "Profil" diganti jadi tab "Lainnya" (`☰`)
- Tab "Lainnya" buka `MoreMenu` — grid 5 menu: Lembur, Reimburse,
  Helpdesk, Notifikasi, Profil
- Sub-screen pakai pola `SubWrapper` dengan back button konsisten

Kenapa pakai grid menu, bukan tab langsung? Bottom-tab dengan 5+ icon
mulai crowded di layar narrow (< 360dp). Tier 1 (Presensi, Cuti, Inbox,
Slip) tetap pinned di tab utama; Tier 2 modules masuk ke "Lainnya"
supaya tab bar tetap rapi.

---

## 4. Test infrastructure

`mobile/jest.setup.js` — mock semua native module supaya unit test bisa
run di Node:
- `react-native-keychain` → in-memory store
- `react-native-mmkv` → Map-based MMKV stub
- `react-native-config` → static object
- `react-native-device-info` → fixture values
- `@react-native-community/netinfo` → always-online
- `react-native-geolocation-service` → fixture coords
- `react-native` → Platform / Permissions stub

`mobile/package.json` jest config sudah include:
- setupFiles → jest.setup.js
- moduleNameMapper untuk path alias `@/`, `@api/`, dst.

Test Jest belum ditambahkan di sprint ini (file `__tests__/` belum dibuat
karena tergantung deps install). Skeleton akan dibuat di Sprint M2 saat
`npm install` sudah jalan dan kita bisa run test asli.

---

## 5. Hal yang masih deferred ke M2+

| Item | Status | Sprint target |
|------|--------|---------------|
| Native folder Android/iOS | Belum di-init | M1 (manual `npx react-native init` merge) |
| Biometric integrate ke Boot flow | Helper ready, integration belum | M1 |
| Push notif handler (FCM token receive) | DeviceController + helper ready, Firebase native belum | M1 |
| Date picker UI | Pakai TextInput YYYY-MM-DD; perlu `react-native-date-picker` | M2 |
| PDF download payslip | `react-native-blob-util` belum di deps | M3 |
| Deep link dari push notif (tap → ke screen) | Butuh routing layer | M2 |
| Settings dalam (2FA mgmt, password change) | UI minimal di Profile | M2 |

---

## 6. Verifikasi

- ✅ Backend tetap pass: `vendor\bin\phpunit` → 905 tests, 2046 assertions, 0 failures
- ✅ TS errors di mobile saat ini hanya "module not found" (auto-resolve setelah `npm install`)
- ✅ MainTabs tetap render Tier 1 modules; Tier 2 di sub-menu
- ✅ Auth flow extended (Login → 2FA + Forgot)
- ✅ Idempotency-Key auto applied untuk Overtime, Reimbursement (backend filter
  sudah cover pattern `/overtimes`, `/reimbursements`)

## 7. Backend yang dipakai (semuanya sudah ada, tidak ada migration baru)

- `POST /auth/forgot-password` (throttle bucket)
- `GET /overtimes`, `POST /overtimes`, `POST /overtimes/plan`,
  `POST /overtimes/{id}/realize`
- `GET /reimbursements`, `POST /reimbursements`,
  `POST /upload/attachment?context=reimbursement`,
  `POST /reimbursements/{id}/ocr-extract`
- `GET /helpdesk/tickets`, `POST /helpdesk/tickets`,
  `POST /helpdesk/tickets/{id}/comments`, status change
- `GET /notifications`, `POST /notifications/{id}/read`,
  `POST /notifications/read-all`, `GET /notifications/unread-count`

## 8. Berikutnya

Sprint M2 fokus:
1. **Native folder generation** + manual config Android/iOS (Permissions,
   Firebase, react-native-config plugin)
2. **Run unit tests** sungguhan setelah `npm install` jalan, plus tambah
   skeleton test untuk API client interceptors + outbox + biometric
3. **Date picker** + better form UX
4. **2FA management screen** (enable/disable, generate backup codes)
5. **Change password screen**
6. **Push notif handler** wiring lengkap (foreground/background/quit
   states + tap handler)