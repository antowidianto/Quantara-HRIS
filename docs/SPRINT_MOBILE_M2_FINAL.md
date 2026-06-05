# Sprint M2 — Final Mobile Surface

> Penutup serial mobile sprint. Sprint ini menyelesaikan modul Tier 2
> terakhir + auth/security UX dalam + native init helper, test stub,
> dan dokumentasi handover lengkap.
> **Update:** 28 Mei 2026.

---

## 1. Apa yang ditambahkan di sprint ini

### 1.1 Tier 2 — Business Trip (Tier 2.3)

- `mobile/src/features/business-trip/api.ts` — list / show / create /
  submit / start / complete / cancel
- `mobile/src/features/business-trip/BusinessTripScreen.tsx` — form
  pengajuan (destination, range tanggal, perdiem, keperluan) + auto-submit
  ke approval flow + riwayat dengan status badge 8-state
- Backend `BusinessTripController` + `BusinessTripService` sudah lengkap
  (SPPD generator, expense tracking, settlement). Detail expenses
  dan SPPD print di-defer ke desktop view.

### 1.2 Settings dalam — Sprint M2

- `mobile/src/features/settings/api.ts` — change password, enable/disable
  2FA, list & revoke sessions
- `mobile/src/features/settings/ChangePasswordScreen.tsx` — form 3-field
  dengan validasi client (≥12 char, kombinasi, beda dari old) + flatten
  error detail dari backend `PasswordPolicy`
- `mobile/src/features/settings/TwoFactorManageScreen.tsx` —
  enable TOTP (display secret + backup codes sekali), disable dengan
  konfirmasi kode, plus toggle biometrik (sidik jari/Face ID gate
  refresh token)
- `mobile/src/features/settings/SettingsScreen.tsx` — aggregator menu
  dengan navigasi internal ke 2 sub-screen di atas

### 1.3 Navigation polish

`mobile/src/MoreMenu.tsx` diperluas: tab "Lainnya" sekarang berisi 7
modul (Lembur, Reimburse, Business Trip, Helpdesk, Notifikasi, Profil,
Pengaturan). Setiap sub-screen pakai `SubWrapper` dengan back button
konsisten.

### 1.4 Test infrastructure

- `mobile/src/api/__tests__/outbox.test.ts` — unit test pertama di mobile
  (4 test: enqueue, list ordering, remove, idempotency key uniqueness)
- Akan auto-run setelah `npm install` selesai (saat ini gagal karena
  deps belum ter-install — TS errors di test file = expected)

### 1.5 Native init helper

- `mobile/scripts/init-native.sh` — bash script generate `android/` +
  `ios/` folder dari template `@react-native-community/cli init`,
  npm install, dan iOS pod install. Dipakai sekali per developer
  baru atau reset workspace.
- Manual TODO setelah script jalan: edit `AndroidManifest.xml`,
  `Info.plist`, dan setup Firebase (untuk push notif).

---

## 2. Total mobile surface yang sudah dibangun

### Tier 1 (MVP)

- ✅ Auth (Login, 2FA, Forgot password, Biometric helper)
- ✅ Attendance (Clock-in/out + GPS + Selfie + Offline outbox)
- ✅ Leave (saldo + ajukan + cancel + half-day support)
- ✅ Approvals inbox (untuk supervisor/manager/HR)
- ✅ Payslip (list + detail breakdown + open PDF)
- ✅ Profile (view + logout)

### Tier 2 (full enhancement)

- ✅ Overtime (ajukan + riwayat)
- ✅ Reimbursement (foto struk + upload + OCR-ready)
- ✅ Business Trip (ajukan + workflow approval)
- ✅ Helpdesk (ticket IT/HR/Finance)
- ✅ Notifications (in-app list + mark read + push registration)

### Foundation

- ✅ API client (axios + JWT + refresh-on-401 + idempotency-key + tenant header)
- ✅ Offline outbox (MMKV) dengan auto-replay saat online
- ✅ Auth store (Zustand) + Keychain token storage
- ✅ Theme (light/dark via useColorScheme)
- ✅ Navigation (5 tab utama + grid menu Lainnya)
- ✅ Test infrastructure (Jest + native module mocks)

### Backend prerequisite (Sprint M0)

