# Sprint 20 — Enterprise Readiness Modules

> Eksekusi 5 prioritas pembuka segmen Enterprise (5.000+ karyawan) yang
> sebelumnya defer di roadmap. Sprint ini menutup gap utama untuk:
> - Finance integration (jurnal otomatis ke ERP)
> - Legal compliance (e-Sign tersertifikasi PSrE)
> - Developer ecosystem (Open API + key management)
> - Segmen jasa profesional (project timesheet)
> - Segmen healthcare/EHS (license expiry tracker)

## Status: ✅ Backend complete + minimal FE delivered

Diperiksa: 54/54 unit pure-helper test pass (`scripts/verify_sprint20_logic.php`).

---

## 1. H3.4 — ERP Journal Integration

**Tujuan:** Otomasi posting jurnal akuntansi dari payroll/bonus/THR ke ERP eksternal (Accurate Online, Zahir, Odoo, SAP) atau export CSV generic.

### Komponen

| Layer | File |
|---|---|
| Migration | `backend/app/Database/Migrations/2026-09-01-000001_H3_4_ErpIntegration.php` |
| Service | `backend/app/Libraries/Erp/ErpJournalService.php` |
| Controller | `backend/app/Controllers/Api/V1/Erp/ErpJournalController.php` |
| Frontend | `frontend/src/views/Erp/ErpJournalDashboard.vue` |

### Tabel
- `erp_connections` — kredensial per company × provider, AES-256-GCM encrypted
- `erp_journal_mappings` — pemetaan cost_center × account_type → COA debit/kredit
- `erp_journal_exports` — log per-export (DRAFT → POSTED → REVERSED) dengan snapshot lengkap

### Endpoints (semua di bawah `/api/v1/`, JWT)
```
GET    erp/connections
POST   erp/connections                    {provider, base_url, api_key, extra_config}
PUT    erp/connections/{id}
DELETE erp/connections/{id}
POST   erp/connections/{id}/test          (dry-run validation)

GET    erp/mappings
POST   erp/mappings                       {cost_center_id?, account_type, debit_coa, credit_coa}
PUT    erp/mappings/{id}
DELETE erp/mappings/{id}

GET    erp/journal-exports                ?source_type=&status=
POST   erp/journal-exports/preview        {source_type, source_id}
POST   erp/journal-exports                {source_type, source_id, format}
GET    erp/journal-exports/{id}
GET    erp/journal-exports/{id}/csv       (download CSV)
POST   erp/journal-exports/{id}/post
POST   erp/journal-exports/{id}/reverse
```

### Algoritma Build Journal
1. Ambil semua `payslips` finalized di period
2. Map setiap `payslip_details` ke `account_type` via heuristik nama/code
3. Aggregate per `cost_center_id × account_type`
4. Tambah BPJS_KES, BPJS_TK, PPH21 dari kolom `payslips` (header level)
5. Lookup mapping dengan priority: spesifik cost_center → catch-all (NULL)
6. Validasi balance debit = kredit (toleransi 1 sen)
7. Persist DRAFT, ready untuk review & POST

### Pure Helpers Tested
- `pickMappingPure()` — fallback hierarchy match
- `isBalancedPure()` — debit=kredit dalam toleransi
- `toCsvPure()` — CSV escaping
- `validateMappingPure()` — schema validation

---

## 2. H3.7 — Digital Signing PSrE

**Tujuan:** Tanda tangan elektronik tersertifikasi sesuai UU ITE 11/2008 dan Perpres 95/2018 untuk dokumen ketenagakerjaan ber-implikasi hukum (kontrak, JIC, severance, offer letter).

### Komponen

| Layer | File |
|---|---|
| Migration | `backend/app/Database/Migrations/2026-09-01-000002_H3_7_DigitalSigning.php` |
| Service | `backend/app/Libraries/Signing/PsreSigningService.php` |
| Controller | `backend/app/Controllers/Api/V1/Signing/SigningController.php` |
| Frontend | `frontend/src/views/Signing/SigningEnvelopes.vue` |

