# Tutorial 03 — Build APK (Debug & Release)

Cara membuat file `.apk` HRIS Mobile yang bisa di-install manual ke HP
Android (sideload). Cocok untuk distribusi internal, testing UAT, atau
sebelum publish ke Play Store.

> Prasyarat:
> - Sudah selesai [Tutorial 01 — Setup](TUTORIAL_MOBILE_01_SETUP.md)
> - Sudah baca [Tutorial 02 — Dev Workflow](TUTORIAL_MOBILE_02_DEV_WORKFLOW.md)

---

## TL;DR

```bat
cd mobile

:: APK debug — paling cepat, ~130 MB, signed dengan debug.keystore
cd android
gradlew.bat :app:assembleDebug
:: Output: android\app\build\outputs\apk\debug\app-debug.apk

:: APK release — production, ~60 MB, butuh keystore production
cd android
gradlew.bat :app:assembleRelease
:: Output: android\app\build\outputs\apk\release\app-release.apk
```

---

## 1. Memilih jenis build

| Jenis | Kapan dipakai | Ukuran | Keystore | Performance |
|-------|---------------|--------|----------|-------------|
| **Debug APK** | Internal testing tim, demo cepat | ~130 MB | Auto (`debug.keystore`) | Lambat (overhead dev) |
| **Release APK** | Distribusi UAT, MDM, sideload publik | ~60 MB | Production (harus dibuat) | Cepat |
| **Release AAB** | Upload ke Google Play Store | ~30 MB (delivered) | Production | Cepat |

> Tutorial ini fokus ke APK. Untuk AAB → [Tutorial 04 — Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md).

---

## 2. Build APK Debug (paling cepat)

Cara tercepat. Tidak butuh keystore — auto pakai `debug.keystore` yang
sudah ada di repo (`mobile/android/app/debug.keystore`).

### 2.1 Pastikan kode sehat

```bat
cd mobile
npx tsc --noEmit
npm test
```

Kalau ada error di sini, fix dulu sebelum build.

### 2.2 Build

```bat
cd mobile\android
gradlew.bat :app:assembleDebug
```

Tunggu sampai muncul:
```
BUILD SUCCESSFUL in Xm Ys
```

Build pertama kali: ~10-17 menit (Gradle download dependency, compile
native code untuk 4 ABI). Build berikutnya: ~30 detik – 2 menit
tergantung perubahan.

### 2.3 Lokasi output

```
mobile\android\app\build\outputs\apk\debug\app-debug.apk
```

Cek ukuran:
```bat
dir mobile\android\app\build\outputs\apk\debug\app-debug.apk
:: ~130 MB
```

### 2.4 Install ke HP

Hubungkan HP via USB (USB debugging aktif):
```bat
adb install -r mobile\android\app\build\outputs\apk\debug\app-debug.apk
:: -r = replace, kalau sudah pernah di-install
```

Atau kirim file APK ke HP (WhatsApp, email, USB transfer), lalu di HP:
1. Buka file manager → tap APK
2. Allow install dari sumber tidak dikenal (biasanya muncul prompt)
3. Tap Install

---

## 3. Build APK Release (production-quality)

Release APK lebih kecil, lebih cepat, dan signed dengan keystore
production. **Wajib punya keystore production sebelum bisa build**.

### 3.1 Generate keystore production (sekali per project, JANGAN diulang)

```bat
cd mobile\android\app
keytool -genkey -v ^
  -keystore release.keystore ^
  -alias hris-mobile ^
  -keyalg RSA ^
  -keysize 2048 ^
  -validity 10000
```

Yang ditanya:
1. **Password keystore** (catat di password manager, mis. 1Password,
   Bitwarden) — minimal 6 karakter
2. **Password key** — bisa sama dengan keystore
3. Nama, organisasi, kota, provinsi, negara — informational, isi sesuai
   info perusahaan

Output: `mobile/android/app/release.keystore`

> ⚠ **PERINGATAN BESAR:**
> - Keystore ini **TIDAK BOLEH** di-commit ke Git. Sudah ada di
>   `.gitignore`, verifikasi dengan `git status`.
> - **Backup** ke 2 tempat: password manager + cloud terenkripsi
>   (1Password vault, Google Drive folder enkripsi, dll).
> - Kalau hilang, app yang sudah ter-publish **tidak bisa di-update lagi**.
>   Harus publish app baru dengan package name beda → user lama tidak
>   migrate otomatis.

### 3.2 Verifikasi keystore

```bat
keytool -list -v -keystore release.keystore -alias hris-mobile
:: masukkan password
```

Harus muncul info: alias, validity (~27 tahun), SHA1/SHA256 fingerprint.

