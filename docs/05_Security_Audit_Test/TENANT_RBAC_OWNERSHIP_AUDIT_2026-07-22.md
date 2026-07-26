# Audit Pembatasan Tenant, RBAC, dan Ownership

Tanggal audit: 22 Juli 2026

Status: **selesai diperbaiki dan diverifikasi**

## Tujuan dan cakupan

Audit ini menyisir batas akses pada backend dan frontend HRIS, meliputi:

- isolasi data antar-company/tenant pada controller, service, repository, model, job, dan query dinamis;
- pembatasan user dan employee untuk data pribadi, bawahan, atau data organisasi;
- permission route API dan akses langsung melalui URL frontend;
- visibilitas tombol aksi agar sesuai dengan RBAC;
- approval payroll, notifikasi, upload, perangkat absensi, master data, recruitment, asset, serta import;
- static security gates untuk route, permission, akses database controller, data sensitif, dan tenant scope.

Pemeriksaan otomatis mencakup **369 file backend** dan **958 route API**. Pemeriksaan manual difokuskan pada jalur yang melakukan baca/tulis data tenant, menerima ID dari client, memakai nama tabel dinamis, atau menampilkan aksi mutasi di UI.

## Temuan dan eksekusi perbaikan

| ID | Tingkat | Area | Temuan | Perbaikan | Status |
|---|---|---|---|---|---|
| TEN-001 | Critical | Attendance device | CRUD dan sinkronisasi perangkat dapat memakai ID tanpa scope company yang konsisten; UI mengirim `company_id` hard-coded. | Semua operasi perangkat dibatasi tenant aktif, field tenant/status sinkronisasi tidak dipercaya dari client, dan UI tidak lagi mengirim company hard-coded. | Fixed |
| TEN-002 | Critical | Benefit policy | CRUD policy belum mengikat company dan validasi job grade tenant tidak konsisten. | Controller ditulis ulang dengan whitelist field, scope company pada semua operasi, serta validasi relasi job grade dalam tenant. | Fixed |
| TEN-003 | High | Generic master CRUD | `show/update/delete` generic master berpotensi mengakses ID milik tenant lain. | Semua lookup dan mutasi wajib memakai `company_id` tenant aktif. | Fixed |
| TEN-004 | High | Shift schedule/roster | Pattern, shift, employee, dan schedule belum selalu divalidasi berada di tenant yang sama. | CRUD, generate, bulk update, dan relasi shift/employee/pattern diberi company scope. | Fixed |
| TEN-005 | High | Machine sync | API key ADMS dapat jatuh ke nilai default dan beberapa update tidak mengikat company. | Konfigurasi kosong sekarang fail-closed, perbandingan secret constant-time, dan seluruh lookup/update discope company. | Fixed |
| OWN-006 | High | File upload | Generic attachment memvalidasi parent tetapi belum membedakan owner employee dan permission pengelola per konteks. | Parent wajib tenant aktif; ticket, dokumen kandidat, dokumen employee, dan sertifikat training memakai rule owner-or-manager yang sesuai. | Fixed |
| TEN-007 | High | Approval dan payroll | Lookup chain, step, request, approver, delegation, outbox, dan notifikasi belum semuanya mempertahankan scope company sepanjang workflow. | Company guard diterapkan end-to-end, termasuk keputusan approval, delegated approver, finalisasi, outbox, dan notification update. | Fixed |
| TEN-008 | High | Asset/recruitment/import/cost center | Sejumlah query asset, offer, import, serta actual cost center masih mengandalkan ID/relasi implisit. | Scope eksplisit ditambahkan pada history/mutasi asset, candidate offer, lookup import, dynamic upsert, dan seluruh sumber actual cost center. | Fixed |
| AUTH-009 | High | Login NIK | NIK yang sama pada dua tenant dapat menghasilkan pemilihan akun ambigu. | Login mendukung `KODE_PERUSAHAAN/NIK`; NIK ambigu tanpa kode ditolak dengan `TENANT_IDENTIFIER_REQUIRED`. | Fixed |
| RBAC-010 | High | Frontend router | Guard belum memproses `permissionAny`; route super-admin dan route langsung dapat lolos dari pembatasan UI. | Guard sekarang mendukung any-of permission, super-admin-only, dan employee-only identity. | Fixed |
| RBAC-011 | High | Direct URL | Banyak halaman hanya tersembunyi dari sidebar tetapi tidak mempunyai metadata permission route frontend. | Permission default ditambahkan untuk master, HR, payroll, recruitment, talent, compliance, admin, report, dan self-service; detail route mengikuti permission read yang sesuai. | Fixed |
| RBAC-012 | Medium | Tombol aksi | Tombol create/edit/delete/import/recompute/approve dapat terlihat walau user hanya punya hak read. | Tombol serta handler mutasi di payroll period, approval chain, cost center, org structure, shift, dan physical device dibatasi permission terkait. | Fixed |
| RBAC-013 | Medium | Employee-only page | Akun tenant yang tidak terhubung ke employee masih dapat membuka shell halaman self-service. | Route self-service penting memakai `requiresEmployee`; API tetap menjadi enforcement utama. | Fixed |
| TOOL-014 | High | Tenant leak scanner | Query dengan nama tabel dinamis tidak terdeteksi oleh scanner lama. | Rule `DYNAMIC_TABLE_NO_TENANT_FILTER` ditambahkan dan generic CRUD/import yang terdeteksi telah diperbaiki. | Fixed |
| FLOW-015 | High | Payroll approval | Approval payroll belum sepenuhnya memakai approval chain dan aksi approve tidak tampil berdasarkan approver aktif. | Workflow payroll memakai chain Payroll Admin → Assistant Manager Payroll → Manager Payroll, aksi diturunkan dari server, detail approval tersedia dalam modal lebar, dan validasi ganda diterapkan. | Fixed |
| MIG-016 | High | Fresh installation | Migration RBAC historis mengaudit katalog menggunakan service runtime yang sudah berkembang, sehingga fresh migration gagal sebelum permission migration berikutnya; klasifikasi tenant unique key juga tertinggal. | Audit antara dipisahkan dari migration historis, final resync/audit ditambahkan setelah seluruh permission, dan klasifikasi tenant/unique key diselaraskan. | Fixed |
| AUTH-017 | High | Web session refresh | Refresh token yang ditolak masuk kembali ke interceptor 401 yang sama, membuat promise menunggu dirinya sendiri sementara polling notifikasi terus menghasilkan 401. | Endpoint auth dikecualikan dari retry recursion, access token di-refresh proaktif, refresh paralel memakai satu promise, sesi invalid fail-closed ke login, dan polling notifikasi dihentikan. | Fixed |
| RBAC-018 | High | Live authorization state | API langsung memakai permission terbaru, tetapi menu, tombol, filter, dan data yang sudah dimuat masih memakai snapshot saat login sampai reload. | Snapshot otorisasi ringan disinkronkan tiap 15 detik serta saat tab kembali aktif/fokus; perubahan me-refresh menu, me-remount modul agar data lama dibuang, dan memindahkan user dari route yang sudah tidak diizinkan. | Fixed |
| TEN-019 | Critical | Global role template | Mengedit system role global dari konteks satu tenant dapat mengubah permission seluruh tenant yang memakai role tersebut. | System role memakai clone-on-write: edit pertama membuat role lokal, memindahkan user dan referensi approval chain tenant tersebut, sementara template global dan tenant lain tetap utuh; permission `super_admin` dibuat immutable karena merupakan break-glass bypass. | Fixed |
| RBAC-020 | Medium | Privileged approval | Debugging permission oleh Super Admin masih membuat request maker-checker sehingga pemulihan akses membutuhkan alur panjang. | Filter privileged action melewati approval hanya untuk group `RBAC` ketika aktor adalah Super Admin; controller tetap memvalidasi tenant dan mencatat audit kritis. User lain tetap melalui approval, sedangkan ekspor PII dan integration secret tidak ikut dibypass. | Fixed |

