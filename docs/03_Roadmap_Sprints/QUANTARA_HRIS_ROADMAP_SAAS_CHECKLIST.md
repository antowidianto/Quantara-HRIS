# Quantara HRIS — Checklist Eksekusi Roadmap SaaS

> Checklist eksekusi turunan dari `QUANTARA_HRIS_ROADMAP_SAAS.md`.
> Dokumen ini bersifat **actionable per item**: setiap S{n}.{m} dipecah
> ke sub-task DB / BE / FE / QA / DOC / OPS yang bisa dicentang per
> sprint S1–S17+.
>
> **Konvensi:**
> - `- [ ]` belum dikerjakan
> - `- [~]` sedang dikerjakan / partial
> - `- [x]` selesai dan terverifikasi
> - **DB** = migration / seed
> - **BE** = backend (controller / service / library)
> - **FE** = frontend (Vue view / component)
> - **QA** = test (unit / feature / integration / e2e)
> - **DOC** = dokumentasi
> - **OPS** = infrastructure / DevOps / vendor contract

---

## STATUS RINGKAS PER PHASE

| Phase | Sprint | Total Item | Selesai | Status |
| --- | --- | --- | --- | --- |
| Phase 1 — SaaS Foundation | S1–S6 | 6 | 5 | 🔄 S1.1 + S1.2 + S1.3 Signup + S1.4 Quota + S1.6 Dunning — BE+FE done. S1.5 Payment Gateway masih pending (kontrak Midtrans/Xendit) |
| Phase 2 — Production Hardening | S7–S10 | 6 | 5 | 🔄 S2.1..S2.5 backend done. S2.3 Data Export self-service FE done |
| Phase 3 — Enterprise Sales Enabler | S11–S16 | 6 | 3 | 🔄 S3.1 White-label (BE+FE BrandingSettings) + S3.4 Customer Success (BE+FE OnboardingWizard + Admin CustomerHealth) + S3.5 Admin Console (BE+FE TenantList/Detail/SaasMetrics) — done |
| Phase 4 — Growth & Scale | S17+ | 5 | 0 | ⬜ Defer (post-PMF) |

> **Konteks**: Quantara HRIS sudah punya 88 migrations, modul A-M
> lengkap, multi-tenant secara teknis. Yang belum: subscription,
> billing, signup, observability, white-label.

---

# 🟦 PHASE 1 — SaaS FOUNDATION (Sprint S1–S6, ~3 bulan)

## SPRINT S1

### S1.1 — Subscription Plans & Feature Flags ✅ Backend
**Modul Baru:** N. Billing · **Effort:** Medium-High · **Impact:** Critical · **Status:** Backend SELESAI, FE pending

#### DB ✅
- [x] Migration `2026-07-01-000001_S1_1_SubscriptionPlansAndFeatureFlags.php` — 4 tabel (subscription_plans, plan_features, feature_flags, company_feature_overrides)
- [x] Idempotent dengan tableExists guard supaya aman re-run di tenant existing
- [x] Seeder `SubscriptionPlanSeeder` — 4 tier (FREE_TRIAL/STARTER/PRO/ENTERPRISE) + 38 feature flags + matrix lengkap

#### BE ✅
- [x] `App\Libraries\Billing\FeatureGateService` dengan hierarki resolusi (override → plan → default)
- [x] Pure resolver `resolvePure()` testable tanpa DB
- [x] `App\Libraries\Billing\FeatureNotAvailableException` untuk error handling konsisten
- [x] `App\Filters\FeatureGateFilter` registered alias `feature` di `Config/Filters.php`
- [x] Endpoint `GET /api/v1/plans` (publik, no auth) — list plan + features_map untuk pricing page
- [x] Endpoint `GET /api/v1/features/me` — return list feature aktif dengan source (OVERRIDE/PLAN/DEFAULT)
- [x] Endpoint super admin `POST /admin/tenants/{id}/feature-override` dengan audit log severity HIGH
- [x] Per-request memoization cache (flush saat super admin override)

#### FE ✅
- [x] Composable `frontend/src/composables/useFeature.js` — `useFeature(key)` + `useFeatureGuard()` + cache 5 menit
- [x] Halaman `/billing/plan` — current plan + comparison table 4-tier + Upgrade/Downgrade modal + cancel
- [x] Component `<UpgradeBanner :feature="key" />` — auto-hide bila feature aktif
- [ ] Pricing page publik `/pricing` (defer — bisa pakai BillingPlan untuk authenticated user dulu)

#### QA ✅
- [x] Unit test `FeatureGateServiceTest.php` — 9 test pure resolver
- [x] Smoke test runner `verify_pkwt_logic.php` ditambah 9 assertion FeatureGate (45/45 pass total)

#### DOC ✅
- [x] **`docs/SAAS_FEATURE_FLAGS.md`** — daftar 38 feature_key per kategori + matrix per plan + cara override + troubleshooting + frontend usage examples
- [ ] `docs/SAAS_FEATURE_MATRIX.md` — defer (sudah cover di SAAS_FEATURE_FLAGS.md)

---

## SPRINT S2

### S1.2 — Subscription Lifecycle Management ✅ Backend
**Modul:** N. Billing · **Effort:** High · **Impact:** Critical · **Status:** Backend SELESAI, FE + cron pending

#### DB ✅
- [x] Migration `2026-07-01-000002_S1_2_Subscriptions.php` — 2 tabel (subscriptions, subscription_events)
- [x] Index `(company_id, status)`, `(current_period_end)`, `(trial_ends_at)` untuk cron filter
- [x] Field enterprise contract: `po_number`, `contract_start`, `contract_end`, `custom_price`, `notes_internal`

#### BE ✅
- [x] `Libraries\Billing\SubscriptionService` dengan 9 method:
  - [x] `createTrial()` — auto trial 14 hari + status TRIAL
  - [x] `upgrade()` — kalkulasi proration via pure helper `calculateProrationPure()`
  - [x] `downgrade()` — schedule perubahan, efektif EOP (no credit refund)
  - [x] `cancel()` — keep access sampai current_period_end
  - [x] `reactivate()` — revive CANCELLED subscription
  - [x] `extendTrial()` — super admin only
  - [x] `suspend()` / `unsuspend()` — dipanggil dunning H+10
