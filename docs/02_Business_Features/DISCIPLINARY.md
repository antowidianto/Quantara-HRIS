# Surat Peringatan & Disiplin Karyawan

Dokumen ini menjelaskan modul **Disciplinary** yang ditambahkan di iterasi
roadmap fase 2 item #5.

## Latar Belakang

UU 13/2003 yang dicabut UU Cipta Kerja & PP 35/2021 mengatur bahwa PHK
karena pelanggaran berat butuh prosedur SP berjenjang (SP1 → SP2 → SP3)
dengan alasan yang dapat dibuktikan. Sebelum modul ini, HR tidak punya
mekanisme tersistem untuk:

- Generate dan menyimpan SP secara konsisten
- Tracking riwayat SP per karyawan
- Otomatis mendorong ke level berikutnya kalau pelanggaran berulang
- Bukti tanggal acknowledgement & tanggapan karyawan
- Auto-expire setelah 6 bulan

Semua sekarang ditangani.

## Apa yang Berubah

### Database

Migration `2024-01-01-000014_CreateDisciplinaryRecords.php`:

- **`disciplinary_categories`**: master kategori pelanggaran
  (`code`, `name`, `severity` MINOR/MODERATE/MAJOR/SEVERE,
  `recommended_level` VERBAL/SP1/.../TERMINATION).
- **`disciplinary_records`**: catatan SP itu sendiri
  (`level`, `reference_number`, `subject`, `description`,
  `incident_date`, `effective_date`, `expires_at`, `status`,
  `acknowledged_at`, `acknowledgment_note`, `letter_html`,
  `template_id`, `issued_by`, audit fields untuk revoke).
- 2 permission baru (`disciplinary.view`, `disciplinary.manage`),
  auto-grant ke role 1 (super admin) & 2 (HR admin) saja.
- Extend enum `contract_templates.contract_type` dengan `'SP'` & `'PHK'`
  agar template surat-surat ini bisa dikelola lewat modul Template Kontrak
  yang sudah ada.

Seeder **`DisciplinaryCategoriesSeeder`** menambah 15 kategori standar:
indisipliner, terlambat, mangkir, etika, K3, SOP, asset, integrity,
pencurian, kebocoran rahasia, narkoba, performa, insubordinasi, dst.
Idempotent, aman dijalankan ulang.

### Backend

#### Library `DisciplinaryService` (`Services::disciplinary()`)

Method | Output / Fungsi
--- | ---
`suggestNextLevel($empId)` | Saran level berikutnya berdasarkan SP aktif (SP1→SP2→SP3→TERMINATION)
`shouldSuggestTermination($empId)` | true kalau employee punya SP3 aktif (signal eligible PHK)
`nextReferenceNumber($coId, $level)` | Generate nomor unik `<LEVEL>/<COMPANY_CODE>/<YEAR>/<5-digit>`
`defaultExpiresAt($level, $effective)` | SP1/2/3 → +6 bulan; lain → null
`renderLetter($record, $templateId)` | HTML lengkap, pakai contract_templates atau fallback build-in
`summary($empId)` | active_level, active_count, timeline lengkap
`expireOverdue($coId)` | Tandai SP yg lewat tanggal jadi EXPIRED, idempotent

**Fallback template**: kalau perusahaan belum bikin template `SP`/`PHK` di
master Template Kontrak, service tetap render surat dengan layout default
(header company, body kronologi, tanda tangan).

#### Controller `DisciplinaryController`

