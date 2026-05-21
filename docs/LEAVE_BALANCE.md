# Leave Balance — Carry-over, Prorate & Team Calendar

Dokumen ini menjelaskan modul saldo cuti yang ditambahkan di iterasi roadmap
fase 1 item #2.

## Latar Belakang

Sebelumnya tabel `leave_balances` sudah ada (`allocated`, `used`, `remaining`,
`carried_over`, `expires_at`), tapi tidak ada:

- Logika **prorate** quota tahun pertama berdasarkan `join_date`.
- Cron / command **carry-over** akhir tahun.
- Konfigurasi per `leave_types` untuk membedakan cuti yang boleh di-carry vs
  yang reset penuh.
- Cara melihat siapa cuti di rentang tanggal (penting bagi manager sebelum
  approve cuti karyawan agar tidak bentrok).

Sekarang semuanya tersedia.

## Apa yang Berubah

### Database

Migration `2024-01-01-000012_EnhanceLeaveBalance.php` menambah 4 kolom di
`leave_types`:

| Kolom | Tipe | Default | Tujuan |
|---|---|---|---|
| `is_prorated` | tinyint | 1 | Quota tahun pertama proporsional dari join_date |
| `is_carry_over_allowed` | tinyint | 0 | Sisa boleh di-carry ke tahun depan |
| `carry_over_max_days` | decimal(6,2) | NULL | Cap hari yang di-carry (NULL = tanpa batas) |
| `carry_over_expires_in_months` | int | 6 | Carry-over kedaluwarsa setelah X bulan |

Default untuk kategori `ANNUAL`: `is_carry_over_allowed=1`, `max_days=6`,
`expires_in_months=6` (sesuai UU 13/2003 Pasal 79 ayat 5).

3 permission baru:
- `leave_balance.view` — lihat detail saldo karyawan lain
- `leave_balance.manage` — kelola saldo (manual override)
- `leave.team_calendar` — akses kalender cuti tim

### Library `LeaveBalanceService`

Single source of truth untuk perhitungan saldo. Lokasi:
`backend/app/Libraries/LeaveBalanceService.php`. Service registered sebagai
`Services::leaveBalance()`.

Method utama:

| Method | Kegunaan |
|---|---|
| `computeProratedQuota($employee, $type, $year)` | Hitung quota proporsional. Return `{quota, prorate_factor, months_worked, is_prorated}` |
| `ensureBalance($employeeId, $typeId, $year)` | Auto-create row balance kalau belum ada. Idempotent. |
| `runYearEndCarryOver($companyId, $fromYear, $toYear, $dryRun)` | Carry-over massal akhir tahun |
| `recalculate($employeeId, $year)` | Re-prorate semua balance (mis. setelah HR ubah default_days) |
| `summary($employeeId, $year)` | Output siap-pakai untuk UI dengan info carry-over + expiry |

### Endpoint API

```
GET    /api/v1/leave-balances/(:num)?year=YYYY
       Saldo lengkap dengan info prorate & carry-over
       Authorization: pegawai boleh lihat dirinya; HR butuh
       leave_balance.view atau leave.view

GET    /api/v1/leaves/team-calendar?branch_id=&department_id=&start_date=&end_date=
       Daftar cuti PENDING + APPROVED di rentang tanggal
       Default rentang: 30 hari ke depan, max 180 hari
```

### Command CLI

```bash
# Default: dari tahun lalu ke tahun ini, semua company aktif
php spark hr:leave-yearend

# Rentang custom
php spark hr:leave-yearend --from 2025 --to 2026

# Hanya 1 company
php spark hr:leave-yearend --company 1

# Dry-run (lihat dampak tanpa menulis)
php spark hr:leave-yearend --dry-run
```

Cron yang disarankan (jalan otomatis tiap 1 Januari jam 01:00):

```cron
0 1 1 1 *  cd /path/to/backend && php spark hr:leave-yearend >> writable/logs/leave-yearend.log 2>&1
```

Setiap eksekusi tercatat di audit log dengan `module=LEAVE`,
`action=leave.year_end_carryover`.

### Frontend

Dua halaman baru:

| Path | Komponen | Untuk |
|---|---|---|
| `/leaves/balance` | `Leaves/LeaveBalance.vue` | Pegawai lihat saldonya, HR pilih karyawan via dropdown |
| `/leaves/team-calendar` | `Leaves/TeamCalendar.vue` | Manager/HR lihat siapa cuti di tim (swimlane) |

Sidebar `AppLayout.vue` ditambah:
- Group **Saya** → "Saldo Cuti"
- Group **HR** → "Kalender Cuti Tim" (perm `leave.team_calendar`)

## Cara Kerja Prorate

```
factor = months_worked_in_year / 12
quota  = round(default_days × factor, 1)
```

