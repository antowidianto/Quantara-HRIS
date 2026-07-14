<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add a provider-native, race-safe deduplication key to email webhooks. */
class AddEmailEventDeduplication extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('email_events')) {
            return;
        }

        $forge = \Config\Database::forge();
        if (! $db->fieldExists('provider_event_key', 'email_events')) {
            $forge->addColumn('email_events', [
                'provider_event_key' => [
                    'type'       => 'CHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'provider_message_id',
                    'comment'    => 'SHA-256 provider event identity for webhook deduplication',
                ],
            ]);
        }

        try {
            $forge->addKey(
                ['provider', 'provider_event_key'],
                false,
                true,
                'uniq_email_provider_event'
            );
            $forge->processIndexes('email_events');
        } catch (\Throwable) {
            // Safe for environments where the named index already exists.
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('email_events') || ! $db->fieldExists('provider_event_key', 'email_events')) {
            return;
        }

        $forge = \Config\Database::forge();
        try {
            $forge->dropKey('email_events', 'uniq_email_provider_event');
        } catch (\Throwable) {
            // Some drivers drop the index together with the column.
        }
        $forge->dropColumn('email_events', 'provider_event_key');
    }
}
