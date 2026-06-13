# Tutorial 01 — Setup Environment Mobile

Panduan langkah-demi-langkah untuk menyiapkan PC/laptop sehingga bisa
build dan run aplikasi mobile HRIS. Tutorial ini ditulis untuk Windows;
catatan macOS/Linux ada di akhir setiap bagian.

> **Estimasi waktu:** 30-60 menit (tergantung kecepatan download).
> Sekali setup, tidak perlu diulang.

---

## 1. Yang akan kita install

| Tool | Versi | Untuk apa |
|------|-------|-----------|
| Node.js | ≥ 18 LTS | Run JavaScript, Metro bundler, npm |
| JDK | 17 (Temurin) | Compile kode Android (Kotlin/Java) |
| Android SDK | 34 (Build-Tools 34.0.0) | Toolchain Android |
| Android NDK | 26.1.10909125 | Native module C++ (MMKV, Hermes) |
| Git | latest | Version control |
| (opsional) Android Studio | Hedgehog 2023.1+ | IDE + emulator + GUI SDK manager |

> Versi sudah disesuaikan dengan React Native 0.76.3. Jangan pakai JDK 11
> atau JDK 21 — RN 0.76 strict di JDK 17.

---

## 2. Install Node.js

### Windows
1. Download installer LTS dari <https://nodejs.org/>
2. Jalankan installer, centang "Automatically install necessary tools"
3. Verifikasi:
   ```bat
   node -v
   npm -v
   ```
   Harus muncul `v18.x.x` atau lebih.

### macOS
```bash
brew install node
```

### Linux (Ubuntu/Debian)
```bash
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs
```

> **Tip:** Kalau punya banyak project Node, pakai `nvm-windows` (Windows)
> atau `nvm` (mac/Linux) supaya bisa switch versi Node per project.

---

## 3. Install JDK 17

### Windows
1. Download Temurin JDK 17 dari <https://adoptium.net/>
2. Pilih: `Windows x64`, MSI Installer
3. Jalankan installer. Saat instalasi, **centang opsi**:
   - "Set JAVA_HOME variable"
   - "Add to PATH"
4. Restart terminal. Verifikasi:
   ```bat
   java -version
   :: harus muncul: openjdk version "17.x.x"

   echo %JAVA_HOME%
   :: harus muncul: C:\Program Files\Eclipse Adoptium\jdk-17.x.x-hotspot
   ```

Kalau `JAVA_HOME` belum kebaca, set manual:
- Tekan Win → cari "Environment Variables" → "Edit the system environment variables"
- Klik "Environment Variables..." → di bagian System variables, klik "New..."
- Variable name: `JAVA_HOME`
- Variable value: `C:\Program Files\Eclipse Adoptium\jdk-17.0.x-hotspot`
- OK semua, restart terminal.

### macOS
```bash
brew install --cask temurin@17
```
Tambahkan ke `~/.zshrc`:
```bash
export JAVA_HOME=$(/usr/libexec/java_home -v 17)
```

### Linux
```bash
sudo apt install temurin-17-jdk
```
Set `JAVA_HOME` di `~/.bashrc`:
```bash
export JAVA_HOME=/usr/lib/jvm/temurin-17-jdk-amd64
```

---

## 4. Install Android SDK

Ada dua opsi: dengan Android Studio (recommended kalau pertama kali)
atau cmdline-tools saja (lebih ringan).

### Opsi A — Android Studio (lebih mudah)

1. Download dari <https://developer.android.com/studio>
2. Install dengan setting default. Saat first-launch wizard, pilih
   "Standard" install. Studio akan auto-download:
   - Android SDK Platform 34
   - Build-Tools 34.0.0
   - Platform-Tools (termasuk `adb`)
   - Android Emulator
3. Setelah selesai, buka Studio → More Actions → SDK Manager
4. Tab "SDK Platforms": centang **Android 14 (API 34)**, klik Apply
5. Tab "SDK Tools": centang
   - Android SDK Build-Tools 34.0.0
   - Android SDK Command-line Tools (latest)
   - **NDK (Side by side) → 26.1.10909125** (PENTING untuk MMKV)
   - CMake 3.22.1+
   Klik Apply, tunggu download.

