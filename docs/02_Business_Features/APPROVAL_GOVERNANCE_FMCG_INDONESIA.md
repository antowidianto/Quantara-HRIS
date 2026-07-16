# Tata Kelola Approval HRIS — Perusahaan FMCG Indonesia

**Versi audit:** 16 Juli 2026  
**Cakupan:** seluruh transaksi HR, payroll, workforce, talent, dan perubahan master sensitif  
**Status dokumen:** baseline desain, hasil audit repository, dan panduan rollout

## 1. Prinsip desain

Tidak ada peraturan ketenagakerjaan Indonesia yang menetapkan bahwa setiap
transaksi HR harus memiliki tepat satu, dua, atau tiga level approval. Jumlah
level adalah kontrol internal perusahaan. Workflow harus proporsional terhadap
dampak pekerja, nilai finansial, risiko kepatuhan, dan risiko fraud.

Baseline ini memakai prinsip berikut:

1. **Four-eyes / maker-checker.** Pembuat, pemohon, approver, dan pelaksana
   pembayaran dipisahkan untuk transaksi material. Self-approval dilarang.
2. **Approval bukan pengganti validasi hukum.** Batas lembur 4 jam/hari dan
   18 jam/minggu adalah hard validation, bukan kondisi yang boleh dilewati
   dengan approval tambahan.
3. **Least privilege.** Approver ditentukan oleh relasi organisasi dan scope
   tenant, cabang, departemen, cost center, serta nilai transaksi.
4. **Fail closed.** Transaksi material berhenti bila approver tidak ditemukan.
   Fallback HR hanya untuk transaksi non-finansial berisiko rendah dan wajib
   tercatat.
5. **Immutable evidence.** Request, nilai, lampiran, chain/step, approver yang
   diharapkan, delegasi, keputusan, alasan, dan waktu keputusan harus dapat
   direkonstruksi setelah organisasi atau konfigurasi berubah.
6. **Tidak boleh mengedit transaksi setelah submit.** Perubahan material
   membatalkan workflow lama dan membuat revisi baru.
7. **Delegasi terbatas.** Delegasi memiliki tanggal, modul, alasan, dan tidak
   boleh menurunkan segregation-of-duties.

## 2. Tier approval yang direkomendasikan

Threshold rupiah di bawah adalah contoh FMCG menengah, bukan angka hukum.
Nilainya wajib dibuat setting per perusahaan dan ditinjau Finance/HR Director.

| Tier | Risiko | Pola minimum |
|---|---|---|
| T0 | Read-only atau koreksi non-material sebelum submit | tanpa approval; audit log |
| T1 | Operasional tim, tanpa dampak finansial material | atasan langsung |
| T2 | Dampak payroll/headcount/cost center terbatas | atasan + pemilik fungsi (HR/Finance) |
| T3 | Nilai besar, perubahan jabatan/upah, hubungan kerja | head fungsi + Director terkait |
| T4 | PHK material, payroll massal, pembayaran sangat besar | Director + BOD/authorized signatory |

## 3. Matriks approval target

