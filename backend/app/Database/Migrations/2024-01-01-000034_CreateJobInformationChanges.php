<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Job Information Changes (JIC) — Promosi / Demosi / Mutasi / Reorganisasi.
 *
 * Mengikuti ketentuan ketenagakerjaan Indonesia:
 *   - UU 13/2003 jo. UU 6/2023 (Cipta Kerja) Pasal 31:
 *     setiap pekerja berhak mengembangkan kompetensi & memperoleh
 *     kesempatan promosi yang setara.
 *   - PP 35/2021: aturan PKWT/PKWTT, perubahan posisi tidak boleh
 *     memaksakan penurunan upah di bawah UMP/UMK.
 *   - Asas konsensual: DEMOSI yang menurunkan upah/jabatan tidak boleh
 *     dilakukan sepihak — wajib persetujuan tertulis karyawan, kecuali
 *     dasar disiplin pelanggaran berat (setelah SP3) atau efisiensi yang
 *     diatur PKB/PP.
 *   - SK Mutasi / Promosi wajib didokumentasikan (audit trail).
 *
 * Workflow standar:
 *   1. HR / atasan (initiator) bikin draft (DRAFT).
 *   2. Submit → PENDING_SUPERVISOR (atasan langsung dari karyawan).
 *   3. Approve → PENDING_DEPT_HEAD (manager_id dept tujuan).
 *   4. Approve → PENDING_HR (validasi UMP, dokumen, kategori).
 *   5. Approve →
 *        - PENDING_BOD bila DEMOTION atau perubahan grade ≥ threshold senior
 *        - APPROVED langsung untuk PROMOTION/LATERAL standar
 *   6. PENDING_EMPLOYEE_CONSENT bila change_type=DEMOTION dan bukan
 *      konsekuensi disipliner (SP3) → karyawan harus tanda tangan.
 *   7. APPROVED → tunggu effective_date.
 *   8. Pada effective_date, command apply-jic akan:
 *        - update employees (position/dept/grade/basic_salary/dst.)
 *        - generate SK / surat amandemen kontrak
 *        - notify payroll untuk recalculate allowance
 *        - status → IMPLEMENTED
 *
 * Multi-tenant: company_id isolasi. Privacy: hanya karyawan ybs +
 * supervisor + dept head + HR + BOD yang boleh view.
 *
 * Lihat docs/JIC.md.
 */
