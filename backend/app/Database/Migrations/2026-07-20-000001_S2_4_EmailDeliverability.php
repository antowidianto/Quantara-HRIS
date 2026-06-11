<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S2.4 — Email Deliverability columns + bounce/complaint tracking.
 *
 * Tujuan:
 *   1. ALTER `users` add bounce/complaint columns supaya bisa skip
 *      ke alamat yang sudah hard-bounced (anti waste sender reputation).
 *   2. Tabel `email_events` mencatat semua webhook event SES/Postmark/Sendgrid
 *      untuk audit + analytics deliverability rate.
 *
 * Idempotent dengan fieldExists/tableExists guard.
 */
class S2_4_EmailDeliverability extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── ALTER users ───────────────────────────────────────
        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            $additions = [];

            if (! in_array('email_bounced_at', $cols, true)) {
                $additions['email_bounced_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! in_array('email_bounce_reason', $cols, true)) {
                $additions['email_bounce_reason'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
            }
            if (! in_array('email_bounce_type', $cols, true)) {
                $additions['email_bounce_type'] = ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true]; // HARD|SOFT
            }
            if (! in_array('email_complaint_at', $cols, true)) {
                $additions['email_complaint_at'] = ['type' => 'DATETIME', 'null' => true];
            }
            if (! in_array('unsubscribed_categories_json', $cols, true)) {
                $additions['unsubscribed_categories_json'] = ['type' => 'JSON', 'null' => true];
            }

            if (! empty($additions)) {
                $forge->addColumn('users', $additions);
            }
        }

        // ─── Tabel email_events ────────────────────────────────
        if (! $db->tableExists('email_events')) {
            $forge->addField([
                'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'user_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'provider'            => ['type' => 'VARCHAR', 'constraint' => 32], // ses|postmark|sendgrid
                'event'               => ['type' => 'VARCHAR', 'constraint' => 32], // BOUNCE|COMPLAINT|DELIVERY|OPEN|CLICK
                'email'               => ['type' => 'VARCHAR', 'constraint' => 191],
                'bounce_type'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
                'reason'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'provider_message_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'occurred_at'         => ['type' => 'DATETIME', 'null' => true],
                'raw_json'            => ['type' => 'JSON', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['email']);
            $forge->addKey(['provider', 'event']);
            $forge->addKey(['occurred_at']);
            $forge->createTable('email_events');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('email_events')) {
            $forge->dropTable('email_events');
        }

        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            foreach ([
                'email_bounced_at',
                'email_bounce_reason',
                'email_bounce_type',
                'email_complaint_at',
                'unsubscribed_categories_json',
            ] as $col) {
                if (in_array($col, $cols, true)) {
                    $forge->dropColumn('users', $col);
                }
            }
        }
    }
}