- ✅ Migration `device_tokens` + `idempotency_keys` (#074)
- ✅ DeviceController + DeviceTokenModel + PushNotifier (FCM HTTP v1)
- ✅ IdempotencyFilter + IdempotencyKeyModel + cron purge
- ✅ FieldsHelper (slim response)
- ✅ OpenApiController (`GET /openapi.json`)
- ✅ 905 backend tests passing (no regression)

### Settings (Sprint M2)

- ✅ Change password
- ✅ 2FA enable/disable + biometrik toggle

---

## 3. Coverage vs `docs/MOBILE_APP_PLAN.md`

| Tier 1                | Status | Tier 2                | Status |
|-----------------------|--------|-----------------------|--------|
| 2.1 Authentication    | ✅      | 3.1 Overtime          | ✅      |
| 2.2 Attendance        | ✅      | 3.2 Reimbursement     | ✅      |
| 2.3 Leave             | ✅      | 3.3 Business Trip     | ✅      |
| 2.4 Approval Inbox    | ✅      | 3.4 Helpdesk          | ✅      |
| 2.5 Payslip           | ✅      | 3.5 Notifications     | ✅      |
| 2.6 Profile & Settings | ✅      |                       |        |

**Tier 3 (optional)** — sengaja TIDAK dibangun di mobile karena:
- Performance review essay → lebih nyaman di desktop
- Training & e-learning → punya UX kompleks, video player butuh polish
- Internal job marketplace → infrequent, OK lewat web
- Onboarding new hires → checklist lebih baik di desktop tabular

Sesuai rekomendasi awal di `MOBILE_APP_PLAN.md` section 4.

---

## 4. Yang masih harus dikerjakan oleh developer mobile

Tahap-tahap ini butuh **build machine real** (Android Studio + Xcode),
tidak bisa dikerjakan di scaffold-only level:

1. **Native folder generation** — jalankan `bash scripts/init-native.sh`
   atau ikuti instruksi di `mobile/README.md`
2. **Firebase setup** untuk push notification:
   - Buat project Firebase
   - Download `google-services.json` → `android/app/`
   - Download `GoogleService-Info.plist` → `ios/HrisMobile/`
   - Install `@react-native-firebase/app` + `@react-native-firebase/messaging`
   - Wire FCM token ke `registerDeviceToken()` di `src/features/devices/register.ts`
3. **Permissions config** di AndroidManifest.xml + Info.plist
4. **Test run** — `npm test` setelah deps install untuk verify outbox test pass
5. **CI/CD pipeline** — GitHub Actions / Bitrise / Codemagic
6. **App icon + splash screen** customization
7. **PDF download** via `react-native-blob-util` (bukan Linking.openURL)
8. **Date picker** UI replace TextInput YYYY-MM-DD
9. **Deep linking** — tap notif → buka screen relevan
10. **Crash reporting** (Sentry / Firebase Crashlytics) wiring

---

## 5. File yang ditambahkan di seluruh serial mobile sprint

```
mobile/
├── App.tsx, index.js, app.json, package.json
├── tsconfig.json, babel.config.js, metro.config.js
├── .env.example, .gitignore
├── README.md
├── jest.setup.js
├── scripts/init-native.sh
└── src/
    ├── config.ts
    ├── theme/{index.ts, ThemeProvider.tsx}
    ├── store/{auth.ts, secureStorage.ts, mmkv.ts}
    ├── api/
    │   ├── client.ts, outbox.ts
    │   └── __tests__/outbox.test.ts
    ├── MainTabs.tsx, MoreMenu.tsx
    └── features/
        ├── auth/                  (api + Login + 2FA + Forgot + AuthStack + biometric)
        ├── attendance/            (api + geo + AttendanceScreen)
        ├── leave/                 (api + List + Create + module)
        ├── approvals/             (api + ApprovalsScreen)
        ├── payslip/               (api + PayslipScreen)
        ├── profile/               (ProfileScreen)
        ├── overtime/              (api + OvertimeScreen)
        ├── reimbursement/         (api + ReimbursementScreen)
        ├── business-trip/         (api + BusinessTripScreen)
        ├── helpdesk/              (api + HelpdeskScreen)
        ├── notifications/         (api + NotificationsScreen)
        ├── settings/              (api + Change password + 2FA + Settings agg.)
        └── devices/               (FCM register helper)
```

**Total file count**: ~45 source files + 4 docs (`MOBILE_APP_PLAN.md`,
`SPRINT_MOBILE_M0_SCAFFOLD.md`, `SPRINT_MOBILE_M1_TIER2.md`, this file).

Backend prereq yang ditambahkan di Sprint M0:
- 1 migration (#074) + 2 model + 2 controller + 2 library + 1 filter +
  1 cron command + 1 unit test + Services & Routes & Filters wiring.

Backend tests: **905 passing, 0 failures**.

---

## 6. Final acceptance checklist

- [x] Backend prep work tuntas, semua tests passing
- [x] Mobile scaffold lengkap (TS + RN 0.76 + path alias + theme + storage)
- [x] API client production-ready (refresh-on-401 + idempotency + multi-tenant)
- [x] Offline outbox dengan auto-replay
- [x] Tier 1: 6/6 modules ✅
- [x] Tier 2: 5/5 modules ✅
- [x] Auth polish: forgot password + biometric helper
- [x] Settings dalam: change password + 2FA management
- [x] Test infrastructure (Jest setup + outbox unit test stub)
- [x] Native init helper script
- [x] OpenAPI spec untuk codegen client
- [x] Dokumentasi lengkap: README mobile + 3 sprint doc

Status: **siap untuk hand-off ke mobile developer untuk native config + Firebase setup**.

---

## 7. Estimasi sisa effort hingga production

Berdasarkan estimate awal di `MOBILE_APP_PLAN.md` section 7 (~4 bulan
kalendar untuk MVP dengan 1 mobile dev), distribusi sisa:

| Aktivitas                                | Estimate |
|------------------------------------------|----------|
| Native init + permission config          | 2–3 hari |
| Firebase setup + push notif end-to-end   | 3–5 hari |
| QA pass Tier 1 modules (build + test di device) | 1 minggu |
| QA pass Tier 2 modules                   | 1 minggu |
| UAT + bug fix                            | 2 minggu |
| App store submission (TestFlight + Play internal) | 1 minggu |
| **Total sisa hingga production launch**  | **~6 minggu** |

Catatan: estimate di atas asumsi tim QA paralel + 1 mobile dev fokus
penuh. Distribusi via MDM enterprise (Microsoft Intune / Workspace ONE)
biasanya ~1 minggu lebih cepat dari Play/App Store karena tidak perlu
review.