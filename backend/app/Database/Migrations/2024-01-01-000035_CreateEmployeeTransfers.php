<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Employee Transfer (Mutasi Karyawan).
 *
 * Modul khusus untuk pemindahan karyawan antar:
 *   - Cabang / branch (mis. Pabrik Cimahi → Sales Office Jakarta)
 *   - Department (mis. Production → Quality Assurance)
 *   - Posisi setara (lateral) — tidak ada perubahan grade / gaji
 *   - Work location (titik absen / lokasi kerja primer)
 *
 * Beda dengan JIC (Job Information Change):
 *   - JIC: handle PROMOTION/DEMOTION/REORG dengan multi-stage approval,
 *     BOD sign-off, employee consent (Pasal 31 UU 13/2003), UMP check,
 *     SK auto-generate, dst. Cocok untuk perubahan kompleks yang menyentuh
 *     upah & jabatan kunci.
 *   - Transfer (modul ini): khusus pemindahan lokasi/dept lateral tanpa
 *     perubahan grade & basic_salary. Workflow lebih ringan (HR-only
 *     approval), cepat di-apply, focused UX. Cocok untuk operasional
 *     harian: rotasi shift cabang, mutasi sales rep, balik kantor pusat,
 *     dst.
 *
 * Aturan ketenagakerjaan Indonesia (UU 13/2003 jo. UU 6/2023):
 *   - Pasal 32: penempatan tenaga kerja wajib memperhatikan harkat,
 *     martabat, hak asasi, dan keselamatan kerja.
 *   - Asas konsensual: mutasi yang mengubah lokasi tugas signifikan
 *     (mis. lintas pulau) sebaiknya dengan persetujuan tertulis. Modul
 *     ini menyediakan field `requires_consent` + signed document upload
 *     untuk kasus tsb.
 *   - Hak atas tunjangan baru: kalau perpindahan branch mengubah jarak
 *     ke rumah / zona UMK, sistem otomatis trigger recompute allowance
 *     (transport, makan) saat IMPLEMENTED.
 *
 * Workflow:
 *   1. HR / atasan submit → status SUBMITTED, butuh approval HR Manager
 *   2. HR Manager approve → status APPROVED, tunggu effective_date
 *   3. Pada effective_date: command apply otomatis update employees
 *      (branch/dept/position/work_location), recompute allowance, log
 *      audit trail. Status → IMPLEMENTED.
 *   4. Kalau perlu instant apply (mis. mutasi mendadak), HR dengan
 *      permission `employee_transfer.implement` boleh langsung apply.
 *
 * Multi-tenant: filter by company_id. Privacy: visible by employee ybs,
 * supervisor, HR.
 */
