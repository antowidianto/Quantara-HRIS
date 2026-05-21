# Severance Calculator (Pesangon, UPMK, UPH)

Dokumen ini menjelaskan modul **Severance Calculator** yang ditambahkan
di iterasi roadmap fase 2 item #6.

## Latar Belakang

UU 6/2023 (Cipta Kerja) dan PP 35/2021 mengatur bahwa setiap PHK harus
dihitung dengan komponen yang spesifik tergantung **alasan PHK**:

- **Pesangon (Uang Pesangon)** — Pasal 40 ayat 2: 1-9 bulan upah berdasarkan masa kerja
- **UPMK (Uang Penghargaan Masa Kerja)** — Pasal 40 ayat 3: 0-10 bulan untuk masa kerja ≥ 3 tahun
- **UPH (Uang Penggantian Hak)** — Pasal 40 ayat 4: cuti tertunda, biaya pulang, hak lain
- **Uang Pisah** — Pasal 50: untuk RESIGN / pelanggaran berat / mangkir
- **Multiplier** sesuai alasan: 0.5×, 1.0×, 1.75×, 2.0×, dst.

Sebelum modul ini, HR harus hitung manual lewat Excel — rentan salah kali,
salah multiplier, dan tidak ter-audit. Sekarang sistem hitung otomatis,
preview real-time, simpan ke DB dengan workflow DRAFT→FINALIZED→PAID, dan
generate dokumen formal PDF.

## Apa yang Berubah

### Database

Migration `2024-01-01-000015_CreateSeveranceCalculations.php`:

- **`severance_calculations`** — snapshot perhitungan per karyawan dgn
  semua komponen di-freeze (multiplier, masa kerja, gaji, semua nilai).
- Field penting: `termination_reason`, `last_monthly_salary`, `service_years`,
  `pesangon_*`, `upmk_*`, `uph_*`, `separation_pay`, `total_amount`,
  `status` (DRAFT/FINALIZED/PAID/CANCELLED).
- FK ke `disciplinary_records` (opsional) — kalau PHK akibat pelanggaran
  yang ada SP3 sebelumnya.
- 2 permission baru: `severance.view`, `severance.manage`. Auto-grant ke
  role 1 (super admin) & 2 (HR admin).

### Backend

#### Library `SeveranceCalculator` (`Services::severance()`)

Method | Output / Fungsi
--- | ---
`pesangonMonths($years)` | 1-9 bulan sesuai Pasal 40 ayat 2
`upmkMonths($years)` | 0-10 bulan sesuai Pasal 40 ayat 3
`computeServiceYears($joinDate, $endDate)` | Masa kerja decimal
`calculate($params)` | Breakdown lengkap dgn pesangon, UPMK, UPH, total
`renderBreakdown($result, $emp, $company)` | HTML formal untuk PDF
`reasonOptions()` | Daftar 17 alasan PHK untuk dropdown UI

**17 alasan PHK terdaftar** dengan multiplier sesuai PP 35/2021:

| Slug | Pesangon | UPMK | Catatan |
|---|---|---|---|
| EFFICIENCY_LOSS | 0.5× | 1.0× | Pasal 43 |
| EFFICIENCY_PREVENT | 1.0× | 1.0× | Pasal 43 |
| MERGER_NO_CONTINUE | 1.0× | 1.0× | Pasal 41 |
| TAKEOVER_NO_CONTINUE | 1.0× | 1.0× | Pasal 41 |
| TAKEOVER_NEW_TERMS | 0.5× | 1.0× | Pasal 42 |
| CLOSURE_LOSS_2YR | 0.5× | 1.0× | Pasal 44 |
| CLOSURE_NO_REASON | 1.0× | 1.0× | Pasal 44 |
| FORCE_MAJEURE | 0.75× | 1.0× | Pasal 45 |
| PKPU_BANKRUPTCY | 0.5× | 1.0× | Pasal 46 |
| PENSION_RETIRE | 1.75× | 1.0× | Pasal 56 |
| DEATH | 2.0× | 1.0× | Pasal 57 |
| DISABILITY_PROLONGED | 2.0× | 1.0× | Pasal 55 |
| RESIGN | — | — | UPH+uang pisah, Pasal 50 |
| VIOLATION_GROSS | — | — | UPH+uang pisah, Pasal 52 |
| LONG_ABSENT_PROHIBIT | — | — | UPH+uang pisah, Pasal 50 |
| END_OF_PKWT | — | — | Kompensasi proporsional 1bln/12bln |
| CONTRACT_EXPIRY | — | — | Tidak ada pesangon |