### 3.3 Set password di gradle.properties

Edit `mobile/android/gradle.properties`, tambahkan di bawah:

```properties
HRIS_RELEASE_STORE_FILE=release.keystore
HRIS_RELEASE_KEY_ALIAS=hris-mobile
HRIS_RELEASE_STORE_PASSWORD=<password-yang-tadi>
HRIS_RELEASE_KEY_PASSWORD=<password-yang-tadi>
```

> File `gradle.properties` BIASANYA di-commit untuk konfigurasi
> build. Tapi karena ada password di sini, **jangan commit setelah edit
> ini**. Tambahkan ke `.gitignore` lokal:
> ```
> :: di mobile/android/.gitignore
> gradle.properties
> ```
> Atau lebih aman: pakai env variable (lihat section 3.6).

### 3.4 Update `app/build.gradle` untuk pakai keystore release

Buka `mobile/android/app/build.gradle`. Cari blok `signingConfigs`:

```gradle
signingConfigs {
    debug {
        storeFile file('debug.keystore')
        storePassword 'android'
        keyAlias 'androiddebugkey'
        keyPassword 'android'
    }
}
```

Ubah jadi:

```gradle
signingConfigs {
    debug {
        storeFile file('debug.keystore')
        storePassword 'android'
        keyAlias 'androiddebugkey'
        keyPassword 'android'
    }
    release {
        if (project.hasProperty('HRIS_RELEASE_STORE_FILE')) {
            storeFile file(HRIS_RELEASE_STORE_FILE)
            storePassword HRIS_RELEASE_STORE_PASSWORD
            keyAlias HRIS_RELEASE_KEY_ALIAS
            keyPassword HRIS_RELEASE_KEY_PASSWORD
        }
    }
}
```

Lalu cari blok `buildTypes { release { ... } }`:

```gradle
buildTypes {
    debug {
        signingConfig signingConfigs.debug
    }
    release {
        // Caution! In production, you need to generate your own keystore file.
        signingConfig signingConfigs.debug    // ← ini yang harus diubah
        minifyEnabled enableProguardInReleaseBuilds
        proguardFiles getDefaultProguardFile("proguard-android.txt"), "proguard-rules.pro"
    }
}
```

Ubah jadi:

```gradle
buildTypes {
    debug {
        signingConfig signingConfigs.debug
    }
    release {
        signingConfig signingConfigs.release
        minifyEnabled true
        shrinkResources true
        proguardFiles getDefaultProguardFile("proguard-android.txt"), "proguard-rules.pro"
    }
}
```

`minifyEnabled true` aktifkan ProGuard/R8 → APK lebih kecil + obfuscate
kode Java/Kotlin. `shrinkResources true` buang resource yang tidak
dipakai.

### 3.5 Build release APK

```bat
cd mobile\android
gradlew.bat clean
gradlew.bat :app:assembleRelease
```

Output:
```
mobile\android\app\build\outputs\apk\release\app-release.apk
```

Ukuran ~50-70 MB. Hermes engine + ProGuard sudah optimasi.

### 3.6 Alternative aman — keystore + password via env variable

Kalau tidak mau taruh password di `gradle.properties` (lebih aman buat
team / CI):

```bat
:: Set sementara di terminal
set HRIS_RELEASE_STORE_FILE=release.keystore
set HRIS_RELEASE_KEY_ALIAS=hris-mobile
set HRIS_RELEASE_STORE_PASSWORD=mypassword
set HRIS_RELEASE_KEY_PASSWORD=mypassword

cd android
gradlew.bat :app:assembleRelease ^
  -PHRIS_RELEASE_STORE_FILE=%HRIS_RELEASE_STORE_FILE% ^
  -PHRIS_RELEASE_KEY_ALIAS=%HRIS_RELEASE_KEY_ALIAS% ^
  -PHRIS_RELEASE_STORE_PASSWORD=%HRIS_RELEASE_STORE_PASSWORD% ^
  -PHRIS_RELEASE_KEY_PASSWORD=%HRIS_RELEASE_KEY_PASSWORD%
```

Atau pakai PowerShell `$env:` syntax.

---

## 4. Verify APK release sebelum distribusi

### 4.1 Cek signature

```bat
keytool -printcert -jarfile mobile\android\app\build\outputs\apk\release\app-release.apk
```

Harus muncul info issuer/validity sesuai keystore production. Kalau
muncul "Android Debug" → masih pakai debug keystore, build belum bener.

### 4.2 Test install di device bersih

```bat
:: Uninstall versi lama dulu (penting kalau sebelumnya install debug)
adb uninstall com.hrismobile

:: Install release APK
adb install mobile\android\app\build\outputs\apk\release\app-release.apk
```

