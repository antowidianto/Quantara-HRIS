<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S1.3 — Self-Service Tenant Signup (Roadmap SaaS).
 *
 * Tabel `signup_attempts` track permintaan signup publik (sebelum verified).
 * Setelah email verified + provisioning sukses, baris ini dihapus / ditandai
 * COMPLETED dan tenant baru sudah punya company_id + user_id sendiri.
 *
 * Juga menambah kolom di `companies` untuk attribution + trial tracking
 * (sumber signup, referrer, trial_ends_at) — penting untuk SaaS metrics
 * funnel & churn analysis.
 *
 * Idempotent: cek tabel + kolom existence supaya aman re-run di tenant
 * existing yang sudah punya migration ini sebagian.
 */
class S1_3_SignupAttempts extends Migration
{
    public function up(): void
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── Tabel signup_attempts ─────────────────────────────────
        if (! $db->tableExists('signup_attempts')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'email'           => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
                'company_name'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => false],
                'admin_name'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'phone'           => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'employees_count_estimate' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Estimasi jumlah karyawan (untuk plan recommendation)'],
                'industry'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'signup_source'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'organic / google_ads / referral / partner / direct'],
                'referrer_code'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'utm_campaign'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'utm_medium'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'utm_source'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'ip_address'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'user_agent'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'status'          => ['type' => 'ENUM', 'constraint' => ['PENDING','VERIFIED','PROVISIONED','EXPIRED','FAILED'],
                    'default' => 'PENDING', 'null' => false],
                'verification_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'verified_at'     => ['type' => 'DATETIME', 'null' => true],
                'provisioned_at'  => ['type' => 'DATETIME', 'null' => true],
                'provisioned_company_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'provisioned_user_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'error_message'   => ['type' => 'TEXT', 'null' => true],
                'expires_at'      => ['type' => 'DATETIME', 'null' => false],
                'created_at'      => ['type' => 'DATETIME', 'null' => false],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('verification_token', 'idx_signup_token');
            $forge->addKey('email', false, false, 'idx_signup_email');
            $forge->addKey(['status', 'expires_at'], false, false, 'idx_signup_status_expires');
            $attributes = $db->DBDriver === 'SQLite3' ? [] : ['ENGINE' => 'InnoDB'];
            $forge->createTable('signup_attempts', true, $attributes);
        }

        // ─── ALTER companies (tambah kolom signup attribution) ─────
        if ($db->tableExists('companies')) {
            $columns = $db->getFieldNames('companies');
            $toAdd = [];
            if (! in_array('trial_ends_at', $columns, true)) {
                $toAdd['trial_ends_at'] = ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Sync dengan subscriptions.trial_ends_at untuk fast filter'];
            }
            if (! in_array('signup_source', $columns, true)) {
                $toAdd['signup_source'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true];
            }
            if (! in_array('referrer_code', $columns, true)) {
                $toAdd['referrer_code'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true];
            }
            if (! in_array('onboarding_completed_at', $columns, true)) {
                $toAdd['onboarding_completed_at'] = ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Saat user first time finish 8-step onboarding'];
            }
            if (! empty($toAdd)) {
                $forge->addColumn('companies', $toAdd);
            }
        }
    }

    public function down(): void
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('signup_attempts')) {
            $forge->dropTable('signup_attempts', true);
        }

        // Tidak drop kolom companies — sengaja preserve data jangan hilang
        // saat rollback. Kalau memang perlu, lakukan manual.
    }
}
