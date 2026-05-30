<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CreateJobQueue — Sprint D+2 / D7.
 *
 * Tabel `job_queue` untuk async background processing. Pakai MySQL sebagai
 * storage (bukan Redis-pure) supaya transactional dan tidak hilang saat
 * worker crash. Lihat `app/Libraries/JobQueue.php` untuk reasoning.
 *
 * Index strategis:
 *   - (status, run_at, priority DESC, id ASC) — composite untuk worker
 *     polling: `WHERE status='PENDING' AND run_at <= NOW() ORDER BY ...`
 *   - (company_id, status) — admin dashboard listing per tenant
 *   - (idempotency_key, company_id) — anti-duplikat enqueue
 */
class CreateJobQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'unsigned' => true],

            'job_class'       => ['type' => 'VARCHAR', 'constraint' => 191, 'comment' => 'FQCN handler class'],
            'payload'         => ['type' => 'JSON', 'null' => true],

            'status'          => [
                'type' => 'ENUM',
                'constraint' => ['PENDING', 'RUNNING', 'DONE', 'FAILED', 'CANCELLED'],
                'default' => 'PENDING',
            ],
            'priority'        => ['type' => 'INT', 'default' => 0, 'comment' => 'Higher first'],
            'attempts'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_attempts'    => ['type' => 'INT', 'unsigned' => true, 'default' => 3],
            'run_at'          => ['type' => 'DATETIME', 'comment' => 'Earliest time to run (backoff)'],
            'started_at'      => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],

            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'result'          => ['type' => 'JSON', 'null' => true, 'comment' => 'Output dari handler'],
            'last_error'      => ['type' => 'TEXT', 'null' => true],

            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');

        // Worker polling — paling kritis untuk performance
        $this->forge->addKey(['status', 'run_at', 'priority', 'id'], false, false, 'idx_jq_worker_poll');

        // Admin dashboard listing per tenant
        $this->forge->addKey(['company_id', 'status'], false, false, 'idx_jq_company_status');

        // Idempotency check (compound; not strictly unique — kita unique-ish lewat
        // app-level check di JobQueue::enqueue supaya retry yang sengaja tetap bisa)
        $this->forge->addKey(['company_id', 'idempotency_key'], false, false, 'idx_jq_idem');

        // FK ke companies — cascade delete kalau company di-purge
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('job_queue');
    }

    public function down(): void
    {
        $this->forge->dropTable('job_queue', true);
    }
}