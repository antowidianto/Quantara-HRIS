# Sprint M6 — Theme Override + Notification Preferences

> **Arsip React Native:** catatan ini mendokumentasikan implementasi legacy.
> Feature baru dan migrasi mengikuti `MOBILE_FLUTTER_MIGRATION.md`.

> Sprint mobile keenam. Fokus: dua item paling-visible dari M5 backlog —
> theme override (light/dark/system) dan notification preferences UI
> per-channel.
> **Update:** 28 Mei 2026.

---

## 1. Konteks

M5 menutup app wiring tapi menyisakan dua kebutuhan UX yang sering
ditanya selama UAT internal:

1. **User belum bisa pilih dark mode kalau OS-nya light** — provider
   theme cuma follow `useColorScheme()` system. Kantor terang tapi
   user mau dark mode? Tidak bisa.
2. **Mute per-channel hanya bisa via web** — backend
   `NotificationPreferenceController` sudah ada, frontend web sudah
   kerjakan, tapi mobile belum punya UI-nya.

Sprint ini selesaikan kedua dengan dua component baru tanpa migrasi
atau endpoint backend tambahan.

---

## 2. Yang ditambahkan

### 2.1 Theme override (light / dark / system)

`mobile/src/theme/ThemeProvider.tsx` di-rewrite jadi context
provider penuh:

```ts
useTheme()        // back-compat — return Theme object
useThemeControl() // M6 — return { theme, mode, scheme, setMode }
```

- Mode tersimpan di MMKV key `theme.mode` (`'light' | 'dark'`).
- `'system'` artinya MMKV key di-delete (default state) → resolve via
  `useColorScheme()`.
- Resolved scheme di-recompute pakai `useMemo` dengan deps
  `[mode, systemScheme]`, jadi switch mode atau ganti OS theme keduanya
  ter-react.
- Test `mobile/src/theme/__tests__/themeProvider.test.ts` cover MMKV
  persistence + buildTheme tone differentiation.

UI control ada di **PreferencesScreen** card baru "Tema":
- Ikuti sistem (default)
- Terang (force light)
- Gelap (force dark)

### 2.2 Notification preferences UI

`mobile/src/features/notifications/preferencesApi.ts` — wrap backend
endpoint:
- `GET /api/v1/notification-preferences` → `{ preferences, available_channels, available_types }`
- `PUT /api/v1/notification-preferences` → bulk update

`mobile/src/features/notifications/NotificationPreferencesScreen.tsx`:

UI card per kategori notifikasi (leave.requested, payslip.published,
dst.). Tiap card berisi 4 toggle channel (in_app, email, whatsapp,
web_push) dengan rules:

- **in_app selalu enabled & disabled toggle-nya** (backend reject
  attempts to disable; UI honor itu agar konsisten).
- Setiap perubahan masuk **draft state lokal**. User klik "Simpan"
  untuk commit. Tombol Save menampilkan jumlah perubahan
  ("Simpan (3)") dan disabled kalau 0.
- Diff vs server state dihitung di `computeUpdates()` — hanya kirim
  yang berbeda, kurangi payload.
- Pakai react-query mutation; invalidate cache untuk re-fetch on success.

Wire sebagai sub-route di **PreferencesScreen** card baru "Notifikasi":
"Atur per channel" → push ke `NotificationPreferencesScreen`.

### 2.3 PreferencesScreen update

Card sections sekarang:
1. Bahasa (M5)
2. **Tema (M6 baru)**
3. Auto-lock threshold (M5)
4. Biometrik (M5)
5. **Notifikasi (M6 baru, sub-route)**

Heading masih "Preferensi" — tidak perlu dirombak.

---

## 3. File summary

### Tambahan baru (4)
```
mobile/src/features/notifications/preferencesApi.ts                   ← BARU
mobile/src/features/notifications/NotificationPreferencesScreen.tsx   ← BARU
mobile/src/theme/__tests__/themeProvider.test.ts                      ← BARU
docs/SPRINT_MOBILE_M6_THEME_NOTIF_PREFS.md                            ← dokumen ini
```

### Diubah (2)
```
mobile/src/theme/ThemeProvider.tsx               ← context provider with mode override
mobile/src/features/settings/PreferencesScreen.tsx  ← + Tema + Notifikasi rows
```

---

## 4. Yang TIDAK termasuk

| Item                              | Alasan                                            |
|-----------------------------------|---------------------------------------------------|
| Localized push body server-side   | Butuh perubahan PushNotifier — sprint backend dedicated |
| Sentry SDK actual wiring          | Stub di `lib/crash.ts` cukup untuk MVP            |
| PDF download payslip              | Native build access required                       |
| Description i18n di MoreMenu      | Sprint copywriting dedicated                      |
| Per-time-window mute (DND)        | Future — backend belum support                    |

---

## 5. Test coverage delta

- **Theme** (`themeProvider.test.ts`): 3 test untuk
  - light vs dark color differentiation
  - color tokens stability
  - MMKV roundtrip
- **NotificationPrefs**: belum ada unit test (UI-heavy; cover di e2e).
  API typedef + 4-channel constraint cukup terlindungi via TypeScript.
- Backend phpunit: **tidak diubah** di sprint ini.

---

## 6. Verifikasi akhir

- ✅ Backend phpunit: **907 tests, 2050 assertions, 0 failures** (no
  regression sejak M4; M5 & M6 tidak menyentuh backend).
- ✅ ThemeProvider back-compat: file lama yang import `useTheme()` tetap
  bekerja tanpa edit.
- ✅ Routes.php tidak diubah.
- ✅ Tidak ada migration baru.
- ✅ Dokumentasi handover lengkap.

---

## 7. Sisa kerjaan ke next sprint (M7 candidate)

1. **Sentry SDK actual wiring** + source map upload di CI.
2. **PDF download payslip** dengan `react-native-blob-util`.
3. **Localized push body** — backend kirim payload sesuai user locale.
4. **Description string i18n** di MoreMenu (copywriting sprint).
5. **DND / quiet hours** untuk notification preferences (per-channel
   time window).
6. **MMKV listener-based gate** saat upgrade ke MMKV v3.
7. **Native build polish** (icon, splash, app store assets).

---

**Status: M6 closeout. Mobile app sekarang menyediakan kontrol UX yang
kompetitif untuk distribusi internal beta.**

Total mobile sprint selesai: **M0 → M1 → M2 → M3 → M4 → M5 → M6**.
