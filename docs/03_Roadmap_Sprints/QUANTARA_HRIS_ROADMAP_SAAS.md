# Quantara HRIS — Roadmap Transformasi SaaS

> Dokumen ini melengkapi `QUANTARA_HRIS_ROADMAP_PENGEMBANGAN.md` dengan
> fokus khusus pada **transformasi dari "internal HRIS multi-tenant"
> menjadi "SaaS commercial-grade siap dijual ke ratusan/ribuan tenant"**.
>
> Sementara dokumen `PENGEMBANGAN.md` fokus pada **kapabilitas HR**,
> dokumen ini fokus pada **kapabilitas SaaS**: subscription/billing,
> tenant onboarding, observability, white-label, dan compliance.

---

## Ringkasan Eksekutif

Quantara HRIS sudah punya **fondasi produk yang sangat solid**:
- 88 migrations, modul A-M lengkap
- Multi-tenant secara teknis (`company_id` di seluruh schema)
- Kepatuhan regulasi Indonesia (PP 35, PP 36, PMK 66, UU 13, UU KIA, UU PDP)
- 607+ tests, fondasi modular & dokumen end-to-end

Tapi sebagai SaaS komersial, ada **gap signifikan** di:
1. **Bisnis layer** — subscription, billing, signup, provisioning
2. **Operational layer** — observability, backup, performance proof
3. **Enterprise readiness** — white-label, mobile native, sertifikasi

Roadmap ini dibagi menjadi **4 Phase** dengan total estimasi **8-14 bulan**:

| Phase | Fokus | Durasi |
| --- | --- | --- |
| Phase 1 — SaaS Foundation | Subscription, billing, signup, provisioning, quota | Sprint S1–S6 (3 bulan) |
| Phase 2 — Production Hardening | Observability, isolation, deliverability, perf | Sprint S7–S10 (2 bulan) |
| Phase 3 — Enterprise Sales | White-label, mobile, sertifikasi, customer success | Sprint S11–S16 (3 bulan) |
| Phase 4 — Growth & Scale | Marketplace, multi-country, AI polish, public site | Sprint S17+ (6+ bulan) |

> **Catatan urutan:** Roadmap ini bisa di-overlap sebagian dengan
> Horizon 1–4 di `PENGEMBANGAN.md`. Item di sini punya prefix `S{n}.`
> (S = SaaS) supaya tidak bentrok dengan H1–H4.

---

## 🟦 PHASE 1 — SaaS FOUNDATION (Sprint S1–S6, ~3 bulan)

> **Mission:** Tenant baru bisa daftar sendiri, bayar, dan langsung pakai
> tanpa intervensi manual. Semua fitur sudah berjalan tapi gated ke plan.

### S1.1 — Subscription Plans & Feature Flags

**Modul Baru:** N. Billing & Subscription
**Effort:** Medium-High · **Impact:** Critical (blocker untuk monetize)

#### Database
- [ ] Tabel `subscription_plans` (code, name, price_monthly, price_yearly, currency, max_employees, max_storage_gb, max_api_calls_month, is_active, sort_order)
- [ ] Tabel `plan_features` (plan_id, feature_key, is_included, quota_value)
- [ ] Tabel `feature_flags` (key, default_enabled, description, category)
- [ ] Tabel `company_feature_overrides` (company_id, feature_key, is_enabled, expires_at)
- [ ] Seed: 4 plan tiers (FREE_TRIAL, STARTER, PRO, ENTERPRISE) dengan feature matrix

#### Backend
- [ ] `Libraries\Billing\FeatureGateService` dengan method `enabled(string $key, ?int $companyId): bool`
- [ ] Filter/Middleware `App\Filters\FeatureGate` untuk gate route per feature key
- [ ] Endpoint `GET /api/v1/features/me` — list feature aktif untuk current tenant
- [ ] Endpoint `GET /api/v1/plans` (publik) — list plan untuk pricing page
- [ ] Endpoint `GET /api/v1/billing/quota-usage` — current usage vs limit per quota dimension

