# Quantara HRIS — Rencana Pengembangan & Improvement

> Dokumen ini berisi hasil telaah fitur existing dan rencana pengembangan menyeluruh,
> mencakup perbaikan bug/UX, peningkatan fitur yang ada, dan penambahan kapabilitas baru.
> Dibuat berdasarkan analisis dokumen `Quantara HRIS — Daftar Fitur & Business Flow End-to-End`.

---

## Ringkasan Eksekutif

Quantara HRIS telah memiliki fondasi yang kuat dengan cakupan modul A–M yang mencakup
seluruh employee lifecycle dari MPP hingga exit. Sistem sudah berjalan di atas stack
modern (CI4 + PHP 8.5, Vue 3 + Vite + Tailwind, MySQL 8) dengan 607 tests dan 1.396
assertions. Sprint 6 baru saja menyelesaikan Cost Center — modul paling kompleks dari
sisi akuntansi HR.

Rencana ini dibagi menjadi **4 Horizon** berdasarkan urgensi dan dampak bisnis:

| Horizon | Fokus | Estimasi |
| --- | --- | --- |
| H1 — Quick Wins | Perbaikan & peningkatan fitur existing | Sprint 7–9 |
| H2 — Core Deepening | Pendalaman modul strategis | Sprint 10–13 |
| H3 — New Capabilities | Modul baru bernilai tinggi | Sprint 14–18 |
| H4 — Platform Evolution | Transformasi skala enterprise | Sprint 19+ |

---

## TELAAH GAP & TEMUAN PER MODUL

### 🔍 A. Master Data & Konfigurasi

**Gap yang ditemukan:**
- `Shifts & Shift Patterns` belum memiliki validasi overlap shift antar karyawan
- `Leave Types` dengan `is_carry_over_allowed` belum ada konfigurasi batas maksimal
  saldo yang bisa di-carry over (mis. max 12 hari)
- `Approval Chains` bersifat per kategori transaksi, belum mendukung approval
  kondisional berbasis nilai nominal (mis. reimbursement > Rp 5jt → tambah satu layer)
- `Holidays` tidak ada fitur import dari Google Calendar atau API hari libur resmi
- `Contract Templates` belum ada versioning (audit trail template lama)

### 🔍 B. Recruitment & Hiring

**Gap yang ditemukan:**
- Pipeline kandidat masih satu dimensi; tidak ada tracking waktu per stage
  (berapa hari rata-rata di SCREENING? di INTERVIEW?)
- Tidak ada **candidate scoring** otomatis berdasarkan hasil online test + kriteria bobot
- Career Portal tidak ada fitur **referral karyawan internal** (employee referral program)
- Anti-cheat online test belum mencakup deteksi wajah via webcam (proctoring)
- Tidak ada **talent pool / database kandidat** untuk lamaran spontan atau kandidat
  yang direject tapi potensial

### 🔍 C. Onboarding & Profil Karyawan

**Gap yang ditemukan:**
- Onboarding tasks masih linear; belum bisa dikonfigurasi task yang bergantung
  satu sama lain (dependency task)
- Tidak ada **buddy system** — penunjukan mentor/buddy untuk karyawan baru
- Welcome email hanya berisi credential; belum ada **portal orientasi** dengan
  konten company culture, org chart, dan panduan hari pertama
- Foto profil karyawan belum ada validasi resolusi/ukuran file minimum
- Belum ada **digital signing workflow** yang terintegrasi (saat ini e-sign masih
  diasumsikan sederhana, belum ada audit trail tanda tangan digital berstandar hukum)

### 🔍 D. Operasional Harian

**Gap yang ditemukan:**
- Attendance geofence berbasis branch; belum ada **multi-location check-in** untuk
  karyawan yang bekerja di client site atau lokasi sementara
- `Anomaly Detection` sudah ada tapi belum ada **ML-based scoring** untuk
  pola anomali yang lebih kompleks (mis. konsisten telat setiap Senin)
- Overtime belum ada **pre-approval** sebelum lembur dilakukan (saat ini approve
  setelah fakta); regulasi PP 35/2021 sebetulnya mensyaratkan perencanaan
- `Helpdesk Tickets` belum ada SLA timer dan eskalasi otomatis
- Reimbursement tidak ada **OCR receipt** — karyawan masih input manual nominal
- Business Trip (SPPD) belum ada integrasi booking hotel/tiket (bahkan link ke
  platform travel)
- Belum ada fitur **work from home (WFH) tracking** — hanya geofence, belum ada
  mode WFH resmi dengan approval

### 🔍 E. Compensation & Benefits

**Gap yang ditemukan:**
- Payroll hanya mendukung siklus bulanan; belum ada **bi-weekly payroll** untuk
  jenis karyawan tertentu (mis. harian lepas)
- `Bank Export` format tersedia untuk 6 bank besar, tapi belum ada format
  **multi-disbursement** (karyawan dengan rekening lebih dari satu untuk alokasi
  berbeda)
