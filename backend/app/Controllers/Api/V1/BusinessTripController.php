<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * BusinessTripController
 *
 * REST API untuk SPPD (Surat Perjalanan Dinas).
 *
 * Authorization layered:
 *   - `business_trip.view`    → list/show. Tanpa manage/approve/settle, user
 *                               hanya lihat trip miliknya sendiri.
 *   - `business_trip.manage`  → create/update/delete trip orang lain
 *   - `business_trip.approve` → approve/reject pending trip
 *   - `business_trip.settle`  → finalkan settlement
 *
 * Endpoint:
 *   GET    /business-trips                   — list
 *   POST   /business-trips                   — buat draft
 *   GET    /business-trips/(:num)            — detail + expenses
 *   PUT    /business-trips/(:num)            — update (DRAFT only)
 *   DELETE /business-trips/(:num)            — soft delete (DRAFT/REJECTED/CANCELLED)
 *   POST   /business-trips/(:num)/submit     — DRAFT → PENDING
 *   POST   /business-trips/(:num)/approve    — PENDING → APPROVED
 *   POST   /business-trips/(:num)/reject     — PENDING → REJECTED
 *   POST   /business-trips/(:num)/start      — APPROVED → IN_PROGRESS
 *   POST   /business-trips/(:num)/complete   — IN_PROGRESS/APPROVED → COMPLETED
 *   POST   /business-trips/(:num)/settle     — COMPLETED → SETTLED (auto-create reimburse)
 *   POST   /business-trips/(:num)/cancel     — cancel kapan saja kecuali SETTLED
 *   GET    /business-trips/(:num)/sppd       — download SPPD PDF/HTML
 *   GET    /business-trips/(:num)/expenses   — list expense items
 *   POST   /business-trips/(:num)/expenses   — add expense
 *   PUT    /business-trip-expenses/(:num)    — update expense
 *   DELETE /business-trip-expenses/(:num)    — delete expense
 */
class BusinessTripController extends ApiController
{
    private const VALID_TRANSPORT = ['PESAWAT','KERETA','BUS','MOBIL_DINAS','MOBIL_PRIBADI','KAPAL','OTHER'];
    private const VALID_EXPENSE_CATS = ['TRANSPORT','ACCOMMODATION','MEAL','DAILY_ALLOWANCE','FUEL','PARKING','TOLL','OTHER'];

    // ────────────────────────────────────────────────────────────────
    // Trip CRUD
    // ────────────────────────────────────────────────────────────────

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $status = strtoupper((string) ($this->request->getGet('status') ?? ''));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $mineOnly = (int) ($this->request->getGet('mine') ?? 0) === 1;

