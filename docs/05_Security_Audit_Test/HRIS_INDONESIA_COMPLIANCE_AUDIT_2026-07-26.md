# Audit Alur dan Kepatuhan HRIS Indonesia

**Tanggal audit teknis:** 26 Juli 2026  
**Repository:** Quantara HRIS  
**Cakupan:** backend, frontend, mobile, database, migration, seed, workflow, keamanan, dan dokumentasi  
**Status:** remediasi teknis berjalan; **belum merupakan opini hukum atau sertifikasi kepatuhan**  
**Acuan tracker:** `INDONESIA_COMPLIANCE_REMEDIATION_TRACKER_2026-07.md`

> Kesimpulan penting: repository memiliki cakupan HRIS Indonesia yang luas,
> tetapi keberadaan menu, calculator, atau unit test tidak otomatis berarti
> patuh produksi. Angka payroll, pemutusan hubungan kerja, pelindungan data,
> dan hak minimum pekerja tetap memerlukan legal review, UAT, rekonsiliasi
> dengan sumber resmi, serta sign-off pemilik proses.

---

## 1. Ringkasan eksekutif

Audit menemukan fondasi end-to-end yang sudah baik:

- employee master, riwayat efektif, mutasi, kontrak, onboarding, dan offboarding;
- attendance, shift, roster, koreksi, lembur, WFH, dan business trip;
- leave balance dan approval;
- payroll, TER PPh 21, BPJS, THR, bonus, natura, PKWT, reimbursement, loan,
  variance, reconciliation, approval, finalization, dan disbursement;
- recruitment, MPP, performance, training, succession, dan compensation;
- RBAC, tenant isolation, approval engine, audit log, privacy, backup, queue,
  webhook, mobile, serta SaaS administration.

Ketidaksesuaian hukum/data yang terkonfirmasi dan diremediasi pada audit ini:

1. JKP/JKK masih menggunakan basis PP 37/2021, bukan PP 6/2025.
2. JKP berpotensi dihitung sebagai employer cost tambahan (double count).
3. payroll tidak mengirim status hubungan kerja ke calculator BPJS.
4. koreksi PPh 21 menggunakan bulan berjalan × 12 dan menutup refund ke nol.
5. payroll selalu menggunakan pola lima hari untuk tier lembur.
6. cuti melahirkan masih memiliki hard-limit 90 hari dan belum memodelkan
   fase hak UU KIA.
7. retensi dokumen pajak ditulis lima tahun, bukan sepuluh tahun.
8. kalender libur nasional 2026 salah/tidak lengkap.
9. angka estimasi upah minimum tampak seperti master resmi.

Remediasi langsung yang dilakukan tercantum di Bagian 4. Gap yang membutuhkan
keputusan organisasi/legal atau implementasi lintas-modul tetap tercantum di
Bagian 5 dan **tidak boleh dianggap selesai**.

---

## 2. Sumber hukum dan kebijakan acuan

| Area | Acuan utama | Implementasi yang diharapkan |
|---|---|---|
| Hubungan kerja | UU 13/2003 sebagaimana diubah, UU 6/2023, PP 35/2021 | PKWT/PKWTT, waktu kerja, lembur, PHK, kompensasi |
| Putusan konstitusional | Putusan MK 168/PUU-XXI/2023 | workflow perselisihan dan final legal gate PHK |
| Kesejahteraan ibu dan anak | UU 4/2024 | maternity, miscarriage, paternity, kondisi khusus, fase upah |
| THR | Permenaker 6/2016 dan ketentuan/surat edaran tahun berjalan | eligibility, basis upah, prorata, tenggat pembayaran |
| Pengupahan | PP 36/2021 jo. PP 51/2023 dan keputusan gubernur | UMP/UMK/UMSP/UMSK, struktur dan skala upah |
| BPJS Kesehatan | Perpres 82/2018 jo. Perpres 64/2020 dan perubahan berlaku | basis upah, plafon, bagian pekerja/pemberi kerja |
| BPJS Ketenagakerjaan | aturan JHT/JP/JKK/JKM dan PP 6/2025 untuk JKP | rate efektif, eligibility, plafon, snapshot aturan |
| PPh 21 | PMK 168/2023, UU PPh, aturan pelaksana DJP | TER bulanan dan koreksi masa pajak terakhir aktual |
| Natura | PMK 66/2023 | taxable/non-taxable benefit dan threshold |
| Retensi pajak | UU KUP Pasal 28 ayat (11) | penyimpanan buku/catatan/dokumen selama 10 tahun |
| Pelindungan data | UU 27/2022 | dasar pemrosesan, hak subjek, DPIA, insiden, retensi |
| Hari libur | SKB 3 Menteri 2026 | 17 hari libur nasional, cuti bersama terpisah |
| Perusahaan | UU 40/2007 | data badan hukum dan struktur kewenangan |
| Administrasi tenaga kerja | WLKP, PP/PKB, aturan BPJS/pajak terkait | bukti registrasi dan kalender pelaporan |

