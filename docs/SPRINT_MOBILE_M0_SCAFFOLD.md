# Sprint M0 — Mobile App Scaffold + Backend Prep

> Eksekusi recommend-ed module dari `docs/MOBILE_APP_PLAN.md`. Backend
> prep work + React Native + TypeScript scaffold untuk Tier 1 MVP.
> **Update:** 28 Mei 2026.

---

## Ringkasan

Branch ini menambahkan:

1. **Backend prep work (~2 sprint setara)** — fondasi yang dibutuhkan
   sebelum mobile app bisa interact aman dengan API:
   - DeviceToken registry untuk push notif fanout (FCM/APNs)
   - Idempotency-Key infrastructure untuk anti-duplikat POST critical
   - PushNotifier (FCM HTTP v1 adapter)
   - FieldsHelper untuk slim response
   - OpenAPI 3.0 spec generator
   - Filter wiring + cron purge

2. **React Native + TypeScript scaffold** — Tier 1 MVP modules:
   - Auth (login + 2FA via challenge token)
   - Attendance (clock-in/out + GPS + selfie + offline outbox)
   - Leave (saldo + ajukan + riwayat)
   - Approvals inbox (untuk supervisor/manager/HR)
   - Payslip (list + detail + buka PDF)
   - Profile + settings ringan

---

## 1. Backend Changes

### 1.1 Migration `#074 CreateDeviceTokensAndIdempotency`

Dua tabel baru:

| Tabel              | Tujuan |
|--------------------|--------|
| `device_tokens`    | Registry FCM/APNs token per user untuk push fanout. Unique by token, indexed by user_id+revoked_at untuk active fanout. |
| `idempotency_keys` | Cache response POST critical (clock-in, leaves, dst.) supaya retry mobile-side tidak menghasilkan duplikat. TTL 24 jam, di-purge daily lewat cron. |

### 1.2 Model & Controller baru

| File | Peran |
|------|-------|
| `backend/app/Models/DeviceTokenModel.php` | upsert/list/revoke token + auto-revoke on FCM 410 |
| `backend/app/Models/IdempotencyKeyModel.php` | hashKey/hashBody/reserve/complete + STATE_* constants |
| `backend/app/Controllers/Api/V1/DeviceController.php` | `POST /devices/register`, `GET /devices`, `DELETE /devices/{id}`, `POST /devices/unregister` |
| `backend/app/Controllers/Api/V1/OpenApiController.php` | `GET /openapi.json` — hand-curated minimal spec untuk mobile-facing endpoints |

### 1.3 Library

| File | Peran |
|------|-------|
| `backend/app/Libraries/PushNotifier.php` | FCM HTTP v1 adapter dengan service-account JWT bearer flow. Best-effort fanout dengan auto-revoke pada 410/UNREGISTERED. Dev mode (no creds) → log only, tidak block. |
| `backend/app/Libraries/FieldsHelper.php` | Parse `?fields=` query param, intersect ke whitelist, project rows. Mobile pakai supaya list employee (5K row) tidak kirim 50 field per row. |

### 1.4 Filter

`backend/app/Filters/IdempotencyFilter.php` — anti-duplikat POST.
Pattern check 5 case:
1. Header tidak ada → bypass
2. Key baru → reserve IN_FLIGHT, lanjut controller, simpan response
3. Key sama + body sama + COMPLETED → replay cached response (header `Idempotency-Replay: true`)
4. Key sama + body sama + IN_FLIGHT → 409 (jangan flood)
5. Key sama + body beda → 422 mismatch

Dipasang di pattern: `attendance/clock-in`, `attendance/clock-out`, `leaves`, `overtimes`, `reimbursements`, `business-trips`, `approval-requests/*/approve|reject`, dan `bulk-approve|reject`.

### 1.5 Routes baru

```
GET  /api/v1/openapi.json              (public)
POST /api/v1/devices/register          (jwt)
POST /api/v1/devices/unregister        (jwt)
GET  /api/v1/devices                   (jwt)
DELETE /api/v1/devices/{id}            (jwt)
```

### 1.6 Services registration

`Services::pushNotifier()` ditambahkan di `Config/Services.php`. Env keys:
- `fcm.projectId` — Firebase project ID
- `fcm.serviceAccountPath` — Absolute path ke service account JSON

Tanpa env ini, PushNotifier mode "log only" — aman untuk dev.

### 1.7 Cron command

`php spark idempotency:purge` — hapus idempotency key expired (TTL 24h).
Schedule daily 03:00.

### 1.8 Tests

`backend/tests/Unit/IdempotencyKeyModelTest.php` — 6 test, semua pass.

Full suite: **905 tests, 2046 assertions, 0 failures** setelah perubahan.

---

## 2. Mobile App (`mobile/` folder)

### 2.1 Stack

