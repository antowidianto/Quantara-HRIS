<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah kolom `tier` ke `positions` untuk menentukan jenjang struktural
 * dalam sebuah department: MANAGER, ASST_MANAGER, SUPERVISOR, STAFF.
 *
 * Tier ini dipakai oleh OrgStructureController untuk menghitung ulang
 * `employees.manager_id` (direct report) saat struktur organisasi diubah:
 *
 *   STAFF        → SUPERVISOR (fallback naik) di department yang sama
 *   SUPERVISOR   → ASST_MANAGER (fallback MANAGER) di department yang sama
 *   ASST_MANAGER → MANAGER di department yang sama
 *   MANAGER      → MANAGER di parent department (naik sampai ketemu)
 *
 * Default 'STAFF' supaya posisi existing tidak null. HR boleh override
 * via halaman Org Structure.
 */
class AddPositionTier extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('positions', [
            'tier' => [
                'type'       => 'ENUM',
                'constraint' => ['MANAGER', 'ASST_MANAGER', 'SUPERVISOR', 'STAFF'],
                'default'    => 'STAFF',
                'null'       => false,
                'after'      => 'name',
                'comment'    => 'Jenjang struktural; dipakai untuk auto-resolve direct report',
            ],
        ]);

        // Index supaya query "cari atasan tier X di dept Y" cepat.
        $this->db->query('CREATE INDEX idx_positions_tier ON positions (department_id, tier)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_positions_tier ON positions');
        $this->forge->dropColumn('positions', 'tier');
    }
}