| Domain/transaksi | Kondisi | Level target | Rekomendasi approver dan kontrol |
|---|---|---:|---|
| Cuti tahunan/sakit | ≤3 hari, tidak bentrok blackout | T1 | Supervisor; HR hanya exception |
| Cuti panjang/khusus | >3 hari, unpaid, maternity, atau saldo exception | T2 | Supervisor → HR; Manager bila operasi pabrik/distribusi terdampak |
| Pembatalan cuti approved | sebelum payroll lock | T1/T2 | Supervisor; HR bila attendance sudah terbentuk |
| Lembur | selalu sebelum kerja | T1 | Perintah Supervisor + persetujuan digital pekerja; hard stop batas legal |
| Lembur hari libur/biaya tinggi | threshold jam/biaya tercapai | T2 | Supervisor → Plant/Branch Manager atau HR |
| Koreksi absensi | periode payroll terbuka | T2 | Supervisor → HR/Timekeeper |
| Koreksi absensi | payroll locked/paid | T3 | HR Payroll → Finance/HR Head; wajib adjustment trail, tidak overwrite |
| Perjalanan dinas domestik | ≤Rp10 juta | T2 | Supervisor → cost-center owner |
| Perjalanan dinas besar/luar negeri | >Rp10 juta / overseas | T3 | Department Head → Finance → Director |
| Reimbursement | ≤Rp1 juta | T1 | Supervisor |
| Reimbursement | >Rp1–10 juta | T2 | Supervisor → Finance/AP |
| Reimbursement | >Rp10 juta atau policy exception | T3 | Department Head → Finance Head/Director |
| Pinjaman/kasbon karyawan | semua | T2 | Supervisor → HR (eligibility) → Finance (capacity/disbursement) |
| Payroll regular | semua periode | T3 | Payroll maker → HR Head checker → Finance approver |
| Payroll variance material | net/company > threshold | T4 | HR Director → Finance Director/BOD |
| Payroll adjustment/off-cycle | semua | T2/T3 | maker ≠ checker; Finance untuk dampak kas |
| Disbursement payroll | semua | T3/T4 | Finance executor berbeda dari payroll maker/approver; bank maker-checker |
| Bonus/insentif | ≤budget dan ≤threshold | T2 | Business owner → HR C&B → Finance |
| Bonus exception/nilai besar | di luar budget / >threshold | T4 | HR Director → Finance Director → BOD |
| MPP/headcount replacement | within approved budget | T2 | Department Head → HR |
| MPP headcount baru | di luar budget | T3/T4 | Department Head → HR → Finance → Director/BOD |
| Offer kandidat | dalam salary band | T2 | Hiring Manager → HR |
| Offer exception | di luar band/joining bonus | T3 | Hiring Manager → HR → Finance → Director |
| Hire/convert employee | setelah offer accepted | T2 | HR checker; data payroll/bank diverifikasi terpisah |
| Pelepasan kontrak kerja | tanpa perubahan gaji material | T2 | HR checker; employee menandatangani setelah final approval |
| Kontrak/perubahan gaji material | perubahan gaji atau policy exception | T3 | HR → Finance → Director untuk variance >20% |
| Perubahan jabatan/promosi | tanpa kenaikan material | T2 | old/new manager → HR |
| Promosi/gaji/grade | berdampak kompensasi | T3 | Department Head → HR C&B/Director |
| Mutasi lintas branch/cost center | semua | T2/T3 | old manager → new manager → HR; Finance bila cost center berubah |
| Resignation | voluntary | T2 | Supervisor (handover) → HR final |
| PHK | semua | T3 | HR Director → authorized management |
| PHK/pesangon material | >Rp100 juta (contoh) | T4 | HR Director → Legal/IR → BOD |
| Surat peringatan/disiplin | SP1–SP2 | T2 | Manager → HR/IR |
| SP3/termination recommendation | semua | T3 | HR/IR Head → Director |
| Training | dalam budget | T1/T2 | Supervisor → budget owner |
| Training mahal/luar negeri | threshold tercapai | T3 | Department Head → HR L&D → Finance/Director |
| Goal/performance review | semua | T1/T2 | Employee → Manager; calibration untuk rating/bonus |
| IDP/succession | nomination key position | T2/T3 | Manager → Talent/HR → calibration committee |
| Asset assignment/return | perangkat standar | T1 | custodian/IT acknowledgement |
| Asset write-off/hilang | berdampak nilai | T2/T3 | custodian → Finance → authorized manager |
| Data bank/NPWP/NIK | perubahan sensitif | T2 | employee/HR maker → HR verifier; masking dan evidence |
| Salary/component/master rate | semua | T3 | HR C&B maker → HR Head/Finance checker |
| Role/permission/approval chain | semua | T3 | admin maker → security/system owner; tidak boleh self-grant |
| Export data pribadi massal | semua | T3 | data owner → privacy/security officer; expiry dan watermark |
| Integrasi/webhook/bank credential | semua | T3/T4 | system admin → security owner; dual control untuk secret rotation |

## 4. Hasil audit repository

### 4.1 Sudah memiliki workflow bertingkat khusus

