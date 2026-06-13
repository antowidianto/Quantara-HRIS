# Quantara HRIS — Subscription Lifecycle Reference

State machine subscription tenant + business rules untuk transition antar
status. Disesuaikan untuk pasar Indonesia (Bahasa Indonesia, IDR, PMK 168
+ UU PDP compliant).

> Code reference: `backend/app/Libraries/Billing/SubscriptionService.php`,
> migration `2026-07-01-000002_S1_2_Subscriptions.php`,
> cron `BillingCheckRenewals` + `BillingDunning` + `BillingTrialExpiryNotify`.

---

## State Diagram

```
                       ┌──────────────────────────────────────────┐
                       │                                          │
                       ▼                                          │
   ┌────────┐   ┌────────────┐   ┌──────────┐   ┌─────────────┐   │
   │ signup │──▶│   TRIAL    │──▶│  ACTIVE  │──▶│  PAST_DUE   │   │
   └────────┘   └────────────┘   └──────────┘   └─────────────┘   │
                       │              │                │           │
                       │              │                │           │ reactivate()
                       │              │                ▼           │
                       │              │         ┌──────────────┐   │
                       │              │         │  SUSPENDED   │   │
                       │              │         └──────────────┘   │
                       │              │                │           │
                       │              ▼                │           │
                       │        ┌──────────┐           │           │
                       └───────▶│CANCELLED │◀──────────┘           │
                                └──────────┘                       │
                                     │                             │
                                     ▼                             │
                                ┌──────────┐                       │
                                │ EXPIRED  │───────────────────────┘
                                └──────────┘
                                ◀──── reactivate (settle invoice)
```

---

## Status Definitions

| Status | Tenant access | Trigger | Action saat masuk |
| --- | --- | --- | --- |
| `TRIAL` | Full read+write | createTrial() saat signup | trial_ends_at = +14 hari |
| `ACTIVE` | Full read+write | upgrade() / renew() / paid invoice | Set current_period_end |
| `PAST_DUE` | Read+write masih bisa, banner kuning | Payment gagal / trial expired tanpa upgrade | Mulai dunning H+1..H+10 |
| `SUSPENDED` | **Read-only** (POST/PUT/DELETE blocked) | Dunning H+10 atau super admin manual | SuspendedTenantFilter aktif |
| `CANCELLED` | Read-only sampai period_end | User cancel sendiri / hard cancel cron | Keep akses sampai EOP |
| `EXPIRED` | Read-only, butuh reaktivasi | Period habis + auto_renew=0 | Banner reactivate |

---

## Lifecycle Operations

### 1. createTrial(companyId, planCode='FREE_TRIAL')

Dipanggil dari `TenantProvisioningService` saat email signup verified.

```php
$svc->createTrial($companyId, 'FREE_TRIAL', $userId);
// → status TRIAL, trial_ends_at = NOW + 14 days, current_period_end = NOW + 14 days
```

Idempotent: throw `RuntimeException` kalau company sudah punya subscription.

### 2. upgrade(companyId, newPlanCode)

User pilih plan berbayar dari halaman /billing/plan. Kalkulasi proration:

```
proration_amount = old_unused_days_credit + new_used_days_charge
                 = (old_price * remaining_days / period_days) - (new_price * used_days / period_days)
```

Pure helper `calculateProrationPure()` testable tanpa DB.

### 3. downgrade(companyId, newPlanCode)

Tidak immediate. Schedule perubahan plan ke periode berikutnya supaya
tenant tetap dapat manfaat plan tinggi sampai period_end. Tidak ada
credit refund (industry standard SaaS).

### 4. cancel(companyId, reason, feedbackScore)

User klik "Cancel Subscription" di /billing/plan. Status tetap ACTIVE
sampai current_period_end, lalu auto ke EXPIRED. User bisa reactivate
gratis sampai 30 hari setelah cancel.

`cancelled_at` di-set ke NOW, `cancel_reason` + `feedback_score` (NPS-style
1-5) disimpan untuk analisis churn.

### 5. reactivate(companyId)

Hanya bisa kalau status CANCELLED atau EXPIRED. Reset cancelled_at, set
status ke ACTIVE atau TRIAL (tergantung plan_code).

### 6. extendTrial(companyId, additionalDays, reason) — super admin

Dipanggil dari console internal Quantara untuk negotiating ENTERPRISE
deal yang butuh trial > 14 hari. Audit log severity HIGH wajib + reason
mandatory min 10 karakter.

### 7. suspend(companyId, reason) / unsuspend(companyId)

Otomatis dipanggil dari `BillingDunning` cron H+10 PAST_DUE. Atau manual
dari super admin console (4-eyes confirmation + reason).

