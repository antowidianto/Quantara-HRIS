<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddEbupotFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('payroll_periods', [
            'ebupot_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'UNREPORTED', // UNREPORTED, IN_PROGRESS, COMPLETED, FAILED
                'null'       => false,
            ],
            'ebupot_reference' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'ebupot_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        
        $this->forge->addColumn('payslips', [
            'ebupot_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'UNREPORTED',
                'null'       => false,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('payroll_periods', 'ebupot_status');
        $this->forge->dropColumn('payroll_periods', 'ebupot_reference');
        $this->forge->dropColumn('payroll_periods', 'ebupot_error');
        
        $this->forge->dropColumn('payslips', 'ebupot_status');
    }
}