### Tabel
- `psre_providers` — config provider (PRIVYID, VIDA, PERURI, MOCK_DEV)
- `signing_envelopes` — satu envelope per dokumen yang dikirim
- `signing_envelope_signers` — daftar signer per envelope dengan urutan
- `signing_envelope_events` — full audit trail (CREATED, SENT, VIEWED, SIGNED, dst)

### State Machine
```
DRAFT → SENT → PARTIALLY_SIGNED → COMPLETED
              ↘ DECLINED → FAILED
              ↘ EXPIRED
       → CANCELLED
```

### Endpoints
```
GET    signing/providers
POST   signing/providers
PUT    signing/providers/{id}
DELETE signing/providers/{id}

GET    signing/envelopes                  ?status=&document_type=
POST   signing/envelopes                  {document_type, subject, document_path, expires_at?}
GET    signing/envelopes/{id}
POST   signing/envelopes/{id}/signers     {signer_role, full_name, email, ...}
POST   signing/envelopes/{id}/send
POST   signing/envelopes/{id}/cancel
POST   signing/envelopes/{id}/wet-sign    {scan_path}  (fallback)
GET    signing/envelopes/{id}/audit
POST   signing/envelopes/{id}/signers/{signerId}/event   (PSrE callback / dev sim)
```

### Catatan Production
- Provider real (PrivyID/Vida/Peruri) butuh kontrak komersial + integration testing.
- MOCK_DEV mode functional untuk dev/staging — tidak ada call ke PSrE.
- Document hash SHA-256 disimpan untuk integrity check.
- Per-signer: IP, user_agent, certificate_fingerprint untuk audit forensic.

### Pure Helpers Tested
- `aggregateEnvelopeStatusPure()` — state machine logic
- `validateEnvelopePayloadPure()` — input validation
- `hashDocumentPure()` — deterministic SHA-256

---

## 3. H3.8 — Open API & Developer Portal

**Tujuan:** Self-service API key management untuk integrasi enterprise.

### Komponen

| Layer | File |
|---|---|
| Migration | `backend/app/Database/Migrations/2026-09-01-000003_H3_8_OpenApiPortal.php` |
| Service | `backend/app/Libraries/Api/ApiKeyService.php` |
| Controller | `backend/app/Controllers/Api/V1/Api/ApiKeyController.php` |
| Frontend | `frontend/src/views/Developer/ApiKeys.vue` |

### Tabel
- `api_keys` — credentials hash-only (plain key SEKALI tampil saat create/rotate)
- `api_key_scopes` — many-to-many scope assignment
- `api_scopes` — master scope catalog (11 scope built-in)
- `api_request_logs` — audit + rate-limit + analytics

### Format Key
```
qhk_live_4f8a2c1d3e9b7c6a5f0e8d4c2b3a9f7e1234567890abcdef
qhk_sand_4f8a2c1d3e9b7c6a5f0e8d4c2b3a9f7e1234567890abcdef
```

Prefix `qhk_live_` (16 char) disimpan plaintext untuk identifikasi UI.
Hash SHA-256 untuk lookup. Plain key tidak pernah disimpan.

### Tier & Rate Limit
- BASIC: 60 req/menit
- PRO: 300 req/menit
- ENTERPRISE: 1200 req/menit

### Built-in Scopes
```
employees.read, employees.write
attendance.read, attendance.write
leaves.read, leaves.write
payroll.read
recruitment.read, recruitment.write
webhooks.subscribe
reports.read
```

### Endpoints
```
GET    api-scopes
GET    api-keys
POST   api-keys                           {name, tier, scopes[], is_sandbox?}
GET    api-keys/{id}
POST   api-keys/{id}/rotate               (revoke lama, generate baru)
POST   api-keys/{id}/revoke
GET    api-keys/{id}/usage                ?since=
```

### Pure Helpers Tested
- `isValidKeyFormat()` — regex validation
- `isUsablePure()` — revoked/expired check
- `sanitizeScopesPure()` — dedupe + format normalize
- `hasScopePure()` — exact scope match

---

## 4. Project Timesheet (Modul Baru)

