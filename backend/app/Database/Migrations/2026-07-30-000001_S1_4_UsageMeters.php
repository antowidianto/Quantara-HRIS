<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S1.4 — Quota Enforcement & Usage Metering.
 *
 * Tujuan:
 *   - Track usage per company per meter_key (employees, storage_mb,
 *     payslips_generated, whatsapp_sent, api_calls, dll)
 *   - Setiap action di endpoint penting → increment meter
 *   - Plan limits di-cek sebelum action: kalau exceed → 422 QUOTA_EXCEEDED
 *
 * Tabel:
 *   1. usage_meters       — counter per (company, meter_key) — current state
 *   2. usage_events       — audit log granular setiap increment
 *
 * Plan limits di-resolve via FeatureGateService (feature_flags rows
 * dengan key prefix 'limit.*' value JSON {"value": 50, "unit": "count"}).
 *
 * Idempotent dengan tableExists guard.
 */
class S1_4_UsageMeters extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── usage_meters: current counter per (company, meter) ──
        if (! $db->tableExists('usage_meters')) {
            $forge->addField([
                'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'BIGINT', 'unsigned' => true],
                'meter_key'      => ['type' => 'VARCHAR', 'constraint' => 64],
                'current_value'  => ['type' => 'BIGINT', 'default' => 0],
                'limit_value'    => ['type' => 'BIGINT', 'null' => true], // cached snapshot dari plan
                'period_start'   => ['type' => 'DATETIME', 'null' => true],
                'period_end'     => ['type' => 'DATETIME', 'null' => true],
                'last_reset_at'  => ['type' => 'DATETIME', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'meter_key']);
            $forge->addKey(['period_end']); // untuk cron monthly reset
            $forge->createTable('usage_meters');
        }

        // ─── usage_events: log granular setiap increment ─────────
        if (! $db->tableExists('usage_events')) {
            $forge->addField([
                'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'BIGINT', 'unsigned' => true],
                'user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'meter_key'    => ['type' => 'VARCHAR', 'constraint' => 64],
                'delta'        => ['type' => 'BIGINT', 'default' => 1],
                'source'       => ['type' => 'VARCHAR', 'constraint' => 64], // 'api:employees.create', 'cron:reset', dll
                'context_json' => ['type' => 'JSON', 'null' => true],
                'occurred_at'  => ['type' => 'DATETIME'],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'meter_key', 'occurred_at']);
            $forge->addKey(['occurred_at']); // untuk archival cron
            $forge->createTable('usage_events');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('usage_events')) {
            $forge->dropTable('usage_events');
        }
        if ($db->tableExists('usage_meters')) {
            $forge->dropTable('usage_meters');
        }
    }
}