Saat SUSPENDED, `App\Filters\SuspendedTenantFilter` block semua POST/PUT/
DELETE dengan response 423 LOCKED. GET tetap allow (user masih bisa lihat
data, tapi tidak bisa modify).

---

## Transition Triggers (Cron)

### `billing:check-renewals` (daily 00:00)

```
TRIAL + trial_ends_at <= NOW
  → status PAST_DUE (kalau tidak ada payment method)
  → status ACTIVE (kalau ada payment method + auto-charge sukses) [S1.5]

ACTIVE + current_period_end <= TODAY + auto_renew=1
  → roll period (MONTHLY +1 bulan, YEARLY +1 tahun)
  → generate invoice [S1.5]
  → trigger BillingService.renew event

ACTIVE + current_period_end <= TODAY + auto_renew=0
  → status EXPIRED

PAST_DUE > 30 hari (updated_at)
  → status CANCELLED otomatis (hard cancel fallback)
```

### `billing:dunning` (daily 08:00)

Progressive escalation untuk PAST_DUE:

```
H+1  : Email reminder ramah ("Pembayaran tertunggak, mohon konfirmasi")
H+3  : Email + WhatsApp warning, status tetap PAST_DUE
H+7  : Email "Akan disuspend H+10"
H+10 : suspend() — read-only mode aktif
H+30 : hard cancel via BillingCheckRenewals fallback
```

State tracking via tabel `dunning_actions` supaya tidak duplicate per hari.

### `billing:trial-expiry-notify` (daily 09:00)

Reminder H-7, H-3, H-1 sebelum trial expire untuk drive trial-to-paid
conversion (target ≥ 25%). Dedup via subscription_events.event_type =
'TRIAL_REMINDER_SENT' + metadata `days_before`.

---

## Business Rules

### Trial duration
Default 14 hari, configurable per plan via `subscription_plans.trial_days`.

### Auto-renewal default
ON saat upgrade. User bisa toggle off di /billing/plan → "Cancel Subscription"
(tidak langsung cancel, hanya disable auto_renew).

### Currency
IDR by default. ENTERPRISE deal bisa pakai USD via `subscriptions.currency`
field (S1.5+ payment gateway).

### Custom price untuk ENTERPRISE
`subscriptions.custom_price` override harga plan default. Diisi saat super
admin negosiasi via console internal. Pure helper proration tetap pakai
custom_price kalau ada.

### Tax
Plan price exclude PPN 11%. Invoice generation (S1.5) akan tambahkan
PPN otomatis untuk customer dengan NPWP.

### Refund
Default no-refund kecuali force majeure. Mid-month upgrade hanya proration
forward (not back).

### Grace period
- TRIAL → PAST_DUE: 0 hari (langsung kalau tidak ada payment method)
- ACTIVE → PAST_DUE: 0 hari (langsung saat invoice unpaid)
- PAST_DUE → SUSPENDED: 10 hari
- SUSPENDED → CANCELLED: 20 hari (total 30 hari dari invoice)

---

## Invariants & Constraints

1. **Single subscription per company**: hanya 1 row di `subscriptions` per
   `company_id`. Enforced via unique index.

2. **No status downgrade kecuali via service method**: jangan UPDATE
   langsung di SQL. Pakai SubscriptionService method supaya audit log
   konsisten + subscription_events ter-track.

3. **trial_ends_at hanya di-set saat status TRIAL**. Saat upgrade atau
   activate, trial_ends_at tetap (untuk historical), tapi
   current_period_end yang dipakai untuk billing.

4. **company.trial_ends_at sync** dengan subscriptions.trial_ends_at saat
   provisioning (TenantProvisioningService update keduanya). Untuk fast
   filter di SaaS metrics dashboard.

5. **cancelled_at != null tidak otomatis berarti CANCELLED**. Kalau user
   reactivate dalam 30 hari, status balik ke ACTIVE tapi cancelled_at di-clear.

---

## Audit & Compliance

Setiap state transition mencatat:

```
audit_logs (severity NOTICE/HIGH per action):
  - billing.subscription.created (TRIAL via signup)
  - billing.subscription.upgraded
  - billing.subscription.downgraded
  - billing.subscription.cancelled
  - billing.subscription.reactivated
  - billing.subscription.suspended (HIGH — financial impact)
  - billing.subscription.unsuspended (HIGH)
  - billing.subscription.trial_extended (HIGH — super admin override)

subscription_events (granular per change):
  - CREATED, UPGRADED, DOWNGRADED, CANCELLED, REACTIVATED
  - RENEWED (auto-renew successful)
  - TRIAL_EXPIRED, EXPIRED, HARD_CANCELLED
  - TRIAL_REMINDER_SENT (dengan metadata days_before)
  - SUSPENDED, UNSUSPENDED
```

