<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S3.4 — Customer Success Tooling.
 *
 * 3 tabel:
 *   1. onboarding_progress       — tenant onboarding step tracking
 *   2. release_announcements     — pop-up "What's New" pop-up per release
 *   3. customer_health_scores    — score retention + churn risk per company
 *
 * Idempotent dengan tableExists guard.
 */
class S3_4_CustomerSuccess extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── onboarding_progress ────────────────────────────────
        if (! $db->tableExists('onboarding_progress')) {
            $forge->addField([
                'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'  => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id'     => ['type' => 'BIGINT', 'unsigned' => true],
                'step_key'    => ['type' => 'VARCHAR', 'constraint' => 64],
                'completed_at'=> ['type' => 'DATETIME', 'null' => true],
                'metadata_json' => ['type' => 'JSON', 'null' => true],
                'created_at'  => ['type' => 'DATETIME', 'null' => true],
                'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['user_id', 'step_key']);
            $forge->addKey(['company_id', 'step_key']);
            $forge->createTable('onboarding_progress');
        }

        // ─── release_announcements ──────────────────────────────
        if (! $db->tableExists('release_announcements')) {
            $forge->addField([
                'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'version'           => ['type' => 'VARCHAR', 'constraint' => 32],
                'title'             => ['type' => 'VARCHAR', 'constraint' => 255],
                'body_html'         => ['type' => 'TEXT'],
                'category'          => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'FEATURE'], // FEATURE|FIX|BREAKING|MAINTENANCE
                'cta_url'           => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
                'cta_label'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'target_plans_json' => ['type' => 'JSON', 'null' => true], // ["STARTER","PRO"] = filter, null = semua
                'published_at'      => ['type' => 'DATETIME', 'null' => true],
                'expires_at'        => ['type' => 'DATETIME', 'null' => true],
                'created_by'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['version']);
            $forge->addKey(['published_at']);
            $forge->createTable('release_announcements');
        }

        // ─── announcement_reads (per user) ──────────────────────
        if (! $db->tableExists('announcement_reads')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'announcement_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id'         => ['type' => 'BIGINT', 'unsigned' => true],
                'read_at'         => ['type' => 'DATETIME'],
                'cta_clicked'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['announcement_id', 'user_id']);
            $forge->createTable('announcement_reads');
        }

        // ─── customer_health_scores ─────────────────────────────
        if (! $db->tableExists('customer_health_scores')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'BIGINT', 'unsigned' => true],
                'score'           => ['type' => 'DECIMAL', 'constraint' => '5,2'], // 0-100
                'tier'            => ['type' => 'VARCHAR', 'constraint' => 16], // GREEN|YELLOW|RED
                'breakdown_json'  => ['type' => 'JSON', 'null' => true],
                'churn_risk'      => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true], // LOW|MEDIUM|HIGH
                'recommendations' => ['type' => 'TEXT', 'null' => true],
                'calculated_at'   => ['type' => 'DATETIME'],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'calculated_at']);
            $forge->addKey(['tier']);
            $forge->createTable('customer_health_scores');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        foreach ([
            'customer_health_scores',
            'announcement_reads',
            'release_announcements',
            'onboarding_progress',
        ] as $t) {
            if ($db->tableExists($t)) {
                $forge->dropTable($t);
            }
        }
    }
}