#### Frontend
- [ ] Composable `useFeature(key)` di Vue — auto-hide menu/component bila feature off
- [ ] Halaman `/billing/plan` — tampilkan plan aktif + comparison
- [ ] Banner upgrade kalau feature di-akses tapi tidak include di plan

#### Acceptance
- [ ] Tenant di plan STARTER tidak bisa akses fitur AI Copilot (UI hide + backend 403 `FEATURE_NOT_AVAILABLE`)
- [ ] Super admin bisa override feature flag per tenant via `company_feature_overrides` (mis. trial fitur baru)

---

### S1.2 — Subscription Lifecycle Management

**Modul Baru:** N. Billing & Subscription
**Effort:** High · **Impact:** Critical

#### Database
- [ ] Tabel `subscriptions` (company_id, plan_id, status [TRIAL|ACTIVE|PAST_DUE|CANCELLED|EXPIRED], billing_cycle [MONTHLY|YEARLY], started_at, current_period_start, current_period_end, trial_ends_at, cancelled_at, cancel_reason)
- [ ] Tabel `subscription_events` (subscription_id, event_type [CREATED|UPGRADED|DOWNGRADED|RENEWED|CANCELLED|REACTIVATED], from_plan_id, to_plan_id, metadata_json, created_at)

#### Backend
- [ ] Service `SubscriptionService` dengan methods:
  - [ ] `createTrial(companyId, planCode)` — start 14-day trial
  - [ ] `upgrade(companyId, newPlanCode)` — proration kalkulasi otomatis
  - [ ] `downgrade(companyId, newPlanCode)` — efektif di period berikutnya (no proration credit)
  - [ ] `cancel(companyId, reason)` — keep access sampai end of period
  - [ ] `renew(subscriptionId)` — dipanggil cron/webhook saat pembayaran sukses
  - [ ] `expire(subscriptionId)` — downgrade ke FREE_TRIAL atau hard-block bila trial habis
- [ ] Cron `php spark billing:check-renewals` — daily, mark PAST_DUE bila pembayaran lewat 3 hari
- [ ] Cron `php spark billing:trial-expiry-notify` — notify H-3, H-1 sebelum trial habis

#### Frontend
- [ ] Halaman `/billing` — current subscription, usage, upgrade button
- [ ] Modal "Upgrade Plan" dengan proration preview
- [ ] Modal "Cancel Subscription" dengan reason dropdown + retention offer

---

### S1.3 — Self-Service Tenant Signup

**Modul Baru:** N. Billing
**Effort:** Medium-High · **Impact:** Critical (blocker untuk scale)

#### Database
- [ ] Tabel `signup_attempts` (email, company_name, ip_address, user_agent, status [PENDING_VERIFICATION|VERIFIED|EXPIRED|ABANDONED], verification_token, verified_at, created_at)
- [ ] Tambah kolom `companies.trial_ends_at`, `companies.signup_source` (DIRECT|REFERRAL|MARKETING_CAMPAIGN_X)

#### Backend
- [ ] Endpoint publik `POST /api/v1/auth/signup` (no auth):
  - [ ] Body: `email`, `password`, `company_name`, `npwp_optional`, `phone`, `referral_code_optional`
  - [ ] Rate limit 3/hour/IP
  - [ ] Validasi NPWP format (jika diisi), email belum terdaftar di sistem
  - [ ] Insert ke `signup_attempts` + kirim email verifikasi
- [ ] Endpoint `GET /api/v1/auth/verify-signup?token=` — verifikasi + provisioning otomatis
- [ ] Service `TenantProvisioningService::provision($signupId)`:
  - [ ] Create company row dengan default settings (timezone Asia/Jakarta, currency IDR)
  - [ ] Create admin user + assign role super_company_admin
  - [ ] Seed default master: leave_types (12 tipe), payroll_components (10 komponen), BPJS rates (latest), PTKP (latest), holidays (Indonesia tahun berjalan)
  - [ ] Create FREE_TRIAL subscription (14 hari)
  - [ ] Send welcome email dengan onboarding wizard link