## Model pembatasan yang berlaku

1. **API adalah enforcement utama.** Setiap query data tenant wajib mendapat company dari konteks JWT, bukan dari payload client.
2. **RBAC route membatasi kapabilitas.** Endpoint mutasi memakai permission khusus; endpoint self-service memakai permission/ownership identity.
3. **Ownership mempersempit hasil.** Permission melihat data tidak otomatis memberi akses ke semua employee; privacy scope menentukan own, team, atau all.
4. **Frontend adalah defense-in-depth.** Menu, route langsung, tombol, dan modal mengikuti permission dari user, tetapi keputusan akhir tetap dari backend.
5. **Approval bersifat server-authoritative.** Tombol approve hanya muncul jika response server menyatakan `can_approve`; server kembali memvalidasi tenant, urutan step, approver, status, dan idempotency.

## Modul yang disisir

- Identity/auth: login, role, permission, user, employee identity, super-admin.
- Organization/master: company, branch, department, position, job title/grade, org structure, cost center, benefit, shift, approval chain.
- Time: attendance, device/machine sync, WFH, roster, shift schedule/pattern/assignment, leave, overtime.
- Payroll/finance: period, payslip, component, reimbursement, loan, bonus, THR, cost-center actual, approval/finalization.
- Employee lifecycle: employee, transfer, resignation, termination, probation, onboarding, contract, asset, business trip.
- Recruitment/talent: candidate, offer, job posting, pipeline, online test, training, certificate, performance, succession, nine-box, internal marketplace.
- Platform: upload, notification, email/outbox, audit, compliance/privacy, billing, data export, integrations, menu manager.

