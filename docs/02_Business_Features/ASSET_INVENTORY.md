# Asset / Inventory Management

Dokumen ini menjelaskan modul **Asset / Inventory** yang ditambahkan di
iterasi roadmap fase 3 item #8.

## Latar Belakang

Setiap perusahaan punya aset (laptop, HP, kendaraan, ID card, kunci ruangan,
dll) yang sering dipinjamkan ke karyawan. Tanpa sistem, tracking-nya
mengandalkan spreadsheet atau memori HR — rentan hilang, tidak ada bukti
serah terima formal, dan bermasalah saat audit / offboarding.

Modul ini menyediakan:
- Master katalog aset dengan asset_number unik per perusahaan
- Lifecycle status: AVAILABLE → ASSIGNED → AVAILABLE/REPAIR/LOST
- Workflow assign/return dengan **BAST** (Berita Acara Serah Terima) PDF
  otomatis
- History assignment per aset
- View "aset yang dipegang karyawan" untuk diintegrasikan ke offboarding

## Apa yang Berubah

### Database

Migration `2024-01-01-000017_CreateAssetsAndAssignments.php` — 2 tabel:

**`assets`** — master katalog aset:
- `asset_number` (unik per company, format `AST-<YEAR>-<5digit>`)
- `category` (LAPTOP/DESKTOP/PHONE/VEHICLE/FURNITURE/TOOL/ID_CARD/ACCESS_CARD/OTHER)
- `name`, `brand`, `model`, `serial_number`, `description`
- `purchase_date`, `purchase_price`, `purchase_invoice`
- `condition` (NEW/GOOD/FAIR/POOR/DAMAGED)
- `status` (AVAILABLE/ASSIGNED/REPAIR/RETIRED/LOST)
- `current_assignment_id`, `current_employee_id` — cached pointer untuk
  query cepat siapa pegang aset apa
- `branch_id`, `notes`, `attachment_path`

**`asset_assignments`** — riwayat assignment:
- `asset_id`, `employee_id`, `assigned_at`, `returned_at`
- `expected_return` (untuk peminjaman sementara)
- `purpose`
- `condition_when_assigned`, `condition_when_returned`, `damage_note`
- `bast_html`, `bast_return_html` — snapshot dokumen formal
- `assigned_by`, `returned_by`, `employee_acknowledged_at`,
  `returned_acknowledged_at`

2 permission baru: `asset.view` (semua role), `asset.manage` (role 1, 2).
Auto-grant role 3-5 hanya `view`. Extend enum `contract_templates.contract_type`
dengan `BAST` agar template BAST bisa dikelola lewat modul Template Kontrak
yang sudah ada.

### Backend

#### Library `AssetService` (`Services::asset()`)

Method | Output / Fungsi
--- | ---
`nextAssetNumber($coId)` | Generate `AST-YEAR-XXXXX` unik
`assignToEmployee($assetId, $empId, $params)` | Atomic: buat assignment, update asset.status=ASSIGNED, render BAST
`returnAsset($assignmentId, $params)` | Atomic: tutup assignment, update asset.status (AVAILABLE/REPAIR/LOST), render BAST balikan
`renderBastHtml($assignment, $asset, $emp, $mode)` | HTML formal Berita Acara untuk ASSIGN atau RETURN
`listForEmployee($empId, $includeReturned)` | Aset yang sedang dipegang karyawan

**Validasi**:
- Asset harus AVAILABLE sebelum bisa di-assign (re-assign → exception)
- Tidak bisa return assignment yang sudah closed
- Asset & Employee harus dari company yang sama (kecuali super admin)

**Smart status update saat return**:
- Kondisi `LOST` → asset.status = LOST
- Kondisi `DAMAGED` → asset.status = REPAIR (perlu service)
- Kondisi NEW/GOOD/FAIR/POOR → asset.status = AVAILABLE,
  asset.condition diupdate ke kondisi terkini

#### Controller `AssetController`

11 endpoint utama:

| Endpoint | Aksi |
|---|---|
| `GET /assets` | List + filter q/status/category/employee |
| `GET /assets/(:num)` | Detail + history 50 assignment terakhir |
| `POST /assets` | Create (auto-generate asset_number) |
| `PUT /assets/(:num)` | Update field master |
| `DELETE /assets/(:num)` | Soft-delete setelah status RETIRED |
| `POST /assets/(:num)/retire` | Submit retirement/write-off untuk approval |
| `POST /assets/(:num)/assign` | Assign ke karyawan |
| `POST /assets/(:num)/return` | Terima return + tentukan kondisi |
| `GET /asset-assignments` | List assignment cross-asset dengan filter |
| `GET /asset-assignments/employee/(:num)` | Aset yg dipegang karyawan |
| `GET /asset-assignments/(:num)/bast?mode=ASSIGN\|RETURN&format=pdf\|html` | Download BAST PDF / HTML |

Audit log per aksi (modul `ASSET`). Notifikasi otomatis ke karyawan saat
aset diterima.

### Frontend

`frontend/src/views/HR/Assets.vue` — full management view:

- **Daftar aset** dengan filter cari/kategori/status/karyawan, kolom
  no aset, kategori, nama, brand/model, SN, kondisi, status, pemegang
- **Badge status** berwarna: Tersedia (hijau), Dipakai (biru),
  Reparasi (amber), Pensiun (abu), Hilang (merah)
