<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add multi-approver evidence and a dedicated break-glass permission. */
class AddPayrollApprovalThresholdAndBreakGlass extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();

        if (! $db->tableExists('payroll_period_approvals')) {
            $forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true],
                'payroll_period_id' => ['type' => 'INT', 'unsigned' => true],
                'approved_by' => ['type' => 'INT', 'unsigned' => true],
                'approval_type' => [
                    'type' => 'ENUM',
                    'constraint' => ['STANDARD', 'BREAK_GLASS'],
                    'default' => 'STANDARD',
                ],
                'reason' => ['type' => 'TEXT', 'null' => true],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(
                ['payroll_period_id', 'approved_by', 'approval_type'],
                'uniq_payroll_period_approver_type'
            );
            $forge->addKey(['company_id', 'payroll_period_id']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('payroll_period_id', 'payroll_periods', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('payroll_period_approvals');
        }

        if ($db->tableExists('permissions')) {
            $exists = $db->table('permissions')->where('slug', 'payroll.break_glass')->countAllResults() > 0;
            if (! $exists) {
                $db->table('permissions')->insert([
                    'slug' => 'payroll.break_glass',
                    'name' => 'Break-glass Payroll Finalization',
                    'group' => 'payroll',
                    'description' => 'Emergency payroll finalization with mandatory reason and critical audit',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        if ($db->tableExists('payroll_period_approvals')) {
            \Config\Database::forge()->dropTable('payroll_period_approvals', true);
        }
        if ($db->tableExists('permissions')) {
            $db->table('permissions')->where('slug', 'payroll.break_glass')->delete();
        }
    }
}
