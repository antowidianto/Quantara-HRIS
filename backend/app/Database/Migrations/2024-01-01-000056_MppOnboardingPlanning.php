<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MPP Onboarding Planning (Sprint 5 — Onboarding Acceleration)
 *
 * Latar belakang masalah:
 *   - Job grade, atasan langsung (supervisor), dan manager kepegawaian
 *     selama ini diisi manual di profil karyawan setelah hire. Akibatnya
 *     HR harus cari & input ulang saat onboarding, sering miss/typo, dan
 *     karyawan baru harus menunggu satu-dua hari sebelum approval flow
 *     (cuti, lembur, attendance) bisa berjalan.
 *   - Saldo cuti tidak ter-generate otomatis untuk karyawan baru. Karyawan
 *     yang baru join harus minta ke HR untuk dibukakan, atau saldo baru
 *     muncul setelah HR mengakses halaman leave balance pertama kali.
 *   - Asset (laptop, HP, ID card, dst) di-assign manual saat onboarding,
 *     sering tertunda karena IT belum tahu kebutuhan ybs (laptop spec,
 *     access card area mana). Karyawan baru harus menunggu beberapa hari
 *     untuk dapat alat kerja → produktivitas hari pertama nol.
 *
 * Solusi:
 *   Pindahkan keputusan job_grade/supervisor/manager/daftar-asset ke
 *   tahap MPP submission, sehingga:
 *     1. Requestor (line manager) yang paling tahu siapa atasan & alat
 *        kerja apa yang dibutuhkan, tinggal isi sekali di MPP.
 *     2. Saat HRD approve MPP, data ikut tersimpan dan auto-flow ke
 *        job_postings → candidate → employee.
 *     3. IT bisa pre-stage laptop & access card sebelum hari pertama,
 *        karena daftar asset sudah final di MPP APPROVED.
 *     4. Onboarding hanya checklist BAST (penyerahan asset) + verifikasi
 *        data — tidak perlu nginput dari nol.
 *     5. Saldo cuti auto-generate saat hire dengan filter gender
 *        (cuti melahirkan/maternity hanya F, paternity hanya M, dst).
 *
 * Schema changes:
 *   1. `manpower_requests` — tambah:
 *        - job_grade_id (FK job_grades, nullable)
 *        - proposed_supervisor_employee_id (FK employees, nullable)
 *        - proposed_manager_employee_id (FK employees, nullable)
 *   2. `manpower_request_assets` — tabel baru:
 *        - 1 MPP : N planned assets
 *        - asset_id (FK assets, nullable — bisa belum tentu specific
 *          asset, mis. "1 laptop spec dev" tapi unit-nya nanti dipilih)
 *        - category, name, brand_model_pref, notes
 *        - is_required (TINYINT)
 *   3. `job_postings` — tambah:
 *        - job_grade_id (FK, nullable)
 *        - default_supervisor_employee_id (FK employees, nullable)
 *        - default_manager_employee_id (FK employees, nullable)
 *   4. `job_posting_assets` — tabel baru (mirror dari MPP, dipakai saat
 *      candidate convert → employee untuk pre-allocate asset).
 *
 * Idempotent: setiap perubahan kolom & tabel dicek dulu existence-nya.
 *
 * Referensi internal: docs/SPRINT_5_ONBOARDING_ACCEL.md (akan dibuat).
 */
