<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S1.1 — Subscription Plans & Feature Flags (Roadmap SaaS)
 *
 * 4 tabel inti:
 *  - subscription_plans     : katalog plan (FREE_TRIAL, STARTER, PRO, ENTERPRISE)
 *  - plan_features          : matrix fitur per plan + quota
 *  - feature_flags          : registry feature key (master)
 *  - company_feature_overrides : override per tenant (mis. trial fitur baru)
 *
 * Idempotent: cek tableExists sebelum createTable.
 */
class S1_1_SubscriptionPlansAndFeatureFlags extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        // ─── SUBSCRIPTION PLANS ──────────────────────────────────────
        if (! $db->tableExists('subscription_plans')) {
            $this->forge->addField([
                'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'code'                  => ['type' => 'VARCHAR', 'constraint' => 32, 'comment' => 'FREE_TRIAL|STARTER|PRO|ENTERPRISE'],
                'name'                  => ['type' => 'VARCHAR', 'constraint' => 100],
                'description'           => ['type' => 'TEXT', 'null' => true],
                'price_monthly'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'price_yearly'          => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Discount hardcoded di sini, biasanya 10×monthly = setahun gratis 2 bulan'],
                'currency'              => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'IDR'],
                'max_employees'         => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = unlimited (Enterprise)'],
                'max_companies'         => ['type' => 'INT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Untuk multi-PT/holding di plan Enterprise'],
                'max_storage_gb'        => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'max_api_calls_month'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'trial_days'            => ['type' => 'INT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Hanya FREE_TRIAL plan yang punya nilai > 0'],
                'is_public'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1,
                    'comment' => '0 = invitation-only / custom enterprise quote'],
                'is_active'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'sort_order'            => ['type' => 'INT', 'default' => 0],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('code');
            $this->forge->addKey('is_active');
            $this->forge->createTable('subscription_plans');
        }

        // ─── FEATURE FLAGS (master registry) ─────────────────────────
        if (! $db->tableExists('feature_flags')) {
            $this->forge->addField([
                'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'key'                   => ['type' => 'VARCHAR', 'constraint' => 80,
                    'comment' => 'Format: module.feature mis. payroll.simulate, ai.copilot, recruitment.online_test'],
                'category'              => ['type' => 'VARCHAR', 'constraint' => 40,
                    'comment' => 'PAYROLL|RECRUITMENT|TALENT|INTELLIGENCE|COMPLIANCE|INTEGRATION|...'],
                'description'           => ['type' => 'TEXT', 'null' => true],
                'default_enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1,
                    'comment' => 'Default state kalau plan tidak override'],
                'is_paid_only'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => '1 = bukan untuk plan FREE_TRIAL'],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('key');
            $this->forge->addKey('category');
            $this->forge->createTable('feature_flags');
        }

        // ─── PLAN FEATURES (matrix plan × feature) ───────────────────
        if (! $db->tableExists('plan_features')) {
            $this->forge->addField([
                'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'plan_id'               => ['type' => 'INT', 'unsigned' => true],
                'feature_key'           => ['type' => 'VARCHAR', 'constraint' => 80],
                'is_included'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'quota_value'           => ['type' => 'INT', 'null' => true,
                    'comment' => 'NULL = unlimited (selama is_included=1)'],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['plan_id', 'feature_key']);
            $this->forge->addKey('feature_key');
            $this->forge->addForeignKey('plan_id', 'subscription_plans', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('plan_features');
        }

        // ─── COMPANY FEATURE OVERRIDES ───────────────────────────────
        // Untuk kasus: trial fitur baru, custom enterprise contract,
        // grandfather pricing, force-disable saat insiden security.
        if (! $db->tableExists('company_feature_overrides')) {
            $this->forge->addField([
                'id'                    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'            => ['type' => 'INT', 'unsigned' => true],
                'feature_key'           => ['type' => 'VARCHAR', 'constraint' => 80],
                'is_enabled'            => ['type' => 'TINYINT', 'constraint' => 1],
                'quota_value'           => ['type' => 'INT', 'null' => true,
                    'comment' => 'Override quota dari plan; NULL = pakai plan'],
                'reason'                => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Wajib diisi audit trail super admin'],
                'expires_at'            => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'NULL = permanent override'],
                'created_by_user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['company_id', 'feature_key']);
            $this->forge->addKey('expires_at');
            $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('company_feature_overrides');
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        foreach (['company_feature_overrides', 'plan_features', 'feature_flags', 'subscription_plans'] as $t) {
            if ($db->tableExists($t)) {
                $this->forge->dropTable($t, true);
            }
        }
    }
}