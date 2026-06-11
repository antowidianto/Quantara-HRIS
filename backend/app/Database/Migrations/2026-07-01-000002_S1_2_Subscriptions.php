<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S1.2 — Subscription Lifecycle Tables (Roadmap SaaS)
 *
 * Tabel untuk lifecycle management subscription per tenant:
 *  - subscriptions       : 1:1 dengan companies (active subscription)
 *  - subscription_events : audit trail per state transition
 *
 * Status lifecycle:
 *   TRIAL → ACTIVE → PAST_DUE → CANCELLED → EXPIRED
 *           ↑                  ↓
 *           └──── REACTIVATED ──┘
 *
 * Idempotent: cek tableExists sebelum createTable.
 */
class S1_2_Subscriptions extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('subscriptions')) {
            $this->forge->addField([
                'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'            => ['type' => 'INT', 'unsigned' => true],
                'plan_id'               => ['type' => 'INT', 'unsigned' => true],
                'status'                => ['type' => 'VARCHAR', 'constraint' => 24,
                    'default' => 'TRIAL',
                    'comment' => 'TRIAL|ACTIVE|PAST_DUE|CANCELLED|EXPIRED|SUSPENDED'],
                'billing_cycle'         => ['type' => 'VARCHAR', 'constraint' => 16,
                    'default' => 'MONTHLY',
                    'comment' => 'MONTHLY|YEARLY|CUSTOM (untuk enterprise contract)'],
                'started_at'            => ['type' => 'DATETIME'],
                'current_period_start'  => ['type' => 'DATE'],
                'current_period_end'    => ['type' => 'DATE'],
                'trial_ends_at'         => ['type' => 'DATETIME', 'null' => true],
                'cancelled_at'          => ['type' => 'DATETIME', 'null' => true],
                'cancel_reason'         => ['type' => 'TEXT', 'null' => true],
                'cancel_feedback_score' => ['type' => 'TINYINT', 'null' => true,
                    'comment' => '1-5, untuk churn analysis'],
                'auto_renew'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                // Enterprise contract data
                'po_number'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Purchase Order number untuk enterprise B2B'],
                'contract_start'        => ['type' => 'DATE', 'null' => true],
                'contract_end'          => ['type' => 'DATE', 'null' => true],
                'custom_price'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true,
                    'comment' => 'Override price dari plan untuk enterprise quote'],
                'notes_internal'        => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan internal CSM/sales, tidak ditampilkan ke tenant'],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('company_id'); // 1 active subscription per company
            $this->forge->addKey('status');
            $this->forge->addKey('current_period_end');
            $this->forge->addKey('trial_ends_at');
            $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('plan_id', 'subscription_plans', 'id', 'CASCADE', 'RESTRICT');
            $this->forge->createTable('subscriptions');
        }

        if (! $db->tableExists('subscription_events')) {
            $this->forge->addField([
                'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'subscription_id'       => ['type' => 'INT', 'unsigned' => true],
                'company_id'            => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Denormalized untuk index per tenant'],
                'event_type'            => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'CREATED|UPGRADED|DOWNGRADED|RENEWED|CANCELLED|REACTIVATED|EXPIRED|SUSPENDED|UNSUSPENDED|TRIAL_EXTENDED'],
                'from_plan_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'to_plan_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'from_status'           => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
                'to_status'             => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
                'metadata_json'         => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Detail perubahan: prorated_amount, reason, by_user_id, dll'],
                'triggered_by'          => ['type' => 'VARCHAR', 'constraint' => 24,
                    'default' => 'SYSTEM',
                    'comment' => 'SYSTEM|USER|ADMIN|WEBHOOK|CRON'],
                'triggered_by_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'            => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('subscription_id');
            $this->forge->addKey(['company_id', 'created_at']);
            $this->forge->addKey('event_type');
            $this->forge->addForeignKey('subscription_id', 'subscriptions', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('subscription_events');
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        foreach (['subscription_events', 'subscriptions'] as $t) {
            if ($db->tableExists($t)) {
                $this->forge->dropTable($t, true);
            }
        }
    }
}