Lokasi default SDK:
- Windows: `C:\Users\<USER>\AppData\Local\Android\Sdk`
- macOS: `~/Library/Android/sdk`
- Linux: `~/Android/Sdk`

### Opsi B — Cmdline-tools standalone (no IDE, ringan)

Khusus kalau cuma butuh build dari terminal/CI tanpa IDE.

1. Download "Command line tools only" dari
   <https://developer.android.com/studio>
2. Buat folder `C:\Android\Sdk\cmdline-tools\latest\`
3. Extract ZIP-nya ke folder tersebut. Struktur akhir:
   ```
   C:\Android\Sdk\cmdline-tools\latest\bin\sdkmanager.bat
   C:\Android\Sdk\cmdline-tools\latest\lib\...
   ```
4. Buka cmd, jalankan:
   ```bat
   cd C:\Android\Sdk\cmdline-tools\latest\bin
   sdkmanager.bat --licenses
   :: Tekan y untuk semua license

   sdkmanager.bat "platform-tools" "platforms;android-34" "build-tools;34.0.0" "ndk;26.1.10909125" "cmake;3.22.1"
   ```

---

## 5. Set environment variables Android

### Windows
Win → "Environment Variables" → New System Variables:

```
ANDROID_HOME      = C:\Users\<USER>\AppData\Local\Android\Sdk
                    (atau C:\Android\Sdk kalau Opsi B)
ANDROID_SDK_ROOT  = %ANDROID_HOME%
```

Edit variable `Path`, tambahkan:
```
%ANDROID_HOME%\platform-tools
%ANDROID_HOME%\cmdline-tools\latest\bin
%ANDROID_HOME%\emulator
```

Restart terminal, verifikasi:
```bat
adb --version
:: harus muncul: Android Debug Bridge version 1.0.x

echo %ANDROID_HOME%
:: harus muncul path SDK
```

### macOS / Linux
Tambah ke `~/.zshrc` atau `~/.bashrc`:
```bash
export ANDROID_HOME=$HOME/Library/Android/sdk     # macOS
# export ANDROID_HOME=$HOME/Android/Sdk           # Linux
export ANDROID_SDK_ROOT=$ANDROID_HOME
export PATH=$PATH:$ANDROID_HOME/platform-tools
export PATH=$PATH:$ANDROID_HOME/cmdline-tools/latest/bin
export PATH=$PATH:$ANDROID_HOME/emulator
```

Reload shell: `source ~/.zshrc` atau buka terminal baru.

---

## 6. Install Git

### Windows
Download dari <https://git-scm.com/download/win>. Install dengan default.
Pilih "Git Bash" supaya bisa run script `*.sh`.

### macOS
```bash
brew install git
```

### Linux
```bash
sudo apt install git
```

Verifikasi: `git --version`.

---

## 7. Clone repo & install dependency mobile

```bat
cd D:\Apps Project
git clone https://github.com/antowidianto/Quantara-HRIS.git hris-project-1
cd hris-project-1\mobile
npm ci
```

> `npm ci` lebih cepat dan lebih reproducible daripada `npm install`
> kalau `package-lock.json` sudah ada (dan harusnya selalu ada di repo).

Output yang diharapkan: ratusan paket terinstall, **tidak ada error**.
Boleh ada warning peer-dep (biasa di RN ecosystem).

---

## 8. Setup file `.env`

```bat
cd mobile
copy .env.example .env
notepad .env
```

Isi minimal:

```env
# URL backend API. Sesuaikan ke environment yang dipakai:
# - Lokal (PC dev + Android emulator): http://10.0.2.2:8080/api/v1
# - Lokal (PC dev + HP fisik di WiFi yg sama): http://<IP-LAN-PC>:8080/api/v1
# - Staging: https://api.staging.your-company.com/api/v1
# - Production: https://api.your-company.com/api/v1
API_BASE_URL=http://10.0.2.2:8080/api/v1

# Versi yang akan tampil di Settings → About
APP_VERSION=0.1.0
APP_BUILD=1