- MPP/manpower request: step table, expected approver, delegasi, reject/skip.
- JIC/promosi/demosi/mutasi: supervisor/department head/HR/BOD sesuai kondisi.
- Transfer karyawan: workflow dedicated dan effective-date execution.
- Resignation: supervisor lalu HR.
- Termination: HR Director dan BOD untuk nilai pesangon besar.
- Payroll period: submit/approve/four-eyes, snapshot, threshold, break-glass.
- Compensation review: proposal dan approval rows.

Workflow khusus dipertahankan sampai tersedia adapter domain yang lengkap.
Jangan memindahkan hanya statusnya ke generic engine karena approval final juga
memiliki side effect (saldo, attendance, payroll snapshot, notifikasi, webhook,
dan posting akuntansi).

### 4.2 Generic engine tersedia tetapi integrasi domain belum seragam

Generic chain mendukung `LEAVE`, `OVERTIME`, `ATTENDANCE_CHANGE`,
`REIMBURSEMENT`, `LOAN`, `BUSINESS_TRIP`, `BONUS`,
`PAYROLL_ADJUSTMENT`, `COMPENSATION_REVIEW`, `TRAINING_REGISTRATION`,
`TIMESHEET`, `CANDIDATE_OFFER`, `GENERATED_CONTRACT`, `ASSET_TRANSACTION`, dan
`HIRE_CONVERSION`, `PERFORMANCE_GOAL`, `IDP_ACTIVATION`,
`EMPLOYEE_SENSITIVE_CHANGE`, `MASTER_RATE_CHANGE`, dan `PRIVILEGED_ACTION`,
termasuk kondisi, assignment organisasi, delegasi,
notifikasi, dan inbox. Dari
audit kode:

- Leave, overtime, attendance change, dan reimbursement membuat
  `approval_requests` ketika submit. Endpoint keputusan domain untuk keempat
  modul tersebut memakai engine yang sama dengan Inbox generic.
- Modul yang sudah diadaptasi memakai lifecycle request dan finalisasi domain
  yang sama; workflow khusus di luar katalog masih perlu adapter bertahap.
- Business Trip/SPPD sudah memakai module catalog dan adapter engine yang sama;
  submit fail-closed, final approval membuat snapshot SPPD, dan settlement yang
  menghasilkan reimbursement otomatis juga membuat workflow reimbursement.

Konsekuensinya: **Inbox generic menjadi source of truth final hanya untuk modul
yang sudah memiliki domain adapter**. Modul baru wajib fail-closed dan baru
boleh diaktifkan di Inbox setelah adapter serta tes side effect-nya tersedia.

Status implementasi P0 per 16 Juli 2026:

- **Leave, Overtime, Attendance Change, Reimbursement, Loan, Business Trip,
  Bonus, Payroll Adjustment, Compensation Review, Training Registration,
  Timesheet, Candidate Offer, Generated Contract, Asset Transaction, Hire
  Conversion, Performance Goal, IDP Activation, Employee Sensitive Change,
  Master Rate Change, dan Privileged Action:
  adapter tersedia.** Final decision engine dan perubahan
  status serta side effect domain berjalan dalam satu transaksi. Untuk Leave
  mencakup saldo/attendance; untuk Overtime mencakup kalkulasi uang atau kredit
  cuti pengganti; Attendance Change menerapkan optimistic snapshot dan menolak
  overwrite periode payroll FINALIZED/PAID/CLOSED; Reimbursement mengecek ulang
  plafon benefit dan mengubah `used_amount` secara optimistic saat keputusan
  final; Loan membentuk jadwal cicilan hanya pada keputusan final dan menjaganya
  dari duplikasi. Endpoint domain tidak lagi dapat memfinalkan transaksi tanpa
  request approval PENDING.
- Publikasi notification/webhook final memakai `approval_outbox_events` yang
  ditulis dalam transaksi final yang sama. Worker
  `php spark approval-outbox:process --batch=50` memublikasikan event setelah
  commit, memakai event ID stabil, exponential retry, dan reclaim lease worker
  yang stale tanpa mengulang side effect domain.

### 4.3 Workflow legacy yang tetap perlu dipantau

