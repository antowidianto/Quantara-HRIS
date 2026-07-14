<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Records isolated restore evidence without claiming that a drill occurred. */
class CreateRestoreDrills extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('restore_drills')) return;

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'backup_run_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16],
            'environment' => ['type' => 'VARCHAR', 'constraint' => 80],
            'rpo_seconds' => ['type' => 'INT', 'unsigned' => true],
            'rto_seconds' => ['type' => 'INT', 'unsigned' => true],
            'record_counts_verified' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'hashes_verified' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'attachments_verified' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'login_verified' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'evidence_reference' => ['type' => 'VARCHAR', 'constraint' => 512],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'started_at' => ['type' => 'DATETIME'],
            'finished_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'finished_at']);
        $this->forge->addKey(['backup_run_id']);
        $this->forge->addForeignKey('backup_run_id', 'backup_runs', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('restore_drills');
    }

    public function down(): void
    {
        $this->forge->dropTable('restore_drills', true);
    }
}
