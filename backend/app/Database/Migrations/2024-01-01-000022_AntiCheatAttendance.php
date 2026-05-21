<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Anti-Cheat Attendance — extend `attendances` dan tambah tabel
 * `attendance_devices` untuk tracking device fingerprint.
 *
 * Rationale anti-cheat:
 *  - Mock GPS detection: simpan GPS accuracy + flag suspicious
 *    (accuracy > 100m, atau flagged via Mock Locations setting Android)
 *  - Device fingerprint: track device pertama kali clock-in.
 *    Device baru → flag warning.
 *  - Selfie verification: simpan path selfie yg user ambil saat clock-in.
 *    Optional face match (deferred ke modul lain).
 *  - Anomaly score: composite score dari semua sinyal anti-cheat
 *    sehingga HR bisa filter list yg suspicious.
 */
class AntiCheatAttendance extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // Extend `attendances` dengan kolom anti-cheat
        $cols = $db->getFieldNames('attendances');

        if (! in_array('clock_in_accuracy', $cols, true)) {
            $forge->addColumn('attendances', [
                'clock_in_accuracy' => [
                    'type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true,
                    'comment' => 'GPS accuracy radius (meter) saat clock-in',
                ],
                'clock_out_accuracy' => [
                    'type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true,
                    'comment' => 'GPS accuracy radius (meter) saat clock-out',
                ],
                'clock_in_is_mock_gps' => [
                    'type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => '1 = Mock Location terdeteksi saat clock-in',
                ],
                'clock_in_device_id' => [
                    'type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Device fingerprint hash (UUID-like)',
                ],
                'clock_in_device_name' => [
                    'type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'OS / Browser / model snapshot',
                ],
                'clock_in_user_agent' => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                ],
                'clock_in_ip' => [
                    'type' => 'VARCHAR', 'constraint' => 45, 'null' => true,
                    'comment' => 'IP saat clock-in',
                ],
                'clock_in_selfie_path' => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Path selfie saat clock-in',
                ],
                'anomaly_score' => [
                    'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '0-100 score anti-cheat (lebih tinggi = lebih mencurigakan)',
                ],
                'anomaly_flags' => [
                    'type' => 'TEXT', 'null' => true,
                    'comment' => 'JSON array of flag codes: [MOCK_GPS, NEW_DEVICE, LOW_ACCURACY, ...]',
                ],
                'anomaly_reviewed_by' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'User HR yg sudah review',
                ],
                'anomaly_reviewed_at' => [
                    'type' => 'DATETIME', 'null' => true,
                ],
                'anomaly_review_note' => [
                    'type' => 'TEXT', 'null' => true,
                ],
            ]);
        }

        // Tabel attendance_devices — track device per user
        if (! $db->tableExists('attendance_devices')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'user_id'        => ['type' => 'INT', 'unsigned' => true],
                'device_id'      => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Device fingerprint hash unik per (user, device)'],
                'device_name'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'user_agent'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'first_seen_at'  => ['type' => 'DATETIME'],
                'last_seen_at'   => ['type' => 'DATETIME'],
                'last_ip'        => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'usage_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Jumlah berapa kali clock-in dari device ini'],
                'is_trusted'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => '1 = HR/admin tandai device tepercaya'],
                'trusted_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'trusted_at'     => ['type' => 'DATETIME', 'null' => true],
                'is_blocked'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0,
                    'comment' => '1 = blokir device ini (hasil cheat detect)'],
                'blocked_reason' => ['type' => 'TEXT', 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['user_id', 'device_id']);
            $forge->addKey(['company_id', 'user_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('attendance_devices');
        }

        // Permissions
        $newPermissions = [
            ['slug' => 'attendance.review_anomaly', 'name' => 'Review Anomaly Attendance', 'group' => 'attendance'],
            ['slug' => 'attendance.manage_devices', 'name' => 'Manage Trusted Devices',    'group' => 'attendance'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant role 1 (super admin) & 2 (HR)
        $grantRoles = [1, 2];
        foreach ($grantRoles as $rid) {
            foreach ($newPermissions as $p) {
                $perm = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $rid)
                    ->where('permission_id', (int) $perm['id'])->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id' => $rid,
                    'permission_id' => (int) $perm['id'],
                    'created_at' => $now,
                ]);
            }
        }

        // Settings default per company
        $settings = [
            ['key' => 'anticheat.require_selfie',        'value' => '0',
                'description' => 'Wajibkan upload selfie saat clock-in (0=optional, 1=required)'],
            ['key' => 'anticheat.max_gps_accuracy_m',    'value' => '100',
                'description' => 'Reject clock-in jika GPS accuracy > X meter'],
            ['key' => 'anticheat.block_mock_gps',        'value' => '1',
                'description' => '1 = block clock-in kalau Mock GPS terdeteksi'],
            ['key' => 'anticheat.warn_new_device',       'value' => '1',
                'description' => '1 = warning + audit kalau device baru terdeteksi'],
            ['key' => 'anticheat.high_anomaly_threshold','value' => '60',
                'description' => 'Score >= ini akan dianggap "high risk" untuk review HR'],
        ];

        $companies = $db->table('companies')->where('is_active', 1)->get()->getResultArray();
        foreach ($companies as $co) {
            foreach ($settings as $s) {
                $exists = $db->table('settings')
                    ->where('company_id', $co['id'])->where('key', $s['key'])->countAllResults();
                if ($exists > 0) continue;
                $db->table('settings')->insert([
                    'company_id'  => (int) $co['id'],
                    'key'         => $s['key'],
                    'value'       => $s['value'],
                    'description' => $s['description'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('attendance_devices')) {
            $this->forge->dropTable('attendance_devices', true);
        }
        $cols = $this->db->getFieldNames('attendances');
        $drop = ['clock_in_accuracy','clock_out_accuracy','clock_in_is_mock_gps',
                 'clock_in_device_id','clock_in_device_name','clock_in_user_agent','clock_in_ip',
                 'clock_in_selfie_path','anomaly_score','anomaly_flags',
                 'anomaly_reviewed_by','anomaly_reviewed_at','anomaly_review_note'];
        foreach ($drop as $c) {
            if (in_array($c, $cols, true)) {
                $this->forge->dropColumn('attendances', $c);
            }
        }
    }
}