Endpoint | Aksi
--- | ---
`GET /api/v1/disciplinary-records/categories` | List kategori
`POST /api/v1/disciplinary-records/categories` | Create kategori
`PUT/DELETE /disciplinary-records/categories/(:num)` | Update/soft-delete kategori
`GET /api/v1/disciplinary-records/employee-summary/(:num)` | Summary per karyawan
`GET /api/v1/disciplinary-records` | List dgn filter `q`, `level`, `status`, `mine`
`POST /api/v1/disciplinary-records` | Create SP (auto-render surat & notifikasi karyawan)
`GET /api/v1/disciplinary-records/(:num)` | Detail
`PUT /api/v1/disciplinary-records/(:num)` | Update (terbatas kalau sudah ACTIVE & acknowledged)
`DELETE /api/v1/disciplinary-records/(:num)` | Delete (DRAFT: hard-delete; lain: soft-delete)
`POST /api/v1/disciplinary-records/(:num)/revoke` | Batalkan SP (alasan min 10 char)
`POST /api/v1/disciplinary-records/(:num)/acknowledge` | Karyawan acknowledge + tanggapan
`GET /api/v1/disciplinary-records/(:num)/letter?format=pdf|html` | Download surat

**Authorization**:
- Pegawai biasa: hanya bisa lihat SP miliknya (lewat `mine=1`) dan bisa
  acknowledge SP-nya sendiri.
- HR/admin (`disciplinary.view`/`manage`): full akses.
- `revoke`/`update`/`delete` butuh `disciplinary.manage`.

**Validasi**:
- Karyawan harus dari company yg sama (kecuali super admin)
- Level harus salah satu enum (VERBAL/SP1/SP2/SP3/SUSPEND/TERMINATION)
- SP yg sudah ACTIVE + acknowledged tidak boleh diubah substansi-nya
  (harus dipakai endpoint revoke kalau memang harus dibatalkan)
- Snapshot HTML letter di-update otomatis kalau ada perubahan substantif

**Audit log**: setiap aksi (create/update/revoke/acknowledge/delete)
ter-log di `audit_logs` dengan `module=HR`, severity `WARNING` untuk
issue/revoke, `NOTICE` untuk acknowledge.

**Notifikasi**: saat SP dibuat, notifikasi otomatis ke user karyawan dengan
title sesuai level (`Surat Peringatan 1`, `PHK`, dst).

### Frontend

`frontend/src/views/HR/Disciplinary.vue`:

- **Daftar SP** dengan filter cari/level/status, paginasi.
- **Badge level** berwarna: SP1 kuning, SP2 oranye, SP3 merah, PHK merah pekat.
- **Badge status**: Draft/Aktif/Berakhir/Dibatalkan + indikator
  "Diakui pada ..." kalau sudah acknowledged.
- **Action per row**:
  - Download Surat (PDF)
  - Edit (kalau status DRAFT)
  - Saya menerima (untuk karyawan yg ybs)
  - Batalkan (HR, dengan modal alasan min 10 char)
  - Hapus (kalau DRAFT)
- **Modal create/edit**: dropdown karyawan + level + kategori (rekomendasi
  level di-show saat pilih), subject, uraian, tanggal kejadian/berlaku.
  Saat level berubah, `expires_at` auto-set ke +6 bulan untuk SP atau
  dikosongkan untuk VERBAL/SUSPEND/TERMINATION.
- **Toast warning** kalau target karyawan sudah punya SP3 aktif: "Karyawan
  ini sudah memiliki SP3 aktif. Pelanggaran berikutnya memenuhi syarat
  untuk diproses sebagai PHK sesuai PP 35/2021."

Sidebar: Group **HR** → **Surat Peringatan** (visible kalau punya
`disciplinary.view`).

Router: `/hr/disciplinary`.

## Workflow

### Skenario 1 — HR menerbitkan SP1

1. HR buka **HR → Surat Peringatan**, klik **Terbitkan SP Baru**.
2. Pilih karyawan, pilih kategori (mis. "Terlambat berulang" — sistem
   menyarankan level SP1).
3. Isi subject + uraian + tanggal kejadian. `Berlaku sampai` otomatis
   terisi 6 bulan dari hari ini.
