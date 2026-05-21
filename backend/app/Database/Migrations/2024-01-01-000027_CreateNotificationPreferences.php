<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 4 — Multi-channel notifications (item K).
 *
 * Tabel user_notification_preferences menyimpan toggle channel per user
 * per notif type. Tanpa baris di tabel ini, fallback ke default per type
 * (didefinisikan di NotificationService).
 *
 * Skema:
 *   user_id      → users.id
 *   notif_type   → string slug (mis. 'leave.requested', 'overtime.approved')
 *                  '*' = default untuk semua tipe yang tidak punya override khusus
 *   channel      → 'in_app' | 'email' | 'whatsapp' | 'web_push'
 *   enabled      → 0/1
 *
 * Composite PK (user_id, notif_type, channel) supaya tidak duplikat dan UPSERT cepat.
 *
 * Lihat docs/AUDIT.md item K.
 */
class CreateNotificationPreferences extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if (! $db->tableExists('user_notification_preferences')) {
            $forge->addField([
                'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'notif_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false,
                    'comment'  => "Slug notif type. '*' = default fallback."],
                'channel'    => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false,
                    'comment'  => 'in_app | email | whatsapp | web_push'],
                'enabled'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey(['user_id', 'notif_type', 'channel']);
            $forge->addKey('user_id');
            $forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('user_notification_preferences', true);
        }

        // Tambah kolom phone & web push subscription di users untuk channel WA & WebPush.
        // Dibuat NULLABLE supaya backward compat.
        if ($db->tableExists('users')) {
            $userFields = $db->getFieldNames('users');
            $missing = [];
            if (! in_array('whatsapp_number', $userFields, true)) {
                $missing['whatsapp_number'] = [
                    'type'    => 'VARCHAR',
                    'constraint' => 32,
                    'null'    => true,
                    'after'   => 'name',
                    'comment' => 'Nomor WA (08xx atau +62xx). Untuk WhatsAppChannel.',
                ];
            }
            if (! in_array('web_push_subscription', $userFields, true)) {
                $missing['web_push_subscription'] = [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'after'   => 'whatsapp_number',
                    'comment' => 'JSON web push subscription (endpoint+keys). Untuk WebPushChannel.',
                ];
            }
            if (! empty($missing)) {
                $forge->addColumn('users', $missing);
            }
        }

        // Email queue: tambah kolom channel + retry_after untuk schedule retry.
        // Tabel email_queue dipakai bersama oleh EmailChannel sekarang.
        if ($db->tableExists('email_queue')) {
            $eqFields = $db->getFieldNames('email_queue');
            $eqMissing = [];
            if (! in_array('retry_after', $eqFields, true)) {
                $eqMissing['retry_after'] = [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'after'   => 'attempts',
                    'comment' => 'Earliest time email boleh di-retry. Diisi exponential backoff.',
                ];
            }
            if (! in_array('last_error', $eqFields, true)) {
                $eqMissing['last_error'] = [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'after'   => 'status',
                    'comment' => 'Pesan error terakhir saat send gagal.',
                ];
            }
            if (! empty($eqMissing)) {
                $forge->addColumn('email_queue', $eqMissing);
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('user_notification_preferences')) {
            $forge->dropTable('user_notification_preferences', true);
        }

        if ($db->tableExists('users')) {
            $userFields = $db->getFieldNames('users');
            foreach (['whatsapp_number', 'web_push_subscription'] as $col) {
                if (in_array($col, $userFields, true)) {
                    $forge->dropColumn('users', $col);
                }
            }
        }

        if ($db->tableExists('email_queue')) {
            $eqFields = $db->getFieldNames('email_queue');
            foreach (['retry_after', 'last_error'] as $col) {
                if (in_array($col, $eqFields, true)) {
                    $forge->dropColumn('email_queue', $col);
                }
            }
        }
    }
}