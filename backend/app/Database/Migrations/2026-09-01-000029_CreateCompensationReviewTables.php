<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCompensationReviewTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true], 'company_id' => ['type' => 'INT', 'unsigned' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 40], 'name' => ['type' => 'VARCHAR', 'constraint' => 160],
            'effective_date' => ['type' => 'DATE'], 'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'DRAFT'],
            'approval_levels' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 2], 'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'], 'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey(['company_id', 'code']); $this->forge->addKey(['company_id', 'status']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE'); $this->forge->createTable('compensation_review_cycles');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true], 'company_id' => ['type' => 'INT', 'unsigned' => true],
            'cycle_id' => ['type' => 'BIGINT', 'unsigned' => true], 'department_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'cost_center_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true], 'budget_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'created_at' => ['type' => 'DATETIME'], 'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id'); $this->forge->addKey(['company_id', 'cycle_id']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE'); $this->forge->addForeignKey('cycle_id', 'compensation_review_cycles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('compensation_review_budgets');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true], 'company_id' => ['type' => 'INT', 'unsigned' => true],
            'cycle_id' => ['type' => 'BIGINT', 'unsigned' => true], 'employee_id' => ['type' => 'INT', 'unsigned' => true],
            'department_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true], 'cost_center_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'current_salary' => ['type' => 'DECIMAL', 'constraint' => '15,2'], 'proposed_salary' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'range_min' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0], 'range_max' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'compa_ratio' => ['type' => 'DECIMAL', 'constraint' => '8,4', 'null' => true], 'range_penetration' => ['type' => 'DECIMAL', 'constraint' => '8,4', 'null' => true],
            'performance_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true], 'calibration_input' => ['type' => 'TEXT', 'null' => true],
            'manager_note' => ['type' => 'TEXT', 'null' => true], 'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'DRAFT'],
            'approval_level' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0], 'proposed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true], 'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'letter_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true], 'history_snapshot_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'], 'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey(['company_id', 'cycle_id', 'employee_id']); $this->forge->addKey(['company_id', 'cycle_id', 'status']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE'); $this->forge->addForeignKey('cycle_id', 'compensation_review_cycles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE'); $this->forge->createTable('compensation_review_proposals');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true], 'company_id' => ['type' => 'INT', 'unsigned' => true],
            'proposal_id' => ['type' => 'BIGINT', 'unsigned' => true], 'approval_level' => ['type' => 'TINYINT', 'unsigned' => true],
            'approver_id' => ['type' => 'INT', 'unsigned' => true], 'decision' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'APPROVED'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id'); $this->forge->addUniqueKey(['company_id', 'proposal_id', 'approval_level']); $this->forge->addUniqueKey(['company_id', 'proposal_id', 'approver_id']);
        $this->forge->addKey(['company_id', 'proposal_id']); $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('proposal_id', 'compensation_review_proposals', 'id', 'CASCADE', 'CASCADE'); $this->forge->createTable('compensation_review_approvals');
    }

    public function down(): void
    {
        $this->forge->dropTable('compensation_review_approvals', true); $this->forge->dropTable('compensation_review_proposals', true); $this->forge->dropTable('compensation_review_budgets', true); $this->forge->dropTable('compensation_review_cycles', true);
    }
}