#### Frontend
- [ ] Landing page `/signup` — form signup dengan estimasi pricing
- [ ] Email template "Verifikasi Email" + "Welcome — Trial Started"
- [ ] Halaman `/onboarding-wizard` 5 langkah:
  1. Upload company logo
  2. Set timezone & working hours
  3. Invite first 3 employees
  4. Create first payroll period
  5. Run first payslip (sample data) → success animation

#### Acceptance
- [ ] Signup → email verifikasi → provisioning < 30 detik end-to-end
- [ ] User pertama di tenant baru dapat role super_company_admin tanpa intervensi manual
- [ ] Failed provisioning → rollback transaction + retry-able dari signup_attempts

---

### S1.4 — Quota Enforcement & Usage Metering

**Modul:** N. Billing
**Effort:** Medium · **Impact:** High

#### Database
- [ ] Tabel `usage_meters` (company_id, meter_key [employees|storage_mb|api_calls|payslips_generated|sms_sent|whatsapp_sent], current_value, period_start, period_end, last_reset_at)
- [ ] Tabel `usage_events` (company_id, meter_key, delta, source, occurred_at) — untuk audit trail

#### Backend
- [ ] Library `UsageMeterService::increment($companyId, $key, $delta, $context)` — update both meters & events
- [ ] Library `QuotaEnforcer::check($companyId, $key, $required)` — throw `QuotaExceededException` bila over limit
- [ ] Wire ke endpoint kritis:
  - [ ] `EmployeeController::create` → `QuotaEnforcer::check($cid, 'employees', 1)`
  - [ ] File upload → `QuotaEnforcer::check($cid, 'storage_mb', $size_mb)`
  - [ ] API call middleware → `QuotaEnforcer::check($cid, 'api_calls', 1)` + reset bulanan
  - [ ] WhatsApp send → `QuotaEnforcer::check($cid, 'whatsapp_sent', 1)`
- [ ] Cron `php spark billing:reset-monthly-meters` — reset api_calls & whatsapp_sent setiap awal billing cycle

#### Frontend
- [ ] Banner usage di top-right header: "47/50 employees (94%)"
- [ ] Halaman `/billing/usage` dengan chart per meter trend 6 bulan terakhir
- [ ] Pop-up "Anda hampir mencapai limit, upgrade now?" saat usage > 90%

---

### S1.5 — Payment Gateway Integration

**Modul:** N. Billing
**Effort:** High · **Impact:** Critical

#### Pre-requisite
- [ ] Pilih provider: Midtrans (paling umum di ID, support cards/VA/e-wallet) ATAU Xendit
- [ ] Dapatkan kontrak vendor + sandbox credentials

#### Database
- [ ] Tabel `invoices` (subscription_id, invoice_number, amount, tax_amount, total, currency, status [DRAFT|OPEN|PAID|VOID|REFUNDED], issue_date, due_date, paid_at, pdf_path)
- [ ] Tabel `invoice_items` (invoice_id, description, quantity, unit_price, amount)
- [ ] Tabel `payments` (invoice_id, gateway, gateway_txn_id, method [CREDIT_CARD|VA|EWALLET|BANK_TRANSFER], amount, status [PENDING|SUCCESS|FAILED], paid_at, raw_response_json)
- [ ] Tabel `payment_methods` (company_id, gateway, token, last_four, brand, expires_at, is_default) — untuk recurring

#### Backend
- [ ] Adapter `Libraries\Billing\PaymentGateways\MidtransAdapter` (atau Xendit)
  - [ ] `createCharge($invoice)` → return checkout URL
  - [ ] `verifyWebhook($payload, $signature)` → boolean
  - [ ] `processWebhook($payload)` → update invoice + payment + trigger renewal
- [ ] Endpoint `POST /api/v1/billing/checkout/{invoice_id}` — generate payment URL
- [ ] Endpoint webhook publik `POST /api/v1/webhooks/payment/{gateway}` (no auth, signature verified)
- [ ] Service `InvoiceService::generateForRenewal($subscription)` — create invoice + email PDF
- [ ] Cron `php spark billing:generate-invoices` — daily, untuk subscription yang renewal date hari ini