- Beberapa attendance correction yang masih memakai jalur legacy.
- Jalur baru yang mengekspor PII lintas karyawan wajib didaftarkan ke filter
  `privilegedapproval:PII_EXPORT`; self-service export milik user sendiri tetap
  T0 karena ownership scope dan throttle berlaku.
- Jalur konfigurasi integration baru wajib memakai
  `privilegedapproval:INTEGRATION`; secret tidak boleh masuk metadata approval,
  notification, webhook outcome, atau audit diff.

## 5. Perbaikan fondasi yang diterapkan pada audit ini

1. Menambahkan `ApprovalModuleCatalog` sebagai allowlist modul dan tabel entitas.
   Payload tidak dapat lagi menunjuk tabel arbitrer atau memasangkan modul dengan
   tabel yang salah.
2. Memvalidasi bahwa employee dan entitas benar-benar berada di tenant yang
   sama sebelum workflow dibuat.
3. Menambahkan idempotency check agar retry submit tidak membuat dua request
   PENDING untuk transaksi yang sama.
4. Melarang self-approval, termasuk ketika user adalah super-admin.
5. Mewajibkan alasan reject minimal lima karakter di service, bukan hanya UI.
6. Memperketat resolver supervisor, manager, department head, work location,
   specific user, dan specific employee dengan scope company.
7. Mengubah fallback approver dari “HR atau Manager” menjadi hanya HR.
8. Melarang edit step/module dan delete chain selama masih ada request PENDING.
   Perubahan policy harus dilakukan dengan membuat versi chain baru.
9. Menambahkan regression test untuk module/table allowlist, tenant integrity,
   four-eyes, fallback HR, dan perlindungan chain aktif.
10. Menyatukan reimbursement ke engine, termasuk cancel workflow, tenant scope,
    alasan reject, recheck plafon pada final approval, dan debit saldo benefit
    tepat satu kali dengan conflict guard.
11. Menyatukan loan/kasbon ke engine. Tanggal cicilan pertama disnapshot saat
    submit, jadwal potongan dibuat tepat satu kali pada final approval, dan
    cancel/reject ikut menutup approval request secara konsisten.
12. Menyimpan `workflow_snapshot` dan `chain_version_hash` saat submit. Urutan
    step, hasil evaluasi kondisi, fallback strategy, serta approver asli tidak
    berubah ketika struktur organisasi/policy diedit. Delegasi tidak dibekukan:
    effective date-nya tetap dievaluasi saat keputusan dilakukan.
13. Menyatukan Business Trip/SPPD ke engine. Default FMCG chain adalah Atasan
    -> Department Head -> Finance dengan `fallback_strategy=BLOCK`; advance,
    tujuan internasional, durasi, dan cost center masuk metadata kondisi.
    Settlement serta reimbursement selisih berjalan dalam satu transaksi.
14. Menyatukan Bonus ke engine. Submit mengunci run sebagai
    `PENDING_APPROVAL`; default chain adalah HR -> Finance -> Direksi untuk
    total di atas Rp100 juta dengan `fallback_strategy=BLOCK`. Final approval
    memvalidasi ulang jumlah recipient dan total, rejection menyimpan alasan,
    dan disburser wajib berbeda dari submitter serta final approver.
15. Menyatukan koreksi payroll dan reversal ke engine. Adjustment menjadi
    `PENDING_APPROVAL` sebelum dapat dikonsumsi payroll, final approval
    memeriksa ulang bahwa periode target masih editable, dan default chain
    memakai HR -> Finance -> Direksi untuk nominal di atas Rp10 juta. Reversal
    tidak lagi auto-approved oleh pembuat dan sumber baru berstatus `REVERSED`
    ketika reversal yang telah disetujui benar-benar diposting ke payroll.
16. Menyatukan Compensation Review ke engine. Default chain FMCG adalah HR C&B
    -> Finance -> Direksi untuk kenaikan di atas Rp2 juta dengan
    `fallback_strategy=BLOCK`. Proposal aktif/approved tidak dapat ditimpa,
    direct approve/reject lama dinonaktifkan, dan final approval memvalidasi
    ulang gaji berjalan serta merit budget sebelum menjadwalkan salary history
    tepat satu kali. Field `approval_levels` dan tabel approval lama hanya
    dipertahankan untuk kompatibilitas; snapshot generic chain menjadi sumber
    kebenaran approver dan urutan keputusan.
