<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Stores versioned, no-code dashboard templates assigned by role slug. */
final class CreateRoleDashboardBuilder extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('dashboard_templates')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'target_role_slug' => ['type' => 'VARCHAR', 'constraint' => 80],
                'name' => ['type' => 'VARCHAR', 'constraint' => 150],
                'description' => ['type' => 'TEXT', 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'DRAFT'],
                'layout_json' => ['type' => 'TEXT'],
                'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'published_at' => ['type' => 'DATETIME', 'null' => true],
                'published_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_by' => ['type' => 'INT', 'unsigned' => true],
                'updated_by' => ['type' => 'INT', 'unsigned' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['company_id', 'target_role_slug', 'status']);
            $this->forge->addKey(['status', 'published_at']);
            $this->forge->createTable('dashboard_templates');
        }

        if (! $this->db->tableExists('dashboard_template_versions')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'template_id' => ['type' => 'INT', 'unsigned' => true],
                'version' => ['type' => 'INT', 'unsigned' => true],
                'snapshot_json' => ['type' => 'TEXT'],
                'change_type' => ['type' => 'VARCHAR', 'constraint' => 30],
                'created_by' => ['type' => 'INT', 'unsigned' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['template_id', 'version']);
            $this->forge->addKey('template_id');
            $this->forge->createTable('dashboard_template_versions');
        }

        $this->seedMenu();
    }

    public function down(): void
    {
        if ($this->db->tableExists('menus')) {
            $this->db->table('menus')->where('code', 'admin.dashboard_builder')->delete();
        }
        $this->forge->dropTable('dashboard_template_versions', true);
        $this->forge->dropTable('dashboard_templates', true);
    }

    private function seedMenu(): void
    {
        if (! $this->db->tableExists('menus')) {
            return;
        }
        $exists = $this->db->table('menus')->where('company_id IS NULL')
            ->where('code', 'admin.dashboard_builder')->countAllResults();
        if ($exists > 0) {
            return;
        }
        $admin = $this->db->table('menus')->select('id')->where('company_id IS NULL')
            ->where('code', 'admin')->where('type', 'SECTION')->get()->getRowArray();
        if (! $admin) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('menus')->insert([
            'company_id' => null,
            'parent_id' => (int) $admin['id'],
            'type' => 'ITEM',
            'code' => 'admin.dashboard_builder',
            'label' => 'Dashboard Builder',
            'translation_key' => null,
            'icon' => 'PresentationChartLineIcon',
            'route_path' => '/admin/dashboard-builder',
            'permission' => null,
            'super_admin_only' => 1,
            'sort_order' => 25,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
