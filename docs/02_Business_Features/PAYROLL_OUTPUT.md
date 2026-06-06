# Payroll Output — Bank Transfer, e-SPT PPh21, BPJS

Dokumen ini menjelaskan modul ekspor payroll yang ditambahkan di iterasi
roadmap fase 1 item #4.

## Latar Belakang

Sebelum perubahan, payroll periode bisa di-calculate dan finalize, payslip
PDF bisa diunduh, tapi belum ada **output untuk eksternal**:

- Bank: tidak ada CSV batch untuk diunggah ke iBanking
- DJP: tidak ada CSV untuk e-SPT PPh21 Masa
- BPJS: tidak ada CSV untuk SiPP Ketenagakerjaan / e-Dabu Kesehatan
- Bukti Potong: tidak ada PDF formal 1721-VI / 1721-A1 per karyawan

Semua ini sekarang tersedia.

## Apa yang Berubah

### Backend

#### Library `PayrollExporter` (`Services::payrollExporter()`)

Method | Output | Format
--- | --- | ---
`bankTransfer($periodId, $bank)` | Batch transfer ke bank | CSV semicolon, UTF-8 BOM
`esptPph21($periodId, $finalForm)` | Daftar PPh21 untuk e-SPT DJP | CSV semicolon
`bpjsKetenagakerjaan($periodId)` | Iuran JHT/JP/JKK/JKM SiPP | CSV semicolon
`bpjsKesehatan($periodId)` | Iuran 5% e-Dabu | CSV semicolon
`buktiPotongHtml($payslipId)` | Bukti Potong 1721-VI / 1721-A1 per karyawan | HTML (dikonversi ke PDF di controller)

**Bank yang didukung**: BCA, MANDIRI, BNI, BRI, CIMB, BSI, PERMATA, DANAMON.
Header CSV menyesuaikan format yang lazim per bank, sehingga file langsung
bisa diunggah ke iBanking masing-masing.

**Validasi**: semua exporter menolak periode yang belum `CALCULATED` (status
`DRAFT` akan dilempar exception).

**Encoding**: semua CSV pakai UTF-8 dengan BOM (`\xEF\xBB\xBF`) agar Excel
di Windows membuka tanpa korup karakter Indonesia.

**Sanitasi**: nama / catatan dibersihkan dari delimiter (`;`) dan newline.
NPWP & NIK di-mask jadi digit-only sesuai format DJP/BPJS.

#### Endpoint API

```
GET /api/v1/payroll-periods/(:num)/export-summary
    Ringkasan: jumlah karyawan, total THP, total PPh21, distribusi bank,
    daftar karyawan dengan data missing (rekening/NPWP/BPJS).

GET /api/v1/payroll-periods/(:num)/bank-export?bank=BCA
    Download CSV transfer batch untuk bank pilihan.

GET /api/v1/payroll-periods/(:num)/pph21-export?final=1
    Download CSV e-SPT PPh21. `final=1` → gunakan kode 1721-A1
    (untuk masa Desember). Default: 1721-VI bulanan.

GET /api/v1/payroll-periods/(:num)/bpjs-export?type=TK|KES
    Download CSV iuran BPJS. `TK` = SiPP Ketenagakerjaan, `KES` = e-Dabu.

GET /api/v1/payslips/(:num)/bukti-potong?format=pdf|html
    Download Bukti Potong PPh21 untuk satu payslip. Default PDF (via Dompdf).
```

Semua endpoint ada audit log entry dengan `module=PAYROLL`,
severity `NOTICE`.

### Frontend

#### `Payroll/PeriodDetail.vue` — tombol "Export & Setor"

Untuk periode dengan status CALCULATED/APPROVED/PAID/CLOSED muncul tombol
**Export & Setor** yang membuka dialog lengkap:

1. **Ringkasan**: 4 kartu (jumlah karyawan, total THP, total PPh21, mix bank).
2. **Warning data missing**: kalau ada karyawan tanpa rekening / NPWP / BPJS
   dan akan di-skip dari ekspor.
3. **Section Bank Transfer**: dropdown 8 bank → tombol Download CSV.
4. **Section e-SPT PPh21**: checkbox "Bukti Potong Tahunan (1721-A1)" + tombol Download.
5. **Section BPJS**: 2 tombol — SiPP Ketenagakerjaan dan e-Dabu Kesehatan.

#### `Payroll/PayslipDetail.vue` — tombol "Bukti Potong PPh21"

Di samping tombol "Download Slip PDF" sekarang ada tombol kedua untuk
generate Bukti Potong individu (PDF) per karyawan.

## Format File

### Bank transfer (BCA contoh)

```
NoRekening;Nama;BankTujuan;Jumlah;Berita
1234567890;BUDI SANTOSO;BCA;5500000;P-2026-05-EMP-001
0987654321;DEWI LESTARI;MANDIRI;6200000;P-2026-05-EMP-002
# TOTAL;15 records;;156750000;
```

