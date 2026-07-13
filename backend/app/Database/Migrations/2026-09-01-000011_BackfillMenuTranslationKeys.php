<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Ensure menus added after the original seed also react to locale changes. */
class BackfillMenuTranslationKeys extends Migration
{
    private const KEYS = [
        'my.overtime_plan' => 'nav.items.overtime_plan',
        'my.team' => 'nav.items.my_team',
        'enterprise' => 'nav.enterprise',
        'admin.enterprise' => 'nav.enterprise',
        'enterprise.erp_journal' => 'nav.items.erp_journal',
        'admin.enterprise.erp_journal' => 'nav.items.erp_journal',
        'enterprise.signing' => 'nav.items.digital_signing',
        'admin.enterprise.signing' => 'nav.items.digital_signing',
        'enterprise.api_keys' => 'nav.items.developer_api_keys',
        'admin.enterprise.api_keys' => 'nav.items.developer_api_keys',
        'enterprise.licenses' => 'nav.items.license_tracker',
        'admin.enterprise.licenses' => 'nav.items.license_tracker',
        'my.timesheet' => 'nav.items.project_timesheet',
        'admin.enterprise.timesheet' => 'nav.items.project_timesheet',
        'admin.enterprise.subscription' => 'nav.items.subscription',
        'admin.enterprise.usage' => 'nav.items.billing_usage',
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('menus')) return;
        foreach (self::KEYS as $code => $key) {
            $this->db->table('menus')->where('code', $code)->update([
                'translation_key' => $key,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('menus')) return;
        foreach (array_keys(self::KEYS) as $code) {
            $this->db->table('menus')->where('code', $code)->update([
                'translation_key' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