#### Frontend
- [ ] Halaman `/billing/invoices` list semua invoice + status + download PDF
- [ ] Halaman checkout (redirect ke gateway) + return page handler

#### Compliance
- [ ] PCI DSS: jangan simpan card number — pakai tokenization gateway
- [ ] Pajak: hitung PPN 11% otomatis di invoice (untuk customer Indonesia)
- [ ] Faktur Pajak (e-Faktur) integration kalau target customer ber-NPWP

---

### S1.6 — Dunning & Churn Management

**Modul:** N. Billing
**Effort:** Medium · **Impact:** Medium-High

#### Backend
- [ ] Cron `php spark billing:dunning` — daily, kirim reminder progressive:
  - H+1 setelah due date: email reminder ramah
  - H+3: email + WhatsApp warning, status PAST_DUE
  - H+7: email "Akun akan di-suspend H+10"
  - H+10: suspend access (read-only mode), notify admin
  - H+30: hard-cancel + offer reactivate dengan diskon
- [ ] Tabel `dunning_actions` (subscription_id, action_type, sent_at)
- [ ] Endpoint `POST /api/v1/billing/reactivate` — admin restart subscription dengan pembayaran lunasi

#### Frontend
- [ ] Banner global "Pembayaran tertunggak — bayar sekarang" untuk admin tenant PAST_DUE
- [ ] Read-only mode: semua action button disabled, hanya view + download

---

## 🟧 PHASE 2 — PRODUCTION HARDENING (Sprint S7–S10, ~2 bulan)

### S2.1 — Observability Stack

**Modul:** Infrastructure
**Effort:** Medium-High · **Impact:** High

#### Application Performance Monitoring
- [ ] Pilih APM: Sentry (paling murah untuk start), upgrade ke Datadog/NewRelic kalau scale
- [ ] Integrasi error tracking + release tracking + performance traces
- [ ] Setup alert: error rate > 1% / 5min → Slack

#### Structured Logging
- [ ] Refactor `log_message()` → JSON format dengan trace_id, company_id, user_id
- [ ] Ship logs ke CloudWatch / ELK / Loki
- [ ] Log retention 90 hari (production), 30 hari (staging)

#### Health & Metrics Endpoints
- [ ] `GET /health` — single endpoint cek DB + Redis + queue + S3 (return 200 / 503)
- [ ] `GET /health/deep` — detail per dependency (untuk admin panel)
- [ ] `GET /metrics` — Prometheus format dengan custom metrics:
  - `payslips_calculated_total{company_id}`
  - `attendance_clockin_duration_seconds`
  - `queue_depth{queue_name}`
  - `subscription_active_count{plan}`

#### Uptime Monitoring
- [ ] External monitor (Uptimerobot / Pingdom) ping `/health` setiap 1 menit
- [ ] SLO target: 99.9% uptime per quarter (4.3 menit downtime allowed)

---

### S2.2 — Multi-Tenant Isolation Hardening

**Modul:** Security
**Effort:** Medium-High · **Impact:** High (Security Risk)

- [ ] Buat `App\Models\BaseModel` dengan global scope `withCompany()`
- [ ] Migrasikan semua model existing ke base ini (extend dari `BaseModel`)
- [ ] Audit script `php spark security:scan-tenant-leaks`:
  - Grep semua `->table('xxx')->...` tanpa `applyScope` → laporkan
  - Grep semua endpoint yang bypass tenant filter → laporkan
- [ ] Add automated test "cross-tenant access denied" template untuk setiap controller baru
- [ ] Optional: PostgreSQL Row Level Security policies (jika migrate dari MySQL)

---

### S2.3 — Backup & Disaster Recovery

**Modul:** Infrastructure
**Effort:** Medium · **Impact:** Critical

#### Backup Otomatis
- [ ] Cron `db:backup-encrypted` — nightly:
  - mysqldump dengan compression + GPG encryption
  - Upload ke S3 dengan SSE-KMS
  - Retention: daily 30 hari, weekly 12 minggu, monthly 12 bulan