Aturan `months_worked`:
- `join_date < year-01-01` → factor 1.0 (full year)
- `join_date > year-12-31` → 0.0 (belum bekerja)
- `join_date` di tengah tahun → `(12 - join_month + 1)` bulan, **dikurangi 1**
  jika hari join > 15 (anggap bulan itu tidak full)

Contoh: default 12 hari
- Join 15 Mei 2026 → bulan ke-5, hari 15 → 8 bulan → 12 × 8/12 = **8.0**
- Join 25 Mei 2026 → bulan ke-5, hari 25 → 7 bulan → 12 × 7/12 = **7.0**
- Join 1 Januari 2026 → 12 bulan → **12.0**
- Join 1 Oktober 2026 → 3 bulan → **3.0**

## Cara Kerja Carry-over

Untuk setiap karyawan aktif × leave_type dengan `is_carry_over_allowed=1`:

```
old_remaining = balance[from_year].remaining
carried       = min(old_remaining, carry_over_max_days)
expired       = old_remaining - carried
expires_at    = (to_year - 01-01) + carry_over_expires_in_months months

balance[to_year] = {
    allocated:    prorated_quota_for(to_year),
    carried_over: carried,
    used:         0,
    remaining:    allocated + carried,
    expires_at:   expires_at
}
```

UI menandai carry-over yang `expires_at < today` dengan warna merah +
tooltip "kadaluarsa".

## Skenario Penggunaan

### Skenario 1 — Hire pertengahan tahun

Karyawan hire 15 Mei 2026, default cuti tahunan 12 hari.

1. Saat aplikasi pertama kali dipanggil `/leave-balances/{id}`, service
   auto-create entry dengan `allocated = 8.0` (8/12 × 12).
2. Karyawan tahu saldonya 8 hari, bukan 12 hari penuh.

### Skenario 2 — Sisa cuti akhir tahun

Akhir 2026 karyawan sisa 7 hari cuti tahunan, max carry 6.

1. Cron `php spark hr:leave-yearend` jalan 1 Jan 2027.
2. Balance 2027 dibuat: `allocated=12, carried_over=6, remaining=18,
   expires_at=2027-07-01`.
3. 1 hari yang di atas cap (`7 - 6 = 1`) hangus, dicatat di output command.
4. Setelah 1 Juli 2027, kalau `carried_over` belum dipakai, UI menampilkannya
   sebagai "kadaluarsa" (tetap di DB untuk audit, tidak otomatis dihapus).

### Skenario 3 — Manager mau approve cuti

1. Manager buka `/leaves/team-calendar`, filter departmentnya.
2. Lihat swimlane: "Si A cuti 12-15 Mei (Tahunan, APPROVED)".
3. Karyawan B mengajukan cuti 14 Mei → manager bisa lihat ada bentrok dan
   menolak / mengkoordinasikan.

## Verifikasi

Smoke test `backend/scripts/test-leave-balance-flow.php` lulus:

```
1. Prorate quota test
  ✓ join 2026-01-01 → quota=12.0 factor=1.00
  ✓ join 2026-05-15 → quota=8.0  factor=0.67
  ✓ join 2026-10-01 → quota=3.0  factor=0.25
  ✓ join 2021-01-01 → quota=12.0 factor=1.00

2. ensureBalance idempotent test
  ✓ Panggil 2x kembalikan ID yang sama

3. Year-end carry-over dry run
  Carried: 4 balance, total 24 hari, expired 24 hari
    - Budi Santoso | sisa 12.0 → carry 6.0, exp 2027-07-01
    - Dewi Lestari | sisa 12.0 → carry 6.0, exp 2027-07-01
    - Agus Saputra | sisa 12.0 → carry 6.0, exp 2027-07-01

4. summary() output
  Cuti Tahunan         alloc=12 carry=0 used=0 remain=12
  ...lengkap untuk semua tipe...
```

## Catatan Implementasi

- **`LeaveController::balance` di-refactor** memakai `LeaveBalanceService::summary()`
  alih-alih query langsung. Auto-create entry yang hilang (idempotent).
- **`LeaveController::approve` belum di-refactor** karena logikanya sudah benar
  (decrement balance + apply ke attendance). Kalau mau pakai service, ganti
  blok decrement dengan `Services::leaveBalance()->ensureBalance(...)` lalu
  update `used` & `remaining`. Trade-off: tetap pakai existing supaya
  kompatibel dengan handler yang sudah jalan di production.
- **Authorization** di `balance()`: pegawai boleh lihat dirinya sendiri; HR
  butuh `leave_balance.view` atau `leave.view`.

## Migrasi Database Existing

```bash
cd backend
php spark migrate
# Kolom baru otomatis di-set ke ANNUAL: is_carry_over_allowed=1, max_days=6, expires=6 bulan
```

Jika ingin men-trigger carry-over untuk data lama (mis. test):
```bash
php spark hr:leave-yearend --from 2025 --to 2026 --dry-run   # preview
php spark hr:leave-yearend --from 2025 --to 2026             # eksekusi