- PPh21 belum ada simulasi **what-if** (mis. "berapa PPh21 jika bonus Rp X ditambah?")
- THR generator sudah ada tapi belum ada **dashboard THR planning** yang menampilkan
  proyeksi total biaya THR sebelum dieksekusi
- `Allowance Types & Rates` sudah per branch × grade, tapi belum ada
  **allowance berbasis jarak tempuh** (transport allowance by distance GPS)
- Tidak ada modul **Flexible Benefits** — karyawan memilih sendiri komposisi
  benefit dari pool yang ditetapkan perusahaan

### 🔍 F. Performance & Development

**Gap yang ditemukan:**
- Goals (OKR/KPI) belum ada **cascading** — goals atasan belum bisa dipecah
  menjadi goals bawahan secara terstruktur
- Performance Reviews belum ada **calibration session** — sesi di mana semua
  manager menyejajarkan rating sebelum finalisasi
- IDP (Individual Development Plan) sudah auto-bootstrap tapi konten masih
  teks bebas; belum ada **library kompetensi** yang bisa dipilih
- Training catalog belum ada integrasi dengan platform LMS eksternal
  (Moodle, TalentLMS, dll)
- Tidak ada **360° feedback** dari rekan sebaya (peer review), hanya
  self + supervisor

### 🔍 G. Disiplin & Mutasi

**Gap yang ditemukan:**
- Disciplinary Records belum ada **tracking recidivism** — apakah karyawan
  yang SP1 akhirnya SP2/SP3? Berapa rata-rata waktu antar SP?
- JIC belum ada **effective date versioning** — history perubahan jabatan
  tersimpan tapi belum ada tampilan timeline yang jelas per karyawan
- Employee Transfer belum ada **mass transfer** — mutasi satu departemen
  ke departemen lain karena restrukturisasi

### 🔍 H. Resignation, PHK & Offboarding

**Gap yang ditemukan:**
- Resignation belum ada **counter offer flow** — HR bisa mengajukan counter
  sebelum resign disetujui
- Exit Interview data sudah ada (eNPS, would_rehire) tapi belum ada
  **analytics dashboard** yang mengagregasi data turnover secara meaningful
  (cohort analysis, survival curve)
- Offboarding clearance masih manual-confirm; belum ada **QR scan asset return**
  untuk efisiensi fisik
- Severance Calculator sudah komprehensif tapi belum ada **simulasi scenario**
  (karyawan bisa melihat proyeksi pesangon mereka sendiri jika resign
  sekarang vs 1 tahun lagi)

### 🔍 I. Compliance, Reporting & Cost Tracking

**Gap yang ditemukan:**
- Laporan WLKP masih export; belum ada integrasi langsung ke **Sisnaker**
  (sistem daring Kemnaker)
- Cost Center Budget vs Actual sudah kuat (Sprint 6) tapi belum ada
  **drill-down interaktif** ke level transaksi individual
- Tidak ada **prediktif analytics** — sistem belum bisa memprediksi,
  mis. "dengan tren ini, cost center X akan over-budget di bulan Oktober"
- Audit Logs belum ada **anomaly detection pada log** itu sendiri
  (mis. user yang tiba-tiba export data massal di luar jam kerja)

### 🔍 J. Self-Service & Notification

**Gap yang ditemukan:**
- Approval Inbox sudah ada tapi belum ada **bulk approval** — setiap
  approval harus satu per satu
- Belum ada **mobile app native** (saat ini hanya web responsif); untuk
  clock-in/out, push notification native akan jauh lebih reliable