## Catatan scanner dan baseline historis

Tenant scanner saat ini melaporkan **0 temuan baru**. Terdapat **626 fingerprint yang telah direview** dan dilewati oleh strict gate. Angka ini adalah sinyal heuristik, bukan 626 kebocoran terkonfirmasi: sebagian berasal dari tabel global, child table yang discope lewat parent, query yang sudah dibatasi sebelumnya dalam builder, atau pola yang telah ditinjau manual. Dua fingerprint baru adalah helper agregasi lintas-tenant read-only pada Super Admin Operations yang hanya dapat dicapai melalui endpoint `jwt + superadmin`. Baseline tetap dipertahankan agar setiap tambahan pola baru membuat CI gagal, sambil refactor repository historis dapat dilakukan bertahap tanpa mematikan gate.

## Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| PHPUnit backend | 1.264 test, 3.857 assertion, lulus |
| Frontend unit test | 15 file, 66 test, lulus; termasuk Super Admin Operations, live RBAC sync, session refresh, `permissionAny`, super-admin, dan employee-only guard |
| Route policy gate | 958 route, 0 route terlindungi tanpa policy |
| Permission registry gate | Lulus; seluruh permission route terdaftar |
| Tenant leak strict gate | 369 file, 0 temuan baru |
| Controller DB access gate | 0 penambahan direct DB access di luar baseline |
| Sensitive repository gate | Lulus |
| Migration static/runtime SQLite | Lulus |
| PHPStan | 225 file, 0 error |
| Frontend production build | Lulus |
| Frontend ESLint | 0 error; warning historis non-security tetap dicatat oleh lint |

## Skenario regresi minimum

- ID milik company B pada request company A harus menghasilkan not-found/forbidden dan tidak boleh membocorkan keberadaan record.
- User read-only tidak melihat tombol create/edit/delete/import/recompute/finalize.
- Membuka URL halaman tanpa permission dialihkan ke dashboard.
- Akun tanpa `employee_id` tidak dapat membuka halaman employee-only.
- Employee tetap dapat membuka self-service jika memiliki permission own, tanpa membutuhkan permission view-all.
- Approver payroll hanya dapat approve step aktif pada company sendiri; requester tidak dapat self-approve ketika chain melarangnya.
- NIK duplikat lintas tenant harus memakai format `KODE_PERUSAHAAN/NIK`.
