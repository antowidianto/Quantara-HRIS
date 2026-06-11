<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S1.6 — Dunning & Churn Management.
 *
 * Tabel `dunning_actions` log setiap action yang dilakukan oleh
 * BillingDunning command (cron daily). Action types:
 *   - REMINDER_FRIENDLY (H+1)
 *   - WARNING (H+3)
 *   - SUSPEND_NOTICE (H+7)
 *   - SUSPENDED (H+10)
 *   - HARD_CANCELLED (H+30)
 *
 * Idempotent dengan tableExists guard.
 */
class S1_6_DunningActions extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if (! $db->tableExists('dunning_actions')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'subscription_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'company_id'      => ['type' => 'BIGINT', 'unsigned' => true],
                'action_type'     => ['type' => 'VARCHAR', 'constraint' => 32], // REMINDER_FRIENDLY|WARNING|SUSPEND_NOTICE|SUSPENDED|HARD_CANCELLED
                'days_overdue'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'channel'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true], // email|whatsapp|inapp|slack
                'success'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'error_message'   => ['type' => 'TEXT', 'null' => true],
                'metadata_json'   => ['type' => 'JSON', 'null' => true],
                'sent_at'         => ['type' => 'DATETIME'],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['subscription_id', 'action_type']);
            $forge->addKey(['company_id', 'sent_at']);
            $forge->addKey(['sent_at']);
            $forge->createTable('dunning_actions');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('dunning_actions')) {
            $forge->dropTable('dunning_actions');
        }
    }
}