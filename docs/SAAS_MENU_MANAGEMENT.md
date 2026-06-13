# SaaS Management — Status Menu & Roadmap Implementasi

> **Status dokumen:** Living doc per Sprint 20 (relokasi menu Enterprise).
> **Audience:** Product owner Quantara, super admin Quantara, frontend dev.
> **Konteks:** Modul SaaS (subscription, billing, tenant monitoring) sudah
> punya backend + Vue view lengkap, tapi sebagian besar **belum punya entry
> di sidebar database-driven** (`menus` table, lihat migration #076 +
> #006/#007 Sprint 20). Doc ini list apa yang ada, apa yang hilang, dan
> cara menambahkan.

---

## 1. Inventaris fitur SaaS yang sudah dibangun

Semua item berikut **sudah jalan** secara backend + Vue view, tinggal
masalah visibility di sidebar.

### 1.1 Tenant-facing (admin tenant)

| Fitur | Route Vue | Controller | View File | Permission |
| --- | --- | --- | --- | --- |
| Subscription Plan | `/billing/plan` | `Billing\BillingController` | `Billing/BillingPlan.vue` | `billing.manage` |
| Usage & Billing | `/billing/usage` | `Billing\BillingController` | `Billing/BillingUsage.vue` | `billing.view` |
| Branding (white-label) | `/settings/branding` | `WhiteLabel\BrandingController` | `Settings/BrandingSettings.vue` | `branding.manage` |
| Data Export (UU PDP) | `/settings/data-export` | `Backup\DataExportController` | `Settings/DataExport.vue` | `data_export.manage` |
| Onboarding Wizard | `/onboarding` | `CustomerSuccess\CustomerSuccessController` | `Onboarding/OnboardingWizard.vue` | (auth saja) |

### 1.2 Quantara internal (super admin)

| Fitur | Route Vue | Controller | View File | Permission |
| --- | --- | --- | --- | --- |
| Tenant List | `/admin/tenants` | `Admin\AdminTenantController::index` | `Admin/TenantList.vue` | super_admin only |
| Tenant Detail | `/admin/tenants/:id` | `Admin\AdminTenantController::show` | `Admin/TenantDetail.vue` | super_admin only |
| SaaS Metrics (MRR/ARR/Churn) | `/admin/saas-metrics` | `Admin\AdminTenantController::saasMetrics` | `Admin/SaasMetrics.vue` | super_admin only |
| Customer Health Score | `/admin/customer-health` | `CustomerSuccess\CustomerSuccessController` | `Admin/CustomerHealth.vue` | super_admin only |

### 1.3 Public (no auth)

| Fitur | Route Vue | Controller | View File | Status menu |
| --- | --- | --- | --- | --- |
| Self-service Signup | `/signup` | `Auth\AuthSignupController::signup` | `Signup.vue` | n/a (link dari landing page) |
| Email Verification | `/verify-email` | `Auth\AuthSignupController::verify` | `VerifyEmail.vue` | n/a (link via email) |
| Pricing page | `/pricing` | `Billing\BillingController::plans` | **❌ BELUM ADA** | n/a |

---

## 2. State menu sidebar saat ini

Menu sidebar Quantara HRIS **database-driven** sejak migration
[`2024-01-01-000076_CreateMenus.php`](../backend/app/Database/Migrations/2024-01-01-000076_CreateMenus.php).
Ada tabel `menus(id, company_id, parent_id, type, code, label, icon,
route_path, permission, super_admin_only, sort_order, is_active)`.

### 2.1 Apa yang sudah di-seed (Sprint 20 — migration #007)

Migration [`2026-09-01-000007_S20_RelocateMenusToSuperAdmin.php`](../backend/app/Database/Migrations/2026-09-01-000007_S20_RelocateMenusToSuperAdmin.php)
membuat **PARENT** `admin.enterprise` di bawah section `admin` dengan
flag `super_admin_only=1`, lalu nempelin children:

```
Admin
└── Enterprise Modules (super_admin_only)
    ├── Subscription Plan       → /billing/plan
    ├── Usage & Billing         → /billing/usage
    ├── ERP Journal             → /erp/journal
    ├── Digital Signing         → /signing
    ├── Developer API Keys      → /developer/api-keys
    ├── License Tracker         → /licenses
    └── Project Timesheet       → /timesheet
```

### 2.2 Yang HILANG dari sidebar

Tujuh fitur SaaS sudah punya Vue view + backend tapi **tidak punya
record di `menus`**, sehingga tidak akan muncul di sidebar siapapun
(kecuali user paste URL langsung):

| Code yang diusulkan | Label | Route | Visibility |
| --- | --- | --- | --- |
| `admin.saas` | SaaS Management (PARENT) | — | super_admin_only |
| `admin.saas.tenants` | Daftar Tenant | `/admin/tenants` | super_admin_only |
| `admin.saas.metrics` | SaaS Metrics | `/admin/saas-metrics` | super_admin_only |
| `admin.saas.customer_health` | Customer Health | `/admin/customer-health` | super_admin_only |
| `settings.branding` | Branding & White-Label | `/settings/branding` | permission `branding.manage` |
| `settings.data_export` | Data Export (UU PDP) | `/settings/data-export` | permission `data_export.manage` |
| `my.onboarding` | Onboarding Wizard | `/onboarding` | (auto-tampil bila status=ONBOARDING) |

### 2.3 Masalah desain saat ini

1. **`/billing/plan` & `/billing/usage` keliru `super_admin_only=1`.**
   Migration #007 set keduanya `super_admin_only=1`. Padahal admin tenant
   (role `super_company_admin`) yang punya permission `billing.manage`
   harus bisa lihat & upgrade plan sendiri. Filter UI di `MenuController`
   akan hide menu untuk non-super-admin walaupun mereka punya permission.
   **→ Harus diubah jadi `super_admin_only=0` + permission `billing.manage`.**

2. **Tidak ada section khusus "SaaS Management" di sidebar super admin.**
   Tenant List, SaaS Metrics, Customer Health sekarang tidak terjangkau
   karena belum di-seed. Super admin terpaksa bookmark URL.

3. **Branding & Data Export tidak masuk section "Pengaturan" tenant.**
   Padahal sudah ada section `settings` (lihat seed default migration #076).

4. **Onboarding Wizard tidak punya entry point selain redirect manual.**
   Setelah signup, user diarahkan ke `/onboarding`, tapi kalau mereka
   keluar tanpa selesai, mereka tidak bisa balik karena tidak ada link.

---

## 3. Struktur menu yang direkomendasikan

### 3.1 Untuk super admin Quantara (internal)

```
Admin
├── Pengguna                    (existing)
├── Role & Permission           (existing)
├── Audit Log                   (existing)
├── ✨ SaaS Management          (super_admin_only)   ← BARU
│   ├── Daftar Tenant           → /admin/tenants
│   ├── SaaS Metrics            → /admin/saas-metrics
│   ├── Customer Health         → /admin/customer-health
│   └── (extend trial / impersonate dilakukan dari TenantDetail)
├── Enterprise Modules          (super_admin_only)   ← sudah ada (#007)
│   ├── Subscription Plan       → /billing/plan       ← PINDAH ke section tenant
│   ├── Usage & Billing         → /billing/usage      ← PINDAH ke section tenant
│   ├── ERP Journal
│   ├── Digital Signing
│   ├── Developer API Keys
│   ├── License Tracker
│   └── Project Timesheet
└── Pengaturan                  (existing)
```

### 3.2 Untuk admin tenant (super_company_admin)

```
Pengaturan
├── Settings                    (existing)
├── Email Settings              (existing)
├── Companies                   (existing)
├── ✨ Branding                 → /settings/branding       ← BARU
└── ✨ Data Export              → /settings/data-export    ← BARU

Billing                         (PARENT, permission: billing.view)   ← BARU
├── Subscription Plan           → /billing/plan       (permission: billing.manage)
└── Usage & Billing             → /billing/usage      (permission: billing.view)
```

> **Catatan:** Menu dengan `permission` non-null akan auto-hidden untuk
> user yang belum punya permission tsb di
> [`MenuController::myMenus()`](../backend/app/Controllers/Api/V1/Identity/MenuController.php).
> Jadi karyawan biasa tidak akan kelihatan section Billing.

### 3.3 Untuk user baru (post-signup, status=ONBOARDING)

```
Saya
├── Profil
├── ...
└── ✨ Onboarding Wizard        → /onboarding

```

> **UX detail:** entry "Onboarding Wizard" sebaiknya **conditional** —
> hanya tampil kalau `companies.onboarding_status != 'COMPLETED'`. Ini
> butuh extension sederhana di `MenuController::myMenus()` untuk
> evaluate predicate per user. Alternatif simple: buat banner/CTA di
> dashboard yang link ke `/onboarding`, dan biarkan menu permanent
> (akan ditandai "Selesai" di dalam wizard).

### 3.4 Public landing — pricing page (defer / opsional)

Halaman `/pricing` belum ada. Sebaiknya dibuat sebagai bagian dari
landing page Quantara terpisah (next.js / static site), bukan di app
Vue ini, karena calon user belum login. Sementara, link "Lihat Paket"
di halaman `/signup` sudah cukup untuk gating awal.

---

## 4. Implementasi — migration template

Buat migration baru bernama `2026-09-15-000001_S20_AddSaasManagementMenus.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah menu untuk SaaS Management Console (super admin) +
 * Branding/Data Export (admin tenant) yang Vue view-nya sudah ada
 * tapi belum di-seed di sidebar.
 *
 * Idempotent. Pakai ensureMenu() pattern sama dengan migration #007.
 */
class S20AddSaasManagementMenus extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('menus')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // ─── 1. Fix BillingPlan + BillingUsage (super_admin_only=0) ─
        // Migration #007 keliru set super_admin_only=1. Admin tenant
        // dengan permission billing.manage harus bisa akses.
        $db->table('menus')
            ->where('company_id IS NULL')
            ->whereIn('code', [
                'admin.enterprise.subscription',
                'admin.enterprise.usage',
            ])
            ->delete();

        // ─── 2. Section "Billing" untuk admin tenant ───────────────
        // Cari section settings (existing dari #076) atau bikin Billing
        // sebagai SECTION sendiri di root dengan permission billing.view.
        $billingSectionId = $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => null,
            'type'             => 'SECTION',
            'code'             => 'billing',
            'label'            => 'Billing',
            'icon'             => 'CreditCardIcon',
            'route_path'       => null,
            'permission'       => 'billing.view',
            'super_admin_only' => 0,
            'sort_order'       => 78, // di antara intel(70-an) dan admin(80-an)
        ], $now);

        $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $billingSectionId,
            'type'             => 'ITEM',
            'code'             => 'billing.plan',
            'label'            => 'Subscription Plan',
            'icon'             => 'CreditCardIcon',
            'route_path'       => '/billing/plan',
            'permission'       => 'billing.manage',
            'super_admin_only' => 0,
            'sort_order'       => 0,
        ], $now);

        $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $billingSectionId,
            'type'             => 'ITEM',
            'code'             => 'billing.usage',
            'label'            => 'Usage & Billing',
            'icon'             => 'ChartBarIcon',
            'route_path'       => '/billing/usage',
            'permission'       => 'billing.view',
            'super_admin_only' => 0,
            'sort_order'       => 10,
        ], $now);

        // ─── 3. PARENT "SaaS Management" (super_admin_only) ─────────
        $adminSection = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'admin')
            ->get()->getRowArray();

        $adminSectionId = $adminSection
            ? (int) $adminSection['id']
            : $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => null,
                'type'             => 'SECTION',
                'code'             => 'admin',
                'label'            => 'Admin',
                'icon'             => null,
                'route_path'       => null,
                'permission'       => null,
                'super_admin_only' => 0,
                'sort_order'       => 80,
            ], $now);

        $saasParentId = $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $adminSectionId,
            'type'             => 'PARENT',
            'code'             => 'admin.saas',
            'label'            => 'SaaS Management',
            'icon'             => 'GlobeAltIcon',
            'route_path'       => null,
            'permission'       => null,
            'super_admin_only' => 1,
            'sort_order'       => 30, // sebelum Enterprise Modules (35)
        ], $now);

        $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $saasParentId,
            'type'             => 'ITEM',
            'code'             => 'admin.saas.tenants',
            'label'            => 'Daftar Tenant',
            'icon'             => 'BuildingOfficeIcon',
            'route_path'       => '/admin/tenants',
            'permission'       => null,
            'super_admin_only' => 1,
            'sort_order'       => 0,
        ], $now);

        $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $saasParentId,
            'type'             => 'ITEM',
            'code'             => 'admin.saas.metrics',
            'label'            => 'SaaS Metrics',
            'icon'             => 'ChartPieIcon',
            'route_path'       => '/admin/saas-metrics',
            'permission'       => null,
            'super_admin_only' => 1,
            'sort_order'       => 10,
        ], $now);

        $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $saasParentId,
            'type'             => 'ITEM',
            'code'             => 'admin.saas.customer_health',
            'label'            => 'Customer Health',
            'icon'             => 'HeartIcon',
            'route_path'       => '/admin/customer-health',
            'permission'       => null,
            'super_admin_only' => 1,
            'sort_order'       => 20,
        ], $now);

        // ─── 4. Branding + Data Export (di section Pengaturan) ─────
        $settingsSection = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'settings')
            ->get()->getRowArray();

        if ($settingsSection) {
            $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => (int) $settingsSection['id'],
                'type'             => 'ITEM',
                'code'             => 'settings.branding',
                'label'            => 'Branding & White-Label',
                'icon'             => 'PaintBrushIcon',
                'route_path'       => '/settings/branding',
                'permission'       => 'branding.manage',
                'super_admin_only' => 0,
                'sort_order'       => 100,
            ], $now);

            $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => (int) $settingsSection['id'],
                'type'             => 'ITEM',
                'code'             => 'settings.data_export',
                'label'            => 'Data Export (UU PDP)',
                'icon'             => 'ArrowDownTrayIcon',
                'route_path'       => '/settings/data-export',
                'permission'       => 'data_export.manage',
                'super_admin_only' => 0,
                'sort_order'       => 110,
            ], $now);
        }

        // ─── 5. Onboarding Wizard di section "Saya" ────────────────
        $mySection = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'my')
            ->get()->getRowArray();

        if ($mySection) {
            $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => (int) $mySection['id'],
                'type'             => 'ITEM',
                'code'             => 'my.onboarding',
                'label'            => 'Onboarding Wizard',
                'icon'             => 'RocketLaunchIcon',
                'route_path'       => '/onboarding',
                'permission'       => null,
                'super_admin_only' => 0,
                'sort_order'       => 250,
            ], $now);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if (! $db->tableExists('menus')) {
            return;
        }
        $codes = [
            'billing.plan', 'billing.usage', 'billing',
            'admin.saas.tenants', 'admin.saas.metrics', 'admin.saas.customer_health', 'admin.saas',
            'settings.branding', 'settings.data_export',
            'my.onboarding',
        ];
        foreach ($codes as $code) {
            $db->table('menus')->where('company_id IS NULL')->where('code', $code)->delete();
        }
    }

    private function ensureMenu(array $row, string $now): int
    {
        $db = $this->db;
        $existing = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('code', $row['code'])
            ->get()->getRowArray();

        if ($existing) {
            $update = [
                'parent_id'        => $row['parent_id'] ?? null,
                'type'             => $row['type'],
                'label'            => $row['label'],
                'icon'             => $row['icon'] ?? null,
                'route_path'       => $row['route_path'] ?? null,
                'permission'       => $row['permission'] ?? null,
                'super_admin_only' => (int) ($row['super_admin_only'] ?? 0),
                'sort_order'       => (int) ($row['sort_order'] ?? 0),
                'is_active'        => 1,
                'updated_at'       => $now,
            ];
            $db->table('menus')->where('id', $existing['id'])->update($update);
            return (int) $existing['id'];
        }

        $row['is_active']  = 1;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $db->table('menus')->insert($row);
        return (int) $db->insertID();
    }
}
```

### 4.1 Permissions yang harus disediakan

Cek di [`backend/app/Database/Migrations/2024-01-01-000058_SeedMissingPermissions.php`](../backend/app/Database/Migrations/2024-01-01-000058_SeedMissingPermissions.php)
dan tambahkan kalau belum ada:

| Slug permission | Status | Diberikan default ke role |
| --- | --- | --- |
| `billing.view` | cek di seed | super_company_admin, hr_admin |
| `billing.manage` | cek di seed | super_company_admin |
| `branding.manage` | cek di seed | super_company_admin |
| `data_export.manage` | cek di seed | super_company_admin |

Kalau belum ada, buat migration permissions baru atau extend
`SeedEnterpriseRoles` (#047).

### 4.2 Verification script

Tambah cek di `backend/scripts/verify_sprint20_menus.php` atau buat
file baru `verify_saas_menus.php`:

```php
$expected = [
    'billing', 'billing.plan', 'billing.usage',
    'admin.saas', 'admin.saas.tenants', 'admin.saas.metrics', 'admin.saas.customer_health',
    'settings.branding', 'settings.data_export',
    'my.onboarding',
];
foreach ($expected as $code) {
    $row = $db->table('menus')->where('code', $code)->where('company_id IS NULL')->get()->getRowArray();
    assert($row !== null, "Menu '$code' missing");
}
```

---

## 5. Halaman publik yang masih perlu dibangun

### 5.1 Pricing page `/pricing` (PRIORITY: medium, defer post-PMF)

Sudah ada endpoint `GET /api/v1/plans` (publik, no auth) yang return
list 4-tier plan + features matrix. Untuk landing page:

- **Opsi A (recommended):** bangun di Next.js / Astro static site
  terpisah (mis. `quantara.id/pricing`), pakai `fetch()` ke
  `/api/v1/plans`. Lebih SEO-friendly, performance bagus.
- **Opsi B (cepat):** tambah Vue route `/pricing` dengan `meta: { public: true }`
  di `frontend/src/router/index.js` + view sederhana yang reuse logic
  dari `BillingPlan.vue`.

### 5.2 Demo / sales contact form (defer)

Untuk lead enterprise (>500 employees), form `/contact-sales` yang kirim
ke email sales. Belum ada di backend; cukup pakai SMTP basic.

---

## 6. Dokumen terkait

- [`QUANTARA_HRIS_ROADMAP_SAAS.md`](03_Roadmap_Sprints/QUANTARA_HRIS_ROADMAP_SAAS.md) — roadmap besar Phase 1–4 SaaS.
- [`QUANTARA_HRIS_ROADMAP_SAAS_CHECKLIST.md`](03_Roadmap_Sprints/QUANTARA_HRIS_ROADMAP_SAAS_CHECKLIST.md) — eksekusi checklist per item.
- [`SAAS_FEATURE_FLAGS.md`](SAAS_FEATURE_FLAGS.md) — daftar 38 feature key + matrix per plan.
- [`SAAS_SUBSCRIPTION_LIFECYCLE.md`](SAAS_SUBSCRIPTION_LIFECYCLE.md) — state machine TRIAL → ACTIVE → PAST_DUE → CANCELLED.
- [`SAAS_TENANT_PROVISIONING.md`](SAAS_TENANT_PROVISIONING.md) — flow signup + email verify + tenant creation.
- Migration SaaS: `#001`–`#005` Phase 1, `#006`–`#007` Sprint 20 menus.

---

## 7. Rangkuman untuk product owner

| Pertanyaan | Jawaban singkat |
| --- | --- |
| Halaman subscribe SaaS sudah ada? | ✅ `/signup` (publik) + `/billing/plan` (in-app upgrade) |
| Halaman monitoring subscriber? | ✅ Vue view sudah ada (`/admin/tenants`, `/admin/saas-metrics`, `/admin/customer-health`), tapi **tidak terjangkau dari sidebar** karena belum di-seed di tabel `menus` |
| Apa yang perlu dilakukan? | Buat 1 migration baru (template di section 4) untuk seed entries. Tidak perlu kode baru, semua view & backend sudah ready |
| Estimasi effort? | **2–4 jam** — copy template, sesuaikan icon/label, jalankan migrate, test akses untuk role super_admin + super_company_admin |
| Apa yang masih missing? | Pricing page publik `/pricing` (defer; bisa pakai BillingPlan untuk authenticated user dulu) |

---

**Author:** Quantara Engineering
**Last reviewed:** Sprint 20 (2026-09)
**Next review:** Setelah migration `S20_AddSaasManagementMenus` di-deploy.