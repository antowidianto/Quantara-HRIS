# Training & Sertifikasi

Modul **Training & Sertifikasi** untuk perusahaan compliance-heavy
(food, medis, manufacturing, construction). Mengelola siklus training
dari pendaftaran sampai sertifikat, dengan tracking expiry untuk
memastikan tidak ada lisensi karyawan yang lewat tanggal tanpa diketahui.

Status: ✅ Implementasi end-to-end terverifikasi (smoke test 33/33).

## Latar Belakang

Perusahaan dengan kewajiban sertifikasi (mis. operator alat berat dengan
SIO, K3 umum, BPOM HACCP, ISO auditor) sering kebobolan **expired
license** karena tracking manual di Excel. Konsekuensi:
- Karyawan tidak boleh kerja di posisi yang butuh sertifikat aktif
- Audit eksternal flag temuan
- Risiko kecelakaan / non-compliance hukum

Modul ini menyediakan:
1. **Master training** dengan biaya, kapasitas, expiry sertifikat
2. **Workflow pendaftaran** (REGISTERED → APPROVED → ATTENDING → COMPLETED → CERTIFIED)
3. **Auto-issue certificate** kalau lulus passing score
4. **Budget tracking** per departemen per tahun (allocated/used/reserved)
5. **Cron expiry alert** ke karyawan + HR (60/30/14/7 hari)

## Apa yang Berubah

### Database

Migration `2024-01-01-000023_CreateTrainingTables.php` membuat **4 tabel**:

**`trainings`** — master training:
- `code` unik per company (`TRN-YEAR-XXXXX`)
- `category` (TECHNICAL/SOFT_SKILL/COMPLIANCE/SAFETY/LEADERSHIP/OTHER)
- `training_type` (INTERNAL/EXTERNAL/ONLINE/HYBRID)
- Schedule: start_date, end_date, duration_hours
- Capacity: max_participants (0 = unlimited), min_participants
- Cost: cost_per_participant, charged-to department_id
- Certificate: issues_certificate flag, validity_months, passing_score
- Status: DRAFT → PUBLISHED → ONGOING → COMPLETED / CANCELLED

**`training_registrations`** — pendaftaran karyawan:
- State machine 8 status: REGISTERED → APPROVED → ATTENDING → COMPLETED → CERTIFIED, branch REJECTED / CANCELLED / NO_SHOW
- attendance_percent, final_score, is_passed flag
- cost (snapshot dari training saat approve, untuk budget tracking)

**`certificates`** — sertifikat per karyawan:
- `certificate_number` unik (`CERT-YEAR-XXXXX` atau dari issuer eksternal)
- Bisa generated dari training_registration ATAU di-upload manual (kategori PROFESSIONAL/LICENSE/OTHER)
- `expires_date` nullable (null = lifetime)
- Status: ACTIVE → EXPIRED → REVOKED
- `last_alert_sent_at` untuk anti-spam alert

**`training_budgets`** — alokasi per dept per tahun:
- `allocated_amount` (set HR)
- `used_amount` (auto: dari training COMPLETED)
- `reserved_amount` (auto: dari APPROVED tapi belum COMPLETED)
- Available = allocated - used - reserved

**6 permission baru**:
- `training.view`, `training.manage`, `training.register`
- `certificate.view`, `certificate.manage`
- `training_budget.manage`

Auto-grant: role 1, 2 dapat semua; role 3-5 dapat view + register + view cert.

### Backend

**Library `TrainingService` (`Services::training()`)** — 8 method:
- `nextTrainingCode($companyId)` — generator unik per tahun
- `nextCertNumber($companyId)` — generator cert number
- `approveRegistration($regId, $userId)` — validasi kapasitas + reserve budget
- `completeRegistration($regId, $data)` — set COMPLETED + auto-issue cert kalau lulus
- `issueCertificate($reg, $training)` — buat row certificates dgn expires berdasarkan validity_months training
- `expireOverdueCertificates($companyId)` — set EXPIRED untuk yang lewat tanggal
- `findExpiringCertificates($daysAhead, $companyId)` — list yg akan expire
- `stats($companyId, $year)` — counter dashboard + budget summary

**Controller `TrainingController`** — 24 endpoint:
- Trainings: CRUD + publish + register peserta + list registrations
- Registrations: approve / reject / complete / cancel
- Certificates: CRUD + endpoint khusus `expiring`
- Budgets: list / create / update per dept per tahun
- Stats dashboard

**Command `php spark hr:check-cert-expiry`** — cron daily:
- Auto-expire cert yg sudah lewat tanggal → status EXPIRED
- Alert karyawan + HR untuk cert yg akan habis dalam 60/30/14/7 hari
- Anti-spam: skip kalau last_alert_sent_at < 7 hari yg lalu
- Flag: `--dry-run`, `--company=N`, `--days=60,30,14,7`

### Frontend

**`Training/TrainingList.vue`** — landing page:
- 5 stat cards: Total/Peserta Aktif/Selesai/Sertifikat Aktif/Expire ≤30 hari
- Budget summary bar dengan progress emerald (used) + amber (reserved)
- Filter cari/status/kategori
- Tabel dengan link ke detail + tombol Publish (DRAFT only)
- Modal Create dengan field lengkap (kategori/tipe/biaya/kuota/sertifikat)

