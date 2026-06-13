<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 20 (revisi) — relokasi menu Enterprise + tambah Subscription ke super admin.
 *
 * Migration #006 sebelumnya bikin section terpisah "Enterprise" + nempelin
 * "Timesheet Saya" di section "Saya". Atas request, semua menu baru itu
 * dipindah jadi *super_admin_only* di bawah section "Admin" yang sudah ada,
 * sebagai PARENT dropdown "Enterprise Modules". Bonus: tambahkan link ke
 * halaman Subscription (Billing Plan + Usage) yang sudah ada controller +
 * Vue view-nya tapi belum dimasukkan sidebar.
 *
 * Strategi:
 *
 *   1. Hapus 6 entries dari migration #006 (idempotent — kalau sudah dihapus
 *      manual / oleh down() #006, no-op).
 *   2. Cari section "admin" (id dari seed default migration #076).
 *   3. Buat PARENT "admin.enterprise" (super_admin_only=1) di bawahnya.
 *   4. Tambah 6 ITEM children:
 *        - Subscription Plan (BillingPlan.vue)
 *        - Usage & Billing (BillingUsage.vue)
 *        - ERP Journal
 *        - Digital Signing
 *        - Developer API Keys
 *        - License Tracker
 *        - Project Timesheet
 *
 *   Semua super_admin_only=1 sesuai request. Karyawan biasa & HR tetap bisa
 *   akses route Vue-nya lewat URL langsung selama mereka punya permission.
 *
 * Idempotent: cek `code` exists per company_id NULL sebelum insert.
 */
class S20RelocateMenusToSuperAdmin extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('menus')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // ─── 1. Cleanup entries lama dari migration #006 ───────────
        $oldCodes = [
            'enterprise.erp_journal',
            'enterprise.signing',
            'enterprise.api_keys',
            'enterprise.licenses',
            'enterprise',     // section parent
            'my.timesheet',   // pindah ke super admin
        ];
        foreach ($oldCodes as $code) {
            $db->table('menus')
                ->where('company_id IS NULL')
                ->where('code', $code)
                ->delete();
        }

        // ─── 2. Cari section "admin" (parent_id = NULL, code = 'admin') ───
        $adminSection = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'admin')
            ->get()->getRowArray();

        if (! $adminSection) {
            // Section admin tidak ditemukan (misal migration #076 tidak full).
            // Fallback: bikin section sendiri dengan super_admin_only=1.
            $adminSectionId = $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => null,
                'type'             => 'SECTION',
                'code'             => 'admin',
                'label'            => 'Admin',
                'translation_key'  => 'nav.admin',
                'icon'             => null,
                'route_path'       => null,
                'permission'       => null,
                'super_admin_only' => 0,
                'sort_order'       => 80,
            ], $now);
        } else {
            $adminSectionId = (int) $adminSection['id'];
        }

        // ─── 3. PARENT "Enterprise Modules" (super_admin_only=1) ───
        $parentId = $this->ensureMenu([
            'company_id'       => null,
            'parent_id'        => $adminSectionId,
            'type'             => 'PARENT',
            'code'             => 'admin.enterprise',
            'label'            => 'Enterprise Modules',
            'translation_key'  => null,
            'icon'             => 'BuildingOffice2Icon',
            'route_path'       => null,
            'permission'       => null,
            'super_admin_only' => 1,
            // Taruh di bawah Pengguna/Role/Audit tapi sebelum Pengaturan.
            'sort_order'       => 35,
        ], $now);

        // ─── 4. ITEM children ──────────────────────────────────────
        $children = [
            [
                'code'       => 'admin.enterprise.subscription',
                'label'      => 'Subscription Plan',
                'icon'       => 'CreditCardIcon',
                'route_path' => '/billing/plan',
                'permission' => 'billing.manage',
                'sort_order' => 0,
            ],
            [
                'code'       => 'admin.enterprise.usage',
                'label'      => 'Usage & Billing',
                'icon'       => 'ChartBarIcon',
                'route_path' => '/billing/usage',
                'permission' => 'billing.view',
                'sort_order' => 10,
            ],
            [
                'code'       => 'admin.enterprise.erp_journal',
                'label'      => 'ERP Journal',
                'icon'       => 'BanknotesIcon',
                'route_path' => '/erp/journal',
                'permission' => 'erp.journal.view',
                'sort_order' => 20,
            ],
            [
                'code'       => 'admin.enterprise.signing',
                'label'      => 'Digital Signing',
                'icon'       => 'PencilSquareIcon',
                'route_path' => '/signing',
                'permission' => 'signing.envelope.view',
                'sort_order' => 30,
            ],
            [
                'code'       => 'admin.enterprise.api_keys',
                'label'      => 'Developer API Keys',
                'icon'       => 'KeyIcon',
                'route_path' => '/developer/api-keys',
                'permission' => 'api_key.view',
                'sort_order' => 40,
            ],
            [
                'code'       => 'admin.enterprise.licenses',
                'label'      => 'License Tracker',
                'icon'       => 'AcademicCapIcon',
                'route_path' => '/licenses',
                'permission' => 'employee_license.view',
                'sort_order' => 50,
            ],
            [
                'code'       => 'admin.enterprise.timesheet',
                'label'      => 'Project Timesheet',
                'icon'       => 'ClockIcon',
                'route_path' => '/timesheet',
                'permission' => 'timesheet.submit',
                'sort_order' => 60,
            ],
        ];

        foreach ($children as $c) {
            $this->ensureMenu([
                'company_id'       => null,
                'parent_id'        => $parentId,
                'type'             => 'ITEM',
                'code'             => $c['code'],
                'label'            => $c['label'],
                'translation_key'  => null,
                'icon'             => $c['icon'],
                'route_path'       => $c['route_path'],
                'permission'       => $c['permission'],
                'super_admin_only' => 1,
                'sort_order'       => $c['sort_order'],
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
            'admin.enterprise.subscription',
            'admin.enterprise.usage',
            'admin.enterprise.erp_journal',
            'admin.enterprise.signing',
            'admin.enterprise.api_keys',
            'admin.enterprise.licenses',
            'admin.enterprise.timesheet',
            'admin.enterprise',
        ];
        foreach ($codes as $code) {
            $db->table('menus')->where('company_id IS NULL')->where('code', $code)->delete();
        }
    }

    /**
     * Insert menu kalau code (per company_id NULL) belum ada. Return id.
     * Kalau sudah ada, update field-field penting (parent_id, super_admin_only,
     * sort_order, route_path) supaya re-run migration aman walau struktur berubah.
     */
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