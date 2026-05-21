<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateNotificationAuditTables extends Migration
{
    public function up(): void
    {
        // ─── NOTIFICATIONS (in-app) ───────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true, 'comment' => 'recipient'],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'leave.requested/payslip.generated/etc'],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'message'      => ['type' => 'TEXT'],
            'data'         => ['type' => 'JSON', 'null' => true],
            'link_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'is_read'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'read_at'      => ['type' => 'DATETIME', 'null' => true],
            'sent_email'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'email_sent_at'=> ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['user_id', 'is_read']);
        $this->forge->addKey(['company_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notifications');

        // ─── EMAIL QUEUE ──────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'to_email'       => ['type' => 'VARCHAR', 'constraint' => 191],
            'to_name'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'subject'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'body_html'      => ['type' => 'MEDIUMTEXT'],
            'attachments'    => ['type' => 'JSON', 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['QUEUED','SENDING','SENT','FAILED'], 'default' => 'QUEUED'],
            'attempts'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_error'     => ['type' => 'TEXT', 'null' => true],
            'sent_at'        => ['type' => 'DATETIME', 'null' => true],
            'scheduled_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->addKey('scheduled_at');
        $this->forge->createTable('email_queue');

        // ─── AUDIT LOGS ──────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'action'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'entity'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'entity_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'before_data'  => ['type' => 'JSON', 'null' => true],
            'after_data'   => ['type' => 'JSON', 'null' => true],
            'metadata'     => ['type' => 'JSON', 'null' => true],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_code'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'created_at']);
        $this->forge->addKey('user_id');
        $this->forge->addKey(['entity', 'entity_id']);
        $this->forge->createTable('audit_logs');

        // ─── LOGIN ATTEMPTS (for brute-force monitoring) ──────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 191],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 45],
            'success'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'reason'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['email', 'created_at']);
        $this->forge->addKey(['ip_address', 'created_at']);
        $this->forge->createTable('login_attempts');

        // ─── SETTINGS (key/value per company) ─────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'key'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'value'        => ['type' => 'TEXT', 'null' => true],
            'data_type'    => ['type' => 'ENUM', 'constraint' => ['STRING','INT','FLOAT','BOOL','JSON'], 'default' => 'STRING'],
            'description'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'key']);
        $this->forge->createTable('settings');
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
        $this->forge->dropTable('login_attempts', true);
        $this->forge->dropTable('audit_logs', true);
        $this->forge->dropTable('email_queue', true);
        $this->forge->dropTable('notifications', true);
    }
}