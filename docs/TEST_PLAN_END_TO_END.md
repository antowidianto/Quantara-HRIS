# Test Plan End-to-End — HRIS Quantara

Skenario uji lengkap dari **detik pertama login** sampai siklus penuh
karyawan (rekrut → onboarding → operasional harian → performance → payroll
→ resign → compliance). Setiap step menyebutkan **akun yang dipakai**,
**action yang dilakukan**, dan **expected result**.

> **Pra-syarat setup:**
> ```bash
> cd backend
> php spark migrate
> php spark db:seed SeedAll
> ```
> `SeedAll` jalankan urutan: `InitialSeeder` → `IndonesianMastersSeeder` → `IndonesianStakeholderSeeder` → `OnlineTestExpandedBankSeeder` → `TaskTemplatesSeeder` → `ApprovalAndContractSeeder`.
>
> Default password semua akun: **`Quantara@2026`** (must_change pada login pertama).
>
> Frontend: `cd frontend && npm run dev` → buka `http://localhost:5173`.
> Backend: `cd backend && php spark serve` → `http://localhost:8080`.
>
> **Default career slug perusahaan:** `quantara` (diset oleh `IndonesianStakeholderSeeder`).
> Portal kandidat: `http://localhost:5173/careers/quantara`.

---

## Daftar Aktor Utama (Quick Reference)

### Akun Internal HRIS (`users`, login di `/login`)

| Akun | Email | Peran |
| --- | --- | --- |
| **KOMUT** | `komisaris.utama@quantara.id` | Komisaris Utama (super_admin) |
| **KOMIN** | `komisaris.independen@quantara.id` | Komisaris Independen (auditor) |
| **CEO** | `direktur.utama@quantara.id` | Direktur Utama |
| **CFO** | `direktur.keuangan@quantara.id` | Direktur Keuangan |
| **CHRO** | `direktur.sdm@quantara.id` | Direktur SDM |
| **COO** | `direktur.operasional@quantara.id` | Direktur Operasional |
| **CTO** | `direktur.ti@quantara.id` | Direktur TI |
| **CMO** | `direktur.pemasaran@quantara.id` | Direktur Pemasaran |
| **CLO** | `direktur.legal@quantara.id` | Direktur Hukum |
| **GM-HR** | `surya.pradana@quantara.id` | GM HR |
| **MGR-REC** | `anita.rahayu@quantara.id` | Recruitment Manager |
| **MGR-PAY** | `yuni.astuti@quantara.id` | HR Operations & Payroll Manager |
| **MGR-CNB** | `hendrawan.lubis@quantara.id` | Comp & Ben Manager |
| **MGR-IR** | `sutrisno.ismail@quantara.id` | Industrial Relations Manager |
| **MGR-DEV** | `aldi.maulana@quantara.id` | Software Engineering Manager |
| **SPV-DEV** | `arif.hidayat@quantara.id` | Backend Tech Lead |
| **STF-BE** | `hadi.pranata@quantara.id` | Backend Developer (Bandung) |
| **STF-REC** | `mira.maharani@quantara.id` | Recruiter Staff |
| **BM-SBY** | `joko.susilo@quantara.id` | Branch Manager Surabaya |
| **STF-OPS-SBY** | `tia.mulyani@quantara.id` | Operations Staff Surabaya |
| **BOOTSTRAP** | `admin@quantara.id` | Super Admin darurat (no employee) |

### Akun Kandidat (`candidate_accounts`, login di `/careers/quantara/login`)

> Portal kandidat **terpisah** dari HRIS internal — pakai tabel & JWT
> berbeda. Akun di-create saat kandidat register sendiri di portal.
> Tidak ada akun pre-seeded; tester buat manual sesuai Suite 3.1.

| Akun (test) | Email | Peran |
| --- | --- | --- |
| **CAND** | `budi.testkandidat@gmail.com` (buat manual) | Kandidat eksternal Backend Developer |

---

## Peta Suite (overview)

