<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterLoansTableAndCreateInstallments extends Migration
{
    public function up()
    {
        // 1. Alter loans table to add interest fields
        $fields = [
            'interest_rate' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0,
                'comment'    => 'Flat interest rate per year in percentage',
                'after'      => 'principal',
            ],
            'total_interest' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
                'after'      => 'interest_rate',
            ],
            'total_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
                'comment'    => 'Principal + Total Interest',
                'after'      => 'total_interest',
            ],
        ];
        $this->forge->addColumn('loans', $fields);

        // Rename columns if needed, but existing are:
        // principal, installment_amount, installments, paid_installments, remaining

        // 2. loan_installments table
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'loan_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'company_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'installment_number' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'due_date' => [
                'type' => 'DATE',
            ],
            'amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'default'    => 0,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'UNPAID', // UNPAID, PAID
            ],
            'paid_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'payroll_period_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
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
        $this->forge->addKey('loan_id');
        $this->forge->addKey('company_id');
        $this->forge->addForeignKey('loan_id', 'loans', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('payroll_period_id', 'payroll_periods', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('loan_installments');
    }

    public function down()
    {
        $this->forge->dropTable('loan_installments', true);
        $this->forge->dropColumn('loans', ['interest_rate', 'total_interest', 'total_amount']);
    }
}