- **Action per row**:
  - Assign (status AVAILABLE) → modal pilih karyawan + kondisi + tujuan
  - Return (status ASSIGNED) → modal kondisi + catatan kerusakan
  - Retire / Write-off (AVAILABLE/REPAIR/LOST tanpa assignment aktif) → approval disposal
  - Download BAST PDF (untuk assignment aktif)
  - Edit / Hapus
- **Form modal** Tambah/Edit aset dengan auto-generate asset_number kalau kosong
- **Assign modal** dengan dropdown karyawan, kondisi penyerahan, tanggal
  diharapkan kembali, tujuan/catatan. BAST otomatis di-generate setelah save.
- **Return modal** dengan dropdown kondisi (NEW/GOOD/FAIR/POOR/DAMAGED/LOST),
  warning kontekstual ("Asset akan otomatis di-set status REPAIR" untuk DAMAGED).

Sidebar: Group "HR" → "Aset Perusahaan" (visible kalau punya `asset.view`).

Router: `/hr/assets`.

## Workflow Skenario

### Skenario 1 — HR berikan laptop ke karyawan baru

1. HR buka **HR → Aset Perusahaan** → klik **Tambah Aset Baru**.
2. Pilih kategori LAPTOP, isi nama "Lenovo ThinkPad T14", brand, SN,
   tanggal beli, harga. Sistem auto-generate `AST-2026-00012`.
3. Status awal: AVAILABLE.
4. Klik tombol **Assign** di row aset → pilih karyawan baru, kondisi NEW,
   tujuan "Operasional harian", expected_return kosong.
5. Sistem:
   - Status asset → ASSIGNED
   - current_employee_id = karyawan tsb
   - BAST HTML formal di-generate (Pihak Pertama HR, Pihak Kedua karyawan,
     rincian aset, tanggal hari ini)
   - Notifikasi otomatis ke akun karyawan
6. Klik icon **BAST PDF** → download dokumen siap dicetak & ditandatangani.

### Skenario 2 — Karyawan resign, kembalikan laptop

1. Saat karyawan offboarding, HR buka **HR → Aset Perusahaan**, filter
   karyawan tersebut → muncul aset yang dipegang.
2. Klik tombol **Return** → modal kondisi:
   - Pilih GOOD / FAIR / POOR sesuai kondisi → status asset jadi AVAILABLE
   - Pilih DAMAGED + catatan "Layar cracked" → status REPAIR
   - Pilih LOST + catatan → status LOST (mungkin perlu klaim asuransi)
3. BAST balikan otomatis di-generate. HR cetak untuk arsip.
4. Cross-reference dengan modul **Onboarding/Offboarding**: task
   `RETURN_LAPTOP` di OFFBOARD_DEFAULT bisa di-mark COMPLETE setelah
   return ini selesai.

### Skenario 3 — Aset rusak, perlu service

Aset yang return dengan kondisi DAMAGED otomatis berstatus REPAIR. Perubahan
status lifecycle tidak dilakukan melalui Edit master; retirement memakai aksi
khusus ber-approval agar histori fisik dan finansial tidak tertimpa.

### Skenario 4 — Aset pensiun

Untuk laptop yang sudah end-of-life, custodian memakai aksi **Retire / Write-off**
dan mengisi tanggal, alasan, metode disposal, nilai buku, proceeds, serta
referensi bukti. Asset tetap pada status semula selama approval atasan,
HR/custodian, Finance, dan Director untuk nilai tinggi belum selesai. Status
`RETIRED` hanya dibentuk oleh final approval. Aset RETIRED tidak bisa di-assign lagi tapi tetap muncul di list untuk
arsip purchase history.

## Verifikasi

Smoke test `backend/scripts/test-asset-flow.php` lulus 26/26:

```
1. Schema check (3 ✓)
2. nextAssetNumber: format AST-YEAR-XXXXX
3. Create asset → status AVAILABLE
4. Assign: status ASSIGNED, BAST snapshot tersimpan, mengandung
   "BERITA ACARA SERAH TERIMA" + asset_number
5. Re-assign asset yang sudah ASSIGNED → exception
6. Return GOOD: status AVAILABLE, BAST return mengandung
   "BERITA ACARA PENGEMBALIAN"
7. Return assignment yang sudah closed → exception
8. Cycle 2: assign + return DAMAGED → status REPAIR
9. listForEmployee active vs include_returned
```

## Migrasi Database

```bash
cd backend
php spark migrate
```

`assets` & `asset_assignments` dibuat kosong. Tidak butuh seeder default —
HR/IT akan manually input aset perusahaan ke sistem.

## Integrasi dengan Modul Lain

- **Offboarding** (modul #7): task `RETURN_LAPTOP` & `RETURN_ASSETS`
  bisa di-cross-check dengan list aset karyawan via
  `GET /asset-assignments/employee/(:num)` sebelum di-mark complete.
- **Severance** (modul #6): saat finalize PHK, HR bisa cek apakah
  semua aset sudah di-return.
- **Audit Log**: setiap create/assign/return tercatat di `audit_logs`
  modul `ASSET`.

## Roadmap Selanjutnya

- Tab di profil karyawan: aset yang sedang dipegang.
- Dashboard widget: aset yang lewat `expected_return` tapi belum return.
- Bulk import aset dari CSV (untuk migrasi data dari spreadsheet existing).
- Foto aset (upload via `attachment_path`) → tampilkan di BAST.
- Integrasi otomatis dengan task `RETURN_LAPTOP` saat offboarding:
  klik return aset → mark task complete.
