<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 11+ — Webhook outbound infrastructure.
 *
 * Tabel:
 *   1. `webhooks` — registrasi endpoint per company + event subscriptions
 *   2. `webhook_deliveries` — audit log + retry tracking per attempt
 *
 * Event types (string enum, akan ditulis konvensi `{module}.{action}`):
 *   - `employee.created`, `employee.updated`, `employee.resigned`
 *   - `payroll.period_finalized`, `payroll.paid`
 *   - `leave.requested`, `leave.approved`, `leave.rejected`
 *   - `overtime.requested`, `overtime.approved`
 *   - `attendance.late_absent`, `attendance.anomaly_flagged`
 *   - `recruitment.candidate_hired`
 *   - dll. (definisi formal di `App\Libraries\WebhookEvents`)
 *
 * Signing: HMAC-SHA256 dengan per-webhook `secret` (di-encrypt at rest
 * via Services::encryption). Header `X-HRIS-Signature: sha256=<hex>` +
 * `X-HRIS-Timestamp: <unix>` + `X-HRIS-Event: leave.approved`.
 *
 * Retry: exponential backoff sama dengan email_queue (1m → 5m → 30m → 2h → 6h),
 * max 5 attempts. Setelah habis → status FAILED final, alert ke admin.
 *
 * Multi-tenant: setiap webhook scoped ke `company_id`. Dispatcher hanya
 * call webhook untuk event yang terjadi di tenant yang sama.
 */
class CreateWebhooks extends Migration
{
    public function up()
    {
        // ─── webhooks: registrasi endpoint ──────────────────────────
        if (! $this->db->tableExists('webhooks')) {
            $this->forge->addField([
                'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'    => ['type' => 'INT', 'unsigned' => true],
                'name'          => ['type' => 'VARCHAR', 'constraint' => 100, 'comment' => 'Label untuk admin (mis. "Accurate Sync")'],
                'url'           => ['type' => 'VARCHAR', 'constraint' => 500],
                'event_types'   => ['type' => 'TEXT', 'comment' => 'JSON array of event type strings'],
                'secret_enc'    => ['type' => 'TEXT', 'comment' => 'Encrypted HMAC secret via Services::encryption()'],
                'is_active'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'last_success_at' => ['type' => 'DATETIME', 'null' => true],
                'last_failure_at' => ['type' => 'DATETIME', 'null' => true],
                'consecutive_failures' => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'Auto-disable kalau ≥ N (anti zombie endpoint)'],
                'created_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'    => ['type' => 'DATETIME'],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('company_id');
            $this->forge->addKey(['company_id', 'is_active']);
            $this->forge->createTable('webhooks');
        }

        // ─── webhook_deliveries: audit log + retry state ────────────
        if (! $this->db->tableExists('webhook_deliveries')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'webhook_id'    => ['type' => 'INT', 'unsigned' => true],
                'company_id'    => ['type' => 'INT', 'unsigned' => true],
                'event_type'    => ['type' => 'VARCHAR', 'constraint' => 64],
                'event_id'      => ['type' => 'VARCHAR', 'constraint' => 36, 'comment' => 'UUID for idempotency at consumer side'],
                'payload'       => ['type' => 'LONGTEXT', 'comment' => 'JSON encoded event body'],
                'status'        => ['type' => 'ENUM', 'constraint' => ['QUEUED','SENDING','DELIVERED','FAILED'], 'default' => 'QUEUED'],
                'http_status'   => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
                'response_body' => ['type' => 'TEXT', 'null' => true, 'comment' => 'First 1KB only, untuk debug'],
                'attempts'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'next_retry_at' => ['type' => 'DATETIME', 'null' => true],
                'last_attempt_at' => ['type' => 'DATETIME', 'null' => true],
                'delivered_at'  => ['type' => 'DATETIME', 'null' => true],
                'last_error'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'    => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('webhook_id');
            $this->forge->addKey('event_type');
            $this->forge->addKey(['status', 'next_retry_at']);
            $this->forge->addKey(['company_id', 'created_at']);
            $this->forge->addUniqueKey(['webhook_id', 'event_id']);  // idempotency key
            $this->forge->createTable('webhook_deliveries');
        }
    }

    public function down()
    {
        if ($this->db->tableExists('webhook_deliveries')) $this->forge->dropTable('webhook_deliveries');
        if ($this->db->tableExists('webhooks'))           $this->forge->dropTable('webhooks');
    }
}