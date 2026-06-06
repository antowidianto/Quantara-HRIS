<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBenefitTables extends Migration
{
    public function up()
    {
        // 1. benefit_policies table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'company_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100, // e.g., 'Kacamata', 'Rawat Jalan'
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'limit_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            // Plafon bisa berdasarkan grade tertentu, jika null = semua grade
            'job_grade_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'reset_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'YEARLY', // YEARLY, MONTHLY
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('job_grade_id', 'job_grades', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('benefit_policies');

        // 2. employee_benefit_balances table
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'employee_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'policy_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'year' => [
                'type'       => 'INT',
                'constraint' => 4,
            ],
            'allocated_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            'used_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('policy_id', 'benefit_policies', 'id', 'CASCADE', 'CASCADE');
        // Unique constraint so an employee only has 1 balance record per policy per year
        $this->forge->addUniqueKey(['employee_id', 'policy_id', 'year']);
        $this->forge->createTable('employee_benefit_balances');
    }

    public function down()
    {
        $this->forge->dropTable('employee_benefit_balances');
        $this->forge->dropTable('benefit_policies');
    }
}
