# HRIS Indonesia Compliance Remediation Tracker

**Tanggal baseline audit:** 16 Juli 2026  
**Tanggal pembaruan terakhir:** 16 Juli 2026  
**Ruang lingkup:** ketenagakerjaan, payroll, PPh 21, BPJS, pelindungan data pribadi, keamanan multi-tenant, dan kesiapan rilis  
**Skor baseline:** 46/100 — sesuai sebagian, belum layak dinyatakan patuh atau digunakan sebagai sumber perhitungan legal produksi  
**Status dokumen:** ACTIVE  
**Pemilik program:** _TBD_  
**Target rilis patuh:** _TBD_

> Dokumen ini adalah tracker implementasi teknis dan bukti kepatuhan. Dokumen
> ini bukan pendapat hukum. Penutupan item yang menyangkut interpretasi hukum
> wajib memperoleh persetujuan Legal/HR, Tax, atau DPO sesuai kolom sign-off.

## 1. Tujuan dan cara menggunakan tracker

Tracker ini menjadi sumber utama untuk:

1. mencatat setiap gap yang ditemukan pada audit kepatuhan Indonesia;
2. menentukan perubahan kode, data, proses, dan dokumentasi yang diperlukan;
3. mencegah item dianggap selesai hanya karena calculator atau UI sudah ada;
4. menyimpan bukti test, UAT, rekonsiliasi, dan persetujuan pihak berwenang;
5. menentukan apakah payroll, PHK, cuti, dan pemrosesan data sudah aman dirilis.

Setiap item hanya boleh ditandai selesai jika seluruh checklist, acceptance
criteria, evidence, dan sign-off yang diwajibkan sudah terpenuhi.

### Status

| Status | Arti |
|---|---|
| `TODO` | Belum dikerjakan |
| `IN PROGRESS` | Sedang dikerjakan dan belum memenuhi acceptance criteria |
| `BLOCKED` | Terhambat keputusan hukum, data resmi, akses, atau dependency |
| `READY FOR REVIEW` | Implementasi selesai, menunggu review/UAT/sign-off |
| `DONE` | Acceptance criteria, evidence, dan sign-off sudah lengkap |
| `ACCEPTED RISK` | Tidak diperbaiki; wajib ada alasan, masa berlaku, dan persetujuan tertulis |

### Prioritas

| Prioritas | Definisi | Batas penyelesaian |
|---|---|---|
| `P0` | Dapat mengubah nominal hak/kewajiban, menyebabkan pelanggaran hukum, kebocoran data, atau membuat instalasi/rilis gagal | Sebelum penggunaan produksi berikutnya |
| `P1` | Risiko kepatuhan atau audit tinggi tetapi dapat dikendalikan sementara dengan prosedur manual terdokumentasi | Maksimal satu siklus rilis setelah P0 |
| `P2` | Penguatan governance, otomasi, dan bukti operasional | Setelah P0 dan P1 stabil |

## 2. Aturan release sementara

Sampai seluruh P0 ditutup:

- [ ] Jangan memakai hasil kalkulasi JKP/JKK sebagai angka final pelaporan atau pembayaran tanpa rekonsiliasi manual.
- [ ] Jangan memfinalisasi koreksi PPh 21 masa pajak terakhir tanpa perhitungan YTD independen.
- [ ] Jangan menolak tambahan cuti melahirkan atau pendampingan hanya karena limit sistem.
- [ ] Jangan mengeksekusi PHK yang disengketakan hanya berdasarkan approval internal sistem.
- [ ] Jangan menjalankan penghapusan data memakai asumsi retensi pajak lima tahun.
- [ ] Nonaktifkan pengiriman data HR ke AI eksternal sampai kontrol PDP disetujui.
- [ ] Setiap pengecualian harus dicatat pada Decision Log dengan pemilik risiko dan tanggal kedaluwarsa.

## 3. Ringkasan workstream

