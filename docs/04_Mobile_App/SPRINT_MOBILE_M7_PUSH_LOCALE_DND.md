# Sprint M7 — Push Localization + DND Quiet Hours

> Sprint mobile ketujuh. Fokus pada dua item dari M6 backlog yang
> menuntut perubahan backend: localized push body sesuai user locale
> dan DND (Do Not Disturb) quiet hours per user.
> **Update:** 28 Mei 2026.

---

## 1. Konteks

Sprint M3 (mobile/src/features/notifications/push.ts) sudah memasang
fanout FCM, dan sprint M6 sudah memberi user kontrol per-channel mute.
Tapi:

1. **Push body masih hardcoded Indonesia** di NotificationService. User
   yang pilih bahasa English di Settings tetap dapat push title/body
   "Cuti Anda disetujui" — pengalaman yang tidak konsisten dengan UI
   in-app.
2. **Tidak ada DND** — user yang sedang ibadah malam, perawatan medis,
   atau cuti panjang tetap kena buzz push setiap kali ada notif. Mute
   per-channel (M6) bersifat global, bukan time-window.

Sprint ini menambahkan dua kemampuan tanpa mengubah path fanout
existing. PushNotifier sengaja TIDAK diintegrasi ke `QuietHours` /
`PushLocalizer` di sprint ini agar review bisa fokus pada library
fondasi-nya. Wiring pemanggil ke fanout adalah next sprint (M7.5 atau
M8 candidate).

---

## 2. Yang ditambahkan

### 2.1 Migration #075

`backend/app/Database/Migrations/2024-01-01-000075_AddPushLocaleAndQuietHours.php`:

- Tambah kolom `users.quiet_hours_start` & `users.quiet_hours_end`
  (TIME nullable). Kalau end < start → window cross-midnight.
- Buat tabel `notification_locale_strings`:
  - `(company_id, notif_type, locale)` unique key
  - `title` VARCHAR(200), `body` TEXT
  - `company_id NULL` = global default; tenant override possible

### 2.2 QuietHours library

`backend/app/Libraries/QuietHours.php` — pure helper untuk evaluasi
window mute:

- `isQuiet(start, end, now)` — same-day window, cross-midnight, equal
  start/end (= 24-jam mute).
- `isQuietAt(start, end, DateTimeImmutable, tz)` — convenience yang
  konversi UTC ke timezone user (default Asia/Jakarta).
- Edge cases: null/empty → fail-open (jangan mute by accident); format
  invalid → fail-open.

### 2.3 PushLocalizer library

`backend/app/Libraries/PushLocalizer.php` — translasi notif:

- `resolve(notifType, locale, context, companyId, default)` — return
  `{title, body}` siap kirim ke FCM. Lookup order:
  1. `(company_id, notif_type, locale)` — tenant override per-locale
  2. `(NULL, notif_type, locale)` — global default per-locale
  3. `(company_id, notif_type, 'id')` — tenant fallback Indonesian
  4. `(NULL, notif_type, 'id')` — global Indonesian
  5. Caller-provided default
- `interpolate(template, context)` static — substitusi `{key}` dari
  context dictionary. Missing key dibiarkan literal (visible di QA).
- In-process memo cache per `(companyId, type, locale)` key supaya
  fanout 50+ user tidak hammer DB.
- Try/catch pada query DB → kalau migration belum jalan, fail-soft
  ke caller default.

### 2.4 Backend endpoint extension

`NotificationPreferenceController` (sudah ada) ditambah:

- **GET `/api/v1/notification-preferences/quiet-hours`** — return
  `{ start, end }` user. Backend trim "HH:MM:SS" MySQL → "HH:MM".
- **PUT `/api/v1/notification-preferences/quiet-hours`** — body
  `{ start, end }`. Validate format `HH:MM`. Jika salah satu null/empty,
  paksa keduanya null untuk konsistensi state. Kalau migration belum
  jalan, return HTTP 503 "feature belum aktif".
- Field `quiet_hours` ditambah ke response `index()` supaya UI bisa
  hydrate dari satu hit.
- Audit log entry untuk setiap perubahan (`quiet_hours.updated`).

Routes.php menambah dua route di-atas-pattern-resource untuk endpoint
khusus, sesuai pola sprint sebelumnya:

```
GET  /api/v1/notification-preferences/quiet-hours
PUT  /api/v1/notification-preferences/quiet-hours
GET  /api/v1/notification-preferences
PUT  /api/v1/notification-preferences
```

### 2.5 Mobile UI

**`preferencesApi.ts`** — type baru `QuietHours { start, end }` dan
fungsi `getQuietHours()` + `setQuietHours(start, end)`. Type response
`NotificationPreferencesResponse` ditambah field `quiet_hours?` agar
UI bisa hydrate dalam satu fetch.

