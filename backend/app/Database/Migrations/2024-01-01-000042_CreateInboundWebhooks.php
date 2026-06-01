<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 14+ — Inbound webhook infrastructure.
 *
 * Counterpart dari outbound webhook (#041). Memungkinkan sistem external
 * (recruitment marketplace, accounting tool, partner HRIS) kirim event ke
 * HRIS lewat HTTP POST dengan signature.
 *
 * Tabel:
 *   1. `inbound_webhooks` — registrasi source per company. Setiap source
 *      punya secret + filter event type yang diterima + handler hint
 *      (untuk dispatch internal). Multi-tenant scoped.
 *
 *   2. `inbound_webhook_events` — log setiap request yang masuk:
 *      - Untuk audit (siapa kirim apa kapan)
 *      - Untuk idempotency (UNIQUE source_event_id supaya retry consumer
 *        tidak duplicate process di sisi HRIS)
 *      - Untuk replay/debug (full request body + headers tersimpan)
 *
 * Auth strategy:
 *   - Signature header: `X-HRIS-Signature: sha256=<hex>` HMAC dari raw body
 *   - Timestamp header: `X-HRIS-Timestamp` untuk replay protection
 *     (5 menit window, sama pattern dengan WebhookSigner outbound)
 *   - Source identifier: header `X-HRIS-Source` atau path param di URL
 *   - IP allowlist opsional (CIDR di kolom `allowed_ips` JSON)
 *
 * Handler dispatch:
 *   Saat event diterima dan signature valid, controller cari handler di
 *   `App\Libraries\Webhook\InboundWebhookHandler::resolve($eventType)` registry.
 *   Handler mengembalikan ack response (200) atau error (4xx/5xx).
 *
 * Multi-tenant: setiap inbound webhook scoped ke company. Source URL
 * menyertakan tenant identifier (mis. `/inbound/{slug}` di mana slug
 * ke-resolve ke company_id).
 */
class CreateInboundWebhooks extends Migration
{
    public function up()
    {
        // ─── inbound_webhooks: registrasi source ────────────────────
        if (! $this->db->tableExists('inbound_webhooks')) {
            $this->forge->addField([
                'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'  => ['type' => 'INT', 'unsigned' => true],
                // Slug unik global (URL-safe) untuk endpoint:
                //   POST /api/v1/inbound/{slug}
                'slug'        => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'        => ['type' => 'VARCHAR', 'constraint' => 100,
                                  'comment' => 'Label admin (mis. "Talenta Sync")'],
                'source'      => ['type' => 'VARCHAR', 'constraint' => 50,
                                  'comment' => 'Identifier source (talenta/accurate/glints/custom)'],
                'event_types' => ['type' => 'TEXT',
                                  'comment' => 'JSON array of accepted event types; empty = all'],
                'secret_enc'  => ['type' => 'TEXT',
                                  'comment' => 'Encrypted HMAC secret untuk verify signature'],
                'allowed_ips' => ['type' => 'TEXT', 'null' => true,
                                  'comment' => 'JSON array of CIDR atau exact IP; null = no IP filter'],
                'is_active'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'last_received_at' => ['type' => 'DATETIME', 'null' => true],
                'last_failure_at'  => ['type' => 'DATETIME', 'null' => true],
                'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'  => ['type' => 'DATETIME'],
                'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('slug');
            $this->forge->addKey('company_id');
            $this->forge->addKey(['company_id', 'is_active']);
            $this->forge->createTable('inbound_webhooks');
        }

        // ─── inbound_webhook_events: log + idempotency ──────────────
        if (! $this->db->tableExists('inbound_webhook_events')) {
            $this->forge->addField([
                'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'inbound_id'  => ['type' => 'INT', 'unsigned' => true],
                'company_id'  => ['type' => 'INT', 'unsigned' => true],
                'event_type'  => ['type' => 'VARCHAR', 'constraint' => 64],
                // ID dari source (X-Source-Event-Id atau di payload).
                // UNIQUE per inbound_id supaya retry consumer aman.
                'source_event_id' => ['type' => 'VARCHAR', 'constraint' => 100],
                'payload'     => ['type' => 'LONGTEXT',
                                  'comment' => 'Raw JSON body diterima'],
                'headers'     => ['type' => 'TEXT', 'null' => true,
                                  'comment' => 'JSON headers untuk debug'],
                'status'      => ['type' => 'ENUM',
                                  'constraint' => ['RECEIVED','PROCESSED','FAILED','REJECTED'],
                                  'default' => 'RECEIVED'],
                'http_status' => ['type' => 'SMALLINT', 'unsigned' => true,
                                  'comment' => 'HTTP status yang dikirim balik ke source'],
                'processed_at' => ['type' => 'DATETIME', 'null' => true],
                'last_error'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'remote_ip'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'created_at'  => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('inbound_id');
            $this->forge->addKey('event_type');
            $this->forge->addKey(['company_id', 'created_at']);
            $this->forge->addKey('status');
            // Idempotency — kalau source kirim ulang event_id sama,
            // INSERT akan ditolak DB → controller balas 200 OK tanpa
            // re-process (deduplication).
            $this->forge->addUniqueKey(['inbound_id', 'source_event_id']);
            $this->forge->createTable('inbound_webhook_events');
        }
    }

    public function down()
    {
        if ($this->db->tableExists('inbound_webhook_events')) $this->forge->dropTable('inbound_webhook_events');
        if ($this->db->tableExists('inbound_webhooks'))       $this->forge->dropTable('inbound_webhooks');
    }
}