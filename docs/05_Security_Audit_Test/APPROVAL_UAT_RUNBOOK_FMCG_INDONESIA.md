# Runbook UAT Approval HRIS — FMCG Indonesia

**Versi:** 16 Juli 2026  
**Tujuan:** memperoleh sign-off bisnis bahwa konfigurasi, otorisasi, keputusan,
dan side effect seluruh approval berjalan sesuai tata kelola perusahaan.

## 1. Gate sebelum UAT

Jalankan dari direktori `backend`:

```bash
php spark migrate:status
php spark approval:readiness --company=1
php vendor/bin/phpunit
```

UAT hanya dimulai bila readiness menghasilkan `failures=0` dan regression test
lulus. Gunakan tenant dan data khusus UAT. Jangan memakai transaksi payroll atau
data pribadi karyawan produksi.

## 2. Akun dan pemisahan tugas

Siapkan akun aktif yang berbeda untuk:

- employee/requester;
- supervisor dan department head;
- HR Admin/HRBP, HR C&B/Payroll, dan HR Director;
- Finance dan CFO;
- CEO/authorized director;
- IT Admin/Security serta Legal/Compliance atau DPO;
- delegate sementara;
- QA observer tanpa permission approve.

Requester, approver terakhir, dan pelaksana pembayaran/disbursement wajib akun
berbeda. Jangan menguji maker-checker menggunakan Super Admin kecuali skenario
break-glass tersendiri.

## 3. Matriks transaksi

| Domain | Skenario minimum | Jalur yang dibuktikan | Side effect final |
|---|---|---|---|
| Leave | pendek dan >3 hari/exception | Supervisor; tambah HR sesuai kondisi | saldo dan attendance tepat sekali |
| Overtime | normal dan hari libur/threshold | Supervisor; HR/manager sesuai chain | kompensasi atau time-off tepat sekali |
| Attendance change | periode terbuka dan locked | Supervisor → HR | koreksi diterapkan; locked tidak ditimpa |
| Reimbursement | di bawah dan di atas threshold | Supervisor → Finance | plafon benefit terdebit sekali |
| Loan | nominal normal dan exception | Supervisor → HR → Finance | jadwal cicilan terbentuk sekali |
| Business trip | domestik dan overseas/advance | Supervisor → Dept Head → Finance | SPPD/snapshot dan settlement konsisten |
| Bonus | normal dan >Rp100 juta | HR → Finance → CEO | run siap disburse; maker ≠ disburser |
| Payroll adjustment | normal dan >Rp10 juta | HR Payroll → Finance → CFO | adjustment baru dapat diposting setelah final |
| Compensation review | dalam dan di luar threshold | HR C&B → Finance → HR Director | salary history dijadwalkan sekali |
| Training | internal, external, dan mahal | Supervisor → Dept Head → HR L&D → Finance/Director | budget di-reserve/dilepas konsisten |
| Timesheet | normal dan bertanda overtime | Supervisor; tambah Dept Head | status final tanpa duplikasi jam |
| Candidate offer | dalam dan di luar salary band | Hiring Manager → HR → Finance/CEO | offer menjadi SENT hanya setelah final |
| Generated contract | normal dan perubahan material | HR → Finance → HR Director | dokumen final immutable |
| Asset transaction | standar dan high value/write-off | Custodian/HR → Finance → CFO | assignment/retirement tercatat sekali |
| Hire conversion | normal dan policy exception | HR → HR Director | employee/user dibuat atau diaktifkan sekali |
| Performance goal | goal baru/revisi | Manager sesuai chain | goal aktif hanya setelah final |
| IDP activation | normal dan talent nomination | Manager → HR Talent | IDP aktif hanya setelah final |
| Employee sensitive change | bank/NPWP/NIK dan login | HR → Finance/IT sesuai field | data sensitif berubah tanpa bocor di log |
| Master rate change | update dan delete | Finance → CFO | rate efektif sesuai tanggal dan audit trail |
| Privileged action | RBAC, PII export, integration secret | Security → Compliance/IT | token approval single-use dan scoped |

Workflow khusus MPP, JIC/mutasi/promosi, resignation, termination, dan payroll
period tetap diuji melalui endpoint domain masing-masing karena memiliki side
effect khusus di luar generic engine.

## 4. Skenario lintas modul wajib

Untuk sedikitnya satu transaksi finansial dan satu non-finansial, catat bukti
berikut:

1. Submit dua kali menghasilkan satu request PENDING yang sama.
2. Requester tidak muncul sebagai approver dan `canApprove` bernilai false,
   termasuk bila requester adalah Super Admin.
3. User tanpa assignment tidak dapat approve maupun reject.
4. Reject dengan alasan kurang dari lima karakter ditolak; alasan valid
   menutup workflow dan domain.
5. Delegate aktif dapat memutuskan dan original approver tercatat; delegasi
   expired, lintas tenant, atau lintas modul ditolak.
6. Dua keputusan bersamaan hanya menghasilkan satu pemenang.
7. Perubahan chain/struktur setelah submit tidak mengubah snapshot request.
8. Perubahan material pada data domain setelah submit memicu conflict atau
   pembatalan, bukan silent overwrite.
9. Finalisasi ulang ditolak dan side effect hanya terjadi sekali.
10. Notification/webhook outbox memakai event ID stabil dan retry tidak
    mengulang side effect.
11. Chain BLOCK tanpa kandidat tetap PENDING/fail-closed.
12. Cancel menutup keputusan PENDING dan domain kembali ke status yang benar.

## 5. Bukti dan sign-off

Untuk setiap skenario simpan: nomor transaksi, request ID, chain/version hash,
urutan approver, decision ID, actor asli/delegate, waktu, alasan, status domain
sebelum/sesudah, outbox event ID, dan screenshot UI tanpa data sensitif.

Sign-off minimum:

| Pemilik | Cakupan |
|---|---|
| HR Operations/HR Director | lifecycle, attendance, talent, kontrak |
| Payroll/C&B | payroll adjustment, bonus, compensation, master rate |
| Finance/CFO | threshold, cash impact, disbursement separation |
| Legal/IR/Compliance | termination, kontrak, PDP/PII exception |
| IT Security | RBAC, integration secret, audit evidence |
| QA/Product Owner | regression, concurrency, usability, traceability |

Temuan severity Critical/High atau ketidaksesuaian saldo, payroll, akses, dan
data pribadi menghentikan go-live. Medium harus memiliki owner dan target waktu;
Low dapat masuk backlog setelah disetujui Product Owner dan pemilik kontrol.

## 6. Baseline otomatis saat dokumen dibuat

Pada 16 Juli 2026:

- migration approval sampai `2026-09-01-000048` sudah diterapkan;
- readiness company 1: 20 default chain, `failures=0`;
- supervisor 69/70 dengan satu root valid (Komisaris Utama), department head
  70/70, hiring manager 0/0 karena belum ada posting OPEN/PAUSED;
- PHPUnit: 1.246 test, 3.725 assertion, seluruhnya lulus;
- regression khusus approval: 30 test, 185 assertion, seluruhnya lulus;
- PHPStan pada engine dan test yang berubah: tidak ada error.

Baseline otomatis bukan sign-off pengguna. Kolom bukti bisnis dan persetujuan
para pemilik kontrol tetap harus dilengkapi selama sesi UAT.