- Notification belum mendukung **WhatsApp / Telegram** sebagai channel
- Belum ada **ESS dashboard** yang contextual — tampilkan info yang relevan
  dengan hari ini (mis. "hari ini jadwal shift kamu: 08:00–17:00, kamu
  belum clock-in")

### 🔍 K. Security & Privacy

**Gap yang ditemukan:**
- 2FA baru mendukung TOTP; belum ada **passkey / FIDO2** support untuk
  autentikasi yang lebih modern
- UU PDP sudah ada consent tracking tapi belum ada **DPIA (Data Protection
  Impact Assessment)** workflow yang terdokumentasi
- Tidak ada **session management dashboard** — user tidak bisa melihat
  sesi aktif dari perangkat mana saja dan me-revoke secara mandiri
- Enkripsi NIK/NPWP/rekening di-AES-256-GCM; belum ada evaluasi apakah
  key rotation sudah terjadwal secara otomatis

### 🔍 L. Integrations

**Gap yang ditemukan:**
- Outbound Webhooks sudah ada tapi belum ada **webhook event catalog**
  yang bisa dikonfigurasi per perusahaan (event apa saja yang di-subscribe)
- Belum ada integrasi resmi dengan **ERP/Accounting** (SAP, Accurate, Odoo)
  untuk posting jurnal biaya HR otomatis
- File Storage sudah S3-ready tapi belum ada **CDN layer** untuk
  akses file yang lebih cepat di multi-region
- Belum ada **Open API / Developer Portal** untuk pelanggan enterprise
  yang ingin membangun integrasi sendiri

---

## HORIZON 1 — QUICK WINS (Sprint 7–9)

> Perbaikan dengan effort rendah, dampak tinggi. Mayoritas adalah enhancement
> fitur yang sudah ada tanpa perubahan arsitektur besar.

### H1.1 — Bulk Approval di Approval Inbox

**Modul:** J. Self-Service
**Latar Belakang:** Manager dengan tim besar (>20 orang) sangat terbebani
harus approve cuti/lembur satu per satu.

**Rencana:**
- Tambah checkbox multi-select di approval inbox
- Endpoint `POST /approvals/bulk-approve` dengan array `approval_ids`
- Validasi: semua item harus dalam approval step yang sama untuk user tersebut
- Audit log per item tetap individual, bukan satu log bulk
- UI: tombol "Setujui Semua yang Dipilih" dengan konfirmasi dialog

**Acceptance Criteria:**
- Manager bisa pilih hingga 50 approval sekaligus
- Jika ada 1 item gagal (mis. data tidak valid), item lain tetap diproses
- Summary result ditampilkan setelah bulk action

---

### H1.2 — Carry-Over Leave Cap

**Modul:** A. Master Data — Leave Types
**Latar Belakang:** `is_carry_over_allowed` ada tapi tidak ada batas maksimal
saldo yang dibawa, membuat karyawan bisa akumulasi saldo cuti bertahun-tahun.

**Rencana:**
- Tambah field `max_carry_over_days` di tabel `leave_types` (nullable = unlimited)
- Modifikasi `hr:run-leave-year-end` untuk memotong saldo carry-over melebihi cap
- Tambah field di UI konfigurasi Leave Types
- Notifikasi H-30 sebelum akhir tahun jika karyawan punya saldo > cap

---

### H1.3 — Approval Kondisional Berbasis Nilai

**Modul:** A. Master Data — Approval Chains
**Latar Belakang:** Reimbursement nominal kecil dan besar saat ini melalui
approval yang sama, padahal kebutuhan bisnis seringkali berbeda.

**Rencana:**
- Tambah `condition_type` dan `condition_value` di `approval_chain_steps`
- Condition types: `amount_gt`, `amount_lte`, `employee_level_gte`, `department_in`
- Jika kondisi tidak terpenuhi, step tersebut di-skip otomatis
- Backward compatible: step tanpa kondisi = selalu aktif (existing behavior)

---

### H1.4 — Time-in-Stage Tracking Recruitment

**Modul:** B. Recruitment
**Latar Belakang:** Tidak ada visibilitas berapa lama kandidat stagnan di setiap
tahap pipeline, padahal ini metrik kunci hiring efficiency.

**Rencana:**
- Tambah tabel `candidate_stage_history` (candidate_id, stage, entered_at, exited_at)
- Populate otomatis setiap kali stage berubah
- Dashboard recruitment: tampilkan rata-rata hari per stage (funnel + bottleneck highlight)
- Alert: kandidat > N hari di stage yang sama → notifikasi ke recruiter

---

### H1.5 — WFH Mode di Attendance

**Modul:** D. Operasional Harian
**Latar Belakang:** Post-pandemi, WFH sudah menjadi bagian dari work arrangement
banyak perusahaan tapi belum ada tracking formal.

**Rencana:**
- Tambah `work_type` ENUM di tabel `attendances`: `ONSITE` | `WFH` | `OFFSITE`
- WFH harus di-approve atasan minimal H-1 (atau sama pagi sebelum jam mulai)
- Geofence check di-skip untuk WFH mode
- Report kehadiran breakdown by work_type per karyawan per bulan
- Konfigurasi per perusahaan: batas maksimal WFH hari per bulan

---

### H1.6 — Overtime Pre-Approval

**Modul:** D. Operasional Harian
**Latar Belakang:** PP 35/2021 dan praktik bisnis yang baik mensyaratkan lembur
direncanakan sebelum dilakukan, bukan diajukan setelah fakta.

**Rencana:**
- Tambah flow baru: `PLANNED` → `APPROVED` → `REALIZED` → `PAID`
- Karyawan/supervisor submit overtime plan (tanggal, estimasi jam, alasan)
- Setelah approve, karyawan clock overtime sesuai rencana yang sudah approve
- Overtime actual hours bisa berbeda dari planned (dengan catatan)
- Existing post-fact overtime masih dipertahankan sebagai opsi (konfigurasi)

---

### H1.7 — Helpdesk SLA & Eskalasi

**Modul:** D. Operasional Harian — Helpdesk Tickets
**Latar Belakang:** Ticket bisa mengendap tanpa ada tekanan waktu penyelesaian.

**Rencana:**
- Tambah `sla_hours` per kategori ticket (mis. payroll issue = 24 jam, IT = 48 jam)
- Cron setiap jam: cek ticket yang mendekati/melebihi SLA
- Eskalasi otomatis: assign ke supervisor agent jika SLA terlampaui
- Dashboard HR: ticket yang overdue SLA di-highlight merah
- Notifikasi ke ticket creator: "Ticket kamu akan diselesaikan dalam X jam"

---

### H1.8 — THR Planning Dashboard

**Modul:** E. Compensation & Benefits
**Latar Belakang:** Finance perlu proyeksi biaya THR jauh sebelum pembayaran.

**Rencana:**
- Endpoint `GET /thr/projection?year=YYYY` yang menghitung proyeksi THR
  per karyawan berdasarkan masa kerja dan komponen gaji saat ini
- Agregasi per department dan cost center
- Export Excel untuk budget planning
- Dashboard: total proyeksi THR vs budget yang disiapkan, breakdown per dept

---

### H1.9 — Notification WhatsApp (via API Gateway)

**Modul:** J. Self-Service & Notification
**Latar Belakang:** Email open rate rendah di Indonesia; WhatsApp jauh lebih efektif
untuk notifikasi time-sensitive (approval, payslip, dll).

**Rencana:**
- Tambah channel `WHATSAPP` di notification preferences
- Integrasi via WhatsApp Business API (Twilio / Wati / Fonnte sebagai provider)
- Provider dikonfigurasi per company (bring your own API key)
- Notification templates untuk WA mengikuti format yang ada
- Rate limit: max 3 pesan WA per user per hari untuk menghindari spam

---

## HORIZON 2 — CORE DEEPENING (Sprint 10–13)

> Pendalaman modul-modul strategis yang sudah ada dengan kapabilitas baru
> yang membutuhkan perubahan model data atau logika bisnis signifikan.

### H2.1 — Mobile App Native (PWA-first, kemudian Native)

**Modul:** J. Self-Service
**Latar Belakang:** Clock-in via browser web di mobile tidak reliable; push
notification native memiliki delivery rate jauh lebih tinggi.

**Fase 1 (Sprint 10) — PWA Enhancement:**
- Service worker untuk offline capability
- Add-to-homescreen prompt
- Web Push notification yang lebih reliable
- Optimize mobile UX untuk attendance, leave request, approval inbox

**Fase 2 (Sprint 12–13) — React Native / Flutter App:**
- Clock-in/out dengan GPS native (lebih akurat dari browser)
- Biometric authentication (Face ID / fingerprint)
- Push notification via FCM/APNs
- Offline mode untuk lihat slip gaji dan saldo cuti

---

### H2.2 — Performance Goals Cascading

**Modul:** F. Performance & Development
**Latar Belakang:** Tanpa cascading, goals karyawan tidak terhubung dengan goals
organisasi secara struktural — hanya teks.

**Rencana:**
- Tambah field `parent_goal_id` di tabel `goals` (self-referential)
- UI: saat buat goal, bisa pilih "align ke goal atasan" dari daftar goals
  supervisor yang visible
- Visualisasi tree/cascade: tampilkan goal hierarchy dari company level
  sampai individual
- Perhitungan progress: progress parent auto-update berdasarkan weighted
  average progress anak
- Alert: goal anak yang behind schedule otomatis flag ke goal parent

---

### H2.3 — 360° Peer Feedback

**Modul:** F. Performance & Development
**Latar Belakang:** Review hanya dari self + supervisor memberikan blind spot yang
besar terutama untuk soft skills dan kolaborasi lintas tim.

**Rencana:**
- Tambah `review_type` ENUM: `SELF` | `SUPERVISOR` | `PEER` | `SUBORDINATE`
- Saat performance cycle dibuka, supervisor nominasi peer reviewer
  (atau karyawan nominasi sendiri dengan approval)
- Peer reviewer menerima form yang lebih ringkas (competency rating + komentar)
- Hasil peer review: aggregated anonymously sebelum ditampilkan ke reviewee
- Bobot peer vs supervisor vs self bisa dikonfigurasi per cycle

---

### H2.4 — Recruitment Candidate Scoring Engine

**Modul:** B. Recruitment
**Latar Belakang:** Recruiter saat ini harus manually membandingkan kandidat.
Scoring otomatis mempercepat shortlisting.

**Rencana:**
- Definisi scoring criteria per job posting:
  - Hasil online test (bobot X%)
  - Kesesuaian ekspektasi gaji dengan range MPP (bobot Y%)
  - Available date vs target start (bobot Z%)
  - Experience years (bobot W%)
- `candidates.composite_score` dihitung ulang setiap ada update data
- Pipeline board bisa di-sort by composite score
- Recruiter tetap bisa override (human-in-the-loop)

---

### H2.5 — Employee Referral Program

**Modul:** B. Recruitment
**Latar Belakang:** Employee referral adalah channel hiring paling efektif dan
paling hemat. Belum ada tracking formal di sistem.

**Rencana:**
- Karyawan bisa share job posting dengan referral link unik
- Kandidat yang apply via referral link ter-tag ke karyawan referrer
- Tracking: status kandidat referral bisa dilihat karyawan referrer
- Referral bonus engine:
  - Trigger: kandidat referral reach stage HIRED + lulus probasi
  - Nominal bonus dikonfigurasi per position/grade
  - Auto-generate bonus run untuk referrer
- Report: top referrers, acceptance rate per referrer

---

### H2.6 — Turnover Analytics & Survival Analysis

**Modul:** H. Resignation + I. Reporting
**Latar Belakang:** Exit Interview data sudah dikumpulkan tapi belum diolah
menjadi insight strategis.

**Rencana:**
- Dashboard Turnover Analytics:
  - **Turnover rate** bulanan/tahunan per department/branch/position
  - **Cohort survival chart**: dari batch hire bulan X, berapa % masih aktif
    setelah 3/6/12/24 bulan?
  - **Average tenure** per department dan reason resign
  - **Voluntary vs Involuntary** breakdown
  - **eNPS trend** dari exit interview
  - **Would-rehire rate** per department/supervisor

- Early warning: identifikasi karyawan dengan "flight risk" score berdasarkan:
  - Lama tidak ada kenaikan gaji/jabatan
  - Anomali attendance meningkat
  - Performance review menurun
  - Tidak ada training aktif

---

### H2.7 — Payroll Simulasi & What-If

**Modul:** E. Compensation & Benefits
**Latar Belakang:** HR dan karyawan perlu bisa melakukan proyeksi gaji tanpa
harus menjalankan payroll sungguhan.

**Rencana:**
- Endpoint `POST /payroll/simulate` — hitung payslip satu karyawan
  dengan parameter override (salary, bonus, tunjangan)
- Bisa diakses HR untuk perencanaan (mis. "berapa total cost jika promosi
  karyawan ini ke grade berikutnya?")
- Bisa diakses karyawan (limited): "berapa take-home jika lembur X jam bulan ini?"
- PPh21 simulation terintegrasi (karena PPh21 paling sering ditanyakan)
- Output: breakdown lengkap sama dengan payslip asli, tapi watermark "SIMULASI"

---

### H2.8 — Reimbursement OCR Receipt

**Modul:** D. Operasional Harian — Reimbursements
**Latar Belakang:** Input manual nominal dari struk memperlambat pengajuan
dan rentan typo.

**Rencana:**
- Upload struk foto/PDF → OCR ekstrak: nominal, tanggal, merchant, mata uang
- OCR engine: Google Vision API atau Tesseract (self-hosted)
- Pre-fill form reimbursement dengan hasil OCR
- Karyawan review dan konfirmasi (human-in-the-loop)
- Jika OCR gagal, fallback ke input manual
- Struk asli tetap wajib dilampirkan (compliance)

---

### H2.9 — Cost Center Drill-Down Interaktif

**Modul:** I. Compliance, Reporting & Cost Tracking
**Latar Belakang:** Laporan Budget vs Actual sudah ada tapi belum bisa ditelusuri
sampai ke transaksi individual.

**Rencana:**
- Dari laporan Cost Center, klik angka → drill-down ke daftar transaksi
- Dari transaksi → klik → lihat dokumen sumber (payslip / reimbursement /
  bonus run / SPPD)
- Filter per periode, per kategori, per status
- Export transaksi drill-down ke CSV/Excel untuk rekonsiliasi accounting
- Breadcrumb navigasi: Root → Directorate → Dept → Kategori → Transaksi

---

## HORIZON 3 — NEW CAPABILITIES (Sprint 14–18)

> Modul-modul baru bernilai tinggi yang memperluas cakupan sistem secara signifikan.

### H3.1 — Modul Kompetensi & Library Jabatan

**Modul Baru:** F. Performance (extension)
**Latar Belakang:** IDP saat ini hanya text field. Tanpa library kompetensi,
perencanaan pengembangan tidak bisa diukur dan di-track secara konsisten.

**Rencana:**
- Master `competencies` (mis. Communication, Problem Solving, Leadership, Technical)
  dengan level 1–5 dan deskriptor per level
- Master `position_competency_profiles` — setiap jabatan punya profil kompetensi
  ideal (expected level per competency)
- Gap analysis: competency aktual karyawan vs profil jabatan → visualisasi radar chart
- IDP di-link ke kompetensi spesifik yang ingin ditingkatkan
- Training catalog di-tag ke kompetensi yang di-develop

---

### H3.2 — Succession Planning

**Modul Baru:** F. Performance + G. Mutasi (extension)
**Latar Belakang:** Perusahaan perlu memastikan setiap posisi kunci punya kandidat
pengganti yang siap.

**Rencana:**
- Definisi **critical positions** (posisi yang wajib ada succession plan)
- Per critical position: daftar successor candidates dengan readiness level:
  `READY_NOW` | `READY_1_YEAR` | `READY_3_YEAR`
- Succession review: dilakukan bersamaan dengan performance cycle tahunan
- Dashboard: coverage ratio (berapa % critical positions yang punya successor)
- Link ke IDP: successor mendapat IDP yang difokuskan ke gap kompetensi
  dengan posisi target

---

### H3.3 — Modul Flexible Benefits (Cafeteria Plan)

**Modul Baru:** E. Compensation (extension)
**Latar Belakang:** Karyawan modern mengharapkan otonomi dalam memilih benefit
sesuai kebutuhan pribadi (yang berbeda antar generasi dan life stage).

**Rencana:**
- Admin definisi **benefit pool** per grade/level (nominal total per tahun)
- Benefit items yang bisa dipilih: asuransi jiwa tambahan, subsidi internet,
  gym membership, voucher buku, dana pensiun tambahan, dll
- Karyawan pilih alokasi benefit di awal tahun atau saat onboarding
- Benefit yang dipilih auto-tag ke payroll sebagai benefit non-cash atau
  reimbursement berkala
- Report: benefit utilization rate per item

---

### H3.4 — Integrasi ERP / Accounting Journal

**Modul Baru:** L. Integrations (extension)
**Latar Belakang:** Saat ini biaya HR dihitung di HRIS tapi harus di-re-entry
manual ke sistem accounting — rentan error dan membuang waktu.

**Rencana:**
- Setelah payroll finalize, generate **jurnal akuntansi otomatis**:
  - Debit: Beban Gaji, Beban BPJS, Beban Tunjangan (per cost center)
  - Kredit: Hutang Gaji, Hutang BPJS, Kas/Bank
- Format jurnal: sesuai standar akuntansi Indonesia (SAK)
- Export ke format yang diterima: Accurate Online, Zahir, Odoo (via API)
- Untuk SAP: generate file iDoc atau CSV dengan mapping COA yang bisa
  dikonfigurasi per perusahaan
- Mapping: `cost_center` HRIS → `profit_center` atau `cost_object` di ERP

---

### H3.5 — Predictive HR Analytics

**Modul Baru:** I. Reporting (extension)
**Latar Belakang:** Laporan saat ini bersifat deskriptif (apa yang sudah terjadi).
Perusahaan perlu insight prediktif (apa yang akan terjadi).

**Rencana:**
- **Attrition Prediction:** model berbasis data historis (tenure, gaji vs market,
  performance trend, attendance anomali, dll) → skor risiko resign per karyawan
- **Cost Overrun Prediction:** berdasarkan tren pengeluaran aktual + committed,
  prediksi bulan mana cost center akan over-budget
- **Hire Time Prediction:** berdasarkan histori MPP, prediksi berapa lama
  posisi tertentu akan terpenuhi
- **Overtime Trend:** prediksi kebutuhan lembur berdasarkan pola historis
  (seasonal, project-based)
- Implementasi: Python microservice (scikit-learn / statsmodels) dengan
  endpoint REST yang dikonsumsi backend CI4

---

### H3.6 — Modul Talent Pool & Talent Marketplace Internal

**Modul Baru:** B. Recruitment + F. Performance (extension)
**Latar Belakang:** Sebelum buka lowongan eksternal, perusahaan seharusnya
cek apakah ada karyawan internal yang cocok.

**Rencana:**
- **Talent Pool:** semua karyawan punya profile skills dan aspirasi karir
- **Internal Job Board:** lowongan yang pertama ditawarkan ke karyawan internal
  sebelum dibuka ke publik
- Karyawan bisa apply ke internal posting, tracking terpisah dari eksternal
- Matching engine: saran karyawan internal yang cocok berdasarkan kompetensi
  dan aspirasi karir saat MPP dibuat
- HRBP bisa buat **talent map**: visualisasi karyawan berdasarkan performance
  × potential (9-box grid)

---

### H3.7 — Digital Signing Berstandar Hukum

**Modul Baru:** C. Onboarding (enhancement major)
**Latar Belakang:** E-sign saat ini belum dijelaskan standar hukumnya.
UU ITE dan Perpres 95/2018 mensyaratkan tanda tangan elektronik tersertifikasi.

**Rencana:**
- Integrasi dengan Penyelenggara Sertifikasi Elektronik (PSrE) yang diakui
  Kominfo: PrivyID, Vida, atau Peruri Digital
- Workflow: dokumen digenerate → dikirim ke PSrE → karyawan sign dengan
  identitas terverifikasi → dokumen tersegel dengan timestamp + certificate
- Audit trail: siapa sign, kapan, dari IP mana, dengan sertifikat apa
- Cakupan: generated contracts, contract amendments (JIC), severance agreement,
  offer letter
- Fallback: jika PSrE gagal, masih bisa wet-sign + scan upload

---

### H3.8 — Open API & Developer Portal

**Modul Baru:** L. Integrations (extension)
**Latar Belakang:** Pelanggan enterprise sering punya sistem internal yang perlu
bertukar data dengan HRIS.

**Rencana:**
- Dokumentasi API lengkap di **developer portal** (Swagger UI / Redoc)
- API key management: buat, rotate, revoke API key per company per aplikasi
- OAuth 2.0 untuk integrasi aplikasi third-party
- Webhook event catalog: company admin pilih event yang ingin di-subscribe
  (mis. "notifikasi saya setiap karyawan baru di-hire")
- Rate limiting per API key dengan tier (basic / pro / enterprise)
- Sandbox environment untuk testing integrasi

---

## HORIZON 4 — PLATFORM EVOLUTION (Sprint 19+)

> Inisiatif jangka panjang yang mentransformasi Quantara dari HRIS menjadi
> platform HR intelligence yang komprehensif.

### H4.1 — AI-Powered HR Assistant (Copilot)

**Konsep:**
- Chat interface di dalam HRIS untuk query natural language
- Contoh: "Siapa di tim Sales yang belum ambil cuti lebih dari 3 bulan?"
  → sistem generate query dan tampilkan hasil
- "Draft email penolakan kandidat untuk posisi Marketing Manager"
  → AI generate draft berdasarkan konteks
- "Apa risiko turnover di department IT bulan depan?"
  → AI analisis dari predictive model dan tampilkan insight
- Implementasi: LLM API (Anthropic Claude / OpenAI) dengan function calling
  ke HRIS API internal

---

### H4.2 — Multi-Country Payroll

**Konsep:**
- Quantara saat ini hanya untuk regulasi Indonesia (PP 35/2021, UU 13/2003,
  TER PPh21, BPJS)
- Ekspansi ke negara ASEAN: Malaysia (EPF/SOCSO), Singapura (CPF), Filipina
  (SSS/PhilHealth/Pag-IBIG), Thailand
- Arsitektur: jurisdiction-aware payroll engine dengan pluggable country modules
- Multi-currency support di semua modul finansial

---

### H4.3 — Workforce Planning & Scenario Modeling

**Konsep:**
- Beyond MPP saat ini yang bersifat operational (satu per satu permintaan),
  tambahkan strategic workforce planning
- Modeling skenario: "Jika perusahaan grow 30% di 2027, berapa headcount yang
  dibutuhkan per department? Berapa cost tambahan?"
- Monte Carlo simulation untuk proyeksi biaya workforce dalam berbagai skenario
- Integrasi dengan data eksternal: benchmark gaji industri (via API survey
  gaji atau scraping)

---

### H4.4 — Employee Wellbeing & Mental Health Module

**Konsep:**
- Pulse survey anonim (weekly/monthly) untuk mengukur employee sentiment
- Burnout detection: berbasis attendance anomali + overtime berlebih +
  cuti yang tidak diambil + performance drop
- Akses ke sumber daya wellbeing: kontak EAP (Employee Assistance Program),
  artikel, self-assessment tools
- HR bisa lihat agregat (bukan individual) untuk menjaga privasi
- Integration dengan platform wellbeing eksternal (Headspace for Work, dll)

---

### H4.5 — Compensation Intelligence & Market Benchmarking

**Konsep:**
- Integrasi dengan data survei gaji (Mercer, Willis Towers Watson, atau
  platform lokal seperti Glints / JobStreet Salary Insight)
- Compa-ratio analysis: bandingkan gaji aktual tiap karyawan dengan
  median market untuk posisi dan level yang sama
- Pay equity analysis: deteksi gap gaji berdasarkan gender atau demografi lain
  yang tidak seharusnya ada
- Salary band rekomendasi: berdasarkan market data, apakah job grade saat ini
  masih kompetitif?

---

## RENCANA SPRINT RINGKAS

```
Sprint 7  — H1.1 Bulk Approval + H1.2 Leave Carry-Over Cap
Sprint 8  — H1.5 WFH Mode + H1.6 Overtime Pre-Approval + H1.7 Helpdesk SLA
Sprint 9  — H1.3 Conditional Approval + H1.4 Time-in-Stage + H1.8 THR Planning
            + H1.9 WhatsApp Notification
Sprint 10 — H2.1 PWA Enhancement + H2.4 Candidate Scoring Engine
Sprint 11 — H2.2 Goals Cascading + H2.3 360° Peer Feedback
Sprint 12 — H2.5 Employee Referral + H2.6 Turnover Analytics
Sprint 13 — H2.7 Payroll Simulasi + H2.8 Reimbursement OCR + H2.9 Cost Center Drill-Down
Sprint 14 — H3.1 Competency Library + H3.2 Succession Planning
Sprint 15 — H3.3 Flexible Benefits + H3.4 ERP Integration
Sprint 16 — H3.5 Predictive Analytics (foundation)
Sprint 17 — H3.6 Internal Talent Marketplace
Sprint 18 — H3.7 Digital Signing Legal + H3.8 Open API Portal
Sprint 19+ — H4.x Platform Evolution (roadmap TBD)
```

---

## TECHNICAL DEBT & INFRASTRUCTURE IMPROVEMENTS

Selain fitur baru, beberapa perbaikan teknis diperlukan untuk mendukung skalabilitas:

### TD.1 — API Rate Limiting yang Lebih Granular
- Saat ini rate limiting di endpoint sensitif; perlu diperluas ke semua
  endpoint dengan tier berbeda per role
- Implementasi: Redis-based token bucket (lebih scalable dari counter MySQL)

### TD.2 — Background Job Queue yang Lebih Robust
- Cron saat ini di crontab sistem; untuk job yang lebih kompleks (OCR, scoring,
  predictive model) perlu message queue: RabbitMQ atau Redis Streams
- Retry yang lebih smart: dead-letter queue untuk job yang gagal berulang

### TD.3 — Database Query Optimization
- Dengan data yang makin besar, beberapa query berat (cost center report,
  payroll calculate) perlu review execution plan
- Tambah index yang belum ada; pertimbangkan read replica untuk report queries
- Materialized view / pre-aggregated table untuk laporan yang sering diakses

### TD.4 — Test Coverage Expansion
- 607 tests saat ini; target naik ke >900 tests dengan fitur baru
- Tambah integration tests untuk business flow end-to-end (MPP → hire → payroll)
- Performance tests: payroll calculate harus selesai < 60 detik untuk 1.000 karyawan

### TD.5 — Key Rotation Otomatis
- AES-256-GCM key versioning sudah ada; tambah cron untuk auto-rotate key
  setiap N bulan (konfigurasi per company)
- Pastikan re-encrypt data lama secara background job tanpa downtime

### TD.6 — CDN & File Storage Optimization
- File storage S3-ready; implementasikan CDN (CloudFront / Cloudflare)
  agar akses payslip PDF dan dokumen lainnya lebih cepat
- Lazy-load dokumen: jangan pre-generate semua PDF, generate on-demand
  dan cache hasil

---

## PRIORITAS BERDASARKAN IMPACT VS EFFORT

```
HIGH IMPACT / LOW EFFORT  → Kerjakan segera
────────────────────────────────────────────
• Bulk Approval (H1.1)
• WFH Mode (H1.5)
• THR Planning Dashboard (H1.8)
• Helpdesk SLA (H1.7)
• Carry-Over Cap (H1.2)

HIGH IMPACT / HIGH EFFORT → Rencanakan dengan matang
────────────────────────────────────────────
• Mobile App Native (H2.1)
• Performance Cascading (H2.2)
• Turnover Analytics (H2.6)
• ERP Integration (H3.4)
• Digital Signing Legal (H3.7)

LOW IMPACT / LOW EFFORT  → Kerjakan kalau ada slot
────────────────────────────────────────────
• Overtime Pre-Approval (H1.6)
• Time-in-Stage Tracking (H1.4)
• Reimbursement OCR (H2.8)

LOW IMPACT / HIGH EFFORT  → Defer atau tidak dikerjakan
────────────────────────────────────────────
• Multi-Country Payroll (H4.2) — defer sampai ada demand nyata
• Monte Carlo Workforce Planning (H4.3) — defer
```

---

## METRIK KEBERHASILAN

Setiap improvement harus diukur dengan metrik konkret:

| Inisiatif | Metrik Sukses |
| --- | --- |
| Bulk Approval | Waktu rata-rata approval inbox turun >40% |
| WFH Mode | 100% WFH ter-track formal; zero untracked WFH complaint |
| Overtime Pre-Approval | % lembur dengan pre-approval naik ke >80% dalam 3 bulan |
| Helpdesk SLA | % ticket selesai dalam SLA naik ke >90% |
| THR Dashboard | Finance tidak perlu buat spreadsheet manual untuk THR planning |
| WhatsApp Notif | Approval response time turun >30% vs email-only |
| Mobile PWA | Clock-in via mobile naik; late clock-in karena "lupa" turun |
| Goals Cascading | % goals ter-align ke company objective naik ke >70% |
| 360° Feedback | Completion rate peer review >85% |
| Turnover Analytics | HRBP bisa identifikasi departemen risiko tinggi sebelum turnover terjadi |
| Referral Program | % hire via referral naik ke >20% dari total hire |

---

## CATATAN AKHIR

Dokumen ini bersifat **living document** — harus di-review dan di-update setiap
akhir sprint bersamaan dengan sprint retrospective. Prioritas dapat berubah
berdasarkan:

- Feedback pengguna dari session usability testing
- Kebutuhan compliance/regulasi baru (perubahan UU Ketenagakerjaan, perpajakan)
- Permintaan fitur dari pelanggan enterprise
- Temuan teknis selama sprint berjalan

---

*Dokumen dibuat: Mei 2026*
*Berdasarkan telaah: `Quantara HRIS — Daftar Fitur & Business Flow End-to-End`*
*Versi sistem yang ditelaah: Sprint 6 (Cost Center finalized)*
