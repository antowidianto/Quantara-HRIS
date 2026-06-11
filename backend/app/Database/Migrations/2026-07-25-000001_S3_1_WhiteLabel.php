<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S3.1 — White-Labeling per Tenant.
 *
 * Tujuan:
 *   1. ALTER `companies` add kolom branding (logo, color, custom domain).
 *   2. Tabel `email_template_overrides` — tenant boleh override subject/body
 *      template email default (welcome, password reset, payslip notif, dll).
 *
 * Feature gate `white_label.enabled` di plan ENTERPRISE only — lihat
 * SubscriptionPlanSeeder. FeatureGateFilter akan blok endpoint update
 * branding kalau plan-nya bukan ENTERPRISE.
 *
 * Idempotent dengan tableExists/fieldExists guard.
 */
class S3_1_WhiteLabel extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── ALTER companies ───────────────────────────────────
        if ($db->tableExists('companies')) {
            $cols = $db->getFieldNames('companies');
            $additions = [];

            if (! in_array('brand_logo_path', $cols, true)) {
                $additions['brand_logo_path'] = ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true];
            }
            if (! in_array('brand_favicon_path', $cols, true)) {
                $additions['brand_favicon_path'] = ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true];
            }
            if (! in_array('brand_primary_color', $cols, true)) {
                $additions['brand_primary_color'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true]; // #RRGGBB
            }
            if (! in_array('brand_secondary_color', $cols, true)) {
                $additions['brand_secondary_color'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true];
            }
            if (! in_array('brand_login_bg_path', $cols, true)) {
                $additions['brand_login_bg_path'] = ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true];
            }
            if (! in_array('brand_email_footer_html', $cols, true)) {
                $additions['brand_email_footer_html'] = ['type' => 'TEXT', 'null' => true];
            }
            if (! in_array('custom_domain', $cols, true)) {
                $additions['custom_domain'] = ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true];
            }
            if (! in_array('custom_domain_verified_at', $cols, true)) {
                $additions['custom_domain_verified_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! in_array('custom_domain_verification_token', $cols, true)) {
                $additions['custom_domain_verification_token'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true];
            }
            if (! in_array('brand_app_name', $cols, true)) {
                $additions['brand_app_name'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true]; // override "Quantara HRIS"
            }
            if (! in_array('brand_support_email', $cols, true)) {
                $additions['brand_support_email'] = ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true];
            }

            if (! empty($additions)) {
                $forge->addColumn('companies', $additions);
            }

            // Add unique index on custom_domain (kalau belum ada)
            try {
                $db->query('CREATE UNIQUE INDEX idx_companies_custom_domain ON companies (custom_domain)');
            } catch (\Throwable $e) {
                // Index sudah ada / engine tidak support — abaikan
            }
        }

        // ─── Tabel email_template_overrides ────────────────────
        if (! $db->tableExists('email_template_overrides')) {
            $forge->addField([
                'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'template_key' => ['type' => 'VARCHAR', 'constraint' => 64], // mis. 'welcome', 'password_reset'
                'subject'    => ['type' => 'VARCHAR', 'constraint' => 255],
                'html_body'  => ['type' => 'MEDIUMTEXT'],
                'text_body'  => ['type' => 'MEDIUMTEXT', 'null' => true],
                'variables_doc' => ['type' => 'TEXT', 'null' => true], // doc variabel yg available
                'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'updated_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'template_key']);
            $forge->addKey(['template_key']);
            $forge->createTable('email_template_overrides');
        }

        // ─── Tabel branding_audit_log (lightweight) ────────────
        // Track perubahan branding untuk compliance + rollback.
        if (! $db->tableExists('branding_audit_log')) {
            $forge->addField([
                'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'action'     => ['type' => 'VARCHAR', 'constraint' => 64], // BRANDING_UPDATED, DOMAIN_VERIFIED
                'before_json'=> ['type' => 'JSON', 'null' => true],
                'after_json' => ['type' => 'JSON', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'created_at']);
            $forge->createTable('branding_audit_log');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('branding_audit_log')) {
            $forge->dropTable('branding_audit_log');
        }
        if ($db->tableExists('email_template_overrides')) {
            $forge->dropTable('email_template_overrides');
        }
        if ($db->tableExists('companies')) {
            $cols = $db->getFieldNames('companies');
            try {
                $db->query('DROP INDEX idx_companies_custom_domain ON companies');
            } catch (\Throwable $e) {
                // ignore
            }
            foreach ([
                'brand_logo_path',
                'brand_favicon_path',
                'brand_primary_color',
                'brand_secondary_color',
                'brand_login_bg_path',
                'brand_email_footer_html',
                'custom_domain',
                'custom_domain_verified_at',
                'custom_domain_verification_token',
                'brand_app_name',
                'brand_support_email',
            ] as $col) {
                if (in_array($col, $cols, true)) {
                    $forge->dropColumn('companies', $col);
                }
            }
        }
    }
}