<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 7 — Notifications Hardening.
 *
 * Tambah:
 *  1. `users.web_push_subscription` (TEXT) — JSON subscription dari
 *     `navigator.serviceWorker.pushManager.subscribe()`.
 *  2. `email_templates` — template per company × event_type dengan
 *     variable substitution (`{{name}}`, `{{leave_dates}}`, dst).
 *     Default global (company_id NULL) dipakai sebagai fallback.
 *  3. `email_queue.next_retry_at` + `last_attempt_at` — untuk exponential
 *     backoff (1m → 5m → 30m default jadwal retry, lihat NotificationService).
 *
 * Migration ini ADDITIVE & idempotent.
 */
class NotificationsHardening extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // Reset cache skema: migrasi sebelumnya (#027) mungkin sudah
        // menambah kolom di koneksi yang sama. getFieldNames() men-cache
        // hasil per-koneksi, jadi tanpa reset kita bisa baca daftar kolom
        // basi → guard idempotent gagal → "Duplicate column". (Di SQLite
        // lama tak terlihat karena alur cache berbeda.)
        $db->resetDataCache();

        // ─── users.web_push_subscription ────────────────────────────
        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            if (! in_array('web_push_subscription', $cols, true)) {
                $forge->addColumn('users', [
                    'web_push_subscription' => [
                        'type' => 'TEXT',
                        'null' => true,
                        'comment' => 'JSON {endpoint, keys{auth, p256dh}} dari pushManager.subscribe()',
                    ],
                ]);
            }
        }

        // ─── email_templates ────────────────────────────────────────
        if (! $db->tableExists('email_templates')) {
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'company_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                    'comment'  => 'NULL = template global default',
                ],
                'event_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'comment'    => 'Mis. leave.requested, payslip.published, sp.issued',
                ],
                'subject' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'comment'    => 'Subject email; support {{var}} substitution',
                ],
                'body_html' => [
                    'type'    => 'TEXT',
                    'comment' => 'HTML body; support {{var}} substitution',
                ],
                'is_active' => [
                    'type'     => 'TINYINT',
                    'unsigned' => true,
                    'default'  => 1,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'event_type']);
            $forge->addKey('event_type');
            $forge->createTable('email_templates');
        }

        // ─── email_queue: next_retry_at + last_attempt_at ───────────
        if ($db->tableExists('email_queue')) {
            $cols = $db->getFieldNames('email_queue');
            $additions = [];
            if (! in_array('next_retry_at', $cols, true)) {
                $additions['next_retry_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'Earliest time to retry (exponential backoff)',
                ];
            }
            if (! in_array('last_attempt_at', $cols, true)) {
                $additions['last_attempt_at'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'comment' => 'Timestamp percobaan terakhir',
                ];
            }
            if (! empty($additions)) {
                $forge->addColumn('email_queue', $additions);
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('email_templates')) {
            $forge->dropTable('email_templates', true);
        }
        if ($db->tableExists('email_queue')) {
            $cols = $db->getFieldNames('email_queue');
            foreach (['last_attempt_at', 'next_retry_at'] as $col) {
                if (in_array($col, $cols, true)) {
                    $forge->dropColumn('email_queue', $col);
                }
            }
        }
        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            if (in_array('web_push_subscription', $cols, true)) {
                $forge->dropColumn('users', 'web_push_subscription');
            }
        }
    }
}