<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class PayrollPeriodController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $builder = $db->table('payroll_periods');
        $this->tenant()->applyScope($builder);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('start_date', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('payroll_periods')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'required|max_length[100]',
            'period_year' => 'required|integer',
            'start_date'  => 'required|valid_date',
            'end_date'    => 'required|valid_date',
            'pay_date'    => 'required|valid_date',
            'type'        => 'permit_empty|in_list[MONTHLY,BI_WEEKLY,WEEKLY,THR,BONUS]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $code = $data['code'] ?? ('P-' . $data['period_year'] . '-' . str_pad((string) ($data['period_month'] ?? 0), 2, '0', STR_PAD_LEFT));
        $insert = [
            'company_id'   => $companyId,
            'code'         => $code,
            'name'         => $data['name'],
            'type'         => $data['type'] ?? 'MONTHLY',
            'period_year'  => $data['period_year'],
            'period_month' => $data['period_month'] ?? null,
            'start_date'   => $data['start_date'],
            'end_date'     => $data['end_date'],
            'pay_date'     => $data['pay_date'],
            'cutoff_attendance_date' => $data['cutoff_attendance_date'] ?? $data['end_date'],
            'is_december_correction' => ! empty($data['is_december_correction']) ? 1 : 0,
            'status'       => 'DRAFT',
            'created_by'   => $this->userId(),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        $db->table('payroll_periods')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('PAYROLL')
            ->logCreate(
                'payroll_period',
                $id,
                $insert,
                "Membuat periode payroll: {$insert['name']} ({$insert['start_date']} s/d {$insert['end_date']})"
            );
        return $this->ok(['id' => $id], 'Period created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('payroll_periods')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if ($existing['status'] !== 'DRAFT') return $this->fail('Cannot update non-draft period', 422);

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name','start_date','end_date','pay_date','cutoff_attendance_date']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('payroll_periods')->where('id', $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('payroll_periods')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! in_array($existing['status'], ['DRAFT', 'CALCULATED'], true)) {
            return $this->fail('Cannot delete finalized period', 422);
        }
        $db->table('payslip_details')->whereIn('payslip_id', function ($q) use ($id) {
            $q->select('id')->from('payslips')->where('payroll_period_id', $id);
        })->delete();
        $db->table('payslips')->where('payroll_period_id', $id)->delete();
        $db->table('payroll_periods')->where('id', $id)->delete();
        Services::audit()
            ->forModule('PAYROLL')
            ->logDelete(
                'payroll_period',
                $id,
                $existing,
                "Menghapus periode payroll: {$existing['name']} ({$existing['code']})"
            );
        return $this->ok(null, 'Deleted');
    }

    public function calculate($id = null)
    {
        $id = (int) $id;
        $audit = Services::audit()->forModule('PAYROLL')->start();
        try {
            $summary = Services::payroll()->calculatePeriod($id);
            $audit->log(
                action: 'payroll_period.calculate',
                entity: 'payroll_period',
                entityId: $id,
                after: $summary,
                description: 'Menghitung payroll periode #' . $id,
                severity: 'NOTICE'
            );
            return $this->ok($summary, 'Period calculated');
        } catch (\Throwable $e) {
            log_message('error', $e->getMessage());
            $audit->logFailure(
                'payroll_period.calculate',
                'payroll_period',
                $id,
                'CALCULATE_FAILED',
                'Gagal menghitung payroll periode #' . $id . ': ' . $e->getMessage()
            );
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function finalize($id = null)
    {
        $id = (int) $id;
        $audit = Services::audit()->forModule('PAYROLL')->start();
        try {
            Services::payroll()->finalize($id);
            $audit->log(
                action: 'payroll_period.finalize',
                entity: 'payroll_period',
                entityId: $id,
                description: 'Finalisasi & pembayaran payroll periode #' . $id,
                severity: 'CRITICAL'
            );
            return $this->ok(null, 'Period finalized & payroll paid');
        } catch (\Throwable $e) {
            $audit->logFailure(
                'payroll_period.finalize',
                'payroll_period',
                $id,
                'FINALIZE_FAILED',
                'Gagal finalisasi payroll periode #' . $id . ': ' . $e->getMessage()
            );
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * GET /payroll-periods/(:num)/bank-export?bank=BCA
     * Download CSV batch transfer bank.
     */
    public function bankExport($id = null)
    {
        $id = (int) $id;
        $bank = strtoupper((string) ($this->request->getGet('bank') ?? 'BCA'));
        try {
            $file = Services::payrollExporter()->bankTransfer($id, $bank);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.bank',
            entity: 'payroll_period', entityId: $id,
            description: "Download bank transfer ({$bank}) periode #{$id}",
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/pph21-export?final=1
     * Download CSV e-SPT PPh21 untuk masa.
     */
    public function pph21Export($id = null)
    {
        $id = (int) $id;
        $final = (int) ($this->request->getGet('final') ?? 0) === 1;
        try {
            $file = Services::payrollExporter()->esptPph21($id, $final);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.pph21',
            entity: 'payroll_period', entityId: $id,
            description: 'Download e-SPT PPh21 periode #' . $id . ($final ? ' (FINAL)' : ''),
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/bpjs-export?type=TK|KES
     * Download CSV iuran BPJS Ketenagakerjaan (SiPP) atau Kesehatan (e-Dabu).
     */
    public function bpjsExport($id = null)
    {
        $id = (int) $id;
        $type = strtoupper((string) ($this->request->getGet('type') ?? 'TK'));
        if (! in_array($type, ['TK', 'KES'], true)) {
            return $this->fail('type harus TK atau KES', 422);
        }
        try {
            $file = $type === 'TK'
                ? Services::payrollExporter()->bpjsKetenagakerjaan($id)
                : Services::payrollExporter()->bpjsKesehatan($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        Services::audit()->forModule('PAYROLL')->log(
            action: 'payroll.export.bpjs_' . strtolower($type),
            entity: 'payroll_period', entityId: $id,
            description: "Download BPJS {$type} periode #{$id}",
            severity: 'NOTICE'
        );

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->setBody($file['content']);
    }

    /**
     * GET /payroll-periods/(:num)/export-summary
     * Ringkasan ekspor: jumlah karyawan, total amount, daftar bank yg dipakai,
     * apakah ada karyawan tanpa rekening / NPWP / BPJS.
     */
    public function exportSummary($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $period = $db->table('payroll_periods')->where('id', $id)->get()->getRowArray();
        if (! $period) return $this->fail('Period not found', 404);

        $payslips = $db->table('payslips p')
            ->select('p.id, p.take_home_pay, p.gross_earning, p.pph21_amount,'
                . ' e.full_name, e.employee_number, e.bank_name, e.bank_account_encrypted,'
                . ' e.npwp_encrypted, e.bpjs_kesehatan_no, e.bpjs_tk_no')
            ->join('employees e', 'e.id = p.employee_id')
            ->where('p.payroll_period_id', $id)
            ->get()->getResultArray();

        $bankBuckets = [];
        $missingAccount = [];
        $missingNpwp = [];
        $missingBpjsKes = [];
        $missingBpjsTk = [];
        $totalNet = 0;
        $totalPph = 0;

        foreach ($payslips as $p) {
            $totalNet += (float) $p['take_home_pay'];
            $totalPph += (float) $p['pph21_amount'];

            $bank = strtoupper((string) ($p['bank_name'] ?? ''));
            if ($bank !== '') $bankBuckets[$bank] = ($bankBuckets[$bank] ?? 0) + 1;

            $miss = ['id' => $p['id'], 'name' => $p['full_name'], 'no' => $p['employee_number']];
            if (empty($p['bank_account_encrypted'])) $missingAccount[] = $miss;
            if (empty($p['npwp_encrypted']))         $missingNpwp[] = $miss;
            if (empty($p['bpjs_kesehatan_no']))      $missingBpjsKes[] = $miss;
            if (empty($p['bpjs_tk_no']))             $missingBpjsTk[] = $miss;
        }

        return $this->ok([
            'period'          => $period,
            'employees'       => count($payslips),
            'total_net'       => $totalNet,
            'total_pph21'     => $totalPph,
            'bank_breakdown'  => $bankBuckets,
            'missing'         => [
                'bank_account' => $missingAccount,
                'npwp'         => $missingNpwp,
                'bpjs_kes'     => $missingBpjsKes,
                'bpjs_tk'      => $missingBpjsTk,
            ],
            'supported_banks' => \App\Libraries\PayrollExporter::supportedBanks(),
        ]);
    }
}