# (Opsional) Sentry/observability
# SENTRY_DSN=
```

> **Cara cari IP LAN PC (Windows):** `ipconfig` → cari "IPv4 Address"
> di adapter WiFi (mis. `192.168.1.5`). HP harus di WiFi yang sama.

---

## 9. Buat `local.properties` (path SDK untuk Gradle)

File ini bilang ke Gradle di mana Android SDK terinstall.

```bat
cd mobile\android
notepad local.properties
```

Isi:
```properties
sdk.dir=C\:\\Users\\<USER>\\AppData\\Local\\Android\\Sdk
# atau:
# sdk.dir=C\:\\Android\\Sdk
```

> **PENTING:** Backslash di-escape jadi `\\` di file properties. Ini
> bukan typo.

> File ini di-gitignore, jadi setiap developer bikin sendiri.

---

## 10. Verifikasi semua sudah benar

Dari `mobile/`:

```bat
:: 1. Cek Node + JDK + Android tooling
node -v
java -version
adb --version

:: 2. Cek dependency JS terinstall
dir node_modules\react-native\package.json

:: 3. Cek TypeScript tidak ada error
npx tsc --noEmit
:: harus exit tanpa output

:: 4. Cek test lulus
npm test
:: harus muncul: Tests: 23 passed, 23 total
```

Kalau semuanya hijau → setup OK. Lanjut ke
[Tutorial 02 — Dev Workflow](TUTORIAL_MOBILE_02_DEV_WORKFLOW.md).

---

## 11. (Opsional) Setup emulator Android

Kalau tidak punya HP fisik untuk testing:

### Via Android Studio
1. Buka Android Studio → "More Actions" → "Virtual Device Manager"
2. Klik "Create Device" → pilih "Pixel 6" (atau yang lain)
3. Pilih system image: **API 34 (Android 14) — x86_64** (lebih cepat
   daripada arm64 di kebanyakan PC)
4. Klik Finish, tunggu download (~3 GB)
5. Klik tombol play di Device Manager untuk start emulator

### Via cmdline (no GUI)
```bat
sdkmanager.bat "system-images;android-34;google_apis;x86_64"
avdmanager.bat create avd -n hris_test -k "system-images;android-34;google_apis;x86_64"
emulator -avd hris_test
```

Verifikasi emulator running:
```bat
adb devices
:: harus muncul: emulator-5554  device
```

---

## 12. Troubleshooting setup

| Gejala | Solusi |
|--------|--------|
| `'java' is not recognized` | `JAVA_HOME` belum diset atau PATH tidak include `%JAVA_HOME%\bin`. Restart terminal. |
| `'adb' is not recognized` | `ANDROID_HOME\platform-tools` belum di PATH. Set lalu restart terminal. |
| `npm ci` gagal di `node-gyp` | Install Build Tools: `npm install -g windows-build-tools` (Windows lama) atau install Visual Studio Build Tools manual. |
| Download SDK lambat | Indonesia kadang lambat ke Google CDN. Bisa pakai mirror npm: `npm config set registry https://registry.npmmirror.com` (jangan lupa balik nanti). |
| `npx tsc --noEmit` munculkan ratusan error | Hapus `node_modules` lalu `npm ci` ulang. |
| Antivirus blokir `gradlew.bat` | Whitelist folder repo di antivirus. Real-time scan bikin Gradle 5-10x lebih lambat. |

Lebih lengkap di [Tutorial 06 — Troubleshooting](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md).

---

## 13. Setup untuk macOS (build iOS)

Kalau mau build iOS, tambahan tool:

```bash
# 1. Xcode dari App Store (~10 GB)
xcode-select --install

# 2. CocoaPods (manage iOS dependency)
sudo gem install cocoapods

# 3. Watchman (file watcher, optional tapi recommended)
brew install watchman
```

Detail di [Tutorial 05 — Build iOS](TUTORIAL_MOBILE_05_BUILD_IOS.md).

---

Setup selesai. Lanjut ke
[Tutorial 02 — Dev Workflow](TUTORIAL_MOBILE_02_DEV_WORKFLOW.md) untuk
mulai ngoding.