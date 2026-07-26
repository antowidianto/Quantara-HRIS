# Tutorial 03 — Build Android Flutter (APK dan AAB)

## 1. Preflight

```bash
cd mobile
flutter doctor -v
flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
```

Pastikan application ID, version, flavor, API URL, Firebase config, permission,
dan signing target benar sebelum release.

## 2. Debug APK

```bash
flutter build apk --debug
```

Output:

```text
build/app/outputs/flutter-apk/app-debug.apk
```

Install ke device:

```bash
flutter install
```

atau:

```bash
adb install -r build/app/outputs/flutter-apk/app-debug.apk
```

## 3. Release APK

Untuk distribusi internal, build per ABI:

```bash
flutter build apk --split-per-abi
```

Output berada di `build/app/outputs/flutter-apk/`. APK release membutuhkan
signing config yang valid. Jangan membagikan APK debug sebagai release.

## 4. Android App Bundle

Google Play menggunakan AAB:

```bash
flutter build appbundle
```

Output:

```text
build/app/outputs/bundle/release/app.aab
```

Dengan flavor:

```bash
flutter build appbundle \
  --flavor production \
  -t lib/main_production.dart
```

## 5. Signing

- Buat upload key khusus organisasi.
- Simpan ke secret manager/backup terenkripsi.
- Referensikan password melalui environment/CI secret.
- Jangan commit `.jks`, `.keystore`, `key.properties`, atau password.
- Aktifkan Play App Signing dan simpan recovery material sesuai policy.

Application ID tidak dapat diubah setelah app dipublikasikan sebagai listing
yang sama.

## 6. Version

Set versi di `pubspec.yaml`:

```yaml
version: 1.2.0+42
```

Build number harus selalu naik untuk upload store. CI boleh override dengan
`--build-name` dan `--build-number`, tetapi sumber versinya harus terdokumentasi.

## 7. Verifikasi artifact

- Install release build pada minimal satu device fisik.
- Uji login/2FA, refresh token, attendance GPS+kamera, offline replay, payslip,
  deep link, push, logout, dan upgrade dari versi sebelumnya.
- Pastikan base URL production, debuggable=false, log disanitasi, dan signing
  certificate benar.
- Upload dahulu ke internal testing.

## 8. Analisis ukuran

```bash
flutter build appbundle --analyze-size
```

Audit asset, font, dan plugin; jangan memakai ukuran APK debug sebagai baseline.

Referensi resmi: <https://docs.flutter.dev/deployment/android>.