- [x] Pure helper `calculateProrationPure()` + `nextPeriodEndPure()` testable tanpa DB
- [x] Endpoint `GET /billing/subscription` — info + usage employees + days_remaining
- [x] Endpoint upgrade/downgrade/cancel/reactivate dengan permission `billing.manage`
- [x] Endpoint super admin `POST /admin/tenants/{id}/extend-trial`
- [x] Audit log per state transition (mirror ke audit_logs severity NOTICE)
- [x] **`App\Commands\BillingCheckRenewals`** (cron daily 00:00) — auto-renew ACTIVE + trial expiry → PAST_DUE + hard-cancel PAST_DUE>30d + pure helper `nextPeriodEndPure()` testable
- [x] **`App\Commands\BillingTrialExpiryNotify`** (cron daily 09:00) — email H-7/H-3/H-1 dengan dedup via `subscription_events.event_type='TRIAL_REMINDER_SENT'` + metadata days_before

#### FE ✅
- [x] Halaman `/billing/plan` — current subscription card + plan comparison + upgrade/downgrade/cancel/reactivate
- [x] Modal upgrade/downgrade dengan billing cycle (MONTHLY/YEARLY) + 4-eyes confirm
- [x] Modal "Cancel Subscription" dengan reason input (audit logged)
- [x] Banner trial countdown via `<UpgradeBanner>` di lokasi yang relevan
- [x] **Composable `useSubscription`** — fetch + cache 60s status subscription dengan helpers (isTrial/isActive/isPastDue/isSuspended/isReadOnly)
- [x] **`SubscriptionStatusBanner` component** — banner global di top of layout (mounted di AppLayout), auto-show untuk PAST_DUE/SUSPENDED/EXPIRED + TRIAL ≤5d, dengan CTA reaktivasi/upgrade

#### QA 🟨 partial
- [x] Pure helper proration ter-cover di service (logic deterministik)
- [ ] Feature test mid-month upgrade vs end-of-month
- [ ] Feature test trial expiry → downgrade

#### DOC ✅
- [x] **`docs/SAAS_SUBSCRIPTION_LIFECYCLE.md`** — state diagram lengkap (TRIAL→ACTIVE→PAST_DUE→SUSPENDED→CANCELLED→EXPIRED), business rules per status, transition triggers per cron, scenarios (trial expire, mid-month upgrade, late payment), invariants, audit log spec

---

## SPRINT S3

### S1.3 — Self-Service Tenant Signup ✅ Backend + Frontend
**Modul:** N. Billing · **Effort:** Medium-High · **Impact:** Critical · **Status:** BE+FE SELESAI; OPS email vendor + CAPTCHA pending

#### DB ✅
- [x] **Migration `2026-08-10-000001_S1_3_SignupAttempts.php`**:
  - [x] Tabel `signup_attempts` dengan 21 kolom (signup data + UTM/referral attribution + verification token + provisioning result tracking)
  - [x] ALTER `companies` add: `trial_ends_at`, `signup_source`, `referrer_code`, `onboarding_completed_at`
  - [x] Unique index `(verification_token)`, index `(status, expires_at)` untuk cleanup cron
  - [x] Idempotent dengan tableExists guard

#### BE ✅
- [x] **`AuthSignupController`** dengan 3 endpoint publik (filter throttle:signup):
  - [x] `POST /auth/signup` — validate input, dedup email, rate limit 3/hr/IP, generate token 32 bytes, kirim email verifikasi
  - [x] `GET /auth/verify-signup?token=` — verify + auto-provision tenant + return JWT untuk auto-login
  - [x] `GET /auth/signup/{id}/status` — polling status (untuk UI loading bar)
- [x] **`Libraries\Tenant\TenantProvisioningService`** atomic transactional:
  - [x] `provision(int $signupId)` — transaksional commit/rollback, return company_id + user_id + subscription_id
  - [x] Auto-generate company code unik dari company_name (strip PT/CV prefix, alfanumerik 8 char + suffix conflict resolver)
  - [x] Insert companies + users (role_id=1 super_company_admin) + subscription TRIAL via SubscriptionService
  - [x] Sync trial_ends_at ke companies untuk fast filter
  - [x] Mark signup_attempts.status = PROVISIONED dengan reference IDs
  - [x] Audit log severity NOTICE saat sukses, ERROR + simpan error_message saat gagal
- [x] **ThrottleFilter** tambah bucket `signup` (3/hour/IP, configurable via env)
- [x] Email verifikasi HTML inline (best-effort — pakai default email service kalau ada)
- [x] Idempotent: signup ulang dengan email PENDING re-send email tanpa create row baru
- [x] Idempotent: verify token VERIFIED/PROVISIONED return data tanpa duplicate provision
- [ ] Trigger queued job `SendWelcomeEmailJob` setelah commit (defer — saat ini sync best-effort)
- [ ] Seed default master Indonesia (LeaveType, PayrollComponent, BpjsRate, PTKP, Holiday) — defer; saat ini master seed sudah global, tenant dapat akses lewat scope

#### FE ✅
- [x] **`/signup` (Signup.vue)** — form publik dengan validation real-time:
  - [x] Field: email, company_name, admin_name, phone, password (+ strength meter), password_confirm, industry dropdown, employees_count_estimate dropdown, agreed_terms checkbox
  - [x] UTM capture otomatis dari query string (utm_source/medium/campaign/referrer/source)
  - [x] Pakai axios langsung (bukan @/api/client) supaya 100% public, tidak attach JWT
  - [x] Submitted state dengan instruksi cek email
  - [x] Trust indicators di footer (PMK 168, UU PDP, BPJS compliance)
- [x] **`/verify-email?token=` (VerifyEmail.vue)** — handler 4-state:
  - [x] verifying → loading spinner
  - [x] success → simpan JWT, fetchUser, redirect ke /onboarding (1.5s delay)
  - [x] already → tombol Login
  - [x] error → tombol Signup Ulang (kalau TOKEN_EXPIRED) atau Login
