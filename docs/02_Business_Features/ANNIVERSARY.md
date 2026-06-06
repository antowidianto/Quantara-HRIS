# Anniversary / Birthday Automation

Dokumen ini menjelaskan modul **Anniversary / Birthday Automation** yang
ditambahkan di iterasi roadmap fase 5 item #17.

## Latar Belakang

Engagement karyawan kecil tapi penting: ucapan ulang tahun otomatis,
penghargaan masa kerja terutama saat milestone (1, 3, 5, 10, 15, 20, 25
tahun). Banyak HR lupa karena harus track manual di kalender.

Modul ini menyediakan:
- Cron harian otomatis cek `date_of_birth` & `join_date` karyawan
- Notifikasi otomatis ke karyawan + atasan + HR
- Highlight milestone dengan template & icon khusus
- Endpoint widget untuk dashboard "upcoming events"

## Apa yang Berubah

### Backend

#### Command `php spark hr:check-anniversary`

Cron harian yang loop semua karyawan aktif:
- **Birthday check**: kalau `date_of_birth.m-d` = today.m-d → kirim notif ke karyawan + HR.
- **Anniversary check**: kalau `join_date.m-d` = today.m-d AND years ≥ 1 →
  notif ke karyawan + atasan langsung. Milestone (1/3/5/10/15/20/25/30 tahun)
  dapat extra notif ke HR untuk pertimbangkan reward.

Flag opsional:
- `--dry-run` — tampilkan list saja tanpa kirim notif
- `--target=birthday|anniversary|both` (default both)
- `--company=N` — filter per perusahaan

Audit log per run: tercatat di modul `HR` dengan jumlah event hari itu.

**Schedule**: tambah ke crontab/Task Scheduler:

```bash
# Setiap hari jam 08:00
0 8 * * * cd /path/to/backend && php spark hr:check-anniversary
```

Untuk Windows Task Scheduler, jalankan via batch:
```batch
@echo off
cd "C:\path\to\backend"
php spark hr:check-anniversary
```

#### Controller `AnniversaryController`

2 endpoint untuk dashboard widget:

| Endpoint | Aksi |
|---|---|
| `GET /anniversary/upcoming?days=30` | List event 30 hari ke depan, sorted by tanggal. Default 30, max 90. |
| `GET /anniversary/today` | Shortcut untuk event hari ini saja. |

Setiap event berisi: type (BIRTHDAY/ANNIVERSARY), date, days_until, employee
info (id/name/department/branch), age atau years, milestone flag.

### Tipe Notifikasi

Type | Trigger | Penerima
--- | --- | ---
`birthday.self` | Hari ulang tahun karyawan | Karyawan ybs
`birthday.team` | Hari ulang tahun karyawan | HR (role 2)
`anniversary.self` | Anniversary kerja non-milestone | Karyawan ybs
`anniversary.milestone` | Milestone (1/3/5/10/15/20/25/30) | Karyawan ybs
`anniversary.team` | Anniversary kerja | Atasan langsung
`anniversary.milestone_hr` | Milestone | HR + admin (role 1, 2)

## Workflow Skenario

### Skenario 1 — Karyawan ulang tahun

1. Cron 08:00 jalan, deteksi `date_of_birth.m-d` = today.
2. Notifikasi otomatis muncul di akun karyawan dengan emoji 🎉, judul
   "Selamat Ulang Tahun!", body mengucapkan dari tim perusahaan.
3. HR juga dapat notif "🎂 Hari ini ulang tahun: [Nama]" supaya bisa
   organisir cake atau ucapan tim.

### Skenario 2 — Anniversary kerja non-milestone

1. Karyawan sudah 2 atau 4 tahun di perusahaan (bukan milestone).
2. Notif sederhana 🎊 "Selamat anniversary X tahun" ke karyawan.
3. Atasan langsung juga dapat notif untuk acknowledge.

### Skenario 3 — Milestone 5 tahun

1. Karyawan tepat 5 tahun di perusahaan hari ini.
2. Notif 🏆 ke karyawan: "Selamat 5 Tahun Berkarya! ... milestone penting..."
3. Atasan langsung dapat notif anniversary.team.
4. **HR & admin** dapat notif khusus `anniversary.milestone_hr`:
   "Pertimbangkan untuk reward/penghargaan" — supaya tidak terlewat.

### Skenario 4 — Dashboard widget upcoming

Frontend dashboard call `GET /anniversary/upcoming?days=14` untuk widget
"Upcoming Events 2 minggu ke depan" yang menampilkan:
- 🎂 Tanggal X — [Nama] ulang tahun ke-Y
- 🏆 Tanggal Z — [Nama] anniversary 10 tahun (MILESTONE)

Membantu HR proaktif menyiapkan ucapan, kado, atau acara.

## Verifikasi

Smoke test `backend/scripts/test-anniversary-flow.php` lulus 17/17:

```
1. Setup: pakai 1 employee dengan user_id, set birthday & join_date hari ini
2. nextOccurrence: hari ini + past date logic
3. Birthday/anniversary MD match today
4. Hitung umur (30) & masa kerja (5 tahun → milestone)
5. Invoke notification logic langsung (bypass CLI dependency)
6. Verifikasi notif birthday.self & anniversary.milestone tersimpan
7. Class CheckAnniversary registered dgn run() + group/name/description
8. AnniversaryController class registered dgn upcoming() + today()
```

## Setup Cron / Task Scheduler

### Linux/Mac (crontab)

```bash
crontab -e
# Tambah:
0 8 * * * cd /var/www/hris/backend && php spark hr:check-anniversary >> /var/log/hris-anniversary.log 2>&1
```

### Windows Task Scheduler

1. Buat file `run-anniversary.bat` di backend folder:
   ```batch
   @echo off
   cd /d "D:\Apps Project\hris-project-1\backend"
   php spark hr:check-anniversary
   ```
2. Buka Task Scheduler → Create Basic Task
3. Name: "HRIS Anniversary Check"
4. Trigger: Daily at 08:00
5. Action: Start `run-anniversary.bat`

## Integrasi dengan Modul Lain

- **Notifikasi**: pakai `Services::notification()` yang sudah ada.
- **Audit Log**: setiap run tercatat dengan jumlah event.
- **Dashboard**: endpoint `upcoming` siap untuk Vue widget di
  `Dashboard.vue` (TODO: tambah card "Upcoming Birthdays/Anniversaries").

## Roadmap Selanjutnya

- **Frontend widget**: card di Dashboard menampilkan upcoming events 14 hari
  ke depan.
- **Email integration**: kirim ucapan via email (saat ini hanya notif in-app).
- **Reward template**: master template hadiah/voucher untuk milestone tertentu
  (mis. 5 tahun = voucher 500k, 10 tahun = sertifikat + voucher 1jt).
- **Public greeting card**: link share-able untuk tim ucapkan ke karyawan.
- **Reminder H-7**: reminder 7 hari sebelum milestone supaya HR siap reward.