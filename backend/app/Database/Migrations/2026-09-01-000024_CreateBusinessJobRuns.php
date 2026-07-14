<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBusinessJobRuns extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('business_job_runs')) return;
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'job_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'job_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'request_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'RUNNING'],
            'processed_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'failed_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'duration_ms' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'context_json' => ['type' => 'TEXT', 'null' => true],
            'error_code' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'started_at' => ['type' => 'DATETIME'],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['job_name', 'status', 'finished_at']);
        $this->forge->addKey(['company_id', 'started_at']);
        $this->forge->createTable('business_job_runs');
    }

    public function down(): void
    {
        $this->forge->dropTable('business_job_runs', true);
    }
}
