<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint9QuickWins (Sprint 9 / H1.3, H1.4, H1.8 partial, H1.9).
 *
 * Migration kombinasi untuk:
 *
 *   1. H1.3 — Conditional Approval per nilai/level/department:
 *      - approval_chain_steps: tambah `condition_type`, `condition_value_json`
 *        (selain field `condition_field/op/value` lama yang sudah ada).
 *
 *   2. H1.4 — Time-in-Stage Tracking Recruitment:
 *      - Tabel baru `candidate_stage_history` (candidate_id, stage,
 *        entered_at, exited_at, duration_hours, by_user_id, note).
 *
 *   3. H1.8 — THR Planning Dashboard: tidak perlu migration baru
 *      (semua data sudah ada di employees + payroll_components).
 *
 *   4. H1.9 — WhatsApp Notification:
 *      - notification_preferences: pastikan support channel WHATSAPP via
 *        kolom JSON `channels` (existing) atau ENUM extension.
 *      - Tabel baru `whatsapp_provider_configs` (company_id, provider,
 *        api_key_encrypted, sender_id, is_active).
 *      - Tabel baru `whatsapp_send_log` untuk rate limiting + audit.
 *
 * Idempotent: cek tabel/kolom sebelum tambah.
 */
class Sprint9QuickWins extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        // ─── H1.3: Conditional approval ──────────────────────────────
        if ($db->tableExists('approval_chain_steps')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('approval_chain_steps'));
            $newCols = [];
            if (! in_array('condition_type', $cols, true)) {
                $newCols['condition_type'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'null'       => true,
                    'comment'    => 'H1.3: amount_gt, amount_lte, employee_level_gte, department_in, dst.',
                ];
            }
            if (! in_array('condition_value_json', $cols, true)) {
                $newCols['condition_value_json'] = [
                    'type'    => 'JSON',
                    'null'    => true,
                    'comment' => 'H1.3: payload kondisi (mis. {"value":5000000} atau {"in":[1,2,3]})',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('approval_chain_steps', $newCols);
            }
        }

        // ─── H1.4: Candidate stage history ───────────────────────────
        if (! $db->tableExists('candidate_stage_history')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'candidate_id'    => ['type' => 'INT', 'unsigned' => true],
                'stage'           => ['type' => 'VARCHAR', 'constraint' => 32],
                'entered_at'      => ['type' => 'DATETIME'],
                'exited_at'       => ['type' => 'DATETIME', 'null' => true],
                'duration_hours'  => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true,
                    'comment' => 'Diisi saat exited_at di-set'],
                'by_user_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User HR yang mengubah stage'],
                'note'            => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['candidate_id', 'entered_at']);
            $forge->addKey(['company_id', 'stage']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('candidate_id', 'candidates', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('candidate_stage_history');
        }

        // ─── H1.9: WhatsApp provider config ──────────────────────────
        if (! $db->tableExists('whatsapp_provider_configs')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'provider' => [
                    'type'       => 'ENUM',
                    'constraint' => ['TWILIO', 'WATI', 'FONNTE', 'WHATSAPP_BUSINESS_API'],
                    'default'    => 'FONNTE',
                ],
                'api_key_encrypted' => ['type' => 'TEXT',
                    'comment' => 'AES-256-GCM via existing crypto helper'],
                'sender_id'         => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true,
                    'comment' => 'Nomor pengirim atau ID provider'],
                'webhook_secret'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'is_active'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'last_test_at'      => ['type' => 'DATETIME', 'null' => true],
                'last_test_status'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id'], 'uniq_wa_company');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('whatsapp_provider_configs');
        }

        // ─── H1.9: WhatsApp send log (untuk rate limit + audit) ──────
        if (! $db->tableExists('whatsapp_send_log')) {
            $forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'user_id'         => ['type' => 'INT', 'unsigned' => true],
                'phone_number'    => ['type' => 'VARCHAR', 'constraint' => 20],
                'event'           => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'mis. approval.request, payslip.ready'],
                'message_preview' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => '160 char pertama dari pesan, untuk debugging'],
                'provider'        => ['type' => 'VARCHAR', 'constraint' => 32],
                'provider_message_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => ['QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'RATE_LIMITED'],
                    'default'    => 'QUEUED',
                ],
                'error_message'   => ['type' => 'TEXT', 'null' => true],
                'sent_at'         => ['type' => 'DATETIME', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'user_id', 'created_at']);
            $forge->addKey(['status', 'created_at']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('whatsapp_send_log');
        }

        // Tambah kolom phone_verified_at di users untuk WhatsApp opt-in.
        if ($db->tableExists('users')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('users'));
            $newCols = [];
            if (! in_array('phone_verified_at', $cols, true)) {
                $newCols['phone_verified_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'H1.9: verifikasi nomor WA berhasil',
                ];
            }
            if (! in_array('whatsapp_enabled', $cols, true)) {
                $newCols['whatsapp_enabled'] = [
                    'type'     => 'TINYINT',
                    'unsigned' => true,
                    'default'  => 0,
                    'comment'  => 'H1.9: opt-in user untuk channel WhatsApp',
                ];
            }
            if (! empty($newCols)) {
                $forge->addColumn('users', $newCols);
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        if ($db->tableExists('whatsapp_send_log')) {
            $forge->dropTable('whatsapp_send_log', true);
        }
        if ($db->tableExists('whatsapp_provider_configs')) {
            $forge->dropTable('whatsapp_provider_configs', true);
        }
        if ($db->tableExists('candidate_stage_history')) {
            $forge->dropTable('candidate_stage_history', true);
        }

        if ($db->tableExists('approval_chain_steps')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('approval_chain_steps'));
            foreach (['condition_type', 'condition_value_json'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('approval_chain_steps', $c);
            }
        }

        if ($db->tableExists('users')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('users'));
            foreach (['phone_verified_at', 'whatsapp_enabled'] as $c) {
                if (in_array($c, $cols, true)) $forge->dropColumn('users', $c);
            }
        }
    }
}