| ID | Prioritas | Area | Gap utama | Status | Owner | Target | Sign-off |
|---|---|---|---|---|---|---|---|
| PAY-REG-01 | P0 | BPJS | JKP/JKK masih memakai PP 37/2021 dan berpotensi double count | TODO | — | — | HR, Finance, BPJS SME |
| PAY-TAX-01 | P0 | PPh 21 | Koreksi masa terakhir bukan YTD aktual dan tidak mendukung refund | TODO | — | — | Tax, Finance |
| LEAVE-KIA-01 | P0 | Cuti | Hak UU KIA 4/2024 belum termodelkan | TODO | — | — | Legal, HR |
| TERM-MK-01 | P0 | PHK | Putusan MK 168 belum menjadi gate workflow | TODO | — | — | Legal, HR Director |
| PDP-RET-01 | P0 | Retensi | Retensi pajak tertulis 5 tahun, seharusnya 10 tahun | TODO | — | — | Tax, DPO |
| PDP-RIGHTS-01 | P0 | UU PDP | Hak subjek data, SLA, DPIA, breach, dan transfer belum lengkap | TODO | — | — | DPO, Legal, Security |
| MASTER-CAL-01 | P0 | Kalender | Libur nasional 2026 salah dan tidak lengkap | TODO | — | — | HR |
| MASTER-WAGE-01 | P0 | Upah minimum | Estimasi disimpan sebagai data 2026 resmi; audit memakai tahun join | TODO | — | — | HR, Legal |
| SEC-TENANT-01 | P0 | Security | Tenant strict gate memiliki 12 temuan HIGH baru | TODO | — | — | Security |
| REL-MIG-01 | P0 | Release | Fresh migration gagal pada sinkronisasi katalog RBAC | TODO | — | — | Engineering |
| REL-AUTH-01 | P0 | Authorization | Route-policy strict dan controller DB gate gagal | TODO | — | — | Security, Engineering |
| PAY-OT-01 | P1 | Lembur | Payroll selalu mengirim pola lima hari kerja | TODO | — | — | HR, Payroll SME |
| PAY-WIRE-01 | P1 | Integrasi | Calculator compliance tersedia tetapi belum terhubung ke transaksi | TODO | — | — | HR, Tax |
| AI-PDP-01 | P1 | AI | Redaksi PII dan kontrol transfer eksternal belum memadai | TODO | — | — | DPO, Security |
| OPS-EVID-01 | P1 | Operasional | Belum ada bukti MySQL, E2E, pentest, restore, dan parallel payroll | TODO | — | — | Engineering, Ops, Audit |
| ORG-COMP-01 | P1 | Organisasi | Bukti PSE, WLKP, PP/PKB, BPJS, dan pajak tidak dapat dibuktikan dari repo | TODO | — | — | Legal, HR, DPO |

## 4. Checklist detail P0

### PAY-REG-01 — Perbaikan JKP/JKK sesuai PP 6/2025

**Baseline:** `BpjsCalculator` memakai `jkp_company_rate=0.0046`, menambahkan
JKP ke `company_total`, dan masih memakai tarif JKK lama. Payroll memanggil
calculator tanpa status hubungan kerja dan tidak menyimpan JKP sebagai komponen
payslip tersendiri.

**Perubahan:**

- [ ] Buat legal-rule record PP 6/2025 dengan tanggal efektif 7 Februari 2025.
- [ ] Ubah total iuran JKP menjadi 0,36% dengan sumber pemerintah 0,22% dan rekomposisi JKK 0,14%.
- [ ] Terapkan tarif JKK setelah rekomposisi: 0,10%, 0,40%, 0,75%, 1,13%, dan 1,60% sesuai tingkat risiko.
- [ ] Pastikan JKP bukan tambahan employer cost di atas JKK yang belum direkomposisi.
- [ ] Kirim status hubungan kerja dan atribut eligibility yang diperlukan dari payroll service.
- [ ] Simpan JKP, basis upah, rate, sumber pendanaan, dan rule version pada snapshot payslip/export.
- [ ] Fail closed jika rate aktif perusahaan tidak tersedia atau belum disetujui.
- [ ] Perbarui seed/migration, admin rate UI, export BPJS/SiPP, payslip PDF, dan dokumentasi.
- [ ] Tambahkan migration/backfill yang tidak mengubah payslip final historis.