Sumber hukum harus dipantau oleh Legal/Tax/HR. Nilai seperti plafon JP,
UMP/UMK, libur, surat edaran THR, dan parameter tahunan tidak boleh diasumsikan
tetap.

---

## 3. Peta alur HRIS Indonesia yang diharapkan

### 3.1 Organization dan master data

1. tenant/perusahaan dibuat;
2. legal entity, NPWP, alamat, zona waktu, mata uang, cabang, lokasi kerja,
   cost center, department, position, job title, grade, dan reporting line
   ditetapkan;
3. master legal-rate diimpor dari sumber resmi dengan tanggal efektif,
   referensi, approval, dan versi;
4. role dan permission dipisahkan berdasarkan maker, checker, approver,
   auditor, dan subject/self-service;
5. perubahan sensitif menggunakan effective history, approval, dan audit log.

**Kondisi repo:** tersedia, tetapi master upah minimum belum memiliki lifecycle
otoritatif lengkap. Seeder estimasi kini ditandai
`NON_AUTHORITATIVE_DEMO_ONLY`.

### 3.2 Manpower planning dan recruitment

Alur ideal:

`MPP request → budget/organization approval → vacancy/posting → candidate →
screening/test/interview → offer approval → candidate acceptance → hire
approval → employee conversion → onboarding plan`

Kontrol wajib:

- kebutuhan tenaga kerja dan anggaran;
- non-discrimination dan minimisasi data kandidat;
- approval offer/compensation;
- consent/legal basis pemrosesan kandidat;
- retensi kandidat dan penghapusan;
- tidak ada hire conversion tanpa approval dan data kontrak lengkap.

**Kondisi repo:** service dan approval workflow tersedia. UAT lintas MPP,
recruitment, offer, onboarding, employee master, payroll, dan BPJS masih perlu
bukti operasional.

### 3.3 Employee lifecycle

Alur ideal:

`hire → contract → probation (PKWTT saja) → active employment → transfer/JIC →
performance/development → disciplinary process → resignation/termination →
clearance → final payroll → paklaring → deactivation/retention`

Kontrol Indonesia:

- PKWT tidak boleh menjalani probation;
- kompensasi PKWT dibayar saat berakhir sesuai masa kerja;
- mutation/transfer memakai effective date;
- disciplinary process konsisten dengan PP/PKB;
- PHK disengketakan tidak boleh dieksekusi hanya dengan approval internal;
- hak final payroll, cuti, THR, kompensasi, pesangon, dan paklaring dihitung.

**Kondisi repo:** alur utama tersedia. Final legal gate pasca Putusan MK 168
belum dinyatakan selesai; tetap P0 pada tracker.

### 3.4 Attendance, shift, dan lembur

Alur ideal:

`effective work pattern → roster → attendance capture → anomaly/correction →
approval → overtime plan → realization → approval → payroll`

Ketentuan utama:

- waktu kerja normal 40 jam/minggu;
- pola lima dan enam hari harus dibedakan;
- lembur hari kerja: jam pertama 1,5×, berikutnya 2×;
- hari istirahat/libur pola lima hari: 8 jam pertama 2×, jam ke-9 3×,
  berikutnya 4×;
- pola enam hari: 7 jam pertama 2×, jam ke-8 3×, berikutnya 4×;
- kasus hari kerja terpendek pada pola enam hari perlu klasifikasi khusus;
- basis upah lembur dan divisor 173 disimpan pada snapshot;
- persetujuan dan realisasi tidak boleh hilang saat payroll.

**Remediasi audit:** pola hari kerja tidak lagi hard-coded lima; setting
`payroll.work_days_per_week` diteruskan ke payroll dan snapshot. Calculator
legacy enam hari juga dilengkapi.

**Residual gap:** hari kerja terpendek dalam pola enam hari belum dimodelkan
sebagai day subtype tersendiri; perlu rule dan golden test sebelum klaim penuh.

### 3.5 Cuti dan hak UU KIA

