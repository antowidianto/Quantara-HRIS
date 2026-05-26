<?php

namespace App\Controllers\Api\V1;

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
        $row = $db->table('reimbursements')->where('id', (int) $id)->get()->getRowArray();
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
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $reqNumber = 'RB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
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
        Services::audit()->log('reimbursement.create', 'reimbursement', $id);
        return $this->ok(['id' => $id, 'request_number' => $reqNumber], 'Reimbursement requested', 201);
    }

    public function update($id = null)
    {
        return $this->fail('Cannot update directly. Use approve/reject.', 405);
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('reimbursements')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if ($row['status'] !== 'PENDING') return $this->fail('Cannot cancel', 422);
        $db->table('reimbursements')->where('id', (int) $id)->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Cancelled');
    }
}