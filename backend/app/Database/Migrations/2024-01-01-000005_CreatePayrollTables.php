<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePayrollTables extends Migration
{
    public function up(): void
    {
        // ─── PAYROLL COMPONENTS (Earnings / Deductions) ───────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'type'           => ['type' => 'ENUM', 'constraint' => ['EARNING','DEDUCTION'], 'default' => 'EARNING'],
            'category'       => ['type' => 'ENUM', 'constraint' => ['BASIC','ALLOWANCE','BONUS','OVERTIME','THR','REIMBURSEMENT','BPJS_KES','BPJS_TK','PPH21','LOAN','OTHER'], 'default' => 'ALLOWANCE'],
            'is_taxable'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'is_fixed'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => 'Tetap=1, Tidak tetap=0 (PMK 252)'],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'formula'        => ['type' => 'TEXT', 'null' => true, 'comment' => 'optional formula JSON'],
            'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payroll_components');

        // ─── EMPLOYEE PAYROLL COMPONENTS (recurring per employee) ─────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'component_id'   => ['type' => 'INT', 'unsigned' => true],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'effective_from' => ['type' => 'DATE'],
            'effective_to'   => ['type' => 'DATE', 'null' => true],
            'notes'          => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addKey('component_id');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('component_id', 'payroll_components', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('employee_payroll_components');

        // ─── PAYROLL PERIODS ──────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'type'           => ['type' => 'ENUM', 'constraint' => ['MONTHLY','BI_WEEKLY','WEEKLY','THR','BONUS'], 'default' => 'MONTHLY'],
            'period_year'    => ['type' => 'YEAR'],
            'period_month'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'start_date'     => ['type' => 'DATE'],
            'end_date'       => ['type' => 'DATE'],
            'pay_date'       => ['type' => 'DATE'],
            'cutoff_attendance_date' => ['type' => 'DATE', 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['DRAFT','CALCULATED','APPROVED','PAID','CLOSED'], 'default' => 'DRAFT'],
            'is_december_correction' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0, 'comment' => 'PPh21 December reconciliation'],
            'total_gross'    => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'total_deduction'=> ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'total_net'      => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'created_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'finalized_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'finalized_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['company_id', 'code']);
        $this->forge->addKey(['period_year', 'period_month']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payroll_periods');

        // ─── PAYSLIPS ─────────────────────────────────────────────────
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'         => ['type' => 'INT', 'unsigned' => true],
            'payroll_period_id'  => ['type' => 'INT', 'unsigned' => true],
            'employee_id'        => ['type' => 'INT', 'unsigned' => true],
            'slip_number'        => ['type' => 'VARCHAR', 'constraint' => 64],

            // Snapshot fields
            'employee_number'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'employee_name'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'department_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'position_name'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'ptkp_status'        => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],

            'work_days'          => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'present_days'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'absent_days'        => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0],
            'late_minutes'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'overtime_hours'     => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],

            'basic_salary'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'gross_earning'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'overtime_amount'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'thr_amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'bonus_amount'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'reimbursement_amount'=> ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

            'bpjs_kes_employee'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '1% Kesehatan'],
            'bpjs_kes_company'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '4% Kesehatan'],
            'bpjs_jht_employee'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '2%'],
            'bpjs_jht_company'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '3.7%'],
            'bpjs_jp_employee'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '1%'],
            'bpjs_jp_company'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '2%'],
            'bpjs_jkk_company'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '0.24-1.74%'],
            'bpjs_jkm_company'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0, 'comment' => '0.3%'],

            'pph21_amount'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'pph21_method'       => ['type' => 'ENUM', 'constraint' => ['TER','GROSS_UP'], 'default' => 'TER'],
            'ter_rate'           => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0, 'comment' => 'TER % per PMK 168/2023'],
            'ter_category'       => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'comment' => 'A/B/C'],

            'loan_deduction'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'absent_deduction'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'late_deduction'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

            'total_earning'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_deduction'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'take_home_pay'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],

            'is_paid'            => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'paid_at'            => ['type' => 'DATETIME', 'null' => true],
            'payment_reference'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],

            'pdf_path'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'breakdown'          => ['type' => 'JSON', 'null' => true, 'comment' => 'all line items'],
            'notes'              => ['type' => 'TEXT', 'null' => true],

            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slip_number');
        $this->forge->addUniqueKey(['payroll_period_id', 'employee_id']);
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('payroll_period_id', 'payroll_periods', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payslips');

        // ─── PAYSLIP DETAILS ──────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'payslip_id'     => ['type' => 'BIGINT', 'unsigned' => true],
            'component_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'type'           => ['type' => 'ENUM', 'constraint' => ['EARNING','DEDUCTION'], 'default' => 'EARNING'],
            'category'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'is_taxable'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'sort_order'     => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('payslip_id');
        $this->forge->addForeignKey('payslip_id', 'payslips', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payslip_details');

        // ─── PTKP TABLE (PMK 101/PMK-010/2016 — current rates) ────────
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'         => ['type' => 'VARCHAR', 'constraint' => 8, 'comment' => 'TK0/K1/etc'],
            'description'  => ['type' => 'VARCHAR', 'constraint' => 200],
            'amount_yearly'=> ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'effective_year'=> ['type' => 'YEAR'],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['code', 'effective_year']);
        $this->forge->createTable('ptkp_rates');

        // ─── TER RATES (PMK 168/2023) ─────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'category'       => ['type' => 'ENUM', 'constraint' => ['A','B','C']],
            'min_income'     => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'max_income'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'rate'           => ['type' => 'DECIMAL', 'constraint' => '6,4', 'comment' => 'percentage e.g. 0.0050 = 0.5%'],
            'effective_year' => ['type' => 'YEAR'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['category', 'effective_year']);
        $this->forge->createTable('ter_rates');

        // ─── PROGRESSIVE PPH21 BRACKETS (UU HPP 2021) ─────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'min_income'     => ['type' => 'DECIMAL', 'constraint' => '18,2'],
            'max_income'     => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
            'rate'           => ['type' => 'DECIMAL', 'constraint' => '6,4'],
            'effective_year' => ['type' => 'YEAR'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('effective_year');
        $this->forge->createTable('pph21_brackets');

        // ─── BPJS RATES (per company config) ──────────────────────────
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'          => ['type' => 'INT', 'unsigned' => true],
            'kes_employee_rate'   => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.01],
            'kes_company_rate'    => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.04],
            'kes_max_salary'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 12000000],
            'jht_employee_rate'   => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.02],
            'jht_company_rate'    => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.037],
            'jp_employee_rate'    => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.01],
            'jp_company_rate'     => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.02],
            'jp_max_salary'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 10547400],
            'jkk_company_rate'    => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.0024],
            'jkm_company_rate'    => ['type' => 'DECIMAL', 'constraint' => '6,4', 'default' => 0.003],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('company_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('bpjs_rates');

        // ─── LOANS / KASBON ──────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'loan_number'    => ['type' => 'VARCHAR', 'constraint' => 32],
            'principal'      => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'installment_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'installments'   => ['type' => 'INT', 'unsigned' => true],
            'paid_installments'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'remaining'      => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'start_period_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reason'         => ['type' => 'TEXT'],
            'status'         => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED','RUNNING','COMPLETED','CANCELLED'], 'default' => 'PENDING'],
            'approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('loan_number');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('loans');

        // ─── REIMBURSEMENTS ──────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'INT', 'unsigned' => true],
            'employee_id'    => ['type' => 'INT', 'unsigned' => true],
            'request_number' => ['type' => 'VARCHAR', 'constraint' => 32],
            'category'       => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'TRANSPORT/MEDICAL/etc'],
            'expense_date'   => ['type' => 'DATE'],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'description'    => ['type' => 'TEXT'],
            'attachment'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['PENDING','APPROVED','REJECTED','PAID','CANCELLED'], 'default' => 'PENDING'],
            'approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at'    => ['type' => 'DATETIME', 'null' => true],
            'paid_in_payroll_period_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('request_number');
        $this->forge->addKey(['company_id', 'employee_id']);
        $this->forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('reimbursements');
    }

    public function down(): void
    {
        $this->forge->dropTable('reimbursements', true);
        $this->forge->dropTable('loans', true);
        $this->forge->dropTable('bpjs_rates', true);
        $this->forge->dropTable('pph21_brackets', true);
        $this->forge->dropTable('ter_rates', true);
        $this->forge->dropTable('ptkp_rates', true);
        $this->forge->dropTable('payslip_details', true);
        $this->forge->dropTable('payslips', true);
        $this->forge->dropTable('payroll_periods', true);
        $this->forge->dropTable('employee_payroll_components', true);
        $this->forge->dropTable('payroll_components', true);
    }
}