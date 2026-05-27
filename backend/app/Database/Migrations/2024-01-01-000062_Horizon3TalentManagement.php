<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Horizon3TalentManagement (Sprint 14 + 17).
 *
 * Migration kombinasi untuk Talent Management cluster Horizon 3:
 *
 *   H3.1 Modul Kompetensi & Library Jabatan
 *     - tabel `competencies` (master taxonomy)
 *     - tabel `competency_levels` (deskriptor 1-5 per kompetensi)
 *     - tabel `position_competency_profiles` (target proficiency per posisi)
 *     - tabel `employee_competency_assessments` (penilaian aktual karyawan)
 *
 *   H3.2 Succession Planning
 *     - tabel `critical_positions` (posisi kunci yang perlu successor)
 *     - tabel `succession_candidates` (mapping employee × critical position)
 *
 *   H3.6 Talent Pool & Internal Marketplace
 *     - tabel `employee_skills` (skill self-declared / di-validate)
 *     - tabel `employee_aspirations` (career goal employee)
 *     - tabel `internal_job_postings` (posting internal sebelum public)
 *     - tabel `internal_applications` (lamaran internal)
 *
 * Idempotent: cek tableExists sebelum create. Schema baru, tidak ada
 * ALTER TABLE existing.
 *
 * Permission baru di-seed via method privat:
 *   - competency.view, competency.manage
 *   - succession.view, succession.manage
 *   - talent_pool.view, talent_pool.manage, internal_job.apply
 */
