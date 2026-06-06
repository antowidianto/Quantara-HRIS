<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDisbursementFields extends Migration
{
    public function up()
    {
        // Add to payroll_periods (batch tracking)
        // ALREADY RUN
        /*
        $this->forge->addColumn('payroll_periods', [
            'disbursement_status' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'default' => 'PENDING',
                'null' => false,
                'after' => 'status', // After DRAFT, FINALIZED, PAID
            ],
            'disbursement_reference' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'disbursement_status',
            ],
            'disbursement_error' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'disbursement_reference',
            ],
        ]);
        */

        // Add to payslips (individual employee tracking)
        $this->forge->addColumn('payslips', [
            'disbursement_status' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'default' => 'PENDING',
                'null' => false,
                'after' => 'take_home_pay', 
            ],
            'disbursement_reference' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'disbursement_status',
            ],
            'disbursement_error' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'disbursement_reference',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('payroll_periods', 'disbursement_status');
        $this->forge->dropColumn('payroll_periods', 'disbursement_reference');
        $this->forge->dropColumn('payroll_periods', 'disbursement_error');

        $this->forge->dropColumn('payslips', 'disbursement_status');
        $this->forge->dropColumn('payslips', 'disbursement_reference');
        $this->forge->dropColumn('payslips', 'disbursement_error');
    }
}
