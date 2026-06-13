# Tutorial 06 — Troubleshooting Lengkap

Kumpulan masalah umum saat develop, build, atau run app HRIS Mobile,
dengan solusi step-by-step. Cari pakai Ctrl+F.

> Update terakhir: 11 Juni 2026 (verifikasi RN 0.76.3 + JDK 17 +
> Android SDK 34).

---

## A. Masalah Setup / Environment

### A1. `'java' is not recognized as an internal or external command`

**Penyebab:** `JAVA_HOME` belum diset atau PATH tidak include
`%JAVA_HOME%\bin`.

**Solusi (Windows):**
1. Win → Environment Variables → cek `JAVA_HOME` ada
2. Edit Path → cek ada `%JAVA_HOME%\bin`
3. Restart terminal (BUKAN cuma restart VSCode — restart cmd/PowerShell)
4. Verifikasi: `java -version`

### A2. `'adb' is not recognized`

**Solusi:** Tambahkan `%ANDROID_HOME%\platform-tools` ke Path. Restart
terminal.

### A3. JDK 21 atau JDK 11 — `Unsupported class file major version 65`

**Penyebab:** RN 0.76 strict di JDK 17. JDK lain tidak compatible.

**Solusi:**
1. Install JDK 17 (Temurin) — lihat
   [Tutorial 01 section 3](TUTORIAL_MOBILE_01_SETUP.md#3-install-jdk-17)
2. Update `JAVA_HOME` arahkan ke JDK 17
3. Verifikasi: `java -version` muncul `17.x.x`
4. `cd mobile/android && gradlew.bat clean`
5. Build ulang

### A4. `npm ci` gagal karena `node-gyp`

**Gejala:** Error compile native module saat npm install/ci.

**Solusi (Windows):**
```bat
:: Install Visual Studio Build Tools
:: Download: https://visualstudio.microsoft.com/visual-cpp-build-tools/
:: Saat install, pilih "Desktop development with C++"

:: Atau (legacy, kalau Windows lama):
npm install -g windows-build-tools
```

### A5. `npm ci` lambat / hang di "extract"

**Solusi:**
```bat
:: Pakai mirror npm Indonesia/China (sementara)
npm config set registry https://registry.npmmirror.com
npm ci
:: Setelah selesai, balikkan
npm config set registry https://registry.npmjs.org
```

### A6. Antivirus blokir gradlew.bat

**Gejala:** Build sangat lambat (>30 menit), atau gagal random.

**Solusi:**
1. Whitelist folder repo di antivirus (Windows Defender,
   Kaspersky, dll). Add exclusion path: `D:\Apps Project\hris-project-1`
2. Whitelist juga `%USERPROFILE%\.gradle` dan `C:\Android\Sdk`

---

## B. Masalah Metro Bundler

### B1. `Error: ENOENT: no such file or directory, open '...index.android.bundle'`

**Penyebab:** Bundle JS belum di-generate untuk debug build offline.

**Solusi:**
```bat
cd mobile
npx react-native bundle ^
  --platform android ^
  --dev true ^
  --entry-file index.js ^
  --bundle-output android\app\src\main\assets\index.android.bundle ^
  --assets-dest android\app\src\main\res
```

### B2. Metro stuck di "Loading dependency graph"

**Solusi:**
```bat
cd mobile
:: Clear Metro cache
npx react-native start --reset-cache
```

Atau hapus cache manual:
```bat
:: Windows
rd /s /q %TEMP%\metro-cache
rd /s /q %TEMP%\haste-map-*

:: macOS
rm -rf $TMPDIR/metro-*
rm -rf $TMPDIR/haste-map-*
```

### B3. Port 8081 sudah dipakai

**Gejala:** `Error: listen EADDRINUSE: address already in use :::8081`

**Solusi (Windows):**
```bat
:: Cari proses yang pakai port 8081
netstat -ano | findstr :8081
:: Output: TCP 0.0.0.0:8081 ... 12345

:: Kill proses
taskkill /PID 12345 /F
```

Atau pakai port lain:
```bat
npm start -- --port 8088
:: Lalu di app: shake → Settings → set debug server host: 10.0.2.2:8088
```

### B4. Fast Refresh tidak jalan

**Solusi:**
1. Dev Menu (Ctrl+M / shake) → centang "Enable Fast Refresh"
2. Kalau masih, restart Metro dengan `npm start -- --reset-cache`
3. Edit file `babel.config.js` atau `metro.config.js` butuh full
   restart (Metro tidak watch file ini)

---

## C. Masalah Build Gradle / Android

### C1. `SDK location not found. Define ANDROID_HOME`

**Solusi:**
1. Set environment variable `ANDROID_HOME` dan `ANDROID_SDK_ROOT`
2. Atau buat `mobile/android/local.properties`:
   ```properties
   sdk.dir=C\:\\Android\\Sdk
   ```
   (backslash di-escape jadi `\\`)

### C2. `Could not resolve all files for configuration ':app:debugRuntimeClasspath'`

**Penyebab:** Gradle gagal download dependency. Internet/proxy issue.

**Solusi:**
```bat
cd mobile\android
gradlew.bat clean --refresh-dependencies

:: Kalau masih, hapus cache Gradle
rd /s /q %USERPROFILE%\.gradle\caches
gradlew.bat :app:assembleDebug
```

Kalau di belakang corporate proxy, set di `gradle.properties`:
```properties
systemProp.http.proxyHost=proxy.company.com
systemProp.http.proxyPort=8080
systemProp.https.proxyHost=proxy.company.com
systemProp.https.proxyPort=8080
```

### C3. Build OOM (out of memory)

**Gejala:** `Java heap space` saat build.

**Solusi:** Edit `mobile/android/gradle.properties`:
```properties
org.gradle.jvmargs=-Xmx4096m -XX:MaxMetaspaceSize=1024m -XX:+HeapDumpOnOutOfMemoryError -Dfile.encoding=UTF-8
```

Naikkan dari default 2048m ke 4096m. Kalau PC RAM 16GB+, boleh sampai
8192m.

### C4. NDK error: `No version of NDK matched the requested version`

**Penyebab:** NDK version di config tidak terinstall.

**Solusi:**
```bat
cd C:\Android\Sdk\cmdline-tools\latest\bin
sdkmanager.bat "ndk;26.1.10909125"
```

Versi NDK ada di `mobile/android/build.gradle`:
```gradle
ndkVersion = "26.1.10909125"
```

### C5. `error: cannot find symbol class Activity` di MainActivity.kt

**Penyebab:** Build cache corrupt.

**Solusi:**
```bat
cd mobile\android
gradlew.bat clean
rd /s /q app\build
gradlew.bat :app:assembleDebug
```

### C6. Hard link warning di Windows (lambat tapi tidak fail)

**Gejala:**
```
Hard link from 'C:\Users\...\libreactnative.so' to '...' failed.
Doing a slower copy instead.
```

**Penjelasan:** Bukan error, cuma warning. Disebabkan Windows tidak
support hard link antar volume. Build tetap sukses, cuma sedikit
lebih lambat.

**Solusi (opsional, untuk speed up):**
- Pastikan project + Android SDK + Gradle cache di drive yang sama
  (mis. semuanya di C:)
- Atau enable Developer Mode di Windows (Settings → Update & Security
  → For developers → Developer Mode = on) supaya ada permission untuk
  symlink/hard link

### C7. `Execution failed for task ':app:mergeDebugResources'`

**Penyebab:** Resource (drawable, mipmap) corrupt atau duplicate.

**Solusi:**
```bat
cd mobile\android
gradlew.bat clean
gradlew.bat :app:assembleDebug --info > build-log.txt 2>&1
:: Cek build-log.txt untuk file mana yang bermasalah
```

Common: PNG corrupt — re-export dari source. Atau XML duplicate id
— rename salah satunya.

### C8. `App not installed` di HP saat install APK

**Penyebab:**
1. Keystore mismatch — APK baru di-sign beda dengan versi yang sudah
   ter-install
2. Storage HP penuh

**Solusi:**
```bat
:: Uninstall versi lama
adb uninstall com.hrismobile

:: Install ulang
adb install -r app-release.apk
```

### C9. `INSTALL_FAILED_USER_RESTRICTED`

**Penyebab:** USB install dimatikan di Developer Options (HP Xiaomi
sering default off).

**Solusi:**
- HP Xiaomi: Settings → Developer Options → "Install via USB" = on
- HP Samsung: Settings → Biometrics and security → "Install unknown
  apps" → enable untuk file manager

---

## D. Masalah Runtime di Device

### D1. Red box: "Network Error" saat clock-in dari emulator Android

**Penyebab:** Android emulator tidak bisa akses `localhost` PC.
`localhost` di emulator = emulator itu sendiri.

**Solusi:** Edit `mobile/.env`:
```
API_BASE_URL=http://10.0.2.2:8080/api/v1
```
`10.0.2.2` adalah alias khusus emulator → host PC.

Rebuild app (`npm run android`) — `.env` di-inject saat build, bukan
runtime.

### D2. Red box: "Network Error" saat dari HP fisik

**Penyebab:** HP tidak bisa akses `localhost` PC. Harus pakai IP LAN.

**Solusi:**
1. PC + HP harus di WiFi yang sama
2. Cari IP LAN PC: `ipconfig` (Windows) atau `ifconfig` (mac/Linux)
3. Edit `.env`:
   ```
   API_BASE_URL=http://192.168.1.5:8080/api/v1
   ```
4. Backend listen di `0.0.0.0:8080` (bukan `127.0.0.1:8080`):
   ```bash
   php spark serve --host=0.0.0.0 --port=8080
   ```
5. Allow port 8080 di firewall PC
6. Test di browser HP: buka `http://192.168.1.5:8080/api/v1/health`
   — harus respond JSON

### D3. Clock-in stuck di "Mengambil lokasi..."

**Solusi:**
1. Permission GPS belum di-grant: HP → Settings → Apps → HRIS Mobile
   → Permissions → Location = Allow
2. Lokasi mock di emulator: Android Studio Emulator → ⋮ (extended
   controls) → Location → set lat/lng manual atau import GPX
3. iOS Simulator: Features → Location → pilih "Apple" atau "Custom
   Location"

### D4. Token expired tapi user tidak di-logout

**Penyebab:** Refresh-on-401 single-flight di `client.ts` gagal.

**Cek:**
```typescript
// Di app, buka Dev Menu → Debug
// Console: cek log [Auth.refresh] atau [client.401]
```

**Solusi:**
1. Cek backend `/auth/refresh` endpoint berfungsi (test via Postman)
2. Reset secure storage:
   ```bat
   adb shell pm clear com.hrismobile
   ```
3. Login ulang

### D5. Biometric login tidak muncul

**Cek:**
```typescript
import {Biometric} from '@/lib/biometric';
console.log(await Biometric.capability());
// { available: false, reason: 'NotEnrolled' } artinya HP belum daftar
// fingerprint/Face ID di OS settings
```

**Solusi:**
- Daftar fingerprint/Face ID di Settings HP dulu
- Reset flag biometric: `Biometric.setEnabled(false)` lalu enable lagi
- Android emulator: Extended controls → Fingerprint → Touch sensor

### D6. App crash saat launch (release APK)

**Cek log:**
```bat
adb logcat -c
:: Buka app sampai crash
adb logcat | findstr "AndroidRuntime hrismobile"
```

Common penyebab:
- ProGuard rule terlalu agresif → komponen JS tidak ke-bundle.
  Tambah keep rule di `mobile/android/app/proguard-rules.pro`:
  ```
  -keep class com.facebook.hermes.unicode.** { *; }
  -keep class com.facebook.jni.** { *; }
  ```
- Native module tidak ter-link → run `npm run android` rebuild
- `google-services.json` tidak ada (kalau pakai Firebase)

### D7. Push notification tidak masuk

**Cek:**
1. FCM token sudah register?
   ```bat
   :: Dari Postman, GET /api/v1/devices dengan token user
   :: Harus ada device dengan FCM token
   ```
2. `google-services.json` ada di `mobile/android/app/`?
3. Backend env `fcm.projectId` dan `fcm.serviceAccountPath` set?
4. Test manual dari Firebase Console → Cloud Messaging → "Send to
   single token"

**Solusi umum:**
- Pastikan permission notification granted (Android 13+ butuh runtime
  permission)
- Cek battery optimization HP — sebagian HP (Xiaomi, Oppo) auto-kill
  background → Settings → Apps → HRIS → Battery → "No restrictions"

### D8. Offline outbox tidak replay saat online

**Cek:**
```typescript
import {Outbox} from '@/api/outbox';
import NetInfo from '@react-native-community/netinfo';

console.log('outbox count:', Outbox.count());
console.log('netinfo:', await NetInfo.fetch());
// isConnected: true && isInternetReachable: true wajib
```

**Solusi:**
1. Tap badge "outbox pending" di Attendance screen → manual sweep
2. Backend reachable? Test `/api/v1/health` dari device
3. Cek log Metro `[Outbox.sweep]` ada error apa

---

## E. Masalah TypeScript / Test

### E1. `Cannot find module 'react-native'` di test Jest

**Solusi:** Cek `package.json` jest config:
```json
"transformIgnorePatterns": [
  "node_modules/(?!(react-native|@react-native|@react-navigation|@tanstack)/)"
]
```

Kalau pakai library baru yang ES module, tambah ke pattern ini.

### E2. `MMKV is not a constructor` di test

**Solusi:** Stub MMKV ada di `mobile/jest.setup.js`. Pastikan test
import dari `@store/mmkv` (bukan langsung dari `react-native-mmkv`).

### E3. `npx tsc --noEmit` munculkan ratusan error setelah `git pull`

**Solusi:**
```bat
cd mobile
rd /s /q node_modules
del package-lock.json
npm install
npx tsc --noEmit
```

Kalau VSCode masih merah:
- Cmd/Ctrl+Shift+P → "TypeScript: Restart TS Server"
- Kalau tetap, restart VSCode

### E4. Test gagal random — `flaky test`

**Penyebab:** Async timing issue, mock state bocor antar test.

**Solusi:**
```typescript
beforeEach(() => {
  jest.clearAllMocks();   // reset mock
  jest.useFakeTimers();   // kontrol waktu
});

afterEach(() => {
  jest.useRealTimers();
});
```

Jangan share state global antar test file.

---

## F. Masalah CocoaPods / iOS (macOS only)

### F1. `pod install` error "Invalid Podfile"

**Solusi:**
```bash
# Update Ruby ke ≥ 3.1.0
brew install rbenv ruby-build
rbenv install 3.1.4
rbenv global 3.1.4
gem install cocoapods

cd ios
rm -rf Pods Podfile.lock
pod install
```

### F2. `pod install` error "CDN trunk URL"

**Penyebab:** CocoaPods CDN tidak reachable (firewall / DNS).

**Solusi:**
```bash
pod repo update
pod install --verbose --repo-update
```

Atau switch ke spec repo manual:
```bash
pod repo add-cdn trunk https://cdn.cocoapods.org/
```

### F3. Xcode build error "No such module 'React'"

**Solusi:**
```bash
cd ios
rm -rf Pods Podfile.lock
pod install
# Buka HrisMobile.xcworkspace (BUKAN .xcodeproj)
```

### F4. Provisioning profile tidak match

**Solusi:**
1. Xcode → project → Signing & Capabilities
2. Centang "Automatically manage signing"
3. Pilih Team
4. Klik "Try Again" kalau error muncul

### F5. iOS Simulator stuck di "Welcome to React Native"

**Solusi:**
1. Cmd+R di simulator (reload)
2. Cmd+D → Reload
3. Kalau tetap, restart Metro: `npm start -- --reset-cache`
4. Restart simulator: `xcrun simctl erase all`

---

## G. Masalah CI / GitHub Actions

### G1. Build di GitHub Actions sukses tapi tidak match local

**Penyebab umum:**
- Node version beda → set `node-version: 18` exact di workflow
- JDK version beda → set `java-version: 17`
- `package-lock.json` out of sync → commit lock file

### G2. Secret keystore tidak ke-decode

**Solusi:** Encode keystore base64 dengan benar:
```bat
:: Windows
certutil -encode release.keystore keystore.b64
:: Buka keystore.b64, copy isi (tanpa BEGIN/END line) ke GitHub Secret
```

```bash
# Linux/macOS
base64 -i release.keystore -o keystore.b64
# Copy isi keystore.b64 ke GitHub Secret
```

Di workflow:
```yaml
- name: Decode keystore
  run: |
    echo "${{ secrets.ANDROID_KEYSTORE_BASE64 }}" | base64 -d > mobile/android/app/release.keystore
```

### G3. macOS runner timeout (build iOS > 6 jam)

**Solusi:**
- Pakai `macos-14` (bukan `macos-latest` yang kadang `macos-12`)
- Cache Pods + node_modules:
  ```yaml
  - uses: actions/cache@v4
    with:
      path: |
        mobile/node_modules
        mobile/ios/Pods
      key: ${{ runner.os }}-${{ hashFiles('mobile/package-lock.json', 'mobile/ios/Podfile.lock') }}
  ```

---

## H. Masalah Play Store / App Store

### H1. Play Store reject: "Privacy policy missing"

**Solusi:** Lihat
[Tutorial 04 section 4](TUTORIAL_MOBILE_04_PLAY_STORE.md#4-privacy-policy-wajib).
Privacy policy URL harus public, HTTPS, dan komprehensif.

### H2. Play Store reject: "Background location"

**Solusi:** Buang permission yang tidak dipakai di
`AndroidManifest.xml`:
```xml
<!-- HAPUS kalau tidak dipakai -->
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
```

HRIS hanya butuh foreground location (`ACCESS_FINE_LOCATION` saat
clock-in).

### H3. Play Store reject: "Test account broken"

**Solusi:** Update credential di Play Console → App content →
App access. Pastikan akun test masih aktif + punya data dummy.

### H4. App Store reject: "Guideline 4.5.4 — Push notification ads"

**Solusi:** Jelaskan di review notes — push notif HRIS untuk approval
flow + reminder, bukan iklan.

### H5. App Store reject: "Guideline 5.1.1 — Privacy practices"

**Solusi:** Lengkapi App Privacy form. Jelaskan SEMUA data yang
dikumpulkan + tujuannya.

---

## I. Masalah Performance

### I1. App lemot saat scroll list panjang

**Solusi:** Ganti `ScrollView + .map()` ke `FlatList`:
```typescript
<FlatList
  data={items}
  renderItem={renderItem}
  keyExtractor={(item) => item.id}
  initialNumToRender={20}
  maxToRenderPerBatch={10}
  windowSize={10}
/>
```

`renderItem` di-`useCallback` supaya tidak re-create tiap render.

### I2. Bundle size terlalu besar (>30 MB release)

**Solusi:**
- Enable Hermes (default RN 0.76)
- Enable ProGuard `minifyEnabled true`
- APK split per ABI
- Audit dep: `npx react-native-bundle-visualizer`
- Buang library yang tidak dipakai

Detail di
[Tutorial 03 section 10](TUTORIAL_MOBILE_03_BUILD_APK.md#10-optimasi-ukuran-apk-lebih-lanjut).

### I3. Battery drain tinggi

**Cek:**
- Tidak ada background location (HRIS sengaja foreground only)
- Tidak ada polling interval pendek (mis. setInterval 1 detik)
- Tidak ada loop infinite di useEffect

Audit pakai Battery Historian (Android) atau Xcode Energy gauge (iOS).

---

## J. Masalah Backend Integration

### J1. `Idempotency-Key` replay tidak balik response sama

**Cek backend log:**
```bash
grep "idempotency" backend/writable/logs/log-*.log
```

Kemungkinan:
- TTL key expired (default 24 jam) → backend re-execute
- Key hashing mismatch antara client (SHA-256) vs backend
- Method/path beda → backend reject 422 `IDEMPOTENCY_KEY_MISMATCH`

### J2. `/openapi.json` 404

**Solusi:** Pastikan route ada di
`backend/app/Config/Routes.php`:
```php
$routes->get('openapi.json', 'OpenApiController::spec');
```

DI LUAR group `['filter' => 'jwt']` (route public).

### J3. Multi-tenant: super admin tidak akses company tertentu

**Solusi:** Set header `X-Company-Id`, atau panggil
`switchCompany(companyId)` di auth store. Mobile simpan ke
`activeCompanyId` di `client.ts` lewat `setActiveCompanyId()`.

---

## K. Masalah Misc

### K1. Versi mismatch — RN 0.76 vs 0.77 di team

**Solusi:** Selalu pakai `npm ci` (bukan `npm install`) supaya match
`package-lock.json`. Kalau perlu upgrade RN, tim harus sync (jangan
sebagian saja).

### K2. Git merge conflict di `package-lock.json`

**Solusi:**
```bat
:: Hapus lockfile + reinstall
del package-lock.json
rd /s /q node_modules
npm install
git add package-lock.json
```

### K3. Hot reload bikin app stuck

**Solusi:**
- Disable Fast Refresh sementara: Dev Menu → uncheck
- Manual reload: shake / Ctrl+M → Reload
- Restart Metro: `npm start -- --reset-cache`

### K4. `EADDRINUSE :::8081` di Mac setelah reboot

**Solusi:**
```bash
lsof -i :8081
# kill PID
kill -9 <PID>
```

### K5. Emulator slow di laptop spek rendah

**Solusi:**
- Pilih image x86_64 (bukan arm64) — lebih cepat di Intel/AMD
- Allocate RAM emulator ≥ 4 GB
- Enable HAXM/Hyper-V (Windows)
- Pakai Genymotion (alternative emulator, lebih ringan)
- Atau pakai HP fisik (paling cepat)

---

## Cara request bantuan tambahan

Kalau error tidak ada di sini:

1. **Cek `mobile/TROUBLESHOOTING.md`** — catatan tim lama
2. **Search GitHub Issues**: cari error message di
   <https://github.com/facebook/react-native/issues>
3. **StackOverflow**: `[react-native]` tag + error message verbatim
4. **Slack `#hris-mobile`** — share log + steps reproduce
5. **Buat GitHub issue di repo HRIS** dengan template:
   ```markdown
   ## Steps to reproduce
   1. ...
   2. ...

   ## Expected vs actual
   ...

   ## Environment
   - OS: Windows 10
   - Node: v18.x.x
   - JDK: 17.x.x
   - RN: 0.76.3

   ## Logs
   <paste log Metro / adb logcat>
   ```

PR untuk update tutorial ini sangat dihargai — kalau menemukan
solusi baru, tambahkan section di sini.

---

## Lanjut

- Index semua tutorial: [TUTORIAL_MOBILE_INDEX.md](TUTORIAL_MOBILE_INDEX.md)