4. Pilih status **Active** → klik Simpan.
5. Sistem:
   - Generate `reference_number` (mis. `SP1/PT-ABC/2026/00012`)
   - Render surat ke HTML (snapshot) dgn template SP perusahaan atau
     fallback build-in
   - Kirim notifikasi ke akun karyawan (judul "Anda menerima Surat Peringatan 1")
   - Audit log dengan severity WARNING
6. HR klik tombol **Download Surat** → PDF siap dicetak/ditanda-tangani.

### Skenario 2 — Karyawan acknowledge SP

1. Karyawan buka **HR → Surat Peringatan** (dia hanya bisa lihat SP-nya
   sendiri).
2. Klik tombol **Saya menerima** (icon CheckBadge).
3. Modal konfirmasi muncul → klik OK.
4. Sistem set `acknowledged_at = now`, audit log severity NOTICE.

### Skenario 3 — Pelanggaran berulang → SP2 → SP3

1. Karyawan yg sudah dapat SP1 melakukan pelanggaran lagi dalam masa SP1
   aktif.
2. HR buka modal create SP → pilih karyawan tersebut.
3. Sistem default level ke SP1, tapi `suggestNextLevel` bisa dipakai
   front-end untuk menyarankan SP2 (saat ini implementasi default form
   tetap SP1; HR pilih SP2 manual).
4. Saat save, kalau setelah ini total SP3 ≥ 1, response API menyertakan
   `warning` field — toast otomatis menampilkan: "Karyawan ini sudah
   memiliki SP3 aktif. Pelanggaran berikutnya memenuhi syarat untuk
   diproses sebagai PHK sesuai PP 35/2021."

### Skenario 4 — HR membatalkan SP karena keliru

1. HR buka SP, klik tombol **Batalkan** (icon NoSymbol).
2. Modal muncul minta alasan ≥ 10 karakter.
3. Isi alasan → konfirmasi.
4. Status berubah jadi REVOKED, kolom `revoked_by`, `revoked_at`,
   `revocation_reason` ter-isi. Audit log WARNING.

### Skenario 5 — Auto-expire setelah 6 bulan

`DisciplinaryService::expireOverdue()` bisa dipanggil dari command harian
yang sudah ada (`hr:check-expiry`) — di iterasi berikutnya. Untuk sekarang
bisa dipanggil manual:

```php
\Config\Services::disciplinary()->expireOverdue();
```

Atau biarkan service `summary()` yang dipanggil saat user buka halaman SP
men-expire SP karyawan tersebut on-the-fly (sudah implemented).

## Verifikasi

Smoke test `backend/scripts/test-disciplinary-flow.php` lulus 27/27:

```
1. Schema check (4 ✓)
2. Setup test
3. Reference number generator (2 ✓) — format SP1/PT-CODE/YEAR/00001
4. defaultExpiresAt (3 ✓) — SP+6mo, VERBAL/TERM null
5. Create SP1 → suggestNextLevel = SP2 ✓
6. Create SP2 → suggestNextLevel = SP3 ✓
7. Create SP3 → shouldSuggestTermination=true ✓
8. Summary: active_level=SP3, active_count >= 3 ✓
9. Render letter HTML berisi judul + nama + reference ✓
10. Render PHK letter berisi "PEMUTUSAN HUBUNGAN KERJA" + UU 6/2023 ✓
11. expireOverdue idempotent ✓
```

## Migrasi Database & Seeding

```bash
cd backend
php spark migrate
php spark db:seed DisciplinaryCategoriesSeeder
```

Tabel `disciplinary_records` dibuat kosong; `disciplinary_categories`
diisi 15 kategori per company aktif.

## Roadmap Selanjutnya

- **#6 Severance Calculator (Pesangon, UPMK, UPH)** — workflow lanjutan
  setelah TERMINATION untuk hitung kewajiban perusahaan sesuai PP 35/2021
  (sudah masuk roadmap fase 2).
- Auto-trigger workflow approval ke direksi sebelum SP3 berlaku.
- Filter "Karyawan dengan SP aktif ≥ 1" di Employee List untuk monitoring HR.