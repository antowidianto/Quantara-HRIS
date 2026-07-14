<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Append-only corrections posted into a later payroll period. */
class CreatePayrollAdjustments extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();
        if (! $db->tableExists('payroll_adjustments')) {
            $forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true],
                'employee_id' => ['type' => 'INT', 'unsigned' => true],
                'original_period_id' => ['type' => 'INT', 'unsigned' => true],
                'target_period_id' => ['type' => 'INT', 'unsigned' => true],
                'reversal_of_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'adjustment_type' => ['type' => 'ENUM', 'constraint' => ['EARNING', 'DEDUCTION']],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '18,2'],
                'is_taxable' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'reason' => ['type' => 'TEXT'],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['DRAFT', 'APPROVED', 'POSTED', 'REVERSED'],
                    'default' => 'DRAFT',
                ],
                'created_by' => ['type' => 'INT', 'unsigned' => true],
                'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at' => ['type' => 'DATETIME', 'null' => true],
                'posted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'posted_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'target_period_id', 'status']);
            $forge->addKey(['company_id', 'employee_id']);
            $forge->addUniqueKey('reversal_of_id', 'uniq_payroll_adjustment_reversal');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('employee_id', 'employees', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('original_period_id', 'payroll_periods', 'id', 'RESTRICT', 'CASCADE');
            $forge->addForeignKey('target_period_id', 'payroll_periods', 'id', 'RESTRICT', 'CASCADE');
            $forge->createTable('payroll_adjustments');
        }

        foreach ([
            ['payroll.adjustment.manage', 'Manage Payroll Adjustments'],
            ['payroll.adjustment.approve', 'Approve Payroll Adjustments'],
        ] as [$slug, $name]) {
            if ($db->tableExists('permissions') && $db->table('permissions')->where('slug', $slug)->countAllResults() === 0) {
                $db->table('permissions')->insert([
                    'slug' => $slug, 'name' => $name, 'group' => 'payroll',
                    'description' => $name,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if ($db->tableExists('payroll_adjustments')) {
            \Config\Database::forge()->dropTable('payroll_adjustments', true);
        }
        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'payroll.adjustment.manage', 'payroll.adjustment.approve',
            ])->delete();
        }
    }
}
