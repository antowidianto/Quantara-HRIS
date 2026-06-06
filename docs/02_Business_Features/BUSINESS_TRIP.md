# Business Trip / SPPD (Surat Perjalanan Dinas)

Dokumen ini menjelaskan modul **Business Trip / SPPD** yang ditambahkan
di iterasi roadmap fase 3 item #9.

## Latar Belakang

Perusahaan butuh formal proses untuk perjalanan dinas:
- Karyawan request → atasan/HR setujui → keuangan keluarkan advance
- Karyawan kembali → upload bukti pengeluaran (transport, akomodasi, makan, dsb.)
- Settlement: actual vs advance — kalau tombok perlu reimburse, kalau lebih harus dikembalikan
- Surat Perintah Perjalanan Dinas (SPPD) formal untuk arsip & perjalanan

Tanpa sistem, tracking lewat email + spreadsheet, sering lupa settle, advance
nyangkut tanpa pertanggungjawaban.

## Apa yang Berubah

### Database

Migration `2024-01-01-000018_CreateBusinessTrips.php` — 2 tabel:

**`business_trips`** — header SPPD:
- `reference_number` (unik, format `SPPD/<COMPANY>/<YEAR>/<5digit>`)
- Karyawan, tujuan (kota + DOMESTIC/INTERNATIONAL), maksud
- `start_date`, `end_date`, `duration_days` (auto-computed)
- `transport_mode` (PESAWAT/KERETA/BUS/MOBIL_DINAS/MOBIL_PRIBADI/KAPAL/OTHER)
- `accommodation`
- `advance_amount`, `actual_amount`, `settlement_amount` (auto-hitung)
- `status` lifecycle: DRAFT → PENDING → APPROVED → IN_PROGRESS → COMPLETED → SETTLED
  (REJECTED/CANCELLED = terminal)
- Snapshot `sppd_html` setelah approve
- `reimbursement_id` — link ke reimbursement otomatis kalau ada selisih

**`business_trip_expenses`** — line items pengeluaran:
- `category` (TRANSPORT/ACCOMMODATION/MEAL/DAILY_ALLOWANCE/FUEL/PARKING/TOLL/OTHER)
- `expense_date`, `description`, `amount`
- `receipt_path` (foto/scan bukti)
- `is_billable` (0 = pengeluaran pribadi, tidak ditagihkan)

4 permission baru:
- `business_trip.view` — semua role; pegawai biasa hanya lihat miliknya sendiri
- `business_trip.manage` — HR (role 1, 2): create untuk orang lain, edit, delete
- `business_trip.approve` — HR + manager (role 1, 2, 3): approve/reject pending
- `business_trip.settle` — finance (role 1, 5): finalkan settlement

### Backend

#### Library `BusinessTripService` (`Services::businessTrip()`)

Method | Output
--- | ---
`nextReferenceNumber($coId)` | Generate `SPPD/<CODE>/<YEAR>/<5digit>` unik
`computeDurationDays($start, $end)` | Hitung lama hari (inklusif)
`submit($tripId)` | DRAFT → PENDING; trigger approval chain (BUSINESS_TRIP) kalau ada, defensive try/catch
`approve($tripId)` | PENDING → APPROVED; render & simpan snapshot SPPD; notif karyawan
`reject($tripId, $reason)` | PENDING → REJECTED; notif karyawan
`recomputeActual($tripId)` | Sum amount line items billable, update header
`markCompleted($tripId)` | APPROVED/IN_PROGRESS → COMPLETED; recompute actual
`settle($tripId)` | COMPLETED → SETTLED; hitung selisih actual − advance, auto-buat reimbursement kalau positif
`cancel($tripId, $reason)` | Cancel kapan saja kecuali SETTLED/CANCELLED/REJECTED
`renderSppdHtml($tripId)` | Surat Perintah Perjalanan Dinas formal

**Logika settlement**:
- `actual > advance` → karyawan tombok → buat row `reimbursements` otomatis
  dengan `category=BUSINESS_TRIP`, amount=selisih, status=PENDING
- `actual < advance` → karyawan kelebihan advance → `settlement_amount` negatif
  sebagai indikator. Finance bisa potong gaji manual atau pakai modul Loan.
- `actual = advance` → tidak ada aksi.

#### Controller `BusinessTripController`

17 endpoint:

| Endpoint | Aksi |
|---|---|
| `GET /business-trips` | List + filter q/status/employee/mine, scoped per-user |
| `POST /business-trips` | Buat draft |
| `GET /business-trips/(:num)` | Detail + line items expense |
| `PUT /business-trips/(:num)` | Update (DRAFT only) |
| `DELETE /business-trips/(:num)` | Soft-delete (DRAFT/REJECTED/CANCELLED) |
| `POST /business-trips/(:num)/submit` | DRAFT → PENDING |
| `POST /business-trips/(:num)/approve` | PENDING → APPROVED |
| `POST /business-trips/(:num)/reject` | PENDING → REJECTED |
| `POST /business-trips/(:num)/start` | APPROVED → IN_PROGRESS |
| `POST /business-trips/(:num)/complete` | → COMPLETED + recompute actual |
| `POST /business-trips/(:num)/settle` | COMPLETED → SETTLED + auto-reimburse |
| `POST /business-trips/(:num)/cancel` | Cancel kapan saja kecuali terminal |
| `GET /business-trips/(:num)/sppd?format=pdf\|html` | Download SPPD PDF |
| `GET /business-trips/(:num)/expenses` | List line items |
| `POST /business-trips/(:num)/expenses` | Add expense |
| `PUT /business-trip-expenses/(:num)` | Update expense |
| `DELETE /business-trip-expenses/(:num)` | Delete expense |

