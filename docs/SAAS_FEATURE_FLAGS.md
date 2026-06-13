# Quantara HRIS — SaaS Feature Flags Reference

Dokumen ini mendeskripsikan semua **feature_key** yang dipakai sistem feature
gating (`FeatureGateService`) dan cara override per tenant.

> Sumber data: tabel `feature_flags`, `plan_features`, `company_feature_overrides`.
> Logic resolusi: `OVERRIDE > PLAN > DEFAULT`. Lihat
> `backend/app/Libraries/Billing/FeatureGateService.php`.

---

## Hierarki Resolusi

Saat backend / FE menanyakan apakah feature aktif untuk tenant tertentu:

```
1. Cek company_feature_overrides
   - Bila row exists & belum expired → pakai value override (TRUE/FALSE)
   - Audit source = 'OVERRIDE'

2. Bila tidak ada override, cek plan_features (lewat subscriptions.plan_id)
   - Bila row exists → pakai value plan
   - Audit source = 'PLAN'

3. Bila plan tidak punya entry (mis. flag baru), pakai default_value di feature_flags
   - Audit source = 'DEFAULT'
```

Setiap pemanggilan dicatat audit log severity NOTICE supaya bisa di-trace
mengapa suatu feature aktif/non-aktif untuk tenant tertentu.

---

## Daftar Feature Flags

Feature flags dikelompokkan per modul. Untuk full matrix per plan
(FREE_TRIAL/STARTER/PRO/ENTERPRISE), lihat
`backend/app/Database/Seeds/SubscriptionPlanSeeder.php`.

### A. Core HR (selalu enabled di semua plan)

| Key | Default | Description |
| --- | --- | --- |
| `employee.basic_crud` | true | CRUD karyawan, kontrak, dokumen |
| `attendance.basic` | true | Clock-in/out + manual correction |
| `leave.basic` | true | Pengajuan + approval cuti |
| `payroll.basic` | true | Periode payroll + komponen gaji standar |

### B. Payroll Advanced (PRO + ENTERPRISE)

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `payroll.thr_engine` | false | ✓ | ✓ | ✓ |
| `payroll.bonus_engine` | false | — | ✓ | ✓ |
| `payroll.simulation` | false | — | ✓ | ✓ |
| `payroll.export_csv` | false | — | ✓ | ✓ |
| `payroll.export_pph21_csv` | false | — | ✓ | ✓ |
| `payroll.export_bpjs_csv` | false | — | ✓ | ✓ |
| `payroll.bank_export_bca` | false | — | ✓ | ✓ |
| `payroll.bank_export_mandiri` | false | — | ✓ | ✓ |
| `payroll.payment_gateway_disbursement` | false | — | — | ✓ |

### C. Recruitment & ATS

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `recruitment.basic_pipeline` | false | ✓ | ✓ | ✓ |
| `recruitment.career_portal` | false | ✓ | ✓ | ✓ |
| `recruitment.online_test` | false | — | ✓ | ✓ |
| `recruitment.referral_program` | false | — | ✓ | ✓ |
| `recruitment.candidate_scoring` | false | — | ✓ | ✓ |

### D. Performance & Talent

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `performance.okr_kpi` | false | — | ✓ | ✓ |
| `performance.review_360` | false | — | — | ✓ |
| `talent.nine_box` | false | — | — | ✓ |
| `talent.succession_planning` | false | — | — | ✓ |
| `talent.competency_library` | false | — | — | ✓ |
| `talent.marketplace` | false | — | — | ✓ |

### E. Integrations

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `integration.whatsapp` | false | — | ✓ | ✓ |
| `integration.adms_devices` | false | ✓ | ✓ | ✓ |
| `integration.payment_gateway` | false | — | ✓ | ✓ |
| `integration.accounting_export` | false | — | ✓ | ✓ |
| `integration.webhook_outbound` | false | — | — | ✓ |
| `integration.api_keys` | false | — | — | ✓ |

### F. Enterprise Sales Features

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `white_label.enabled` | false | — | — | ✓ |
| `white_label.custom_domain` | false | — | — | ✓ |
| `white_label.custom_email_template` | false | — | — | ✓ |
| `enterprise.sso_saml` | false | — | — | ✓ |
| `enterprise.audit_log_long_retention` | false | — | — | ✓ |
| `enterprise.dedicated_support` | false | — | — | ✓ |

### G. AI & Advanced Analytics (Phase 4)

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `ai.copilot_chat` | false | — | — | add-on |
| `ai.payroll_anomaly_detection` | false | — | — | add-on |
| `analytics.workforce_scenarios` | false | — | — | ✓ |
| `analytics.compensation_intel` | false | — | — | ✓ |
| `analytics.pulse_survey` | false | — | — | ✓ |