#### Controller `SeveranceController`

Endpoint | Aksi
--- | ---
`GET /api/v1/severance/reasons` | Daftar alasan PHK (untuk UI dropdown)
`POST /api/v1/severance/calculate` | **Preview** perhitungan tanpa simpan ke DB
`GET /api/v1/severance` | List dgn filter (employee_id, status)
`POST /api/v1/severance` | Simpan sebagai DRAFT
`GET /api/v1/severance/(:num)` | Detail
`PUT /api/v1/severance/(:num)` | Update DRAFT (re-kalkulasi otomatis)
`DELETE /api/v1/severance/(:num)` | Hapus DRAFT (hard delete)
`POST /api/v1/severance/(:num)/finalize` | DRAFT → FINALIZED + snapshot HTML
`POST /api/v1/severance/(:num)/mark-paid` | FINALIZED → PAID
`POST /api/v1/severance/(:num)/cancel` | DRAFT/FINALIZED → CANCELLED (alasan ≥ 10 char)
`GET /api/v1/severance/(:num)/breakdown?format=pdf|html` | Download dokumen rincian

**Auto-default**:
- `last_monthly_salary` dari `employee.basic_salary + fixed_allowance`
- `service_years` dari `employee.join_date` ke `termination_date`
- `unused_leave_days` dari `leave_balances` ANNUAL tahun PHK

**Audit log**: setiap aksi (calculate/save/finalize/markPaid/cancel/delete)
ter-log dengan severity sesuai (NOTICE untuk hitung, CRITICAL untuk
finalize/markPaid, WARNING untuk cancel).

### Frontend

`frontend/src/views/HR/Severance.vue`:

- **Daftar perhitungan** dengan filter karyawan & status, kolom
  Pesangon/UPMK/UPH/Total, badge status berwarna.
- **Action per row**:
  - Download PDF rincian (selalu tersedia)
  - Finalize (status DRAFT, icon CheckCircle)
  - Mark Paid (status FINALIZED, icon Banknotes)
  - Cancel (status DRAFT/FINALIZED, dengan prompt alasan)
- **Modal Calculator** — 2 kolom:
  - **Kiri**: form input (karyawan, tanggal PHK, alasan, gaji,
    masa kerja, sisa cuti, biaya pulang, hak lain, uang pisah, catatan).
    Field gaji/masa kerja/sisa cuti otomatis terisi dari profile karyawan
    saat preview pertama kali.
  - **Kanan**: live preview tabel rincian dgn total + info masa kerja
    & alasan PHK.
  - **Indikator multiplier**: saat alasan dipilih, muncul box biru
    menjelaskan multiplier Pesangon/UPMK + warning kalau alasan tipe
    UPH+uang pisah saja.
  - **Auto re-preview** saat karyawan/tanggal/alasan berubah.
  - Tombol "Simpan sebagai DRAFT" mengirim ke `POST /severance`.

Sidebar: Group **HR** → **Pesangon / Severance** (visible kalau punya `severance.view`).

Router: `/hr/severance`.

## Workflow Skenario

### Skenario 1 — PHK Efisiensi (5 thn, gaji 10jt)

1. HR buka **HR → Pesangon / Severance** → klik **Hitung Severance Baru**.
2. Pilih karyawan, tanggal PHK, alasan = "PHK Efisiensi (perusahaan rugi)".
3. Sistem auto-fill gaji 10jt, masa kerja 5 tahun, sisa cuti 0.
4. Preview muncul:
   - Pesangon: 6 bulan × 0.5 × 10jt = **30jt**
   - UPMK: 2 bulan × 1.0 × 10jt = **20jt**
   - **Total: 50jt**
5. Simpan sebagai DRAFT → review → klik Finalize → klik Mark Paid setelah transfer.

### Skenario 2 — Pensiun (8 thn, gaji 12jt)