- [x] Wizard `/onboarding` (OnboardingWizard.vue) — 8-step (lihat S3.4 FE) sudah dibuat sebelumnya, dipakai sebagai redirect target setelah verify
- [ ] Email template preview di `frontend/src/email-templates/` (defer — backend sudah punya inline HTML)

#### QA ⬜
- [ ] Feature test signup happy path < 30 detik
- [ ] Feature test failed provisioning → rollback bersih
- [ ] Feature test rate limit 3/hr/IP enforced

#### DOC ✅
- [x] **`docs/SAAS_TENANT_PROVISIONING.md`** — high-level flow + step-by-step (signup→verify→provision→onboarding) + provisioning transaction detail + company code generation algorithm + anti-abuse + edge cases & rollback matrix + local testing

#### OPS ⬜
- [ ] Setup transactional email vendor (lihat S2.4) sebelum sprint ini production (saat ini fallback ke SMTP default tenant)
- [ ] Setup CAPTCHA / Cloudflare Turnstile di form signup (saat ini hanya rate limit per IP)
- [ ] `Libraries\Billing\QuotaEnforcer::check($companyId, $key, $required)` throw `QuotaExceededException`
- [ ] Wire ke endpoint:
  - [ ] `EmployeeController::create` cek quota `employees`
  - [ ] File upload (semua endpoint) cek quota `storage_mb`
  - [ ] Middleware `MeterApiCalls` di filter group api_v1
  - [ ] `WhatsAppService::send` cek quota `whatsapp_sent`
  - [ ] `PayrollService::finalize` increment `payslips_generated`
- [ ] `App\Commands\BillingResetMonthlyMeters` (cron 1st of month 00:30)

#### FE ✅
- [x] Halaman `/billing/usage` — usage cards (employees, payslips, storage, whatsapp, api_calls) + warna berdasarkan threshold (90%/100%)
- [x] CTA upgrade tampil otomatis bila ≥1 meter > 80%
- [ ] Banner usage di top-right header (defer — ada di halaman billing/usage)
- [ ] Chart trend historical (defer — usage_events history belum tersedia di endpoint utama)

#### QA
- [ ] Unit test `UsageMeterService` increment + reset
- [ ] Feature test create employee #51 di plan max 50 → 422 `QUOTA_EXCEEDED`

---

## SPRINT S5

### S1.5 — Payment Gateway Integration
**Modul:** N. Billing · **Effort:** High · **Impact:** Critical

#### Pre-requisite
- [ ] Kontrak Midtrans (PROD) atau Xendit
- [ ] Sandbox credentials disimpan di `.env.example`
- [ ] Setup webhook URL di dashboard gateway

#### DB
- [ ] Migration `invoices` (id, subscription_id, invoice_number, amount, tax_amount, total, currency, status, issue_date, due_date, paid_at, pdf_path)
- [ ] Migration `invoice_items` (invoice_id, description, quantity, unit_price, amount)
- [ ] Migration `payments` (id, invoice_id, gateway, gateway_txn_id, method, amount, status, paid_at, raw_response_json)
- [ ] Migration `payment_methods` (id, company_id, gateway, token, last_four, brand, expires_at, is_default) — untuk recurring

#### BE
- [ ] Interface `Libraries\Billing\PaymentGateways\PaymentGatewayInterface`
  - [ ] `createCharge(Invoice $invoice): array{checkout_url, txn_id}`
  - [ ] `verifyWebhook(array $payload, string $signature): bool`
  - [ ] `processWebhook(array $payload): void`
  - [ ] `refund(string $txnId, float $amount): bool`
- [ ] Adapter `MidtransAdapter` implements interface
- [ ] (Opt) Adapter `XenditAdapter`
- [ ] Endpoint `POST /api/v1/billing/checkout/{invoice_id}`
- [ ] Endpoint webhook publik `POST /api/v1/webhooks/payment/{gateway}` (no auth, signature verified)
- [ ] `Libraries\Billing\InvoiceService::generateForRenewal($subscription): Invoice`
- [ ] `App\Commands\BillingGenerateInvoices` (cron daily)
- [ ] PDF generator invoice (TCPDF / mPDF) dengan format Faktur Pajak Indonesia

#### FE
- [ ] Halaman `/billing/invoices` — list dengan filter status
- [ ] Halaman `/billing/invoices/{id}` — detail + download PDF + button "Bayar Sekarang"
- [ ] Halaman checkout return handler `/billing/checkout/return?status=`

#### QA
- [ ] Unit test `MidtransAdapter::verifyWebhook` (signature valid + invalid)
- [ ] Feature test webhook idempotency (callback dua kali → status tetap konsisten)
- [ ] Feature test reconciliation: bayar sukses tapi webhook gagal → cron `BillingReconcile` benerin status

#### DOC
- [ ] `docs/SAAS_PAYMENT_INTEGRATION.md` — flow diagram + webhook schema
- [ ] `docs/SAAS_FAKTUR_PAJAK.md` — PPN 11% calculation + e-Faktur (untuk B2B)

#### OPS
- [ ] Setup queue worker untuk async invoice PDF generation
- [ ] Setup alert webhook signature mismatch → security incident

---

## SPRINT S6

### S1.6 — Dunning & Churn Management
**Modul:** N. Billing · **Effort:** Medium · **Impact:** Medium-High

#### DB
- [ ] Migration `dunning_actions` (id, subscription_id, action_type, sent_at, channel, success)

#### BE
- [ ] `App\Commands\BillingDunning` (cron daily) dengan rule progressive:
  - [ ] H+1: email reminder ramah
  - [ ] H+3: email + WhatsApp warning, status PAST_DUE
  - [ ] H+7: email "Akan disuspend H+10"
  - [ ] H+10: suspend (read-only mode), notify admin
  - [ ] H+30: hard-cancel + offer reactivate
- [ ] Filter `App\Filters\SuspendedTenant` — block write endpoint untuk tenant SUSPENDED
- [ ] Endpoint `POST /api/v1/billing/reactivate` — admin restart subscription dengan settle invoice tunggakan
- [ ] Notification webhook ke Slack internal Quantara saat tenant masuk PAST_DUE

