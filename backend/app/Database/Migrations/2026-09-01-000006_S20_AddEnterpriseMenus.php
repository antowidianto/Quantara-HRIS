<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 20 — Tambah menu sidebar untuk 5 modul Enterprise Readiness.
 *
 * Migration #076 mendefinisikan sidebar Quantara HRIS sebagai database-driven
 * (tabel `menus`). Sprint 20 menambah 5 modul baru (ERP Journal, Digital
 * Signing PSrE, Open API Portal, Project Timesheet, License Tracker) yang
 * route Vue + controller-nya sudah jalan tapi belum kelihatan di sidebar
 * karena belum di-seed.
 *
 * Migration ini idempotent — pakai `code` sebagai dedup key. Strategi:
 *
 *   1. Bikin satu SECTION baru "Enterprise" yang menampung modul lintas-
 *      domain (ERP, Signing, API Portal, License). Ditaruh sebelum Admin.
 *   2. Tambahkan ITEM "Timesheet Saya" di SECTION existing "Saya"
 *      sebagai sub-PARENT "Timesheet" supaya karyawan langsung bisa akses.
 *
 * Permission slug merujuk ke permissions yang sudah di-seed di
 * `2026-09-01-000003_H3_8_OpenApiPortal.php`,
 * `2026-09-01-000002_H3_7_DigitalSigning.php`,
 * `2026-09-01-000001_H3_4_ErpIntegration.php`,
 * `2026-09-01-000004_ProjectTimesheet.php`,
 * `2026-09-01-000005_LicenseExpiryTracker.php`.
 *
 * Aman re-run: cek `code` exists sebelum insert; sort_order di-increment
 * dari max sort_order section parent.
 */
class S20AddEnterpriseMenus extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('menus')) {
            // Migration #076 belum jalan; skip — akan di-seed oleh #076 default
            // di environment fresh. Di tenant existing, #076 sudah berjalan
            // duluan jadi jalur ini hampir tidak akan terjadi.
            return;
        }

        $now = date('Y-m-d H:i:s');

        // ─── 1. SECTION "Enterprise" + 4 ITEM ─────────────────────
        $sectionId = $this->ensureMenu([
            'company_id'      => null,
            'parent_id'       => null,
            'type'            => 'SECTION',
            'code'            => 'enterprise',
            'label'           => 'Enterprise',
            'translation_key' => 'nav.enterprise',
            'icon'            => null,
            'route_path'      => null,
            'permission'      => null,
            'sort_order'      => 75, // antara intel(70-ish) dan admin(80-ish)
        ], $now);

        $erpId = $this->ensureMenu([
            'company_id'      => null,
            'parent_id'       => $sectionId,
            'type'            => 'ITEM',
            'code'            => 'enterprise.erp_journal',
            'label'           => 'ERP Journal',
            'translation_key' => 'nav.items.erp_journal',
            'icon'            => 'BanknotesIcon',
            'route_path'      => '/erp/journal',
            'permission'      => 'erp.journal.view',
            'sort_order'      => 0,
        ], $now);

        $signId = $this->ensureMenu([
            'company_id'      => null,
            'parent_id'       => $sectionId,
            'type'            => 'ITEM',
            'code'            => 'enterprise.signing',
            'label'           => 'Digital Signing',
            'translation_key' => 'nav.items.digital_signing',
            'icon'            => 'PencilSquareIcon',
            'route_path'      => '/signing',
            'permission'      => 'signing.envelope.view',
            'sort_order'      => 10,
        ], $now);

        $apiId = $this->ensureMenu([
            'company_id'      => null,
            'parent_id'       => $sectionId,
            'type'            => 'ITEM',
            'code'            => 'enterprise.api_keys',
            'label'           => 'Developer API Keys',
            'translation_key' => 'nav.items.developer_api_keys',
            'icon'            => 'KeyIcon',
            'route_path'      => '/developer/api-keys',
            'permission'      => 'api_key.view',
            'sort_order'      => 20,
        ], $now);

        $licenseId = $this->ensureMenu([
            'company_id'      => null,
            'parent_id'       => $sectionId,
            'type'            => 'ITEM',
            'code'            => 'enterprise.licenses',
            'label'           => 'Lisensi & Sertifikat',
            'translation_key' => 'nav.items.license_tracker',
            'icon'            => 'AcademicCapIcon',
            'route_path'      => '/licenses',
            'permission'      => 'employee_license.view',
            'sort_order'      => 30,
        ], $now);

        // ─── 2. ITEM "Timesheet Saya" di SECTION "Saya" ───────────
        // Cari section "my" → tempel ITEM langsung di bawahnya supaya
        // karyawan biasa langsung bisa akses entry timesheet harian.
        $mySection = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'my')
            ->get()->getRowArray();

        if ($mySection) {
            $tsId = $this->ensureMenu([
                'company_id'      => null,
                'parent_id'       => (int) $mySection['id'],
                'type'            => 'ITEM',
                'code'            => 'my.timesheet',
                'label'           => 'Timesheet Saya',
                'translation_key' => 'nav.items.project_timesheet',
                'icon'            => 'ClockIcon',
                'route_path'      => '/timesheet',
                'permission'      => 'timesheet.submit',
                // Tempel di paling bawah section "Saya"
                'sort_order'      => 200,
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
            'enterprise.erp_journal',
            'enterprise.signing',
            'enterprise.api_keys',
            'enterprise.licenses',
            'my.timesheet',
            'enterprise', // section terakhir
        ];
        foreach ($codes as $code) {
            $db->table('menus')->where('company_id IS NULL')->where('code', $code)->delete();
        }
    }

    /**
     * Insert menu kalau code (per company_id NULL) belum ada. Return id.
     */
    private function ensureMenu(array $row, string $now): int
    {
        $db = $this->db;
        $existing = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('code', $row['code'])
            ->get()->getRowArray();

        if ($existing) {
            return (int) $existing['id'];
        }

        $row['is_active']  = 1;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $db->table('menus')->insert($row);

        return (int) $db->insertID();
    }
}
