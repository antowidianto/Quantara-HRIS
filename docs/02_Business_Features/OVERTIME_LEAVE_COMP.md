# Lembur → Cuti Pengganti

Dokumen ini menjelaskan modul **Overtime Leave Compensation** (kompensasi
lembur dalam bentuk cuti pengganti) yang ditambahkan di iterasi roadmap
fase 3 item #10.

## Latar Belakang

Banyak perusahaan memberi opsi karyawan: jam lembur boleh ditukar jadi
**cuti pengganti** alih-alih dibayar uang. Praktik umum:
- 1 jam lembur = 1 jam cuti (rasio 1.0, default)
- Bisa juga 1.5:1 (lebih menguntungkan perusahaan) atau 0.5:1 (1 jam OT = 2 jam cuti, sangat jarang)
- Ada cap maksimal hari yang bisa dikumpulkan per tahun (default 12 hari)

Modul ini reuse infrastruktur **Overtime** + **Leave Balance** yang sudah
ada — tidak perlu tabel baru, hanya beberapa kolom dan satu leave_type
khusus per perusahaan.

## Apa yang Berubah

### Database

Migration `2024-01-01-000019_OvertimeLeaveCompensation.php`:

**`overtimes` — kolom baru**:
- `compensation_type` VARCHAR(16) default `CASH` — `CASH` atau `LEAVE`
- `leave_hours_credited` DECIMAL(8,2) — jam cuti yang ditambahkan saat approve LEAVE
- `leave_balance_id` INT — pointer ke `leave_balances` row yang dikredit
- `leave_conversion_ratio` DECIMAL(4,2) — rasio yang dipakai (snapshot)

**`leave_types` — kolom baru**:
- `source` VARCHAR(20) default `STANDARD` — `STANDARD` atau `OVERTIME_COMP`

**Seed otomatis per perusahaan**:
- Leave type `CPL` (Cuti Pengganti Lembur) dengan `source=OVERTIME_COMP`,
  default 0 hari, allocated bertambah otomatis saat lembur LEAVE disetujui.

**Settings default per company** (di tabel `settings`):
- `overtime_to_leave_enabled` = `1` (bisa di-disable kalau perusahaan tidak mengizinkan)
- `overtime_to_leave_ratio` = `1.0` (1 jam OT × ratio = jam cuti)
- `overtime_to_leave_max_days_year` = `12` (cap maksimal per tahun)

### Backend

**`OvertimeController::create`** — extend:
- Terima field `compensation_type` (CASH default).
- Kalau LEAVE tapi `overtime_to_leave_enabled=0` di settings → fallback ke CASH.
- Field disimpan di `overtimes`.

**`OvertimeController::approve`** — branching logic:
- **CASH** (default, behaviour lama): hitung amount pakai `OvertimeCalculator` (PP 35/2021), simpan di `computed_amount`.
- **LEAVE**: 
  1. Baca rasio dari settings (default 1.0)
  2. Jam cuti = `hours × ratio`
  3. Cari `leave_types` dengan `source=OVERTIME_COMP` untuk company
  4. Auto-create `leave_balances` row tahun berjalan kalau belum ada
  5. Cek cap `max_days_year`: kalau total accumulated + jam baru melebihi cap → capped ke yang tersisa, kalau cap penuh → 422 error
  6. Convert jam ke hari (8 jam = 1 hari), tambah ke `allocated` & `remaining`
  7. Simpan `leave_balance_id`, `leave_hours_credited`, `leave_conversion_ratio` di overtime
  8. `computed_amount = 0` (tidak bayar uang)
- Notifikasi karyawan dengan pesan kontekstual.
- Audit log: deskripsi mencakup mode (CASH/LEAVE).

### Frontend

**`Overtimes/OvertimeForm.vue`** — extend:
- Toggle radio button **"Bentuk kompensasi"** dengan 2 card:
  - 💵 **Dibayar Uang** — penjelasan tarif PP 35/2021
  - 🌴 **Cuti Pengganti** — preview "X hari (rasio 1:1)"
- Live calculation total jam saat input start_time/end_time
- Field `compensation_type` dikirim ke API saat submit

Saldo Cuti CPL otomatis muncul di **`Leaves/LeaveBalance.vue`** karena
view existing sudah loop semua leave_types yang punya balance.

