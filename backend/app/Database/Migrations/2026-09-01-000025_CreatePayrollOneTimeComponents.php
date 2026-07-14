<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Approved, source-linked one-time earnings/deductions consumed by payroll. */
class CreatePayrollOneTimeComponents extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('payroll_one_time_components')) return;

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'payroll_period_id' => ['type' => 'INT', 'unsigned' => true],
            'employee_id' => ['type' => 'INT', 'unsigned' => true],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'name' => ['type' => 'VARCHAR', 'constraint' => 160],
            'component_type' => ['type' => 'ENUM', 'constraint' => ['EARNING', 'DEDUCTION']],
            'category' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'OTHER'],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '18,2'],
            'is_taxable' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['company_id', 'source_type', 'source_id', 'code'],
            'uniq_payroll_one_time_source',
        );
        $this->forge->addKey(['company_id', 'payroll_period_id', 'employee_id']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('payroll_period_id', 'payroll_periods', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('payroll_one_time_components');
    }

    public function down(): void
    {
        $this->forge->dropTable('payroll_one_time_components', true);
    }
}