Alur ideal:

`leave type/policy effective → eligibility → request + evidence → approval →
calendar/balance → payroll phase → return-to-work`

Hak minimum:

- cuti tahunan paling sedikit 12 hari setelah 12 bulan bekerja;
- sakit, haid, alasan penting, dan hak lain tidak boleh mengurangi annual leave
  jika secara hukum/kebijakan merupakan hak terpisah;
- maternity tiga bulan pertama;
- tambahan sampai tiga bulan berikutnya pada kondisi khusus dengan surat dokter;
- upah penuh bulan 1–4 dan sedikitnya 75% bulan 5–6;
- keguguran 1,5 bulan atau sesuai surat tenaga kesehatan;
- suami mendampingi persalinan dua hari dan tambahan sampai tiga hari berikutnya
  sesuai kesepakatan;
- dokumen kesehatan diperlakukan sebagai data pribadi spesifik/sensitif;
- tidak boleh ada adverse action karena menggunakan hak yang dilindungi.

**Remediasi audit:**

- `LeavePolicyService::kiaEntitlement()` menjadi rule minimum;
- hard-limit maternity 90 hari dihapus melalui migration;
- field kondisi khusus, dokumen medis, tambahan durasi, unit bulan/hari,
  snapshot fase upah, reviewer, dan waktu review ditambahkan;
- template import dan seed diperbarui;
- test maternity, paternity cap, dan miscarriage ditambahkan.

**Residual gap:** controller/UI/approval/payroll belum seluruhnya memakai field
KIA baru. Sampai wiring dan UAT selesai, HR wajib melakukan review manual dan
sistem dilarang menolak tambahan hak hanya karena UI lama.

### 3.6 Payroll end-to-end

Alur ideal:

`period open → effective employee snapshot → approved inputs → calculation →
variance/reconciliation → maker-checker approval → finalization → bank
disbursement → settlement → payslip/e-Bupot/journal/export → correction`

Input:

- gaji pokok dan tunjangan tetap;
- attendance/unpaid leave;
- lembur berdasarkan pola kerja;
- bonus, THR, natura, reimbursement, loan;
- PKWT compensation, resignation/final settlement, retro adjustment;
- BPJS dan PPh 21;
- cost center dan accounting mapping.

Kontrol:

- transaksi pending ditampilkan sebelum calculate;
- payroll historis final immutable;
- koreksi dilakukan pada adjustment/off-cycle;
- snapshot menyimpan input, rule version, rate, basis, dan pembulatan;
- maker tidak menjadi approver/finalizer;
- disbursement dan settlement dapat direkonsiliasi;
- payslip dapat diakses pekerja dan tetap tenant-safe.

### 3.7 PPh 21 TER dan koreksi masa terakhir

Januari–November (atau sebelum masa terakhir) menggunakan TER sesuai kategori
PTKP. Masa pajak terakhir menggunakan penghasilan aktual sejak awal tahun/mulai
bekerja sampai masa terakhir, bukan gaji bulan terakhir × 12.

**Remediasi audit:**

- annualisasi `$grossTaxable * 12` dan BPJS bulan berjalan × 12 dihapus;
- ledger YTD membaca payslip periode sebelumnya pada tenant/tahun yang sama;
- masa resign di dalam periode diperlakukan sebagai masa pajak terakhir;
- nilai koreksi negatif tidak lagi dipaksa nol;
- refund disimpan sebagai `PPH21_REFUND` (deduction negatif), sehingga menaikkan
  take-home pay dan tampak pada payslip.

**Residual gap kritis:**

- histori lama hanya memiliki `gross_earning`, belum selalu memiliki snapshot
  `gross_taxable`; reimbursement/non-taxable historical dapat mencemari YTD;
- pindah pemberi kerja/entity dan bukti potong dari pemberi kerja sebelumnya
  belum dimodelkan lengkap;
- golden file Tax minimal 20 kasus dan rekonsiliasi e-Bupot belum tersedia.

Karena itu hasil koreksi harus direview Tax sampai residual gap ditutup.

### 3.8 BPJS

#### BPJS Kesehatan

- pekerja 1%, pemberi kerja 4%;
- basis/plafon mengikuti ketentuan aktif;
- nomor peserta dan status kepesertaan harus diverifikasi;
- perubahan plafon/rate harus effective-dated.

#### JHT, JP, JKK, JKM

- JHT: bagian pekerja dan pemberi kerja;
- JP: rate dan plafon yang berlaku pada periode;
- JKK: tingkat risiko perusahaan;
- JKM: pemberi kerja;
- seluruh rate perlu approval dan snapshot.