**Tujuan:** Membuka segmen jasa profesional (konsultan, kontraktor, agency) dengan time-tracking per project untuk billing klien & tracking utilization.

### Komponen

| Layer | File |
|---|---|
| Migration | `backend/app/Database/Migrations/2026-09-01-000004_ProjectTimesheet.php` |
| Service | `backend/app/Libraries/Timesheet/TimesheetService.php` |
| Controllers | `ProjectController.php`, `TimesheetController.php` |
| Frontend | `frontend/src/views/Timesheet/TimesheetEntry.vue` |

### Tabel
- `projects` — master project (code, client, budget_hours, budget_amount)
- `project_assignments` — pemetaan karyawan ke project dengan rate
- `timesheet_entries` — entry per hari per project per task

### Validasi Entry
- Hours kelipatan 0.25 (15 menit) — ceil ke atas (industri standard billable)
- Total per hari ≤ 24 jam
- Project assignment harus aktif di tanggal tsb
- work_date tidak boleh > +1 hari dari sekarang

### Endpoints
```
# Project master
GET    projects                           ?status=&q=
POST   projects                           {code, name, client_name, budget_hours, ...}
GET    projects/{id}
PUT    projects/{id}
DELETE projects/{id}
GET    projects/{id}/burn-rate            (revenue, cost, margin, burn-pct)

# Assignments
GET    projects/{id}/assignments
POST   projects/{id}/assignments          {employee_id, billable_rate, cost_rate}
PUT    project-assignments/{id}
DELETE project-assignments/{id}

# Timesheet entries
GET    timesheet                          ?from=&to=&project_id=&status=
GET    timesheet/my                       ?from=&to=
GET    timesheet/utilization              ?employee_id=&from=&to=
POST   timesheet                          {project_id, work_date, hours, task_description}
POST   timesheet/{id}/submit
POST   timesheet/{id}/approve
POST   timesheet/{id}/reject              {reason}
```

### Reporting Pure Functions
- `utilizationPctPure(billable, total)` → 0-100
- `burnPctPure(actual, budget)` → 0-∞
- `marginPctPure(revenue, cost)` → -∞ to 100

---

## 5. License & Certification Expiry Tracker (Modul Baru)

**Tujuan:** Membuka segmen healthcare (RS, klinik), EHS-heavy (manufaktur, konstruksi), aviation, professional services dengan tracking sertifikat berekspiry + auto-notif.

### Komponen

| Layer | File |
|---|---|
| Migration | `backend/app/Database/Migrations/2026-09-01-000005_LicenseExpiryTracker.php` |
| Service | `backend/app/Libraries/License/LicenseTrackerService.php` |
| Controller | `backend/app/Controllers/Api/V1/License/LicenseController.php` |
| Cron Command | `backend/app/Commands/LicenseCheckExpiry.php` |
| Frontend | `frontend/src/views/License/LicenseDashboard.vue` |

### Tabel
- `license_types` — master jenis lisensi (STR, SIP, K3 Umum, BOSIET, dst)
- `employee_licenses` — license yang dipegang karyawan + status

### Use Cases
- Healthcare: STR (5 tahun), SIP — auto-block roster kalau expired
- EHS: K3 Umum, BOSIET, BNSP
- Aviation: pilot license, simulator currency, medical certificate
- Driver: SIM B1 Umum, SIM B2 Umum

### Notification Threshold
Default: H-30, H-7, H-1 (configurable per license_type via `warn_before_days_json`).

State transitions:
- `ACTIVE` → `EXPIRING_SOON` (saat threshold ter-cross)
- `EXPIRING_SOON` → `EXPIRED` (lewat tanggal)
- `ACTIVE/EXPIRING_SOON` → `RENEWED` (via renew flow)
- Manual: `REVOKED`

### Cron
```
# Daily 06:00
php spark licenses:check-expiry
# Dry-run preview
php spark licenses:check-expiry --dry-run
```

