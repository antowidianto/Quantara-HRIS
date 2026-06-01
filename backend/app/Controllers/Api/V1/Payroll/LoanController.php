<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class LoanController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));

        $builder = $db->table('loans l')
            ->select('l.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = l.employee_id');

        // Privacy scope: tanpa `loan.approve`/`loan.view_all`, paksa filter ke
        // employee_id user. Detail pinjaman = sensitive financial data.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'loan',
            companyColumn: 'l.company_id',
            employeeColumn: 'l.employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($status) $builder->where('l.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('l.created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('loans')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->privacy()->canAccessRow($row, 'loan')) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'principal'    => 'required|numeric|greater_than[0]',
            'installments' => 'required|integer|greater_than[0]',
            'reason'       => 'required|max_length[1000]',
        ]);
        if (! $valid) return null;

        $employeeId = (int) ($data['employee_id'] ?? $this->tenant()->getEmployeeId());
        if (! $employeeId) return $this->fail('Employee not specified', 400);

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $loanNumber = 'LN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $principal = (float) $data['principal'];
        $installments = (int) $data['installments'];
        $instAmount = round($principal / $installments);

        $insert = [
            'company_id'      => $emp['company_id'],
            'employee_id'     => $employeeId,
            'loan_number'     => $loanNumber,
            'principal'       => $principal,
            'installment_amount' => $instAmount,
            'installments'    => $installments,
            'paid_installments' => 0,
            'remaining'       => $principal,
            'reason'          => $data['reason'],
            'status'          => 'PENDING',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        // Sprint 6 — Cost Center: tag pinjaman ke cost center karyawan
        // (asset finance perspective). Resolve via chain.
        if (in_array('cost_center_id', $db->getFieldNames('loans'), true)) {
            try {
                $insert['cost_center_id'] = Services::costCenter()->resolveForEmployee(
                    $employeeId,
                    ['cost_center_id' => $data['cost_center_id'] ?? null]
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Loan cost_center resolve failed: ' . $e->getMessage());
            }
        }

        $db->table('loans')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->log('loan.create', 'loan', $id);
        return $this->ok(['id' => $id, 'loan_number' => $loanNumber, 'installment_amount' => $instAmount], 'Loan requested', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $action = $data['action'] ?? '';
        $db = \Config\Database::connect();
        $loan = $db->table('loans')->where('id', $id)->get()->getRowArray();
        if (! $loan) return $this->fail('Not found', 404);

        // Pastikan loan masuk scope tenant user (cegah cross-company write).
        if (! $this->privacy()->canAccessRow($loan, 'loan')) {
            return $this->fail('Forbidden', 403);
        }

        // Approve/reject sangat sensitif: harus punya permission `loan.approve`,
        // dan tidak boleh approve loan milik diri sendiri (separation of duty).
        if ($action === 'approve' || $action === 'reject') {
            $tenant = $this->tenant();
            if (! $tenant->isSuperAdmin() && ! $tenant->hasPermission('loan.approve')) {
                return $this->fail('Forbidden — butuh izin loan.approve', 403);
            }
            $myEmpId = (int) $tenant->getEmployeeId();
            if ($myEmpId > 0 && (int) $loan['employee_id'] === $myEmpId) {
                return $this->fail('Tidak bisa approve/reject pinjaman sendiri', 422,
                    'CANNOT_APPROVE_OWN_LOAN');
            }
        }

        if ($action === 'approve') {
            if ($loan['status'] !== 'PENDING') return $this->fail('Not pending', 422);
            $db->table('loans')->where('id', $id)->update([
                'status' => 'RUNNING',
                'approved_by' => $this->userId(),
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            Services::audit()->forModule('FINANCE')->log(
                action: 'loan.approve', entity: 'loans', entityId: $id,
                description: "Loan #{$loan['loan_number']} disetujui (akan dipotong otomatis di payroll)",
                severity: 'NOTICE',
            );
            return $this->ok(null, 'Loan approved & running');
        }
        if ($action === 'reject') {
            if ($loan['status'] !== 'PENDING') return $this->fail('Not pending', 422);
            $db->table('loans')->where('id', $id)->update([
                'status' => 'REJECTED', 'approved_by' => $this->userId(), 'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Services::audit()->forModule('FINANCE')->log(
                action: 'loan.reject', entity: 'loans', entityId: $id,
                description: "Loan #{$loan['loan_number']} ditolak",
            );
            return $this->ok(null, 'Rejected');
        }
        return $this->fail('Unknown action', 400);
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $loan = $db->table('loans')->where('id', (int) $id)->get()->getRowArray();
        if (! $loan) return $this->fail('Not found', 404);

        // Hanya owner atau yang punya `loan.approve` boleh cancel.
        $tenant = $this->tenant();
        $myEmpId = (int) $tenant->getEmployeeId();
        $isOwner = $myEmpId > 0 && (int) $loan['employee_id'] === $myEmpId;
        if (! $isOwner && ! $tenant->isSuperAdmin() && ! $tenant->hasPermission('loan.approve')) {
            return $this->fail('Forbidden', 403);
        }
        if ($loan['status'] !== 'PENDING') return $this->fail('Cannot cancel — only PENDING loans can be cancelled', 422);

        $db->table('loans')->where('id', (int) $id)->update([
            'status' => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('FINANCE')->log(
            action: 'loan.cancel', entity: 'loans', entityId: (int) $id,
            description: "Loan #{$loan['loan_number']} dibatalkan",
        );
        return $this->ok(null, 'Cancelled');
    }
}