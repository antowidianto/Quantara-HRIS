<?php

namespace App\Controllers\Api\V1;

class PayslipController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $periodId = (int) ($this->request->getGet('period_id') ?? 0);
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);

        $builder = $db->table('payslips ps')
            ->select('ps.*, pp.name AS period_name, pp.code AS period_code')
            ->join('payroll_periods pp', 'pp.id = ps.payroll_period_id');
        $this->tenant()->applyScope($builder, 'ps.company_id');
        if ($periodId) $builder->where('ps.payroll_period_id', $periodId);
        if ($employeeId) $builder->where('ps.employee_id', $employeeId);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('ps.created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function my()
    {
        $employeeId = $this->tenant()->getEmployeeId();
        if (! $employeeId) return $this->fail('No employee linked', 400);
        $db = \Config\Database::connect();
        $rows = $db->table('payslips ps')
            ->select('ps.*, pp.name AS period_name')
            ->join('payroll_periods pp', 'pp.id = ps.payroll_period_id')
            ->where('ps.employee_id', $employeeId)
            ->orderBy('ps.created_at', 'DESC')
            ->limit(24)
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $ps = $db->table('payslips')->where('id', $id)->get()->getRowArray();
        if (! $ps) return $this->fail('Not found', 404);

        // Authorize: employee can only see own
        if (! $this->tenant()->isSuperAdmin()) {
            if ((int) $ps['company_id'] !== $this->companyId()) return $this->fail('Forbidden', 403);
            // Non-HR can only see own
            $roleId = $this->tenant()->getRoleId();
            if (in_array($roleId, [4], true) && (int) $ps['employee_id'] !== $this->tenant()->getEmployeeId()) {
                return $this->fail('Forbidden', 403);
            }
        }

        $details = $db->table('payslip_details')->where('payslip_id', $id)->orderBy('sort_order', 'ASC')->get()->getResultArray();
        $ps['details'] = $details;
        return $this->ok($ps);
    }

    public function pdf($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $ps = $db->table('payslips')->where('id', $id)->get()->getRowArray();
        if (! $ps) return $this->fail('Not found', 404);

        if (! $this->tenant()->isSuperAdmin()) {
            if ((int) $ps['company_id'] !== $this->companyId()) return $this->fail('Forbidden', 403);
            $roleId = $this->tenant()->getRoleId();
            if (in_array($roleId, [4], true) && (int) $ps['employee_id'] !== $this->tenant()->getEmployeeId()) {
                return $this->fail('Forbidden', 403);
            }
        }

        $period = $db->table('payroll_periods')->where('id', $ps['payroll_period_id'])->get()->getRowArray();
        $company = $db->table('companies')->where('id', $ps['company_id'])->get()->getRowArray();
        $details = $db->table('payslip_details')->where('payslip_id', $id)->orderBy('sort_order', 'ASC')->get()->getResultArray();

        $html = view('payslip_pdf', compact('ps', 'period', 'company', 'details'));

        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $ps['slip_number'] . '.pdf"')
            ->setBody($output);
    }

    /**
     * Bukti Potong PPh21 (1721-VI bulanan / 1721-A1 final).
     * GET /api/v1/payslips/(:num)/bukti-potong-pdf
     * GET /api/v1/payslips/(:num)/bukti-potong-html?inline=1
     */
    public function buktiPotong($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $ps = $db->table('payslips')->where('id', $id)->get()->getRowArray();
        if (! $ps) return $this->fail('Not found', 404);

        // Authorization sama dengan show()/pdf().
        if (! $this->tenant()->isSuperAdmin()) {
            if ((int) $ps['company_id'] !== $this->companyId()) return $this->fail('Forbidden', 403);
            $roleId = $this->tenant()->getRoleId();
            if (in_array($roleId, [4], true) && (int) $ps['employee_id'] !== $this->tenant()->getEmployeeId()) {
                return $this->fail('Forbidden', 403);
            }
        }

        try {
            $file = \Config\Services::payrollExporter()->buktiPotongHtml($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // Kalau klien minta HTML (untuk preview/edit), serve as-is. Default
        // konversi ke PDF via Dompdf supaya bisa langsung diunduh & ditandatangani.
        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        if ($format === 'html') {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody($file['content']);
        }

        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($file['content']);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();

        $pdfName = preg_replace('/\.html$/', '.pdf', $file['filename']);
        \Config\Services::audit()->forModule('PAYROLL')->log(
            action: 'payslip.bukti_potong',
            entity: 'payslip', entityId: $id,
            description: 'Download bukti potong untuk payslip #' . $id,
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $pdfName . '"')
            ->setBody($output);
    }
}
