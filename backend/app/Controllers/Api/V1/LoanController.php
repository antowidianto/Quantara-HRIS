<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class LoanController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));

        $builder = $db->table('loans l')
            ->select('l.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = l.employee_id');
        $this->tenant()->applyScope($builder, 'l.company_id');
        if ($employeeId) $builder->where('l.employee_id', $employeeId);
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

        if ($action === 'approve') {
            if ($loan['status'] !== 'PENDING') return $this->fail('Not pending', 422);
            $db->table('loans')->where('id', $id)->update([
                'status' => 'RUNNING',
                'approved_by' => $this->userId(),
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            Services::audit()->log('loan.approve', 'loan', $id);
            return $this->ok(null, 'Loan approved & running');
        }
        if ($action === 'reject') {
            $db->table('loans')->where('id', $id)->update([
                'status' => 'REJECTED', 'approved_by' => $this->userId(), 'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->ok(null, 'Rejected');
        }
        return $this->fail('Unknown action', 400);
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $loan = $db->table('loans')->where('id', (int) $id)->get()->getRowArray();
        if (! $loan) return $this->fail('Not found', 404);
        if ($loan['status'] !== 'PENDING') return $this->fail('Cannot cancel', 422);
        $db->table('loans')->where('id', (int) $id)->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Cancelled');
    }
}