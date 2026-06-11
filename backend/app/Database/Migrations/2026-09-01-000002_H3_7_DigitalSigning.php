<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H3.7 — Digital Signing Berstandar Hukum (PSrE).
 *
 * UU ITE 11/2008 (jo. UU 19/2016) dan Perpres 95/2018 mensyaratkan
 * tanda tangan elektronik tersertifikasi (TTE Tersertifikasi) untuk
 * dokumen ketenagakerjaan yang ber-implikasi hukum:
 *   - Kontrak kerja (PKWT/PKWTT)
 *   - Amendment kontrak (JIC)
 *   - Surat pengangkatan / mutasi
 *   - Severance agreement
 *   - Offer letter resmi
 *
 * Module ini mempersiapkan dua tabel:
 *
 *   - psre_providers      : konfigurasi PSrE per company
 *                           (PrivyID, Vida, Peruri Digital — semuanya
 *                           PSrE Tersertifikasi Kominfo)
 *   - signing_envelopes   : satu envelope = satu dokumen yang dikirim
 *                           ke PSrE untuk di-sign oleh 1+ pihak
 *                           (karyawan, HR, atasan). Audit trail penuh
 *                           per signer (waktu sign, IP, certificate fingerprint).
 *
 * Idempotent: tableExists guard. Aman re-run.
 *
 * Permission baru:
 *   - signing.envelope.create  : kirim dokumen ke PSrE
 *   - signing.envelope.view    : lihat status & audit trail
 *   - signing.provider.manage  : kelola koneksi PSrE
 */
class H3_7_DigitalSigning extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── psre_providers ────────────────────────────────────────
        if (! $db->tableExists('psre_providers')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'provider'            => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'comment'    => 'PRIVYID | VIDA | PERURI | MOCK_DEV',
                ],
                'base_url'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'merchant_id'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'api_key_encrypted'   => ['type' => 'TEXT', 'null' => true],
                'callback_secret'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true,
                    'comment' => 'HMAC secret untuk verify callback dari PSrE'],
                'is_active'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'extra_config_json'   => ['type' => 'TEXT', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'provider']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('psre_providers');
        }

        // ─── signing_envelopes ─────────────────────────────────────
        if (! $db->tableExists('signing_envelopes')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'provider_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'document_type'       => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'comment'    => 'CONTRACT | CONTRACT_AMENDMENT | OFFER_LETTER | SEVERANCE | TRANSFER_LETTER | OTHER',
                ],
                'reference_table'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Tabel sumber dokumen (mis. employee_contracts, offer_letters)'],
                'reference_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'subject'             => ['type' => 'VARCHAR', 'constraint' => 255],
                'document_path'       => ['type' => 'VARCHAR', 'constraint' => 500,
                    'comment' => 'Path PDF asli yang dikirim ke PSrE'],
                'signed_document_path'=> ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Path PDF setelah semua pihak tanda tangan + sealed certificate'],
                'document_hash'       => ['type' => 'VARCHAR', 'constraint' => 128,
                    'comment' => 'SHA-256 dari dokumen asli untuk integrity check'],
                'envelope_external_id'=> ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true,
                    'comment' => 'ID envelope di sisi PSrE provider'],
                'status'              => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'DRAFT',
                    'comment'    => 'DRAFT | SENT | PARTIALLY_SIGNED | COMPLETED | CANCELLED | EXPIRED | FAILED',
                ],
                'sent_at'             => ['type' => 'DATETIME', 'null' => true],
                'completed_at'        => ['type' => 'DATETIME', 'null' => true],
                'expires_at'          => ['type' => 'DATETIME', 'null' => true],
                'created_by_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'fallback_wet_sign'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 jika PSrE flow gagal & user upload scan tangan basah sebagai fallback'],
                'wet_sign_path'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'status', 'created_at']);
            $forge->addKey(['document_type', 'reference_table', 'reference_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('provider_id', 'psre_providers', 'id', 'SET NULL', 'CASCADE');
            $forge->createTable('signing_envelopes');
        }

        // ─── signing_envelope_signers ───────────────────────────────
        if (! $db->tableExists('signing_envelope_signers')) {
            $forge->addField([
                'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'envelope_id'        => ['type' => 'INT', 'unsigned' => true],
                'signer_role'        => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'comment'    => 'EMPLOYEE | HR | DIRECT_SUPERVISOR | DIRECTOR | LEGAL | WITNESS',
                ],
                'sign_order'         => ['type' => 'INT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Urutan signing (1 sign dulu, baru 2, dst)'],
                'employee_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'user_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'full_name'          => ['type' => 'VARCHAR', 'constraint' => 200],
                'email'              => ['type' => 'VARCHAR', 'constraint' => 200],
                'national_id'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'NIK (encrypted at rest via BaseModel encrypted fields jika memungkinkan)'],
                'status'             => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'PENDING',
                    'comment'    => 'PENDING | SIGNED | DECLINED | EXPIRED',
                ],
                'signed_at'          => ['type' => 'DATETIME', 'null' => true],
                'sign_ip'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'sign_user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'certificate_fingerprint' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true,
                    'comment' => 'SHA-256 fingerprint dari sertifikat digital signer (audit forensic)'],
                'declined_reason'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'         => ['type' => 'DATETIME', 'null' => true],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['envelope_id', 'sign_order']);
            $forge->addKey(['envelope_id', 'status']);
            $forge->addForeignKey('envelope_id', 'signing_envelopes', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('signing_envelope_signers');
        }

        // ─── signing_envelope_events (full audit trail) ─────────────
        if (! $db->tableExists('signing_envelope_events')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'envelope_id'  => ['type' => 'INT', 'unsigned' => true],
                'signer_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'event_type'   => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'comment'    => 'CREATED | SENT | VIEWED | SIGNED | DECLINED | EXPIRED | COMPLETED | CANCELLED | FALLBACK_WET_SIGN',
                ],
                'event_at'     => ['type' => 'DATETIME'],
                'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'metadata_json'=> ['type' => 'TEXT', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['envelope_id', 'event_at']);
            $forge->addForeignKey('envelope_id', 'signing_envelopes', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('signing_envelope_events');
        }

        // ─── Permissions ────────────────────────────────────────────
        if ($db->tableExists('permissions')) {
            $perms = [
                ['slug' => 'signing.envelope.create', 'name' => 'Create Signing Envelope', 'group' => 'signing', 'description' => 'Buat dan kirim envelope tanda tangan ke PSrE'],
                ['slug' => 'signing.envelope.view',   'name' => 'View Signing Envelope',   'group' => 'signing', 'description' => 'Lihat status dan audit trail envelope'],
                ['slug' => 'signing.provider.manage', 'name' => 'Manage PSrE Provider',    'group' => 'signing', 'description' => 'Kelola koneksi PSrE per company'],
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($perms as $p) {
                $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
                if (! $exists) {
                    $db->table('permissions')->insert($p + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        foreach ([
            'signing_envelope_events',
            'signing_envelope_signers',
            'signing_envelopes',
            'psre_providers',
        ] as $tbl) {
            if ($db->tableExists($tbl)) {
                $forge->dropTable($tbl, true);
            }
        }

        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'signing.envelope.create',
                'signing.envelope.view',
                'signing.provider.manage',
            ])->delete();
        }
    }
}