17. Menyatukan registrasi Training ke engine. Default chain adalah atasan,
    Department Head dan HR L&D untuk training eksternal, Finance di atas Rp5
    juta, serta Direksi di atas Rp20 juta. Final approval memeriksa ulang biaya,
    kuota, tenant, dan sisa budget; budget baru di-reserve pada keputusan final.
    Cancel pending menutup request engine, sedangkan cancel setelah approved
    melepaskan reserved budget. Maker registrasi dan peserta tidak dapat
    menyetujui transaksi sendiri.
18. Menyatukan Timesheet ke engine. Default chain adalah atasan satu level,
    ditambah Department Head bila entry ditandai overtime. Submit dan final
    approval memvalidasi ulang tenant, employee aktif, project/assignment aktif,
    rentang tanggal project, serta total maksimum 24 jam per hari. Endpoint
    approve/reject lama tetap kompatibel tetapi seluruh keputusan dialirkan ke
     generic engine; permission `timesheet.approve` saja tidak lagi cukup untuk
     melewati expected approver pada workflow snapshot.
19. Menyatukan Candidate Offer ke engine. Offer dalam salary band memakai
    Hiring Manager → HR; offer di luar band atau dengan joining bonus menambah
    Finance → Director. Submit menyimpan hash snapshot isi offer dan salary
    band, final approval memvalidasi ulang kandidat, lowongan, hiring manager,
    serta policy exception sebelum mengubah status menjadi `SENT`. Kandidat baru
    menerima notifikasi setelah final approval; penolakan approval internal
    kembali ke pembuat dan disimpan sebagai `APPROVAL_REJECTED`, terpisah dari
    penolakan kandidat. Withdraw saat pending juga menutup request engine.
20. Menyatukan pelepasan dan pencabutan Generated Contract ke engine. HR tidak
    lagi dapat mengubah `DRAFT` langsung ke `SENT` atau menandai `SIGNED` atas
    nama karyawan. Default chain adalah HR; Finance ditambahkan bila snapshot
    kontrak mengubah gaji, dan Director bila variance absolut di atas 20% atau
    kontrak dicabut. Hash HTML dan variable snapshot serta gaji berjalan
    diperiksa ulang pada final decision. Penolakan pencabutan memulihkan status
    `SENT`/`SIGNED`, sedangkan tanda tangan hanya melalui endpoint employee.
21. Menyatukan assignment dan return Asset ke engine. Transaksi standar memakai
    approval atasan; return berstatus `DAMAGED`/`LOST` menambah HR/custodian dan
    Finance, serta Director untuk kehilangan aset di atas Rp50 juta. Selama
    pending, asset master tidak dapat diedit/dihapus dan belum berpindah status.
    Final approval memeriksa hash asset serta assignment aktif, baru kemudian
    membuat/menutup assignment, mengubah cached pointer/status, dan membentuk
    snapshot BAST tepat satu kali. Penolakan tidak mengubah kondisi fisik asset.
22. Menyatukan Hire/employee conversion ke engine. Konversi hanya dapat diajukan
    setelah kandidat menerima offer; default chain adalah HR checker, ditambah
    Director bila join date berbeda dari offer yang diterima. Gaji, status
    kerja, posisi, departemen, dan branch tidak boleh dioverride dari accepted
    offer; perubahan material wajib melalui offer baru yang kembali diterima
    kandidat. NPWP, rekening bank, dan payload onboarding disimpan terenkripsi
    dengan hash snapshot. Selama pending, kandidat tidak dapat diedit,
    dipindahkan stage, ditolak, atau dihapus. Employee, dokumen, onboarding,
    leave balance, pre-allocation asset, kontrak, job fill, dan sinkronisasi MPP
    baru dijalankan pada final approval dalam transaksi domain yang sama;
    notification/webhook final dipublikasikan lewat transactional outbox.
