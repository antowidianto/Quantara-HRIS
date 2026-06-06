<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTalentManagementTables extends Migration
{
    public function up()
    {
        // ALREADY RUN — all tables (review_360_cycles, review_360_nominations,
        // review_360_responses, talent_assessments, critical_positions,
        // succession_candidates) already exist from earlier sprints.
        return;
        // ───────────────────────────────────────────────────────────
        // 1. 360-Degree Feedback Review
        // ───────────────────────────────────────────────────────────

        // Review cycles (periode review 360)
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'description' => ['type' => 'TEXT', 'null' => true],
            'year'        => ['type' => 'INT', 'constraint' => 4],
            'start_date'  => ['type' => 'DATE'],
            'end_date'    => ['type' => 'DATE'],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'DRAFT'], // DRAFT, ACTIVE, CLOSED
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('review_360_cycles');

        // Nominations (siapa me-review siapa, dan dari perspektif apa)
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'cycle_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'reviewee_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true], // yang di-review
            'reviewer_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true], // yang me-review
            'relationship'=> ['type' => 'VARCHAR', 'constraint' => 30], // SELF, SUPERIOR, PEER, SUBORDINATE
            'status'      => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'PENDING'], // PENDING, SUBMITTED
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('cycle_id', 'review_360_cycles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reviewee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reviewer_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('review_360_nominations');

        // Responses (jawaban per-pertanyaan/competency)
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'nomination_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'competency'     => ['type' => 'VARCHAR', 'constraint' => 150], // nama kompetensi/aspek yang dinilai
            'score'          => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'comment'        => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('nomination_id', 'review_360_nominations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('review_360_responses');

        // ───────────────────────────────────────────────────────────
        // 2. Nine-Box Matrix (Talent Assessments)
        // ───────────────────────────────────────────────────────────
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'employee_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'assessment_year'   => ['type' => 'INT', 'constraint' => 4],
            'performance_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0], // 1-5
            'potential_score'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0], // 1-5
            'nine_box_label'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],     // auto-computed
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'assessed_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addUniqueKey(['employee_id', 'assessment_year']);
        $this->forge->createTable('talent_assessments');

        // ───────────────────────────────────────────────────────────
        // 3. Succession Planning
        // ───────────────────────────────────────────────────────────

        // Critical positions
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'position_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'risk_level'  => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIUM'], // LOW, MEDIUM, HIGH, CRITICAL
            'incumbent_id'=> ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'notes'       => ['type' => 'TEXT', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('position_id', 'positions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('critical_positions');

        // Succession candidates
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'critical_position_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'candidate_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true], // employee
            'readiness'            => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'NOT_READY'], // NOT_READY, READY_1_2Y, READY_NOW
            'development_plan'     => ['type' => 'TEXT', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('critical_position_id', 'critical_positions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('candidate_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('succession_candidates');
    }

    public function down()
    {
        $this->forge->dropTable('succession_candidates');
        $this->forge->dropTable('critical_positions');
        $this->forge->dropTable('talent_assessments');
        $this->forge->dropTable('review_360_responses');
        $this->forge->dropTable('review_360_nominations');
        $this->forge->dropTable('review_360_cycles');
    }
}
