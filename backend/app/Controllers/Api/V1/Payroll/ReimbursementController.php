<?php

namespace App\Controllers\Api\V1\Payroll;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Payroll\ReimbursementApprovalException;
use Config\Services;

class ReimbursementController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = trim((string) $this->request->getGet('status'));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);

        $builder = $db->table('reimbursements r')
            ->select('r.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = r.employee_id');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): tanpa `reimbursement.approve`
        // / `reimbursement.view_all`, paksa filter ke employee_id user.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'reimbursement',
            companyColumn: 'r.company_id',
            employeeColumn: 'r.employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($status) $builder->where('r.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('r.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $row = $db->table('reimbursements')
            ->where('id', (int) $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        // Sebelumnya tanpa authorization sama sekali — bisa akses ID lintas-tenant.
        if (! $this->privacy()->canAccessRow($row, 'reimbursement')) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'category'     => 'required|max_length[50]',
            'expense_date' => 'required|valid_date',
            'amount'       => 'required|numeric|greater_than[0]',
            'description'  => 'required|max_length[1000]',
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

        $reqNumber = 'RB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        // Phase 3: Check Flexible Benefit Plafon
        $policy = $db->table('benefit_policies')->where('company_id', $emp['company_id'])
                     ->where('name', $data['category'])->get()->getRowArray();

        if ($policy) {
            $year = date('Y', strtotime($data['expense_date']));
            $balance = $db->table('employee_benefit_balances')
                          ->where('company_id', $companyId)
                          ->where('employee_id', $employeeId)
                          ->where('policy_id', $policy['id'])
                          ->where('year', $year)
                          ->get()->getRowArray();

            $available = $balance ? ($balance['allocated_amount'] - $balance['used_amount']) : $policy['limit_amount'];

            if ($data['amount'] > $available) {
                return $this->fail("Klaim melebihi sisa plafon. Sisa Plafon: Rp " . number_format($available, 0, ',', '.'), 422);
            }
        }

        $insert = [
            'company_id'   => $emp['company_id'],
            'employee_id'  => $employeeId,
            'request_number' => $reqNumber,
            'category'     => $data['category'],
            'expense_date' => $data['expense_date'],
            'amount'       => $data['amount'],
            'description'  => $data['description'],
            'attachment'   => $data['attachment'] ?? null,
            'status'       => 'PENDING',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        // Sprint 6 — Cost Center: tag biaya reimbursement ke cost center
        // sehingga muncul di laporan budget vs actual kategori REIMBURSE.
        // Resolve chain: override → employee → dept → branch → company root.
        if (in_array('cost_center_id', $db->getFieldNames('reimbursements'), true)) {
            try {
                $insert['cost_center_id'] = Services::costCenter()->resolveForEmployee(
                    $employeeId,
                    ['cost_center_id' => $data['cost_center_id'] ?? null]
                );
            } catch (\Throwable $e) {
                log_message('warning', 'Reimbursement cost_center resolve failed: ' . $e->getMessage());
            }
        }

        $db->table('reimbursements')->insert($insert);
        $id = (int) $db->insertID();
        try {
            $approval = Services::approval()->start([
                'company_id' => $companyId,
                'module' => 'REIMBURSEMENT',
                'entity_table' => 'reimbursements',
                'entity_id' => $id,
                'employee_id' => $employeeId,
                'meta' => [
                    'amount' => (float) $data['amount'],
                    'category' => (string) $data['category'],
                    'expense_date' => (string) $data['expense_date'],
                    'cost_center_id' => $insert['cost_center_id'] ?? null,
                ],
            ]);
        } catch (\Throwable $exception) {
            $db->table('reimbursements')->where('id', $id)
                ->where('company_id', $companyId)->delete();
            return $this->fail(
                'Gagal membuat workflow approval: ' . $exception->getMessage(),
                500,
                'APPROVAL_START_FAILED'
            );
        }
        Services::audit()->log('reimbursement.create', 'reimbursement', $id);
        return $this->ok([
            'id' => $id,
            'request_number' => $reqNumber,
            'status' => 'PENDING',
            'approval' => $approval,
        ], 'Reimbursement requested', 201);
    }

    public function update($id = null)
    {
        return $this->fail('Cannot update directly. Use approve/reject.', 405);
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        $row = $db->table('reimbursements')
            ->where('id', (int) $id)
            ->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->privacy()->canAccessRow($row, 'reimbursement')) return $this->fail('Forbidden', 403);
        if ($row['status'] !== 'PENDING') return $this->fail('Cannot cancel', 422);
        $db->transBegin();
        try {
            $db->table('reimbursements')
                ->where('id', (int) $id)
                ->where('company_id', $companyId)
                ->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('Request berubah; muat ulang data');
            Services::approval()->cancelEntity(
                'REIMBURSEMENT', 'reimbursements', (int) $id, $companyId
            );
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membatalkan workflow');
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();
            return $this->fail($exception->getMessage(), 409, 'CANCEL_CONFLICT');
        }
        Services::audit()->log('reimbursement.cancel', 'reimbursement', (int) $id);
        return $this->ok(null, 'Cancelled');
    }

    public function approve($id)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $note = trim((string) ($this->getJsonInput()['note'] ?? ''));
        try {
            $approval = Services::approval()->approveEntity(
                'REIMBURSEMENT', 'reimbursements', (int) $id,
                $companyId, $this->userId(), $note
            );
        } catch (ReimbursementApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
        }
        if (! ($approval['final'] ?? false)) {
            Services::audit()->log('reimbursement.approval_step', 'approval_request', (int) $approval['request_id']);
            return $this->ok($approval, 'Approval step recorded');
        }
        Services::audit()->log('reimbursement.approve', 'reimbursement', (int) $id);
        return $this->ok(['approval' => $approval], 'Reimbursement approved');
    }

    public function reject($id)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $reason = trim((string) ($this->getJsonInput()['reason'] ?? ''));
        try {
            $approval = Services::approval()->rejectEntity(
                'REIMBURSEMENT', 'reimbursements', (int) $id,
                $companyId, $this->userId(), $reason
            );
        } catch (ReimbursementApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->status, $exception->errorCode);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 422, 'APPROVAL_WORKFLOW_ERROR');
        }
        Services::audit()->log('reimbursement.reject', 'reimbursement', (int) $id);
        return $this->ok(['approval' => $approval], 'Reimbursement rejected');
    }

    public function myBalances()
    {
        $db = \Config\Database::connect();
        $employeeId = $this->tenant()->getEmployeeId();
        $year = date('Y');

        $builder = $db->table('benefit_policies bp')
            ->select('bp.id, bp.name, bp.limit_amount, bp.reset_type, COALESCE(ebb.used_amount, 0) as used_amount')
            ->join('employee_benefit_balances ebb', "ebb.policy_id = bp.id AND ebb.employee_id = $employeeId AND ebb.year = $year", 'left')
            ->where('bp.company_id', $this->tenant()->getCompanyId());

        $balances = $builder->get()->getResultArray();

        foreach ($balances as &$b) {
            $b['available_amount'] = $b['limit_amount'] - $b['used_amount'];
        }

        return $this->ok($balances);
    }
}