class CreateEmployeeTransfers extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('employee_transfers')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'transfer_number'     => [
                    'type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Nomor referensi mutasi (auto-generate saat submit, format MUT-YYYYMM-NNNN)',
                ],

                'transfer_type' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'BRANCH',           // Pindah cabang (department/posisi tetap)
                        'DEPARTMENT',       // Pindah department (cabang tetap)
                        'POSITION',         // Pindah posisi setara (lateral, dept/cabang tetap)
                        'WORK_LOCATION',    // Pindah titik absen / lokasi kerja
                        'COMBINED',         // Kombinasi (mis. pindah dept sekaligus cabang)
                    ],
                    'comment' => 'Kategori mutasi untuk reporting & filter',
                ],

                'reason' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'OPERATIONAL',      // Kebutuhan operasional perusahaan
                        'EMPLOYEE_REQUEST', // Atas permintaan karyawan
                        'ROTATION',         // Rotasi rutin (sales, branch ops)
                        'REORGANIZATION',   // Restrukturisasi divisi/cabang
                        'PROMOTION_PREP',   // Persiapan promosi (cross-functional exposure)
                        'PERSONAL',         // Alasan pribadi (keluarga, kesehatan)
                        'OTHER',
                    ],
                ],
                'reason_text'         => ['type' => 'TEXT', 'null' => true],

                // ─── SNAPSHOT POSISI LAMA (saat submit) ──────────────────
                'old_branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_branch_name'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_department_name' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_position_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_position_name'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_work_location_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_work_location_name' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_manager_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_supervisor_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // ─── POSISI BARU ────────────────────────────────────────
                'new_branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_position_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_work_location_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                // manager/supervisor di-recompute otomatis saat IMPLEMENTED
                // berdasarkan struktur dept tujuan (lihat ReportingChainService)

                // ─── TANGGAL ────────────────────────────────────────────
                'effective_date'      => [
                    'type' => 'DATE',
                    'comment' => 'Tanggal mulai berlaku; tidak boleh < hari ini saat APPROVED',
                ],
                'submitted_at'        => ['type' => 'DATETIME', 'null' => true],
                'approved_at'         => ['type' => 'DATETIME', 'null' => true],
                'implemented_at'      => ['type' => 'DATETIME', 'null' => true],

                // ─── CONSENT ────────────────────────────────────────────
                // Untuk mutasi lintas pulau / signifikan, HR boleh request
                // consent karyawan + upload surat persetujuan.
                'requires_consent'    => [
                    'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = perlu surat persetujuan karyawan sebelum di-apply',
                ],
                'consent_status'      => [
                    'type' => 'ENUM',
                    'constraint' => ['NOT_REQUIRED', 'PENDING', 'AGREED', 'REJECTED'],
                    'default' => 'NOT_REQUIRED',
                ],
                'consent_signed_path' => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Path surat persetujuan ditandatangani karyawan',
                ],
                'consent_at'          => ['type' => 'DATETIME', 'null' => true],

                // ─── WORKFLOW ───────────────────────────────────────────
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'DRAFT',       // Belum disubmit
                        'SUBMITTED',   // Menunggu approval HR
                        'APPROVED',    // Disetujui, menunggu effective_date
                        'IMPLEMENTED', // Sudah di-apply ke records employees
                        'REJECTED',
                        'CANCELLED',
                    ],
                    'default' => 'DRAFT',
                ],

                // Approver (HR Manager / Direktur HR)
                'approver_user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approver_action_at'  => ['type' => 'DATETIME', 'null' => true],
                'approver_note'       => ['type' => 'TEXT', 'null' => true],

                // Rejection / cancellation
                'rejection_reason'    => ['type' => 'TEXT', 'null' => true],
                'cancelled_at'        => ['type' => 'DATETIME', 'null' => true],
                'cancelled_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancellation_reason' => ['type' => 'TEXT', 'null' => true],

                // Implementation log
                'implemented_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'implementation_note' => ['type' => 'TEXT', 'null' => true],
                // Snapshot apa saja yang di-recompute setelah apply (untuk audit)
                'allowance_recomputed' => [
                    'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 jika allowance karyawan di-recompute setelah implement',
                ],

                // SK / Surat tugas (opsional)
                'sk_path'             => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

                // Audit
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'transfer_number']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['transfer_type', 'effective_date']);
            $forge->addKey('effective_date');
            $forge->addForeignKey('company_id',  'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('employee_transfers');
        }

        // ─── PERMISSIONS ────────────────────────────────────────────
        $newPermissions = [
            ['slug' => 'employee_transfer.view',      'name' => 'View Employee Transfers',          'group' => 'hr'],
            ['slug' => 'employee_transfer.create',    'name' => 'Create Employee Transfer',         'group' => 'hr'],
            ['slug' => 'employee_transfer.approve',   'name' => 'Approve Employee Transfer',        'group' => 'hr'],
            ['slug' => 'employee_transfer.implement', 'name' => 'Implement (apply) approved Transfer', 'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant ke role 1 (super admin) & 2 (HR admin).
        foreach ([1, 2] as $roleId) {
            foreach (array_column($newPermissions, 'slug') as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
        // Manager (role 3) dapat view & create — bisa propose mutasi anak buah.
        foreach (['employee_transfer.view', 'employee_transfer.create'] as $slug) {
            $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
            if (! $perm) continue;
            $exists = $db->table('role_permissions')
                ->where('role_id', 3)
                ->where('permission_id', (int) $perm['id'])
                ->countAllResults();
            if ($exists > 0) continue;
            $db->table('role_permissions')->insert([
                'role_id'       => 3,
                'permission_id' => (int) $perm['id'],
                'created_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('employee_transfers')) {
            $this->forge->dropTable('employee_transfers', true);
        }
    }
}