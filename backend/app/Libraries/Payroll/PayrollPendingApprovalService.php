<?php

declare(strict_types=1);

namespace App\Libraries\Payroll;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class PayrollPendingApprovalService
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
    }

    /** @param array<string,mixed> $period @return array{total_count:int,items:array<string,list<array<string,mixed>>>,counts:array<string,int>} */
    public function scan(int $payrollPeriodId, int $companyId, array $period): array
    {
        if ($companyId <= 0 || (int) ($period['company_id'] ?? 0) !== $companyId) {
            throw new \DomainException('Active company context is required for payroll pending approvals');
        }
        $start = (string) $period['start_date'];
        $end = (string) $period['end_date'];
        $items = [];

        $overtime = $this->db->table('overtimes o')
            ->select('o.id, o.employee_id, o.work_date, o.hours, o.day_type, o.created_at,'
                . ' e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = o.employee_id AND e.company_id = o.company_id', 'left')
            ->where('o.company_id', $companyId)->where('o.status', 'PENDING')
            ->where('o.work_date >=', $start)->where('o.work_date <=', $end)
            ->orderBy('o.work_date', 'ASC')->limit(100)->get()->getResultArray();
        if ($overtime !== []) $items['overtime'] = $overtime;

        if ($this->db->tableExists('attendance_change_requests')) {
            $attendance = $this->db->table('attendance_change_requests acr')
                ->select('acr.id, acr.employee_id, acr.work_date, acr.created_at,'
                    . ' e.full_name AS employee_name, e.employee_number')
                ->join('employees e', 'e.id = acr.employee_id AND e.company_id = acr.company_id', 'left')
                ->where('acr.company_id', $companyId)->where('acr.status', 'PENDING')
                ->where('acr.work_date >=', $start)->where('acr.work_date <=', $end)
                ->orderBy('acr.work_date', 'ASC')->limit(100)->get()->getResultArray();
            if ($attendance !== []) $items['attendance_change'] = $attendance;
        }

        $reimbursements = $this->db->table('reimbursements r')
            ->select('r.id, r.employee_id, r.request_number, r.category, r.expense_date,'
                . ' r.amount, r.created_at, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = r.employee_id AND e.company_id = r.company_id', 'left')
            ->where('r.company_id', $companyId)->where('r.status', 'PENDING')
            ->where('r.expense_date <=', $end)->orderBy('r.expense_date', 'ASC')
            ->limit(100)->get()->getResultArray();
        if ($reimbursements !== []) $items['reimbursement'] = $reimbursements;

        $loans = $this->db->table('loans l')
            ->select('l.id, l.employee_id, l.loan_number, l.principal, l.installment_amount,'
                . ' l.installments, l.created_at, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = l.employee_id AND e.company_id = l.company_id', 'left')
            ->where('l.company_id', $companyId)->where('l.status', 'PENDING')
            ->orderBy('l.created_at', 'ASC')->limit(100)->get()->getResultArray();
        if ($loans !== []) $items['loan'] = $loans;

        if ($this->db->tableExists('bonus_runs')) {
            $bonus = $this->db->table('bonus_runs br')
                ->select('br.id, br.name, br.status, br.total_amount, br.recipient_count, br.pay_date,'
                    . ' br.bonus_scheme_id, bs.code AS scheme_code, bs.name AS scheme_name')
                ->join('bonus_schemes bs', 'bs.id = br.bonus_scheme_id AND bs.company_id = br.company_id', 'left')
                ->where('br.company_id', $companyId)->where('br.payroll_period_id', $payrollPeriodId)
                ->whereIn('br.status', ['DRAFT', 'CALCULATED'])->where('br.deleted_at IS NULL')
                ->orderBy('br.id', 'ASC')->limit(100)->get()->getResultArray();
            if ($bonus !== []) $items['bonus_run'] = $bonus;
        }

        $counts = [];
        foreach ($items as $key => $rows) $counts[$key] = count($rows);
        return ['total_count' => array_sum($counts), 'items' => $items, 'counts' => $counts];
    }

    /** @param array{counts:array<string,int>} $pending @return list<array{type:string,count:int,message:string}> */
    public function warnings(array $pending): array
    {
        $labels = [
            'overtime' => 'pengajuan lembur', 'attendance_change' => 'koreksi absensi',
            'reimbursement' => 'pengajuan reimbursement', 'loan' => 'pengajuan kasbon',
            'bonus_run' => 'bonus run',
        ];
        $warnings = [];
        foreach ($pending['counts'] as $type => $count) {
            if ($count <= 0) continue;
            $warnings[] = [
                'type' => $type, 'count' => $count,
                'message' => sprintf(
                    'Ada %d %s yang masih menunggu approval — perhitungan periode ini akan mengabaikan transaksi tersebut sampai di-approve & re-calculate.',
                    $count,
                    $labels[$type] ?? $type,
                ),
            ];
        }
        return $warnings;
    }
}
