# Allowance Mapping, Payslip PDF Fix & UX Polish

Iterasi ini menambahkan tiga area:

1. **Pemetaan tunjangan multi-cabang & multi-perusahaan** dengan threshold
   jarak (default 30 km) dan tarif berbeda per job grade.
2. **Perbaikan download PDF slip gaji** — sebelumnya gagal karena `window.open`
   tidak meneruskan JWT, sekarang fetch lewat axios sebagai blob.
3. **Standarisasi UX**: semua dialog konfirmasi/peringatan pakai modal
   konsisten, detail karyawan dibuka sebagai modal yang **tidak menutup
   saat klik luar**, edit terintegrasi di modal yang sama untuk HR & super
   admin.

---

## 1. Pemetaan Tunjangan

### Skema data

| Tabel | Tujuan |
|---|---|
| `allowance_types` | Definisi tipe tunjangan per perusahaan (kode, nama, kategori, default threshold). |
| `allowance_rates` | Tarif per `(allowance_type, job_grade, branch)`. Branch/grade boleh `NULL` sebagai fallback. Field: `threshold_km`, `rate_below`, `rate_above`. |
| `employee_allowance_assignments` | Snapshot per karyawan setelah perhitungan jarak: `distance_km`, `eligible`, `amount`, `manual_override`. |

Tambah dua kolom kecil di `employees`:
- `home_lat`, `home_lng` — koordinat rumah karyawan untuk perhitungan
  Haversine.

### Aturan perhitungan

- Distance dihitung dengan **Haversine** dari `(home_lat, home_lng)` ke
  `(branches.lat, branches.lng)`.
- `eligible = distance >= threshold_km`. Threshold default 30 km, tapi
  bisa di-override per `allowance_rates`.
- `amount = eligible ? rate_above : rate_below` (umumnya `rate_below = 0`).
- Saat HR mengubah lokasi rumah / cabang / grade karyawan, sistem
  otomatis recompute snapshot. Saat hitung payroll, `PayrollService`
  juga recompute defensif sebelum membaca assignment.
- HR boleh **override manual** (misalnya angka khusus untuk satu
  karyawan); flag `manual_override=1` mencegah recompute otomatis.

### Pemilihan tarif (priority)

Saat satu karyawan memenuhi banyak rate, sistem memilih yang paling
spesifik:

1. **branch_id cocok + job_grade_id cocok** (skor 15)
2. branch_id cocok + grade NULL (skor 11)
3. branch_id NULL + grade cocok (skor 6)
4. branch_id NULL + grade NULL (skor 2) — fallback company-wide

### Endpoint Backend

```
GET    /allowance-types                                daftar tipe
POST   /allowance-types                                tambah tipe
PUT    /allowance-types/{id}                           ubah
DELETE /allowance-types/{id}                           hapus

GET    /allowance-rates?allowance_type_id=...          daftar tarif
POST   /allowance-rates                                tambah tarif
PUT    /allowance-rates/{id}                           ubah
DELETE /allowance-rates/{id}                           hapus

GET    /employee-allowances/{employeeId}               snapshot karyawan
POST   /employee-allowances/{employeeId}/recompute     recompute manual
PUT    /employee-allowances/{id}                       override (HR)
POST   /allowances/recompute-company                   recompute seluruh
                                                       karyawan dlm company
```

Permission: tulis butuh `payroll.manage`. Tenant scope: super-admin
bebas, user lain otomatis di-scope ke `company_id` mereka. HR cabang
lain tidak bisa cross-check ke perusahaan/cabang lain (sama dengan
aturan attendance).

### Integrasi Payroll

`PayrollService::calculateEmployee` sekarang:
1. Memanggil `Services::allowance()->recomputeEmployee($empId)` untuk
   memastikan snapshot terbaru.
2. Memanggil `earningsForPayroll($empId)` untuk mengambil semua
   tunjangan yang aktif (amount > 0) sebagai earnings.
3. Tunjangan yang `is_taxable=1` ikut dihitung dalam grossTaxable
   sehingga PPh 21 / TER otomatis menyesuaikan.

### UI

- **`/master/allowances`** — halaman manajemen 2 tab: tipe tunjangan
  & tarif per grade/cabang. Tombol "Hitung Ulang Semua" di kanan atas
  untuk trigger recompute company-wide.
- **Modal karyawan** punya tab **Tunjangan** yang menampilkan snapshot
  lengkap (jarak, threshold, eligible, amount) plus tombol "Hitung
  Ulang" per karyawan.
- **Modal karyawan** juga punya field koordinat rumah (`home_lat`,
  `home_lng`) di tab "Alamat & Lokasi".

---

## 2. Fix Download PDF Slip Gaji

**Masalah lama:** `window.open('/api/v1/payslips/123/pdf', '_blank')`
membuka tab baru, tapi browser tidak meneruskan header `Authorization:
Bearer <jwt>`. Backend mengembalikan `401 UNAUTHENTICATED` dan PDF
tidak muncul.

**Solusi:** fetch lewat axios dengan `responseType: 'blob'`, lalu
buat object URL untuk download programatik.

```js
const res = await api.get(`/payslips/${id}/pdf`, { responseType: 'blob' })
const blob = new Blob([res.data], { type: 'application/pdf' })
const url  = URL.createObjectURL(blob)
const a    = document.createElement('a')
a.href = url
a.download = slipNumber + '.pdf'
a.click()
URL.revokeObjectURL(url)
```