#### JKP PP 6/2025

Efektif 7 Februari 2025:

- total JKP 0,36% dari basis upah sampai Rp5.000.000;
- Pemerintah Pusat 0,22%;
- rekomposisi JKK 0,14%;
- tarif JKK baru: 0,10%, 0,40%, 0,75%, 1,13%, 1,60%;
- JKP bukan tambahan employer cost di atas JKK yang sudah direkomposisi.

**Remediasi audit:**

- default JKP menjadi 0,36%;
- breakdown `jkp_total`, `jkp_government`, dan
  `jkp_jkk_reallocation` tersedia;
- `jkp_company` menjadi additional cost 0 agar tidak double count;
- company total tidak lagi menambahkan JKP kedua kali;
- status hubungan kerja diteruskan dari payroll;
- payslip breakdown menyimpan rule `PP_6_2025`, tanggal efektif, basis, dan
  sumber dana;
- migration memetakan lima rate JKK legacy;
- seed dan company provisioning memakai rate baru;
- unit test plafon, eligibility, sumber dana, dan no-double-count diperbarui.

**Residual gap:** eligibility harus direkonsiliasi dengan status kepesertaan,
usia, program lain, dan data BPJS aktual; export SiPP/tagihan membutuhkan
rekonsiliasi resmi.

### 3.9 THR

Alur minimum:

1. tentukan hari raya pekerja/kebijakan pembayaran;
2. freeze eligibility pada tanggal pembayaran;
3. masa kerja ≥12 bulan memperoleh satu bulan upah;
4. masa kerja 1–<12 bulan memperoleh proporsional;
5. basis upah mengikuti ketentuan dan PP/PKB yang lebih baik;
6. pembayaran memenuhi tenggat;
7. pekerja resign/PHK dinilai sesuai jenis hubungan kerja dan jendela hak;
8. THR masuk payroll, PPh 21, jurnal, payslip, dan bukti transfer.

**Kondisi repo:** calculator multi-contract dan resignation eligibility ada.
Wiring/golden test dengan legal memo tetap diperlukan; jangan membatasi tenure
pekerja lama ke 1 Januari tahun berjalan.

### 3.10 Termination dan severance

Alur aman:

`proposal → reason/evidence → notice → employee response → bipartite →
agreement OR mediation/conciliation/PHI/cassation → final legal status →
settlement calculation → legal maker-checker → execution`

- tabel pesangon adalah minimum jika hukum menggunakan “paling sedikit”;
- PP/PKB/perjanjian yang lebih menguntungkan harus dibandingkan;
- upah proses dan status sengketa harus ditangani;
- execute diblok sampai kesepakatan atau putusan final yang sah;
- payroll historis tidak diubah diam-diam.

**Status:** belum ditutup pada audit ini. `TERM-MK-01` tetap P0 dan wajib
legal review.

### 3.11 Pelindungan data pribadi

Program minimum:

- data inventory dan ROPA;
- purpose dan legal basis per processing activity;
- controller/processor/subprocessor mapping;
- DPO/function appointment bila memenuhi kriteria;
- DPIA untuk biometrik, GPS, AI, scoring, dan monitoring;
- hak access, correction, restriction, objection, withdrawal, deletion,
  portability/transfer, dan complaint;
- breach register, containment, assessment, serta notifikasi 3×24 jam;
- cross-border transfer assessment;
- retention schedule, legal hold, dry-run purge, approval, destruction log;
- data minimization, encryption, tenant isolation, least privilege;
- backup dan deletion propagation.

**Remediasi audit:** dokumentasi retensi pajak dikoreksi dari 5 ke 10 tahun.

**Residual gap:** export masih memiliki limit 120/365/200 pada beberapa tabel,
consent revocation cache, DPIA/ROPA, breach workflow, backup propagation, dan
hak subjek end-to-end belum terbukti. `PDP-RIGHTS-01` tetap P0.

### 3.12 Kalender dan upah minimum

- baseline 2026 dinormalisasi menjadi 17 hari libur nasional;
- tanggal salah dibuang melalui migration, bukan hanya seed;
- cuti bersama tidak dicampur sebagai libur nasional;
- angka upah minimum estimasi diberi label non-authoritative.

**Residual gap:** schema harus dikembangkan agar menyimpan nomor keputusan,
URL, checksum, status approval, wilayah/sektor, effective range, superseded
version, serta enforcement yang fail-closed untuk payroll produksi.

