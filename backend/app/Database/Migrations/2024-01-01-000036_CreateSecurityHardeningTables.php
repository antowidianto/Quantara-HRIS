<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 3 — Security Hardening Tables.
 *
 * Tabel yang ditambah:
 *   1. `password_history` — track hash N password terakhir (R5: tidak boleh
 *      reuse dari 5 password sebelumnya). Lihat `App\Libraries\Auth\PasswordPolicy`.
 *   2. `twofa_backup_codes` — kode recovery 2FA single-use, 8 kode/user
 *      yang di-hash via Argon2id. Lihat `App\Libraries\Auth\TwoFactorBackupCodes`.
 *
 * Migration ini ADDITIVE — tidak mengubah tabel existing.
 */
class CreateSecurityHardeningTables extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── password_history ───────────────────────────────────────
        if (! $db->tableExists('password_history')) {
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
                'password_hash' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'comment' => 'Argon2id hash dari password yang sudah pernah dipakai',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['user_id', 'created_at']);
            $forge->createTable('password_history');
        }

        // ─── twofa_backup_codes ─────────────────────────────────────
        if (! $db->tableExists('twofa_backup_codes')) {
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
                'code_hash' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'comment' => 'Argon2id hash dari backup code (single-use)',
                ],
                'used_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                    'comment' => 'NULL = aktif; non-NULL = sudah dipakai',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['user_id', 'used_at']);
            $forge->createTable('twofa_backup_codes');
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        if ($db->tableExists('twofa_backup_codes')) {
            $forge->dropTable('twofa_backup_codes', true);
        }
        if ($db->tableExists('password_history')) {
            $forge->dropTable('password_history', true);
        }
    }
}