class Horizon3TalentManagement extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        $forge = $this->forge;

        // ─── H3.1 Competency Master ─────────────────────────────────
        if (! $db->tableExists('competencies')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 32,
                    'comment' => 'COMM_LEAD, TECH_PHP, dst — slug uppercase'],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'category'     => [
                    'type' => 'ENUM',
                    'constraint' => ['CORE', 'LEADERSHIP', 'FUNCTIONAL', 'TECHNICAL', 'BEHAVIORAL'],
                    'default' => 'FUNCTIONAL',
                    'comment' => 'CORE = wajib semua karyawan; LEADERSHIP = manager+; '
                        . 'FUNCTIONAL = per peran; TECHNICAL = hard skill; BEHAVIORAL = soft skill',
                ],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'sort_order'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code'], 'uniq_competency_code');
            $forge->addKey(['company_id', 'category', 'is_active']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('competencies');
        }

        if (! $db->tableExists('competency_levels')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'competency_id'  => ['type' => 'INT', 'unsigned' => true],
                'level_number'   => ['type' => 'TINYINT', 'unsigned' => true,
                    'comment' => '1-5 (1=basic, 5=expert)'],
                'label'          => ['type' => 'VARCHAR', 'constraint' => 100,
                    'comment' => 'Foundational / Working / Advanced / Expert / Master'],
                'descriptor'     => ['type' => 'TEXT',
                    'comment' => 'Behavioral indicator yang observable di level ini'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['competency_id', 'level_number'], 'uniq_competency_level');
            $forge->addForeignKey('competency_id', 'competencies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('competency_levels');
        }

        if (! $db->tableExists('position_competency_profiles')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'position_id'    => ['type' => 'INT', 'unsigned' => true],
                'competency_id'  => ['type' => 'INT', 'unsigned' => true],
                'target_level'   => ['type' => 'TINYINT', 'unsigned' => true,
                    'comment' => 'Required proficiency level untuk posisi ini'],
                'weight'         => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 1,
                    'comment' => 'Bobot relative untuk gap analysis'],
                'is_critical'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = must-have (gate untuk promosi)'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['position_id', 'competency_id'], 'uniq_pos_comp');
            $forge->addKey(['company_id', 'position_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('competency_id', 'competencies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('position_competency_profiles');
        }

        if (! $db->tableExists('employee_competency_assessments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true],
                'competency_id'   => ['type' => 'INT', 'unsigned' => true],
                'current_level'   => ['type' => 'TINYINT', 'unsigned' => true,
                    'comment' => 'Aktual proficiency 1-5'],
                'evidence'        => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Bukti / contoh demonstrasi level ini (project, training, certificate)'],
                'assessor_type'   => [
                    'type' => 'ENUM',
                    'constraint' => ['SELF', 'SUPERVISOR', 'PEER', 'HR', 'CERTIFICATION'],
                    'default' => 'SUPERVISOR',
                ],
                'assessor_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'assessed_at'     => ['type' => 'DATETIME', 'null' => true],
                'valid_until'     => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Untuk certification (mis. AWS expire 3 tahun)'],
                'cycle_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Optional link ke performance_cycle'],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id', 'competency_id']);
            $forge->addKey(['cycle_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('competency_id', 'competencies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('employee_competency_assessments');
        }

        // Hubungkan IDP item ke competency_id (tabel idps + idp_items
        // sudah ada di schema existing — kita defensive ALTER kalau kolom belum ada).
        if ($db->tableExists('idps')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('idps'));
            if (! in_array('competency_id', $cols, true)) {
                $forge->addColumn('idps', [
                    'competency_id' => [
                        'type' => 'INT', 'unsigned' => true, 'null' => true,
                        'comment' => 'H3.1: link IDP ke competency yang dikembangkan',
                    ],
                ]);
            }
        }

        // Tag training catalog ke competency (training_catalogs / trainings sudah ada).
        if ($db->tableExists('trainings')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('trainings'));
            if (! in_array('competency_id', $cols, true)) {
                $forge->addColumn('trainings', [
                    'competency_id' => [
                        'type' => 'INT', 'unsigned' => true, 'null' => true,
                        'comment' => 'H3.1: training mengembangkan competency mana',
                    ],
                ]);
            }
        }

        // ─── H3.2 Succession Planning ───────────────────────────────
        if (! $db->tableExists('critical_positions')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'position_id'     => ['type' => 'INT', 'unsigned' => true],
                'criticality'     => [
                    'type' => 'ENUM', 'constraint' => ['HIGH', 'MEDIUM', 'LOW'],
                    'default' => 'MEDIUM',
                    'comment' => 'HIGH = bisnis berhenti kalau kosong; MEDIUM = >1 bulan disrupt; LOW = <1 bulan',
                ],
                'risk_factor'     => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Mengapa kritis (single-person knowledge, hard to recruit, dll)'],
                'incumbent_employee_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Karyawan yang saat ini menempati'],
                'min_successor_count' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2,
                    'comment' => 'Target minimal jumlah successor ready (default 2)'],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'position_id'], 'uniq_critical_pos');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('critical_positions');
        }

        if (! $db->tableExists('succession_candidates')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'critical_position_id'=> ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'readiness'           => [
                    'type' => 'ENUM',
                    'constraint' => ['READY_NOW', 'READY_1Y', 'READY_2Y', 'READY_3Y_PLUS', 'NOT_READY'],
                    'default' => 'READY_2Y',
                ],
                'gap_score'           => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => '0-100 gap analysis terhadap profil posisi (0=fully ready)'],
                'development_plan_idp_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Link ke IDP yang dibikin khusus mengisi gap ini'],
                'nominated_by_user_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'nominated_at'        => ['type' => 'DATETIME', 'null' => true],
                'last_reviewed_at'    => ['type' => 'DATETIME', 'null' => true],
                'notes'               => ['type' => 'TEXT', 'null' => true],
                'is_active'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['critical_position_id', 'employee_id'], 'uniq_succession_candidate');
            $forge->addKey(['company_id', 'readiness']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('critical_position_id', 'critical_positions', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('succession_candidates');
        }

        // ─── H3.6 Talent Pool & Internal Marketplace ────────────────
        if (! $db->tableExists('employee_skills')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'employee_id'  => ['type' => 'INT', 'unsigned' => true],
                'skill_name'   => ['type' => 'VARCHAR', 'constraint' => 100],
                'skill_slug'   => ['type' => 'VARCHAR', 'constraint' => 100,
                    'comment' => 'lowercase normalized untuk matching'],
                'proficiency'  => [
                    'type' => 'ENUM',
                    'constraint' => ['BEGINNER', 'INTERMEDIATE', 'ADVANCED', 'EXPERT'],
                    'default' => 'INTERMEDIATE',
                ],
                'years_experience' => ['type' => 'DECIMAL', 'constraint' => '4,1', 'null' => true],
                'is_validated' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                    'comment' => '1 = di-validate supervisor / via assessment'],
                'validated_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'validated_at' => ['type' => 'DATETIME', 'null' => true],
                'evidence'     => ['type' => 'TEXT', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['employee_id', 'skill_slug'], 'uniq_employee_skill');
            $forge->addKey(['company_id', 'skill_slug']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('employee_skills');
        }

        if (! $db->tableExists('employee_aspirations')) {
            $forge->addField([
                'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'          => ['type' => 'INT', 'unsigned' => true],
                'employee_id'         => ['type' => 'INT', 'unsigned' => true],
                'aspiration_type'     => [
                    'type' => 'ENUM',
                    'constraint' => ['POSITION', 'DEPARTMENT', 'CAREER_TRACK', 'SKILL_DEVELOPMENT', 'GEOGRAPHY'],
                    'default' => 'POSITION',
                ],
                'target_position_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'target_department_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'target_branch_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'target_text'         => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Free-form aspiration kalau tidak match ke FK existing'],
                'timeline'            => [
                    'type' => 'ENUM',
                    'constraint' => ['IMMEDIATE', 'SHORT_TERM', 'MID_TERM', 'LONG_TERM'],
                    'default' => 'MID_TERM',
                    'comment' => 'IMMEDIATE = <6 bulan, SHORT = 6-12, MID = 1-3 thn, LONG = 3+ thn',
                ],
                'visibility'          => [
                    'type' => 'ENUM', 'constraint' => ['SUPERVISOR', 'HR', 'COMPANY'], 'default' => 'SUPERVISOR',
                    'comment' => 'Siapa yang boleh lihat (privacy karyawan)',
                ],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addKey(['target_position_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('target_position_id', 'positions', 'id', 'CASCADE', 'SET NULL');
            $forge->addForeignKey('target_department_id', 'departments', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('employee_aspirations');
        }

        if (! $db->tableExists('internal_job_postings')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'job_posting_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Optional FK ke job_postings public — internal first, public later'],
                'position_id'     => ['type' => 'INT', 'unsigned' => true],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'title'           => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'     => ['type' => 'TEXT', 'null' => true],
                'requirements'    => ['type' => 'TEXT', 'null' => true],
                'min_tenure_months' => ['type' => 'INT', 'unsigned' => true, 'default' => 12,
                    'comment' => 'Eligibility: harus karyawan ≥ N bulan'],
                'eligibility_json'=> ['type' => 'TEXT', 'null' => true,
                    'comment' => 'JSON filter: {min_grade:N, departments:[], performance_rating_min:X}'],
                'opens_at'        => ['type' => 'DATETIME', 'null' => true],
                'closes_at'       => ['type' => 'DATETIME', 'null' => true],
                'status'          => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT', 'OPEN', 'CLOSED', 'CANCELLED', 'PROMOTED_TO_PUBLIC'],
                    'default' => 'DRAFT',
                ],
                'created_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'status']);
            $forge->addKey(['position_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'SET NULL');
            $forge->addForeignKey('branch_id', 'branches', 'id', 'CASCADE', 'SET NULL');
            $forge->addForeignKey('job_posting_id', 'job_postings', 'id', 'CASCADE', 'SET NULL');
            $forge->createTable('internal_job_postings');
        }

        if (! $db->tableExists('internal_applications')) {
            $forge->addField([
                'id'                       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'               => ['type' => 'INT', 'unsigned' => true],
                'internal_job_posting_id'  => ['type' => 'INT', 'unsigned' => true],
                'employee_id'              => ['type' => 'INT', 'unsigned' => true],
                'cover_letter'             => ['type' => 'TEXT', 'null' => true],
                'current_supervisor_notified_at' => ['type' => 'DATETIME', 'null' => true,
                    'comment' => 'Supervisor lama wajib di-notify (transparency)'],
                'status'                   => [
                    'type' => 'ENUM',
                    'constraint' => [
                        'SUBMITTED', 'IN_REVIEW', 'INTERVIEW',
                        'OFFER', 'ACCEPTED', 'REJECTED', 'WITHDRAWN',
                    ],
                    'default' => 'SUBMITTED',
                ],
                'rejection_reason'         => ['type' => 'TEXT', 'null' => true],
                'jic_id'                   => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Saat ACCEPTED, link ke JIC yang generate untuk rotasi internal'],
                'created_at'               => ['type' => 'DATETIME', 'null' => true],
                'updated_at'               => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['internal_job_posting_id', 'employee_id'], 'uniq_internal_app');
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('internal_job_posting_id', 'internal_job_postings', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('internal_applications');
        }

        // Permissions baru
        $this->seedHorizon3Permissions();
    }

    /**
     * Seed permissions baru untuk Talent Management cluster + auto-grant
     * ke role default (super_admin, hr_admin, manager).
     */
    private function seedHorizon3Permissions(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) return;

        $now = date('Y-m-d H:i:s');
        $permissions = [
            // Competency
            ['slug' => 'competency.view', 'name' => 'View Competency Library',
                'group' => 'performance', 'description' => 'Lihat master kompetensi + profile per posisi'],
            ['slug' => 'competency.manage', 'name' => 'Manage Competency Library',
                'group' => 'performance', 'description' => 'CRUD competencies, levels, position profiles, assessments'],
            ['slug' => 'competency.assess_self', 'name' => 'Self-Assess Competency',
                'group' => 'performance', 'description' => 'Karyawan self-assess proficiency mereka'],

            // Succession Planning
            ['slug' => 'succession.view', 'name' => 'View Succession Plan',
                'group' => 'hr', 'description' => 'Lihat critical positions + successor pool'],
            ['slug' => 'succession.manage', 'name' => 'Manage Succession Plan',
                'group' => 'hr', 'description' => 'CRUD critical positions + nominate succession candidates'],

            // Talent Pool
            ['slug' => 'talent_pool.view', 'name' => 'View Talent Pool',
                'group' => 'recruitment', 'description' => 'Lihat skill matrix + aspirasi karyawan untuk staffing'],
            ['slug' => 'talent_pool.manage', 'name' => 'Manage Talent Pool',
                'group' => 'recruitment', 'description' => 'Validate skill, manage internal posting'],
            ['slug' => 'internal_job.apply', 'name' => 'Apply to Internal Job',
                'group' => 'recruitment', 'description' => 'Karyawan apply ke internal job posting'],
        ];

        // Default grants:
        //   1 super_admin → semua
        //   2 hr_admin    → semua kecuali assess_self & internal_job.apply
        //   3 manager     → competency.view, succession.view, talent_pool.view
        //   default semua karyawan (tabel 'employee' kalau ada) → competency.assess_self, internal_job.apply
        $grantMap = [
            1 => ['competency.view', 'competency.manage', 'competency.assess_self',
                  'succession.view', 'succession.manage',
                  'talent_pool.view', 'talent_pool.manage', 'internal_job.apply'],
            2 => ['competency.view', 'competency.manage',
                  'succession.view', 'succession.manage',
                  'talent_pool.view', 'talent_pool.manage'],
            3 => ['competency.view', 'succession.view', 'talent_pool.view',
                  'competency.assess_self', 'internal_job.apply'],
        ];

        foreach ($permissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $db->table('permissions')->insert($p + ['created_at' => $now]);
        }
        foreach ($grantMap as $roleId => $slugs) {
            if ($db->table('roles')->where('id', $roleId)->countAllResults() === 0) continue;
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $alreadyGranted = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($alreadyGranted > 0) continue;
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
        $db = $this->db;
        $forge = $this->forge;

        foreach (['internal_applications', 'internal_job_postings',
            'employee_aspirations', 'employee_skills',
            'succession_candidates', 'critical_positions',
            'employee_competency_assessments', 'position_competency_profiles',
            'competency_levels', 'competencies'] as $tbl) {
            if ($db->tableExists($tbl)) $forge->dropTable($tbl, true);
        }

        if ($db->tableExists('idps')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('idps'));
            if (in_array('competency_id', $cols, true)) {
                try { $forge->dropColumn('idps', 'competency_id'); } catch (\Throwable $e) {}
            }
        }
        if ($db->tableExists('trainings')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('trainings'));
            if (in_array('competency_id', $cols, true)) {
                try { $forge->dropColumn('trainings', 'competency_id'); } catch (\Throwable $e) {}
            }
        }
    }
}