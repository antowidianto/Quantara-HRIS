<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S2.3 — Backup & Disaster Recovery
 *
 * Tabel `backup_runs` mencatat eksekusi job backup database (encrypted).
 * Dipakai oleh `App\Commands\DbBackupEncrypted` (cron nightly) dan
 * `App\Commands\DbBackupVerify` (weekly) untuk audit + alerting.
 *
 * Kolom kunci:
 * - status: PENDING | RUNNING | SUCCESS | FAILED | VERIFIED
 * - kind:   FULL (mysqldump) | INCREMENTAL (binlog future)
 * - storage_target: LOCAL | S3 | GCS
 * - retention_class: DAILY (7d) | WEEKLY (4w) | MONTHLY (12m)
 *
 * NOTE: idempotent — guard tableExists supaya aman re-run.
 */
class S2_3_BackupRuns extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if (! $db->tableExists('backup_runs')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'kind'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'FULL'],
                'status'          => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'PENDING'],
                'storage_target'  => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'LOCAL'],
                'retention_class' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'DAILY'],
                'started_at'      => ['type' => 'DATETIME', 'null' => true],
                'finished_at'     => ['type' => 'DATETIME', 'null' => true],
                'duration_sec'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'size_bytes'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'storage_path'    => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
                'sha256'          => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'gpg_encrypted'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'verified_at'     => ['type' => 'DATETIME', 'null' => true],
                'error_message'   => ['type' => 'TEXT', 'null' => true],
                'metadata_json'   => ['type' => 'JSON', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['status']);
            $forge->addKey(['retention_class', 'started_at']);
            $forge->addKey(['kind', 'started_at']);
            $forge->createTable('backup_runs');
        }

        // ─── Tabel data export request (S2.3 GDPR Right to Portability) ───
        if (! $db->tableExists('data_export_requests')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'BIGINT', 'unsigned' => true],
                'requested_by'    => ['type' => 'BIGINT', 'unsigned' => true],
                'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'QUEUED'], // QUEUED|RUNNING|READY|FAILED|EXPIRED
                'scope_json'      => ['type' => 'JSON', 'null' => true], // mis. {"include": ["employees","payslips"]}
                'file_path'       => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
                'file_size_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'sha256'          => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
                'download_token'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'expires_at'      => ['type' => 'DATETIME', 'null' => true],
                'started_at'      => ['type' => 'DATETIME', 'null' => true],
                'finished_at'     => ['type' => 'DATETIME', 'null' => true],
                'error_message'   => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['download_token']);
            $forge->addKey(['expires_at']);
            $forge->createTable('data_export_requests');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('data_export_requests')) {
            $forge->dropTable('data_export_requests');
        }
        if ($db->tableExists('backup_runs')) {
            $forge->dropTable('backup_runs');
        }
    }
}