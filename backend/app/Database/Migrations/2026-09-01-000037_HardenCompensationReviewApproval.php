<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenCompensationReviewApproval extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('compensation_review_proposals')) return;
        $fields = [];
        foreach ([
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ] as $name => $definition) {
            if (! $this->db->fieldExists($name, 'compensation_review_proposals')) $fields[$name] = $definition;
        }
        if ($fields !== []) $this->forge->addColumn('compensation_review_proposals', $fields);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('compensation_review_proposals')) return;
        foreach (['rejected_by', 'rejected_at', 'rejection_reason'] as $column) {
            if ($this->db->fieldExists($column, 'compensation_review_proposals')) {
                $this->forge->dropColumn('compensation_review_proposals', $column);
            }
        }
    }
}
