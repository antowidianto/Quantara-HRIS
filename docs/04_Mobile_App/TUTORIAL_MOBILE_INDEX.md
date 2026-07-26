# Tutorial Mobile HRIS — Flutter

Index panduan aktif untuk mengembangkan, menguji, build, dan merilis aplikasi
Flutter Quantara HRIS ke Android dan iOS.

> Cutover dari React Native ke Flutter selesai pada Juli 2026. Lihat
> [catatan migrasi](MOBILE_FLUTTER_MIGRATION.md) untuk hasil dan checklist
> release.

## Urutan baca

| # | Tutorial | Tujuan |
|---|---|---|
| 01 | [Setup Environment](TUTORIAL_MOBILE_01_SETUP.md) | Pasang Flutter, Android SDK, dan Xcode |
| 02 | [Development Workflow](TUTORIAL_MOBILE_02_DEV_WORKFLOW.md) | Run, hot reload, debug, test |
| 03 | [Build APK/AAB](TUTORIAL_MOBILE_03_BUILD_APK.md) | Build Android debug/release |
| 04 | [Google Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md) | Signing, internal test, staged rollout |
| 05 | [Build iOS](TUTORIAL_MOBILE_05_BUILD_IOS.md) | TestFlight dan App Store |
| 06 | [Troubleshooting](TUTORIAL_MOBILE_06_TROUBLESHOOTING.md) | Diagnosis build/runtime |

## Quick reference

Semua perintah dijalankan dari `mobile/`:

```bash
flutter doctor -v
flutter pub get
dart format --output=none --set-exit-if-changed lib test
flutter analyze
flutter test
flutter devices
flutter run
flutter build apk --split-per-abi
flutter build appbundle
flutter build ipa
```

## Struktur aktif

```text
mobile/
├── lib/
│   ├── core/
│   ├── features/
│   ├── app.dart
│   └── main.dart
├── test/
├── android/
├── ios/
├── pubspec.yaml
└── analysis_options.yaml
```

## Prinsip

1. Format, analyze, dan test harus hijau sebelum PR.
2. Keystore, provisioning profile, token, `.env`, dan Firebase credential
   tidak boleh di-commit.
3. UI, state, repository, dan platform service dipisahkan.
4. Mutation kritis mempertahankan idempotency key saat retry.
5. Android dan iOS wajib diuji; berhasil di satu platform belum berarti parity.
6. Dokumen `SPRINT_MOBILE_M0`–`M7` adalah histori React Native, bukan panduan
   implementasi baru.

Referensi resmi: <https://docs.flutter.dev/reference/flutter-cli>.
