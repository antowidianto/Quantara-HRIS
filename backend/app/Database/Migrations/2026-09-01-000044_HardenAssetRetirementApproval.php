<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenAssetRetirementApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('assets')) return;
        $fields = [];
        foreach ([
            'retired_at' => ['type' => 'DATETIME', 'null' => true],
            'retired_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'retirement_approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'retirement_reason' => ['type' => 'TEXT', 'null' => true],
            'disposal_method' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'disposal_proceeds' => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'disposal_reference' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'book_value_at_retirement' => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
            'write_off_amount' => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, 'assets')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('assets', $fields);
    }

    public function down(): void
    {
        // Financial disposal evidence is intentionally retained.
    }
}
