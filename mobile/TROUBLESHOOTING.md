# Troubleshooting Flutter Mobile

Panduan ini berlaku untuk aplikasi Flutter aktif. Catatan React Native pada
dokumen sprint lama hanya bersifat historis.

## Diagnosis awal

Jalankan dari `mobile/` dan sertakan hasilnya ketika membuat issue:

```bash
flutter doctor -v
flutter --version
flutter devices
flutter pub get
flutter analyze
flutter test
```

Catat OS, device/emulator, flavor, commit SHA, perintah yang gagal, dan error
pertama yang relevan. Hapus token, credential, PII, dan isi `.env` dari log.

## `flutter` tidak ditemukan

Pastikan folder `bin` dari Flutter SDK ada di `PATH`, buka terminal baru, lalu:

```bash
flutter doctor -v
```

Gunakan versi SDK yang dikunci repo/CI. Jangan memperbaiki build dengan upgrade
global tanpa memperbarui lock/version policy melalui PR.

## Dependency gagal diambil

```bash
flutter pub get
flutter pub outdated
```

Periksa koneksi, proxy perusahaan, certificate trust, dan versi SDK yang
diminta `pubspec.yaml`. Jangan menghapus `pubspec.lock` pada aplikasi hanya
untuk memaksa resolver memilih versi baru.

Jika cache lokal benar-benar dicurigai rusak:

```bash
flutter pub cache repair
```

## Device tidak terdeteksi

```bash
flutter devices
flutter emulators
adb devices
```

Android:

- Aktifkan USB debugging dan terima RSA prompt.
- Status `unauthorized` berarti otorisasi device belum diterima.
- Pastikan satu instalasi `adb` yang aktif berasal dari Android SDK yang sama.

iOS:

- Build memerlukan macOS dan Xcode.
- Buka Xcode sekali untuk menerima license dan memasang component.
- Percayai developer certificate pada device fisik.

## App tidak bisa mengakses backend

- Android Emulator memakai `http://10.0.2.2:8080`, bukan `localhost`.
- iOS Simulator dapat memakai `http://127.0.0.1:8080` jika backend berjalan
  pada Mac yang sama.
- Device fisik memakai IP LAN host atau staging HTTPS.
- Pastikan firewall membuka port backend dan kedua perangkat berada di jaringan
  yang dapat saling menjangkau.

Tes endpoint dari browser/curl pada device network sebelum mengubah Dio.
Untuk production, cleartext HTTP tidak boleh diaktifkan sebagai workaround.

## Perubahan source tidak muncul

Saat `flutter run` aktif:

- tekan `r` untuk hot reload;
- tekan `R` untuk hot restart;
- restart penuh diperlukan untuk perubahan native plugin, manifest,
  entitlement, generated code tertentu, atau inisialisasi sebelum `runApp`.

Jika generated code berubah, jalankan generator yang ditetapkan proyek lalu
ulang `flutter analyze`. Gunakan `flutter clean` hanya setelah error build
menunjukkan artifact stale; perintah ini menghapus artifact dan membuat build
berikutnya lebih lambat.

## Android build gagal

Mulai dari:

```bash
flutter doctor -v
flutter build apk --debug -v
```

Periksa error pertama sebelum cascade Gradle. Penyebab umum:

- JDK/Android SDK tidak sesuai dengan Flutter SDK yang dikunci.
- `applicationId`, namespace, atau manifest merge tidak konsisten.
- `google-services.json` tidak cocok dengan application ID/flavor.
- Keystore release atau environment variable signing tidak tersedia.
- Plugin membutuhkan `minSdk`/permission/Gradle config tambahan.

Jangan commit `local.properties`, keystore, atau password signing.

## iOS build / CocoaPods gagal

Jalankan di macOS:

```bash
flutter doctor -v
flutter pub get
cd ios
pod install --repo-update
cd ..
flutter build ios --debug
```

Buka `ios/Runner.xcworkspace`, bukan `.xcodeproj`. Periksa bundle ID, team,
provisioning, entitlements, deployment target, `GoogleService-Info.plist`, dan
permission descriptions. `flutter build ipa` membutuhkan konfigurasi signing
release yang valid.

## `flutter analyze` gagal

```bash
dart format .
flutter analyze
```

Perbaiki warning/error; jangan menurunkan lint global untuk satu file. Untuk
generated code, gunakan exclusion yang sempit dan terdokumentasi.

## Test flaky atau menggantung

```bash
flutter test --reporter expanded
flutter test test/path/to/failing_test.dart
```

- Gunakan fake clock/network/storage; jangan bergantung ke API nyata.
- Pastikan async animation/timer selesai (`pump`, `pumpAndSettle` dengan
  batas yang masuk akal).
- Reset provider container dan database temporary pada setiap test.
- Integration test yang butuh backend harus memakai seed dan tenant khusus.

## Login berulang / refresh loop

Periksa bahwa:

- hanya satu refresh request berjalan pada waktu yang sama;
- request lain menunggu hasil refresh;
- refresh gagal menghapus session dan mengarahkan ke login sekali;
- endpoint refresh tidak melalui interceptor refresh yang sama;
- clock device tidak terlalu melenceng;
- `X-Company-Id` tidak bocor antar-session.

Jangan mencetak token untuk debug. Log request ID, status code, endpoint, dan
error code yang sudah disanitasi.

## Offline outbox tidak terkirim

Verifikasi record memiliki idempotency key, tenant, created time, retry count,
dan payload valid. Connectivity hanya memicu percobaan; response request nyata
menentukan online/offline. Item `401`, validation error, atau conflict tidak
boleh retry tanpa batas.

Untuk clock-in/out, jangan membuat idempotency key baru pada setiap retry karena
akan menghasilkan duplikasi server-side.

## FCM tidak diterima

- Pastikan Firebase app cocok dengan bundle/application ID dan flavor.
- Minta permission pada iOS dan Android yang memerlukannya.
- Sinkronkan token setelah login dan saat token di-refresh.
- Bedakan foreground, background, dan terminated handling.
- Background handler harus top-level dan dipertahankan dari tree shaking.
- Uji notification payload dan data payload secara terpisah.

## Biometric, kamera, atau GPS gagal

Periksa permission platform, usage description, capability device, dan state
denied/permanently denied. Tampilkan fallback yang aman:

- biometric gagal → password/2FA, bukan bypass auth;
- lokasi gagal → jangan memalsukan koordinat;
- kamera gagal → izinkan retry atau flow manual sesuai policy backend.

## Build terlalu besar

```bash
flutter build appbundle --analyze-size
flutter build apk --split-per-abi
```

Audit asset, font, native symbol, dan plugin yang tidak digunakan. Jangan
menilai ukuran release dari APK debug.

## Membuat laporan issue

Gunakan format berikut:

```text
OS:
Flutter version:
Device/emulator:
Flavor:
Commit:
Command:
Expected:
Actual:
First relevant error:
Reproduction steps:
```

Lampirkan log yang sudah disanitasi. Untuk panduan lebih lengkap lihat
`docs/04_Mobile_App/TUTORIAL_MOBILE_06_TROUBLESHOOTING.md`.
