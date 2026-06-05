# Build APK / AAB — HRIS Mobile

> Panduan step-by-step untuk export APK dari workspace `mobile/`.
> Cover: Windows, Linux/macOS, debug + release + Play Store bundle.
> **Update:** 28 Mei 2026.

---

## TL;DR

Kalau prerequisite sudah lengkap dan folder `mobile/android/` sudah
ter-generate:

```bat
cd mobile
scripts\build-android.cmd debug      :: APK debug, signed test, ~70 MB
scripts\build-android.cmd release    :: APK release production, perlu keystore
scripts\build-android.cmd bundle     :: AAB untuk Google Play Store
```

Output ada di `android/app/build/outputs/apk/{debug|release}/*.apk`
atau `android/app/build/outputs/bundle/release/*.aab`.

Belum punya prerequisite? Lanjut baca section 1.

---

## 1. Prerequisite

### 1.1 JDK 17

React Native 0.76 butuh JDK 17 (bukan 11 atau 21).

**Windows (chocolatey):**
```powershell
choco install temurin17
```

**Manual:** download dari https://adoptium.net/ → install → set
`JAVA_HOME` ke folder install (mis.
`C:\Program Files\Eclipse Adoptium\jdk-17.0.x-hotspot`).

Verifikasi:
```bat
java -version
:: harus print: openjdk version "17.0.x"
```

### 1.2 Android SDK + cmdline-tools

Pilih salah satu:

**Opsi A — Android Studio (recommended kalau pertama kali):**
1. Download dari https://developer.android.com/studio
2. Install lengkap dengan Android SDK 34 + Build-Tools 34.0.0
3. Buka Android Studio → SDK Manager → Tools tab → centang:
   - Android SDK Platform-Tools
   - Android SDK Build-Tools 34.0.0
   - Android SDK Command-line Tools (latest)

**Opsi B — Cmdline-tools standalone (no IDE):**
1. Download "Command line tools only" dari
   https://developer.android.com/studio
2. Extract ke `C:\Android\Sdk\cmdline-tools\latest\`
3. Run:
   ```bat
   cd C:\Android\Sdk\cmdline-tools\latest\bin
   sdkmanager.bat "platform-tools" "platforms;android-34" "build-tools;34.0.0"
   ```

### 1.3 Environment variables

Set permanent di Windows (System Properties → Environment Variables):

```
JAVA_HOME         = C:\Program Files\Eclipse Adoptium\jdk-17.0.x-hotspot
ANDROID_HOME      = C:\Android\Sdk        (atau C:\Users\<you>\AppData\Local\Android\Sdk kalau Studio)
ANDROID_SDK_ROOT  = %ANDROID_HOME%
PATH              = %PATH%;%JAVA_HOME%\bin;%ANDROID_HOME%\platform-tools;%ANDROID_HOME%\cmdline-tools\latest\bin
```

Restart terminal, verifikasi:
```bat
echo %JAVA_HOME%
echo %ANDROID_HOME%
adb --version
```

### 1.4 Node ≥ 18

```bat
node -v
:: harus ≥ v18.x
```

Kalau belum: install dari https://nodejs.org/ (LTS) atau pakai
nvm-windows.

---

## 2. Generate folder native (sekali saja)

Folder `mobile/android/` dan `mobile/ios/` belum ter-commit di repo.
Mereka di-generate dari template React Native default lalu di-merge
custom config.

### Windows (PowerShell di WSL2 atau Git Bash)

```bash
cd mobile
bash scripts/init-native.sh
```

### Windows native (tanpa WSL)

Script Bash di atas tidak jalan di cmd murni. Lakukan manual:

```bat
cd mobile
:: 1. Generate scaffold ke folder sementara
npx --yes @react-native-community/cli@latest init HrisMobileTmp ^
  --version 0.76.3 ^
  --skip-install ^
  --skip-git-init ^
  --directory _tmp\scaffold

:: 2. Copy folder native ke workspace
xcopy /E /I /Y _tmp\scaffold\android android
xcopy /E /I /Y _tmp\scaffold\ios     ios

:: 3. Cleanup
rmdir /S /Q _tmp

