<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddEmployeeDataQualityMenu extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('menus')) return;
        if ($this->db->table('menus')->where('company_id IS NULL')->where('code', 'hr.data_quality')->countAllResults() > 0) return;

        $parent = $this->db->table('menus')->select('id')->where('company_id IS NULL')->where('code', 'hr.talent')->get()->getRowArray();
        if (! $parent) $parent = $this->db->table('menus')->select('id')->where('company_id IS NULL')->where('code', 'hr')->get()->getRowArray();
        if (! $parent) return;
        $max = $this->db->table('menus')->selectMax('sort_order', 'max_sort')->where('parent_id', (int) $parent['id'])->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $this->db->table('menus')->insert([
            'company_id' => null, 'parent_id' => (int) $parent['id'], 'type' => 'ITEM',
            'code' => 'hr.data_quality', 'label' => 'Kualitas Data Karyawan',
            'translation_key' => 'data_quality.nav_label', 'icon' => 'ShieldCheckIcon',
            'route_path' => '/hr/data-quality', 'permission' => 'hr_analytics.view,compliance.report.view',
            'super_admin_only' => 0, 'sort_order' => (int) ($max['max_sort'] ?? 0) + 10,
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('menus')) $this->db->table('menus')->where('company_id IS NULL')->where('code', 'hr.data_quality')->delete();
    }
}
