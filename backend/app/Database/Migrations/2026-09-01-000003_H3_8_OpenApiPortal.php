<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H3.8 — Open API & Developer Portal.
 *
 * Mempersiapkan infrastruktur untuk integrasi enterprise self-service:
 *
 *   - api_keys           : kredensial API per company per aplikasi
 *                          (rotate-able, revoke-able). Hash-only storage:
 *                          plain key hanya dikembalikan SEKALI saat
 *                          create/rotate.
 *   - api_key_scopes     : whitelist scope per API key (lihat
 *                          permissions table). Many-to-many.
 *   - api_request_logs   : log per request masuk (rate-limit + audit
 *                          + analytics). Append-only; cleanup via cron
 *                          90 hari.
 *
 * Plus seed `api_scopes` master (subset permission yang aman di-expose
 * untuk integrasi luar — tidak semua permission internal di-expose
 * karena beberapa hanya untuk admin internal).
 *
 * Idempotent guard.
 *
 * Permission baru:
 *   - api_key.manage     : kelola API key (rotate, revoke)
 *   - api_key.view       : lihat list + audit log
 */
class H3_8_OpenApiPortal extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── api_keys ──────────────────────────────────────────────
        if (! $db->tableExists('api_keys')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Label aplikasi pemilik key, mis. "Internal Slack Bot"'],
                'key_prefix'      => ['type' => 'VARCHAR', 'constraint' => 16,
                    'comment' => 'Prefix non-secret untuk identifikasi (mis. qhk_live_AbCd) — 8 char unique'],
                'key_hash'        => ['type' => 'VARCHAR', 'constraint' => 128,
                    'comment' => 'SHA-256 hash dari plain key. Plain hanya dikembalikan saat create/rotate.'],
                'tier'            => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'BASIC',
                    'comment'    => 'BASIC | PRO | ENTERPRISE — menentukan rate limit',
                ],
                'rate_limit_per_minute' => ['type' => 'INT', 'unsigned' => true, 'default' => 60],
                'is_sandbox'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = sandbox key (akses ke env testing/mock data only)'],
                'last_used_at'    => ['type' => 'DATETIME', 'null' => true],
                'last_used_ip'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'expires_at'      => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'NULL = tidak expire'],
                'revoked_at'      => ['type' => 'DATETIME', 'null' => true],
                'revoked_reason'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('key_prefix');
            $forge->addKey(['company_id', 'revoked_at']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('api_keys');
        }

        // ─── api_key_scopes ────────────────────────────────────────
        if (! $db->tableExists('api_key_scopes')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'api_key_id' => ['type' => 'INT', 'unsigned' => true],
                'scope'      => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Scope identifier — lihat tabel api_scopes'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['api_key_id', 'scope']);
            $forge->addForeignKey('api_key_id', 'api_keys', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('api_key_scopes');
        }

        // ─── api_scopes (master scope catalog) ─────────────────────
        if (! $db->tableExists('api_scopes')) {
            $forge->addField([
                'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'scope'       => ['type' => 'VARCHAR', 'constraint' => 64],
                'group_label' => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Group untuk UI (Employee, Payroll, Recruitment, dst)'],
                'description' => ['type' => 'VARCHAR', 'constraint' => 500],
                'is_dangerous'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = scope tulis/destructive — perlu konfirmasi extra di UI'],
                'created_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey('scope');
            $forge->createTable('api_scopes');

            // Seed scope catalog standar
            $scopes = [
                ['employees.read',         'Employee', 'Baca data karyawan (basic profile)', 0],
                ['employees.write',        'Employee', 'Buat / update / nonaktifkan karyawan', 1],
                ['attendance.read',        'Attendance', 'Baca data absensi & roster', 0],
                ['attendance.write',       'Attendance', 'Submit clock-in/out & koreksi via API', 1],
                ['leaves.read',            'Leave', 'Baca pengajuan & saldo cuti', 0],
                ['leaves.write',           'Leave', 'Submit pengajuan cuti via API', 1],
                ['payroll.read',           'Payroll', 'Baca payslip & ringkasan period', 0],
                ['recruitment.read',       'Recruitment', 'Baca kandidat & lamaran', 0],
                ['recruitment.write',      'Recruitment', 'Push kandidat dari sumber eksternal', 1],
                ['webhooks.subscribe',     'Webhook', 'Subscribe ke event outbound', 0],
                ['reports.read',           'Reports', 'Baca laporan analytics', 0],
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($scopes as $s) {
                $db->table('api_scopes')->insert([
                    'scope'       => $s[0],
                    'group_label' => $s[1],
                    'description' => $s[2],
                    'is_dangerous'=> $s[3],
                    'created_at'  => $now,
                ]);
            }
        }

        // ─── api_request_logs ──────────────────────────────────────
        if (! $db->tableExists('api_request_logs')) {
            $forge->addField([
                'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'api_key_id'   => ['type' => 'INT', 'unsigned' => true],
                'endpoint'     => ['type' => 'VARCHAR', 'constraint' => 255],
                'method'       => ['type' => 'VARCHAR', 'constraint' => 10],
                'status_code'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'duration_ms'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'request_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'rate_limited' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'created_at'   => ['type' => 'DATETIME'],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'created_at']);
            $forge->addKey(['api_key_id', 'created_at']);
            $forge->addForeignKey('api_key_id', 'api_keys', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('api_request_logs');
        }

        // ─── Permissions ────────────────────────────────────────────
        if ($db->tableExists('permissions')) {
            $perms = [
                ['slug' => 'api_key.manage', 'name' => 'Manage API Keys', 'group' => 'developer', 'description' => 'Kelola API key (create, rotate, revoke)'],
                ['slug' => 'api_key.view',   'name' => 'View API Keys',   'group' => 'developer', 'description' => 'Lihat daftar API key + audit request log'],
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
            'api_request_logs',
            'api_key_scopes',
            'api_keys',
            'api_scopes',
        ] as $tbl) {
            if ($db->tableExists($tbl)) {
                $forge->dropTable($tbl, true);
            }
        }
        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'api_key.manage',
                'api_key.view',
            ])->delete();
        }
    }
}