class CreateJobInformationChanges extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('job_information_changes')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'jic_number'          => [
                    'type' => 'VARCHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'Nomor referensi JIC (di-generate saat submit)',
                ],

                'change_type' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'PROMOTION',          // Naik grade/posisi/upah
                        'DEMOTION',           // Turun grade/posisi/upah
                        'LATERAL',            // Mutasi setara (dept/lokasi pindah, grade tetap)
                        'REORG',              // Restrukturisasi org (boleh apa saja, biasanya massal)
                        'SALARY_ADJUSTMENT',  // Penyesuaian upah saja (tahunan, merit, UMP)
                        'STATUS_CHANGE',      // PKWT → PKWTT, Probation → PKWTT
                    ],
                ],

                'reason_category' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'PERFORMANCE',        // Hasil review (perlu link performance_review_id)
                        'MERIT',              // Penghargaan kinerja (kenaikan upah)
                        'TENURE',             // Masa kerja / senioritas
                        'COMPETENCY',         // Lulus sertifikasi / training
                        'RESTRUCTURE',        // Reorg perusahaan
                        'DISCIPLINARY',       // Konsekuensi pelanggaran (perlu SP3)
                        'EMPLOYEE_REQUEST',   // Atas permintaan karyawan
                        'PROBATION_END',      // Akhir masa probation lulus
                        'CONTRACT_RENEWAL',   // Perpanjangan PKWT
                        'UMP_ADJUSTMENT',     // Penyesuaian UMP/UMK tahunan
                        'OTHER',
                    ],
                ],
                'reason_text'         => ['type' => 'TEXT', 'null' => true],

                // Tanggal-tanggal kunci
                'proposed_at'         => ['type' => 'DATETIME', 'null' => true],
                'submitted_at'        => ['type' => 'DATETIME', 'null' => true],
                'effective_date'      => [
                    'type' => 'DATE',
                    'comment' => 'Tanggal mulai berlaku perubahan; tidak boleh < hari ini saat APPROVED',
                ],
                'notice_date'         => [
                    'type' => 'DATE', 'null' => true,
                    'comment' => 'Tanggal pemberitahuan resmi ke karyawan (untuk SK / amandemen)',
                ],

                // ─── SNAPSHOT POSISI LAMA ──────────────────────────────
                // Disimpan saat submit supaya tidak terpengaruh perubahan
                // master setelahnya.
                'old_position_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_position_name'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_department_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_department_name'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'old_branch_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_work_location_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_job_grade_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_job_grade_name'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'old_employment_status'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'old_basic_salary'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'old_supervisor_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'old_manager_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // ─── POSISI BARU ───────────────────────────────────────
                'new_position_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_department_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_branch_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_work_location_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_job_grade_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_employment_status'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'new_basic_salary'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'new_supervisor_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'new_manager_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // ─── COMPLIANCE INDONESIA ──────────────────────────────
                'salary_change_pct'       => [
                    'type' => 'DECIMAL', 'constraint' => '8,4', 'null' => true,
                    'comment' => 'Persentase perubahan upah (negatif = turun)',
                ],
                'ump_reference'           => [
                    'type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true,
                    'comment' => 'UMP/UMK rujukan saat submit (snapshot dari company_settings.minimum_wage)',
                ],
                'ump_check_status'        => [
                    'type' => 'ENUM',
                    'constraint' => ['PASS', 'FAIL', 'NOT_APPLICABLE', 'OVERRIDE'],
                    'default' => 'NOT_APPLICABLE',
                    'comment' => 'PASS = upah baru ≥ UMP, FAIL = di bawah UMP, OVERRIDE = HR override sah (PKB)',
                ],
                'ump_override_reason'     => ['type' => 'TEXT', 'null' => true],

                // Karyawan wajib consent untuk DEMOTION non-disipliner.
                // Pasal 31 UU 13/2003 + asas konsensual perjanjian kerja.
                'employee_consent_required' => [
                    'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => 'Auto-set 1 bila change_type=DEMOTION & reason ≠ DISCIPLINARY',
                ],
                'employee_consent_status' => [
                    'type' => 'ENUM',
                    'constraint' => ['NOT_REQUIRED', 'PENDING', 'AGREED', 'REJECTED'],
                    'default' => 'NOT_REQUIRED',
                ],
                'employee_consent_at'     => ['type' => 'DATETIME', 'null' => true],
                'employee_consent_note'   => ['type' => 'TEXT', 'null' => true],
                'employee_consent_signed_path' => [
                    'type' => 'VARCHAR', 'constraint' => 500, 'null' => true,
                    'comment' => 'Surat persetujuan/penolakan ditandatangani karyawan',
                ],

                // Link dokumen pendukung sesuai ketentuan
                'disciplinary_record_id'  => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Wajib jika reason=DISCIPLINARY (rujukan SP)',
                ],
                'performance_review_id'   => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Rujukan performance review jika reason=PERFORMANCE/MERIT',
                ],
                'mpp_id'                  => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Manpower Plan kalau reason=RESTRUCTURE',
                ],
                'training_certificate_id' => [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Sertifikasi pendukung kalau reason=COMPETENCY',
                ],

                // ─── WORKFLOW ──────────────────────────────────────────
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'DRAFT',
                        'PENDING_SUPERVISOR',
                        'PENDING_DEPT_HEAD',
                        'PENDING_HR',
                        'PENDING_BOD',
                        'PENDING_EMPLOYEE_CONSENT',
                        'APPROVED',          // Lolos semua approval, menunggu effective_date
                        'IMPLEMENTED',       // Sudah di-apply ke records
                        'REJECTED',
                        'CANCELLED',
                    ],
                    'default' => 'DRAFT',
                ],

                'supervisor_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'supervisor_action_at'=> ['type' => 'DATETIME', 'null' => true],
                'supervisor_decision' => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],
                'supervisor_note'     => ['type' => 'TEXT', 'null' => true],

                'dept_head_user_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'dept_head_action_at' => ['type' => 'DATETIME', 'null' => true],
                'dept_head_decision'  => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],
                'dept_head_note'      => ['type' => 'TEXT', 'null' => true],

                'hr_user_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'hr_action_at'        => ['type' => 'DATETIME', 'null' => true],
                'hr_decision'         => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],
                'hr_note'             => ['type' => 'TEXT', 'null' => true],

                'bod_user_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'bod_action_at'       => ['type' => 'DATETIME', 'null' => true],
                'bod_decision'        => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED', 'REJECTED'],
                    'null' => true,
                ],
                'bod_note'            => ['type' => 'TEXT', 'null' => true],

                // ─── SK / SURAT AMANDEMEN ──────────────────────────────
                'sk_number'           => [
                    'type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                    'comment' => 'Nomor Surat Keputusan (auto-generate saat APPROVED)',
                ],
                'sk_generated_at'     => ['type' => 'DATETIME', 'null' => true],
                'sk_html'             => [
                    'type' => 'LONGTEXT', 'null' => true,
                    'comment' => 'Snapshot HTML SK setelah render template',
                ],
                'sk_signed_path'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'sk_template_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],

                // ─── IMPLEMENTASI ─────────────────────────────────────
                'implemented_at'      => ['type' => 'DATETIME', 'null' => true],
                'implemented_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'implementation_note' => ['type' => 'TEXT', 'null' => true],

                // ─── CANCELLATION ─────────────────────────────────────
                'cancelled_at'        => ['type' => 'DATETIME', 'null' => true],
                'cancelled_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'cancellation_reason' => ['type' => 'TEXT', 'null' => true],

                // Audit
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'jic_number']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addKey(['change_type', 'effective_date']);
            $forge->addKey('effective_date');
            $forge->addForeignKey('company_id',  'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('disciplinary_record_id', 'disciplinary_records', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('job_information_changes');
        }

        // ─── PERMISSIONS ────────────────────────────────────────────
        $newPermissions = [
            ['slug' => 'jic.view',      'name' => 'View Job Information Changes',           'group' => 'hr'],
            ['slug' => 'jic.create',    'name' => 'Create Job Information Change',          'group' => 'hr'],
            ['slug' => 'jic.approve',   'name' => 'Approve Job Information Change (HR/BOD)','group' => 'hr'],
            ['slug' => 'jic.manage',    'name' => 'Manage Job Information Change (HR full)','group' => 'hr'],
            ['slug' => 'jic.implement', 'name' => 'Implement (apply) approved JIC',         'group' => 'hr'],
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
    }

    public function down(): void
    {
        if ($this->db->tableExists('job_information_changes')) {
            $this->forge->dropTable('job_information_changes', true);
        }
    }
}