23. Menyatukan Performance Goal ke engine. Pemilik goal membuat dan mengedit
    `DRAFT`, lalu mengajukannya menjadi `PENDING_APPROVAL`; manager tidak lagi
    dapat mengubah `DRAFT` langsung ke `APPROVED`. Default chain adalah atasan
    langsung dengan `fallback_strategy=BLOCK`. Submit dan final approval
    memvalidasi tenant employee/cycle/parent goal, periode goal setting, deadline,
    bobot per goal, dan total bobot 100% per employee-cycle. Isi material goal
    serta cycle disimpan sebagai hash snapshot. Goal pending/approved tidak dapat
    diedit atau dihapus; rejection menyimpan alasan dan membuka revisi serta
    resubmit. Keputusan final dan outcome outbox berjalan dalam transaksi engine.
24. Menyatukan aktivasi IDP ke engine sekaligus memperketat tenant isolation.
    Seluruh operasi load, create-from-review, create, update, complete, cancel,
    dan delete kini membutuhkan company scope eksplisit. Manager/HR menyusun IDP
    sebagai maker, lalu HR Talent checker yang berbeda menyetujui aktivasi;
    direct `DRAFT` ke `ACTIVE` dinonaktifkan. Submit memvalidasi periode serta
    minimum satu action item dengan action, owner, dan due date, kemudian
    menyimpan hash snapshot IDP beserta cycle/review. Selama pending IDP tidak
    dapat diedit atau dihapus. Final approval baru mengubah status menjadi
    `ACTIVE`; rejection membuka revisi dan resubmit dengan alasan tercatat.
25. Memperluas Asset Transaction untuk retirement/write-off. Status asset tidak
    lagi dapat diubah langsung menjadi `RETIRED`, dan asset `AVAILABLE` tidak
    boleh dihapus untuk melewati disposal. Custodian mengajukan tanggal, alasan,
    metode disposal, nilai buku, proceeds, serta referensi bukti; payload dan
    master asset di-hash saat submit. Chain memakai atasan → HR/custodian →
    Finance, ditambah Director bila write-off di atas Rp50 juta. Final approval
    memastikan asset tidak kembali di-assign, lalu menyimpan nilai buku,
    proceeds, write-off, bukti, maker, approver, dan timestamp sebelum status
    menjadi `RETIRED`. Penolakan tidak mengubah status fisik maupun nilai asset.
26. Menyatukan perubahan data sensitif employee ke engine. PUT employee biasa
    menolak perubahan employee number/login identifier, NIK KTP, NPWP, rekening,
    bank, BPJS, dan metode pajak. Payload request disimpan terenkripsi; hanya
    nama field dan hash yang masuk metadata/inbox/outbox. HR menjadi checker,
    Finance ditambahkan untuk field payroll, dan perubahan login identifier
    mendapat checker tambahan. Snapshot employee diverifikasi ulang pada final
    approval. `basic_salary` sengaja tidak menerima shortcut ini dan wajib
    melalui Compensation Review agar effective date serta salary history tetap
    lengkap.
27. Menyatukan master rate payroll ke engine. Create/update/delete allowance
    type, allowance rate, dan payroll component sekarang membuat staging request
    `PENDING_APPROVAL`; master aktif tidak berubah sebelum Finance checker final.
    Penghapusan menambah Director, payload serta target di-hash, perubahan stale
    diblokir, tenant reference divalidasi, dan allowance company baru dihitung
    ulang tepat setelah final approval.
28. Menambahkan approval sekali pakai untuk privileged action. Mutasi role/user
    dan permission mapping, outbound/inbound webhook termasuk secret rotation,
    company/audit/compliance export, serta bank/PPH21/BPJS export kini ditahan
    filter sebelum controller. Otorisasi terikat pada tenant, maker, action
    group, HTTP method, path, query, dan body hash; berlaku 24 jam serta berubah
    menjadi `USED` sebelum aksi yang identik dijalankan. Request pertama
    mengembalikan HTTP 202, lalu maker mengulangi payload yang sama setelah
    approval dengan Idempotency-Key baru. Metadata tidak menyimpan body/PII/
    secret. User dan role controller juga diperketat terhadap referensi
    employee/role lintas tenant.

## 6. Konfigurasi chain FMCG yang disarankan