:: 4. Install JS deps
npm ci
```

### Merge native config tambahan

File template `mobile/native-config/` berisi penambahan untuk
permission camera, GPS, push, dst. Setelah `android/` ter-generate,
merge:

1. Buka `mobile/android/app/src/main/AndroidManifest.xml`
2. Tambahkan `<uses-permission>` dari
   `mobile/native-config/android-manifest-additions.xml`
3. Pastikan `<application>` punya `android:usesCleartextTraffic="false"`
   (kecuali memang butuh untuk dev backend HTTP localhost — lihat
   note di `mobile/native-config/`).

iOS sama: merge `mobile/native-config/info-plist-additions.plist` ke
`mobile/ios/HrisMobile/Info.plist`.

---

## 3. Konfigurasi `.env`

```bat
cd mobile
copy .env.example .env
notepad .env
```

Isi minimal:

```
API_BASE_URL=https://api.staging.your-company.com/api/v1
APP_VERSION=0.1.0
APP_BUILD=1
```

Untuk production build, ganti `API_BASE_URL` ke URL produksi. Build
script (`react-native-config`) akan inject ini ke binary.

---

## 4. Build APK Debug

Paling cepat — pakai keystore Android default (`debug.keystore` sudah
ada di setiap RN scaffold). APK ini **bisa di-install ke device tapi
tidak bisa upload ke Play Store**.

```bat
cd mobile
scripts\build-android.cmd
```

Atau manual:

```bat
cd mobile
npm ci
cd android
gradlew.bat assembleDebug --no-daemon
```

Output:
```
mobile\android\app\build\outputs\apk\debug\app-debug.apk
```

Install ke device (USB-debugging mode aktif):
```bat
adb install -r android\app\build\outputs\apk\debug\app-debug.apk
```

Untuk distribusi internal via grup WhatsApp / Telegram: kirim file
`app-debug.apk` (~70 MB). Penerima harus enable
"Install dari sumber tidak dikenal" di Settings Android.

---

## 5. Build APK Release (production)

Step ini butuh keystore production. **Keystore wajib dijaga
keamanannya** — kalau hilang, app baru tidak bisa update aplikasi
existing yang sudah di-distribute.

### 5.1 Generate keystore (sekali per project)

```bat
cd mobile\android\app
keytool -genkey -v -keystore release.keystore -alias hris-mobile -keyalg RSA -keysize 2048 -validity 10000
```

Isi prompt:
- Password: **simpan di password manager**
- Nama, organisasi, kota, dst. (informational)

Output: `mobile/android/app/release.keystore`

⚠ **Tambahkan ke .gitignore** (sudah ada di `mobile/.gitignore` —
verifikasi). Backup keystore terpisah, jangan ikut commit!

### 5.2 Konfigurasi gradle untuk pakai keystore

Edit `mobile/android/gradle.properties`:

```properties
HRIS_RELEASE_STORE_FILE=release.keystore
HRIS_RELEASE_KEY_ALIAS=hris-mobile
HRIS_RELEASE_STORE_PASSWORD=password-anda
HRIS_RELEASE_KEY_PASSWORD=password-anda
```

Edit `mobile/android/app/build.gradle`, di dalam `android { ... }`:

```gradle
signingConfigs {
    release {
        if (project.hasProperty('HRIS_RELEASE_STORE_FILE')) {
            storeFile file(HRIS_RELEASE_STORE_FILE)
            storePassword HRIS_RELEASE_STORE_PASSWORD
            keyAlias HRIS_RELEASE_KEY_ALIAS
            keyPassword HRIS_RELEASE_KEY_PASSWORD
        }
    }
}
buildTypes {
    release {
        signingConfig signingConfigs.release
        minifyEnabled true
        shrinkResources true
        proguardFiles getDefaultProguardFile("proguard-android.txt"), "proguard-rules.pro"
    }
}
```

⚠ **Jangan commit `gradle.properties` dengan password!** Atau pakai
variabel environment dengan `signingConfig` dynamic — lihat React
Native docs section "Signing your Android app".

### 5.3 Build

```bat
cd mobile
scripts\build-android.cmd release
```

Output:
```
mobile\android\app\build\outputs\apk\release\app-release.apk
```

Ukuran release biasanya 25–40 MB (dibanding debug 70+ MB) karena
ProGuard + Hermes JS engine.

Install/test sebelum distribute:
```bat
adb install -r android\app\build\outputs\apk\release\app-release.apk
```

---

## 6. Build AAB untuk Google Play Store

Play Store tidak terima APK lagi (sejak Aug 2021); harus AAB
(Android App Bundle). Format ini lebih efisien karena Play Store
generate APK split per device.

```bat
cd mobile
scripts\build-android.cmd bundle
```

Output:
```
mobile\android\app\build\outputs\bundle\release\app-release.aab
```

Upload `.aab` ini ke Google Play Console → Internal testing /
Closed testing / Production.

---

## 7. CI/CD: build via GitHub Actions

Workflow `.github/workflows/mobile-ci.yml` saat ini hanya run
TypeScript check + jest. Untuk build APK otomatis di tag rilis:

```yaml
# Tambah job baru di .github/workflows/mobile-ci.yml
android-build:
  if: startsWith(github.ref, 'refs/tags/mobile-v')
  runs-on: ubuntu-latest
  needs: [verify]
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-java@v4
      with:
        distribution: temurin
        java-version: 17
    - uses: actions/setup-node@v4
      with:
        node-version: 18
        cache: npm
        cache-dependency-path: mobile/package-lock.json
    - run: cd mobile && npm ci
    - name: Decode keystore
      run: echo "${{ secrets.ANDROID_KEYSTORE_BASE64 }}" | base64 -d > mobile/android/app/release.keystore
    - name: Build AAB
      env:
        HRIS_RELEASE_STORE_FILE: release.keystore
        HRIS_RELEASE_KEY_ALIAS: ${{ secrets.ANDROID_KEY_ALIAS }}
        HRIS_RELEASE_STORE_PASSWORD: ${{ secrets.ANDROID_STORE_PASSWORD }}
        HRIS_RELEASE_KEY_PASSWORD: ${{ secrets.ANDROID_KEY_PASSWORD }}
      run: cd mobile/android && ./gradlew bundleRelease --no-daemon
    - uses: actions/upload-artifact@v4
      with:
        name: hris-mobile-aab
        path: mobile/android/app/build/outputs/bundle/release/*.aab
```

GitHub secrets yang harus di-set di repo settings:
- `ANDROID_KEYSTORE_BASE64` — `cat release.keystore | base64 | clip`
- `ANDROID_KEY_ALIAS`
- `ANDROID_STORE_PASSWORD`
- `ANDROID_KEY_PASSWORD`

Tag rilis: `git tag mobile-v0.2.0 && git push origin mobile-v0.2.0`.

---

## 8. Troubleshooting

### `error: ENOENT: no such file or directory ... index.android.bundle`

Bundle JS belum di-generate. Untuk debug-offline build:

```bat
scripts\build-android.cmd debug-offline
```

Atau manual:
```bat
cd mobile
npx react-native bundle ^
  --platform android ^
  --dev true ^
  --entry-file index.js ^
  --bundle-output android\app\src\main\assets\index.android.bundle ^
  --assets-dest android\app\src\main\res
```

### `Could not resolve all files for configuration ':app:debugRuntimeClasspath'`

Gradle gagal download dependency. Cek koneksi internet, atau setting
proxy. Force re-download:

```bat
cd mobile\android
gradlew.bat clean --refresh-dependencies
```

### `Unable to find Java. Set JAVA_HOME...`

`JAVA_HOME` belum set permanen. Restart terminal setelah setting,
atau set sementara di terminal aktif:
```bat
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-17.0.x-hotspot
```

### `SDK location not found. Define ANDROID_HOME or add local.properties`

Atau set `ANDROID_HOME`, atau buat `mobile/android/local.properties`:
```properties
sdk.dir=C\:\\Android\\Sdk
```
(Note: backslash di-escape pakai `\\` di Windows.)

### Build sukses tapi APK install error "App not installed"

Biasanya karena keystore mismatch — kalau di device sudah ada
`app-debug.apk` lama dengan signature beda, uninstall dulu:

```bat
adb uninstall com.hrismobile
adb install -r android\app\build\outputs\apk\debug\app-debug.apk
```

### Build OOM (out of memory)

Tambah heap di `mobile/android/gradle.properties`:
```properties
org.gradle.jvmargs=-Xmx4096m -XX:MaxMetaspaceSize=1024m
```

---

## 9. Build size optimization

Untuk distribusi yang lebih ringan:

1. **Hermes** sudah aktif default di RN 0.76 → JS engine ringan,
   APK 25–30% lebih kecil.
2. **APK split per ABI** — Play Store auto-handle via AAB. Untuk APK
   manual, di `mobile/android/app/build.gradle`:
   ```gradle
   splits {
     abi {
       enable true
       reset()
       include "armeabi-v7a", "arm64-v8a", "x86_64"
       universalApk false
     }
   }
   ```
   Output: 3 APK, masing-masing untuk satu arch (~15 MB tiap).
3. **ProGuard / R8** sudah aktif di build release.
4. **Asset pruning** — buang resource yang tidak dipakai dengan
   `shrinkResources true` di `buildTypes.release`.

---

## 10. iOS build (macOS only)

iOS butuh Xcode + Apple Developer account ($99/tahun). Tidak bisa
di Windows tanpa cloud Mac (mis. Codemagic, Bitrise).

Quick reference:

```bash
cd mobile/ios
pod install
cd ..
npx react-native run-ios   # debug, ke simulator

# Untuk archive .ipa:
# Buka mobile/ios/HrisMobile.xcworkspace di Xcode
# Product → Archive → Distribute App
```

Tutorial lengkap di docs Apple.

---

## 11. Distribusi internal (tanpa Play Store)

Untuk testing internal di kantor:

1. **Email blast / WhatsApp share** — kirim `app-release.apk`
   langsung. Penerima enable "Install from unknown sources"
   sekali, lalu tap APK untuk install.
2. **MDM (Mobile Device Management)** — Microsoft Intune,
   Workspace ONE, Hexnode. Cocok untuk enterprise yang punya
   policy device.
3. **Firebase App Distribution** — gratis, drag-drop APK, kirim
   notifikasi tester via email. Bagus untuk QA team.
4. **Web hosting sendiri** — upload APK ke server internal,
   user download via link. Pasang `<meta http-equiv` redirect
   ke Play Store version setelah app rilis publik.

---

**Summary:**

| Output       | Command                            | Use case                     |
|--------------|------------------------------------|------------------------------|
| APK debug    | `scripts\build-android.cmd debug`  | Internal testing, dev team   |
| APK release  | `scripts\build-android.cmd release`| Distribusi private (MDM)     |
| AAB release  | `scripts\build-android.cmd bundle` | Google Play Store            |

Dokumen ini akan di-update kalau workflow berubah. Pertanyaan?
Hubungi tim mobile via Slack #hris-mobile.