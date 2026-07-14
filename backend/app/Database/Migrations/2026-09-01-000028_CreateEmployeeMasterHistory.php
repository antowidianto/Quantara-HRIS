<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Effective-dated, approved snapshots of employee master and compensation data. */
final class CreateEmployeeMasterHistory extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('employee_master_history')) return;

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'employee_id' => ['type' => 'INT', 'unsigned' => true],
            'valid_from' => ['type' => 'DATE'],
            'valid_to' => ['type' => 'DATE', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'ACTIVE'],
            'position_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'manager_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'supervisor_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'department_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'branch_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'cost_center_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'job_grade_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'work_location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'basic_salary' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'fixed_allowance' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'employment_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'employment_type' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'contract_type' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'contract_start_date' => ['type' => 'DATE', 'null' => true],
            'contract_end_date' => ['type' => 'DATE', 'null' => true],
            'benefit_eligibility_json' => ['type' => 'JSON', 'null' => true],
            'changed_fields_json' => ['type' => 'JSON', 'null' => true],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'applied_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'employee_id', 'valid_from'], 'uniq_employee_master_effective_date');
        $this->forge->addKey(['company_id', 'employee_id', 'valid_from', 'valid_to'], false, false, 'idx_employee_master_as_of');
        $this->forge->addKey(['company_id', 'status', 'valid_from'], false, false, 'idx_employee_master_due');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_master_history');

        $this->backfillCurrentState();
    }

    public function down(): void
    {
        $this->forge->dropTable('employee_master_history', true);
    }

    private function backfillCurrentState(): void
    {
        $employees = $this->db->table('employees')->where('deleted_at IS NULL')->orderBy('id', 'ASC')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        foreach ($employees as $employee) {
            $contract = $this->db->table('employee_contracts')->where('company_id', (int) $employee['company_id'])
                ->where('employee_id', (int) $employee['id'])->orderBy('start_date', 'DESC')->orderBy('id', 'DESC')->get()->getRowArray();
            $policies = $this->db->table('benefit_policies')->select('id')->where('company_id', (int) $employee['company_id'])
                ->groupStart()->where('job_grade_id IS NULL')->orWhere('job_grade_id', $employee['job_grade_id'] ?? 0)->groupEnd()
                ->orderBy('id', 'ASC')->get()->getResultArray();
            $this->db->table('employee_master_history')->insert([
                'company_id' => (int) $employee['company_id'], 'employee_id' => (int) $employee['id'],
                'valid_from' => $employee['join_date'] ?: '1900-01-01', 'valid_to' => null, 'status' => 'ACTIVE',
                'position_id' => $employee['position_id'], 'manager_id' => $employee['manager_id'], 'supervisor_id' => $employee['supervisor_id'] ?? null,
                'department_id' => $employee['department_id'], 'branch_id' => $employee['branch_id'], 'cost_center_id' => $employee['cost_center_id'] ?? null,
                'job_grade_id' => $employee['job_grade_id'] ?? null, 'work_location_id' => $employee['work_location_id'] ?? null,
                'basic_salary' => $employee['basic_salary'] ?? 0, 'fixed_allowance' => $employee['fixed_allowance'] ?? 0,
                'employment_status' => $employee['employment_status'], 'employment_type' => $employee['employment_type'],
                'contract_type' => $contract['contract_type'] ?? null, 'contract_start_date' => $contract['start_date'] ?? null,
                'contract_end_date' => $contract['end_date'] ?? $employee['contract_end_date'] ?? null,
                'benefit_eligibility_json' => json_encode(array_map('intval', array_column($policies, 'id'))),
                'changed_fields_json' => json_encode([]), 'source_type' => 'MIGRATION_BACKFILL', 'source_id' => null,
                'approved_by' => null, 'approved_at' => null, 'applied_at' => $now, 'created_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
