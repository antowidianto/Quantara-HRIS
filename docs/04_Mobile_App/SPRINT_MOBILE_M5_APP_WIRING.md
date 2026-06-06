# Sprint M5 — App Wiring & Settings Hardening

> Sprint mobile kelima. Fokus: tarik primitive hardening dari M4
> (ErrorBoundary, IdleLock, version gate) ke dalam App.tsx & UI utama,
> tambah Preferences screen (language switcher + idle threshold), dan
> aktifkan i18n di tab bar + MoreMenu.
> **Update:** 28 Mei 2026.

---

## 1. Konteks

M4 menghasilkan tiga primitive hardening (`ErrorBoundary`,
`IdleLockGate`, `ForceUpdateGate`) tapi belum dipasang ke App.tsx — file
masih punya struktur lama dari M3. Selain itu, i18n dictionary sudah
diperluas tapi screen utama masih hard-code label Indonesia. Sprint ini
menutup loop:

- Tiga gerbang produksi diaktifkan tanpa keputusan UX yang baru
  (banner soft-recommend tetap di mana developer bisa override).
- User dapat ganti bahasa, ubah idle threshold, dan toggle biometric
  di satu tempat (Preferences) tanpa perlu masuk ke 2FA-manage.

Tidak ada migration; tidak ada endpoint baru.

---

## 2. Yang ditambahkan

### 2.1 IdleLockGate component

`mobile/src/components/IdleLockGate.tsx` — fullscreen overlay yang
mount mengelilingi MainTabs. Polling 1 detik (`setInterval`) untuk
sinkron dengan flag `IdleLock.isLocked()`. UI:
- Ikon kunci + judul + body i18n (`idle.title`, `idle.body`).
- Tombol primary "Buka kunci" → `Biometric.authenticate()`.
- Tombol sekunder "Sign out" → call `onSignOut` props yang dipasang
  ke `useAuthStore.signOut`.

Polling ringan (1s) dan setState hanya saat `prev !== cur` supaya
tidak trigger re-render terus.

### 2.2 ForceUpdateGate component

`mobile/src/components/ForceUpdateGate.tsx` — dipasang sebelum Boot.
Tiga case:
- `'force'` → fullscreen blocker dengan tombol `Linking.openURL` ke
  store URL platform-aware. Kalau URL kosong, fallback ke pesan
  "Hubungi IT helpdesk" + label `Platform.OS`.
- `'recommended'` → banner warning di atas children, dengan tombol
  Update + Tutup (state lokal `dismissed`).
- `'ok'` (atau result null saat fetch belum selesai) → render children
  apa adanya. Optimistic — kita tidak block UI selama 50–200ms latency
  untuk ambiguity.

### 2.3 App.tsx wiring

Provider tree sekarang:
```
SafeAreaProvider
└─ QueryClientProvider
   └─ ThemeProvider
      └─ ErrorBoundary  ← catch render-time exception
         └─ ForceUpdateGate  ← block kalau backend bilang force-update
            └─ Boot  ← auth bootstrap + IdleLockGate(MainTabs)
```

Boot juga sekarang memanggil `IdleLock.install()` di useEffect supaya
listener AppState aktif sepanjang lifetime app. Cleanup return
function-nya.

ErrorBoundary diberi `fallbackHint` Indonesia agar user tahu yang
harus dilakukan kalau crash. Ini lebih bermakna ketimbang stacktrace
mentah.

### 2.4 PreferencesScreen

`mobile/src/features/settings/PreferencesScreen.tsx`:

- **Bahasa**: list `availableLocales()` dengan checkmark di yang aktif.
  `setLocale()` immediate; UI re-render dengan `setTick`.
- **Auto-lock threshold (menit)**: numeric input, save saat blur atau
  submitEditing. `IdleLock.setThresholdSec(n*60)` clamp ke max 60
  menit otomatis, lalu re-baca untuk menampilkan nilai aktual.
