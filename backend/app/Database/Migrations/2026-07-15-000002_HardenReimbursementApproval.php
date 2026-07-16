<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenReimbursementApproval extends Migration
{
    public function up(): void
    {
        $db = $this->db;
        if ($db->tableExists('employee_benefit_balances')
            && ! in_array('company_id', $db->getFieldNames('employee_benefit_balances'), true)) {
            $this->forge->addColumn('employee_benefit_balances', [
                'company_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
            ]);
            $db->query(
                'UPDATE employee_benefit_balances SET company_id = '
                . '(SELECT e.company_id FROM employees e WHERE e.id = employee_benefit_balances.employee_id) '
                . 'WHERE company_id IS NULL'
            );
            $this->forge->addKey('company_id', false, false, 'idx_benefit_balances_company');
            $this->forge->processIndexes('employee_benefit_balances');
        }

        if ($db->tableExists('reimbursements')
            && ! in_array('rejection_note', $db->getFieldNames('reimbursements'), true)) {
            $this->forge->addColumn('reimbursements', [
                'rejection_note' => ['type' => 'TEXT', 'null' => true, 'after' => 'approved_at'],
            ]);
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if ($db->tableExists('reimbursements')
            && in_array('rejection_note', $db->getFieldNames('reimbursements'), true)) {
            $this->forge->dropColumn('reimbursements', 'rejection_note');
        }
        if ($db->tableExists('employee_benefit_balances')
            && in_array('company_id', $db->getFieldNames('employee_benefit_balances'), true)) {
            $this->forge->dropColumn('employee_benefit_balances', 'company_id');
        }
    }
}
