<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMyTeamMenu extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('menus')) return;
        $exists = $this->db->table('menus')
            ->where('company_id IS NULL')
            ->where('code', 'my.team')
            ->countAllResults();
        if ($exists > 0) return;

        $section = $this->db->table('menus')
            ->where('company_id IS NULL')
            ->where('parent_id IS NULL')
            ->where('code', 'my')
            ->get()->getRowArray();
        if (! $section) return;

        $now = date('Y-m-d H:i:s');
        $this->db->table('menus')->insert([
            'company_id' => null,
            'parent_id' => (int) $section['id'],
            'type' => 'ITEM',
            'code' => 'my.team',
            'label' => 'Tim Saya',
            'translation_key' => 'nav.items.my_team',
            'icon' => 'UserGroupIcon',
            'route_path' => '/my-team',
            'permission' => null,
            'sort_order' => 5,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('menus')) return;
        $this->db->table('menus')
            ->where('company_id IS NULL')
            ->where('code', 'my.team')
            ->delete();
    }
}
