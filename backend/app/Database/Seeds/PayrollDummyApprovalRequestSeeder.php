<?php

namespace App\Database\Seeds;

use App\Libraries\Payroll\PayrollApprovalWorkflowService;
use CodeIgniter\Database\Seeder;

/** Starts missing approval requests for calculated payroll periods in local dummy data. */
final class PayrollDummyApprovalRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('PayrollDummyApprovalRequestSeeder tidak boleh dijalankan di production');
        }

        $periods = $this->db->table('payroll_periods')
            ->where('status', 'CALCULATED')
            ->where('submitted_at IS NULL')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $submitted = 0;
        foreach ($periods as $period) {
            $makerId = (int) ($period['prepared_by'] ?? $period['created_by'] ?? 0);
            if ($makerId <= 0) {
                throw new \RuntimeException("Payroll period #{$period['id']} tidak memiliki maker");
            }

            (new PayrollApprovalWorkflowService($this->db))->submit(
                (int) $period['id'],
                (int) $period['company_id'],
                $makerId,
            );
            $submitted++;
        }

        echo "Payroll dummy periods submitted to approval chain: {$submitted}." . PHP_EOL;
    }
}