Bonus: kalau backend mengembalikan JSON error (mis. forbidden), kode
mendeteksi via `Content-Type` dan menampilkan pesannya.

File yang diubah: `frontend/src/views/Payroll/PayslipDetail.vue`.

---

## 3. UX Polish

### 3.1 Dialog konfirmasi terpadu

Dibuat `useConfirm.js` + `<ConfirmDialog />` global di `App.vue`.
Tiga API:

```js
import { confirm, alertDialog, promptDialog } from '@/composables/useConfirm'

await confirm({ title, message, okText, variant: 'danger' })  // boolean
await alertDialog({ title, message })                          // void
await promptDialog({ title, inputLabel, inputType: 'textarea' }) // string|null
```

Variant: `primary` | `danger` | `warning` | `success` — mempengaruhi
warna ikon & tombol OK.

Semua `window.confirm()` dan `window.prompt()` di view berikut sudah
diganti:

- `composables/useResource.js` (delete row)
- `components/SimpleMaster.vue` (delete row)
- `views/Leaves/LeaveList.vue` (approve / reject)
- `views/Overtimes/OvertimeList.vue` (approve / reject)
- `views/Admin/Users.vue` (deactivate)
- `views/Payroll/PeriodList.vue` (calculate / finalize)
- `views/Payroll/PeriodDetail.vue` (calculate / finalize)
- `views/Attendance/MyChangeRequests.vue` (cancel)

Modal SimpleMaster & form admin user juga di-rewrite agar **tidak
menutup saat klik luar** (hanya tombol Batal / X).

### 3.2 Detail karyawan modal

`EmployeeDetail.vue` + `EmployeeForm.vue` digabung jadi satu komponen
**`EmployeeModal.vue`** dengan:

- Tab: Umum, Kepegawaian, Gaji & Pajak, Alamat & Lokasi, Tunjangan,
  Kontrak, Saldo Cuti.
- Mode: `view` (default) & `edit`. Toggle via tombol **Edit** di header,
  tampil hanya untuk user dengan permission `employee.update` /
  super-admin.
- **Tidak menutup saat klik luar** — hanya tombol Tutup / X / Batal.
- Tombol **Nonaktifkan** muncul untuk user dengan `employee.delete`,
  langsung memunculkan ConfirmDialog warning.
- Mode `create` (klik tombol "Tambah Karyawan") menampilkan tab Umum
  dengan opsi "Buat akun login otomatis" + email akan dikirim password
  sementara.

`EmployeeList.vue` sekarang membuka modal langsung; URL legacy
(`/employees/:id`, `/employees/:id/edit`, `/employees/new`) tetap
berfungsi karena route dialihkan ke list yang otomatis membuka modal
sesuai path.

---

## Test Otomatis

```
php backend/scripts/test-allowance-flow.php   # 20/20 pass
php backend/scripts/test-attendance-flow.php  # 10/10 pass (dari iterasi sebelumnya)
php backend/scripts/test-attendance-api.php   # 15/15 pass
```

Mencakup:

- Schema (tabel, kolom, FK)
- Haversine accuracy (Jakarta → Bandung ≈ 122 km)
- Eligibility: jauh → eligible; dekat → tidak; tanpa koordinat → tidak
- Snapshot tersimpan & di-update saat koordinat berubah
- Branch isolation (HR cabang lain ditolak)
- PDF blob path (manual; verifikasi: tombol download bekerja saat
  user terotentikasi)

---

## Migrasi & Build

```
php spark migrate                  # menambah tabel allowance + 2 kolom employees
php spark db:seed InitialSeeder    # idempotent (tidak menambah permission baru)

cd frontend && npm run build       # sukses, allowance & employee bundle baru
```

## File Baru

```
backend/app/Database/Migrations/2024-01-01-000009_CreateAllowanceMapping.php
backend/app/Libraries/AllowanceService.php
backend/app/Controllers/Api/V1/AllowanceController.php
backend/scripts/test-allowance-flow.php

frontend/src/components/ConfirmDialog.vue
frontend/src/composables/useConfirm.js
frontend/src/views/Employees/EmployeeModal.vue
frontend/src/views/Master/Allowances.vue
docs/ALLOWANCE_AND_UX.md
```

## File Diperbarui

```
backend/app/Config/Services.php                 (+ allowance service)
backend/app/Config/Routes.php                   (+ 11 route)
backend/app/Libraries/PayrollService.php        (integrate allowance)
backend/app/Controllers/Api/V1/EmployeeController.php
                                                (+ home_lat/lng + auto recompute)

frontend/src/App.vue                            (mount ConfirmDialog)
frontend/src/composables/useResource.js         (confirm pakai modal)
frontend/src/components/SimpleMaster.vue        (no outside close + confirm)
frontend/src/views/Leaves/LeaveList.vue         (confirm + prompt)
frontend/src/views/Overtimes/OvertimeList.vue   (confirm + prompt)
frontend/src/views/Admin/Users.vue              (confirm + no outside close)
frontend/src/views/Payroll/PeriodList.vue       (confirm)
frontend/src/views/Payroll/PeriodDetail.vue     (confirm)
frontend/src/views/Payroll/PayslipDetail.vue    (PDF via axios blob)
frontend/src/views/Attendance/MyChangeRequests.vue (confirm)
frontend/src/views/Employees/EmployeeList.vue   (rewrite: open modal)
frontend/src/router/index.js                    (+ allowances route)
frontend/src/layouts/AppLayout.vue              (+ allowances menu)