- [ ] Tabel `backup_runs` log success/failure + size + duration
- [ ] Verify backup: test restore weekly ke staging environment

#### Per-Tenant Export (UU PDP Right to Portability)
- [ ] Endpoint `POST /api/v1/companies/me/export-data` — async job
- [ ] Generate ZIP berisi: CSV semua tabel terkait company_id + dokumen file storage
- [ ] Email link download + auto-expire 7 hari
- [ ] Audit log severity NOTICE

#### Runbook
- [ ] `docs/RUNBOOK_RESTORE.md` — step-by-step restore dari backup
- [ ] `docs/RUNBOOK_INCIDENT.md` — incident response (severity classification, on-call rotation)
- [ ] `docs/DR_PLAN.md` — RPO 24 jam, RTO 4 jam, scenario tests quarterly

---

### S2.4 — Email & Notification Deliverability

**Modul:** J. Notification
**Effort:** Medium · **Impact:** Medium-High

- [ ] Migrate dari SMTP sederhana → Postmark / Sendgrid / AWS SES
- [ ] Setup SPF, DKIM, DMARC records — dokumentasikan di `docs/EMAIL_DELIVERABILITY.md`
- [ ] Bounce handling:
  - Webhook dari ESP → mark email invalid di tabel `users.email_bounced_at`
  - Auto-fallback ke WhatsApp (jika opt-in) atau in-app notification
- [ ] Unsubscribe link wajib di footer notif non-transactional (regulatory compliance)
- [ ] Email template testing tools (Litmus / Email on Acid) untuk brand-critical email

---

### S2.5 — Comprehensive Rate Limiting (TD.1)

**Modul:** Security
**Effort:** Medium · **Impact:** Medium

- [ ] Migrate dari MySQL counter ke Redis token bucket
- [ ] Tier per role: super_admin unlimited, hr_admin 1000/hr, employee 200/hr
- [ ] Endpoint sensitif:
  - `/auth/login` 5/15min/IP
  - `/auth/signup` 3/hour/IP
  - `/auth/password-reset` 3/hour/email
  - `/files/upload` 100MB/hour/tenant
- [ ] Whitelist internal cron + webhook source IPs
- [ ] Dashboard request rate per endpoint (Grafana)

---

### S2.6 — Performance Test & Optimization (TD.3 + TD.4)

**Modul:** Infrastructure
**Effort:** Medium-High · **Impact:** Medium

- [ ] Setup k6 / locust dengan skenario:
  - 1K karyawan calculate payroll < 30 detik p95
  - 10K karyawan calculate payroll < 60 detik p95
  - 100 concurrent clock-in < 500ms p95
  - List page (employees, payslips) < 200ms p50
- [ ] Slow query audit (TD.3):
  - Enable MySQL slow_query_log threshold 500ms
  - Top 10 query terberat → optimize (index / refactor / materialized view)
- [ ] Setup read replica MySQL untuk laporan
- [ ] Materialized view untuk cost center summary, payroll summary

---

## 🟨 PHASE 3 — ENTERPRISE SALES ENABLER (Sprint S11–S16, ~3 bulan)

### S3.1 — White-Labeling per Tenant

**Modul Baru:** O. White-Label
**Effort:** Medium-High · **Impact:** High (untuk enterprise)

#### Database
- [ ] Tambah kolom `companies.brand_logo_path`, `companies.brand_primary_color`, `companies.brand_login_bg_path`, `companies.custom_domain`
- [ ] Tabel `email_template_overrides` (company_id, template_key, subject, html_body)

#### Backend
- [ ] CDN-served custom assets per tenant
- [ ] Custom domain: Let's Encrypt automation via Caddy / Traefik wildcard
- [ ] Email template renderer cek `email_template_overrides` dulu → fallback default
- [ ] Setup tenant resolution by Host header: `hr.acme.co.id` → tenant ACME

#### Frontend
- [ ] Halaman `/settings/branding` (admin tenant) untuk upload logo + pick color + preview
- [ ] CSS variables driven by company.brand_primary_color
- [ ] Custom favicon per tenant