Gunakan `fallback_strategy=BLOCK` untuk payroll, bonus, reimbursement, loan,
perubahan kompensasi, termination, dan semua transaksi T3/T4. `HR_FALLBACK`
hanya layak untuk leave/attendance non-material saat struktur atasan belum
lengkap. `SKIP` tidak boleh dipakai pada step wajib.

Contoh metadata kondisi:

```json
{
  "amount": 12500000,
  "days": 5,
  "hours": 3,
  "is_policy_exception": false,
  "is_overseas": false,
  "changes_cost_center": true
}
```

Semua threshold harus disimpan sebagai setting tenant dengan currency IDR,
effective date, pembuat, approver perubahan setting, dan audit log.

## 7. Rencana rollout teknis

### P0 — sebelum Inbox generic dipakai luas

1. `ApprovalDomainAdapterInterface`, registry, kontrak cancel, transactional
   outcome outbox, dan snapshot/version chain sudah tersedia.
2. Adapter Leave, Overtime, Attendance Change, Reimbursement, Loan, Business
   Trip, Bonus, Payroll Adjustment, Compensation Review, Training Registration,
   Timesheet, Candidate Offer, Generated Contract, Asset Transaction, Hire
   Conversion, Performance Goal, IDP Activation, Employee Sensitive Change,
   Master Rate Change, dan Privileged Action sudah tersedia. Pertahankan invariant bahwa satu keputusan
   mengubah engine + domain dalam satu transaksi.
3. Snapshot payload, chain version, kondisi, dan expected approver sudah
   disimpan saat submit; pertahankan kompatibilitas untuk request legacy.
4. Tambahkan optimistic lock/idempotency key dan unique active request di DB.
5. Nonaktifkan bulk approval untuk payroll, bonus, PHK, perubahan upah, dan
   transaksi policy exception.

### P1 — transaksi finansial dan lifecycle

1. Adapter lifecycle material lain di luar katalog aktif dan inventaris jalur
   legacy yang masih mengubah status domain secara langsung.
2. Threshold tenant untuk amount/headcount/salary variance/severance.
3. Enforcement maker-checker-disburser lintas modul.
4. SLA reminder, escalation, out-of-office delegation, dan queue monitoring.

### P2 — governance lanjutan

1. Quarterly review chain perubahan master sensitif, RBAC, export PDP, dan
   integrasi; uji sampling bahwa route baru tidak melewati privileged filter.
2. Dashboard aging, bottleneck, reject reason, delegation usage, self-approval
   attempts, break-glass, dan policy exceptions.
3. Quarterly access review dan sampling audit keputusan.

## 8. Skenario uji wajib

- Requester juga supervisor/HR/super-admin: keputusan ditolak.
- Employee/entity berasal dari tenant lain: submit ditolak.
- Retry request yang sama: request PENDING tidak terduplikasi.
- Chain diubah/dihapus ketika ada request PENDING: HTTP 409.
- Approver tidak ditemukan pada transaksi material: status BLOCKED/PENDING,
  tidak auto-approved.
- Delegasi expired, cross-tenant, cross-module, atau delegate = requester:
  keputusan ditolak.
- Nilai dinaikkan setelah submit: snapshot mismatch membatalkan workflow.
- Dua approver mengklik bersamaan: tepat satu keputusan berhasil.
- Reject tanpa alasan bermakna: ditolak.
- Payroll locked/paid: koreksi menciptakan adjustment, tidak menimpa histori.
- Lembur melebihi batas legal: hard reject walau approver/BOD menyetujui.
- Final approval domain: side effect, notification, webhook, dan audit log
  terjadi tepat sekali.
- Privileged action diulang dengan path/query/body berbeda: approval lama tidak
  dapat dipakai; approval yang sama hanya dapat dikonsumsi sekali dan expired
  setelah 24 jam.
- Payload sensitif employee tidak muncul di approval request, notification,
  webhook, audit description, atau outbox; ciphertext baru didekripsi pada
  finalisasi domain.

### 8.1 Audit kesiapan operasional

Setelah migration dan seed dijalankan, readiness setiap tenant wajib diperiksa
dari direktori `backend`:

