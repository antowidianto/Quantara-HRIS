# Tutorial 05 — Build dan Rilis iOS Flutter

Build iOS memerlukan macOS, Xcode, Apple Developer membership, dan akses ke
signing identity/provisioning yang sesuai.

## 1. Validasi environment

```bash
flutter doctor -v
xcodebuild -version
pod --version
flutter devices
```

Terima Xcode license dan buka Xcode minimal sekali setelah instalasi/update.

## 2. Dependency

```bash
cd mobile
flutter pub get
cd ios
pod install --repo-update
cd ..
```

Gunakan `ios/Runner.xcworkspace` ketika membuka project di Xcode.

## 3. Konfigurasi

Periksa pada Runner target/flavor:

- bundle ID dan display name;
- team, signing certificate, dan provisioning;
- minimum iOS version;
- push notification dan background mode;
- associated domains/deep link;
- camera, photo, location, biometric, dan notification usage description;
- Firebase plist yang cocok;
- app icon dan launch assets;
- privacy manifest/required reason API dari app dan dependency.

## 4. Run simulator/device

```bash
flutter run -d <device-id>
```

Simulator tidak mewakili semua biometric, push, kamera, background, dan
location behavior. Critical flow wajib diuji pada device fisik.

## 5. Quality gate

```bash
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
flutter test integration_test
```

## 6. Build archive dan IPA

```bash
flutter build ipa \
  --flavor production \
  -t lib/main_production.dart
```

Output:

- archive: `build/ios/archive/`
- IPA: `build/ios/ipa/`

Versi diambil dari `pubspec.yaml` atau dioverride melalui
`--build-name`/`--build-number`. Build number harus unik untuk setiap upload.

## 7. TestFlight

1. Upload melalui Xcode Organizer atau Transporter.
2. Tunggu processing dan perbaiki validation issue.
3. Isi export compliance, privacy, dan tester notes.
4. Distribusikan ke internal tester.
5. Uji clean install, upgrade, push foreground/background/terminated,
   deep link, biometric fallback, offline replay, dan logout.

## 8. App Store

- Siapkan screenshot sesuai device class dan metadata localized.
- Sediakan reviewer account/instructions yang tidak memakai data nyata.
- Pastikan privacy label sesuai data HR dan SDK.
- Pilih manual/phased release sesuai rollout plan.
- Monitor crash, hang, auth, push, dan attendance setelah rilis.

Signing certificate, private key, provisioning profile, App Store Connect API
key, dan APNs key disimpan di secret manager—bukan repo.

Referensi resmi: <https://docs.flutter.dev/deployment/ios>.
