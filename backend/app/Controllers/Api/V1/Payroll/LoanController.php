<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\LoanApprovalException;
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
        $companyId = $this->requireCompanyId();
        $row = $db->table('loans')
            ->where('id', (int) $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->privacy()->canAccessRow($row, 'loan')) {
            return $this->fail('Forbidden', 403);
        }
        
        $installments = $db->table('loan_installments')
            ->where('company_id', $companyId)
            ->where('loan_id', (int) $id)
            ->orderBy('installment_number', 'ASC')
            ->get()
            ->getResultArray();
            
        $row['installments_list'] = $installments;
        
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'principal'    => 'required|numeric|greater_than[0]',
            'installments' => 'required|integer|greater_than[0]',
            'reason'       => 'required|max_length[1000]',
            'first_installment_date' => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;

        $employeeId = (int) ($data['employee_id'] ?? $this->tenant()->getEmployeeId());
        if (! $employeeId) return $this->fail('Employee not specified', 400);

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $emp = $db->table('employees')
            ->where('id', $employeeId)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $principal = (float) $data['principal'];
        $installments = (int) $data['installments'];
        $reason = $data['reason'];
        $firstInstallmentDate = (string) ($data['first_installment_date']
            ?? (new \DateTimeImmutable('first day of next month'))->format('Y-m-d'));

        $interestRate = 10.0; // Flat 10%

        try {
            $loanService = new \App\Libraries\Payroll\LoanService();
            $loan = $loanService->applyLoan(
                (int) $emp['company_id'],
                $employeeId,
                $principal,
                $interestRate,
                $installments,
                $reason
            );
            $db->table('loans')->where('id', (int) $loan['id'])
                ->where('company_id', $companyId)
                ->update(['first_installment_date' => $firstInstallmentDate]);

            try {
                $approval = Services::approval()->start([
                    'company_id' => $companyId,
                    'module' => 'LOAN',
                    'entity_table' => 'loans',
                    'entity_id' => (int) $loan['id'],
                    'employee_id' => $employeeId,
                    'meta' => [
                        'amount' => $principal,
                        'installments' => $installments,
                        'first_installment_date' => $firstInstallmentDate,
                    ],
                ]);
            } catch (\Throwable $exception) {
                $db->table('loans')->where('id', (int) $loan['id'])
                    ->where('company_id', $companyId)->delete();
                return $this->fail(
                    'Gagal membuat workflow approval: ' . $exception->getMessage(),
                    500,
                    'APPROVAL_START_FAILED'
                );
            }

            Services::audit()->log('loan.create', 'loan', $loan['id']);
            return $this->ok([
                'id' => $loan['id'],
                'loan_number' => $loan['loan_number'],
                'installment_amount' => $loan['installment_amount'],
                'first_installment_date' => $firstInstallmentDate,
                'approval' => $approval,
            ], 'Loan requested', 201);
        } catch (\Exception $e) {
            return $this->fail('Failed to apply loan: ' . $e->getMessage(), 500);
        }
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $action = $data['action'] ?? '';
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $loan = $db->table('loans')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
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
            try {
                $approval = Services::approval()->approveEntity(
                    'LOAN', 'loans', $id, $companyId, $this->userId(),
                    trim((string) ($data['note'] ?? ''))
                );
                if (! ($approval['final'] ?? false)) {
                    Services::audit()->log('loan.approval_step', 'approval_request', (int) $approval['request_id']);
                    return $this->ok($approval, 'Approval step recorded');
                }
                Services::audit()->forModule('FINANCE')->log(
                    action: 'loan.approve', entity: 'loans', entityId: $id,
                    description: "Loan #{$loan['loan_number']} disetujui (akan dipotong otomatis di payroll)",
                    severity: 'NOTICE',
                );
                return $this->ok(['approval' => $approval], 'Loan approved & running');
            } catch (LoanApprovalException $exception) {
                return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
            } catch (\Throwable $exception) {
                return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
            }
        }
        if ($action === 'reject') {
            try {
                $reason = trim((string) ($data['reason'] ?? ''));
                $approval = Services::approval()->rejectEntity(
                    'LOAN', 'loans', $id, $companyId, $this->userId(), $reason
                );
                Services::audit()->forModule('FINANCE')->log(
                    action: 'loan.reject', entity: 'loans', entityId: $id,
                    description: "Loan #{$loan['loan_number']} ditolak",
                );
                return $this->ok(['approval' => $approval], 'Rejected');
            } catch (LoanApprovalException $exception) {
                return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
            } catch (\Throwable $exception) {
                return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
            }
        }
        return $this->fail('Unknown action', 400);
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $loan = $db->table('loans')
            ->where('id', (int) $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $loan) return $this->fail('Not found', 404);

        // Hanya owner atau yang punya `loan.approve` boleh cancel.
        $tenant = $this->tenant();
        $myEmpId = (int) $tenant->getEmployeeId();
        $isOwner = $myEmpId > 0 && (int) $loan['employee_id'] === $myEmpId;
        if (! $isOwner && ! $tenant->isSuperAdmin() && ! $tenant->hasPermission('loan.approve')) {
            return $this->fail('Forbidden', 403);
        }
        if ($loan['status'] !== 'PENDING') return $this->fail('Cannot cancel — only PENDING loans can be cancelled', 422);

        $db->transBegin();
        try {
            $db->table('loans')
                ->where('id', (int) $id)
                ->where('company_id', $companyId)
                ->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('Request berubah; muat ulang data');
            Services::approval()->cancelEntity('LOAN', 'loans', (int) $id, $companyId);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membatalkan workflow');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            return $this->fail($exception->getMessage(), 409, 'CANCEL_CONFLICT');
        }
        Services::audit()->forModule('FINANCE')->log(
            action: 'loan.cancel', entity: 'loans', entityId: (int) $id,
            description: "Loan #{$loan['loan_number']} dibatalkan",
        );
        return $this->ok(null, 'Cancelled');
    }
}
