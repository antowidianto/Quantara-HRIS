# Anti-Cheat Attendance

Dokumen ini menjelaskan modul **Anti-Cheat Attendance** yang ditambahkan
di iterasi roadmap fase 5 item #14.

## Latar Belakang

Sistem absensi GPS rentan dimanipulasi:
- **Mock GPS apps** (Fake GPS, Lockito, dll) — bisa fake lokasi ke titik kantor
- **Titip absen** — minta teman clock-in pakai HP-nya
- **VPN / IP spoofing** — palsukan jaringan
- **Re-fake browser** — ubah user-agent untuk pretend device lain

Tanpa kontrol, manajemen sulit detect kecurangan ini sampai laporan dari
rekan atau audit manual. Modul ini menyediakan **scoring otomatis +
device tracking + HR review interface** untuk surface kasus mencurigakan
proaktif.

## Apa yang Berubah

### Database

Migration `2024-01-01-000022_AntiCheatAttendance.php`:

**Extend `attendances`** dengan kolom anti-cheat:
- `clock_in_accuracy`, `clock_out_accuracy` — GPS accuracy radius (meter)
- `clock_in_is_mock_gps` — flag dari client
- `clock_in_device_id` — fingerprint hash 32-char
- `clock_in_device_name`, `clock_in_user_agent`, `clock_in_ip`
- `clock_in_selfie_path` — path foto saat clock-in
- `anomaly_score` (0-100), `anomaly_flags` (JSON array)
- `anomaly_reviewed_by`, `anomaly_reviewed_at`, `anomaly_review_note`

**Tabel baru `attendance_devices`** — track device per user:
- `device_id` unik per (user, device) — dari client fingerprint
- `first_seen_at`, `last_seen_at`, `usage_count`, `last_ip`
- `is_trusted`, `trusted_by`, `trusted_at` — HR mark device tepercaya
- `is_blocked`, `blocked_reason` — HR block device dari clock-in

2 permission baru:
- `attendance.review_anomaly` (role 1, 2)
- `attendance.manage_devices` (role 1, 2)

5 settings per company:
- `anticheat.require_selfie` (0/1) — wajibkan selfie
- `anticheat.max_gps_accuracy_m` (default 100) — reject kalau accuracy > Xm
- `anticheat.block_mock_gps` (0/1, default 1)
- `anticheat.warn_new_device` (0/1, default 1)
- `anticheat.high_anomaly_threshold` (default 60) — score >= ini = high risk

### Anomaly Scoring System

`AntiCheatService::computeAnomaly()` evaluasi 7 sinyal dengan bobot:

Flag | Weight | Trigger
--- | --- | ---
**MOCK_GPS** | 40 | Client kirim `is_mock_gps=true`
**LOW_ACCURACY** | 15 | GPS accuracy > `max_gps_accuracy_m`
**NEW_DEVICE** | 10 | Device pertama kali dipakai user (sudah ada device lain)
**BLOCKED_DEVICE** | 60 | Device sudah di-block HR
**IP_MISMATCH** | 10 | IP berbeda dari `last_ip` device
**GEOFENCE_FAR** | 15 | Jarak > radius cabang
**NO_SELFIE** | 10 | Selfie required tapi tidak ada

Score dijumlahkan (capped 100). Threshold default 60 untuk "high risk".

### Block Conditions

Beberapa flag langsung **block** clock-in (return 422):
1. **MOCK_GPS** + `block_mock_gps=1` (default ON)
2. **BLOCKED_DEVICE** (device sudah di-block HR)
3. **LOW_ACCURACY ekstrim** (> 3× threshold, mis. 300m+ kalau threshold 100m)

Selain itu, anomaly tetap tersimpan di DB dengan score & flags untuk
HR review nanti — tidak block real-time.

### Backend

**Library `AntiCheatService` (`Services::antiCheat()`)** — 5 method:
- `trackDevice($coId, $userId, $device)` — register/bump device. Return `is_new`.
- `computeAnomaly($context)` — kalkulasi flags + score + block decision
- `generateServerFingerprint($ua, $lang, $extra)` — fallback fingerprint server
- `trustDevice($id, $byUser)` / `blockDevice($id, $reason, $byUser)` — HR action
- `markReviewed($attId, $userId, $note)` — close anomaly review
- `stats($companyId, $from, $to)` — dashboard counter

**Patch `AttendanceController::clockIn`**:
- Track device fingerprint (dari body `device_id` atau generate server-side dari UA)
- Compute anomaly menggunakan AntiCheatService
- Kalau `should_block=true` → fail 422 dengan error code `CHEAT_DETECTED`
- Simpan semua field anti-cheat ke row attendance
- Audit log dengan severity WARNING saat block

**Controller baru `AnomalyController`** — 6 endpoint untuk HR:
- `GET /attendance-anomaly` — list dgn filter min_score, reviewed, date range
- `GET /attendance-anomaly/stats` — counter total/high_risk/reviewed/pending
- `POST /attendance-anomaly/(:num)/review` — mark sebagai reviewed dgn note
- `GET /attendance-devices` — list devices per user
- `POST /attendance-devices/(:num)/trust` — HR trust device
- `POST /attendance-devices/(:num)/block` — HR block device dgn alasan

### Frontend (Client-Side Detection)

Untuk efektif, frontend **harus**:

1. **Capture GPS accuracy** — kirim `position.coords.accuracy` ke backend.
2. **Detect mock location** (Android via Cordova/Capacitor):
   ```javascript
   // Pseudo-code — perlu plugin native
   if (navigator.plugins?.diagnostic?.isMockLocationEnabled) { is_mock_gps = true }
   ```
   Browser web murni tidak bisa detect mock GPS — perlu native wrapper.
