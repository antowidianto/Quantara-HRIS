# Tutorial 02 — Dev Workflow & Run di Device

Cara menjalankan app HRIS Mobile di emulator atau HP fisik untuk
develop sehari-hari (hot reload, debugging, log).

> Prasyarat: sudah selesai
> [Tutorial 01 — Setup](TUTORIAL_MOBILE_01_SETUP.md).

---

## 1. Hubungkan device

Pilih salah satu cara: emulator atau HP fisik.

### A. Emulator Android

1. Buka Android Studio → "Virtual Device Manager"
2. Klik tombol play di emulator yang sudah dibuat
3. Tunggu sampai home screen muncul

Verifikasi dari terminal:
```bat
adb devices
```
Output yang diharapkan:
```
List of devices attached
emulator-5554   device
```

### B. HP fisik via USB

1. Di HP: Settings → About Phone → tap "Build Number" 7x sampai muncul
   "Developer Mode enabled"
2. Settings → System → Developer Options:
   - Aktifkan **USB debugging**
   - (Opsional) Aktifkan **Stay awake** supaya layar tidak mati saat
     charging
3. Sambungkan HP ke PC via USB
4. Di HP akan muncul popup "Allow USB debugging?" → **Allow** (centang
   "Always allow from this computer")

Verifikasi:
```bat
adb devices
```
```
List of devices attached
ABCD12345678   device
```

> Kalau muncul `unauthorized`: cabut USB → matikan & nyalakan USB
> debugging → colokkan lagi → Allow popup. Kalau muncul `offline`:
> `adb kill-server && adb start-server`.

### C. HP fisik via WiFi (Android 11+)

1. Aktifkan USB debugging dulu (cara di B)
2. Di Developer Options → aktifkan **Wireless debugging**
3. Tap "Wireless debugging" → "Pair device with pairing code"
4. Catat IP+port yang muncul (mis. `192.168.1.5:37123`) dan kode 6 digit
5. Di PC:
   ```bat
   adb pair 192.168.1.5:37123
   :: masukkan kode 6 digit

   :: Lalu connect ke port debugging (beda dengan port pairing)
   adb connect 192.168.1.5:5555
   ```
6. Setelah connected, USB boleh dicabut.

---

## 2. Start Metro bundler

Metro adalah JavaScript bundler React Native — dia compile JS source
realtime dan kirim ke device tiap kali kamu save file.

Buka **terminal pertama**:

```bat
cd mobile
npm start
```

Output:
```
                ▒▒▓▓▓▓▒▒
              ▒▓▓▓▒▒░░▒▒▓▓▓▒
            ▒▓▓▓▓░░░▒▒▒▒░░░▓▓▓▓▒
            ▓▓▓░░░▒▓▓▓▓▓▓▓▓▒░░░▓▓▓
            ...
            Welcome to Metro v0.81.x

› Press a │ open Android
› Press r │ reload app
› Press d │ open Dev Menu
› Press j │ open debugger
```

Biarkan terminal ini running.

---

## 3. Install + run app ke device

Buka **terminal kedua** (Metro tetap nyala di terminal pertama):

```bat
cd mobile
npm run android
```

Yang terjadi:
1. Gradle compile native code Android (~5-10 menit pertama kali, ~30
   detik setelahnya)
2. APK debug auto-install ke device yang aktif (`adb devices`)
3. App auto-launch
4. Metro inject JS bundle realtime

Kalau berhasil, app HRIS akan muncul di layar device.

> **Build pertama lama (10-15 menit)** karena Gradle download dependency
> dan compile native code untuk semua arch. Build kedua dst. cuma 30-60
> detik selama tidak ada perubahan native.

---

## 4. Hot reload — edit kode, lihat hasil instan

Setelah app jalan:

1. Buka file source di IDE, mis. `mobile/src/features/auth/LoginScreen.tsx`
2. Edit text/style/logic, save (Ctrl+S)
3. App di device akan auto-refresh dalam 1-2 detik

Modes:
- **Fast Refresh** (default): perubahan kecil, state komponen
  dipertahankan
- **Reload manual**: kalau Fast Refresh stuck, tekan `r` di terminal
  Metro, atau shake device → "Reload"

---

## 5. Dev Menu (penting!)

Di app yang lagi running:
- **Emulator**: `Ctrl+M` (Windows/Linux) atau `Cmd+M` (Mac)
- **HP fisik**: shake HP

Menu yang muncul:
- **Reload** — reload JS tanpa restart app
- **Debug with Chrome** — buka DevTools di browser (lihat section 6)
- **Show Element Inspector** — klik komponen di layar untuk lihat
  struktur React (mirip Inspector di browser)
- **Toggle Performance Monitor** — overlay FPS, RAM
- **Settings** — set IP host Metro (untuk WiFi development)

---

## 6. Debugging

### A. Console.log

```typescript
console.log('[LoginScreen] tap login', { email, hasPassword: !!password });
```

Log muncul di terminal Metro. Untuk warna/level:
```typescript
console.warn('balance kurang')
console.error('API down', err)
```

### B. React DevTools

```bat
npx react-devtools
```
Buka jendela baru. Di app, Dev Menu → "Toggle Inspector" → klik komponen
→ DevTools sync.

