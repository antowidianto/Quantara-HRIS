# ADR 0002: Migrasi Client Mobile ke Flutter

- Status: Accepted / Implemented
- Tanggal: 2026-07-26
- Pemilik: Mobile / Architecture

## Context

Client mobile sebelumnya dibangun dengan React Native dan TypeScript. Tim
memutuskan membangun ulang client mobile dengan Flutter untuk Android dan iOS.
Kontrak backend, feature scope, security control, dan offline behavior harus
tetap kompatibel selama migrasi.

## Decision

Flutter menjadi runtime dan UI toolkit aktif. Dart menjadi bahasa client
mobile. Struktur aplikasi memisahkan:

- view/widget dan view model/controller;
- repository sebagai source of truth;
- service untuk REST API, database, dan platform plugin;
- domain/use-case hanya untuk logic kompleks atau reusable.

Baseline stack adalah Riverpod untuk state/dependency injection, `go_router`
untuk navigation, Dio untuk HTTP, secure storage untuk token, storage persisten
untuk outbox, dan Firebase Messaging untuk push.

Cutover repository selesai pada Juli 2026. React Native tetap dapat dilihat
melalui riwayat Git sebagai behavioral reference, tetapi source dan runtime
legacy tidak lagi berada di aplikasi aktif.

## Consequences

Positif:

- satu codebase Flutter untuk Android dan iOS;
- konsistensi rendering dan design system;
- architecture boundary dan testability yang eksplisit;
- build/release memakai satu Flutter toolchain.

Trade-off:

- tim perlu upskilling Dart/Flutter;
- UI dan plugin integration dibangun ulang;
- release signing, crash reporting, deep link, localization, dan UAT device
  tetap memerlukan hardening per environment.

## Guardrails

- Jangan menghidupkan kembali runtime React Native untuk feature baru.
- Backend API tidak di-fork khusus Flutter tanpa review kontrak.
- Mutation kritis mempertahankan idempotency key saat offline retry.
- Token hanya disimpan di secure storage.
- Plugin baru memerlukan review maintenance, license, platform support,
  permission, privacy, dan native build impact.
- Application ID/bundle ID serta store listing dipertahankan atau diubah hanya
  melalui release decision terpisah.

## References

- `docs/04_Mobile_App/MOBILE_FLUTTER_MIGRATION.md`
- `docs/04_Mobile_App/MOBILE_APP_PLAN.md`
- `mobile/README.md`
