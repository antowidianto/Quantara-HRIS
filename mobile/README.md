# Quantara HRIS Mobile — Flutter

Aplikasi Android dan iOS Quantara HRIS. Runtime React Native telah diganti
dengan Flutter; source TypeScript, dependency Node, Metro, dan native project
React Native tidak lagi digunakan.

## Status implementasi

- Flutter 3.44.7 dan Dart null safety.
- Material 3, Riverpod, `go_router`, dan Dio.
- Login, 2FA, refresh JWT single-flight, tenant header, dan idempotency key.
- Attendance dengan GPS, kamera selfie, riwayat, dan offline outbox.
- Leave, approvals, payslip, profile, overtime, reimbursement, business trip,
  helpdesk, notifications, performance, training, tasks, dan settings.
- Token tersimpan di `flutter_secure_storage`.
- Push FCM terhubung ke endpoint registrasi device dan aman ketika konfigurasi
  Firebase suatu environment belum dipasang.
- Android application ID dan iOS bundle ID tetap `com.hrismobile`.

Daftar layar Tier 2/3 menggunakan komponen koleksi API generik agar seluruh
modul tetap dapat digunakan selama UI khusus per domain disempurnakan.

## Struktur

```text
mobile/
├── lib/
│   ├── core/       # auth, HTTP, storage, push, offline outbox
│   ├── features/   # screen dan controller per domain
│   ├── app.dart
│   └── main.dart
├── test/
├── android/
├── ios/
├── pubspec.yaml
└── analysis_options.yaml
```

## Menjalankan aplikasi

Prasyarat: Flutter 3.44.7, Android SDK 36 untuk Android, atau macOS + Xcode
untuk iOS.

```bash
cd mobile
flutter pub get
flutter doctor
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080/api/v1
```

Android Emulator mengakses backend host melalui `10.0.2.2`. Untuk device
fisik, gunakan IP LAN atau URL staging HTTPS.

Nilai build yang tersedia:

```text
API_BASE_URL   default: http://10.0.2.2:8080/api/v1
APP_VERSION    default: 1.0.0
APP_BUILD      default: 1
```

Semua nilai diberikan memakai `--dart-define`; jangan menaruh secret pada
source atau build define.

## Firebase push

Tambahkan file konfigurasi dari project Firebase yang sesuai environment:

- `android/app/google-services.json`
- `ios/Runner/GoogleService-Info.plist`

Konfigurasi tersebut bersifat environment-specific dan tidak boleh memakai
credential produksi di repository. Tanpanya aplikasi tetap berjalan, tetapi
registrasi push dilewati.

## Quality gate

```bash
dart format --output=none --set-exit-if-changed lib test
flutter analyze
flutter test
flutter build apk --debug
```

CI menjalankan seluruh gate di atas, termasuk build APK Android.

## Build release

```bash
flutter build apk --split-per-abi
flutter build appbundle
flutter build ipa
```

Signing Android release sengaja tidak memakai debug key. CI/release pipeline
harus memasok keystore, provisioning profile, serta credential store secara
aman.

Dokumentasi lanjutan:

- [Panduan migrasi](../docs/04_Mobile_App/MOBILE_FLUTTER_MIGRATION.md)
- [Tutorial mobile](../docs/04_Mobile_App/TUTORIAL_MOBILE_INDEX.md)
- [Troubleshooting](TROUBLESHOOTING.md)
