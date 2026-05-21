<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Business Trip / SPPD (Surat Perjalanan Dinas)
 *
 * Modul perjalanan dinas dengan workflow approval + advance + settlement.
 *
 * Workflow umum:
 *   1. Karyawan submit pengajuan dgn estimasi biaya (advance_amount).
 *   2. Approval chain (modul BUSINESS_TRIP) — manager → finance.
 *   3. Setelah approve: advance disbursed (manual atau via reimbursement).
 *   4. Karyawan kembali → upload bukti pengeluaran (line items).
 *   5. Settlement: actual_amount vs advance.
 *      - Kalau actual > advance → buat reimbursement otomatis untuk selisih
 *      - Kalau actual < advance → karyawan kembalikan kelebihan (potong gaji)
 *
 * Idempotent.
 */
class CreateBusinessTrips extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('business_trips')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'reference_number'=> ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'SPPD/<COMPANY>/<YEAR>/<5-digit>'],

                'purpose'         => ['type' => 'TEXT'],
                'destination'     => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Kota/lokasi tujuan'],
                'destination_type'=> [
                    'type' => 'ENUM',
                    'constraint' => ['DOMESTIC','INTERNATIONAL'],
                    'default' => 'DOMESTIC',
                ],
                'start_date'      => ['type' => 'DATE'],
                'end_date'        => ['type' => 'DATE'],
                'duration_days'   => ['type' => 'INT', 'default' => 1,
                    'comment' => 'Lama perjalanan'],

                'transport_mode'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'PESAWAT / KERETA / BUS / MOBIL_DINAS / MOBIL_PRIBADI / KAPAL / OTHER'],
                'accommodation'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Hotel/penginapan'],

                // Anggaran & realisasi
                'advance_amount'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Uang muka yang disetujui'],
                'actual_amount'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'Total realisasi pengeluaran (sum dari expenses)'],
                'settlement_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0,
                    'comment' => 'actual - advance: positif = perlu reimburse, negatif = kembalikan kelebihan'],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','PENDING','APPROVED','REJECTED','IN_PROGRESS','COMPLETED','SETTLED','CANCELLED'],
                    'default' => 'DRAFT',
                    'comment' => 'DRAFT→PENDING→APPROVED→IN_PROGRESS→COMPLETED→SETTLED. REJECTED/CANCELLED = terminal.',
                ],

                // Workflow
                'submitted_at'    => ['type' => 'DATETIME', 'null' => true],
                'approved_at'     => ['type' => 'DATETIME', 'null' => true],
                'rejected_at'     => ['type' => 'DATETIME', 'null' => true],
                'rejection_reason'=> ['type' => 'TEXT', 'null' => true],
                'completed_at'    => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Karyawan kembali, sudah upload bukti'],
                'settled_at'      => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Finance sudah finalkan settlement'],

                // Snapshot dokumen surat tugas
                'sppd_html'       => ['type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot HTML SPPD setelah approve'],

                // Link
                'reimbursement_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke reimbursements kalau ada selisih'],

                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'reference_number']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['company_id', 'start_date']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('business_trips');
        }

        if (! $db->tableExists('business_trip_expenses')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'business_trip_id'=> ['type' => 'INT', 'unsigned' => true],

                'category' => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'TRANSPORT / ACCOMMODATION / MEAL / DAILY_ALLOWANCE / FUEL / PARKING / TOLL / OTHER',
                ],
                'expense_date'    => ['type' => 'DATE', 'null' => true],
                'description'     => ['type' => 'VARCHAR', 'constraint' => 500],
                'amount'          => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'receipt_path'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'is_billable'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1,
                    'comment' => '0 = pengeluaran pribadi tidak ditagihkan'],

                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['business_trip_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('business_trip_id', 'business_trips', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('business_trip_expenses');
        }

        // Permission baru
        $newPermissions = [
            ['slug' => 'business_trip.view',      'name' => 'View Business Trips',          'group' => 'business_trip'],
            ['slug' => 'business_trip.manage',    'name' => 'Manage Business Trips',        'group' => 'business_trip'],
            ['slug' => 'business_trip.approve',   'name' => 'Approve Business Trips',       'group' => 'business_trip'],
            ['slug' => 'business_trip.settle',    'name' => 'Settle Business Trip Expenses','group' => 'business_trip'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant:
        //  role 1 super → semua
        //  role 2 HR    → view, manage, approve
        //  role 3 manager → view, approve
        //  role 4 employee → view (bisa lihat milik sendiri)
        //  role 5 finance → view, settle
        $grantMap = [
            1 => ['business_trip.view','business_trip.manage','business_trip.approve','business_trip.settle'],
            2 => ['business_trip.view','business_trip.manage','business_trip.approve'],
            3 => ['business_trip.view','business_trip.approve'],
            4 => ['business_trip.view'],
            5 => ['business_trip.view','business_trip.settle'],
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
    }

    public function down(): void
    {
        if ($this->db->tableExists('business_trip_expenses')) $this->forge->dropTable('business_trip_expenses', true);
        if ($this->db->tableExists('business_trips'))         $this->forge->dropTable('business_trips', true);
    }
}