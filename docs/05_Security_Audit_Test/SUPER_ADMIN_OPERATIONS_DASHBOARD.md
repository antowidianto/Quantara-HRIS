# Super Admin Operations Dashboard

Tanggal implementasi: 26 Juli 2026

## Tujuan

`/dashboard` menampilkan **Platform Command Center** ketika user memiliki role
`super_admin`. Dashboard personal HRIS tetap digunakan oleh seluruh role lain.
Halaman yang sama juga dapat dibuka langsung melalui `/admin/operations`.

Command center berfungsi sebagai lapisan monitoring dan triage. Tindakan
operasional tetap dilakukan pada modul sumber melalui shortcut atau drill-down,
sehingga validasi RBAC, tenant, ownership, maker-checker, dan idempotency pada
modul tersebut tidak dilewati.

## Cakupan monitoring

| Area | Indikator utama | Sumber |
|---|---|---|
| Platform health | Database, cache, queue, storage, subscription module, durasi check | `HealthChecker` |
| Tenant | Total/aktif, trial, past due, suspended, user/karyawan aktif, user terkunci | `companies`, `subscriptions`, `users`, `employees` |
| Async pipeline | Pending/running/success/failed untuk job, business job, email, webhook, approval outbox | Tabel queue masing-masing |
| Workflow | Approval pending, tertahan >24 jam, keputusan 24 jam, privileged action menunggu/siap diterapkan | Approval engine |
| Payroll | Status periode, proses aktif, gagal, melewati pay date, approval payroll, kalkulasi 30 hari | Payroll period, approval, business job |
| Security | Login gagal, audit high/critical, user terkunci/nonaktif, perubahan RBAC menunggu | Login attempt, audit, user, privileged action |
| Business flow | Absensi, WFH, lembur, cuti, recruitment, onboarding, resignasi, probation, HR helpdesk | Modul HRIS terkait |
| Tenant risk | Ranking berbobot dari approval macet dan kegagalan job/delivery | Agregasi lintas modul |
| Failure feed | Kegagalan business job dan webhook terbaru tanpa payload sensitif | Metadata operasional |

## Status dan alert

- `HEALTHY`: dependency sehat dan tidak ada kondisi yang memerlukan perhatian.
- `DEGRADED`: platform masih berjalan, tetapi ada warning operasional.
- `CRITICAL`: dependency kritis gagal, job macet/gagal, atau payroll gagal.
- Dashboard melakukan refresh setiap 30 detik dan dapat dimatikan oleh operator.
- Fokus dashboard adalah triage. Alert mengarahkan operator ke modul sumber.

## Tenant risk score

Skor bukan penilaian kepatuhan atau performa karyawan. Skor hanya prioritas
operasional dengan bobot:

- approval tertahan >24 jam: `2`;
- email gagal: `2`;
- webhook gagal: `3`;
- background/business job gagal: `4`.

Skor dihitung per tenant dan delapan tenant dengan skor tertinggi ditampilkan.

## Security dan privacy

- Endpoint `GET /api/v1/admin/operations/overview` dilindungi filter JWT dan
  `superadmin`.
- Controller melakukan pemeriksaan `isSuperAdmin()` kembali sebagai
  defense-in-depth.
- Dua fingerprint query dinamis lintas-tenant pada helper agregasi telah
  direview dan dicatat dalam baseline tenant scanner. Pengecualian ini hanya
  berlaku pada service read-only di balik endpoint `jwt + superadmin`; scanner
  strict tetap menolak fingerprint baru lainnya.
- Dashboard tidak mengambil payload job, body email, payload webhook, data
  karyawan, rincian gaji, rekening, NIK, NPWP, token, atau secret.
- Failure feed hanya membawa source, reference, tenant, error code/pesan
  terbatas, dan timestamp.
- Semua drill-down kembali memakai policy API modul tujuan.
- Role `super_admin` tetap merupakan break-glass role dan permission-nya
  immutable; perubahan role tenant tetap melalui maker-checker.

## Ketahanan terhadap perbedaan migration

Setiap sumber opsional diperiksa dengan `tableExists`. Query modul opsional
dibungkus secara fail-soft sehingga tabel atau kolom yang belum tersedia
memberikan nilai `0`, tanpa menjatuhkan seluruh command center. Dependency
kritis tetap dilaporkan melalui status health.

## File utama

- Backend aggregator:
  `backend/app/Libraries/Admin/SuperAdminOperationsService.php`
- Backend endpoint:
  `backend/app/Controllers/Api/V1/Admin/SuperAdminOperationsController.php`
- Frontend:
  `frontend/src/views/Admin/SuperAdminDashboard.vue`
- Regression policy:
  `backend/tests/Unit/Security/SuperAdminOperationsPolicyTest.php`
- Regression UI:
  `frontend/src/views/Admin/__tests__/SuperAdminDashboard.spec.js`
