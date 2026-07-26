# Tutorial 04 — Publish Flutter ke Google Play

## 1. Prasyarat

- Google Play Console organization account.
- Application ID final dan konsisten dengan Firebase.
- Upload key + Play App Signing.
- Privacy policy, data safety, content rating, target audience, dan store asset.
- Production API HTTPS dan backend push aktif.

## 2. Preflight release

```bash
cd mobile
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
flutter test integration_test
flutter build appbundle \
  --flavor production \
  -t lib/main_production.dart
```

Output standar:

```text
build/app/outputs/bundle/release/app.aab
```

## 3. Store configuration

Sebelum upload:

- nama, short/full description, icon, feature graphic, screenshot;
- privacy policy dan in-app data deletion path;
- Data safety sesuai data HR: identitas, lokasi, foto/selfie, dokumen,
  diagnostics, dan device token;
- permission declaration untuk kamera, lokasi, notifikasi, dan biometric;
- app access instructions untuk reviewer (test account khusus);
- negara, pricing, dan distribution policy.

Deklarasi harus mencerminkan perilaku binary dan SDK yang benar-benar dipakai,
bukan sekadar dokumentasi rencana.

## 4. Internal testing

1. Upload AAB ke Internal testing.
2. Tambahkan release notes dan tester group.
3. Instal melalui Play Store link, bukan sideload.
4. Uji clean install dan upgrade.
5. Periksa pre-launch report, crash, ANR, permission, dan device compatibility.

Gunakan backend staging terisolasi untuk tester internal kecuali release
candidate memang ditujukan ke production.

## 5. Closed/Open testing

Promosikan artifact yang sama bila tidak ada perubahan binary. Uji:

- Android versi minimum dan terbaru;
- low-memory device;
- background/terminated FCM;
- koneksi lambat/offline;
- permission denied/permanently denied;
- perubahan tenant dan logout;
- upgrade database lokal.

## 6. Production rollout

- Mulai staged rollout dengan persentase kecil.
- Monitor crash-free users, ANR, login error, refresh failure, attendance
  rejection, outbox backlog, dan push registration.
- Pause rollout jika threshold operasional terlewati.
- Naikkan persentase bertahap setelah observation window.

Rollback binary store tidak selalu instan. Pertahankan feature flag server-side
dan kompatibilitas backend dengan versi sebelumnya.

## 7. Setiap release berikutnya

- Naikkan build number.
- Perbarui changelog dan Data safety jika data/SDK berubah.
- Jalankan upgrade test dari production version.
- Pastikan mapping/symbol file crash reporting terunggah.
- Simpan provenance artifact, commit SHA, Flutter version, dan signing identity.

Referensi resmi: <https://docs.flutter.dev/deployment/android>.
