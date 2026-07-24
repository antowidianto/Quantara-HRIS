<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/** Ensures dummy payroll data always has a usable cost center. */
final class PayrollDummyCostCenterSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->db->tableExists('cost_centers') || !$this->db->fieldExists('cost_center_id', 'employees')) {
            throw new \RuntimeException('Cost center migrations must be applied before running this seeder');
        }

        $now = date('Y-m-d H:i:s');
        $companies = $this->db->table('companies')
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $summary = ['companies' => 0, 'cost_centers_created' => 0, 'employees_updated' => 0, 'payslips_updated' => 0];
        $this->db->transBegin();

        try {
            foreach ($companies as $company) {
                $companyId = (int) $company['id'];
                $costCenter = $this->db->table('cost_centers')
                    ->where('company_id', $companyId)
                    ->where('code', 'DEFAULT')
                    ->get()->getRowArray();

                if (!$costCenter) {
                    $this->db->table('cost_centers')->insert([
                        'company_id' => $companyId,
                        'code' => 'DEFAULT',
                        'name' => 'Default Cost Center',
                        'description' => 'Default cost center untuk payroll dummy dan data yang belum dipetakan.',
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $costCenterId = (int) $this->db->insertID();
                    $summary['cost_centers_created']++;
                } else {
                    $costCenterId = (int) $costCenter['id'];
                    $this->db->table('cost_centers')->where('id', $costCenterId)->update([
                        'is_active' => 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);
                }

                $this->db->table('employees')
                    ->where('company_id', $companyId)
                    ->where('deleted_at IS NULL')
                    ->groupStart()->where('cost_center_id IS NULL')->orWhere('cost_center_id', 0)->groupEnd()
                    ->update(['cost_center_id' => $costCenterId, 'updated_at' => $now]);
                $summary['employees_updated'] += $this->db->affectedRows();

                $editablePeriods = array_map(
                    'intval',
                    array_column(
                        $this->db->table('payroll_periods')->select('id')
                            ->where('company_id', $companyId)
                            ->whereIn('status', ['DRAFT', 'CALCULATED'])
                            ->get()->getResultArray(),
                        'id',
                    ),
                );
                if ($editablePeriods !== []) {
                    $unassignedPayslips = $this->db->table('payslips ps')
                        ->select('ps.id, e.cost_center_id')
                        ->join('employees e', 'e.id = ps.employee_id AND e.company_id = ps.company_id')
                        ->where('ps.company_id', $companyId)
                        ->whereIn('ps.payroll_period_id', $editablePeriods)
                        ->groupStart()->where('ps.cost_center_id IS NULL')->orWhere('ps.cost_center_id', 0)->groupEnd()
                        ->get()->getResultArray();
                    foreach ($unassignedPayslips as $payslip) {
                        $this->db->table('payslips')->where('id', (int) $payslip['id'])->update([
                            'cost_center_id' => (int) $payslip['cost_center_id'],
                            'updated_at' => $now,
                        ]);
                        $summary['payslips_updated']++;
                    }

                    $this->db->table('payroll_periods')
                        ->where('company_id', $companyId)
                        ->whereIn('id', $editablePeriods)
                        ->update([
                            'reconciliation_json' => null,
                            'reconciliation_checked_at' => null,
                            'updated_at' => $now,
                        ]);
                }

                $summary['companies']++;
            }

            if ($this->db->transStatus() === false || !$this->db->transCommit()) {
                throw new \RuntimeException('Unable to commit dummy cost center backfill');
            }
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        echo sprintf(
            "Payroll dummy cost center ready: %d companies, %d cost centers created, %d employees updated, %d payslips updated.\n",
            $summary['companies'],
            $summary['cost_centers_created'],
            $summary['employees_updated'],
            $summary['payslips_updated'],
        );
    }
}