```bash
php spark approval:readiness --company=1
```

Audit ini memeriksa default chain aktif, keberadaan step, kandidat approver
global berdasarkan role, cakupan atasan langsung, department head, dan hiring
manager pada job posting aktif. Exit code bukan nol menandakan chain tanpa step
atau step global wajib yang tidak memiliki kandidat aktif. Gap reporting line
tetap ditampilkan untuk ditindaklanjuti, tetapi tidak otomatis dianggap gagal
karena pucuk struktur organisasi memang dapat tidak memiliki supervisor.

Hasil tenant PT Quantara Nusantara Sejahtera pada 16 Juli 2026:

- migration `2026-09-01-000048` sudah mengoreksi role approver default dari
  Super Admin generik menjadi CEO, CFO, HR Director, IT Admin, atau
  Legal/Compliance sesuai jenis transaksi;
- 20 default chain aktif dan seluruh step global mempunyai kandidat aktif;
- department-head coverage 70/70 dan supervisor coverage 69/70;
- satu reporting-line root adalah `EMP-0001 - Sumarno Hadiwijaya`, Komisaris
  Utama. Tidak diberi supervisor secara artifisial; transaksi employee yang
  mensyaratkan supervisor untuk root ini harus tetap fail-closed dan memakai
  chain khusus yang disahkan perusahaan bila memang diperlukan;
- belum ada job posting berstatus OPEN/PAUSED, sehingga hiring-manager coverage
  0/0. Setiap posting baru wajib memiliki hiring manager aktif sebelum offer
  dapat diajukan;
- hasil akhir `failures=0`.

Perintah ini wajib dijalankan setelah perubahan struktur organisasi, aktivasi
atau deaktivasi user approver, perubahan role, deployment migration approval,
dan sebelum UAT/go-live. Hasilnya adalah pemeriksaan konfigurasi, bukan
pengganti UAT skenario keputusan dan side effect pada bagian 8.

Pelaksanaan dan bukti sign-off mengikuti
[`APPROVAL_UAT_RUNBOOK_FMCG_INDONESIA.md`](../05_Security_Audit_Test/APPROVAL_UAT_RUNBOOK_FMCG_INDONESIA.md).

## 9. Dasar regulasi dan batas interpretasi

- PP 35/2021 Pasal 26 membatasi lembur dan Pasal 28 mensyaratkan perintah
  pengusaha serta persetujuan pekerja secara tertulis atau media digital.
- PP 35/2021 juga menjadi dasar workflow PKWT, waktu kerja, dan PHK.
- UU 27/2022 mewajibkan pengendali menjaga kerahasiaan, mengawasi pihak yang
  memproses data, melindungi dari pemrosesan tidak sah, dan mencegah akses tidak
  sah. Approval perubahan data sensitif adalah salah satu kontrol internal,
  bukan satu-satunya kewajiban PDP.
- PP 36/2021 tentang Pengupahan telah diubah oleh PP 51/2023 dan perubahan
  kedua PP 49/2025. Formula/payroll engine perlu ditinjau terpisah ketika
  setting pengupahan berubah; jumlah level approval tetap kebijakan internal.

Dokumen ini adalah desain kontrol aplikasi, bukan pendapat hukum. HR/IR, Legal,
Finance, dan auditor perusahaan harus mengesahkan threshold, authorized
signatory, Peraturan Perusahaan/PKB, serta pengecualian sektor sebelum go-live.

Sumber primer:

- [PP 35 Tahun 2021 — BPK RI](https://peraturan.bpk.go.id/Details/161904/pp-no-35-tahun-2021)
- [UU 27 Tahun 2022 tentang Pelindungan Data Pribadi — BPK RI](https://peraturan.bpk.go.id/Details/229798/uu-no-27-tahun-2022)
- [PP 51 Tahun 2023, perubahan PP 36/2021 — BPK RI](https://peraturan.bpk.go.id/Details/270269/pp-)
- [PP 49 Tahun 2025, perubahan kedua PP 36/2021 — BPK RI](https://peraturan.bpk.go.id/Details/339037/pp-no-49-tahun-2025)