Di HP, buka app:
- Login dengan user test → harus bisa
- Test fitur core (clock-in, leave request, payslip view)
- Cek tidak ada warning developer (red box, yellow box) → di release
  mode harusnya bersih

### 4.3 Check size & content

```bat
:: Ukuran
dir mobile\android\app\build\outputs\apk\release\app-release.apk

:: Kalau install aapt:
aapt dump badging mobile\android\app\build\outputs\apk\release\app-release.apk
:: muncul: package name, version, permissions, supported screens
```

---

## 5. APK split per ABI (untuk APK lebih kecil)

Defaultnya APK include 4 arsitektur (`armeabi-v7a`, `arm64-v8a`, `x86`,
`x86_64`). Bisa split jadi 4 APK kecil per arch (~15-25 MB tiap).

Edit `mobile/android/app/build.gradle`, tambahkan di dalam `android { }`:

```gradle
splits {
    abi {
        enable true
        reset()
        include "armeabi-v7a", "arm64-v8a"   // skip x86 untuk APK production
        universalApk false
    }
}
```

Build:
```bat
cd android
gradlew.bat :app:assembleRelease
```

Output:
```
app-arm64-v8a-release.apk     :: untuk HP modern (90% device sekarang)
app-armeabi-v7a-release.apk   :: untuk HP lama 32-bit
```

User download APK sesuai arch HP-nya. Cara cek arch HP:
- Settings → About → Hardware → biasanya tertera atau cek lewat app
  "CPU-Z"
- 99% HP modern (>=2018) pakai `arm64-v8a`

> **Tip:** kalau distribusi via Play Store, tidak perlu split manual
> — cukup upload AAB, Play Store auto-handle per device. Split APK
> berguna kalau distribusi sideload di mana ukuran kritis (mis. via
> WhatsApp, max 100MB).

---

## 6. APK universal (single file untuk semua HP)

Default tanpa `splits` setting. Build menghasilkan satu APK universal
yang berjalan di semua arch. Ukuran lebih besar (~130 MB debug, ~60 MB
release) tapi paling simple untuk distribusi.

Untuk distribusi UAT internal, **APK universal lebih praktis**. Tester
tidak perlu tahu arch HP-nya.

---

## 7. Distribusi APK ke tester / user akhir

Pilihan:

### 7.1 Manual (paling simple, < 50 user)
- Kirim file APK via WhatsApp / Telegram / email
- Penerima:
  1. Settings → Security → Install from unknown sources → enable
  2. Tap APK → Install → Open
- Update: kirim APK baru, mereka install ulang

### 7.2 Firebase App Distribution (gratis, recommended)
1. Buat project di <https://console.firebase.google.com>
2. App Distribution → Add Android app → masukkan `com.hrismobile`
3. Upload APK manual via web, atau via CLI:
   ```bash
   npm install -g firebase-tools
   firebase login
   firebase appdistribution:distribute app-release.apk \
     --app 1:1234567890:android:abcdef \
     --release-notes "Bug fix login + payslip" \
     --testers "tester1@company.com,tester2@company.com"
   ```
4. Tester dapat email + link install via web App Distribution

Bonus: integrasi dengan crashlytics, beta tester management,
versioning otomatis.

### 7.3 MDM (Mobile Device Management) — enterprise
- Microsoft Intune, VMware Workspace ONE, Hexnode
- Push APK ke device karyawan otomatis
- Bisa enforce policy (passcode, encryption, remote wipe)
- Cocok untuk perusahaan ≥100 karyawan

### 7.4 Web hosting sendiri
- Upload APK ke server internal (mis. `https://intranet.company.com/hris-app.apk`)
- User scan QR code untuk download
- Update: replace file di server, user manual download lagi

### 7.5 Internal track Play Store (kalau punya akun developer)
- Upload AAB (bukan APK) ke Internal Testing track Play Store
- Invite tester via email — mereka bisa update otomatis
- Detail di [Tutorial 04 — Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md)

---

## 8. Versioning APK

Sebelum build release baru, naikkan versi di
`mobile/android/app/build.gradle`:

```gradle
defaultConfig {
    applicationId "com.hrismobile"
    minSdkVersion rootProject.ext.minSdkVersion
    targetSdkVersion rootProject.ext.targetSdkVersion
    versionCode 1            // ← naikkan +1 tiap rilis
    versionName "1.0"        // ← format semantic version
}
```

Aturan:
- **versionCode** integer, **harus naik**. Play Store reject kalau
  versionCode ≤ versi sebelumnya.
- **versionName** untuk user (tampil di Settings → About). Format
  bebas tapi recommended `MAJOR.MINOR.PATCH` (1.2.3).