---

### S3.2 — Mobile Native App (H2.1 Fase 2 lengkap)

**Modul:** J. Self-Service
**Effort:** Very High · **Impact:** High (competitive parity)

- [ ] Pilih stack: React Native (lebih cepat dengan tim web existing) vs Flutter
- [ ] Core features Phase 1 (3 bulan):
  - Login + biometric (Face ID / fingerprint)
  - Clock-in/out dengan GPS native + selfie validation
  - Approval inbox dengan push notification
  - Lihat saldo cuti + ajukan cuti
  - Lihat slip gaji terakhir + download PDF
- [ ] CI/CD: Fastlane untuk build iOS+Android otomatis dari main branch
- [ ] Crashlytics untuk error tracking
- [ ] Beta testing via TestFlight + Internal Testing Track
- [ ] Public release di App Store + Play Store

---

### S3.3 — Compliance Sertifikasi

**Modul:** Compliance
**Effort:** Very High · **Impact:** High (gate untuk enterprise/BUMN)

- [ ] **ISO 27001** (Information Security Management):
  - Gap analysis dengan auditor (Lloyd's / TÜV / SGS)
  - Implement 14 control domains (access control, asset management, dll)
  - Internal audit + management review
  - External audit (Stage 1 + Stage 2)
  - Estimasi 9-12 bulan, biaya ~150-300 juta IDR untuk audit
- [ ] **SOC 2 Type II** (untuk customer fintech / BUMN):
  - 5 Trust Service Criteria: Security, Availability, Processing Integrity, Confidentiality, Privacy
  - 6 bulan observation period sebelum bisa di-audit
  - Annual recertification
- [ ] **ISO 27701** (Privacy / UU PDP) — extension ISO 27001
- [ ] Public security page `quantara.io/security` — sertifikat + whitepaper

---

### S3.4 — Customer Success Tooling

**Modul Baru:** P. Customer Success
**Effort:** Medium · **Impact:** Medium-High

#### In-App Onboarding
- [ ] Driver.js / Intro.js untuk product tour saat first login
- [ ] Track completion event ke `usage_events`
- [ ] Tabel `onboarding_progress` per user

#### Feature Discovery
- [ ] Modal "What's New" saat ada release (filter belum dilihat per user)
- [ ] In-app help center widget (search articles, video tutorial)
- [ ] Feedback widget (NPS in-app, screen reader friendly)

#### CS Dashboard (Internal Quantara Team)
- [ ] Tabel `customer_health_score` per tenant: login_frequency, feature_adoption_rate, payslip_calculation_count, support_ticket_count
- [ ] Auto-flag low engagement → CSM team takes action
- [ ] Churn risk score: prediksi tenant yang akan cancel berdasarkan health score trend

---

### S3.5 — Admin / Super Admin Console

**Modul Baru:** Q. Admin Console
**Effort:** Medium-High · **Impact:** High (operational efficiency)

> Console internal Quantara untuk manage tenant, support, billing

#### Halaman Super Admin (Quantara staff only)
- [ ] List semua tenant dengan filter status, plan, signup date, MRR
- [ ] Detail tenant: subscription history, usage, payment history, support tickets
- [ ] Action: impersonate tenant admin (audit log mandatory), force upgrade plan, reset password admin, export data
- [ ] Dashboard metrics: MRR, ARR, churn rate, MAU, DAU, signup conversion funnel

#### Audit Log
- [ ] Semua action super admin tracked dengan severity HIGH
- [ ] 4-eyes principle untuk action sensitif (suspend tenant, refund payment)

---

### S3.6 — API Documentation & Developer Portal (H3.8 lengkap)

**Modul:** L. Integration
**Effort:** Medium-High · **Impact:** Medium (customer enterprise)

- [ ] Auto-generate OpenAPI spec dari controller annotations
- [ ] Swagger UI di `/developer/docs`
- [ ] API key management UI: create, rotate, revoke per company
- [ ] OAuth 2.0 server (auth code + client credentials flow)
- [ ] Rate limiting per API key per tier
- [ ] Webhook event catalog + subscription UI (per company admin)
- [ ] Sandbox environment dengan reset weekly
- [ ] Quickstart guide + code samples (PHP/Python/Node)

---

## 🟪 PHASE 4 — GROWTH & SCALE (Sprint S17+, ~6+ bulan)

### S4.1 — Marketplace / App Ecosystem

**Modul Baru:** R. Marketplace
**Effort:** Very High · **Impact:** Medium-High

- [ ] Integration partners directory:
  - Slack / Microsoft Teams (notifikasi)
  - Zapier / Make.com (automation)
  - Tableau / Looker / Power BI connector
  - Accurate Online / Zahir / Odoo (akuntansi)
- [ ] Partner program: revenue share, co-marketing
- [ ] Tabel `installed_integrations` per tenant + OAuth handshake

---

### S4.2 — Multi-Country Expansion (H4.2)

**Modul:** E. Payroll
**Effort:** Very High · **Impact:** High (TAM expansion)

- [ ] Refactor PayrollService → jurisdiction-aware
- [ ] Country modules:
  - Malaysia (EPF, SOCSO, EIS, PCB)
  - Singapura (CPF, IRAS)
  - Filipina (SSS, PhilHealth, Pag-IBIG, BIR)
  - Thailand (Social Security, Withholding Tax)
- [ ] Multi-currency: tabel `exchange_rates` dengan daily refresh dari BI/Open Exchange Rates
- [ ] Country-specific compliance reports

---

### S4.3 — AI Copilot Production (H4.1 lengkap)

**Modul:** Intelligence
**Effort:** High · **Impact:** Medium-High (differentiator)

- [ ] Chat UI di shell HRIS (sidebar, full-screen mode)
- [ ] Function calling: AI bisa execute action dengan confirmation:
  - Create leave request
  - Lookup employee
  - Generate report
  - Schedule interview
- [ ] Cost monitoring per tenant: tabel `ai_usage_meters`
- [ ] AI add-on billing (premium feature, $5-20/user/month)
- [ ] Multi-LLM fallback: GPT-4 primary → Claude → local model

---

### S4.4 — Public Customer Portal & Help Center

**Modul:** Marketing
**Effort:** Medium · **Impact:** Medium

- [ ] `help.quantara.io` — knowledge base public (Notion / GitBook / Helpjuice)
- [ ] `status.quantara.io` — incidents, scheduled maintenance (Statuspage / Atlassian)
- [ ] `community.quantara.io` — forum customer (Discourse)
- [ ] Public roadmap (Productboard / Canny) — transparency dengan customer

---

### S4.5 — Sales Demo Environment

**Modul:** Marketing
**Effort:** Low-Medium · **Impact:** Medium

- [ ] Sandbox tenant `demo.quantara.io` reset tiap Senin pagi
- [ ] Pre-loaded data realistis:
  - 200 karyawan (mix PKWT / PKWTT / probation)
  - 12 bulan attendance dengan anomali wajar
  - 1 case SP1 + 1 termination dengan severance
  - Payroll period sudah finalize 11 bulan + 1 bulan draft
- [ ] One-click "Try Demo" dari pricing page (auto-login as demo admin)

---

## RENCANA SPRINT RINGKAS (SaaS Track)

```
Sprint S1  — S1.1 Subscription Plans + Feature Flags
Sprint S2  — S1.2 Subscription Lifecycle
Sprint S3  — S1.3 Self-Service Signup + Provisioning
Sprint S4  — S1.4 Quota Enforcement + Metering
Sprint S5  — S1.5 Payment Gateway Integration
Sprint S6  — S1.6 Dunning & Churn Management
Sprint S7  — S2.1 Observability Stack
Sprint S8  — S2.2 Multi-Tenant Hardening + S2.3 Backup/DR
Sprint S9  — S2.4 Email Deliverability + S2.5 Rate Limiting
Sprint S10 — S2.6 Performance Test & Optimization
Sprint S11 — S3.1 White-Labeling
Sprint S12 — S3.2 Mobile Native (Phase 1: core features)
Sprint S13 — S3.3 ISO 27001 prep (parallel dengan S12)
Sprint S14 — S3.4 Customer Success Tooling
Sprint S15 — S3.5 Admin Console
Sprint S16 — S3.6 Developer Portal lengkap (H3.8)
Sprint S17+ — Phase 4 (TBD priority, depend on customer feedback)
```

---

## METRIK KEBERHASILAN SAAS

| KPI | Target Phase 1 | Target Phase 3 | Cara Ukur |
| --- | --- | --- | --- |
| Time to first payslip | < 2 jam dari signup | < 30 menit | onboarding_progress timestamp |
| Trial → Paid conversion | TBD baseline | > 25% | subscription status transition |
| MRR (Monthly Recurring Revenue) | First $10K | $100K | sum(active subscriptions × plan price) |
| Churn rate (annual) | TBD | < 8% | cancelled / active * 12 |
| NPS (Net Promoter Score) | TBD | > 40 | in-app survey quarterly |
| Customer Acquisition Cost (CAC) | TBD | CAC < 12-month payback | total marketing spend / new paying customers |
| Uptime (production) | 99.9% | 99.95% | external monitoring |
| Payroll calc 10K employees | TBD | < 60 detik p95 | k6 load test |
| Support response time | TBD | < 4 jam business hours | helpdesk_tickets tracking |

---

## PRIORITAS ACTION ITEM SEKARANG (Top 5)

Bila harus pilih hanya 5 hal untuk dikerjakan dulu untuk SaaS launch:

1. **S1.1 + S1.2 + S1.3 + S1.5** — Subscription, signup, billing harus jalan dulu (paling lambat 8-10 minggu)
2. **S2.1 Observability** — wajib sebelum production launch
3. **S2.3 Backup/DR** — wajib untuk customer trust
4. **S3.1 White-Labeling minimal** (logo+color) — pembeda penting saat demo
5. **S3.5 Admin Console minimal** — supaya tim Quantara bisa support tenant

Item 11–20 (mobile native, sertifikasi, marketplace, multi-country) bisa **delay sampai ada 50-100 paying customer** untuk validasi product-market fit dulu.

---

## ASUMSI & RISIKO

### Asumsi
- Tim development 3-5 engineer full-time (mix backend, frontend, devops)
- 1 designer full-time atau full-time consultant untuk landing/onboarding
- 1 PM/founder yang punya akses ke calon customer untuk feedback loop
- Budget infrastructure: $1-3K/month awal (AWS / GCP) → scale dengan customer

### Risiko Tinggi
- **Multi-tenant isolation bug** → data leak antar tenant → reputational damage. Mitigasi: S2.2 + automated testing.
- **Compliance audit gagal** → enterprise customer churn. Mitigasi: hire consultant 1 tahun sebelum audit.
- **Payment gateway integration glitch** → customer charged but service not activated. Mitigasi: thorough testing + reconciliation cron.
- **Mobile app rejection** dari Apple/Google → delay launch 2-4 minggu. Mitigasi: comply guideline ketat sejak design.

---

## CATATAN AKHIR

Dokumen ini bersifat **living document** seperti `PENGEMBANGAN.md`. Akan
direview tiap akhir Phase dengan input dari:

- Feedback customer paying & trial
- Metrik Phase sebelumnya (apakah on track ke target)
- Update regulasi (UU PDP, perubahan PP/PMK perpajakan)
- Competitive landscape (Mekari, GajiHub, Talenta, BambooHR ID)

Roadmap ini **tidak menggantikan** `PENGEMBANGAN.md` — keduanya jalan paralel.
PENGEMBANGAN.md fokus produk HR; SAAS.md fokus go-to-market & operations.

---

*Dokumen dibuat: Juni 2026*
*Berdasarkan analisis: 88 migrations, modul A-M, gap menuju SaaS commercial-grade*
*Versi sistem yang ditelaah: Sprint 9+ (Critical Legal Fixes finalized)*