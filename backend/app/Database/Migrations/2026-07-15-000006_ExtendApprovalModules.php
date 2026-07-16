<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Replace rigid module ENUMs with catalog-validated strings. */
final class ExtendApprovalModules extends Migration
{
    public function up(): void
    {
        foreach (['approval_chains', 'approval_assignments', 'approval_requests'] as $table) {
            if (! $this->db->tableExists($table) || ! $this->db->fieldExists('module', $table)) continue;
            $this->forge->modifyColumn($table, [
                'module' => ['name' => 'module', 'type' => 'VARCHAR', 'constraint' => 64],
            ]);
        }
        if ($this->db->tableExists('business_trips') && ! $this->db->fieldExists('approved_by', 'business_trips')) {
            $this->forge->addColumn('business_trips', [
                'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'approved_at'],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('business_trips') && $this->db->fieldExists('approved_by', 'business_trips')) {
            $this->forge->dropColumn('business_trips', 'approved_by');
        }
        // Module VARCHAR is intentionally retained: reverting it could truncate
        // requests created by modules introduced after this migration.
    }
}