| Suite | Aktor utama | Estimasi | Modul yang ditest |
| --- | --- | ---: | --- |
| [Suite 0 — First-time login & password reset](#suite-0--first-time-login--password-reset) | semua | 5 min | Auth, password policy, 2FA |
| [Suite 1 — Setup Korporat oleh Direksi](#suite-1--setup-korporat-oleh-direksi-day-1) | KOMUT, CEO, CHRO, CFO, CLO | 15 min | Master, regulasi, approval chain, cost center, work locations |
| [Suite 2 — Manpower Planning Plan (MPP)](#suite-2--manpower-planning-plan-mpp-day-23) | CHRO, CEO, CFO, MGR-REC | 10 min | MPP, approval flow, convert MR → Job Posting |
| [Suite 3 — Rekrutmen end-to-end (portal kandidat)](#suite-3--rekrutmen-end-to-end-day-410) | MGR-REC, STF-REC, kandidat | 35 min | Job posting, candidate portal, online test self-service, scoring, **offer self-service (Option C)** |
| [Suite 4 — Onboarding karyawan baru](#suite-4--onboarding-karyawan-baru-day-1115) | MGR-PAY, karyawan baru | 15 min | Convert ke employee, contract sign, onboarding plan, asset, training, probation |
| [Suite 5 — Operasional harian](#suite-5--operasional-harian-day-1645) | STF-BE, SPV-DEV, MGR-DEV | 25 min | Attendance, leave, overtime, WFH, reimbursement, loan, business trip |
| [Suite 6 — Performance review siklus](#suite-6--performance-review-siklus-day-46) | CHRO, semua manager + staff | 15 min | Goals, peer review, IDP, calibration |
| [Suite 7 — Payroll bulanan + THR + Bonus](#suite-7--payroll-bulanan--thr--bonus-day-2530) | MGR-PAY, MGR-CNB, CFO | 20 min | Period, calculate, finalize, slip, THR, Bonus Engine |
| [Suite 8 — Disciplinary & SP](#suite-8--disciplinary--sp-day-30) | MGR-IR | 5 min | Surat Peringatan, kategori SP |
| [Suite 9 — Talent management](#suite-9--talent-management-day-50) | CHRO, CEO | 10 min | Competency, succession, talent pool, internal mobility |
| [Suite 10 — Helpdesk & WhatsApp](#suite-10--helpdesk--whatsapp-day-30) | semua | 5 min | HR ticket, notifikasi WA |
| [Suite 11 — Lifecycle change: JIC & Mutasi](#suite-11--lifecycle-change-jic--mutasi-day-60) | SPV-DEV, MGR-PAY, CFO, BOD | 10 min | Promosi/demosi (JIC), Employee Transfer |
| [Suite 12 — Resignation + clearance + paklaring](#suite-12--resignation--clearance--paklaring-day-9095) | STF-BE, MGR-DEV, MGR-PAY | 20 min | Resignation, offboarding clearance, BPJS deactivate, severance, paklaring, exit interview |
| [Suite 13 — Termination workflow (PHK formal)](#suite-13--termination-workflow-phk-formal-day-100) | MGR-IR, CHRO, CEO | 10 min | Bipartite → HR Director → BOD → executed |
| [Suite 14 — Compliance & audit](#suite-14--compliance--audit-day-100) | KOMIN, CHRO, MGR-CNB | 10 min | WLKP, BPJS report, PPh 21, audit log filter |

**Total estimasi:** ~3.5 jam untuk regression penuh.

---

## Suite 0 — First-time login & password reset

**Tujuan:** memastikan login flow + force-change-password berfungsi.

### Test 0.1 — Login Bootstrap Admin

| Step | Aksi | Expected |
| ---: | --- | --- |
| 1 | Buka `/login` | Form email + password tampil |
| 2 | Masuk `admin@quantara.id` / `Quantara@2026` | Auth berhasil |
| 3 | Sistem redirect ke "Change password" karena `must_change_password=1` | Halaman ganti password tampil |
| 4 | Isi old=`Quantara@2026`, new=`Adm1n#Quantara2026`, confirm sama | Password tersimpan, redirect ke dashboard |
| 5 | Logout, login ulang dengan password baru | Berhasil masuk dashboard |

**Expected DB:**
- `users.must_change_password = 0` untuk akun ini.
- `password_history` row baru.
- `audit_logs` mencatat `auth.login.success` + `auth.password.change`.

### Test 0.2 — Login CEO + setup 2FA opsional

| Step | Aksi | Expected |
| ---: | --- | --- |
| 1 | Logout, login `direktur.utama@quantara.id` / `Quantara@2026` | Force change password |
| 2 | Set password baru `Ceo#Quantara2026` | OK |
| 3 | Buka Profile → Aktifkan 2FA | QR code TOTP muncul |
| 4 | Scan dengan Authenticator, masukkan 6-digit | 2FA aktif, 10 backup codes muncul |
| 5 | Logout, login ulang | Setelah password, diminta TOTP |

### Test 0.3 — Failed login lockout

| Step | Aksi | Expected |
| ---: | --- | --- |
| 1 | Login `mira.maharani@quantara.id` dengan password salah 5x | Setelah ke-5 muncul "Account locked" |
| 2 | Cek `users.failed_attempts` & `locked_until` | `failed_attempts >= 5`, `locked_until` > NOW |

---

## Suite 1 — Setup Korporat oleh Direksi (Day 1)

**Aktor:** KOMUT, CEO, CHRO, CFO, CLO.

### Test 1.1 — Verifikasi struktur organisasi

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CEO | **Master → Org Structure** | Tree muncul: Komisaris → Direksi → GM → Manager → Staff |
| 2 | CEO | Klik node "Direktur Utama" | Detail menampilkan 6 direct report (CFO, CHRO, COO, CTO, CMO, CLO) |
| 3 | CEO | Cek node "Software Engineering Manager" | Direct report: 2 supervisor (BE Tech Lead, FE Tech Lead) |

### Test 1.2 — Setup Cost Center hierarki + budget

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CFO | **Master → Cost Centers** → "Tambah" | Form CC code, name, parent |
| 2 | CFO | Buat CC root: `CC-CORPORATE`, `Korporat` | Tree menampilkan node baru |
| 3 | CFO | Buat anak: `CC-FIN`, `CC-HR`, `CC-OPS-JKT`, `CC-OPS-SBY`, `CC-OPS-BDG`, `CC-IT`, `CC-MKT` | Hierarki 2-level lengkap |
| 4 | CFO | Buka `CC-IT` → tab Budget → input budget Q1 2026 = Rp 5 miliar | Budget tersimpan |
| 5 | CFO | Buka tab "Budget vs Actual" untuk CC-IT | Tampil komitmen (offer pending) + actual (payroll terealisasi) |

### Test 1.3 — Setup Approval Chain custom

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **Admin → Approval Chains** | Default chain dari `ApprovalAndContractSeeder` muncul (LEAVE, OVERTIME, REIMBURSEMENT, dst.) |
| 2 | CHRO | Tambah chain `LEAVE_LONG_5_DAYS` dengan condition `days >= 5` → step: SUPERVISOR + DEPARTMENT_HEAD + HR | Chain tersimpan |
| 3 | CHRO | **Approval Assignments** → tambah rule: module=LEAVE, job_grade=MGR (Manager keatas), chain=LEAVE_LONG_5_DAYS, priority=10 | Rule aktif |

### Test 1.4 — Setup Peraturan Perusahaan + Template Kontrak

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CLO | **Admin → Company Regulations** | Library kosong (atau hasil seeder) |
| 2 | CLO | Tambah regulasi `LEAVE-001`, kategori `LEAVE`, body HTML | Tersimpan |
| 3 | CHRO | **Admin → Contract Templates** → buat template "PKWTT Standar" dengan body HTML mengandung `{{employee.full_name}}`, `{{contract.start_date}}`, `{{regulations}}` | Tersimpan, marker `is_default=1` |
| 4 | CHRO | Pastikan template `LETTER_OFFER` sudah ada dari seeder | Tersedia (dipakai untuk render preview offer) |

### Test 1.5 — Setup Work Locations granular

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **Master → Work Locations** | List dari seeder muncul |
| 2 | CHRO | Tambah `WL-JKT-CLIENT-ASTRA` (CLIENT_OFFICE, allow_clock_here=1) | Tersimpan |
| 3 | CHRO | Pastikan `WL-WFH` (WFH, allow_clock_here=1, no geofence) sudah ada | OK |

---

## Suite 2 — Manpower Planning Plan (MPP) (Day 2-3)

**Aktor:** CHRO ajukan, CEO + CFO approve, MGR-REC convert ke posting.

### Test 2.1 — CHRO buat MPP 2026

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **Recruitment → MPP List → New** | Form MPP |
| 2 | CHRO | Isi: title="MPP 2026 Q1", year=2026, expected_approver=CEO, justification="Ekspansi tim engineering & sales" | Tersimpan status DRAFT |
| 3 | CHRO | Tambah line items: 3 Backend Dev (IT-DEV), 2 Sales Exec (MKT-SLS), 1 Recruiter (HR-REC) | 6 items |
| 4 | CHRO | Submit untuk approval | Status PENDING_APPROVAL, notif terkirim ke CEO |

### Test 2.2 — CEO approve MPP

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CEO | **Approvals → Inbox** | MPP "MPP 2026 Q1" muncul |
| 2 | CEO | Approve dengan note "OK, prioritas BE Dev dulu" | Status APPROVED |
| 3 | Sistem | Auto-create 6 Manpower Request | `manpower_requests` punya 6 row baru status OPEN |
| 4 | MGR-REC | **Manpower Requests → List** | 6 MR muncul |

### Test 2.3 — Convert MR ke Job Posting + onboarding plan

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-REC | Buka MR untuk Backend Dev | Detail MR |
| 2 | MGR-REC | Tab "Planned Assets" → tambah laptop + akun email + akses GitLab | Asset planning tersimpan |
| 3 | MGR-REC | Klik "Buat Job Posting" | Form prefilled |
| 4 | MGR-REC | Lengkapi description, requirements, salary 9-13jt, headcount=3, deadline +30 hari, **`public_slug=backend-developer-2026`** | Tersimpan status DRAFT |
| 5 | MGR-REC | Klik "Publish" | Status OPEN, slug aktif di `/careers/quantara/jobs/backend-developer-2026` |

---

## Suite 3 — Rekrutmen end-to-end (Day 4-10)

**Aktor:** MGR-REC, STF-REC, **kandidat eksternal** (akun baru di portal kandidat).

> Portal kandidat **terpisah** dari HRIS internal — login di `/careers/quantara/login`,
> pakai tabel `candidate_accounts` + JWT terpisah. Lihat `docs/SPRINT_25_CANDIDATE_OFFER_SELF_SERVICE.md`.

### Test 3.1 — Kandidat register di portal karir

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Kandidat | Buka `http://localhost:5173/careers/quantara` (career page) | Header "Career Portal" + list 1 lowongan: Backend Developer |
| 2 | Kandidat | Klik kartu "Backend Developer" | Detail JD + tombol "Lamar Sekarang" |
| 3 | Kandidat | Klik "Lamar Sekarang" → diminta login/register | Form auth muncul |
| 4 | Kandidat | Pilih "Daftar Akun Baru" → register `budi.testkandidat@gmail.com` / password "Test#Kandidat1", full_name="Athaya Maulida", phone | Akun terbuat di `candidate_accounts`, login otomatis, JWT candidate disimpan |
| 5 | Kandidat | Lengkapi form lamaran (DOB, address, current company, expected salary, upload CV PDF) | Tersimpan di `candidates` stage NEW |
| 6 | Kandidat | Redirect ke `/careers/quantara/dashboard` | Header nav menampilkan: Lowongan, Lacak Lamaran, **Dashboard Saya**, **Penawaran**, Logout |
| 7 | Kandidat | Cek "Dashboard Saya" | 2 quick-access cards (Penawaran Kerja, Cari Lowongan) + section "Lamaran Saya" dengan 1 row kandidat di stage "Baru Masuk" |

### Test 3.2 — Recruiter screening + assign online test

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-REC | Login internal HRIS → **Recruitment → Pipeline Board** untuk job Backend Dev | Card kandidat baru muncul kolom NEW |
| 2 | STF-REC | Buka detail kandidat → klik "Lihat CV" | PDF tampil di viewer |
| 3 | STF-REC | Klik "Move to SCREENING" | Stage update, `candidate_stages` history baru |
| 4 | STF-REC | **Online Tests → Assignments → New** untuk kandidat ini | Form assignment |
| 5 | STF-REC | Pilih test bank: TPA Verbal + Bahasa Inggris, due_at=+3 hari, max_attempts=2 | `online_test_assignments` tersimpan, status PENDING |

### Test 3.3 — Kandidat ambil online test (self-service)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Kandidat | Login portal kandidat → Dashboard | Section "Tes Online untuk Anda" muncul dengan 2 test (TPA + English) status "Menunggu" |
| 2 | Kandidat | Klik "Mulai Test" pada TPA | Halaman pre-test (intro, instruksi, durasi 60 min, soal random) |
| 3 | Kandidat | Klik "Start Attempt" | Timer countdown mulai, soal pertama tampil, anti-cheat (window blur log) aktif |
| 4 | Kandidat | Jawab semua soal, klik "Submit" | Auto-graded MCQ, score muncul di halaman result |
| 5 | Kandidat | Test ke-2 (English) → submit | Score muncul |
| 6 | STF-REC | **Online Tests → Attempts** | Lihat hasil 2 test kandidat + breakdown per kategori |
| 7 | STF-REC | Klik kandidat → tombol "Recalculate Score" (composite scoring H2.4) | Score komposit ter-update |

### Test 3.4 — Interview flow

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-REC | Move kandidat ke INTERVIEW_HR, jadwalkan tanggal | Calendar event |
| 2 | STF-REC | Setelah interview, isi feedback rating 4/5, move ke INTERVIEW_USER | OK |
| 3 | MGR-DEV | Login → notifikasi interview kandidat | OK |
| 4 | MGR-DEV | Lakukan technical interview, isi feedback rating 5/5 | OK |
| 5 | MGR-DEV | Move ke OFFER stage | OK, stage candidate berubah ke OFFER |

### Test 3.5 — HR buat draft offer

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-REC | Buka detail kandidat (stage OFFER) → tab "Offers" → "Buat Offer Baru" | Form offer |
| 2 | MGR-REC | Isi: position=Backend Developer, basic_salary=11.000.000, employment_status=PKWTT, probation_months=3, proposed_join_date=+14 hari, response_deadline=+7 hari, allowances (Transport 500rb, Komunikasi 200rb), joining_bonus=1.000.000 | Tersimpan status DRAFT, `candidate_offers` row baru |
| 3 | MGR-REC | Preview HTML offer letter (render dari template `LETTER_OFFER`) | PDF/HTML tampil dengan placeholder ter-fill |
| 4 | MGR-REC | Klik "Send Offer" + note "Selamat & semoga sukses" | Status SENT, `sent_at` di-set, notifikasi terkirim ke kandidat (in-app + email) |

### Test 3.6 — **Kandidat self-service review & accept offer (Sprint 25 / Option C)**

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Kandidat | Login portal kandidat | Header nav menu **Penawaran** menampilkan badge merah "1" |
| 2 | Kandidat | Dashboard tampilkan card "Penawaran Kerja" dengan gradient amber + badge "1 baru" | Visual highlight benar |
| 3 | Kandidat | Klik menu **Penawaran** atau card | Halaman list `/careers/quantara/offers` dengan banner "Anda punya 1 penawaran kerja menunggu respon" |
| 4 | Kandidat | Klik kartu offer (status "Menunggu Respon Anda") → "Review & Respon" | Halaman detail `/careers/quantara/offers/{id}` |
| 5 | Kandidat | Verifikasi semua section tampil: Header (offer number, company), Posisi, Kompensasi (gaji pokok + tunjangan + joining bonus + total bulanan), Ketentuan Kerja (PKWTT, probation 3 bulan, tanggal mulai), banner deadline | Total bulanan = basic + sum(allowances) = 11.700.000 |
| 6 | Kandidat | Klik tombol **"Terima Penawaran"** (hijau) | Dialog konfirmasi muncul dengan ringkasan: nomor offer, posisi, total bulanan, mulai |
| 7 | Kandidat | Isi note "Terima kasih, siap bergabung" → klik "Ya, Terima" | Toast success, halaman refresh, banner berubah ke "Anda menerima penawaran ini pada [tanggal]" warna emerald, tombol respon hilang |
| 8 | Sistem | Auto-progress | `candidates.current_stage` jadi HIRED, `candidate_offers.status` = ACCEPTED, `responded_at` di-set, `audit_logs` baru dengan `source=CANDIDATE_PORTAL`, `metadata.actor='candidate'`, `metadata.candidate_account_id=...`, IP & user_agent tercatat |
| 9 | Sistem | Notifikasi HR | MGR-REC + GM-HR + CHRO dapat in-app notif "Kandidat menerima offer X via Career Portal — siap di-hire" |
| 10 | Kandidat | Kembali ke Dashboard | Badge "1 baru" hilang, lamaran sekarang stage "Diterima" |

### Test 3.7 — Negative path: kandidat tolak offer

> Reset dengan kandidat lain (atau revert via `php spark db:seed IndonesianStakeholderSeeder` lalu ulangi 3.1–3.5)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Kandidat | Buka offer detail → klik "Tolak Penawaran" | Dialog konfirmasi |
| 2 | Kandidat | Coba submit dengan alasan kosong | Error "Alasan penolakan minimal 5 karakter" |
| 3 | Kandidat | Isi alasan "Sudah menerima offer di tempat lain dengan paket lebih baik" → "Ya, Tolak" | Status REJECTED, banner berubah merah, audit log severity=WARNING |
| 4 | Sistem | Notif ke HR | "Kandidat menolak offer X via Career Portal" + alasan |
| 5 | Sistem | Candidate stage **tidak berubah** ke REJECTED | HR yang putuskan re-engage atau reject final via Pipeline Board |

### Test 3.8 — Negative path: visibility & ownership

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Kandidat A | Login dapat URL `/careers/quantara/offers/{id_milik_B}` (offer kandidat lain) | 404 "Penawaran tidak ditemukan atau bukan milik Anda" |
| 2 | MGR-REC | Buat offer baru tapi belum send (status DRAFT) | Kandidat dilihat dari portal → `GET /offers` tidak include DRAFT, `GET /offers/{id}` 404 OFFER_NOT_VISIBLE |
| 3 | Kandidat | Coba respond offer sudah expired (response_deadline lewat) | 422 + auto-set EXPIRED + pesan "Hubungi HR untuk re-issue offer baru" |

### Test 3.9 — Backward compat: HR proxy respond (alur lama)

> Untuk kandidat yang tidak punya akun portal (apply via channel lama).

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-REC | Untuk kandidat lain (yang `candidate_account_id` NULL), buat & send offer | Status SENT |
| 2 | MGR-REC | Dapat info dari kandidat via WA "saya accept" → buka offer di HRIS internal → **Respond → ACCEPTED** + note "Confirmed via WhatsApp" | Status ACCEPTED, audit log dengan `source=WEB`, `actor=user_id` (HR) — bukan CANDIDATE_PORTAL |

### Test 3.10 — Convert HIRED → Employee

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **Recruitment → Pipeline → HIRED tab** | Kandidat (Test 3.6) siap convert |
| 2 | MGR-PAY | Klik "Convert to Employee" | Form prefilled (NIK, NPWP perlu input ulang) |
| 3 | MGR-PAY | Lengkapi semua field wajib (NIK, NPWP, BPJS, bank), pilih branch=BDG, manager=Arif Hidayat (SPV-DEV), cost_center=CC-IT | `employees` row baru dengan `employee_number=EMP-0XXX` |
| 4 | Sistem | Trigger OnboardingPlan dari template `ONBOARD_DEFAULT` (planned assets dari MPP otomatis di-link) | `employee_tasks` generated, `asset_assignments` planned |

---

## Suite 4 — Onboarding karyawan baru (Day 11-15)

**Aktor:** MGR-PAY, karyawan baru (Athaya Maulida, hasil Suite 3.10).

### Test 4.1 — Karyawan baru first login

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | Trigger "Send credentials" untuk karyawan baru | Email berisi credentials |
| 2 | Karyawan | Login pakai temp password di `/login` (bukan portal kandidat) | Force change password |
| 3 | Karyawan | Set password baru | Redirect ke dashboard internal |
| 4 | Karyawan | Lihat home → widget "Onboarding Progress" 0/N tasks | OK |

### Test 4.2 — Sign Contract digital

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **Employees → [Athaya] → Contracts → Generate** dari template "PKWTT Standar" | Generated contract status DRAFT |
| 2 | MGR-PAY | Review HTML render, klik "Send for signing" | Email terkirim, status SENT |
| 3 | Karyawan | Buka link `/employee/contracts/sign/{id}` | Preview PDF, tombol Sign + PIN OTP |
| 4 | Karyawan | Klik Sign + masukkan PIN | Status SIGNED, file PDF tersimpan di `employee_documents` |

### Test 4.3 — Asset assignment + BAST

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **Master → Assets** → tambah laptop "MBP M3 14-inch" SN ABC123 | Asset terbuat |
| 2 | MGR-PAY | **Employees → [Athaya] → Assets → Assign** asset MBP, condition=NEW | Tersimpan |
| 3 | MGR-PAY | Generate BAST PDF | PDF terbentuk dengan signature placeholder |
| 4 | Karyawan | Profil → tab Assets | Laptop muncul |

### Test 4.4 — Training registration

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **Trainings → New** "Onboarding Engineering Day 1" tanggal +5 hari | Tersimpan |
| 2 | MGR-PAY | Register Athaya | OK |
| 3 | Karyawan | Profil → Training schedule | Training muncul |
| 4 | MGR-PAY | Setelah training, mark "Completed" + upload sertifikat | Sertifikat masuk `certificates`, expiring tracker aktif |

### Test 4.5 — Probation tracking + decision

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Sistem | Cron `php spark check:probation:deadline` | Deteksi karyawan dengan `probation_end_date` mendekati H+90 |
| 2 | SPV-DEV | (Hari ke-85) Login → notif "Probation evaluation due" | OK |
| 3 | SPV-DEV | **HR → Probation Dashboard** → buka kartu Athaya → klik "Decide" | Form: PASS / EXTEND / FAIL |
| 4 | SPV-DEV | Pilih PASS dengan note evaluasi | Status karyawan PROBATION → PKWTT, contract baru di-generate, audit log |

---

## Suite 5 — Operasional harian (Day 16-45)

**Aktor:** STF-BE (Hadi Pranata), SPV-DEV (Arif), MGR-DEV (Aldi).

### Test 5.1 — Clock-in dengan geofence

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **My Attendance** jam 08:50 | Tombol Clock-In aktif |
| 2 | STF-BE | Pilih lokasi work_location terdaftar, izinkan GPS | Lokasi ter-detect |
| 3 | STF-BE | Klik Clock-In | Status PRESENT, late_minutes=0 |
| 4 | STF-BE | Coba clock-in dari lokasi 1km jauh | Error "Outside geofence" |
| 5 | STF-BE | Jam 17:00 klik Clock-Out | OK, work_minutes ~480 |

### Test 5.2 — Late + attendance change request

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | Esoknya clock-in jam 09:30 | Status LATE, late_minutes=30 |
| 2 | STF-BE | **Attendance → Request Change** untuk hari ini, alasan "kemacetan" + foto | PENDING |
| 3 | SPV-DEV | **Approvals → Inbox** → Approve dengan note | Status APPROVED, attendance.late_minutes=0 |

### Test 5.3 — Cuti tahunan (3 hari)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Leaves → Request New** | Form |
| 2 | STF-BE | Pilih CT (Cuti Tahunan), 3 hari | Tersimpan PENDING, balance preview menurun |
| 3 | SPV-DEV | Approve | APPROVED, balance ter-reduce |
| 4 | STF-BE | **Leaves → Balance** | CT terpakai 3, sisa 9 |

### Test 5.4 — Cuti panjang (>5 hari) → multi-tier approval

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | Request CT 7 hari | Sistem deteksi >=5 hari → pakai chain LEAVE_LONG_5_DAYS |
| 2 | SPV-DEV | Approve step 1 | Step 2 trigger ke MGR-DEV |
| 3 | MGR-DEV | Approve step 2 | Step 3 trigger ke MGR-PAY |
| 4 | MGR-PAY | Approve step 3 | Final APPROVED |

### Test 5.5 — Bulk approval (Sprint 7 / H1.1)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | SPV-DEV | **Approvals → Inbox** dengan 5 row PENDING dari berbagai tipe | List muncul |
| 2 | SPV-DEV | Centang 3 baris → klik "Bulk Approve" | 3 ter-approve sekaligus, audit log per item |

### Test 5.6 — Lembur weekend + pre-approval plan

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Overtimes → Plan** Sabtu 4 jam | Plan PENDING |
| 2 | SPV-DEV | Approve plan | OK |
| 3 | STF-BE | Realize plan setelah selesai lembur | `overtimes` actual row |
| 4 | Sistem | Hitung amount = 4 * upah_jam * faktor_weekend (PP 35/2021) | OK |

### Test 5.7 — WFH request

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **WFH Requests → New** Senin-Selasa minggu depan | PENDING |
| 2 | SPV-DEV | Approve | OK |
| 3 | STF-BE | Hari H clock-in dari WL-WFH | Allowed (no geofence) |

### Test 5.8 — Reimbursement medis dengan OCR

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Reimbursement → New** kategori MEDICAL, upload kuitansi (foto) | OCR extract auto-fill amount + tanggal |
| 2 | STF-BE | Submit Rp 800.000 | PENDING |
| 3 | SPV-DEV | Approve step 1 | Pindah ke step 2 |
| 4 | MGR-CNB | Approve step 2 | APPROVED, ditambahkan ke payslip bulan berjalan |

### Test 5.9 — Loan/kasbon

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Loans → Request** Rp 5.000.000, 5x cicilan | PENDING |
| 2 | SPV-DEV | Approve | Pindah ke CFO |
| 3 | CFO | Approve | APPROVED, status RUNNING |
| 4 | Sistem | Bulan berikutnya payslip auto-deduct cicilan 1.000.000 | OK |

### Test 5.10 — Business trip + expense settlement

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Business Trips → New** ke Jakarta 2 hari | Form |
| 2 | STF-BE | Submit dengan estimasi Rp 3jt | PENDING |
| 3 | MGR-DEV | Approve | OK |
| 4 | STF-BE | Setelah trip, upload nota tiket pesawat + hotel + meal | Expense tercatat per kategori |
| 5 | MGR-CNB | Settle expense | Auto-add ke payslip |

---

## Suite 6 — Performance review siklus (Day 46)

### Test 6.1 — CHRO buka cycle Q1 2026

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **Performance → Cycles → New** "Q1 2026 Review" | Tersimpan |
| 2 | CHRO | Set start, end, target=semua aktif → "Start cycle" | Notif ke semua karyawan + manager |

### Test 6.2 — Cascade goals (CEO → Direktur → Manager → Staff)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CEO | **Performance → Goals Tree** → buat goal "Revenue 2026 100B" | Goal level 1 |
| 2 | CMO | Cascade → "Sales target 50B Q1" | Child goal |
| 3 | MGR-SLS | Cascade → "Sales SBY 5B" | Grandchild |
| 4 | STF-SLS | Cascade → "Acquire 10 SME accounts" | Great-grandchild |

### Test 6.3 — Self review + manager review

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Performance → My Performance** → form Q1 self-review → submit rating 4/5 + narasi | OK |
| 2 | SPV-DEV | **Performance → Reviews** → review STF-BE rating 4/5 → submit | OK |

### Test 6.4 — Peer review

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Performance → Peer Review → Nominate** 2-3 rekan | Tersimpan |
| 2 | Rekan | Notif → isi feedback anonim → submit | OK |
| 3 | SPV-DEV | Lihat rangkuman peer review | Aggregat tampil tanpa nama |

### Test 6.5 — IDP creation dari review

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | SPV-DEV | Buka review STF-BE → klik "Create IDP from this review" | IDP draft otomatis dengan area pengembangan |
| 2 | SPV-DEV | Tambah goal "Belajar Microservices" + link training | OK |
| 3 | STF-BE | **HR → IDP Dashboard → My IDP** | Goals muncul, bisa update progress |

---

## Suite 7 — Payroll bulanan + THR + Bonus (Day 25-30)

### Test 7.1 — Setup periode payroll

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **Payroll → Periods → New** "Payroll Maret 2026", cutoff 25, pay_date 28 | DRAFT |

### Test 7.2 — Pre-check pending approvals

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | Buka periode → klik "Cek Pending Approvals" | List leave/overtime/reimbursement yang masih PENDING di periode ini → warning untuk follow up |

### Test 7.3 — Calculate

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | Klik "Calculate" | Job berjalan untuk semua karyawan aktif |
| 2 | Sistem | Hitung gaji pokok + tunjangan + lembur + reimbursement - BPJS - PPh21 (TER PMK 168/2023) | Slip per karyawan |
| 3 | MGR-PAY | Spot-check 1 karyawan: STF-BE | Slip detail menampilkan komponen + perhitungan PPh21 TER kategori A |

### Test 7.4 — Review by CFO + finalize + bukti potong

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CFO | **Payroll → Periods → Maret 2026** | Status CALCULATED |
| 2 | CFO | Review summary, klik "Finalize" + 2FA confirm | FINALIZED, no edits |
| 3 | Sistem | Slip gaji dikirim ke email semua karyawan | OK |
| 4 | MGR-CNB | Generate Bukti Potong PPh 21 (1721-A1 bulanan) per karyawan | PDF terbentuk |

### Test 7.5 — Karyawan view slip + bonus history

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Payroll → My Payslips** → klik Maret 2026 | PDF preview, breakdown lengkap |
| 2 | STF-BE | **Payroll → My Bonuses** | Riwayat bonus tahun berjalan (kosong kalau belum ada run) |

### Test 7.6 — THR Idul Fitri planning

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-CNB | **Payroll → THR Planning** | Form periode |
| 2 | MGR-CNB | Klik "Generate THR" | 1 bulan gaji untuk masa kerja >12 bulan, prorata <12 bulan |
| 3 | CFO | Approve & finalize | Slip THR terkirim 7 hari sebelum hari raya |

### Test 7.7 — Bonus Engine: annual performance bonus

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-CNB | **Payroll → Bonus Schemes → New** "Annual 2026", basis=multiplier_of_basic, kondisi=performance_rating ≥ 4 | Tersimpan |
| 2 | MGR-CNB | Preview scheme dengan sample data 5 karyawan | Recipient calculation muncul |
| 3 | MGR-CNB | **Bonus Runs → New** untuk scheme tsb, periode Q1 2026 | Run DRAFT |
| 4 | MGR-CNB | Klik "Calculate" | Status CALCULATED, list recipients muncul |
| 5 | MGR-CNB | Override 1 recipient (custom amount + alasan) | Override tercatat |
| 6 | CFO | Approve run | APPROVED |
| 7 | MGR-PAY | Mark run as PAID setelah transfer bank | Status PAID, bonus masuk ke `bonus_history` |

### Test 7.8 — Payroll simulation what-if

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-CNB | **Payroll → Simulate → Compare** | Skenario kenaikan UMP 10% vs current |
| 2 | MGR-CNB | Submit | Hasil simulasi tampil dengan delta total cost, PPh21, BPJS |
| 3 | MGR-CNB | Reverse THP: input target net Rp 10jt → backsolve gross | Gross + komponen tax tampil |

---

## Suite 8 — Disciplinary & SP (Day 30)

### Test 8.1 — Buat SP1

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-IR | **HR → Disciplinary → New** untuk karyawan target | Form |
| 2 | MGR-IR | Pilih kategori "Tidak masuk tanpa keterangan 3x", level=SP1, valid_until=+6 bulan | Tersimpan |
| 3 | MGR-IR | Generate surat → preview HTML, sign elektronik | PDF tersimpan, terkirim ke karyawan |

### Test 8.2 — Eskalasi ke SP2 / Termination

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-IR | Pelanggaran berulang → SP2 | OK |
| 2 | MGR-IR | Pelanggaran berulang → "Trigger Termination" | Pindah ke flow Termination Board (Suite 13) |

---

## Suite 9 — Talent management (Day 50)

### Test 9.1 — Competency framework

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **HR → Competency Library** → tambah "Backend Engineering" + 5 level | OK |
| 2 | CHRO | Map ke position "Senior Backend Developer" target level=4 | OK |

### Test 9.2 — Self-assessment + gap analysis

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | Self-assess Backend Eng rating 3/5 | Tersimpan |
| 2 | SPV-DEV | Validate rating | OK |
| 3 | SPV-DEV | Lihat gap analysis STF-BE | Gap = target 4 - current 3 = 1 level, training recommendation muncul |

### Test 9.3 — Succession plan + 9-box grid

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **HR → Succession Planning** → tandai "Direktur Utama" critical | OK |
| 2 | CHRO | Add CFO + COO sebagai successor candidate dengan readiness 70% & 50% | OK |
| 3 | CHRO | **HR → Talent Marketplace → 9-box grid** | Karyawan plotted by performance vs potential |

### Test 9.4 — Internal mobility

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-REC | **HR → Talent Marketplace** post internal job "Project Lead - Bandung" | OK |
| 2 | STF-BE | Apply internal | Application tercatat |
| 3 | MGR-REC | Lihat recommended candidates by skill match | Ranked list |

---

## Suite 10 — Helpdesk & WhatsApp (Day 30)

### Test 10.1 — HR ticket

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Helpdesk → New Ticket** "Slip gaji Februari belum diterima" | Tersimpan, SLA timer mulai |
| 2 | MGR-PAY | Lihat ticket → assign ke diri sendiri → reply → resolve | Status RESOLVED |
| 3 | STF-BE | Confirm resolution | CLOSED |

### Test 10.2 — SLA breach detection

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Sistem | Cron `php spark check:helpdesk:sla` | Ticket overdue dapat notif eskalasi ke supervisor |

### Test 10.3 — WhatsApp notification

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Bootstrap | **Admin → WhatsApp Settings** → aktifkan provider Wablas dengan API key dummy | Tersimpan |
| 2 | STF-BE | Submit cuti | Notif WA terkirim ke SPV-DEV (cek `whatsapp_send_log`) |

---

## Suite 11 — Lifecycle change: JIC & Mutasi (Day 60)

### Test 11.1 — JIC promosi (Job Information Change)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | SPV-DEV | **HR → JIC → New** untuk STF-BE, type=PROMOTION, new_position=Senior BE Dev, new_grade naik 1 step, new_salary +20% | DRAFT |
| 2 | SPV-DEV | Submit | Step 1: SUPERVISOR done, ke Department Head |
| 3 | MGR-DEV | Approve | Ke HR |
| 4 | MGR-PAY | Approve | Ke BOD (kondisional kalau salary > threshold) |
| 5 | CHRO | Approve | Status APPROVED |
| 6 | MGR-PAY | Klik "Implement" | `employees` row update (position_id, grade, salary), audit log lengkap |

### Test 11.2 — JIC demosi dengan consent

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-IR | JIC type=DEMOTION (bukan disipliner), submit & approve sampai final | Status PENDING_CONSENT |
| 2 | Karyawan | Notif → buka detail → klik "Setuju" + sign | Status APPROVED, siap di-implement |
| 3 | Karyawan (alt) | Klik "Tolak" | Status REJECTED, audit log alasan |

### Test 11.3 — Employee Transfer (mutasi sederhana antar branch)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **HR → Transfers → New** untuk STF-BE: from BDG → to JKT, effective_date=+30 hari | DRAFT |
| 2 | MGR-PAY | Submit (chain 1-tier HR approval) | PENDING |
| 3 | MGR-PAY | Approve (atau approver lain sesuai assignment) | APPROVED |
| 4 | MGR-PAY | "Implement" | `employees.branch_id` update, work_location reset |
| 5 | Karyawan | **HR → My Transfers** | Riwayat mutasi muncul |

---

## Suite 12 — Resignation + clearance + paklaring (Day 90-95)

### Test 12.1 — Karyawan submit resignation

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **HR → My Resignation → New** alasan "Pindah perusahaan", last_date=+30 hari | PENDING |

### Test 12.2 — Multi-tier approve

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-DEV | **Approvals → Inbox** → Approve | Pindah ke HR |
| 2 | MGR-PAY | Final approve | APPROVED, `employees.resignation_date` set |

### Test 12.3 — Offboarding clearance checklist

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **HR → Resignations → Detail → Tab Offboarding** | Status panel: Asset Clearance, BPJS, Paklaring, Final Settlement |
| 2 | SPV-DEV | Hand-over completed → tandai task done | Task closed |
| 3 | MGR-PAY | **Asset Clearance** → tandai semua asset returned | `asset_assignments.return_date` di-set, condition assessment dicatat |
| 4 | MGR-PAY | **BPJS Deactivated** → input tanggal deaktivasi | OK |
| 5 | MGR-PAY | **Generate Paklaring** → render template + sign HR | PDF Surat Keterangan Kerja |
| 6 | MGR-PAY | **Final Settlement** → link ke last payslip + pesangon (kalau applicable) | OK |
| 7 | MGR-PAY | Klik "Mark Settlement Paid" setelah transfer | Status PAID |

### Test 12.4 — BPJS overdue tracking

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | Sistem | Cron `php spark check:bpjs:compliance` | List karyawan resign >7 hari yang BPJS belum di-deactivate → notif HR |
| 2 | MGR-PAY | **HR → Compliance Reports → BPJS Overdue** | Dashboard list overdue cases |

### Test 12.5 — Exit interview

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **HR → Exit Interviews → New** untuk STF-BE | Form |
| 2 | STF-BE | Isi survey | Submit |
| 3 | MGR-PAY | **HR → Exit Interviews → Analytics** | Aggregate alasan resign perusahaan |

### Test 12.6 — Last payroll + severance

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-PAY | **HR → Severance → Calculate** preview untuk STF-BE | Hitung uang pesangon + penghargaan masa kerja + UPH (UU 6/2023 PP 35/2021) |
| 2 | MGR-PAY | Buat severance record → finalize → mark paid | OK |
| 3 | Sistem | Cron `php spark deactivate:resigned` setelah resign_date lewat | User di-deactivate, `is_active=0`, JWT existing tetap valid sampai expired |

---

## Suite 13 — Termination workflow (PHK formal) (Day 100)

> Workflow lebih ketat dari resignation karena legal sensitive.
> DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR → PENDING_BOD → APPROVED → EXECUTED.

### Test 13.1 — MGR-IR ajukan termination

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-IR | **HR → Termination Board → New** untuk karyawan dengan SP3 | DRAFT |
| 2 | MGR-IR | Isi reason, supporting evidence, planned_termination_date | Tersimpan |
| 3 | MGR-IR | Submit | PENDING_BIPARTITE |

### Test 13.2 — Bipartite meeting

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-IR | Setelah meeting, klik "Record Bipartite Outcome" → upload notulen | Status PENDING_HR_DIRECTOR |

### Test 13.3 — Approve chain

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | Approve | PENDING_BOD |
| 2 | CEO | Approve | APPROVED |
| 3 | MGR-IR | Klik "Execute" | Status EXECUTED, employee deactivated, severance auto-calculated, paklaring generated |
| 4 | MGR-IR | Generate "Surat PHK" | PDF |

---

## Suite 14 — Compliance & audit (Day 100)

### Test 14.1 — WLKP report (Wajib Lapor Ketenagakerjaan)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | **HR → Compliance Reports → Generate** type=WLKP, periode=Q1 2026 | CSV/XLSX format WLKP standar download |
| 2 | CHRO | Verifikasi via `php scripts/verify-compliance-wlkp.php` | All required columns valid |

### Test 14.2 — BPJS report (e-Dabu)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | CHRO | Compliance Reports → BPJS bulan Maret 2026 | List karyawan + BPJS Kes + TK + JP |
| 2 | CHRO | Download CSV | Format kompatibel upload e-Dabu |

### Test 14.3 — PPh 21 1721-A1 tahunan

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | MGR-CNB | **Payroll → Period → Pph21 Export** untuk year 2026 | Bundle PDF semua A1 |
| 2 | MGR-CNB | Spot-check 1 karyawan | Total bruto, PPh21, NPWP, status PTKP benar |

### Test 14.4 — Employee 360 (auditor view)

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | KOMIN | Buka **Employees → [Athaya Maulida] → 360** | 12 section: profile, contracts, attendance, leaves, payroll, performance, training, IDP, disciplinary, assets, transfers, audit log |

### Test 14.5 — Audit log untuk Komisaris Independen

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | KOMIN | **Admin → Audit Log** | Filter form |
| 2 | KOMIN | Filter `module=PAYROLL`, `action=payroll_period.finalize` | Daftar siapa & kapan finalize payroll |
| 3 | KOMIN | Filter `source=CANDIDATE_PORTAL`, `entity=candidate_offer` | List action self-service kandidat (Sprint 25 / Option C) — IP, UA, account_id tampil di metadata |
| 4 | KOMIN | Export ke CSV | OK |

### Test 14.6 — UU PDP — Data Subject Rights

| Step | Aktor | Aksi | Expected |
| ---: | --- | --- | --- |
| 1 | STF-BE | **Profile → Privacy → Data Export** | ZIP berisi semua data pribadi yang dimiliki sistem (throttle:export limit 10/jam) |
| 2 | STF-BE | **Profile → Privacy → Request Deletion** alasan "stop using service" | Request submitted |
| 3 | CHRO | **Admin → Privacy → Deletion Requests** → review → process | Anonymized fields, audit log |

---

## Tabel Cron Jobs

Setting di `app/Config/Tasks.php`:

| Schedule | Command | Test scenario |
| --- | --- | --- |
| Setiap menit | `attendance:auto-checkout` | Karyawan lupa clock-out di-set otomatis |
| Daily 06:00 | `check:probation:deadline` | Suite 4.5 |
| Daily 06:30 | `check:offer:expiry` | Offer >7 hari kadaluarsa (Suite 3.8) |
| Daily 07:00 | `check:helpdesk:sla` | Suite 10.2 |
| Daily 23:00 | `deactivate:resigned` | Suite 12.6 |
| Daily 23:30 | `check:bpjs:compliance` | Suite 12.4 |
| Weekly Mon 08:00 | `notify:carryover:cap` | Cuti carry-over warning |

Trigger manual saat testing:
```bash
php spark check:probation:deadline
php spark check:offer:expiry
php spark deactivate:resigned
php spark notify:carryover:cap
php spark check:bpjs:compliance
php spark check:helpdesk:sla
```

---

## Smoke Test Otomatis (PHPUnit)

Suite tes unit yang sudah ada dan harus tetap **HIJAU**:

```bash
cd backend

# Recruitment & onboarding
vendor/bin/phpunit tests/Unit/RecruitmentOnlineTestGateTest.php
vendor/bin/phpunit tests/Unit/OnboardingPlanServiceTest.php
vendor/bin/phpunit tests/Unit/OfferValidationTest.php
vendor/bin/phpunit tests/Unit/CandidateOfferSelfServiceTest.php   # Sprint 25 / Option C

# Master & operations
vendor/bin/phpunit tests/Unit/CostCenterServiceTest.php
vendor/bin/phpunit tests/Unit/Sprint7BulkApprovalAndCarryOverTest.php
vendor/bin/phpunit tests/Unit/Sprint9QuickWinsTest.php
vendor/bin/phpunit tests/Unit/IdpAndDelegationTest.php

# Lifecycle
vendor/bin/phpunit tests/Unit/OffboardingClearanceTest.php
vendor/bin/phpunit tests/Unit/Sprint4TerminationPolishTest.php

# Horizon services
vendor/bin/phpunit tests/Unit/Horizon2ServicesTest.php
vendor/bin/phpunit tests/Unit/Horizon3ServicesTest.php
vendor/bin/phpunit tests/Unit/Horizon4ServicesTest.php

# Atau full suite (target ~742 tests, 1700+ assertions, semua passing):
vendor/bin/phpunit
```

---

## Skrip Verifikasi Data

Dipakai setelah seeding atau migrasi besar:

```bash
cd backend

# Verifikasi seeder stakeholder Indonesia (32 user, role tree)
php scripts/verify-stakeholder-seed.php

# Verifikasi 200+ soal online test ter-seed lengkap
php scripts/verify-online-tests.php

# Audit hak akses (cek role-permission mapping)
php scripts/audit_permissions.php

# Cek format laporan WLKP siap upload
php scripts/verify-compliance-wlkp.php

# Scan kode untuk audit log call yang malformed
php scripts/find_bad_audit_calls.php
```

---

## Tips Eksekusi

1. **Reset cepat antar suite:**
   ```bash
   php spark migrate:refresh
   php spark db:seed SeedAll
   ```
   Atau partial:
   ```bash
   php spark db:seed IndonesianStakeholderSeeder
   ```

2. **Pakai 2 browser** (Chrome + Incognito) supaya bisa login 2 akun bersamaan (mis. requester + approver, atau HR internal + kandidat di portal). Untuk Suite 3 (Option C) bahkan butuh 3 tab: kandidat di Career Portal, MGR-REC di HRIS internal, KOMIN cek audit log.

3. **Capture screenshot** di setiap step dengan nama `S<suite>-<test>-<step>.png` untuk dokumentasi QA.

4. **Database snapshot** sebelum suite besar:
   ```bash
   mysqldump -u root hris_db > backup-pre-suite-N.sql
   ```

5. **Periksa logs** kalau ada error:
   ```
   backend/writable/logs/log-YYYY-MM-DD.php
   ```

6. **Cek email locally** dengan MailHog (atau provider sejenis) — semua notifikasi email kandidat (offer sent, password reset) bisa di-intercept dari `http://localhost:8025`.

7. **Inspect audit trail Option C:**
   ```sql
   SELECT created_at, action, severity, source, ip_address, metadata
   FROM audit_logs
   WHERE entity = 'candidate_offer'
     AND source = 'CANDIDATE_PORTAL'
   ORDER BY created_at DESC
   LIMIT 20;
   ```
   Pastikan setiap accept/reject self-service tercatat dengan IP, user_agent, dan `metadata.actor='candidate'`.

---

## Acceptance Criteria

Test plan dianggap **PASSED** kalau:

- ✅ Semua suite 0-14 lulus tanpa error 500
- ✅ Email notifications terkirim (cek MailHog atau log)
- ✅ Audit log mencatat semua action create/update/delete
- ✅ Approval chains route ke approver yang benar berdasarkan tier (single + multi-tier + bulk approve)
- ✅ Payroll calculation sesuai dengan PMK 168/2023 (TER) untuk minimal 5 karyawan acak
- ✅ BPJS calculation sesuai 5 program (Kes 1%, JHT 2%, JP 1%, JKK & JKM company-side)
- ✅ Slip gaji + Bukti Potong PDF render dengan benar
- ✅ Geofence reject clock-in di luar radius
- ✅ Online test auto-grade benar untuk MCQ + auto-submit saat timer habis + anti-cheat event log tercatat
- ✅ **Sprint 25 / Option C — Candidate self-service offer:**
  - ✅ Menu "Penawaran" tampil permanent di nav portal kandidat setelah login
  - ✅ Badge merah angka muncul saat ada offer status SENT
  - ✅ Halaman list & detail menampilkan breakdown kompensasi lengkap
  - ✅ Accept flow auto-progress kandidat ke stage HIRED
  - ✅ Reject flow tidak ubah candidate stage (HR yang putuskan)
  - ✅ Audit log dengan `source=CANDIDATE_PORTAL` + IP + UA + `metadata.actor='candidate'`
  - ✅ Ownership check: kandidat tidak bisa lihat offer milik kandidat lain (404)
  - ✅ DRAFT offer di-block 404 OFFER_NOT_VISIBLE
  - ✅ Deadline lewat → auto-expire + reject dengan pesan jelas
  - ✅ HR proxy respond tetap berfungsi untuk backward compat
- ✅ JIC (PROMOTION/DEMOTION) jalan multi-tier + consent flow untuk demotion
- ✅ Employee Transfer (mutasi sederhana) approve & implement update branch_id
- ✅ Resignation → offboarding clearance (asset + BPJS + paklaring + final settlement) → severance otomatis
- ✅ Termination workflow PHK formal (DRAFT → BIPARTITE → HR Director → BOD → EXECUTED)
- ✅ Cron jobs jalan di schedule yang ditentukan
- ✅ Compliance reports (WLKP + BPJS + PPh 21) generate dengan format kompatibel upload regulator
- ✅ Audit log auditor (KOMIN) dapat filter by `source=CANDIDATE_PORTAL` untuk forensic Option C
- ✅ UU PDP Data Subject Rights: data export + deletion request berfungsi