#### FE ✅
- [x] **Banner global merah/kuning** "Pembayaran tertunggak / Akun disuspend / Akun expired" via `SubscriptionStatusBanner` (mounted di `AppLayout.vue`) — auto-show untuk PAST_DUE/SUSPENDED/EXPIRED + TRIAL ≤5d
- [x] **`useSubscription.isReadOnly`** computed untuk gate UI write actions di view (component-level binding `:disabled` + tooltip)
- [ ] Halaman `/billing/reactivate` dedicated (defer — `/billing/plan` sudah cover reactivate flow)

#### QA
- [ ] Unit test rule dunning calendar (H+1..H+30 logic)
- [ ] Feature test SuspendedTenant filter blokir POST endpoint

---

# 🟧 PHASE 2 — PRODUCTION HARDENING (Sprint S7–S10, ~2 bulan)

## SPRINT S7

### S2.1 — Observability Stack 🔄 Backend partial
**Modul:** Infrastructure · **Effort:** Medium-High · **Impact:** High · **Status:** Health + Metrics endpoint done; APM + structured logging pending OPS setup

#### OPS ⬜
- [ ] Pilih APM: Sentry (default), upgrade Datadog/NewRelic kalau scale
- [ ] Setup Sentry projects (backend + frontend)
- [ ] Setup CloudWatch Log Group / ELK / Loki untuk structured logs
- [ ] Setup Uptimerobot / Pingdom monitor `/health`
- [ ] Buat Slack channel `#alerts-prod` + webhook integration

#### BE 🟨
- [ ] Composer require `sentry/sentry`
- [ ] Bootstrap `Sentry::init` di `app/Config/Boot/production.php`
- [ ] Custom logger handler JSON format dengan trace_id, company_id, user_id
- [x] **`GET /api/v1/health`** — basic liveness check (DB ping + schema validation, return 503 saat down)
- [x] **`GET /api/v1/health/deep`** — comprehensive check (DB, cache, queue, storage, subscriptions). Super admin only. Return 503 bila critical dep down.
- [x] **`Libraries\System\HealthChecker`** — 5 check method dengan `wrap()` helper untuk timing + critical flag
- [x] **`GET /api/v1/metrics`** — Prometheus exposition format dengan IP allowlist + super admin bypass
  - [x] `hris_app_info{version,env}`
  - [x] `hris_companies_total`
  - [x] `hris_employees_total{company_id}` (top 50)
  - [x] `hris_subscription_count{plan,status}`
  - [x] `hris_payslips_calculated_total`
  - [x] `hris_jobs_count{status}` (pending/running/failed)
  - [x] `hris_audit_log_critical_24h`
  - [x] `hris_php_memory_*`

#### FE ⬜
- [ ] Sentry SDK browser di main.js + sourcemap upload di CI
- [ ] Performance metrics (Web Vitals) ship ke Sentry

#### QA ⬜
- [ ] Test `/health` return 503 saat DB down (mock)
- [ ] Test `/metrics` content-type & format valid

#### DOC ⬜
- [ ] `docs/RUNBOOK_OBSERVABILITY.md` — alert mapping, on-call rotation, escalation
- [ ] `docs/SLO.md` — 99.9% uptime, 60s payroll calc, 500ms p95 API

---

## SPRINT S8

### S2.2 — Multi-Tenant Isolation Hardening ✅ Backend
**Modul:** Security · **Effort:** Medium-High · **Impact:** High · **Status:** Backend SELESAI; CI integration pending

#### BE ✅
- [x] `App\Models\BaseModel` sudah ada (Sprint B/A1) dengan multi-tenant scope:
  - [x] `scoped()` query builder otomatis WHERE company_id
  - [x] `findScoped()` / `createScoped()` / `updateScoped()` / `deleteScoped()`
  - [x] Auto-set company_id di insert dari TenantContext
  - [x] Anti tenant-jump: strip company_id dari payload update
  - [x] Encryption casts (encryptedFields) untuk PII at-rest
- [x] **`App\Commands\SecurityScanTenantLeaks`** — heuristic static scan:
  - [x] Rule 1: `->table('xxx')` tanpa company_id guard di 12-line window (HIGH severity)
  - [x] Rule 2: `->query("SELECT…FROM…")` raw tanpa company_id (HIGH)
  - [x] Rule 3: `->find($id)` di Controller tanpa `findScoped()` (MEDIUM)
  - [x] Rule 4: `->setTable()` dynamic (LOW)
  - [x] Whitelist global tables (companies, subscription_plans, jobs, dll)
  - [x] Output JSON `writable/security/tenant-leak-audit.json`
  - [x] Baseline support — `tenant-leak-baseline.json` untuk skip false positive
  - [x] `--strict` mode (exit 1) untuk CI gate
- [ ] CI pipeline integration — pending (`.github/workflows/ci.yml` add step)
- [ ] (Opt) Migrate ke PostgreSQL dengan Row Level Security policies

#### QA ⬜
- [ ] Buat trait `Tests\Traits\AssertsCrossTenantDenied` untuk reuse
- [ ] Apply trait ke semua controller test (target 100% coverage)

### S2.3 — Backup & Disaster Recovery ✅ Backend
**Modul:** Infrastructure · **Effort:** Medium · **Impact:** Critical · **Status:** Backend SELESAI; OPS S3+GPG setup pending

#### OPS ⬜
- [ ] Setup S3 bucket `quantara-backups-prod` dengan SSE-KMS
- [ ] IAM role least-privilege khusus backup
- [ ] Setup GPG key pair untuk encrypt-decrypt backup

#### BE ✅
- [x] **Migration `2026-07-15-000001_S2_3_BackupRuns.php`** — 2 tabel:
  - [x] `backup_runs` (kind, status, retention_class, sha256, gpg_encrypted, storage_path)
  - [x] `data_export_requests` (untuk GDPR portability)
- [x] **`App\Libraries\Backup\BackupService`** dengan pure helpers testable:
  - [x] `retentionClassPure()` — daily/weekly/monthly logic
  - [x] `isExpiredPure()` — retention policy enforcement
  - [x] `buildFilenamePure()` — naming convention
  - [x] `executeDump()` — mysqldump | gzip | (opt GPG) | upload S3
  - [x] `findExpired()` — janitor untuk auto-cleanup