Contoh perubahan:
| Versi sebelumnya | Versi baru | versionCode | versionName |
|------------------|-----------|-------------|-------------|
| MVP launch | Bug fix login | 1 → 2 | 1.0.0 → 1.0.1 |
| Versi MVP | Tambah fitur reimburse | 1 → 10 | 1.0.0 → 1.1.0 |
| Versi MVP | Major redesign | 1 → 100 | 1.0.0 → 2.0.0 |

Tip: pakai script bump versi otomatis kalau workflow rilis sering.

---

## 9. CI build di GitHub Actions (otomatis tiap tag)

Workflow di `.github/workflows/mobile-build.yml`:

```yaml
name: Mobile Build APK

on:
  push:
    tags:
      - 'mobile-v*'

jobs:
  build-android:
    runs-on: ubuntu-latest
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

      - name: Install JS deps
        run: cd mobile && npm ci

      - name: Decode keystore
        run: |
          echo "${{ secrets.ANDROID_KEYSTORE_BASE64 }}" | base64 -d > mobile/android/app/release.keystore

      - name: Build release APK
        env:
          HRIS_RELEASE_STORE_FILE: release.keystore
          HRIS_RELEASE_KEY_ALIAS: ${{ secrets.ANDROID_KEY_ALIAS }}
          HRIS_RELEASE_STORE_PASSWORD: ${{ secrets.ANDROID_STORE_PASSWORD }}
          HRIS_RELEASE_KEY_PASSWORD: ${{ secrets.ANDROID_KEY_PASSWORD }}
        run: |
          cd mobile/android
          ./gradlew :app:assembleRelease --no-daemon

      - uses: actions/upload-artifact@v4
        with:
          name: hris-mobile-apk
          path: mobile/android/app/build/outputs/apk/release/*.apk
          retention-days: 30
```

Setup secrets di GitHub repo settings → Secrets and variables → Actions:

```bat
:: Encode keystore ke base64
certutil -encode mobile\android\app\release.keystore keystore.b64
:: copy isi keystore.b64 ke secret ANDROID_KEYSTORE_BASE64
```

Trigger:
```bat
git tag mobile-v1.0.1
git push origin mobile-v1.0.1
```

Otomatis build, artifact APK bisa di-download dari halaman Actions.

---

## 10. Optimasi ukuran APK lebih lanjut

Kalau ukuran masih terlalu besar, coba:

1. **Hermes** sudah aktif default RN 0.76 (cek `enableHermes: true`).
2. **ProGuard `minifyEnabled true`** sudah aktif di section 3.4.
3. **R8 full mode** — tambah di `gradle.properties`:
   ```properties
   android.enableR8.fullMode=true
   ```
4. **APK split per ABI** (section 5) — bagi jadi 2-3 APK kecil.
5. **Audit asset besar:**
   ```bat
   cd mobile
   npx react-native-bundle-visualizer
   ```
   Buka HTML hasilnya, lihat dependency apa yang paling gendut. Ganti
   atau buang kalau tidak penting.
6. **Drop fonts/icons yang tidak dipakai.** Default
   `react-native-vector-icons` include semua font (~3 MB). Selektif
   pakai cherry-pick:
   ```js
   // di app/build.gradle
   project.ext.vectoricons = [
       iconFontNames: ['MaterialCommunityIcons.ttf', 'FontAwesome.ttf']
   ]
   apply from: "../../node_modules/react-native-vector-icons/fonts.gradle"
   ```

---

## 11. Cek list — sebelum distribusi

- [ ] `npx tsc --noEmit` lulus, 0 error
- [ ] `npm test` lulus, semua suite pass
- [ ] Versi `versionCode` & `versionName` di-bump
- [ ] `.env` set ke URL production (bukan localhost atau staging)
- [ ] `gradle.properties` tidak ke-commit kalau berisi password
- [ ] `release.keystore` ada, sudah di-backup
- [ ] APK release ter-build, ukuran < 100 MB (untuk WhatsApp)
- [ ] Test install di minimal 2 device (HP teman + emulator)
- [ ] Test 5 menit fitur utama: login, attendance, leave, payslip,
      logout
- [ ] Tidak ada console warning red/yellow di release mode
- [ ] CHANGELOG.md di-update dengan list fitur/bugfix
- [ ] Git tag dibuat (`git tag mobile-v1.0.x`)

---

## 12. Lanjut

- Mau publish ke Google Play Store →
  [Tutorial 04 — Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md)
- Mau build versi iOS →
  [Tutorial 05 — Build iOS](TUTORIAL_MOBILE_05_BUILD_IOS.md)
- Build error / install gagal →
  [Tutorial 06 — Troubleshooting](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md)