---

## 4. Perubahan kode pada remediasi ini

| Area | File | Perubahan |
|---|---|---|
| BPJS | `Libraries/Payroll/BpjsCalculator.php` | PP 6/2025, breakdown sumber JKP, no double count |
| BPJS | `Libraries/Payroll/JkpPayslipIntegration.php` | rate 0,36%, pemerintah 0,22%, rekomposisi 0,14% |
| BPJS | `PayrollEmployeeCalculationService.php` | employment status dan statutory snapshot |
| BPJS | migration `000054_ApplyBpjsPp6Of2025.php` | backfill rate legacy |
| BPJS | seed/provisioning | rate baru untuk tenant baru |
| PPh 21 | `Pph21TerCalculator.php` | refund negatif tidak di-clamp |
| PPh 21 | `PayrollEmployeeCalculationService.php` | ledger YTD aktual dan masa resign |
| Lembur | `PayrollSettings.php` | `work_days_per_week` |
| Lembur | `PayrollService.php` | wiring setting ke employee calculation |
| Lembur | kedua OvertimeCalculator | tier pola enam hari |
| UU KIA | `LeavePolicyService.php` | entitlement maternity/paternity/miscarriage |
| UU KIA | migration `000056_AddKiaLeaveEntitlementFields.php` | field audit dan hapus hard-limit |
| UU KIA | seed/import | template hak minimum baru |
| Kalender | `IndonesianMastersSeeder.php` | 17 tanggal resmi |
| Kalender | migration `000055_CorrectNationalHolidays2026.php` | normalisasi instalasi existing |
| Retensi | `DataPrivacyService.php` | 10 tahun |
| UMP/UMK | `IndonesianMastersSeeder.php` | label non-authoritative |
| Tests | unit test BPJS/JKP/lembur/settings/KIA | regression coverage |

---

## 5. Register gap setelah remediasi

| ID | Priority | Gap | Release control |
|---|---|---|---|
| TAX-YTD-02 | P0 | Histori belum memiliki `gross_taxable_ytd` authoritative | review Tax/manual reconciliation |
| TERM-MK-01 | P0 | final legal gate PHK pasca MK 168 belum ditutup | blok eksekusi PHK disengketakan |
| KIA-WIRE-02 | P0 | field baru belum terhubung penuh UI/approval/payroll | HR manual override; jangan menolak hak |
| PDP-RIGHTS-01 | P0 | ROPA/DPIA/hak/insiden/transfer belum end-to-end | external processing off; manual DPO process |
| MASTER-WAGE-01 | P0 | keputusan gubernur authoritative belum tersedia | jangan gunakan seed estimasi |
| SEC-TENANT-01 | P0 | strict tenant findings perlu hasil gate terbaru | release hanya jika scanner hijau |
| REL-MIG-01 | P0 | fresh SQLite/MySQL perlu bukti pada commit ini | migration gates wajib |
| PAY-BPJS-02 | P1 | rekonsiliasi tagihan resmi BPJS belum ada | parallel/manual reconciliation |
| PAY-OT-02 | P1 | shortest-day tier pola 6 hari belum dimodelkan | manual classification |
| THR-WIRE-01 | P1 | golden case dan legal memo belum lengkap | parallel THR review |
| AI-PDP-01 | P1 | external AI minimization/vendor/DPIA belum lengkap | default off |
| OPS-EVID-01 | P1 | pentest, restore drill, load, parallel payroll | no production sign-off |

---

## 6. Checklist konfigurasi tenant sebelum go-live

### Legal dan organization

- [ ] Akta/legal entity, NPWP, NIB, PSE (jika wajib), WLKP tersedia.
- [ ] PP/PKB/perjanjian kerja aktif telah dipetakan ke policy.
- [ ] Serikat pekerja dan jalur perselisihan dikonfigurasi.
- [ ] Legal, HR, Tax, Finance, DPO, Security, dan Ops owner ditetapkan.
- [ ] Kalender perubahan regulasi dan SLA implementasi ditetapkan.

### Employee master

- [ ] NIK, NPWP/NIK pajak, BPJS, bank, PTKP, status kerja tervalidasi.
- [ ] PKWT/PKWTT dan tanggal kontrak konsisten.
- [ ] Tidak ada probation untuk PKWT.
- [ ] Branch/work location menentukan minimum wage dan kalender yang tepat.
- [ ] Reporting line dan maker-checker tidak konflik.

### Attendance dan leave