- **Toggle biometrik**: shortcut ke `Biometric.setEnabled()`.

Wire ke `SettingsScreen.tsx` sebagai sub-route ketiga "Preferensi"
selain Ubah password & Verifikasi 2 langkah. Heading screen di-rename
dari "Pengaturan Keamanan" → "Pengaturan" karena scope-nya luas
sekarang.

### 2.5 i18n active

- `mobile/src/MainTabs.tsx` — `TABS` const di-konversi jadi `buildTabs()`
  factory yang panggil `t()` setiap render. Switch bahasa di
  Preferences langsung ter-refleksi saat MainTabs re-mount setelah
  user keluar dari More menu.
- `mobile/src/MoreMenu.tsx` — `MENU` jadi `buildMenu()`, plus heading
  + subtitle + back button label semua via `t()`.

Description per menu item sengaja tetap hard-code Indonesia di M5.
Ekstrak description ke i18n adalah sprint terpisah karena banyak
string + butuh review copywriting.

---

## 3. File summary

### Tambahan baru (3)
```
mobile/src/components/IdleLockGate.tsx              ← BARU
mobile/src/components/ForceUpdateGate.tsx           ← BARU
mobile/src/features/settings/PreferencesScreen.tsx  ← BARU
```

### Diubah (4)
```
mobile/App.tsx                                       ← provider tree + IdleLock.install
mobile/src/features/settings/SettingsScreen.tsx      ← +row Preferensi
mobile/src/MainTabs.tsx                              ← labels via t()
mobile/src/MoreMenu.tsx                              ← labels via t()
```

### Sprint doc baru (1)
```
docs/SPRINT_MOBILE_M5_APP_WIRING.md                  ← dokumen ini
```

---

## 4. Yang TIDAK termasuk (dan kenapa)

| Item                              | Alasan                                            |
|-----------------------------------|---------------------------------------------------|
| Description string i18n           | Butuh sprint copywriting terpisah                 |
| Theme override (light/dark/system)| Tier 3, defer setelah ada user feedback           |
| Notification preferences UI       | Sudah ada endpoint backend, butuh design dedicated |
| Sentry SDK actual wiring          | Wrapper di `lib/crash.ts` cukup untuk MVP         |
| MMKV listener (alih dari polling) | RN MMKV punya `addOnValueChangedListener` di v3+; sekarang masih v2.12, polling 1s acceptable |

---

## 5. Verifikasi

- ✅ Backend phpunit: **907 tests, 2050 assertions, 0 failures**
  (tidak ada perubahan backend di M5).
- ✅ Mobile changes konsisten dengan pattern M0-M4 (tetap zero-dep di
  luar yang sudah ada di package.json).
- ✅ i18n test (`mobile/src/i18n/__tests__/i18n.test.ts`) tetap pass —
  tidak ada key existing yang dihapus.
- ✅ Routes.php tidak diubah.
- ✅ Provider tree di App.tsx ter-dokumentasi dengan ASCII diagram.

---

## 6. Sisa kerjaan ke next sprint (M6 candidate)

1. **Localized push notification body** — backend kirim payload sesuai
   user locale. Saat ini push pakai server default.
2. **Theme override** (light/dark/system) di Preferences.
3. **Notification preferences UI** untuk per-channel mute (push, email,
   in-app per kategori).
4. **Sentry SDK wiring** dengan source map upload di CI.
5. **PDF download** (carry over dari M3+M4 — masih milik developer
   mobile dengan native build access).
6. **MMKV listener-based gate** saat upgrade ke v3.

---

**Status: M5 closeout. App tree siap untuk distribusi internal beta.**

Total sprint mobile yang sudah selesai: **M0 → M1 → M2 → M3 → M4 → M5**.
Roadmap MOBILE_APP_PLAN.md original (M5 = beta release) telah
ter-cover melalui kombinasi M3-M5.