3. **Generate device fingerprint** dari combo:
   - `navigator.userAgent`, `screen.width × screen.height`, `navigator.language`,
     `navigator.hardwareConcurrency`, `Intl.DateTimeFormat().resolvedOptions().timeZone`
   - Hash via SHA-256, simpan di `localStorage` untuk konsistensi
4. **Selfie capture** — `<input type="file" accept="image/*" capture="user">`
   atau MediaRecorder API. Upload ke `/uploads` lalu kirim path di body.

Saat ini Clock.vue belum di-update untuk semua ini — backend sudah siap
terima field, frontend bisa di-implement bertahap (lihat Roadmap).

## Workflow Skenario

### Skenario 1 — Karyawan jujur, GPS bagus

1. Karyawan tap Clock In, GPS accuracy 8m, lokasi tepat di kantor.
2. Body: `{ lat, lng, accuracy: 8, is_mock_gps: false, device_id: "abc..." }`
3. Backend trackDevice → device existing, usage_count++.
4. computeAnomaly → flags=[], score=0, block=false.
5. Clock-in sukses, anomaly_score=0 disimpan, tidak masuk review queue.

### Skenario 2 — Mock GPS terdeteksi (block)

1. Karyawan pakai Fake GPS app, set lokasi ke kantor virtual.
2. Body: `{ lat, lng, accuracy: 5, is_mock_gps: true }`
3. Backend computeAnomaly → flag MOCK_GPS, score=40, should_block=true.
4. Audit log severity WARNING: "Clock-in di-block: Mock GPS terdeteksi..."
5. Response 422 dengan code `CHEAT_DETECTED` dan flags. UI tampilkan error message.

### Skenario 3 — Device baru, accuracy rendah → high risk tapi tidak block

1. Karyawan ganti HP. Device baru, GPS accuracy 150m (di kafe sebelah kantor).
2. Backend trackDevice → device baru (is_new=true).
3. computeAnomaly → flags=[NEW_DEVICE, LOW_ACCURACY], score=25.
4. score=25 < threshold 60 → tidak high_risk → clock-in tetap masuk.
5. Tersimpan dengan anomaly_score=25 + flags. Tidak masuk review queue (default min_score=30 di list).

### Skenario 4 — Combo high risk → masuk review HR

1. Karyawan clock-in dari device baru (NEW_DEVICE +10), accuracy 200m (LOW_ACCURACY +15), 600m dari kantor (GEOFENCE_FAR +15) — total 40.
2. Lebih buruk: tanpa selfie + selfie required (NO_SELFIE +10) → 50.
3. Belum block, tapi muncul di **HR Anomaly List** dengan filter `min_score=30`.
4. HR review:
   - Lihat detail flags + GPS coords + device info
   - Klik **Review** → input note "Sudah konfirmasi karyawan, ada acara di luar kantor"
   - Atau klik **Block Device** kalau curiga cheat

### Skenario 5 — HR trust device baru

1. Karyawan dapat HP baru dari kantor. Clock-in pertama → flag NEW_DEVICE.
2. HR buka `/attendance-devices?user_id=X`, lihat device baru.
3. Klik **Trust** → `is_trusted=1`. Selanjutnya NEW_DEVICE flag tidak fire untuk device ini.

## Verifikasi

Smoke test `backend/scripts/test-anticheat-flow.php` lulus 45/45:
- Schema check (kolom + tabel + permission + 5 settings) ✓
- Server fingerprint deterministik ✓
- trackDevice: is_new flag + usage_count increment ✓
- computeAnomaly skenario:
  - Clean: score=0, no flags ✓
  - Mock GPS: flag + score=40 + should_block=true ✓
  - Low accuracy 200m: flag + score=15 ✓
  - Extreme accuracy 500m: should_block=true ✓
  - Geofence far: flag + score=15 ✓
  - Combo: 3+ flags, score >= 40 ✓
- trustDevice + blockDevice + verifikasi flag ✓
- Stats aggregate ✓
- Class structure ✓

## Migrasi Database

```bash
cd backend
php spark migrate
```

Settings akan auto-seed per company. Tidak ada data masuk awal.

## Integrasi dengan Modul Lain

- **Attendance** (modul existing): clockIn() di-patch tanpa breaking. Field
  baru ditambah ke row, lama tetap kompatibel.
- **Audit Log**: setiap block tercatat dengan severity WARNING, setiap review
  dengan severity NOTICE.
- **PWA Service Worker** (modul #15): mutating request lewat axios → SW
  intercept saat offline. Anti-cheat field ikut dipertahankan saat replay.

## Roadmap Selanjutnya

- **Frontend update Clock.vue**: tambah selfie capture, GPS accuracy
  display, device fingerprint generation client-side (hash dari userAgent +
  screen + timezone), kirim ke `/clock-in` body.
- **Native plugin** (Capacitor/Cordova) untuk detect mock GPS di Android.
  iOS: pakai significant location flag check.
- **HR review UI**: Vue page `Attendance/Anomalies.vue` dengan list filter
  + modal detail review (saat ini hanya endpoint API siap pakai).
- **Face match selfie**: integrasi dengan service face recognition
  (mis. AWS Rekognition, Azure Face API) untuk verifikasi selfie cocok
  dengan foto profil karyawan.
- **ML anomaly detection**: train model dari pola normal clock-in karyawan
  (jam, lokasi, device) dan flag yang menyimpang dari baseline.
- **Real-time alert**: kirim notifikasi ke HR saat clock-in dengan score
  ≥ threshold (saat ini hanya tersimpan di DB).