- React Native 0.76.3 + TypeScript 5.6
- State: Zustand (auth) + react-query (server state)
- Storage: react-native-keychain (token) + react-native-mmkv (prefs/outbox)
- Network: axios + uuid (idempotency key)
- Native: react-native-geolocation-service, react-native-image-picker, react-native-biometrics, react-native-config, react-native-device-info

### 2.2 Folder structure

```
mobile/
├── App.tsx                          # Root: providers + boot flow
├── index.js                         # RN entry
├── app.json
├── package.json                     # All Tier 1 deps
├── tsconfig.json
├── babel.config.js
├── metro.config.js
├── .env.example
├── README.md
└── src/
    ├── config.ts                    # env wrapper
    ├── theme/                       # design tokens + provider
    ├── store/
    │   ├── auth.ts                  # Zustand store
    │   ├── secureStorage.ts         # Keychain wrapper
    │   └── mmkv.ts                  # MMKV instances
    ├── api/
    │   ├── client.ts                # axios + interceptor
    │   └── outbox.ts                # offline queue
    ├── MainTabs.tsx                 # bottom tab nav
    └── features/
        ├── auth/         (Login + 2FA + AuthStack)
        ├── attendance/   (clock-in/out + history + geo + outbox-aware api)
        ├── leave/        (list + balance + create + cancel)
        ├── approvals/    (inbox + approve/reject)
        ├── payslip/      (list + detail + PDF)
        ├── profile/      (view + logout)
        └── devices/      (FCM token registration helper)
```

### 2.3 Patterns yang konsisten lintas feature

- Setiap feature punya `api.ts` (semua HTTP call ke backend).
- Setiap screen import `useTheme()` untuk dark/light support otomatis.
- Mutations pakai react-query supaya cache invalidation rapi.
- Form validation in-screen (MVP); pindah ke `react-hook-form` saat butuh form besar.

### 2.4 Offline-first attendance

`AttendanceScreen` flow:

1. Tombol clock-in/out → request GPS permission → `getCurrentPosition`
2. Buka kamera selfie front camera
3. Cek NetInfo → kalau online: POST multipart langsung; kalau offline: enqueue di outbox
4. Outbox replay otomatis saat NetInfo bilang online atau AppState jadi active
5. Backend `IdempotencyFilter` cegah duplikat saat retry

### 2.5 Approval inbox

`ApprovalsScreen` tampilkan card per pending request dengan tombol Setujui / Tolak. Confirmation dialog untuk hindari mis-tap. Hanya muncul di tab kalau `roleId !== 0` (basic gate; backend tetap enforce permission).

### 2.6 Hal yang sengaja deferred

| Item | Alasan defer | Sprint target |
|------|--------------|---------------|
| Native config (`android/`, `ios/` folders) | Butuh `npx react-native init` di-merge manual; CI biasanya generate di-machine pertama | M1 |
| Biometric login (Face ID / fingerprint) | Library sudah ada di deps, integrate saat M1 setelah native config siap | M1 |
| Push notif handler (FCM token receive) | Butuh `@react-native-firebase/messaging` + `google-services.json` | M1 |
| Date picker UI | MVP pakai TextInput YYYY-MM-DD; UX better dengan `react-native-date-picker` | M2 |
| PDF download | RN tidak punya native download manager built-in | M3 |
| Forgot password / 2FA management | Settings layer dalam | M2 |

---

## 3. Verification

- ✅ `cd backend && vendor\bin\phpunit` → 905 tests passing
- ✅ Filter & Routes config validated (no syntax error)
- ✅ Mobile TypeScript files siap (TS errors saat ini hanya "module not found"
  yang akan auto-resolve setelah `npm install` di `mobile/`)
- ✅ Migration #074 idempotent (up + down handler)

## 4. Cara test end-to-end

1. **Backend**:
   ```
   cd backend
   php spark migrate
   php spark serve
   ```

2. **Mobile** (setelah generate native folders):
   ```
   cd mobile
   cp .env.example .env       # edit API_BASE_URL ke backend dev
   npm install
   npm run android            # atau npm run ios di macOS
   ```

3. **Smoke test**:
   - Login lewat email/NIK
   - Lihat tab Presensi → clock-in (nanti minta GPS + camera permission)
   - Test offline: matikan WiFi/Data → coba clock-in → harus muncul "tersimpan offline"
   - Test idempotency: `tap` clock-in beberapa kali cepat → backend hanya proses sekali

## 5. Next sprint

Lihat `mobile/README.md` section "Roadmap selanjutnya" untuk Sprint M1–M5.

Total estimate Tier 1 MVP siap dipakai end-to-end: **3–4 bulan kalendar** dengan 1 mobile dev (sesuai estimate awal di `docs/MOBILE_APP_PLAN.md` section 7).