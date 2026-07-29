<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Data minimum untuk hak kelahiran/keguguran sesuai UU 4 Tahun 2024.
 *
 * Migration additive: tidak mengubah cuti historis yang sudah disetujui.
 * Kolom menyimpan kondisi khusus, bukti medis, durasi tambahan, fase upah,
 * dan jejak review yang diperlukan untuk audit serta integrasi payroll.
 */
final class AddKiaLeaveEntitlementFields extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();

        if ($db->tableExists('leaves')) {
            $columns = $db->getFieldNames('leaves');
            $additions = [];

            $definitions = [
                'kia_special_condition' => [
                    'type' => 'TINYINT', 'default' => 0,
                    'comment' => '1 jika tambahan hak UU KIA karena kondisi khusus',
                ],
                'kia_condition_note' => [
                    'type' => 'TEXT', 'null' => true,
                    'comment' => 'Kondisi khusus/komplikasi yang direview HR',
                ],
                'kia_medical_document_path' => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Surat dokter/tenaga kesehatan untuk tambahan hak',
                ],
                'kia_additional_duration' => [
                    'type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0,
                    'comment' => 'Tambahan durasi; unit ditentukan kia_duration_unit',
                ],
                'kia_duration_unit' => [
                    'type' => 'VARCHAR', 'constraint' => 10, 'null' => true,
                    'comment' => 'DAY|MONTH; hak maternity tidak dipaksa menjadi 30 hari/bulan',
                ],
                'kia_pay_phase_json' => [
                    'type' => 'TEXT', 'null' => true,
                    'comment' => 'Snapshot fase upah: bulan 1-4 100%, bulan 5-6 >=75%',
                ],
                'kia_reviewed_by' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                ],
                'kia_reviewed_at' => [
                    'type' => 'DATETIME', 'null' => true,
                ],
            ];

            foreach ($definitions as $name => $definition) {
                if (! in_array($name, $columns, true)) {
                    $additions[$name] = $definition;
                }
            }

            if ($additions !== []) {
                $forge->addColumn('leaves', $additions);
            }
        }

        if (! $db->tableExists('leave_types')) {
            return;
        }

        // Hilangkan hard-limit maternity 90 hari pada konfigurasi existing.
        $db->table('leave_types')
            ->where('category', 'MATERNITY')
            ->update([
                'max_days' => null,
                'requires_doc' => 1,
                'description' => 'UU 4/2024: hak 3 bulan dan tambahan kondisi khusus hingga 3 bulan; upah penuh bulan 1-4 dan sedikitnya 75% bulan 5-6.',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $db->table('leave_types')
            ->where('category', 'PATERNITY')
            ->update([
                'requires_doc' => 1,
                'description' => 'UU 4/2024: 2 hari dan tambahan hingga 3 hari berikutnya sesuai kesepakatan.',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function down()
    {
        // Hak minimum dan bukti audit tidak dihapus otomatis.
    }
}