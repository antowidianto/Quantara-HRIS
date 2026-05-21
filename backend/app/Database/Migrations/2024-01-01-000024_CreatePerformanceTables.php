<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Performance Management — OKR / KPI / Review
 *
 * 4 tabel:
 *   1. `performance_cycles` — period (Q1 2026, Annual 2026, dll)
 *   2. `goals`              — KPI/OKR per karyawan, support parent untuk alignment
 *   3. `goal_check_ins`     — progress update berkala
 *   4. `performance_reviews`— review akhir cycle dengan score + comments
 */
class CreatePerformanceTables extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // 1. performance_cycles
        if (! $db->tableExists('performance_cycles')) {
            $forge->addField([
                'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'  => ['type' => 'INT', 'unsigned' => true],
                'code'        => ['type' => 'VARCHAR', 'constraint' => 50,
                    'comment' => 'mis. Q1-2026, ANNUAL-2026'],
                'name'        => ['type' => 'VARCHAR', 'constraint' => 200],
                'cycle_type'  => ['type' => 'VARCHAR', 'constraint' => 16,
                    'comment' => 'YEARLY | QUARTERLY | MONTHLY | CUSTOM'],
                'start_date'  => ['type' => 'DATE'],
                'end_date'    => ['type' => 'DATE'],
                'goal_setting_deadline' => ['type' => 'DATE', 'null' => true,
                    'comment' => 'Batas akhir set goal'],
                'review_start_date'  => ['type' => 'DATE', 'null' => true],
                'review_end_date'    => ['type' => 'DATE', 'null' => true],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','OPEN','GOAL_SETTING','IN_PROGRESS','REVIEW','CLOSED','CANCELLED'],
                    'default' => 'DRAFT',
                ],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey(['company_id', 'status']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('performance_cycles');
        }

        // 2. goals — KPI/OKR
        if (! $db->tableExists('goals')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'cycle_id'     => ['type' => 'INT', 'unsigned' => true],
                'employee_id'  => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Pemilik goal'],
                'parent_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Untuk alignment: child dari goal manager/dept'],
                'goal_type' => [
                    'type' => 'VARCHAR', 'constraint' => 16,
                    'default' => 'KPI',
                    'comment' => 'KPI | OKR_OBJECTIVE | OKR_KEY_RESULT | DEVELOPMENT',
                ],
                'category' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'FINANCIAL/CUSTOMER/INTERNAL/LEARNING/OPERATIONAL/dll (BSC)'],
                'title'        => ['type' => 'VARCHAR', 'constraint' => 255],
                'description'  => ['type' => 'TEXT', 'null' => true],

                // Measurement
                'measurement_type' => [
                    'type' => 'VARCHAR', 'constraint' => 16,
                    'default' => 'PERCENT',
                    'comment' => 'PERCENT | NUMBER | CURRENCY | BINARY (yes/no) | MILESTONE',
                ],
                'target_value' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
                'current_value' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
                'unit' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                    'comment' => 'mis. %, IDR, pcs, hari'],

                'weight' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0,
                    'comment' => 'Bobot 0-100, total per employee per cycle = 100'],

                'due_date' => ['type' => 'DATE', 'null' => true],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','APPROVED','IN_PROGRESS','ACHIEVED','PARTIAL','MISSED','CANCELLED'],
                    'default' => 'DRAFT',
                ],
                'progress_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0,
                    'comment' => 'Auto-calc dari current/target × 100, capped 100'],
                'final_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'Skor final saat review (0-100 atau 0-5 sesuai company)'],

                'approved_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'  => ['type' => 'DATETIME', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'cycle_id', 'employee_id']);
            $forge->addKey(['cycle_id', 'status']);
            $forge->addKey('parent_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('cycle_id', 'performance_cycles', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('parent_id', 'goals', 'id', 'SET NULL', 'CASCADE');
            $forge->createTable('goals');
        }

        // 3. goal_check_ins — progress updates
        if (! $db->tableExists('goal_check_ins')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true],
                'goal_id'    => ['type' => 'INT', 'unsigned' => true],
                'employee_id'=> ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Pemilik goal yang report'],
                'check_in_date' => ['type' => 'DATE'],
                'progress_value' => ['type' => 'DECIMAL', 'constraint' => '15,2',
                    'comment' => 'Nilai aktual saat check-in (snapshot ke goal.current_value)'],
                'progress_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
                'status_color' => ['type' => 'VARCHAR', 'constraint' => 8,
                    'default' => 'GREEN',
                    'comment' => 'GREEN | YELLOW | RED — self-assessment status'],
                'comment'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Karyawan: progress, blocker, kebutuhan support'],
                'attachment' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['goal_id', 'check_in_date']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('goal_id', 'goals', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('goal_check_ins');
        }

        // 4. performance_reviews
        if (! $db->tableExists('performance_reviews')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'cycle_id'     => ['type' => 'INT', 'unsigned' => true],
                'employee_id'  => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Yang di-review'],
                'reviewer_id'  => ['type' => 'INT', 'unsigned' => true,
                    'comment' => 'Yang melakukan review (atasan)'],

                'review_type' => [
                    'type' => 'VARCHAR', 'constraint' => 16,
                    'default' => 'MANAGER',
                    'comment' => 'SELF | MANAGER | PEER | SUBORDINATE (360)',
                ],

                // Scoring
                'overall_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true,
                    'comment' => 'Skor keseluruhan 0-100 (atau 0-5 dikali 20)'],
                'rating' => [
                    'type' => 'VARCHAR', 'constraint' => 24, 'null' => true,
                    'comment' => 'OUTSTANDING | EXCEEDS | MEETS | NEEDS_IMPROVEMENT | UNSATISFACTORY',
                ],

                // Goal scores breakdown (JSON: {goal_id: score})
                'goal_scores' => ['type' => 'TEXT', 'null' => true],
                'competency_scores' => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'JSON {competency_name: score}'],

                // Comments
                'strengths'    => ['type' => 'TEXT', 'null' => true],
                'improvements' => ['type' => 'TEXT', 'null' => true],
                'development_plan' => ['type' => 'TEXT', 'null' => true],
                'reviewer_comment' => ['type' => 'TEXT', 'null' => true],
                'employee_comment' => ['type' => 'TEXT', 'null' => true],

                // Recommendations
                'recommendation' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'PROMOTION | RAISE | KEEP | PIP | TERMINATION'],
                'recommended_increase_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],

                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT','SUBMITTED','ACKNOWLEDGED','FINALIZED','CANCELLED'],
                    'default' => 'DRAFT',
                ],
                'submitted_at'   => ['type' => 'DATETIME', 'null' => true],
                'acknowledged_at'=> ['type' => 'DATETIME', 'null' => true],
                'finalized_at'   => ['type' => 'DATETIME', 'null' => true],

                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['cycle_id', 'employee_id', 'reviewer_id', 'review_type']);
            $forge->addKey(['cycle_id', 'status']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('cycle_id', 'performance_cycles', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('performance_reviews');
        }

        // Permissions
        $perms = [
            ['slug' => 'performance.view',       'name' => 'View Performance',       'group' => 'performance'],
            ['slug' => 'performance.manage',     'name' => 'Manage Performance Cycles', 'group' => 'performance'],
            ['slug' => 'goal.view',              'name' => 'View Goals',             'group' => 'performance'],
            ['slug' => 'goal.manage',            'name' => 'Manage Goals',           'group' => 'performance'],
            ['slug' => 'goal.approve',           'name' => 'Approve Goals',          'group' => 'performance'],
            ['slug' => 'review.conduct',         'name' => 'Conduct Reviews',        'group' => 'performance'],
            ['slug' => 'review.view_team',       'name' => 'View Team Reviews',      'group' => 'performance'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($perms as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }

        // Auto-grant: role 1 & 2 dapat semua; role 3 manager dapat goal.approve + review.conduct + review.view_team
        $grantMap = [
            1 => ['performance.view','performance.manage','goal.view','goal.manage','goal.approve','review.conduct','review.view_team'],
            2 => ['performance.view','performance.manage','goal.view','goal.manage','goal.approve','review.conduct','review.view_team'],
            3 => ['performance.view','goal.view','goal.approve','review.conduct','review.view_team'],
            4 => ['performance.view','goal.view'],
            5 => ['performance.view','goal.view'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])->countAllResults();
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
        if ($this->db->tableExists('performance_reviews')) $this->forge->dropTable('performance_reviews', true);
        if ($this->db->tableExists('goal_check_ins'))      $this->forge->dropTable('goal_check_ins', true);
        if ($this->db->tableExists('goals'))                $this->forge->dropTable('goals', true);
        if ($this->db->tableExists('performance_cycles'))   $this->forge->dropTable('performance_cycles', true);
    }
}