        $builder = $db->table('business_trips bt')
            ->select('bt.*, e.full_name AS employee_name, e.employee_number, e.department_id, d.name AS department_name')
            ->join('employees e', 'e.id = bt.employee_id')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->where('bt.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): tanpa
        // `business_trip.approve`/`view_all`/`manage`, paksa filter ke
        // perjalanan dinas miliknya. mine=1 shortcut untuk explicit self filter.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'business_trip',
            companyColumn: 'bt.company_id',
            employeeColumn: 'bt.employee_id',
            requestedEmployeeId: $mineOnly
                ? $this->tenant()->getEmployeeId()
                : ($requestedEmpId ?: null),
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($q !== '') {
            $builder->groupStart()
                ->like('bt.reference_number', $q)
                ->orLike('bt.destination', $q)
                ->orLike('bt.purpose', $q)
                ->orLike('e.full_name', $q)
                ->groupEnd();
        }
        if ($status !== '') $builder->where('bt.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('bt.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;

        $db = \Config\Database::connect();
        $expenses = $db->table('business_trip_expenses')
            ->where('business_trip_id', (int) $id)
            ->orderBy('expense_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $row['expenses'] = $expenses;
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'purpose'     => 'required|max_length[1000]',
            'destination' => 'required|max_length[200]',
            'start_date'  => 'required|valid_date',
            'end_date'    => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $tenant = $this->tenant();
        $canManageOthers = $tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.manage');

        $employeeId = (int) ($data['employee_id'] ?? 0);
        if (! $employeeId) $employeeId = (int) ($tenant->getEmployeeId() ?? 0);
        if (! $employeeId) return $this->fail('employee_id required', 422);

        // Pegawai biasa hanya boleh request untuk dirinya sendiri
        if (! $canManageOthers && $employeeId !== $tenant->getEmployeeId()) {
            return $this->fail('Forbidden: hanya boleh request SPPD untuk diri sendiri', 403);
        }

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            return $this->fail('end_date harus ≥ start_date', 422);
        }

        $svc = Services::businessTrip();
        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => (int) $emp['company_id'],
            'employee_id'      => $employeeId,
            'reference_number' => $svc->nextReferenceNumber((int) $emp['company_id']),
            'purpose'          => $data['purpose'],
            'destination'      => $data['destination'],
            'destination_type' => in_array(strtoupper((string) ($data['destination_type'] ?? 'DOMESTIC')), ['DOMESTIC','INTERNATIONAL'], true)
                ? strtoupper((string) ($data['destination_type'] ?? 'DOMESTIC')) : 'DOMESTIC',
            'start_date'       => $data['start_date'],
            'end_date'         => $data['end_date'],
            'duration_days'    => $svc->computeDurationDays($data['start_date'], $data['end_date']),
            'transport_mode'   => isset($data['transport_mode']) && in_array(strtoupper((string) $data['transport_mode']), self::VALID_TRANSPORT, true)
                ? strtoupper((string) $data['transport_mode']) : null,
            'accommodation'    => $data['accommodation'] ?? null,
            'advance_amount'   => (float) ($data['advance_amount'] ?? 0),
            'status'           => 'DRAFT',
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $this->userId(),
            'updated_by'       => $this->userId(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        // Sprint 6 — Cost Center: tag biaya perjalanan dinas ke cost center
        // karyawan. Resolve via chain dengan override eksplisit kalau payload
        // explicit (mis. trip lintas department, di-charge ke department lain).
        if (in_array('cost_center_id', $db->getFieldNames('business_trips'), true)) {
            try {
                $insert['cost_center_id'] = Services::costCenter()->resolveForEmployee(
                    $employeeId,
                    ['cost_center_id' => $data['cost_center_id'] ?? null]
                );
            } catch (\Throwable $e) {
                log_message('warning', 'BusinessTrip cost_center resolve failed: ' . $e->getMessage());
            }
        }

        $db->table('business_trips')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('BUSINESS_TRIP')->logCreate('business_trip', $id, $insert,
            "SPPD baru: {$insert['reference_number']} ke {$insert['destination']}");
        return $this->ok(['id' => $id, 'reference_number' => $insert['reference_number']], 'Created', 201);
    }

    public function update($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        if ($row['status'] !== 'DRAFT') {
            return $this->fail('Hanya status DRAFT yang bisa diubah', 422);
        }

        $tenant = $this->tenant();
        $canManageOthers = $tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.manage');
        if (! $canManageOthers && (int) $row['employee_id'] !== $tenant->getEmployeeId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'purpose','destination','destination_type','start_date','end_date',
            'transport_mode','accommodation','advance_amount','notes',
        ]));
        $svc = Services::businessTrip();
        if (isset($update['start_date']) || isset($update['end_date'])) {
            $start = $update['start_date'] ?? $row['start_date'];
            $end = $update['end_date'] ?? $row['end_date'];
            if (strtotime($end) < strtotime($start)) return $this->fail('end_date harus ≥ start_date', 422);
            $update['duration_days'] = $svc->computeDurationDays($start, $end);
        }
        if (isset($update['transport_mode']) && ! in_array(strtoupper((string) $update['transport_mode']), self::VALID_TRANSPORT, true)) {
            return $this->fail('transport_mode tidak valid', 422);
        }
        $update['updated_by'] = $this->userId();
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('business_trips')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('BUSINESS_TRIP')->logUpdate('business_trip', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        if (! in_array($row['status'], ['DRAFT','REJECTED','CANCELLED'], true)) {
            return $this->fail('Trip status tidak bisa di-delete', 422);
        }
        \Config\Database::connect()->table('business_trips')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('BUSINESS_TRIP')->logDelete('business_trip', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Workflow transitions
    // ────────────────────────────────────────────────────────────────

    public function submit($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.manage')
            || (int) $row['employee_id'] === $tenant->getEmployeeId())) {
            return $this->fail('Forbidden', 403);
        }
        try {
            $r = Services::businessTrip()->submit((int) $id, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('BUSINESS_TRIP')->log(
            action: 'business_trip.submit', entity: 'business_trip', entityId: (int) $id,
            description: "Submit SPPD {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok($r, 'Submitted');
    }

    public function approve($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $note = (string) ($this->getJsonInput()['note'] ?? '');
        try {
            Services::businessTrip()->approve((int) $id, $this->userId(), $note);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('BUSINESS_TRIP')->log(
            action: 'business_trip.approve', entity: 'business_trip', entityId: (int) $id,
            after: ['note' => $note], description: "Approve SPPD {$row['reference_number']}",
            severity: 'NOTICE'
        );
        return $this->ok(null, 'Approved');
    }

    public function reject($id = null)
    {
        if (! $this->canApprove()) return $this->fail('Forbidden', 403);
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $data = $this->getJsonInput();
        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 5) return $this->fail('Alasan minimal 5 karakter', 422);
        try {
            Services::businessTrip()->reject((int) $id, $reason, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('BUSINESS_TRIP')->log(
            action: 'business_trip.reject', entity: 'business_trip', entityId: (int) $id,
            after: ['reason' => $reason], description: "Reject SPPD {$row['reference_number']}",
            severity: 'WARNING'
        );
        return $this->ok(null, 'Rejected');
    }

    public function start($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || (int) $row['employee_id'] === $tenant->getEmployeeId()
            || $tenant->hasPermission('business_trip.manage'))) {
            return $this->fail('Forbidden', 403);
        }
        if ($row['status'] !== 'APPROVED') return $this->fail('Hanya APPROVED yang bisa di-start', 422);
        \Config\Database::connect()->table('business_trips')->where('id', (int) $id)->update([
            'status' => 'IN_PROGRESS',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Started');
    }

    public function complete($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || (int) $row['employee_id'] === $tenant->getEmployeeId()
            || $tenant->hasPermission('business_trip.manage'))) {
            return $this->fail('Forbidden', 403);
        }
        try {
            Services::businessTrip()->markCompleted((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('BUSINESS_TRIP')->log(
            action: 'business_trip.complete', entity: 'business_trip', entityId: (int) $id,
            description: "Complete SPPD {$row['reference_number']}", severity: 'NOTICE'
        );
        return $this->ok(null, 'Completed');
    }

    public function settle($id = null)
    {
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.settle'))) {
            return $this->fail('Forbidden', 403);
        }
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        try {
            $r = Services::businessTrip()->settle((int) $id, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        Services::audit()->forModule('BUSINESS_TRIP')->log(
            action: 'business_trip.settle', entity: 'business_trip', entityId: (int) $id,
            after: $r, description: "Settle SPPD {$row['reference_number']}, selisih: " . $r['settlement_amount'],
            severity: 'CRITICAL'
        );
        return $this->ok($r, 'Settled');
    }

    public function cancel($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $tenant = $this->tenant();
        if (! ($tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.manage')
            || (int) $row['employee_id'] === $tenant->getEmployeeId())) {
            return $this->fail('Forbidden', 403);
        }
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        try {
            Services::businessTrip()->cancel((int) $id, $reason);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(null, 'Cancelled');
    }

    public function sppd($id = null)
    {
        $row = $this->loadTripOrFail((int) $id);
        if (! $row) return $this->response;
        $svc = Services::businessTrip();
        $html = $row['sppd_html'] ?? '';
        if ($html === '') $html = $svc->renderSppdHtml((int) $id);

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        if ($format === 'html') {
            return $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($html);
        }
        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        $filename = sprintf('SPPD_%s.pdf', preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['reference_number']));
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($output);
    }

    // ────────────────────────────────────────────────────────────────
    // Expense line items
    // ────────────────────────────────────────────────────────────────

    public function listExpenses($tripId = null)
    {
        $row = $this->loadTripOrFail((int) $tripId);
        if (! $row) return $this->response;
        $rows = \Config\Database::connect()->table('business_trip_expenses')
            ->where('business_trip_id', (int) $tripId)
            ->orderBy('expense_date', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function addExpense($tripId = null)
    {
        $row = $this->loadTripOrFail((int) $tripId);
        if (! $row) return $this->response;
        // Hanya boleh tambah expense untuk trip APPROVED/IN_PROGRESS/COMPLETED (sebelum SETTLED)
        if (! in_array($row['status'], ['APPROVED','IN_PROGRESS','COMPLETED'], true)) {
            return $this->fail('Trip status ' . $row['status'] . ' tidak bisa terima expense baru', 422);
        }
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'category'    => 'required|in_list[' . implode(',', self::VALID_EXPENSE_CATS) . ']',
            'description' => 'required|max_length[500]',
            'amount'      => 'required|numeric|greater_than[0]',
        ]);
        if (! $valid) return null;

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => (int) $row['company_id'],
            'business_trip_id' => (int) $tripId,
            'category'         => $data['category'],
            'expense_date'     => $data['expense_date'] ?? null,
            'description'      => $data['description'],
            'amount'           => (float) $data['amount'],
            'receipt_path'     => $data['receipt_path'] ?? null,
            'is_billable'      => isset($data['is_billable']) ? (int) (bool) $data['is_billable'] : 1,
            'created_at'       => $now, 'updated_at' => $now,
        ];
        $db = \Config\Database::connect();
        $db->table('business_trip_expenses')->insert($insert);
        $id = (int) $db->insertID();

        // Auto-recompute actual_amount
        Services::businessTrip()->recomputeActual((int) $tripId);
        return $this->ok(['id' => $id], 'Expense added', 201);
    }

    public function updateExpense($expenseId = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trip_expenses')->where('id', (int) $expenseId)->get()->getRowArray();
        if (! $row) return $this->fail('Expense not found', 404);
        $trip = $this->loadTripOrFail((int) $row['business_trip_id']);
        if (! $trip) return $this->response;
        if ($trip['status'] === 'SETTLED') return $this->fail('Trip sudah SETTLED', 422);

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['category','expense_date','description','amount','receipt_path','is_billable']));
        if (isset($update['amount'])) $update['amount'] = (float) $update['amount'];
        if (isset($update['is_billable'])) $update['is_billable'] = (int) (bool) $update['is_billable'];
        if (isset($update['category']) && ! in_array($update['category'], self::VALID_EXPENSE_CATS, true)) {
            return $this->fail('category tidak valid', 422);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('business_trip_expenses')->where('id', (int) $expenseId)->update($update);
        Services::businessTrip()->recomputeActual((int) $row['business_trip_id']);
        return $this->ok(null, 'Updated');
    }

    public function deleteExpense($expenseId = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trip_expenses')->where('id', (int) $expenseId)->get()->getRowArray();
        if (! $row) return $this->fail('Expense not found', 404);
        $trip = $this->loadTripOrFail((int) $row['business_trip_id']);
        if (! $trip) return $this->response;
        if ($trip['status'] === 'SETTLED') return $this->fail('Trip sudah SETTLED', 422);
        $db->table('business_trip_expenses')->where('id', (int) $expenseId)->delete();
        Services::businessTrip()->recomputeActual((int) $row['business_trip_id']);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canApprove(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('business_trip.approve');
    }

    private function loadTripOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('business_trips')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Trip not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        // Pegawai biasa hanya boleh akses trip miliknya sendiri (kecuali punya perm)
        $canViewAll = $tenant->isSuperAdmin() || $tenant->hasPermission('business_trip.manage')
            || $tenant->hasPermission('business_trip.approve')
            || $tenant->hasPermission('business_trip.settle');
        if (! $canViewAll && (int) $row['employee_id'] !== $tenant->getEmployeeId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}