class MppOnboardingPlanning extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. manpower_requests — planning fields ──────────────────
        if ($db->tableExists('manpower_requests')) {
            $cols = $db->getFieldNames('manpower_requests');
            $add  = [];
            if (! in_array('job_grade_id', $cols, true)) {
                $add['job_grade_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Job grade target untuk hire ini (FK job_grades.id)',
                ];
            }
            if (! in_array('proposed_supervisor_employee_id', $cols, true)) {
                $add['proposed_supervisor_employee_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Atasan langsung yang akan jadi reporting line karyawan baru',
                ];
            }
            if (! in_array('proposed_manager_employee_id', $cols, true)) {
                $add['proposed_manager_employee_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Manager kepegawaian (HR-side approver, bisa beda dengan supervisor)',
                ];
            }
            if ($add) {
                $forge->addColumn('manpower_requests', $add);
            }
        }

        // ─── 2. manpower_request_assets — planned assets per MPP ─────
        if (! $db->tableExists('manpower_request_assets')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'manpower_request_id' => ['type' => 'INT', 'unsigned' => true],
                'category'            => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'LAPTOP / DESKTOP / PHONE / VEHICLE / ID_CARD / ACCESS_CARD / TOOL / OTHER',
                ],
                'name'                => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Deskripsi singkat: "Laptop developer 16GB", "ID card area produksi"'],
                'brand_model_pref'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true,
                    'comment' => 'Preferensi brand/model dari requestor, opsional'],
                'asset_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Specific asset dari inventory (kalau requestor sudah lock unit). NULL = IT pilihkan.'],
                'is_required'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1,
                    'comment' => '1 = wajib, 0 = nice-to-have'],
                'notes'               => ['type' => 'TEXT', 'null' => true],
                'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['manpower_request_id']);
            $forge->addKey(['company_id', 'category']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('manpower_request_id', 'manpower_requests', 'id', 'CASCADE', 'CASCADE');
            // asset_id FK opsional — kalau di-set, harus ada di assets table
            // tapi pakai SET NULL on delete supaya kalau asset di-retire
            // record planning tidak ikut hilang.
            if ($db->tableExists('assets')) {
                $forge->addForeignKey('asset_id', 'assets', 'id', 'CASCADE', 'SET NULL');
            }
            $forge->createTable('manpower_request_assets');
        }

        // ─── 3. job_postings — carry-over planning fields ────────────
        if ($db->tableExists('job_postings')) {
            $cols = $db->getFieldNames('job_postings');
            $add  = [];
            if (! in_array('job_grade_id', $cols, true)) {
                $add['job_grade_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Job grade default — ikut dari MPP, bisa di-override saat publish',
                ];
            }
            if (! in_array('default_supervisor_employee_id', $cols, true)) {
                $add['default_supervisor_employee_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Atasan default kandidat saat di-hire',
                ];
            }
            if (! in_array('default_manager_employee_id', $cols, true)) {
                $add['default_manager_employee_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Manager default kandidat saat di-hire',
                ];
            }
            if ($add) {
                $forge->addColumn('job_postings', $add);
            }
        }

        // ─── 4. job_posting_assets — mirror dari MPP plan ────────────
        if (! $db->tableExists('job_posting_assets') && $db->tableExists('job_postings')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'job_posting_id'  => ['type' => 'INT', 'unsigned' => true],
                'category'        => ['type' => 'VARCHAR', 'constraint' => 32],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
                'brand_model_pref'=> ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
                'asset_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'is_required'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['job_posting_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'CASCADE');
            if ($db->tableExists('assets')) {
                $forge->addForeignKey('asset_id', 'assets', 'id', 'CASCADE', 'SET NULL');
            }
            $forge->createTable('job_posting_assets');
        }

        // ─── 5. asset_assignments — extend dengan PRE_ALLOCATED ──────
        // Pre-allocation status: assignment dibuat saat hire tapi BAST
        // belum di-tandatangani (belum diserahkan fisik). Saat onboarding,
        // HR/IT klik "Konfirmasi Serah Terima" dan status pindah ke
        // ASSIGNED biasa.
        if ($db->tableExists('asset_assignments')) {
            $cols = $db->getFieldNames('asset_assignments');
            if (! in_array('pre_allocated', $cols, true)) {
                $forge->addColumn('asset_assignments', [
                    'pre_allocated' => [
                        'type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                        'comment' => '1 = di-reserve dari MPP plan, BAST belum diserahkan; 2 = sudah handover',
                    ],
                ]);
            }
            if (! in_array('source_mpp_id', $cols, true)) {
                $forge->addColumn('asset_assignments', [
                    'source_mpp_id' => [
                        'type' => 'INT', 'unsigned' => true, 'null' => true,
                        'comment' => 'MPP yang melahirkan pre-allocation ini (audit trail)',
                    ],
                ]);
            }
        }

        // ─── 6. employees — flag untuk track initial leave balance ──
        // Tidak strict perlu, tapi helpful untuk diagnosa kalau auto-init
        // gagal di hire flow.
        if ($db->tableExists('employees')) {
            $cols = $db->getFieldNames('employees');
            if (! in_array('initial_balances_seeded_at', $cols, true)) {
                $forge->addColumn('employees', [
                    'initial_balances_seeded_at' => [
                        'type' => 'DATETIME', 'null' => true,
                        'comment' => 'Timestamp saat saldo cuti awal di-generate (idempotent flag)',
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;

        if ($db->tableExists('job_posting_assets'))    $this->forge->dropTable('job_posting_assets', true);
        if ($db->tableExists('manpower_request_assets')) $this->forge->dropTable('manpower_request_assets', true);

        // Drop kolom-kolom tambahan (best-effort)
        $dropCols = function (string $table, array $cols) use ($db) {
            if (! $db->tableExists($table)) return;
            $existing = $db->getFieldNames($table);
            foreach ($cols as $c) {
                if (in_array($c, $existing, true)) {
                    try { $this->forge->dropColumn($table, $c); } catch (\Throwable $e) {}
                }
            }
        };
        $dropCols('manpower_requests', [
            'job_grade_id', 'proposed_supervisor_employee_id', 'proposed_manager_employee_id',
        ]);
        $dropCols('job_postings', [
            'job_grade_id', 'default_supervisor_employee_id', 'default_manager_employee_id',
        ]);
        $dropCols('asset_assignments', ['pre_allocated', 'source_mpp_id']);
        $dropCols('employees', ['initial_balances_seeded_at']);
    }
}