**Test wajib:**

- [ ] Unit test kelima tingkat risiko JKK.
- [ ] Unit test upah di bawah, sama dengan, dan di atas plafon Rp5.000.000.
- [ ] Test eligibility PKWT, PKWTT, magang, harian, outsourcing, usia, dan kepesertaan.
- [ ] Integration test bahwa `company_total` tidak double count.
- [ ] Rekonsiliasi minimal 10 pekerja dengan kalkulator/tagihan resmi BPJS.
- [ ] Regression test payslip, jurnal, export BPJS, dan variance review.

**Acceptance criteria:** selisih dengan hasil resmi BPJS Rp0 setelah aturan
pembulatan disepakati; snapshot menyimpan versi aturan; HR dan Finance
menandatangani rekonsiliasi.

**Referensi:** [PP 6/2025](https://www.peraturan.go.id/files/pp-no-6-tahun-2025.pdf)

### PAY-TAX-01 — Koreksi PPh 21 masa pajak terakhir

**Baseline:** bruto dan BPJS bulan terakhir dikalikan 12. Kelebihan potong
dipaksa minimum nol sehingga refund kepada pegawai tidak dapat dihasilkan.

**Perubahan:**

- [ ] Bangun ledger YTD dari transaksi payroll final aktual, bukan annualisasi bulan berjalan.
- [ ] Masukkan bonus, THR, natura, rapel, retro-pay, pindah cabang/entity, join, resign, dan koreksi historis.
- [ ] Tentukan masa pajak terakhir pegawai, tidak terbatas pada Desember.
- [ ] Hubungkan `Pph21YearEndCorrection` ke alur payroll produksi setelah algoritmanya direview.
- [ ] Izinkan adjustment negatif dan buat komponen refund yang terlihat di payslip serta jurnal.
- [ ] Hindari pengambilan bracket lintas tahun efektif; pilih satu rule set terbaru yang berlaku.
- [ ] Simpan kertas kerja, PTKP, TER yang telah dipotong, pajak final, adjustment, dan rule version.
- [ ] Sediakan mekanisme pembetulan setelah payroll final tanpa mengubah payslip historis secara diam-diam.

**Test wajib:**

- [ ] Pegawai bekerja Januari–Desember dengan gaji tetap.
- [ ] Mid-year join, mid-year resign, dan join-resign pada tahun yang sama.
- [ ] Kenaikan/penurunan gaji, bonus/THR, natura, retro-pay, dan mutasi entity.
- [ ] Final tax lebih besar, sama, dan lebih kecil daripada TER yang telah dipotong.
- [ ] Refund muncul di payslip dan dibayarkan paling lambat sesuai PMK 168.
- [ ] Golden-file bukti potong dan rekonsiliasi minimal 20 kasus dengan konsultan pajak.

**Acceptance criteria:** seluruh kasus golden-file sesuai kertas kerja Tax;
refund dan tambahan potong ter-posting benar; tidak ada perkalian 12 terhadap
bulan berjalan untuk menggantikan YTD.

**Referensi:** [PMK 168/2023](https://pajak.go.id/id/peraturan/petunjuk-pelaksanaan-pemotongan-pajak-atas-penghasilan-sehubungan-dengan-pekerjaan-jasa-1)

### LEAVE-KIA-01 — Hak ibu dan ayah sesuai UU 4/2024

**Perubahan data dan workflow:**

- [ ] Ganti limit maternity tetap 90 hari menjadi tiga bulan pertama dan tambahan bersyarat sampai tiga bulan.
- [ ] Simpan kondisi khusus, surat dokter, periode, reviewer, dan audit trail.
- [ ] Modelkan upah penuh bulan 1–4 serta 75% bulan 5–6 dan hubungkan ke payroll.
- [ ] Modelkan keguguran 1,5 bulan atau sesuai surat tenaga kesehatan.
- [ ] Modelkan cuti suami dua hari dan tambahan sampai tiga hari berikutnya sesuai kesepakatan.
- [ ] Modelkan waktu tambahan karena komplikasi/kematian ibu atau anak tanpa hard-code yang menurunkan hak.
- [ ] Cegah PHK dan adverse action selama hak yang dilindungi sedang digunakan.
- [ ] Perbarui leave types, policy service, UI, approval, kalender, saldo, payroll, dan laporan.
- [ ] Tentukan perlakuan data lama dan jangan mengurangi cuti yang sudah disetujui.

**Test/UAT wajib:**

- [ ] Persalinan normal tiga bulan.
- [ ] Kondisi khusus empat, lima, dan enam bulan beserta fase upah.
- [ ] Keguguran dengan durasi dari surat tenaga kesehatan.
- [ ] Paternity dua hari serta tambahan yang disetujui/ditolak sesuai kebijakan perusahaan.
- [ ] Overlap lintas periode payroll dan lintas tahun.
- [ ] UAT HR, payroll, employee self-service, dan manager approval.

**Acceptance criteria:** sistem tidak dapat menurunkan hak minimum UU; payroll
sesuai fase; semua keputusan dan dokumen dapat diaudit.

**Referensi:** [UU 4/2024](https://www.peraturan.go.id/files/uu-no-4-tahun-2024.pdf)

### TERM-MK-01 — Workflow PHK dan pesangon pasca Putusan MK 168

- [ ] Review ulang seluruh reason code bersama penasihat hukum ketenagakerjaan.
- [ ] Hapus asumsi bahwa pelanggaran berat atau mangkir otomatis tidak memerlukan proses perselisihan.
- [ ] Wajibkan bipartit secara musyawarah untuk mufakat bila PHK ditolak pekerja.
- [ ] Buat state mediasi/konsiliasi/PHI/kasasi dan simpan nomor perkara serta dokumen.
- [ ] Blok `execute()` ketika belum ada kesepakatan atau putusan berkekuatan hukum tetap.
- [ ] Hitung dan akrualkan upah proses sampai putusan berkekuatan hukum tetap.
- [ ] Perlakukan tabel pesangon sebagai minimum (`paling sedikit`), bukan nilai maksimum/pasti.
- [ ] Bandingkan hasil minimum UU dengan PP/PKB/perjanjian kerja dan gunakan yang lebih menguntungkan jika diwajibkan.
- [ ] Tambahkan maker-checker Legal sebelum surat PHK dan settlement diterbitkan.
- [ ] Jangan mengubah termination historis; gunakan correction/version record.

**Test/UAT wajib:**

- [ ] Mutual agreement dapat lanjut dengan bukti kesepakatan terdaftar.
- [ ] Bipartit tidak sepakat tidak dapat maju ke execute.
- [ ] `PENDING_TRIPARTITE` tidak dapat melewati final legal gate.
- [ ] Putusan belum final tetap mengakumulasi upah proses.
- [ ] Putusan final membuka execute dan memakai tanggal/nominal yang disetujui.
- [ ] Pesangon minimum dibandingkan dengan benefit perusahaan/PKB.

**Acceptance criteria:** tidak ada PHK disengketakan yang dapat dieksekusi hanya
dengan approval internal; Legal menandatangani state machine dan formula.

**Referensi:** [Putusan MK 168 pada status UU 6/2023](https://peraturan.bpk.go.id/Details/246523/uu-no-6-tahun-2023)

### PDP-RET-01 — Koreksi retensi pajak menjadi 10 tahun

- [ ] Ubah seluruh komentar, service, policy, cron, UI, dan dokumentasi dari lima menjadi sepuluh tahun.
- [ ] Cari seluruh kemunculan `5 tahun`, `5 years`, `years=5`, dan konfigurasi ekuivalen.
- [ ] Pisahkan jadwal retensi menurut kategori: pajak, payroll, kontrak, attendance, biometrik, GPS, rekrutmen, audit, dan dokumen lain.
- [ ] Catat dasar hukum, trigger awal retensi, tanggal akhir, legal hold, dan tindakan akhir.
- [ ] Pastikan legal hold menghentikan penghapusan yang terkait sengketa/investigasi.
- [ ] Buat migration aman untuk policy/retention schedule yang telah tersimpan.
- [ ] Tambahkan dry-run dan approval sebelum purge serta immutable destruction log setelah purge.

**Acceptance criteria:** tidak ada aturan pajak lima tahun tersisa; seluruh
purge fail closed jika policy tidak ada, belum disetujui, atau terkena legal hold.

**Referensi:** [UU KUP](https://www.pajak.go.id/index.php/undang-undang-nomor-28-tahun-2007)

### PDP-RIGHTS-01 — Program kepatuhan UU PDP

**Governance:**

- [ ] Buat data inventory dan Record of Processing Activities untuk seluruh modul.
- [ ] Tetapkan salah satu dasar pemrosesan yang sah per purpose; jangan memakai blanket consent.
- [ ] Tetapkan controller, joint controller, processor, subprocessor, dan pemilik sistem.
- [ ] Tetapkan DPO/petugas fungsi PDP berdasarkan kriteria UU dan simpan bukti penunjukan.
- [ ] Buat DPIA untuk biometrik/selfie, GPS, anomaly scoring, AI, recruitment scoring, dan analytics.
- [ ] Buat Data Processing Agreement serta proses persetujuan subprocessor.
- [ ] Dokumentasikan mekanisme transfer data lintas negara dan lokasi penyimpanan.

**Hak subjek data dan insiden:**

- [ ] Implementasikan request access, correction, withdrawal, restriction, objection, deletion, dan complaint.
- [ ] Terapkan SLA 3×24 jam pada aksi yang diwajibkan UU dan escalation jika mendekati breach.
- [ ] Ekspor seluruh data dan rekam pemrosesan yang relevan tanpa limit arbitrer 120/365/200.
- [ ] Pastikan ekspor tidak membocorkan secret atau data pribadi orang lain.
- [ ] Perluas anonimisasi/penghapusan ke dokumen fisik, selfie, device token, kandidat, AI session, attachment, dan backup sesuai policy.
- [ ] Sinkronkan cache consent ketika consent dicabut.
- [ ] Implementasikan breach register, severity assessment, containment, dan notifikasi 3×24 jam.
- [ ] Catat seluruh aktivitas pemrosesan dan pemberian akses secara dapat diaudit.

**Acceptance criteria:** DPIA dan ROPA disetujui DPO; semua hak dapat diuji end-to-end;
SLA dan breach drill lulus; ekspor lengkap dan tenant-safe.

**Referensi:** [UU PDP 27/2022](https://jdih.komdigi.go.id/produk_hukum/view/id/832/t/crc32/)

### MASTER-CAL-01 — Kalender libur nasional 2026

- [ ] Ganti seed dengan 17 hari libur nasional resmi pada SKB 3 Menteri 2026.
- [ ] Tambahkan Isra Mikraj 16 Januari dan Paskah 5 April.
- [ ] Koreksi Iduladha menjadi 27 Mei, Waisak 31 Mei, 1 Muharam 16 Juni, dan Maulid 25 Agustus.
- [ ] Hapus penandaan 20 Maret sebagai Wafat Yesus; klasifikasikan cuti bersama secara terpisah.
- [ ] Pisahkan `national_holiday` dan `collective_leave`; jangan menganggap cuti bersama otomatis mengurangi hak tanpa policy perusahaan.
- [ ] Simpan nomor SKB, URL sumber, tanggal penerbitan, dan checksum/import batch.
- [ ] Buat diff preview dan maker-checker untuk update kalender tahunan.
- [ ] Recompute attendance/overtime hanya melalui correction workflow yang dapat diaudit.

**Acceptance criteria:** tanggal sama dengan sumber Setneg; test kalender, shift,
attendance, cuti, dan lembur lulus untuk seluruh tanggal terdampak.

**Referensi:** [SKB 3 Menteri 2026](https://www.setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2026)

### MASTER-WAGE-01 — Upah minimum resmi dan audit periode berjalan

- [ ] Hapus atau tandai seluruh angka estimasi 2026 sebagai non-authoritative dan tidak dapat dipakai payroll.
- [ ] Import UMP/UMK/UMSP/UMSK dari keputusan gubernur yang berlaku untuk lokasi kerja.
- [ ] Simpan nomor keputusan, wilayah, sektor, tanggal efektif, sumber, status approval, dan superseded version.
- [ ] Ubah audit agar memakai tahun/periode payroll berjalan, bukan tahun `join_date`.
- [ ] Tentukan aturan pekerja dengan masa kerja lebih dari satu tahun melalui struktur dan skala upah, bukan hanya minimum wage check.
- [ ] Buat warning/block yang dapat dikonfigurasi tetapi tidak dapat diabaikan tanpa alasan dan approval.
- [ ] Uji perpindahan lokasi, perubahan sektor, kenaikan pertengahan periode, dan pekerja lama.

**Acceptance criteria:** tidak ada estimasi berstatus efektif; seluruh hasil audit
dapat ditelusuri ke keputusan resmi dan periode payroll yang benar.

### SEC-TENANT-01 — Tutup temuan tenant strict

- [ ] Triage 12 temuan baru pada `ApprovalEngine`, `ApprovalOutcomeOutbox`, dan `AssetService`.
- [ ] Untuk setiap temuan, dokumentasikan `confirmed`, `safe global query`, atau `false positive` beserta alasan.
- [ ] Tambahkan company/parent scope pada query yang confirmed.
- [ ] Tambahkan deklarasi/allowlist sempit pada query global yang sah; jangan menurunkan strictness scanner.
- [ ] Tambahkan cross-tenant integration test untuk read, approve, finalize, worker/outbox, dan asset mutation.
- [ ] Pastikan worker memulihkan dan membersihkan tenant context setiap job.
- [ ] Jalankan `composer tenant:check` sampai exit code 0 dan `new_findings=0`.

**Acceptance criteria:** tidak ada temuan baru, test dua tenant lulus, dan hasil
triage direview Security.

### REL-MIG-01 — Pulihkan fresh migration

- [ ] Perbaiki sinkronisasi katalog RBAC pada migration `2026-09-01-000032` tanpa melemahkan validasi katalog.
- [ ] Pastikan migration dapat dijalankan pada database kosong dan upgrade snapshot produksi tersanitasi.
- [ ] Pastikan nomor/class migration unik dan `down()`/roll-forward strategy terdokumentasi.
- [ ] Jalankan SQLite dan MySQL/MariaDB dengan FK serta index verification aktif.
- [ ] Jangan mengedit migration yang telah dirilis jika perubahan additive repair lebih aman.

**Acceptance criteria:** `composer migration:check`, `composer migration:fresh-sqlite`,
dan `composer migration:fresh-mysql` lulus; upgrade snapshot juga lulus.

### REL-AUTH-01 — Authorization dan controller DB gates

- [ ] Deklarasikan policy untuk `GET /api/v1/attendance/my` dan regenerate matrix.
- [ ] Pastikan own-data scope tidak hanya bergantung pada permission route.
- [ ] Triage direct DB call baru pada `OfferController` dan pindahkan ke service/model scoped bila relevan.
- [ ] Jalankan `composer permission:check`, `composer route:check`, dan `composer controller-db:check`.
- [ ] Review diff `AUTHORIZATION_ROUTE_MATRIX.md` sebelum commit.

**Acceptance criteria:** ketiga gate exit code 0 dan tidak ada endpoint protected
tanpa policy eksplisit.

## 5. Checklist P1 dan P2

### PAY-OT-01 — Pola kerja lembur

- [ ] Ambil hari kerja per minggu dari shift/roster/policy efektif pekerja.
- [ ] Dukung pola lima dan enam hari, hari istirahat mingguan, serta libur nasional.
- [ ] Simpan rule input pada payroll snapshot.
- [ ] Tambahkan golden test seluruh tier lembur untuk kedua pola.

### PAY-WIRE-01 — Integrasi calculator compliance

- [ ] Inventaris semua pure calculator dan caller produksi yang seharusnya menggunakannya.
- [ ] Perbaiki basis THR menjadi upah sebulan sesuai regulasi/kebijakan yang berlaku.
- [ ] Jangan membatasi masa kerja THR pekerja lama ke 1 Januari tahun berjalan.
- [ ] Hubungkan long sick leave ke payroll setelah dasar hukum dan fase upah disetujui.
- [ ] Hubungkan tenure/long-service leave hanya setelah aturan sektor/perusahaan divalidasi.
- [ ] Hapus klaim “implemented/production ready” dari dokumentasi sampai wiring dan integration test selesai.

### AI-PDP-01 — AI eksternal

- [ ] Default external AI `off` sampai DPIA dan vendor review selesai.
- [ ] Redaksi nama, employee ID, gaji, kesehatan, biometrik, lokasi, keluarga, free text, dan identifier lain.
- [ ] Gunakan hasil redaksi untuk content dan session title; jangan simpan input mentah tanpa purpose dan retensi.
- [ ] Terapkan allowlist field/context dan data minimization sebelum request provider.
- [ ] Catat provider, model, region, subprocessor, retention, purpose, dan legal basis.
- [ ] Sediakan opt-out atau kontrol alternatif jika diwajibkan.
- [ ] Uji prompt injection, data exfiltration, cross-tenant context, logging, dan deletion.

### OPS-EVID-01 — Bukti kesiapan operasional

- [ ] Seluruh `composer ci` lulus.
- [ ] Frontend test, lint, i18n, build, dan Playwright lulus.
- [ ] Mobile Jest, TypeScript, lint, dan build release lulus.
- [ ] Fresh MySQL dan upgrade snapshot produksi tersanitasi lulus.
- [ ] Pentest independen dan remediation retest selesai.
- [ ] Restore drill terisolasi memverifikasi count, hash, attachment, dan login.
- [ ] Parallel payroll minimal 12 bulan dan seluruh golden case direkonsiliasi.
- [ ] Load test payroll, attendance peak, export, queue, dan approval dilakukan.
- [ ] UAT ditandatangani HR, Finance, Legal, DPO, Security, dan Operations.

### ORG-COMP-01 — Bukti kepatuhan organisasi

- [ ] Verifikasi kebutuhan dan bukti pendaftaran PSE Lingkup Privat.
- [ ] Simpan bukti WLKP dan jadwal pembaruannya.
- [ ] Simpan Peraturan Perusahaan/PKB yang berlaku dan mapping rule ke konfigurasi sistem.
- [ ] Verifikasi kepesertaan dan pelaporan BPJS Kesehatan/Ketenagakerjaan.
- [ ] Verifikasi proses setor/lapor PPh 21 dan pemberian bukti potong.
- [ ] Tetapkan owner legal watch untuk perubahan regulasi dan SLA implementasinya.
- [ ] Jalankan review kepatuhan minimal tahunan dan setiap ada perubahan regulasi material.

## 6. Perintah verifikasi minimum

Jalankan dari direktori masing-masing dan tempelkan output/ringkasan pada
Evidence Log.

```powershell
# backend
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

# frontend
npm.cmd run lint
npm.cmd run i18n:check
npm.cmd run test
npm.cmd run test:e2e
npm.cmd run build

# mobile
npm.cmd run lint
npm.cmd test -- --runInBand
npm.cmd run tsc
```

## 7. Definition of Done program

Program remediasi hanya dapat dinyatakan selesai jika:

- [ ] Semua item P0 berstatus `DONE`; tidak ada `ACCEPTED RISK` tanpa persetujuan eksekutif dan Legal/DPO.
- [ ] Semua nilai payroll golden-case cocok dengan kertas kerja yang disetujui.
- [ ] Seluruh hak minimum pekerja dapat dijalankan tanpa workaround database/manual tersembunyi.
- [ ] Seluruh strict security dan release gate hijau pada commit yang sama.
- [ ] Fresh install, upgrade, rollback/roll-forward, backup, dan restore telah dibuktikan.
- [ ] ROPA, DPIA, retention schedule, incident response, dan data-subject workflow disetujui DPO.
- [ ] Dokumentasi tidak lagi mengklaim calculator orphan atau fondasi parsial sebagai production-ready.
- [ ] Legal, HR, Tax, Finance, DPO, Security, Engineering, dan Operations menandatangani release readiness.

## 8. Template Evidence Log

Tambahkan satu baris untuk setiap bukti. Gunakan path repository, URL CI, nomor
tiket, atau lokasi dokumen resmi yang dapat diakses auditor.

| Tanggal | Item | Jenis bukti | Hasil | Referensi | Diuji oleh | Direview oleh |
|---|---|---|---|---|---|---|
| 2026-07-16 | BASELINE | Backend PHPUnit | PASS — 1.246 test, 3.725 assertion | Local audit run | Codex | — |
| 2026-07-16 | BASELINE | PHPStan | PASS — 221 file | Local audit run | Codex | — |
| 2026-07-16 | BASELINE | Frontend Vitest/build | PASS — 51 test; build berhasil | Local audit run | Codex | — |
| 2026-07-16 | BASELINE | Mobile Jest/TypeScript | PASS — 29 test; `tsc` berhasil | Local audit run | Codex | — |
| 2026-07-16 | BASELINE | Fresh migration | FAIL — katalog RBAC tidak lengkap | Migration `000032` | Codex | — |
| 2026-07-16 | BASELINE | Tenant strict | FAIL — 12 temuan HIGH baru | `writable/security/tenant-leak-audit.json` | Codex | — |
| YYYY-MM-DD | ITEM-ID | Unit/integration/UAT/legal memo | PASS/FAIL | URL/path/ticket | Nama | Nama |

## 9. Decision Log

Gunakan log ini untuk interpretasi hukum, perubahan formula, pengecualian, atau
accepted risk. Keputusan tanpa baris ini dianggap belum disetujui.

| Tanggal | Item | Keputusan | Dasar/sumber | Dampak | Disetujui oleh | Review ulang |
|---|---|---|---|---|---|---|
| YYYY-MM-DD | ITEM-ID | Ringkasan keputusan | Peraturan/legal memo | Modul/data terdampak | Nama/jabatan | YYYY-MM-DD |

## 10. Progress Log

Catat perubahan aktual, bukan hanya rencana.

| Tanggal | Item | Perubahan | File/migration | Verifikasi | Status baru |
|---|---|---|---|---|---|
| 2026-07-16 | BASELINE | Tracker remediasi dibuat dari audit kepatuhan Indonesia | Dokumen ini | Review struktur dan scope | ACTIVE |
| YYYY-MM-DD | ITEM-ID | Ringkasan perubahan | Path/PR/commit | Test/UAT | Status |

## 11. Referensi internal

- `docs/03_Roadmap_Sprints/HRIS_REPO_BUSINESS_AUDIT_2026-07-12.md`
- `docs/03_Roadmap_Sprints/IMPROVEMENT_CHECKLIST.md`
- `docs/03_Roadmap_Sprints/SPRINT_C_COMPLIANCE.md`
- `docs/05_Security_Audit_Test/AUDIT.md`
- `docs/05_Security_Audit_Test/AUTHORIZATION_ROUTE_MATRIX.md`
- `docs/05_Security_Audit_Test/DATA_PRIVACY.md`
- `docs/05_Security_Audit_Test/TEST_PLAN_END_TO_END.md`

> Checklist historis tetap berguna sebagai catatan implementasi, tetapi status
> lama tidak mengalahkan tracker ini bila regulasi, wiring produksi, atau bukti
> pengujian terbaru menunjukkan kondisi yang berbeda.
