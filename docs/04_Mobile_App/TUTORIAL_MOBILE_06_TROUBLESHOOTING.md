# Tutorial 06 — Troubleshooting Flutter

Mulai dari panduan ringkas di
[`mobile/TROUBLESHOOTING.md`](../../mobile/TROUBLESHOOTING.md). Halaman ini
menjelaskan urutan eskalasi agar masalah tidak ditutupi oleh cache reset atau
perubahan dependency acak.

## 1. Kumpulkan baseline

```bash
cd mobile
flutter doctor -v
flutter --version
flutter devices
flutter pub get
flutter analyze
flutter test --reporter expanded
```

Rekam error pertama, bukan hanya baris terakhir. Sertakan OS, device, flavor,
commit, command, reproduction, dan expected/actual result. Sanitasi credential
dan PII.

## 2. Klasifikasikan error

| Kelas | Indikasi | Fokus |
|---|---|---|
| Toolchain | `flutter doctor`, SDK/JDK/Xcode | versi dan PATH |
| Dependency | resolver/pub/plugin | `pubspec`, lock, SDK constraint |
| Compile native | Gradle/Kotlin/Swift/Pods | platform config |
| Dart analyze | type/lint/import | source/generated code |
| Test | assertion/timeout | fake, state reset, async |
| Runtime | crash/blank screen | sanitized logs, lifecycle |
| Network | timeout/TLS/401 | base URL, cert, interceptor |
| Store | signing/validation | identity, entitlement, metadata |

## 3. Tindakan aman berurutan

1. Reproduce pada command/file paling kecil.
2. Bandingkan Flutter version dengan CI.
3. Jalankan `flutter pub get` tanpa mengubah lock.
4. Baca error pertama dan plugin/platform requirement terkait.
5. Perbaiki konfigurasi/source.
6. Gunakan `flutter clean` hanya jika artifact stale terbukti atau sangat
   mungkin setelah perubahan native besar.
7. Build ulang dan jalankan test regression.

Jangan langsung menghapus lock file, Pods, Gradle cache, atau seluruh SDK.
Tindakan tersebut menghilangkan bukti dan dapat menambah variabel baru.

## 4. Logging aman

Log yang boleh:

```text
POST /api/v1/attendance/clock-in -> 409 ATTENDANCE_DUPLICATE
request_id=... duration_ms=... retry=...
```

Log yang dilarang: JWT, refresh token, password, TOTP, NIK, NPWP, rekening,
selfie, dokumen, full payslip response, signing password, Firebase/APNs key.

## 5. Masalah khusus migrasi

### Perilaku Flutter berbeda dari React Native

Bandingkan kontrak, bukan widget:

- request method/path/header/body;
- parsing timezone, currency, decimal, dan nullable field;
- error code ke UI state;
- idempotency key dan retry;
- local cache invalidation;
- permission dan lifecycle;
- analytics event dan deep link.

Buat fixture request/response yang sama untuk kedua implementasi dan tambahkan
regression test Flutter.

### Dua runtime menggunakan identifier yang sama

Jangan install build development di atas production tanpa memahami application
ID, signing certificate, dan database migration. Gunakan ID/flavor terpisah
untuk parallel testing. Strategi update store ditentukan saat cutover.

### Legacy file masih muncul di pencarian

`SPRINT_MOBILE_M0`–`M7` dan tree TypeScript adalah histori sementara. Feature
baru harus masuk `lib/`. Jika CI masih menjalankan npm/Jest mobile, workflow
Flutter belum di-cutover—perbarui CI hanya ketika scaffold dan tests tersedia.

## 6. Kapan eskalasi

Eskalasi ke:

- backend: kontrak/error code/tenant/idempotency tidak jelas;
- security: pinning, attestation, root policy, secret atau PII exposure;
- release/ops: signing, provisioning, store credential, Firebase/APNs;
- mobile lead: architecture boundary, plugin baru, native channel;
- product/QA: parity atau acceptance criteria ambigu.

Issue harus menyertakan minimal reproduction dan log yang sudah disanitasi.

Referensi:

- Flutter CLI: <https://docs.flutter.dev/reference/flutter-cli>
- Android release: <https://docs.flutter.dev/deployment/android>
- iOS release: <https://docs.flutter.dev/deployment/ios>