**`Training/TrainingDetail.vue`** — halaman per-training:
- Card meta lengkap (kategori, tanggal, durasi, lokasi, biaya, kapasitas, sertifikat)
- Tombol "Daftar Diri Saya" (karyawan) + "Daftarkan Karyawan" (HR)
- Tabel peserta dengan action per row:
  - Approve / Reject (untuk REGISTERED)
  - Selesai dengan modal input score + attendance (APPROVED/ATTENDING)
- Modal Complete: input final_score + attendance_percent → trigger auto-issue cert kalau training mengeluarkan dan lulus passing score

**`Training/CertificateList.vue`** — list semua sertifikat:
- Toggle "Hanya milik saya" untuk view personal
- Filter status (ACTIVE/EXPIRED/REVOKED)
- Tombol "Akan Habis" → load filter expiring 30 hari
- Highlight kuning untuk row yang akan expire ≤30 hari + label "X hari lagi"
- Modal Upload Sertifikat Eksternal (kategori PROFESSIONAL/LICENSE/dll)

## Workflow Skenario

### Skenario 1 — Internal training dengan sertifikat (compliance)

1. **HR buat training** "K3 Umum 2026" — category=COMPLIANCE, max_participants=20, biaya 500k/peserta, issues_certificate=true, validity_months=36, passing_score=70.
2. **HR publish** → status PUBLISHED.
3. **Karyawan A** klik "Daftar Diri Saya" → status REGISTERED.
4. **HR approve** → status APPROVED + budget reserved +500k.
5. Training berjalan, **HR mark Complete** dengan score=85, attendance=100% → status CERTIFIED + cert otomatis dibuat dengan expires_date = +36 bulan + budget reserved -500k, used +500k.
6. 30 hari sebelum expires_date → cron `hr:check-cert-expiry` kirim notifikasi ke karyawan + HR.

### Skenario 2 — External training berbayar (vendor)

1. HR buat training "AWS Certified Solutions Architect" — type=EXTERNAL, provider="AWS", biaya 8jt/peserta.
2. Manager approve, total budget reserved naik 8jt.
3. Setelah training, HR input final_score=820 (dari 1000) → manual issue cert atau biarkan service auto-issue (kalau passing_score=750 misalnya).

### Skenario 3 — Sertifikat eksternal (upload manual)

1. Karyawan punya SIM B1 dari kepolisian. HR buka `/certificates` → klik "Upload Sertifikat".
2. Pilih karyawan, kategori=LICENSE, issuer="POLRI", issued_date, expires_date.
3. Cron akan track expiry dan alert sebelum habis.

### Skenario 4 — Capacity overflow

1. Training kuota 5 orang, sudah 5 yang APPROVED.
2. Karyawan ke-6 daftar → status REGISTERED.
3. HR coba approve → throw error "Kuota peserta penuh (5/5)". HR harus tambah kuota atau reject.

### Skenario 5 — Gagal lulus passing score

1. Training dengan passing_score=70.
2. Karyawan complete dengan final_score=50 → status COMPLETED (bukan CERTIFIED).
3. is_passed=0, tidak ada certificate dibuat.
4. Karyawan harus daftar lagi atau retake assessment terpisah.

## Verifikasi

Smoke test `backend/scripts/test-training-flow.php` lulus 33/33:
- Schema (4 tabel + 6 permission) ✓
- Generators TRN-YEAR-XXXXX & CERT-YEAR-XXXXX ✓
- Create training + register karyawan ✓
- approveRegistration → status APPROVED + budget reserved bertambah ✓
- Capacity overflow → throw RuntimeException "penuh" ✓
- completeRegistration dengan score lulus → CERTIFIED + auto-issue cert ✓
- Cert dibuat dengan category COMPLIANCE + expires_date = +12 bulan ✓
- Budget used bertambah setelah complete ✓
- Gagal score (50 < passing 70) → COMPLETED, no cert ✓
- expireOverdueCertificates → set EXPIRED ✓
- findExpiringCertificates(30) include cert +20 hari, exclude di findExpiringCertificates(7) ✓
- Stats dashboard struktur lengkap ✓
- Class structure ✓

## Cron Setup

### Linux (cron)

```bash
# Edit crontab
crontab -e

# Tambah baris (jalan setiap hari jam 8 pagi)
0 8 * * * cd /path/to/backend && php spark hr:check-cert-expiry >> writable/logs/cert-expiry.log 2>&1
```

### Windows (Task Scheduler)

```powershell
schtasks /create /tn "HRIS Cert Expiry Check" /sc DAILY /st 08:00 ^
  /tr "cmd /c cd /d D:\Apps Project\hris-project-1\backend && php spark hr:check-cert-expiry"
```

### Manual run

```bash
# Dry-run (lihat tanpa kirim notif)
php spark hr:check-cert-expiry --dry-run

# Per company
php spark hr:check-cert-expiry --company=1

# Custom buckets
php spark hr:check-cert-expiry --days=90,60,30,14,7
```

## Roadmap Selanjutnya

- **File upload** sertifikat (PDF) — schema sudah ada `file_path`, perlu endpoint POST multipart
- **Renewal flow** — auto-link cert lama dengan training baru sebagai renewal
- **Compliance dashboard** — % karyawan dengan cert valid per posisi/dept
- **Skill matrix** — link cert ke kompetensi posisi (training prerequisite untuk grade naik)
- **Email integration** — selain notif in-app, kirim email reminder
- **External integrations** — sinkronisasi dengan platform LMS (Coursera, Udemy Business)