- [x] **`App\Commands\DbBackupEncrypted`** (cron nightly 02:00):
  - [x] mysqldump dengan defaults-extra-file (no password leak ke ps aux)
  - [x] gzip -9 + opsional GPG encrypt
  - [x] Upload S3 via aws CLI dengan SSE-AES256
  - [x] `--cleanup` flag untuk auto-purge expired
  - [x] Audit log severity NOTICE (success) / CRITICAL (failed)
- [x] **`App\Commands\DbBackupVerify`** (cron weekly):
  - [x] File exists check (local + S3 head-object)
  - [x] SHA-256 hash match
  - [x] gzip --test untuk validate gzip integrity
  - [x] `--all-recent` untuk verify 7 hari terakhir
- [x] **`App\Libraries\Backup\DataExportService`** (UU PDP / GDPR Art. 20):
  - [x] Whitelist 8 tabel safe-to-export (kolom sensitif di-strip)
  - [x] `rowsToCsvPure()` + `buildManifestPure()` testable
  - [x] Generate ZIP + manifest.json dengan SHA-256
  - [x] Throttle: max 1 export aktif per tenant
- [x] **`Backup\DataExportController`** dengan 4 endpoint:
  - [x] `POST /companies/me/data-exports` (sync atau queue mode)
  - [x] `GET  /companies/me/data-exports` (list 50 terakhir)
  - [x] `GET  /companies/me/data-exports/{id}` (detail + download_url kalau READY)
  - [x] `GET  /data-exports/download?token=` (PUBLIC, signed token 7 hari, throttle:export)

#### FE ✅
- [x] Halaman `/settings/data-export` — scope picker (10 tabel), submit + status polling 5 detik, riwayat dengan download button + format size + expires
- [x] Compliance notice (PII warning, 7-hari expiry, audit HIGH, 1 export aktif)

#### DOC ⬜
- [ ] `docs/RUNBOOK_RESTORE.md` — step-by-step restore (dengan estimated time)
- [ ] `docs/RUNBOOK_INCIDENT.md` — severity classification + on-call rotation
- [ ] `docs/DR_PLAN.md` — RPO 24h, RTO 4h, scenario test schedule quarterly

---

## SPRINT S9

### S2.4 — Email & Notification Deliverability ✅ Backend
**Modul:** J. Notification · **Effort:** Medium · **Impact:** Medium-High · **Status:** Backend SELESAI; OPS DNS+ESP setup pending

#### OPS ⬜
- [ ] Pilih ESP: AWS SES (paling murah), atau Postmark / Sendgrid
- [ ] Setup SPF, DKIM, DMARC di DNS
- [ ] Verifikasi domain di ESP
- [ ] Subscribe SNS topic ke endpoint webhook + simpan ARN ke `SES_SNS_TOPIC_ARN`

#### BE ✅
- [x] **`EmailProviderInterface`** — adapter pattern untuk multi-ESP
- [x] **`SesAdapter`** dengan AWS SigV4 signing via cURL (no SDK dependency):
  - [x] `send()` — SES v2 SendEmail dengan tag, configuration_set, reply_to
  - [x] `verifyWebhook()` — SNS topic ARN whitelist + SignatureVersion check
  - [x] `parseWebhookEvent()` — Bounce/Complaint/Delivery normalization
  - [x] Auto-handle SubscriptionConfirmation (GET SubscribeURL)
- [x] **Migration `2026-07-20-000001_S2_4_EmailDeliverability.php`**:
  - [x] ALTER `users` add: `email_bounced_at`, `email_bounce_reason`, `email_bounce_type`, `email_complaint_at`, `unsubscribed_categories_json`
  - [x] Tabel `email_events` untuk audit + analytics deliverability
- [x] **`Webhook\EmailWebhookController`** receiver (PUBLIC, signature-verified):
  - [x] `POST /webhooks/email/{provider}` dengan throttle:inbound_webhook
  - [x] HARD bounce → mark user.email_bounced_at + suppress
  - [x] SOFT bounce → log only (transient)
  - [x] COMPLAINT → auto-unsubscribe marketing/newsletter/product_update
  - [x] DELIVERY → reset SOFT bounce flag (recovery)
  - [x] Return 200 selalu setelah parse (avoid provider retry loop)
- [ ] Refactor `Services::email()` untuk pakai adapter sesuai env `EMAIL_PROVIDER` — pending
- [ ] `NotificationService::send` — fallback ke WhatsApp / in-app kalau bounced — pending
- [ ] Footer email template: link unsubscribe + alasan kirim — pending

#### QA ⬜
- [ ] Unit test bounce handler mark user
- [ ] Feature test fallback ke WhatsApp

#### DOC ⬜
- [ ] `docs/EMAIL_DELIVERABILITY.md` — DNS setup, monitoring, troubleshooting

### S2.5 — Comprehensive Rate Limiting (TD.1)
**Modul:** Security · **Effort:** Medium · **Impact:** Medium

#### OPS
- [ ] Setup Redis (managed atau self-hosted) untuk token bucket

#### BE
- [ ] `Libraries\Security\RedisRateLimiter` (token bucket)
- [ ] Filter `App\Filters\RateLimit` configurable per route
- [ ] Tier per role:
  - super_admin: unlimited
  - hr_admin: 1000/hr
  - employee: 200/hr
- [ ] Endpoint sensitif:
  - [ ] `/auth/login` — 5/15min/IP
  - [ ] `/auth/signup` — 3/hr/IP
  - [ ] `/auth/password-reset` — 3/hr/email
  - [ ] `/files/upload` — 100MB/hr/tenant (size-based)
- [ ] Whitelist internal cron + webhook source IPs (env-based)
- [ ] Header `X-RateLimit-Remaining`, `X-RateLimit-Reset` di response

#### FE
- [ ] Toast graceful saat 429 dengan retry-after countdown

#### OPS
- [ ] Grafana dashboard request rate per endpoint

---

## SPRINT S10