### H. Quotas & Limits

Bukan boolean, tapi dipakai sebagai numerik limit di `UsageMeterService`.
Lihat juga `docs/SAAS_USAGE_METERS.md` untuk enforcement detail.

| Key | Default | STARTER | PRO | ENTERPRISE |
| --- | --- | --- | --- | --- |
| `quota.employees` | 50 | 50 | 250 | unlimited |
| `quota.storage_mb` | 1024 | 5120 | 20480 | unlimited |
| `quota.whatsapp_per_month` | 0 | 0 | 1000 | unlimited |
| `quota.api_calls_per_month` | 1000 | 10000 | 100000 | unlimited |

---

## Backend Usage

### Cek dari Filter (route-level)

Tag filter `feature` di route definition supaya kalau feature off, request
auto-rejected dengan 403:

```php
$routes->get('payroll/simulate',
    'Horizon2Controller::simulatePayroll',
    ['filter' => 'feature:payroll.simulation']);
```

### Cek dari Code (programmatic)

```php
use App\Libraries\Billing\FeatureGateService;

$svc = new FeatureGateService();
if (! $svc->isEnabled($companyId, 'payroll.bonus_engine')) {
    throw new FeatureNotAvailableException('Bonus engine butuh PRO+');
}
```

### Pure Resolver (untuk testing)

```php
$flags = ['payroll.bonus_engine' => ['default_value' => false]];
$plan = ['payroll.bonus_engine' => true];
$override = ['payroll.bonus_engine' => false];

[$value, $source] = FeatureGateService::resolvePure(
    'payroll.bonus_engine',
    $override,
    $plan,
    $flags,
);
// $value = false, $source = 'OVERRIDE'
```

---

## Frontend Usage

### Composable `useFeature`

```vue
<script setup>
import { useFeature, useFeatureGuard } from '@/composables/useFeature'

// Simple boolean reactive
const { enabled, source } = useFeature('payroll.bonus_engine')

// Guard pattern: throw kalau off
const guard = useFeatureGuard()
async function tryGenerateBonus() {
  if (! guard('payroll.bonus_engine')) return
  // ... lanjutkan
}
</script>

<template>
  <button :disabled="!enabled" @click="tryGenerateBonus">
    Generate Bonus Run
  </button>
  <UpgradeBanner v-if="!enabled" feature="payroll.bonus_engine" />
</template>
```

Cache 5 menit per feature key supaya tidak refetch di setiap mount.

---

## Override Per Tenant (Super Admin Only)

Endpoint:
```
POST /api/v1/admin/tenants/{companyId}/feature-override
Body:
{
  "feature_key": "payroll.bonus_engine",
  "enabled": true,
  "expires_in_days": 30,    // null = permanen
  "reason": "Trial extension for negotiating ENTERPRISE deal #INV-2026-001"
}
```

Audit log severity HIGH otomatis. Override expire akan auto-fallback ke
nilai plan saat reach expires_at (TTL check di FeatureGateService).

UI: `/admin/tenants/{id}` → tab "Feature Overrides".

---

## Menambah Feature Baru

1. **Insert di seeder** `SubscriptionPlanSeeder` — tambahkan key + default + matrix per plan
2. **Filter / programmatic check** — tag route atau cek di service
3. **Tambah ke dokumen ini** dengan deskripsi + matrix
4. **Migrate / re-seed** di production via `php spark db:seed SubscriptionPlanSeeder`

Per-request memoization aktif: cache di-flush otomatis kalau ada
`feature-override` dipanggil oleh super admin, supaya override langsung
efektif tanpa wait TTL.

---

## Troubleshooting

**"Tenant tetap kena 403 padahal plan PRO"**
1. Cek apakah ada `company_feature_overrides` row dengan `enabled=0` belum expired
2. Cek subscriptions.status — kalau SUSPENDED, semua write blocked di filter `SuspendedTenant` (lebih dulu dari feature filter)
3. Tail audit_logs filter `event=feature.gate_check` untuk lihat source

**"Feature baru ke-skip default false"**
- Setelah deploy, jangan lupa `php spark db:seed SubscriptionPlanSeeder` supaya plan_features ter-update.
- Atau insert manual untuk hot-fix:
  ```sql
  INSERT INTO plan_features (plan_id, feature_key, value, created_at)
  SELECT id, 'new.feature_key', 1, NOW() FROM subscription_plans WHERE code = 'PRO';
  ```

---

*Versi 1.0 · 2026-08-10 · Update setiap kali ada feature flag baru di seeder.*