### C. Chrome DevTools (JS only)

Dev Menu → "Open Debugger". Browser tab terbuka dengan DevTools.
Source tab → `localhost:8081` → set breakpoint.

### D. Flipper (advanced)

Download Flipper dari <https://fbflipper.com/>. Bisa lihat:
- Network log (request/response axios)
- AsyncStorage / MMKV content
- Database (kalau pakai SQLite)
- Crash report
- Layout inspector

---

## 7. Lihat log device langsung

Kalau app crash dan info di Metro tidak cukup:

```bat
:: Filter ke app HRIS saja
adb logcat | findstr "hrismobile ReactNativeJS AndroidRuntime"

:: Atau pakai filter level
adb logcat *:E    :: error+ saja

:: Reset buffer (kalau penuh)
adb logcat -c
```

Untuk track crash pas reproduksi bug:
```bat
adb logcat -c && adb logcat *:W
:: lalu reproduksi crash di app
```

---

## 8. Test changes — workflow lengkap

Sebelum commit/push, pastikan:

```bat
cd mobile

:: 1. Format kode (kalau ada prettier)
npx prettier --write src/

:: 2. Lint
npm run lint

:: 3. TypeScript check
npx tsc --noEmit

:: 4. Unit test
npm test

:: 5. Smoke test build (optional, kalau edit native config)
cd android
gradlew.bat :app:assembleDebug
```

Kalau semua hijau → commit.

---

## 9. Connect ke backend lokal

Backend HRIS biasanya jalan di `localhost:8080`. Tapi dari device,
`localhost` itu device-nya sendiri, bukan PC.

Tabel mapping:

| Device | Cara akses backend di PC | Set di `.env` |
|--------|--------------------------|---------------|
| Emulator Android | Pakai IP khusus emulator → host PC | `API_BASE_URL=http://10.0.2.2:8080/api/v1` |
| HP fisik (USB / WiFi sama) | Pakai IP LAN PC | `API_BASE_URL=http://192.168.1.5:8080/api/v1` |
| Emulator iOS | Pakai `localhost` | `API_BASE_URL=http://localhost:8080/api/v1` |
| HP fisik via tunnel | Pakai ngrok/cloudflared | `API_BASE_URL=https://abc123.ngrok.io/api/v1` |

> **PENTING:** Setelah edit `.env`, **rebuild app** (`npm run android`)
> karena `react-native-config` inject value saat build, bukan runtime.

> Backend harus listen di `0.0.0.0:8080` (bukan `127.0.0.1:8080`) supaya
> bisa diakses dari LAN. Cek `php spark serve --host=0.0.0.0 --port=8080`.

---

## 10. Workflow harian recommended

```
Pagi:
  cd mobile
  git pull
  npm ci             :: kalau package.json berubah
  npm start          :: terminal 1, biarkan jalan
  npm run android    :: terminal 2, sekali

Saat ngoding:
  edit src/...
  save → app auto-reload
  console.log untuk debug
  Cmd+M / shake → Dev Menu

Sebelum push:
  npx tsc --noEmit
  npm test
  git add -p
  git commit
  git push
```

---

## 11. Tips produktivitas

- **Pin Metro terminal** ke pojok layar, jangan minimize. Biar log
  terus kelihatan.
- **Pakai `console.log` dengan tag**: `console.log('[Auth.login]', ...)`
  → gampang grep kalau log panjang.
- **Set breakpoint di backend juga** kalau API behavior aneh — pakai
  Xdebug/PhpStorm di sisi backend.
- **Pakai dummy data dulu** kalau backend belum ready: mock di
  `src/api/__mocks__/`.
- **Fast Refresh kadang stale** kalau edit `babel.config.js` atau
  `metro.config.js` — restart Metro dengan `npm start -- --reset-cache`.

---

## 12. Beda dev mode vs production

| Aspek | Dev (`npm run android`) | Release APK |
|-------|------------------------|-------------|
| JS bundle | Live dari Metro (port 8081) | Pre-bundled di APK |
| Debugging | Aktif | Mati |
| Console.log | Tampil di Metro | Hilang (Hermes optimasi) |
| Performance | Lebih lambat (dev overhead) | Cepat |
| Ukuran APK | ~130 MB | ~60 MB |
| Yang panik kalau no internet | Iya (Metro disconnect) | Tidak |

Saat testing fitur untuk demo client, **selalu test di release build**,
bukan dev mode — perilaku bisa beda.

---

## 13. Reset state app (clean slate)

Kadang state corrupt (token expired aneh, MMKV cache, dll). Reset:

```bat
:: Uninstall total
adb uninstall com.hrismobile

:: Atau clear data tanpa uninstall
adb shell pm clear com.hrismobile

:: Lalu install ulang
npm run android
```

---

## 14. Lanjut

- Kalau mau bikin file APK untuk dibagikan ke tester →
  [Tutorial 03 — Build APK](TUTORIAL_MOBILE_03_BUILD_APK.md)
- Kalau mau publish ke Play Store →
  [Tutorial 04 — Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md)
- Kalau ada error build/runtime →
  [Tutorial 06 — Troubleshooting](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md)