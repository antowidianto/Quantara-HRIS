# Migrasi Mobile React Native ke Flutter

Status: **cutover repository selesai (Juli 2026)**. Aplikasi aktif di
`mobile/` sekarang menggunakan Flutter; source, dependency, tooling, serta
native project React Native telah dihapus.

Sebelum rilis store, setiap environment tetap harus menyelesaikan UAT device,
Firebase native config, signing, provisioning, dan staged rollout.

## Tujuan

- Mengganti client React Native dengan satu codebase Flutter untuk Android
  dan iOS.
- Mempertahankan kontrak REST API, JWT refresh, `X-Company-Id`,
  `Idempotency-Key`, offline outbox, biometric, GPS, kamera, dan FCM.
- Memindahkan client tanpa mengubah aturan bisnis backend.
- Menjadikan Flutter satu-satunya runtime mobile di repository.

## Stack aktif

| Area | Implementasi |
|---|---|
| SDK | Flutter 3.44.7 stable, Dart null safety |
| State + DI | Riverpod |
| Navigation | `go_router` |
| HTTP | Dio dengan auth, refresh single-flight, tenant, dan idempotency interceptor |
| Secret | `flutter_secure_storage` |
| Offline | Outbox persisten melalui `shared_preferences` |
| Push | Firebase Core + `firebase_messaging` |
| UI | Material 3, antarmuka utama Bahasa Indonesia |
| Testing | Unit dan widget test melalui `flutter test` |

## Struktur aktif

```text
mobile/
├── lib/
│   ├── main.dart
│   ├── app.dart
│   ├── core/
│   │   ├── auth/
│   │   ├── config/
│   │   ├── network/
│   │   ├── notifications/
│   │   └── offline/
│   └── features/
├── test/
├── android/
├── ios/
├── pubspec.yaml
└── analysis_options.yaml
```

## Pemetaan hasil cutover

| React Native lama | Flutter aktif |
|---|---|
| `App.tsx` | `lib/app.dart` |
| `src/features/*Screen.tsx` | `lib/features/**/*_screen.dart` |
| React Navigation | `go_router` |
| Zustand | Riverpod notifier/provider |
| Axios interceptor | Dio interceptor |
| Keychain wrapper | `flutter_secure_storage` |
| MMKV outbox | Persistent Flutter outbox |
| NetInfo | `connectivity_plus` dan validasi request nyata |
| AppState | `WidgetsBindingObserver` |
| Jest/component test | `flutter test` |
| Metro | Flutter tool dan stateful hot reload |

Widget dibangun ulang mengikuti Material 3. Kontrak API, state transition,
permission flow, dan acceptance criteria tetap menjadi acuan kompatibilitas.

## Hasil migrasi

1. **Fondasi selesai** — Flutter scaffold, lint, CI, Dio client, secure
   storage, session, routing, dan Material 3.
2. **Auth selesai** — login, refresh token single-flight, 2FA, biometric,
   logout, dan pembersihan token.
3. **Tier 1 tersedia** — Profile, Attendance, Leave, Approvals, Payslip,
   serta Devices/push registration.
4. **Tier 2 tersedia** — Overtime, Reimbursement, Business Trip, Helpdesk.
5. **Tier 3 tersedia** — Notifications, Performance, Training, Tasks,
   dan Settings.
6. **Reliability tersedia** — offline attendance outbox,
   connectivity/lifecycle replay, stable idempotency key, dan background push.
7. **Cutover selesai** — workflow CI memakai Flutter dan dependency Node
   khusus mobile telah dihapus.

Layar Tier 2/3 menggunakan koleksi API generik sebagai baseline fungsional.
UI dan validasi khusus domain dapat ditingkatkan tanpa menghidupkan kembali
runtime React Native.

## Quality gate

Jalankan dari `mobile/`:

```bash
dart format --output=none --set-exit-if-changed lib test
flutter analyze
flutter test
flutter build apk --debug
```

Build iOS dijalankan di macOS:

```bash
flutter build ipa
```

## Checklist release setelah cutover

- Application ID dan bundle ID dipertahankan sebagai `com.hrismobile`.
- Verifikasi flow auth, presensi, cuti, approval, payslip, dan offline replay
  terhadap staging di device Android/iOS.
- Pasang `google-services.json` dan `GoogleService-Info.plist` per environment.
- Verifikasi signing key, APNs entitlement, universal/app links, privacy
  declarations, izin kamera/lokasi, dan screenshot store.
- Gunakan staged rollout; rollback melalui versi store sebelumnya.
- Tambahkan ARB localization `id`/`en`, integration test, accessibility audit,
  serta deep-link routing sebagai hardening sebelum cakupan produksi penuh.

## Dokumen aktif dan historis

- Panduan aktif: `mobile/README.md`, `mobile/TROUBLESHOOTING.md`, dan
  `TUTORIAL_MOBILE_*.md`.
- `SPRINT_MOBILE_M0` sampai `M7` adalah arsip implementasi React Native lama,
  bukan panduan untuk feature baru.

## Referensi resmi

- Flutter CLI: <https://docs.flutter.dev/reference/flutter-cli>
- Arsitektur Flutter: <https://docs.flutter.dev/app-architecture/guide>
- Offline-first: <https://docs.flutter.dev/app-architecture/design-patterns/offline-first>
- Android release: <https://docs.flutter.dev/deployment/android>
- iOS release: <https://docs.flutter.dev/deployment/ios>
- Firebase Messaging:
  <https://firebase.google.com/docs/cloud-messaging/flutter/receive-messages>