Authorization layered: pegawai biasa hanya akses milik sendiri; HR/manager
sesuai permission. Audit log per aksi (modul `BUSINESS_TRIP`).

### Frontend

`frontend/src/views/Travel/TripList.vue`:

- **List dengan filter** cari/status/karyawan/mine-only, badge status berwarna 8-tier
- **Action per row** kontekstual:
  - DRAFT → Submit, Edit, Hapus
  - PENDING → (approver) Approve / Reject
  - APPROVED → Mulai (start), Selesai, SPPD PDF
  - IN_PROGRESS → Selesai, SPPD PDF
  - COMPLETED → (finance) Settle, SPPD PDF
  - SETTLED → SPPD PDF (read-only)
  - REJECTED/CANCELLED → Hapus
- **Click row → detail modal** dengan ringkasan advance/actual/settlement,
  list line items expense, tombol tambah/hapus expense
- **Form modal** ajukan SPPD: maksud, tujuan, tanggal, transportasi, advance
- **Add expense modal** dengan kategori, tanggal, amount, checkbox is_billable
- **Reject modal** dengan validasi alasan ≥ 5 karakter
- **Settle confirm dialog** dengan preview selisih dan implikasinya

Sidebar: Group "HR" → "Perjalanan Dinas".

Router: `/travel`.

## Workflow Skenario

### Skenario 1 — Perjalanan dinas normal, actual = advance

1. Karyawan ajukan SPPD ke Surabaya, advance Rp 5,000,000.
2. Submit → Manager approve. SPPD PDF tersedia untuk dicetak.
3. Karyawan berangkat, kumpulkan kuitansi.
4. Setelah pulang, upload semua bukti (tiket Rp 2,800,000 + hotel Rp 1,800,000 + makan Rp 400,000 = total Rp 5,000,000).
5. Mark Completed → actual_amount = 5,000,000.
6. Finance Settle → settlement = 0, tidak ada aksi keuangan tambahan.

### Skenario 2 — Karyawan tombok, perlu reimbursement

1. Sama dengan skenario 1, tapi actual = Rp 5,200,000 (lebih Rp 200,000).
2. Settle → sistem otomatis:
   - `settlement_amount = +200,000`
   - Buat row `reimbursements` baru dengan amount Rp 200,000, kategori BUSINESS_TRIP, status PENDING
   - Karyawan bisa lihat di menu "Reimbursement"
3. Finance approve reimbursement → masuk payroll periode berikutnya.

### Skenario 3 — Advance kelebihan, harus dikembalikan

1. Advance Rp 5,000,000, tapi karyawan cuma habis Rp 3,000,000.
2. Settle → `settlement_amount = -2,000,000`.
3. UI nge-display warning "Karyawan harus kembalikan Rp 2,000,000".
4. Finance pilih opsi:
   - Karyawan transfer balik manual
   - Atau dipotong gaji → buat row `loans`/manual deduction

### Skenario 4 — Pengeluaran pribadi non-billable

Saat trip, karyawan beli oleh-oleh Rp 200,000 pakai uang muka. Pas input
expense, uncheck "Tagihkan ke perusahaan". Sistem **tidak** masukkan ke
actual_amount, jadi karyawan tetap harus kembalikan Rp 200,000 itu.

## Verifikasi

Smoke test `backend/scripts/test-business-trip-flow.php` lulus 33/33:

```
1. Schema check (3 ✓)
2. nextReferenceNumber: format SPPD/<CODE>/<YEAR>/<5digit>
3. computeDurationDays: 1, 3, 7 hari + edge case end<start
4. Create draft trip → status DRAFT
5. submit() DRAFT → PENDING; resubmit → exception
6. approve() PENDING → APPROVED; SPPD HTML snapshot terisi & valid
7. Add 4 expenses (3 billable + 1 pribadi) → recompute = 5,200,000
8. markCompleted() → COMPLETED, actual = 5,200,000
9. Settle case underpay: actual > advance → reimbursement otomatis Rp 200,000
10. Cycle 2 settle case overpay: actual < advance → settlement negatif, no reimbursement
11. Cancel; Cancel SETTLED → exception
```

## Migrasi Database

```bash
cd backend
php spark migrate
```

Kosong sampai karyawan ajukan trip.

## Integrasi dengan Modul Lain

- **Reimbursement**: settlement positif otomatis create row reimbursement
  dengan category `BUSINESS_TRIP` & description berisi referensi SPPD.
- **Approval Engine**: `submit()` mencoba trigger chain modul `BUSINESS_TRIP`.
  Kalau company belum konfigurasi chain, fallback ke approval manual via
  `business_trip.approve` permission.
- **Audit Log**: setiap transition (submit/approve/reject/settle/cancel)
  tercatat di modul `BUSINESS_TRIP`.
- **Notification**: karyawan dapat notif saat approve/reject.

## Roadmap Selanjutnya

- Upload foto bukti via `receipt_path` (saat ini stored, perlu UI upload).
- Master `business_trip_rates` per grade × destination_type untuk auto-suggest
  advance amount (uang harian × hari + standar transportasi).
- Integrasi langsung ke payroll: settlement positif/negatif otomatis jadi
  earning/deduction di period terkait.
- Dashboard widget: SPPD pending settlement > 7 hari setelah completion.