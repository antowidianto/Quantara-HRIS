<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Improvement set untuk:
 *   1. Data kepegawaian sesuai konteks Indonesia (KK, ibu kandung, suku,
 *      kewarganegaraan, alamat KTP vs domisili, pendidikan terakhir, dll).
 *   2. Master lokasi kerja (di luar `branches`): kantor pusat, kantor cabang
 *      tambahan, project site, WFH, client office. Lengkap dengan riwayat
 *      penugasan per karyawan.
 *   3. Pola persetujuan (approval chain) yang bisa dikustomisasi per modul:
 *      LEAVE / OVERTIME / ATTENDANCE_CHANGE / REIMBURSEMENT / LOAN.
 *      Setiap chain punya beberapa step terurut (Atasan → HR → Direktur, dst.)
 *      dengan tipe approver yang fleksibel.
 *   4. Generator kontrak + peraturan perusahaan yang sepenuhnya editable
 *      lewat aplikasi: template HTML dengan placeholder + library klausul.
 *
 * Migration ini idempotent: aman dijalankan ulang.
 */
class EnhanceEmployeeAndApprovalAndContract extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. Tambah kolom kepegawaian khas Indonesia ─────────────
        $employeeAdditions = [
            'kk_number'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'comment' => 'No. Kartu Keluarga'],
            'mother_name'           => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'comment' => 'Nama ibu kandung (verifikasi bank/BPJS)'],
            'father_name'           => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'citizenship'           => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'WNI', 'comment' => 'WNI/WNA + kode negara'],
            'ethnicity'             => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'comment' => 'Suku (opsional)'],
            'education_level'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'comment' => 'Pendidikan terakhir: SD/SMP/SMA/SMK/D1/D2/D3/D4/S1/S2/S3'],
            'marital_certificate_no'=> ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'comment' => 'No. akta nikah / cerai'],

            // Alamat KTP vs domisili — sering berbeda di Indonesia.
            'ktp_address'           => ['type' => 'TEXT', 'null' => true, 'comment' => 'Alamat sesuai KTP'],
            'ktp_rt_rw'             => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'ktp_kelurahan'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'ktp_kecamatan'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'ktp_city'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'ktp_province'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'ktp_postal_code'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],

            // Lokasi kerja default + atasan langsung (selain manager_id, kita
            // tambahkan supervisor_id agar bisa membedakan reporting line vs
            // garis approval struktural).
            'work_location_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supervisor_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'Atasan langsung untuk approval (boleh ≠ manager_id)'],

            // Approval chain default per karyawan — kalau null → fallback ke
            // assignment rule by department/branch/grade.
            'approval_chain_leave_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approval_chain_overtime_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approval_chain_attendance_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ];
        $existingEmpCols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
        $toAdd = [];
        foreach ($employeeAdditions as $col => $def) {
            if (! in_array(strtolower($col), $existingEmpCols, true)) {
                $toAdd[$col] = $def;
            }
        }
        if (! empty($toAdd)) {
            $forge->addColumn('employees', $toAdd);
        }
        $this->createIndexIfMissing($db, 'employees', 'employees_supervisor_idx', ['supervisor_id']);
        $this->createIndexIfMissing($db, 'employees', 'employees_worklocation_idx', ['work_location_id']);

        // ─── 2. WORK LOCATIONS ───────────────────────────────────────
        // Lokasi kerja yang lebih granular dari `branches`. Mis. PT punya
        // 1 cabang Jakarta tapi karyawannya bisa ditempatkan di:
        //  - HQ Sudirman, kantor cabang Sudirman, project site BSD,
        //    WFH, client office Astra, dll.
        // Kita pisahkan supaya geofence absensi & laporan bisa sub-branch.
        if (! $db->tableExists('work_locations')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'Lokasi induk kalau ada'],
                'code'            => ['type' => 'VARCHAR', 'constraint' => 32],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
                'type'            => [
                    'type' => 'ENUM',
                    'constraint' => ['HEAD_OFFICE','BRANCH_OFFICE','SUB_OFFICE','PROJECT_SITE','CLIENT_OFFICE','WAREHOUSE','FACTORY','REMOTE','WFH','OTHER'],
                    'default' => 'BRANCH_OFFICE',
                ],
                'address'         => ['type' => 'TEXT', 'null' => true],
                'province'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'city'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'postal_code'     => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
                'phone'           => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'lat'             => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
                'lng'             => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
                'geofence_radius_m' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'allow_clock_here' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => '1 = boleh dijadikan titik clock-in'],
                'manager_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey('company_id');
            $forge->addKey('branch_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('branch_id', 'branches', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('work_locations');
        }

        // ─── 3. EMPLOYEE WORK LOCATION HISTORY ──────────────────────
        if (! $db->tableExists('employee_work_locations')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'employee_id'       => ['type' => 'INT', 'unsigned' => true],
                'work_location_id'  => ['type' => 'INT', 'unsigned' => true],
                'assignment_type'   => [
                    'type' => 'ENUM',
                    'constraint' => ['PRIMARY','SECONDARY','TEMPORARY','PROJECT','REMOTE'],
                    'default' => 'PRIMARY',
                ],
                'date_from'         => ['type' => 'DATE'],
                'date_to'           => ['type' => 'DATE', 'null' => true, 'comment' => 'NULL = aktif'],
                'notes'             => ['type' => 'TEXT', 'null' => true],
                'created_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['employee_id', 'date_from']);
            $forge->addKey('work_location_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('work_location_id', 'work_locations', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('employee_work_locations');
        }

        // ─── 4. APPROVAL CHAINS ─────────────────────────────────────
        // Chain = nama dari sebuah pola approval. Mis.
        //   "Cuti Standar Staff"     → step1 Atasan, step2 HR
        //   "Cuti VIP Manager"       → step1 HR, step2 Direktur
        //   "Lembur Operasional"     → step1 Atasan only
        if (! $db->tableExists('approval_chains')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true],
                'code'       => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
                'module'     => [
                    'type' => 'ENUM',
                    'constraint' => ['LEAVE','OVERTIME','ATTENDANCE_CHANGE','REIMBURSEMENT','LOAN','GENERIC'],
                    'default' => 'LEAVE',
                ],
                'description'   => ['type' => 'TEXT', 'null' => true],
                'is_default'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'comment' => '1 = chain default untuk modul ini di company'],
                // Strategi resolusi step yang gagal (mis. approver tidak ada).
                'fallback_strategy' => [
                    'type' => 'ENUM',
                    'constraint' => ['SKIP','BLOCK','HR_FALLBACK'],
                    'default' => 'HR_FALLBACK',
                    'comment' => 'SKIP = skip step, BLOCK = stuck di pending, HR_FALLBACK = HR jadi approver',
                ],
                'is_active'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'module']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_chains');
        }

        // ─── 5. APPROVAL CHAIN STEPS ────────────────────────────────
        if (! $db->tableExists('approval_chain_steps')) {
            $forge->addField([
                'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'        => ['type' => 'INT', 'unsigned' => true],
                'chain_id'          => ['type' => 'INT', 'unsigned' => true],
                'step_order'        => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'name'              => ['type' => 'VARCHAR', 'constraint' => 100, 'comment' => 'Mis. "Persetujuan Atasan"'],
                'approver_type'     => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'SUPERVISOR',         // Atasan langsung (employees.supervisor_id)
                        'MANAGER',            // Manager (employees.manager_id)
                        'DEPARTMENT_HEAD',    // departments.manager_employee_id
                        'BRANCH_HEAD',        // work_locations.manager_employee_id atau branch
                        'HR',                 // role HR Admin
                        'FINANCE',            // role Finance
                        'ROLE',               // user dengan role tertentu
                        'SPECIFIC_USER',      // user spesifik
                        'SPECIFIC_EMPLOYEE',  // employee spesifik (lookup user-nya)
                    ],
                    'default' => 'SUPERVISOR',
                ],
                'approver_role_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approver_user_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approver_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                // Threshold opsional. Mis. step ini hanya wajib kalau days >= 5
                // atau amount >= 1.000.000.
                'condition_field'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'days/hours/amount/...'],
                'condition_op'      => ['type' => 'ENUM', 'constraint' => ['>','>=','<','<=','=','!='], 'null' => true],
                'condition_value'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'is_required'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => '0 = optional/notify only'],
                'allow_skip_if_self'=> ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => '1 = skip step kalau approver = pemohon'],
                'sla_hours'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'Auto escalate setelah X jam'],
                'created_at'        => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['chain_id', 'step_order']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('chain_id', 'approval_chains', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_chain_steps');
        }

        // ─── 6. APPROVAL ASSIGNMENT RULES ───────────────────────────
        // Rule pemetaan: chain mana yang dipakai untuk modul + scope tertentu.
        // Prioritas: employee-spesifik (di kolom employees.approval_chain_*_id)
        // > rule by job_grade > by department > by branch > company default.
        if (! $db->tableExists('approval_assignments')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'chain_id'       => ['type' => 'INT', 'unsigned' => true],
                'module'         => [
                    'type' => 'ENUM',
                    'constraint' => ['LEAVE','OVERTIME','ATTENDANCE_CHANGE','REIMBURSEMENT','LOAN','GENERIC'],
                ],
                'department_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'job_grade_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'priority'       => ['type' => 'INT', 'unsigned' => true, 'default' => 100, 'comment' => 'Lebih kecil = prioritas lebih tinggi'],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'module']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('chain_id', 'approval_chains', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_assignments');
        }

        // ─── 7. APPROVAL REQUEST INSTANCES ──────────────────────────
        // Setiap pengajuan dari LeaveController/OvertimeController/dll. mencatat
        // 1 baris di sini sebagai pintu masuk ke chain. Tabel ini ringan,
        // hanya menyimpan pointer ke entitas asli + state chain saat ini.
        if (! $db->tableExists('approval_requests')) {
            $forge->addField([
                'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'module'         => [
                    'type' => 'ENUM',
                    'constraint' => ['LEAVE','OVERTIME','ATTENDANCE_CHANGE','REIMBURSEMENT','LOAN','GENERIC'],
                ],
                'entity_table'   => ['type' => 'VARCHAR', 'constraint' => 64, 'comment' => 'mis. leaves, overtimes'],
                'entity_id'      => ['type' => 'BIGINT', 'unsigned' => true],
                'employee_id'    => ['type' => 'INT', 'unsigned' => true],
                'chain_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'current_step_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true, 'comment' => 'approval_chain_steps.id step yg sedang menunggu'],
                'current_step_order' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'status'         => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING','APPROVED','REJECTED','CANCELLED'],
                    'default' => 'PENDING',
                ],
                'final_decided_at' => ['type' => 'DATETIME', 'null' => true],
                'meta'           => ['type' => 'JSON', 'null' => true, 'comment' => 'Snapshot field penting untuk evaluasi kondisi (days/hours/amount)'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['module', 'entity_id']);
            $forge->addKey(['employee_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_requests');
        }

        // ─── 8. APPROVAL DECISIONS LOG ──────────────────────────────
        if (! $db->tableExists('approval_decisions')) {
            $forge->addField([
                'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'approval_request_id' => ['type' => 'BIGINT', 'unsigned' => true],
                'chain_step_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'step_order'          => ['type' => 'INT', 'unsigned' => true],
                'approver_user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approver_role'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'Resolved approver_type'],
                'decision'            => [
                    'type' => 'ENUM',
                    'constraint' => ['APPROVED','REJECTED','SKIPPED','DELEGATED','PENDING'],
                    'default' => 'PENDING',
                ],
                'decided_at'          => ['type' => 'DATETIME', 'null' => true],
                'note'                => ['type' => 'TEXT', 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey('approval_request_id');
            $forge->addForeignKey('approval_request_id', 'approval_requests', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('approval_decisions');
        }

        // ─── 9. CONTRACT TEMPLATES ──────────────────────────────────
        // Template kontrak yang sepenuhnya editable lewat UI. Body memakai
        // placeholder Mustache-like: {{employee.full_name}}, {{contract.start_date}},
        // {{company.name}}, {{regulations}}, dll.
        if (! $db->tableExists('contract_templates')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'code'           => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'contract_type'  => [
                    'type' => 'ENUM',
                    'constraint' => ['PKWT','PKWTT','PROBATION','PKHL','MAGANG','OUTSOURCING','GENERIC'],
                    'default' => 'PKWTT',
                ],
                'language'       => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'id'],
                'header_html'    => ['type' => 'LONGTEXT', 'null' => true, 'comment' => 'Kop, logo'],
                'body_html'      => ['type' => 'LONGTEXT', 'comment' => 'Isi kontrak dgn placeholder {{}}'],
                'footer_html'    => ['type' => 'LONGTEXT', 'null' => true, 'comment' => 'TTD, materai'],
                'description'    => ['type' => 'TEXT', 'null' => true],
                'include_regulations' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => '1 = sisipkan klausul peraturan perusahaan'],
                'is_default'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey('company_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('contract_templates');
        }

        // ─── 10. COMPANY REGULATIONS / KLAUSUL ─────────────────────
        // Library klausul / pasal peraturan perusahaan. Bisa di-include ke
        // template kontrak via {{regulations}} atau dipanggil per kategori
        // {{regulations.LEAVE}}.
        if (! $db->tableExists('company_regulations')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'category'       => [
                    'type' => 'ENUM',
                    'constraint' => ['GENERAL','WORKING_HOURS','LEAVE','SALARY','OVERTIME','DISCIPLINE','RESIGNATION','CONFIDENTIALITY','INTELLECTUAL_PROPERTY','BENEFITS','TERMINATION','OTHER'],
                    'default' => 'GENERAL',
                ],
                'code'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'title'          => ['type' => 'VARCHAR', 'constraint' => 255],
                'order_no'       => ['type' => 'INT', 'unsigned' => true, 'default' => 100],
                'body_html'      => ['type' => 'LONGTEXT', 'comment' => 'Isi pasal/klausul, boleh placeholder'],
                'effective_from' => ['type' => 'DATE', 'null' => true],
                'effective_to'   => ['type' => 'DATE', 'null' => true],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'category']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('company_regulations');
        }

        // ─── 11. GENERATED CONTRACTS (snapshot HTML) ───────────────
        // Setiap kali HR generate kontrak, snapshot HTML-nya disimpan supaya
        // perubahan template di masa depan tidak mengubah dokumen yg sudah
        // ditandatangani. employee_contract_id referensi ke kontrak yang
        // sudah ada (PKWT/PKWTT row di employee_contracts).
        if (! $db->tableExists('generated_contracts')) {
            $forge->addField([
                'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'employee_contract_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'template_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'contract_number'     => ['type' => 'VARCHAR', 'constraint' => 64],
                'subject'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'rendered_html'       => ['type' => 'LONGTEXT', 'comment' => 'Hasil render placeholder, snapshot final'],
                'variables_snapshot'  => ['type' => 'JSON', 'null' => true],
                'status'              => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','SENT','SIGNED','REVOKED'],
                    'default' => 'DRAFT',
                ],
                'sent_at'             => ['type' => 'DATETIME', 'null' => true],
                'signed_at'           => ['type' => 'DATETIME', 'null' => true],
                'signed_url'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'URL ke file PDF tertandatangan / e-sign provider'],
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey('employee_id');
            $forge->addKey('employee_contract_id');
            $forge->addUniqueKey(['company_id', 'contract_number']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_contract_id', 'employee_contracts', 'id', 'CASCADE', 'SET NULL');
            $forge->addForeignKey('template_id', 'contract_templates', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('generated_contracts');
        }

        // Tambahkan permission baru.
        $newPermissions = [
            ['slug' => 'work_location.view',  'name' => 'View Work Locations',  'group' => 'master'],
            ['slug' => 'work_location.manage','name' => 'Manage Work Locations','group' => 'master'],
            ['slug' => 'approval_chain.view', 'name' => 'View Approval Chains', 'group' => 'admin'],
            ['slug' => 'approval_chain.manage','name'=> 'Manage Approval Chains','group' => 'admin'],
            ['slug' => 'contract_template.view',  'name' => 'View Contract Templates',  'group' => 'admin'],
            ['slug' => 'contract_template.manage','name' => 'Manage Contract Templates','group' => 'admin'],
            ['slug' => 'regulation.view',     'name' => 'View Company Regulations',  'group' => 'admin'],
            ['slug' => 'regulation.manage',   'name' => 'Manage Company Regulations','group' => 'admin'],
            ['slug' => 'contract.generate',   'name' => 'Generate Employee Contract', 'group' => 'employee'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant ke HR Admin (role_id 2) & Super Admin (role_id 1).
        $hrSlugs = array_column($newPermissions, 'slug');
        foreach (['1' => $hrSlugs, '2' => $hrSlugs] as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', (int) $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id' => (int) $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        foreach ([
            'generated_contracts',
            'company_regulations',
            'contract_templates',
            'approval_decisions',
            'approval_requests',
            'approval_assignments',
            'approval_chain_steps',
            'approval_chains',
            'employee_work_locations',
            'work_locations',
        ] as $tbl) {
            if ($db->tableExists($tbl)) $forge->dropTable($tbl, true);
        }

        // Drop tambahan kolom employees
        $cols = ['kk_number','mother_name','father_name','citizenship','ethnicity',
            'education_level','marital_certificate_no','ktp_address','ktp_rt_rw',
            'ktp_kelurahan','ktp_kecamatan','ktp_city','ktp_province','ktp_postal_code',
            'work_location_id','supervisor_id',
            'approval_chain_leave_id','approval_chain_overtime_id','approval_chain_attendance_id'];
        $existing = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
        foreach ($cols as $c) {
            if (in_array(strtolower($c), $existing, true)) {
                try { $forge->dropColumn('employees', $c); } catch (\Throwable $e) {}
            }
        }
    }

    private function createIndexIfMissing($db, string $table, string $indexName, array $columns): void
    {
        try {
            $driver = strtolower($db->getPlatform());
            if (str_contains($driver, 'sqlite')) {
                $rows = $db->query("PRAGMA index_list('{$table}')")->getResultArray();
                foreach ($rows as $r) {
                    if (strtolower($r['name']) === strtolower($indexName)) return;
                }
            } else {
                $rows = $db->query("SHOW INDEX FROM `{$table}`")->getResultArray();
                foreach ($rows as $r) {
                    if (strtolower($r['Key_name']) === strtolower($indexName)) return;
                }
            }
            $cols = '`' . implode('`, `', $columns) . '`';
            $db->query("CREATE INDEX {$indexName} ON {$table} ({$cols})");
        } catch (\Throwable $e) {
            log_message('warning', "createIndexIfMissing({$indexName}): " . $e->getMessage());
        }
    }
}