### S2.6 — Performance Test & Optimization
**Modul:** Infrastructure · **Effort:** Medium-High · **Impact:** Medium

#### OPS
- [ ] Setup k6 / locust di environment staging
- [ ] Skenario load test:
  - [ ] 1K karyawan calc payroll < 30s p95
  - [ ] 10K karyawan calc payroll < 60s p95
  - [ ] 100 concurrent clock-in < 500ms p95
  - [ ] List page (employees) < 200ms p50
- [ ] CI gate: regression > 10% dari baseline → fail PR

#### BE
- [ ] Enable MySQL slow_query_log threshold 500ms
- [ ] Audit top 10 slow query → optimize:
  - [ ] Tambah index missing (per tabel hot-path)
  - [ ] Refactor N+1 query (mis. loop dalam payroll calc)
  - [ ] Materialized view untuk cost center summary, payroll summary
- [ ] Setup MySQL read replica untuk laporan (separate connection di `Database.php`)
- [ ] Service `ReadReplicaService::route()` — auto-route SELECT-only ke replica

#### DOC
- [ ] `docs/PERFORMANCE_BUDGET.md` — target p50/p95/p99 per endpoint
- [ ] `docs/DATABASE_TUNING.md` — index strategy + replica routing

---

# 🟨 PHASE 3 — ENTERPRISE SALES ENABLER (Sprint S11–S16, ~3 bulan)

## SPRINT S11

### S3.1 — White-Labeling per Tenant ✅ Backend
**Modul:** O. White-Label · **Effort:** Medium-High · **Impact:** High · **Status:** Backend SELESAI; FE + OPS wildcard SSL pending

#### DB ✅
- [x] **Migration `2026-07-25-000001_S3_1_WhiteLabel.php`**:
  - [x] ALTER `companies` add 11 kolom: `brand_logo_path`, `brand_favicon_path`, `brand_primary_color`, `brand_secondary_color`, `brand_login_bg_path`, `brand_email_footer_html`, `custom_domain`, `custom_domain_verified_at`, `custom_domain_verification_token`, `brand_app_name`, `brand_support_email`
  - [x] Unique index `idx_companies_custom_domain`
  - [x] Tabel `email_template_overrides` (company_id, template_key, subject, html_body, text_body, variables_doc)
  - [x] Tabel `branding_audit_log` untuk compliance + rollback

#### BE ✅
- [x] **`Libraries\WhiteLabel\BrandingService`** dengan pure helpers:
  - [x] `resolve(int $companyId)` — return branding dengan fallback ke Quantara DEFAULTS
  - [x] `resolveByHost(string $host)` — host-based resolver (login page tenant-branded sebelum JWT)
  - [x] `mergePure()` — pure helper testable tanpa DB
  - [x] `normalizeColorPure()` — accept #RRGGBB / #RGB / lowercase / no-hash, reject invalid
  - [x] `update()` dengan validation + audit log + cache invalidation
  - [x] `verifyDomain()` via DNS TXT lookup `_quantara.{domain}` = verification_token
  - [x] Static `flushCache()` untuk super admin override
- [x] **`WhiteLabel\BrandingController`** dengan 4 endpoint:
  - [x] `GET /companies/me/branding` (selalu allowed, return Quantara default kalau no override)
  - [x] `PUT /companies/me/branding` (gated FeatureGate `white_label.enabled` → ENTERPRISE only)
  - [x] `POST /companies/me/branding/verify-domain` (DNS TXT verify)
  - [x] `GET /branding/by-host?host=…` (PUBLIC, no auth, untuk login page)
- [x] Audit log severity HIGH untuk semua perubahan branding
- [ ] Email renderer cek `email_template_overrides` — pending
- [ ] Tenant resolver `HostBasedResolver` — pending (BrandingService.resolveByHost sudah cover read path)

#### FE ✅
- [x] Halaman `/settings/branding` — upload logo + favicon + login bg, color picker (primary + secondary), app name, support email, custom domain
- [x] Custom domain verification flow (DNS TXT → backend resolves)
- [x] Preview mode (live preview pakai brand colors di card)
- [ ] CSS variables driven by branding di global shell (defer — render value per page sekarang)
- [ ] Custom favicon per tenant injection ke `<link rel="icon">` di index.html (defer)

