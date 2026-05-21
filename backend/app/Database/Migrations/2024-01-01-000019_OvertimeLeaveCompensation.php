<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lembur → Cuti Pengganti
 *
 * Item #10 roadmap. Karyawan boleh memilih kompensasi lembur dalam bentuk:
 *   - CASH  : dibayar uang (default, behaviour lama)
 *   - LEAVE : ditambahkan jadi quota cuti pengganti (compensatory time-off)
 *
 * Yang berubah:
 *   1. Kolom baru di `overtimes`:
 *      - `compensation_type` ENUM(CASH, LEAVE) default CASH
 *      - `leave_hours_credited` (decimal) — jumlah jam yang dikonversi ke cuti
 *      - `leave_balance_id` (FK) — pointer ke `leave_balances` yang ditambah saat approve
 *      - `leave_conversion_ratio` (decimal) — rasio jam→jam cuti, default 1.0
 *   2. Kolom baru di `leave_types`:
 *      - `source` VARCHAR(20) default 'STANDARD' (vs 'OVERTIME_COMP')
 *   3. Insert leave_type baru `OVERTIME_COMP` per company kalau belum ada,
 *      dengan kategori SPECIAL + source=OVERTIME_COMP.
 *   4. Insert pengaturan default per company:
 *      - `overtime_to_leave_ratio` (jam lembur per jam cuti, default 1.0)
 *      - `overtime_to_leave_max_days_year` (max hari yg bisa dikumpulkan, default 12)
 *
 * Idempotent.
 */
class OvertimeLeaveCompensation extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;
        $platform = strtolower((string) $db->getPlatform());

        // 1. Tambah kolom di `overtimes`
        $overtimeFields = [
            'compensation_type' => [
                'type' => 'VARCHAR', 'constraint' => 16, 'default' => 'CASH', 'null' => false,
                'comment' => 'CASH = bayar uang, LEAVE = konversi ke cuti pengganti',
            ],
            'leave_hours_credited' => [
                'type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true,
                'comment' => 'Jumlah jam cuti pengganti yg ditambahkan ke balance',
            ],
            'leave_balance_id' => [
                'type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'FK leave_balances yg dikredit saat approve LEAVE',
            ],
            'leave_conversion_ratio' => [
                'type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 1.00, 'null' => true,
                'comment' => '1 jam lembur = X jam cuti (default 1.0)',
            ],
        ];
        foreach ($overtimeFields as $col => $def) {
            if (! $this->hasColumn($db, 'overtimes', $col)) {
                $forge->addColumn('overtimes', [$col => $def]);
            }
        }

        // 2. Kolom `source` di `leave_types`
        if (! $this->hasColumn($db, 'leave_types', 'source')) {
            $forge->addColumn('leave_types', [
                'source' => [
                    'type' => 'VARCHAR', 'constraint' => 20, 'default' => 'STANDARD', 'null' => false,
                    'comment' => 'STANDARD | OVERTIME_COMP — sumber quota cuti',
                ],
            ]);
        }

        // 3. Buat leave_type baru per company (idempotent)
        $companies = $db->table('companies')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        foreach ($companies as $co) {
            $exists = $db->table('leave_types')
                ->where('company_id', (int) $co['id'])
                ->where('source', 'OVERTIME_COMP')
                ->countAllResults();
            if ($exists > 0) continue;

            $db->table('leave_types')->insert([
                'company_id'             => (int) $co['id'],
                'code'                   => 'CPL', // Cuti Pengganti Lembur
                'name'                   => 'Cuti Pengganti Lembur',
                'category'               => 'SPECIAL',
                'default_days'           => 0,
                'is_paid'                => 1,
                'requires_doc'           => 0,
                'min_tenure_months'      => 0,
                'reset_yearly'           => 1,
                'gender_restriction'     => 'ALL',
                'description'            => 'Cuti yang dikumpulkan dari konversi jam lembur. Kuota awal 0, bertambah otomatis saat lembur disetujui dengan kompensasi LEAVE.',
                'is_active'              => 1,
                'is_prorated'            => 0,
                'is_carry_over_allowed'  => 0,
                'carry_over_max_days'    => 0,
                'carry_over_expires_in_months' => 0,
                'source'                 => 'OVERTIME_COMP',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }

        // 4. Default settings per company (kalau pakai key/value `settings`)
        if ($db->tableExists('settings')) {
            $defaults = [
                'overtime_to_leave_enabled'      => '1',
                'overtime_to_leave_ratio'        => '1.0',
                'overtime_to_leave_max_days_year'=> '12',
            ];
            foreach ($companies as $co) {
                foreach ($defaults as $k => $v) {
                    $exists = $db->table('settings')
                        ->where('company_id', (int) $co['id'])
                        ->where('`key`', $k)
                        ->countAllResults();
                    if ($exists > 0) continue;
                    $db->table('settings')->insert([
                        'company_id' => (int) $co['id'],
                        'key'        => $k,
                        'value'      => $v,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        // Drop kolom (kalau ada)
        if ($this->hasColumn($db, 'overtimes', 'compensation_type')) {
            $forge->dropColumn('overtimes', ['compensation_type', 'leave_hours_credited', 'leave_balance_id', 'leave_conversion_ratio']);
        }
        if ($this->hasColumn($db, 'leave_types', 'source')) {
            $forge->dropColumn('leave_types', 'source');
        }
    }

    private function hasColumn($db, string $table, string $column): bool
    {
        if (! $db->tableExists($table)) return false;
        return $db->fieldExists($column, $table);
    }
}