- [ ] Pola `payroll.work_days_per_week` bernilai 5 atau 6.
- [ ] Roster, timezone, overnight shift, weekly rest, holiday diuji.
- [ ] Basis lembur dan divisor 173 disetujui.
- [ ] Leave type mandatory tidak mengurangi annual leave.
- [ ] UU KIA workflow dan proteksi dokumen kesehatan diuji.

### Payroll

- [ ] Rate BPJS perusahaan diverifikasi terhadap kelas risiko/tagihan resmi.
- [ ] Plafon JP/BPJS tahun berjalan diperbarui.
- [ ] PTKP, TER, bracket, natura, dan tax method disetujui Tax.
- [ ] UMP/UMK berasal dari keputusan resmi, bukan demo seed.
- [ ] THR basis, eligibility, hari raya, dan tenggat dikonfigurasi.
- [ ] Bank, jurnal ERP, e-Bupot, BPJS export diuji.
- [ ] Maker-checker, finalization, correction, dan rollback diuji.

### PDP dan security

- [ ] ROPA, DPIA, retention schedule, legal hold disetujui.
- [ ] Vendor/subprocessor/DPA/cross-border assessment tersedia.
- [ ] Tenant scanner, permission gate, route gate, controller DB gate hijau.
- [ ] Secret, encryption, key rotation, backup, restore diuji.
- [ ] Breach drill dan subject-right request diuji.

---

## 7. Test dan evidence minimum

```powershell
Set-Location backend

composer migration:check
composer migration:fresh-sqlite
composer migration:fresh-mysql
composer permission:check
composer route:check
composer controller-db:check
composer repository:sensitive-check
composer tenant:check
composer stan
composer test
composer ci
```

Target functional golden cases:

1. BPJS seluruh lima tingkat risiko, basis di bawah/tepat/di atas plafon;
2. PKWT, PKWTT, magang, outsourcing, harian, peserta tidak eligible;
3. payroll tetap, join/resign tengah tahun, bonus, THR, natura, retro;
4. PPh final >, =, dan < TER (refund);
5. lembur weekday/rest/holiday pola 5 dan 6 hari;
6. maternity 3–6 bulan, miscarriage, paternity 2–5 hari;
7. final settlement PKWT/PKWTT dan PHK disputed;
8. dua tenant untuk read/write/approve/finalize/export;
9. backup-restore dengan hash dan attachment verification.

Evidence harus memuat commit, command, exit code, jumlah test/assertion, golden
file, reviewer, dan tanggal.

### Evidence eksekusi lokal 27 Juli 2026

| Gate | Hasil |
|---|---|
| `composer migration:check` | PASS; 161 file, version, dan class migration unik |
| `composer migration:fresh-sqlite` | PASS; 161 migration, 551 index, 177 tabel tenant terindeks, 46 tabel non-tenant terklasifikasi, FK dan integrity OK |
| `composer stan` | PASS; 228 file dianalisis, 0 error |
| `composer test` | PASS; 1.272 test, 4.014 assertion |

Fresh migration gate diperbaiki dengan mengklasifikasikan
`dashboard_template_versions` sebagai tabel tenant melalui parent
`dashboard_templates`. Test kebijakan idempotency mobile juga diselaraskan
dengan client Flutter aktif di `mobile/lib/core/network/api_client.dart`,
menggantikan referensi path React Native yang sudah tidak ada.

Evidence ini berasal dari working tree lokal dan belum memiliki commit/reviewer.
Gate MySQL/MariaDB, security gate lengkap, golden file resmi, dan UAT tetap
harus dijalankan sebelum release decision.

---

## 8. Release decision

Repository **tidak boleh diberi label “fully compliant”** hanya karena perubahan
ini telah di-merge. Release produksi legal/payroll baru dapat disetujui jika:

- semua P0 pada tracker berstatus `DONE`;
- migration fresh dan upgrade lulus pada SQLite dan MySQL/MariaDB;
- seluruh security gate hijau pada commit yang sama;
- BPJS dan PPh 21 cocok dengan golden file resmi/konsultan;
- KIA dan PHK lulus UAT Legal/HR;
- PDP artefact dan drill disetujui DPO;
- parallel payroll dan reconciliation ditandatangani Finance/Tax/HR;
- accepted risk memiliki owner, alasan, expiry, dan executive sign-off.

Dokumen ini menjadi baseline audit teknis 26 Juli 2026 dan harus diperbarui
setiap perubahan regulasi material atau perubahan calculator/workflow.
