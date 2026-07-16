<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenLoanApproval extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('loans')) {
            $existing = $this->db->getFieldNames('loans');
            $fields = [];
            if (! in_array('first_installment_date', $existing, true)) {
                $fields['first_installment_date'] = ['type' => 'DATE', 'null' => true, 'after' => 'start_period_id'];
            }
            if (! in_array('rejection_note', $existing, true)) {
                $fields['rejection_note'] = ['type' => 'TEXT', 'null' => true, 'after' => 'approved_at'];
            }
            if ($fields !== []) $this->forge->addColumn('loans', $fields);
        }

        if ($this->db->tableExists('loan_installments')) {
            $this->forge->addKey(
                ['company_id', 'loan_id', 'installment_number'],
                false,
                true,
                'uq_loan_installment_number'
            );
            $this->forge->processIndexes('loan_installments');
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('loans')) return;
        $existing = $this->db->getFieldNames('loans');
        foreach (['rejection_note', 'first_installment_date'] as $column) {
            if (in_array($column, $existing, true)) $this->forge->dropColumn('loans', $column);
        }
    }
}
