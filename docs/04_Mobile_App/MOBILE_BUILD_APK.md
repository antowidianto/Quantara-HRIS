# Build APK/AAB Mobile

Dokumen build React Native lama sudah digantikan oleh panduan Flutter.

Gunakan:

- [Tutorial Build Android Flutter](TUTORIAL_MOBILE_03_BUILD_APK.md)
- [Tutorial Google Play Store](TUTORIAL_MOBILE_04_PLAY_STORE.md)
- [Rencana migrasi Flutter](MOBILE_FLUTTER_MIGRATION.md)

Quick reference setelah scaffold Flutter tersedia:

```bash
cd mobile
flutter pub get
flutter analyze
flutter test
flutter build apk --split-per-abi
flutter build appbundle
```

APK berada di `build/app/outputs/flutter-apk/`; AAB berada di
`build/app/outputs/bundle/release/app.aab`. Keystore dan password signing tidak
boleh di-commit.
