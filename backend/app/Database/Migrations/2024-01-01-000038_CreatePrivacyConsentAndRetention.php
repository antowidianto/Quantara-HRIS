<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 4 — UU PDP (UU 27/2022) compliance.
 *
 * Tabel & kolom yang ditambah:
 *
 *   1. `privacy_consents` — log persetujuan user atas pengumpulan &
 *      pemrosesan data pribadi. UU PDP Pasal 22 mewajibkan persetujuan
 *      tertulis dari Subjek Data; tabel ini menyimpan jejak versi kebijakan
 *      yang disetujui + timestamp + IP saat persetujuan diberikan.
 *
 *   2. `data_deletion_requests` — workflow self-service untuk hak
 *      penghapusan data (Pasal 8 huruf f). HR review dan setujui/tolak.
 *      Saat disetujui, command anonimisasi dijalankan untuk record
 *      ex-karyawan (anonimkan PII, simpan record finansial sesuai retensi
 *      pajak 5 tahun).
 *
 *   3. Kolom `users.consent_version_accepted` + `users.consent_at` — cache
 *      versi terakhir yang disetujui supaya bisa cek tanpa join (untuk
 *      banner "kebijakan diperbarui, mohon persetujuan").
 *
 * Migration ini ADDITIVE & idempotent.
 */
class CreatePrivacyConsentAndRetention extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── privacy_consents ───────────────────────────────────────
        if (! $db->tableExists('privacy_consents')) {
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'user_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                ],
                'company_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'consent_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'comment'    => 'PRIVACY_POLICY|MARKETING|DATA_SHARING|BIOMETRIC|...'
                ],
                'version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'comment'    => 'Versi kebijakan yang disetujui (mis. "2026-01-01" / "v1.2")',
                ],
                'granted' => [
                    'type'       => 'TINYINT',
                    'unsigned'   => true,
                    'default'    => 1,
                    'comment'    => '1=setuju, 0=tolak/cabut',
                ],
                'ip_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'user_agent' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['user_id', 'consent_type', 'created_at']);
            $forge->createTable('privacy_consents');
        }

        // ─── data_deletion_requests ─────────────────────────────────
        if (! $db->tableExists('data_deletion_requests')) {
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'user_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                ],
                'company_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'reason' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'PENDING',
                    'comment'    => 'PENDING|APPROVED|REJECTED|COMPLETED|CANCELLED',
                ],
                'reviewed_by' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'reviewed_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'completed_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'comment' => 'Tanggal anonimisasi dijalankan',
                ],
                'review_note' => [
                    'type' => 'TEXT',
                    'null' => true,
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
            $forge->addKey(['user_id', 'status']);
            $forge->addKey(['company_id', 'status']);
            $forge->createTable('data_deletion_requests');
        }

        // ─── users.consent_version_accepted + consent_at ────────────
        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            $additions = [];
            if (! in_array('consent_version_accepted', $cols, true)) {
                $additions['consent_version_accepted'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                    'comment'    => 'Versi kebijakan privasi terakhir yang disetujui',
                ];
            }
            if (! in_array('consent_at', $cols, true)) {
                $additions['consent_at'] = [
                    'type' => 'DATETIME',
                    'null' => true,
                    'comment' => 'Timestamp saat consent terakhir diberikan',
                ];
            }
            if (! empty($additions)) {
                $forge->addColumn('users', $additions);
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('data_deletion_requests')) {
            $forge->dropTable('data_deletion_requests', true);
        }
        if ($db->tableExists('privacy_consents')) {
            $forge->dropTable('privacy_consents', true);
        }
        if ($db->tableExists('users')) {
            $cols = $db->getFieldNames('users');
            foreach (['consent_at', 'consent_version_accepted'] as $col) {
                if (in_array($col, $cols, true)) {
                    $forge->dropColumn('users', $col);
                }
            }
        }
    }
}