# Tutorial 02 — Development Workflow Flutter

## 1. Mulai sesi

```bash
cd mobile
flutter pub get
flutter devices
flutter run
```

Pilih device dengan `-d` jika lebih dari satu:

```bash
flutter run -d <device-id>
```

Untuk flavor:

```bash
flutter run --flavor development -t lib/main_development.dart
```

## 2. Hot reload dan restart

Saat `flutter run` aktif:

- `r`: stateful hot reload;
- `R`: hot restart;
- `q`: stop.

Perubahan Dart UI biasanya cukup hot reload. Perubahan plugin native, manifest,
entitlement, generated initialization, dan dependency membutuhkan restart atau
build ulang.

## 3. Alur implementasi feature

1. Tetapkan endpoint, payload, error code, permission, dan acceptance criteria.
2. Buat service yang membungkus API/platform.
3. Buat repository sebagai source of truth dan tempat cache/retry policy.
4. Buat controller/view model untuk UI state dan command.
5. Buat screen/widget tanpa business logic.
6. Tambah ARB localization dan accessibility semantics.
7. Tambah unit/widget test; integration test untuk critical journey.

Jangan memanggil Dio, secure storage, database, kamera, atau GPS langsung dari
widget.

## 4. Debug network

Log hanya metadata aman:

- method dan path;
- HTTP status dan backend error code;
- request/correlation ID;
- durasi;
- retry count.

Jangan log authorization header, token, password, NIK, NPWP, rekening, selfie,
atau isi payslip. Gunakan staging proxy hanya jika disetujui security policy.

## 5. Backend lokal

Untuk Android Emulator:

```dotenv
API_BASE_URL=http://10.0.2.2:8080
```

Untuk device fisik, uji IP LAN backend dari device. Cleartext HTTP hanya untuk
local development; staging dan production harus HTTPS.

## 6. Quality loop

```bash
dart format .
flutter analyze
flutter test
```

Jalankan test file terfokus saat iterasi:

```bash
flutter test test/features/auth/auth_controller_test.dart
```

Sebelum PR:

```bash
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
flutter test integration_test
```

## 7. Offline dan lifecycle

- Connectivity event hanya menjadi trigger untuk retry, bukan bukti request
  akan berhasil.
- Persist idempotency key bersama outbox item dan gunakan kembali pada retry.
- Resume app memicu session validation dan outbox sweep yang bounded.
- Logout menghapus token dan data lokal yang terikat user/tenant.
- Error validation/conflict tidak diretry tanpa batas.

## 8. Branch dan review

Satu PR sebaiknya memuat satu feature slice atau satu concern platform. Sertakan:

- screen state yang berubah;
- endpoint/permission yang disentuh;
- bukti test;
- screenshot Android dan iOS bila UI berubah;
- risiko migrasi/parity terhadap React Native legacy.

Panduan gaya dan reviewer ada di `CONTRIBUTING.md`.