**`NotificationPreferencesScreen.tsx`** — kartu baru "Jam tenang (DND)"
di paling atas list, di atas grid per-kategori:
- Dua text input HH:MM (Mulai, Selesai).
- Tombol "Simpan window" + "Matikan" (hanya muncul kalau ada window
  aktif).
- Validasi format HH:MM client-side; jika salah satu kosong, treat
  sebagai OFF.
- React-query mutation invalidate cache + alert konfirmasi.
- Honor backend response — re-baca state setelah save.

### 2.6 Tests

- `backend/tests/Unit/QuietHoursTest.php` — 7 test:
  - Normal same-day window
  - Cross-midnight window (22:00→06:00)
  - Equal start/end = always quiet
  - Null/empty fail-open
  - Invalid format fail-open
  - HH:MM:SS format support
  - Timezone conversion
- `backend/tests/Unit/PushLocalizerTest.php` — 6 test untuk pure
  `interpolate()`:
  - Basic replacement
  - Missing key literal
  - Empty template / empty context
  - Scalar coercion (int, bool)
  - Repeated placeholder

`PushLocalizer::resolve()` butuh DB connection; integration test
di-defer ke sprint berikut (atau ke feature test setelah migration
#075 dijalankan di CI).

---

## 3. File summary

### Backend (5 baru, 2 diubah)

```
backend/
├── app/
│   ├── Database/Migrations/
│   │   └── 2024-01-01-000075_AddPushLocaleAndQuietHours.php  ← BARU
│   ├── Libraries/
│   │   ├── QuietHours.php                                    ← BARU
│   │   └── PushLocalizer.php                                 ← BARU
│   ├── Controllers/Api/V1/
│   │   └── NotificationPreferenceController.php             ← +quiet-hours endpoints
│   └── Config/Routes.php                                    ← +2 route
└── tests/Unit/
    ├── QuietHoursTest.php                                    ← BARU
    └── PushLocalizerTest.php                                 ← BARU
```

### Mobile (1 baru in-place via diff, 2 diubah)

```
mobile/src/features/notifications/
├── preferencesApi.ts                              ← +QuietHours type & fns
└── NotificationPreferencesScreen.tsx              ← +card "Jam tenang"
```

### Sprint doc (1)

```
docs/SPRINT_MOBILE_M7_PUSH_LOCALE_DND.md          ← dokumen ini
```

---

## 4. Yang TIDAK termasuk (dan kenapa)

| Item                                | Alasan                                        |
|-------------------------------------|-----------------------------------------------|
| PushNotifier integration ke QuietHours | Defer — perubahan invasif yang lebih baik di-review dengan path fanout di sprint dedicated |
| Seed default locale strings (id+en) | Ops tetap edit via DB; CMS UI bisa di-iterate kalau perlu |
| DND time picker UI native           | Native picker di-defer; HH:MM TextInput cukup MVP |
| MMKV cache di mobile untuk quiet_hours | React-query sudah cache 30s, tidak butuh layer ekstra |
| Per-day-of-week DND                 | Future — backend belum dukung; weekday matrix bisa scope sprint terpisah |

---

## 5. Verifikasi

- ✅ Backend phpunit: **920 tests, 2082 assertions, 0 failures**
  (delta +13 dari M6: QuietHours 7 + PushLocalizer 6).
- ✅ Migration #075 `up()` dan `down()` idempotent (cek table/column
  exists sebelum operasi).
- ✅ Routes order benar: `/quiet-hours` di atas pattern resource.
- ✅ Backend endpoint return 503 kalau migration belum jalan (graceful
  degrade — UI mobile show fallback null).
- ✅ Mobile screen handle `quiet_hours` undefined (M6 mode tanpa M7
  migration) — UI tampil dengan empty state.

---

## 6. Sisa kerjaan ke next sprint (M8 candidate)

1. **PushNotifier integration:**
   - Sebelum `sendToUser()`, resolve dulu PushLocalizer dengan
     `device.locale` (sudah ada di tabel `device_tokens`).
   - Sebelum kirim, cek `QuietHours::isQuietAt()` — kalau quiet, skip
     channel push (in-app & email tetap kirim, jadi kontrak notifikasi
     ke user tetap intact).
2. **Seed default locale strings** untuk 12 notif type yang ada di
   `knownNotifTypes()` controller, supaya UI tidak fallback ke
   notifType slug saat first deploy.
3. **Sentry SDK actual wiring** (carry-over dari M6).
4. **PDF download payslip** (carry-over).
5. **Per-day-of-week DND** kalau ada user research justification.
6. **Native time picker** untuk quiet hours UI.

---

**Status: M7 closeout. Library fondasi (QuietHours + PushLocalizer)
sudah testable + production-ready. Wiring ke fanout PushNotifier akan
di-sprint khusus berikutnya.**

Total sprint mobile selesai: **M0 → M1 → M2 → M3 → M4 → M5 → M6 → M7**.