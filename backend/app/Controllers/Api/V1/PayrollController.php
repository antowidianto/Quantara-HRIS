<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * PayrollController — for actions not tied to a single period (e.g. THR generation)
 */
class PayrollController extends ApiController
{
    /**
     * Generate THR period for the company.
     * THR aturan: minimal 1 bulan upah untuk masa kerja >= 12 bulan,
     * proporsional untuk masa kerja >= 1 bulan tapi < 12 bulan.
     */
    public function generateThr()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'pay_date' => 'required|valid_date',
            'name'     => 'required|max_length[100]',
        ]);
        if (! $valid) return null;

        $companyId = $this->companyId();
        $year = (int) date('Y', strtotime($data['pay_date']));
        $code = 'THR-' . $year;

        $db = \Config\Database::connect();
        $existing = $db->table('payroll_periods')->where('company_id', $companyId)->where('code', $code)->countAllResults();
        if ($existing > 0) {
            return $this->fail('THR period for this year already exists', 409);
        }

        $startDate = date('Y-01-01', strtotime($data['pay_date']));
        $endDate   = $data['pay_date'];

        $db->table('payroll_periods')->insert([
            'company_id'   => $companyId,
            'code'         => $code,
            'name'         => $data['name'],
            'type'         => 'THR',
            'period_year'  => $year,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'pay_date'     => $data['pay_date'],
            'cutoff_attendance_date' => $endDate,
            'status'       => 'DRAFT',
            'created_by'   => $this->userId(),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $periodId = (int) $db->insertID();

        // Create THR slips
        $employees = $db->table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('deleted_at IS NULL')
            ->get()->getResultArray();

        $totalGross = 0;
        foreach ($employees as $emp) {
            $tenureMonths = max(0, (int) ((time() - strtotime($emp['join_date'])) / 86400 / 30));
            if ($tenureMonths < 1) continue;
            $monthly = (float) $emp['basic_salary'];
            $thrAmount = $tenureMonths >= 12 ? $monthly : round($monthly * ($tenureMonths / 12));

            $slipNumber = 'THR-' . $year . '-' . str_pad((string) $emp['id'], 5, '0', STR_PAD_LEFT);
            $db->table('payslips')->insert([
                'company_id'        => $companyId,
                'payroll_period_id' => $periodId,
                'employee_id'       => $emp['id'],
                'slip_number'       => $slipNumber,
                'employee_number'   => $emp['employee_number'],
                'employee_name'     => $emp['full_name'],
                'ptkp_status'       => $emp['ptkp_status'],
                'thr_amount'        => $thrAmount,
                'gross_earning'     => $thrAmount,
                'total_earning'     => $thrAmount,
                'take_home_pay'     => $thrAmount,
                'pph21_method'      => 'TER',
                'breakdown'         => json_encode([
                    'tenure_months' => $tenureMonths,
                    'monthly_base'  => $monthly,
                    'thr_amount'    => $thrAmount,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            $totalGross += $thrAmount;
        }

        $db->table('payroll_periods')->where('id', $periodId)->update([
            'status'      => 'CALCULATED',
            'total_gross' => $totalGross,
            'total_net'   => $totalGross,
        ]);

        Services::audit()->log('payroll.thr_generate', 'payroll_period', $periodId);
        return $this->ok([
            'period_id' => $periodId,
            'total'     => $totalGross,
            'count'     => count($employees),
        ], 'THR generated', 201);
    }
}