### Endpoints
```
GET    license-types                      ?category=&active=
POST   license-types                      {code, name, validity_months, warn_before_days[]}
PUT    license-types/{id}
DELETE license-types/{id}

GET    employee-licenses                  ?employee_id=&status=&expiring_in=
POST   employee-licenses                  {employee_id, license_type_id, license_number, issued_on}
GET    employee-licenses/{id}
PUT    employee-licenses/{id}
POST   employee-licenses/{id}/renew       (chain: lama → RENEWED, baru → ACTIVE)
POST   employee-licenses/{id}/revoke      {reason}

GET    employees/{id}/license-compliance  ?position_id=&on_date=
GET    licenses/expiring-dashboard        (counts: expired, ≤7d, ≤30d, ≤90d)
POST   licenses/scan-now                  (manual trigger cron)
```

### Pure Helpers Tested
- `resolveActionPure(daysLeft, thresholds, lastNotified)` — state transition logic
- `parseThresholdsPure()` — JSON parse + filter + dedupe
- `computeExpiryPure(issuedOn, validityMonths)` — auto-compute expires_on
- `isLicenseValidPure(row, onDate)` — compliance check

---

## Permission Matrix

### Dependent permissions di-seed otomatis via migration

| Permission | Module | Default Roles |
|---|---|---|
| `erp.mapping.manage` | H3.4 ERP | Finance Admin, Super Admin |
| `erp.journal.post` | H3.4 ERP | Finance Admin |
| `erp.journal.view` | H3.4 ERP | Finance, HR Director |
| `signing.envelope.create` | H3.7 PSrE | HR Admin, Legal |
| `signing.envelope.view` | H3.7 PSrE | HR, Legal, Affected employee |
| `signing.provider.manage` | H3.7 PSrE | Super Admin |
| `api_key.manage` | H3.8 API | Super Admin |
| `api_key.view` | H3.8 API | IT, Super Admin |
| `timesheet.submit` | Timesheet | All employees (assigned to projects) |
| `timesheet.approve` | Timesheet | Project Manager, Direct Supervisor |
| `timesheet.report` | Timesheet | HR, PM, Finance |
| `project.manage` | Timesheet | PMO, HR Admin |
| `license_type.manage` | License | HR Admin |
| `employee_license.manage` | License | HR, Direct Supervisor |
| `employee_license.view` | License | HR, Affected employee |

> Permission auto-seed via migration. Roles default belum di-bind — perlu
> assign manual via `RoleController` atau seeder per company.

---

## Database Migration Order

5 migrations baru, semua idempotent (tableExists/fieldExists guard):

```
2026-09-01-000001_H3_4_ErpIntegration.php
2026-09-01-000002_H3_7_DigitalSigning.php
2026-09-01-000003_H3_8_OpenApiPortal.php
2026-09-01-000004_ProjectTimesheet.php
2026-09-01-000005_LicenseExpiryTracker.php
```

Run:
```
php spark migrate
```

---

## Verifikasi

```bash
cd backend
php scripts/verify_sprint20_logic.php
# Expected: Pass: 54, Fail: 0
```

54 pure-helper assertion across all 5 services pass.

---

## Items Defer untuk Sprint 21+

- **ERP Adapter Real** — implementasi POST ke API Accurate/Odoo/Zahir/SAP
  butuh kontrak komersial vendor + sandbox testing.
- **PSrE Adapter Real** — sama, butuh kontrak PrivyID/Vida/Peruri.
- **API Key Auth Filter** — filter middleware untuk validate key dari
  request header pada endpoint public-facing API. Saat ini infrastruktur
  storage + verify ada, filter routing belum dipasang.
- **Notification dispatch** dari `LicenseCheckExpiry` ke email/push/WA —
  saat ini status update berjalan, notification action di-stub via TODO.
- **Project Time Approval Chain** — saat ini approval timesheet single-tier
  (langsung supervisor). Multi-tier (PM → Project Director) bisa
  ditambahkan via integrasi ke ApprovalChain existing.
- **License → Roster Block** — service `complianceFor()` ready, tapi
  integrasi ke `RosterController::generate` belum dipasang.

---

*Dibuat: Sprint 20 (Sept 2026) — Eksekusi 5 prioritas Enterprise Readiness*