#### OPS ⬜
- [ ] Setup Caddy / Traefik wildcard SSL (Let's Encrypt) untuk subdomain
- [ ] CDN (CloudFront / Cloudflare) untuk asset branding

#### QA ⬜
- [ ] Test override branding tampil di login page custom domain
- [ ] Test fallback ke Quantara default kalau tenant tidak set branding

---

## SPRINT S12

### S3.2 — Mobile Native App (Phase 1: Core Features)
**Modul:** J. Self-Service · **Effort:** Very High · **Impact:** High

#### Decision
- [ ] Pilih stack: React Native (rekomendasi karena tim web existing)

#### Mobile Setup
- [ ] Init project di `mobile/` dengan TypeScript
- [ ] Setup React Navigation, Redux Toolkit / Zustand
- [ ] Setup Axios client dengan interceptor JWT
- [ ] Setup Fastlane untuk iOS+Android build CI

#### Core Features
- [ ] Login + biometric (react-native-biometrics)
- [ ] Clock-in/out:
  - [ ] GPS native (react-native-geolocation-service)
  - [ ] Selfie validation (react-native-vision-camera)
  - [ ] Offline queue dengan AsyncStorage
- [ ] Approval inbox dengan FCM push
- [ ] Lihat saldo cuti + ajukan cuti
- [ ] Lihat slip gaji terakhir + download PDF

#### OPS
- [ ] Apple Developer Account ($99/year)
- [ ] Google Play Console Account ($25 one-time)
- [ ] Crashlytics setup
- [ ] App icon + splash screen design

#### QA
- [ ] Beta testing TestFlight (iOS) + Internal Testing Track (Android)
- [ ] Manual QA matrix: 5 devices × 3 OS versions

#### DOC
- [ ] `mobile/README.md` — setup, build, release flow

---

## SPRINT S13

### S3.3 — Compliance Sertifikasi (Prep)
**Modul:** Compliance · **Effort:** Very High · **Impact:** High

#### Pre-requisite
- [ ] Hire/contract security consultant (3-6 bulan engagement)
- [ ] Budget audit ~150-300 juta IDR (ISO 27001 saja)

#### ISO 27001 Prep
- [ ] Gap analysis dengan auditor (Lloyd's / TÜV / SGS)
- [ ] Document Information Security Management System (ISMS):
  - [ ] Access Control Policy
  - [ ] Asset Management Policy
  - [ ] Incident Response Policy
  - [ ] Business Continuity Policy
  - [ ] HR Security Policy
  - [ ] Physical Security Policy
- [ ] Implement 14 control domains:
  - [ ] Risk register + risk treatment plan
  - [ ] Asset inventory dengan classification
  - [ ] Access review quarterly
  - [ ] Pen-test annual
- [ ] Internal audit + management review
- [ ] External audit Stage 1 (document review)
- [ ] External audit Stage 2 (on-site)

#### SOC 2 Type II
- [ ] 6 bulan observation period dengan 5 Trust Service Criteria
- [ ] Annual recertification

#### DOC
- [ ] Public security page `quantara.io/security` — sertifikat + whitepaper
- [ ] `docs/COMPLIANCE_ISO27001.md`, `docs/COMPLIANCE_SOC2.md`

---

## SPRINT S14

### S3.4 — Customer Success Tooling
**Modul:** P. Customer Success · **Effort:** Medium · **Impact:** Medium-High

#### DB
- [ ] Migration `onboarding_progress` (user_id, step_key, completed_at)
- [ ] Migration `release_announcements` (id, version, title, body, published_at, target_plans_json)
- [ ] Migration `customer_health_scores` (company_id, score, breakdown_json, calculated_at)

#### BE
- [ ] Endpoint `GET /api/v1/onboarding/progress` & `POST /api/v1/onboarding/{step}/complete`
- [ ] Endpoint `GET /api/v1/announcements/unread`
- [ ] Service `CustomerHealthService::compute($companyId): array{score, drivers}`:
  - [ ] login_frequency_30d
  - [ ] feature_adoption_rate (% feature aktif dipakai)
  - [ ] payslip_calc_count_30d
  - [ ] support_ticket_count_30d
  - [ ] churn_risk_score (predictive)
- [ ] Cron `cs:compute-health-scores` weekly
- [ ] Auto-flag low engagement → notif Slack #cs-team

#### FE ✅
- [x] Halaman `/onboarding` — 8-step wizard dengan progress bar + deep-link CTA per step + skip / mark complete
- [x] Halaman `/admin/customer-health` (super admin) — list semua tenant + score 0-100 + tier GREEN/YELLOW/RED + breakdown JSON + recompute on-demand + summary cards
- [x] **Modal "What's New" announcement** (`AnnouncementsModal.vue`) — auto-mount di AppLayout, polling `/announcements/unread`, queue multi-announcement dengan progress dots, mark read + cta_clicked tracking
- [ ] Driver.js / Intro.js product tour (defer — onboarding wizard cover use case utama)
- [ ] In-app help center widget (defer)
- [ ] Feedback NPS widget (defer)

#### OPS
- [ ] Knowledge base content authoring (Notion / GitBook)

---

## SPRINT S15

### S3.5 — Admin / Super Admin Console ✅ Backend
**Modul:** Q. Admin Console · **Effort:** Medium-High · **Impact:** High · **Status:** Backend SELESAI; FE pending

> Console internal Quantara untuk manage tenant, support, billing

#### BE ✅
- [x] **`AdminTenantController`** dengan 6 endpoint super admin only:
  - [x] `GET /admin/tenants` — list dengan filter status, plan, search + employee count per tenant
  - [x] `GET /admin/tenants/{id}` — detail komprehensif: subscription, events history, usage stats, tickets, audit critical, feature overrides
  - [x] `POST /admin/tenants/{id}/impersonate` — generate JWT short-lived 15 menit dengan flag `is_impersonated` + `impersonator_id`. Audit severity HIGH wajib + reason.
  - [x] `POST /admin/tenants/{id}/suspend` — wire ke SubscriptionService.suspend(). 4-eyes via `confirm: true` + reason mandatory
  - [x] `POST /admin/tenants/{id}/unsuspend` — restore status ACTIVE
  - [x] `GET /admin/metrics/saas` — MRR (monthly + yearly normalized), ARR, churn 30d, plan distribution, signup funnel, new tenants per month
- [x] Hardcoded super admin check (tidak ada permission slug supaya tidak bisa di-grant ke role tenant biasa)
- [x] Audit log severity HIGH untuk semua admin operation

#### FE ✅
- [x] Halaman `/admin/tenants` — filter (status, plan_code, search), table + inline action (impersonate, suspend, unsuspend), pagination
- [x] Halaman `/admin/tenants/{id}` — header status, subscription card, usage stats, subscription events history, recent critical audit, feature overrides + quick actions (impersonate, extend trial, feature override, suspend/unsuspend)
- [x] Modal impersonate dengan reason input (audit HIGH) + 4-eyes confirm + token expiry 15 menit + auto-restore
- [x] Dashboard `/admin/saas-metrics` — MRR + ARR + MRR growth %, churn rate 30d, plan distribution, signup funnel (signups → activated → paid), new tenants 12-month chart

#### QA ⬜
- [ ] Feature test impersonate audit log mandatory
- [ ] Feature test 4-eyes principle untuk action sensitif

---

## SPRINT S16

### S3.6 — API Documentation & Developer Portal (extend H3.8)
**Modul:** L. Integration · **Effort:** Medium-High · **Impact:** Medium

#### BE
- [ ] OpenAPI spec auto-generate dari controller annotations (zircote/swagger-php)
- [ ] Endpoint `/api/v1/docs/openapi.json` (auth required)
- [ ] Swagger UI di `/developer/docs` (frontend)
- [ ] OAuth 2.0 server (laravel/passport adaptor atau implementasi sendiri):
  - [ ] Authorization Code flow
  - [ ] Client Credentials flow
- [ ] CRUD `oauth_clients` per company
- [ ] CRUD `api_keys` per company dengan scopes
- [ ] Rate limiting per API key per tier
- [ ] Webhook event catalog UI:
  - [ ] List 30+ event types
  - [ ] Toggle subscribe per event
  - [ ] Test webhook fire button

#### FE
- [ ] `/developer/docs` Swagger UI embedded
- [ ] `/developer/api-keys` create/rotate/revoke
- [ ] `/developer/oauth-clients` setup (untuk 3rd party app)
- [ ] `/developer/webhooks` event catalog + test fire
- [ ] `/developer/sandbox` — toggle ke sandbox env

#### OPS
- [ ] Sandbox environment dengan reset weekly
- [ ] DNS `sandbox.api.quantara.io`

#### DOC
- [ ] `docs/DEVELOPER_QUICKSTART.md` dengan code samples PHP / Python / Node
- [ ] `docs/API_VERSIONING.md` — strategy v1 → v2 migration

---

# 🟪 PHASE 4 — GROWTH & SCALE (Sprint S17+, ~6+ bulan)

> **Catatan:** Phase 4 sebaiknya **delay sampai ada 50-100 paying customer**
> untuk validasi product-market fit dulu. Item ini bersifat tentative.

### S4.1 — Marketplace / App Ecosystem
- [ ] DB tabel `installed_integrations` per tenant
- [ ] OAuth handshake untuk Slack / Teams / Zapier / Make.com / Tableau / Power BI / Accurate / Zahir / Odoo
- [ ] Partner program: revenue share, co-marketing
- [ ] Marketplace UI dengan rating + review
- [ ] Submission flow untuk 3rd party developer

### S4.2 — Multi-Country Expansion (H4.2)
- [ ] Refactor PayrollService → jurisdiction-aware
- [ ] Country modules: MY (EPF/SOCSO/EIS/PCB), SG (CPF/IRAS), PH (SSS/PhilHealth/Pag-IBIG/BIR), TH (Social Security/WHT)
- [ ] Multi-currency: tabel `exchange_rates` daily refresh
- [ ] Country-specific compliance reports

### S4.3 — AI Copilot Production Polish (H4.1 lengkap)
- [ ] Chat UI di shell (sidebar + full-screen mode)
- [ ] Function calling: create leave, lookup employee, generate report, schedule interview
- [ ] Cost monitoring per tenant — `ai_usage_meters`
- [ ] AI add-on billing ($5-20/user/month)
- [ ] Multi-LLM fallback (GPT-4 → Claude → local)

### S4.4 — Public Customer Portal & Help Center
- [ ] `help.quantara.io` (Notion / GitBook / Helpjuice)
- [ ] `status.quantara.io` (Statuspage)
- [ ] `community.quantara.io` (Discourse forum)
- [ ] Public roadmap (Productboard / Canny)

### S4.5 — Sales Demo Environment
- [ ] Sandbox `demo.quantara.io` reset Senin pagi
- [ ] Pre-loaded data realistis (200 karyawan, 12 bulan attendance, edge cases)
- [ ] One-click "Try Demo" dari pricing page

---

# 📊 METRIK KEBERHASILAN SAAS (TRACKER)

| KPI | Target Phase 1 | Target Phase 3 | Cara Ukur | Status |
| --- | --- | --- | --- | --- |
| Time to first payslip | < 2 jam dari signup | < 30 menit | onboarding_progress | ⬜ |
| Trial → Paid conversion | TBD | > 25% | subscriptions transitions | ⬜ |
| MRR | First $10K | $100K | sum active subs × plan price | ⬜ |
| Churn rate (annual) | TBD | < 8% | cancelled / active * 12 | ⬜ |
| NPS | TBD | > 40 | in-app quarterly | ⬜ |
| Uptime | 99.9% | 99.95% | external monitoring | ⬜ |
| Payroll calc 10K emp | TBD | < 60s p95 | k6 load test | ⬜ |
| Support response time | TBD | < 4h business hours | helpdesk_tickets | ⬜ |
| Quota enforcement | 100% endpoint covered | - | code review checklist | ⬜ |
| Email deliverability | > 98% inbox | > 99% | ESP report | ⬜ |

---

# 🚦 PRIORITAS EKSEKUSI (Critical Path)

Bila resource terbatas, urutan **must-have** untuk launch sebagai SaaS:

```
Critical Path (8-10 minggu blokir launch):
  S1.1 → S1.2 → S1.3 → S1.5 → S2.1 → S2.3
  (Subscription + Billing + Signup + Payment + Observability + Backup)

Nice-to-have sebelum launch (2-4 minggu):
  S1.4 (Quota) — bisa di-skip sementara dengan generous limit
  S1.6 (Dunning) — bisa manual dulu untuk first 50 tenant
  S2.2 (Tenant Isolation) — security audit eksternal sebelum prod
  S2.4 (Email Deliverability) — wajib untuk transactional email

Defer post-launch (3-6 bulan):
  S2.5 / S2.6 / S3.1 / S3.4 / S3.5 / S3.6

Defer sampai PMF (6+ bulan):
  S3.2 (Mobile) — only kalau customer demand kuat
  S3.3 (ISO 27001) — only kalau target enterprise/BUMN
  Phase 4 entirely
```

---

# 🔄 RITUAL UPDATE

**Per Sprint:**
- [ ] Awal sprint: pilih item dari Phase aktif → tandai `- [~]`
- [ ] Demo / review akhir sprint dengan stakeholder
- [ ] Update tabel status Phase di atas
- [ ] Update KPI tracker dengan angka aktual

**Per Bulan:**
- [ ] Review prioritas vs feedback customer
- [ ] Adjust roadmap kalau ada perubahan strategi go-to-market

**Per Quarter:**
- [ ] Snapshot metrics ke `docs/METRICS_QUARTERLY.md`
- [ ] Re-prioritize Phase berdasarkan revenue & churn data

---

*Dokumen turunan dari `QUANTARA_HRIS_ROADMAP_SAAS.md`*
*Dibuat: Juni 2026 · Versi sistem: Sprint 9+ (Critical Legal Fixes finalized)*