## Workflow Skenario

### Skenario 1 — Lembur dibayar uang (default)

1. Karyawan pilih **💵 Dibayar Uang** saat ajukan lembur.
2. Manager approve → `OvertimeCalculator` hitung amount (PP 35/2021).
3. Amount masuk ke payroll periode terkait sebagai komponen lembur.

### Skenario 2 — Lembur dikonversi ke cuti

1. Karyawan lembur 4 jam, pilih **🌴 Cuti Pengganti**.
2. Manager approve → sistem:
   - 4 jam × ratio 1.0 = 4 jam cuti
   - 4 jam ÷ 8 jam-per-hari = 0.5 hari cuti
   - `leave_balances` CPL tahun berjalan: `allocated += 0.5`, `remaining += 0.5`
   - Karyawan dapat notif: "Lembur Anda disetujui dan dikonversi ke 4 jam cuti pengganti"
3. Karyawan bisa pakai saldo CPL untuk ajukan cuti seperti tipe cuti lain.

### Skenario 3 — Cap mencapai batas

1. Karyawan sudah accumulated 11.5 hari CPL tahun ini, ajukan lembur 8 jam (= 1 hari) dengan LEAVE.
2. Approve → sistem capped jadi 0.5 hari saja (cap 12 hari).
3. `leave_hours_credited = 4` (bukan 8). Tidak error, tapi karyawan dapat sebagian.

### Skenario 4 — Cap sudah penuh

1. CPL allocated sudah = 12 hari (max).
2. Karyawan ajukan lembur lagi LEAVE.
3. Approve → 422 error: "Quota cuti pengganti tahun 2026 sudah mencapai max 12 hari."
4. Manager perlu reject atau minta karyawan ubah ke CASH.

## Verifikasi

Smoke test `backend/scripts/test-overtime-leave-comp-flow.php` lulus 22/22:

```
1. Schema check (kolom + leave_type + settings)
2. Setup: leave type CPL ada, settings ratio terisi
3. Create overtime LEAVE
4. Approve LEAVE: leave_hours_credited = 4, balance.allocated = 0.5,
   computed_amount = 0, leave_balance_id ter-set
5. Create overtime CASH (default): approve hitung amount, leave_balance_id null
6. Test ratio 0.5: 2 jam OT → 1 jam cuti ✓
7. Test cap: 10 jam OT capped ke 8 jam (1 hari max) ✓
8. Cap penuh: approve berikutnya → error "quota max" ✓
```

## Migrasi Database

```bash
cd backend
php spark migrate
```

Otomatis insert leave_type CPL + 3 settings default per company.

## Konfigurasi Company

Admin/HR bisa atur di settings (UI Settings di frontend, atau langsung
di tabel `settings`):

| Key | Default | Deskripsi |
|---|---|---|
| `overtime_to_leave_enabled` | `1` | Set `0` untuk disable opsi LEAVE |
| `overtime_to_leave_ratio` | `1.0` | 1 jam OT × ratio = jam cuti |
| `overtime_to_leave_max_days_year` | `12` | Cap maksimal hari per tahun |

## Integrasi dengan Modul Lain

- **Overtime**: tidak ada perubahan di OvertimeList / inbox — flow sama.
- **Leave**: balance CPL muncul di `LeaveBalance.vue` dan bisa dipakai
  di `LeaveForm.vue` seperti tipe cuti lain. Saat karyawan ambil cuti
  CPL, `used` bertambah otomatis seperti cuti biasa.
- **Payroll**: lembur LEAVE tidak menghasilkan komponen earning di payroll
  (karena `computed_amount = 0`). Lembur CASH tetap masuk seperti biasa.
- **Audit Log**: setiap approve mencatat mode (CASH/LEAVE) di description.

## Roadmap Selanjutnya

- UI di Settings page untuk atur 3 settings di atas (saat ini perlu via
  API atau langsung DB).
- Reminder di overtime detail: "Saldo CPL Anda saat ini: X hari" sebelum submit.
- Expiry date untuk CPL (opsional — saat ini reset_yearly + tidak carry over).
- Convert balik (cuti pengganti → uang) untuk karyawan yang akan resign.