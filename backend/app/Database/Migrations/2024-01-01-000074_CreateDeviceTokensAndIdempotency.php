<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CreateDeviceTokensAndIdempotency — Mobile Prep / MOB-1
 *
 * Dua tabel untuk fondasi mobile app:
 *
 *   1. **device_tokens** — registry FCM/APNs token per user supaya backend
 *      bisa kirim push notification. Satu user boleh punya banyak device
 *      (HP pribadi + HP kerja, atau ganti HP). Token unik per device,
 *      auto-revoke kalau "Sign out all devices".
 *
 *   2. **idempotency_keys** — anti-duplikat untuk POST critical (clock-in,
 *      leave create, reimbursement) dari mobile yang offline. Mobile
 *      generate UUID, kirim di header `Idempotency-Key`. Backend cek;
 *      kalau sudah pernah diproses, return response cached. Sesuai pola
 *      Stripe / RFC draft (Idempotency-Key spec).
 *
 * Index strategy:
 *   - device_tokens: (user_id) untuk push fanout, unique (token) supaya
 *     kalau user re-install app, register ulang akan replace, bukan duplikat.
 *   - idempotency_keys: unique (company_id, key) supaya 2 tenant boleh
 *     pakai key yang sama, expired_at indexed untuk cron cleanup.
 */
class CreateDeviceTokensAndIdempotency extends Migration
{
    public function up(): void
    {
        // ─── device_tokens ───────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true],
            'employee_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            'platform'     => [
                'type' => 'ENUM',
                'constraint' => ['ANDROID', 'IOS', 'WEB'],
                'comment' => 'Source platform for push routing',
            ],
            'token'        => ['type' => 'VARCHAR', 'constraint' => 512, 'comment' => 'FCM token / APNs device token / Web push endpoint'],

            // Device fingerprint (untuk Sign out this device + audit)
            'device_id'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'comment' => 'Device unique ID (Android ID / iOS identifierForVendor)'],
            'device_model'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'os_version'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'app_version'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'locale'        => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'timezone'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],

            'last_seen_at'  => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Updated tiap call /me atau push delivered'],
            'revoked_at'    => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Set saat logout / sign-out-all'],
            'failure_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'Counter FCM 410 / APNs Unregistered → auto-revoke saat ≥ 3'],

            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['token'], 'uniq_dt_token');
        $this->forge->addKey(['user_id', 'revoked_at'], false, false, 'idx_dt_user_active');
        $this->forge->addKey(['company_id', 'platform'], false, false, 'idx_dt_company_platform');
        $this->forge->addKey(['device_id'], false, false, 'idx_dt_device_id');

        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('device_tokens');

        // ─── idempotency_keys ────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            'key_hash'     => ['type' => 'CHAR', 'constraint' => 64, 'comment' => 'SHA-256 dari Idempotency-Key header'],
            'method'       => ['type' => 'VARCHAR', 'constraint' => 10],
            'path'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'SHA-256 dari body untuk deteksi key reuse dengan body beda'],

            'status_code'  => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'response_body'=> ['type' => 'MEDIUMTEXT', 'null' => true],
            'response_headers' => ['type' => 'TEXT', 'null' => true],

            'state'        => [
                'type' => 'ENUM',
                'constraint' => ['IN_FLIGHT', 'COMPLETED', 'FAILED'],
                'default' => 'IN_FLIGHT',
            ],
            'expired_at'   => ['type' => 'DATETIME', 'comment' => 'Default H+24'],

            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'key_hash'], 'uniq_idem_company_key');
        $this->forge->addKey(['expired_at'], false, false, 'idx_idem_expired');

        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('idempotency_keys');
    }

    public function down(): void
    {
        $this->forge->dropTable('idempotency_keys', true);
        $this->forge->dropTable('device_tokens', true);
    }
}