### e-SPT PPh21

```
NPWP_Pemotong;Masa_Pajak;Tahun_Pajak;NPWP_Pegawai;NIK;Nama;Status_PTKP;Bruto;PPh21;Tarif_TER;Kode_Bukti_Potong
00.123.456.7-001.000;05;2026;12.345.678.9-001.000;3201234567890001;BUDI SANTOSO;TK0;5500000;55000;0.0100;1721-VI
```

### SiPP BPJS Ketenagakerjaan

```
No_KPJ;NIK;Nama;Tgl_Lahir;Upah;JHT_Karyawan;JHT_Perusahaan;JP_Karyawan;JP_Perusahaan;JKK;JKM
1234567890;3201234567890001;BUDI SANTOSO;15/03/1990;5000000;100000;185000;50000;100000;12000;15000
```

### e-Dabu BPJS Kesehatan

```
No_BPJS;NIK;Nama;Status_PTKP;Upah_Pokok;Iuran_Karyawan;Iuran_Perusahaan;Total
0001234567890;3201234567890001;BUDI SANTOSO;TK0;5000000;50000;200000;250000
```

### Bukti Potong PPh21 (HTML → PDF)

Dokumen formal dengan layout 3 bagian:
- A. Pemotong Pajak (NPWP, nama, alamat perusahaan)
- B. Penerima Penghasilan (NPWP, NIK, nama, status PTKP, alamat)
- C. Penghitungan PPh21 (bruto, TER %, PPh21 dipotong)
- Tanda tangan Pejabat Pemotong + tanggal otomatis

## Skenario Penggunaan

### Skenario 1 — HR setor payroll bulanan

1. HR jalankan **Hitung Ulang** → status periode jadi CALCULATED.
2. Klik **Finalisasi** → status PAID.
3. Klik **Export & Setor** → dialog muncul.
4. Cek warning kalau ada karyawan data belum lengkap.
5. **Bank Transfer**: pilih BCA → Download → buka file di iBanking BCA.
6. **e-SPT PPh21**: tanpa centang final → Download → impor ke aplikasi
   e-SPT DJP melalui menu Impor → CSV (delimiter `;`).
7. **BPJS Ketenagakerjaan**: Download → upload di portal SiPP.
8. **BPJS Kesehatan**: Download → upload di portal e-Dabu.

### Skenario 2 — Bukti Potong tahunan

1. Periode Desember dengan flag `is_december_correction=1` di-finalize.
2. HR pilih satu karyawan → buka payslip → klik **Bukti Potong PPh21** →
   PDF formulir 1721-A1 ter-download untuk setahun.
3. (Atau bulk: panggil endpoint export PPh21 dengan `final=1` untuk semua
   karyawan sekaligus, lalu generate per orang via UI / script.)

### Skenario 3 — Validasi sebelum upload

Sebelum benar-benar upload ke iBanking / DJP / BPJS, HR pakai
**Export Summary** sebagai sanity check:

- Jumlah karyawan match dengan ekspektasi
- Total THP cocok dengan periode
- Tidak ada karyawan dengan data missing kritis

## Verifikasi

Smoke test `backend/scripts/test-payroll-export.php` lulus 26/26:

```
1. Bank transfer CSV
  ✓ [BCA] header tidak kosong, filename ada, mime CSV
  ✓ [MANDIRI] ... ✓ [BNI] ... ✓ [BRI] ...
  ✓ Bank tidak didukung → exception
2. e-SPT PPh21 CSV
  ✓ Header punya kolom NPWP_Pemotong
  ✓ Jumlah baris data == payslip count
  ✓ Filename eSPT_PPh21_*.csv
3. SiPP BPJS Ketenagakerjaan CSV (header + filename)
4. e-Dabu BPJS Kesehatan CSV (header + filename)
5. Bukti potong HTML (BUKTI PEMOTONGAN, Penghasilan Bruto, mime, filename)
```

## Catatan Format

- Format bank dapat berubah sewaktu-waktu (mis. BCA versi .CTE 2024).
  Modifikasi `bankHeader()` dan `buildLine()` di `PayrollExporter.php`
  kalau bank Anda menerapkan format eksklusif.
- Format e-SPT yang umum diakses lewat fitur **Impor → CSV** di aplikasi
  e-SPT DJP. Untuk **e-Bupot Unifikasi 2024** yang baru, format sedikit
  berbeda — kalau Anda butuh, kita bisa tambahkan method
  `eBupotUnifikasi()` mengikuti spec PER-1/PJ/2023.
- SiPP BPJS-TK juga punya format upload XML (PER1721) untuk mutasi
  karyawan. Implementasi kita fokus pada CSV iuran bulanan, yang lebih
  sering dipakai HR.

## Migrasi Database

Tidak ada migration baru — modul ini hanya menambah library, controller
methods, dan endpoint. Cukup pull kode terbaru.