- Pesangon: 9 bulan × 1.75 × 12jt = **189jt**
- UPMK: 3 bulan × 1.0 × 12jt = **36jt**
- **Total: 225jt** (sebelum pajak)

Catatan footer di breakdown menjelaskan PPh21 atas pesangon dipotong
terpisah sesuai PMK 16/2010 (progresif 0%/5%/15%/25%).

### Skenario 3 — Resign (4 thn, gaji 6jt, sisa cuti 5 hari)

- Pesangon: 0 (tidak berhak)
- UPMK: 0
- UPH cuti: 5 × (6jt/22) = **1.36jt**
- Uang pisah: 3jt (sesuai PKB perusahaan)
- **Total: 4.36jt**

### Skenario 4 — Akhir PKWT (24 bulan, gaji 5jt)

UU 6/2023 mengatur kompensasi PKWT proporsional: 1 bulan upah per 12 bulan
masa kerja.
- Kompensasi: 24/12 × 5jt = **10jt** (diparkir di field pesangon)
- UPH cuti: sesuai sisa cuti tahunan
- **Total**: kompensasi + UPH

### Skenario 5 — Integrasi dengan SP

Saat HR membuat severance untuk karyawan yang punya catatan SP, frontend
bisa pre-fill `disciplinary_record_id` (link FK). Backend menerima field
ini dan men-link severance ke pelanggaran tertentu untuk audit trail
yang utuh: SP1 → SP2 → SP3 → PHK + perhitungan severance.

## Verifikasi

Smoke test `backend/scripts/test-severance-flow.php` lulus 41/41:

```
1. Schema check (2 ✓)
2-3. Tabel pesangon & UPMK Pasal 40 (10 ✓)
4. computeServiceYears (1 ✓)
5. EFFICIENCY_LOSS 5 thn 10jt → Total 50jt ✓
6. PENSION_RETIRE 8 thn 12jt → Total 225jt ✓
7. DEATH 3 thn 8jt → Pesangon 64jt + UPMK 16jt ✓
8. RESIGN 4 thn 6jt → UPH 1.36jt + uang pisah 3jt ✓
9. END_OF_PKWT 24 bln 5jt → 10jt ✓
10. UPH cuti formula (daily rate × hari) ✓
11. Render breakdown HTML lengkap (UU 6/2023, semua section) ✓
12. Alasan invalid → exception ✓
13. DB workflow (insert DRAFT → finalize → cleanup) ✓
```

## Catatan Implementasi

- **Snapshot freezing**: saat finalize, semua nilai (multiplier, gaji,
  masa kerja, total) dan `breakdown_html` di-freeze ke DB. Kalau regulasi
  berubah di masa depan (mis. multiplier disesuaikan), perhitungan lama
  tetap valid.
- **PPh21 atas pesangon**: tidak otomatis dikurangkan dari `total_amount`.
  Footer dokumen menyebutkan tarif final progresif PMK 16/2010. Kalau Anda
  ingin auto-deduct, tambah method `calculatePph21Pesangon($total)` di
  service dan tampilkan terpisah.
- **Workflow approval**: untuk audit lebih ketat, finalize bisa dijadikan
  request approval lewat `ApprovalEngine` dengan modul `SEVERANCE`.
  Belum diimplementasi — bisa ditambah di iterasi berikutnya.
- **Integrasi payroll**: setelah `mark-paid`, ideal-nya muncul entry di
  `payslips` atau `payroll_periods` khusus. Untuk sekarang HR catat
  sebagai PAID secara manual setelah transfer.

## Migrasi Database

```bash
cd backend
php spark migrate
```

Tabel `severance_calculations` dibuat kosong. Tidak perlu seeder.

## Roadmap Selanjutnya

- **#7 Onboarding & Offboarding Checklist** — workflow lanjutan dari
  PHK (offboarding tasks: kembalikan asset, tutup akses, exit interview,
  dst). Sinergis dengan severance ini.
- Auto-trigger workflow approval ke direksi sebelum finalize.
- Integrasi dengan payroll: setelah PAID, catat di `payslips`.
- Auto-deduct PPh21 atas pesangon mengikuti tarif final PMK 16/2010.