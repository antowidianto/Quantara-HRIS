<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint Mobile M7 — Push localization + DND quiet hours.
 *
 * Dua perubahan kecil ke tabel existing:
 *
 *   1. `users.quiet_hours_start` (TIME nullable) +
 *      `users.quiet_hours_end` (TIME nullable):
 *      Window mute push notification per user. Kalau keduanya null
 *      → tidak ada quiet hour. Kalau end < start → window malam yang
 *      cross midnight (mis. 22:00→06:00).
 *
 *   2. Tabel baru `notification_locale_strings`:
 *      Translasi (notif_type, locale) → title/body template.
 *      Backend pakai ini saat fanout untuk override default copy
 *      sesuai locale device target. Kosong → fallback ke template
 *      hardcoded di NotificationService (Indonesian default).
 *
 * Why "tabel" bukan "config file":
 *   - HR / ops mau bisa edit copy tanpa deploy.
 *   - Mendukung multi-tenant kalau di kemudian hari client A & B mau
 *     wording berbeda (saat ini company_id=null = global default).
 *
 * Tidak menyentuh `device_tokens` karena `locale` sudah ada di tabel
 * itu sejak migration #074.
 */
class AddPushLocaleAndQuietHours extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('users')) {
            $userFields = $db->getFieldNames('users');
            $missing = [];
            if (! in_array('quiet_hours_start', $userFields, true)) {
                $missing['quiet_hours_start'] = [
                    'type'    => 'TIME',
                    'null'    => true,
                    'comment' => 'Mulai jam DND (mute push). Null = tidak aktif.',
                ];
            }
            if (! in_array('quiet_hours_end', $userFields, true)) {
                $missing['quiet_hours_end'] = [
                    'type'    => 'TIME',
                    'null'    => true,
                    'comment' => 'Akhir jam DND. Kalau end < start = cross midnight.',
                ];
            }
            if (! empty($missing)) {
                $forge->addColumn('users', $missing);
            }
        }

        if (! $db->tableExists('notification_locale_strings')) {
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
                    'comment'  => 'Null = global default.',
                ],
                'notif_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'locale'     => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => false,
                    'comment'  => "id | en (lihat mobile/src/i18n)"],
                'title'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
                'body'       => ['type' => 'TEXT', 'null' => false],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'notif_type', 'locale']);
            $forge->addKey(['notif_type', 'locale']);
            $forge->createTable('notification_locale_strings', true);
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('users')) {
            $userFields = $db->getFieldNames('users');
            foreach (['quiet_hours_start', 'quiet_hours_end'] as $col) {
                if (in_array($col, $userFields, true)) {
                    $forge->dropColumn('users', $col);
                }
            }
        }

        if ($db->tableExists('notification_locale_strings')) {
            $forge->dropTable('notification_locale_strings', true);
        }
    }
}