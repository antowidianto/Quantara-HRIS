# Tutorial 01 — Setup Environment Flutter

## 1. Prasyarat

| Tool | Android | iOS |
|---|---:|---:|
| Flutter stable | wajib | wajib |
| Git | wajib | wajib |
| Android Studio + Android SDK | wajib | opsional |
| JDK yang didukung Flutter | wajib | tidak |
| Xcode + command line tools | tidak | wajib, macOS |
| CocoaPods | tidak | wajib |

Versi Flutter harus mengikuti file version manager/CI repo. Jangan memilih
versi berdasarkan instalasi global developer.

## 2. Validasi toolchain

```bash
flutter --version
flutter doctor -v
```

Selesaikan item merah untuk platform yang akan dibuild. Warning Xcode boleh
diabaikan oleh developer Windows/Linux yang hanya mengerjakan Android.

Untuk Android license:

```bash
flutter doctor --android-licenses
```

## 3. Siapkan emulator atau device

Android:

```bash
flutter emulators
flutter devices
adb devices
```

Aktifkan USB debugging untuk device fisik. Untuk emulator, buat AVD melalui
Android Studio Device Manager.

iOS (macOS):

```bash
open -a Simulator
flutter devices
```

## 4. Ambil dependency

Setelah scaffold Flutter tersedia:

```bash
cd mobile
flutter pub get
```

Pertahankan `pubspec.lock` untuk aplikasi. Dependency upgrade dilakukan lewat
PR terpisah dengan test Android dan iOS.

## 5. Konfigurasi environment

```bash
cp .env.example .env
```

Nilai umum:

```dotenv
APP_ENV=local
API_BASE_URL=http://10.0.2.2:8080
```

- Android Emulator: `10.0.2.2` menunjuk host.
- iOS Simulator: `127.0.0.1` dapat dipakai bila backend di Mac yang sama.
- Device fisik: gunakan IP LAN host atau staging HTTPS.

`.env` hanya untuk konfigurasi publik. Secret backend, signing password, dan
service account tidak boleh dikirim ke binary mobile.

Jika project memakai flavors, file entry point dan konfigurasi per flavor harus
jelas (`development`, `staging`, `production`) tanpa mengubah source manual.

## 6. Firebase dan capability platform

Firebase app harus dibuat terpisah per application ID/bundle ID atau sesuai
strategi flavor:

- Android: letakkan config pada source set/flavor yang benar.
- iOS: pasang config ke Runner target yang benar.
- Aktifkan push capability/APNs untuk iOS.

File config Firebase bukan pengganti secret management. Service account
Firebase tetap hanya berada di backend/CI.

## 7. Verifikasi project

```bash
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
flutter run
```

Setup berhasil bila app terbuka, dapat mencapai backend, dan log tidak
mengandung token atau PII.

## 8. Editor

Gunakan plugin Flutter/Dart resmi untuk VS Code atau Android Studio. Aktifkan:

- format on save;
- analysis server;
- organize imports;
- ruler sesuai style repo.

Jangan menonaktifkan lint global untuk menyembunyikan error lokal.

Referensi resmi: <https://docs.flutter.dev/get-started/install>.