---

## API Endpoints

### Tenant-facing (auth + permission `billing.manage`)

```
GET    /api/v1/billing/subscription           — current sub + usage + days_remaining
POST   /api/v1/billing/upgrade                — body: { plan_code, billing_cycle }
POST   /api/v1/billing/downgrade              — body: { plan_code, billing_cycle }
POST   /api/v1/billing/cancel                 — body: { reason, feedback_score? }
POST   /api/v1/billing/reactivate
GET    /api/v1/features/me                    — feature flags effective
GET    /api/v1/billing/usage                  — usage meters semua key
```

### Super admin (Quantara internal)

```
POST   /api/v1/admin/tenants/{id}/extend-trial    — body: { days, reason }
POST   /api/v1/admin/tenants/{id}/suspend         — body: { reason, confirm: true }
POST   /api/v1/admin/tenants/{id}/unsuspend
POST   /api/v1/admin/tenants/{id}/feature-override — feature override per tenant
GET    /api/v1/admin/metrics/saas                  — MRR, ARR, churn, plan dist
```

---

## Frontend Surfaces

| Komponen | Lokasi | Status yang ditampilkan |
| --- | --- | --- |
| `SubscriptionStatusBanner` | Top of every page (AppLayout) | TRIAL ≤5d, PAST_DUE, SUSPENDED, EXPIRED |
| `BillingPlan.vue` | `/billing/plan` | Current plan + comparison + upgrade/downgrade/cancel |
| `BillingUsage.vue` | `/billing/usage` | Quota meters + warning threshold |
| `UpgradeBanner` | Inline di feature-gated views | Per-feature kalau OFF |
| `TenantDetail.vue` | `/admin/tenants/:id` (super admin) | Detail subscription + events history |
| `SaasMetrics.vue` | `/admin/saas-metrics` | Aggregate MRR/ARR/churn |

---

## Common Scenarios

### Scenario 1: Tenant baru signup
```
1. POST /auth/signup → signup_attempts row PENDING
2. User klik link email → GET /auth/verify-signup
3. TenantProvisioningService.provision()
   → INSERT companies + users
   → SubscriptionService.createTrial() → status TRIAL, 14 hari
4. Auto-login + redirect /onboarding wizard
```

### Scenario 2: Trial 14 hari habis tanpa upgrade
```
Day 14 23:59 — trial_ends_at terlewat
Day 15 00:00 — billing:check-renewals
  → status PAST_DUE (no payment method)
Day 15 08:00 — billing:dunning H+1
  → email reminder "Pembayaran tertunggak"
Day 17 08:00 — H+3 email + WhatsApp
Day 21 08:00 — H+7 warning "Akan disuspend"
Day 24 08:00 — H+10 suspend() + read-only banner
Day 44 — H+30 hard cancel
```

### Scenario 3: User upgrade TRIAL → PRO mid-month
```
1. POST /billing/upgrade { plan_code: 'PRO', billing_cycle: 'MONTHLY' }
2. SubscriptionService.upgrade() menghitung proration
3. INSERT subscription_events (UPGRADED dengan from/to plan)
4. Status TRIAL → ACTIVE, current_period_end = NOW + 1 bulan
5. Audit log severity NOTICE
6. UI refresh feature flags via composable cache invalidation
```

### Scenario 4: Tenant lupa bayar 10 hari → suspend
```
1. Day H : invoice due (current_period_end)
2. Day H+1 : status PAST_DUE, dunning email H+1
3. Day H+10 : DunningService.suspend() → status SUSPENDED
4. Tenant login → SubscriptionStatusBanner merah "Akun disuspend"
5. Semua POST/PUT/DELETE return 423 LOCKED via SuspendedTenantFilter
6. Tenant bayar tunggakan → super admin POST /unsuspend → ACTIVE
```

---

## Migration & Backwards Compatibility

Tenant existing yang dibuat sebelum sprint S1.2 (tidak punya row di
`subscriptions`):

- API GET `/billing/subscription` return 404 → FE handle gracefully (composable `useSubscription` return null)
- Filter `SuspendedTenantFilter` skip kalau no subscription row (assumed legacy = ACTIVE)
- Migrasi production: jalankan one-time `php spark` script untuk insert row TRIAL atau ACTIVE per company existing

---

*Versi 1.0 · 2026-08-10 · Update bila ada status baru atau business rule berubah.*