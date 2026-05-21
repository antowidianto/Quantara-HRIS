<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Asset / Inventory Management
 *
 * Tracking aset perusahaan (laptop, HP, kendaraan, ID card, dll) dan
 * assignment-nya ke karyawan. Mengikuti praktik HR/IT umum:
 *   - Setiap aset punya asset_number unik (auto-generate atau manual)
 *   - Status: AVAILABLE / ASSIGNED / REPAIR / RETIRED / LOST
 *   - Saat assign ke karyawan: catat condition_when_assigned + BAST HTML
 *   - Saat return: catat condition_when_returned + BAST balik
 *
 * Idempotent.
 */
class CreateAssetsAndAssignments extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('assets')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'asset_number'    => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Unik per company, mis. AST-2026-0001'],
                'category'        => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'LAPTOP / DESKTOP / PHONE / VEHICLE / FURNITURE / TOOL / ID_CARD / ACCESS_CARD / OTHER',
                ],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
                'brand'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'model'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'serial_number'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'description'     => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Spec, varian, warna, dst.'],

                'purchase_date'   => ['type' => 'DATE', 'null' => true],
                'purchase_price'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'purchase_invoice'=> ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'No. invoice / kontrak pembelian'],

                'condition' => [
                    'type' => 'ENUM',
                    'constraint' => ['NEW','GOOD','FAIR','POOR','DAMAGED'],
                    'default' => 'NEW',
                ],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['AVAILABLE','ASSIGNED','REPAIR','RETIRED','LOST'],
                    'default' => 'AVAILABLE',
                ],

                // Cached pointer ke assignment aktif (untuk query cepat)
                'current_assignment_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'current_employee_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Lokasi penyimpanan saat AVAILABLE'],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'attachment_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                    'comment' => 'Foto/dokumen pendukung'],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'asset_number']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['company_id', 'category']);
            $forge->addKey('current_employee_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('assets');
        }

        if (! $db->tableExists('asset_assignments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'asset_id'        => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],

                'assigned_at'     => ['type' => 'DATETIME'],
                'returned_at'     => ['type' => 'DATETIME', 'null' => true],
                'expected_return' => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Untuk peminjaman bersifat sementara'],
                'purpose'         => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Tujuan/penggunaan aset'],

                'condition_when_assigned' => [
                    'type' => 'ENUM',
                    'constraint' => ['NEW','GOOD','FAIR','POOR','DAMAGED'],
                    'default' => 'GOOD',
                ],
                'condition_when_returned' => [
                    'type' => 'ENUM',
                    'constraint' => ['NEW','GOOD','FAIR','POOR','DAMAGED','LOST'],
                    'null' => true,
                ],
                'damage_note'     => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan kerusakan saat return'],

                // Snapshot dokumen BAST
                'bast_html'       => ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot HTML BAST saat assignment'],
                'bast_return_html'=> ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot HTML BAST balikan'],

                // Tanda tangan & acknowledgment
                'employee_acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
                'returned_acknowledged_at' => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Saat HR konfirmasi terima return'],

                // Audit
                'assigned_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'returned_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'asset_id']);
            $forge->addKey(['employee_id', 'returned_at']);
            $forge->addKey(['asset_id', 'returned_at']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('asset_id', 'assets', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('asset_assignments');
        }

        // Permission baru
        $newPermissions = [
            ['slug' => 'asset.view',   'name' => 'View Assets',   'group' => 'asset'],
            ['slug' => 'asset.manage', 'name' => 'Manage Assets & Assignments', 'group' => 'asset'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Auto-grant: role 1 (super) + 2 (HR) + 3 (manager) → view+manage
        // role 4 (employee) + 5 (finance) → view saja
        $grantMap = [
            1 => ['asset.view','asset.manage'],
            2 => ['asset.view','asset.manage'],
            3 => ['asset.view'],
            4 => ['asset.view'],
            5 => ['asset.view'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at' => $now,
                ]);
            }
        }

        // Extend enum contract_type dengan 'BAST' untuk template Berita Acara
        try {
            $platform = strtolower($db->getPlatform());
            if (str_contains($platform, 'mysql')) {
                $db->query(
                    "ALTER TABLE contract_templates MODIFY contract_type ENUM("
                    . "'PKWT','PKWTT','PROBATION','PKHL','MAGANG','OUTSOURCING','GENERIC',"
                    . "'SP','PHK','BAST'"
                    . ") DEFAULT 'PKWTT'"
                );
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Gagal extend enum contract_type with BAST: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('asset_assignments')) $this->forge->dropTable('asset_assignments', true);
        if ($this->db->tableExists('assets'))            $this->forge->dropTable('assets', true);
    }
}