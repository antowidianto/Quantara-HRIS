<?php

namespace App\Controllers\Api\V1\Asset;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * AssetController
 *
 * REST API untuk Asset & Asset Assignment.
 *
 * Authorization:
 *   - `asset.view`   → list/show + lihat aset milik sendiri
 *   - `asset.manage` → create/update asset, assign/return ke karyawan
 *
 * Endpoint utama:
 *   GET    /assets                          — list dgn filter
 *   GET    /assets/(:num)                   — detail + history assignment
 *   POST   /assets                          — create asset baru
 *   PUT    /assets/(:num)                   — update asset
 *   DELETE /assets/(:num)                   — soft-delete (kalau status AVAILABLE/RETIRED)
 *   POST   /assets/(:num)/assign            — assign ke karyawan
 *   POST   /assets/(:num)/return            — terima return (close assignment aktif)
 *   GET    /assets/(:num)/bast/(:num)?mode=ASSIGN|RETURN&format=pdf|html — BAST PDF/HTML
 *   GET    /asset-assignments               — list assignment (filter employee, asset, returned)
 *   GET    /asset-assignments/employee/(:num) — list aset yg dipegang karyawan
 *   GET    /asset-assignments/(:num)        — detail assignment
 */
class AssetController extends ApiController
{
    private const VALID_CATEGORIES = ['LAPTOP','DESKTOP','PHONE','VEHICLE','FURNITURE','TOOL','ID_CARD','ACCESS_CARD','OTHER'];
    private const VALID_CONDITIONS = ['NEW','GOOD','FAIR','POOR','DAMAGED'];
    private const VALID_STATUSES   = ['AVAILABLE','ASSIGNED','REPAIR','RETIRED','LOST'];

    // ────────────────────────────────────────────────────────────────
    // Asset master CRUD
    // ────────────────────────────────────────────────────────────────

    
    private function getMyEmployeeId(): ?int
    {
        $eid = (int) $this->tenant()->getEmployeeId();
        if ($eid > 0) return $eid;
        $userId = (int) $this->tenant()->getUserId();
        if (! $userId) return null;
        // Bug fix: ApiController tidak punya method `db()`. Pakai pola
        // yang sama dengan method-method lain di file ini, langsung
        // resolve via Config\Database::connect().
        $emp = \Config\Database::connect()->table('employees')
            ->where('user_id', $userId)
            ->where('deleted_at IS NULL')->limit(1)->get()->getRowArray();
        return $emp ? (int) $emp['id'] : null;
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $status = strtoupper((string) ($this->request->getGet('status') ?? ''));
        $category = strtoupper((string) ($this->request->getGet('category') ?? ''));
        $requestedEmpId = $this->getMyEmployeeId();

        $builder = $db->table('assets a')
            ->select('a.*, b.name AS branch_name, e.full_name AS employee_name, e.employee_number AS employee_no')
            ->join('branches b', 'b.id = a.branch_id', 'left')
            ->join('employees e', 'e.id = a.current_employee_id', 'left')
            ->where('a.deleted_at IS NULL')
            ->where('e.id', $requestedEmpId);

        // Privacy scope (lihat docs/DATA_PRIVACY.md): asset master pakai
        // `current_employee_id` sebagai pemegang aset. User tanpa
        // `asset.view`/`asset.manage` (HR) hanya boleh lihat aset miliknya.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'asset',
            companyColumn: 'a.company_id',
            employeeColumn: 'a.current_employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($q !== '') {
            $builder->groupStart()
                ->like('a.asset_number', $q)
                ->orLike('a.name', $q)
                ->orLike('a.brand', $q)
                ->orLike('a.serial_number', $q)
                ->groupEnd();
        }
        if ($status !== '' && in_array($status, self::VALID_STATUSES, true)) $builder->where('a.status', $status);
        if ($category !== '' && in_array($category, self::VALID_CATEGORIES, true)) $builder->where('a.category', $category);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('a.id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadAssetOrFail((int) $id);
        if (! $row) return $this->response;
        // Sertakan history assignment
        $db = \Config\Database::connect();
        $history = $db->table('asset_assignments aa')
            ->select('aa.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = aa.employee_id')
            ->where('aa.asset_id', (int) $id)
            ->orderBy('aa.assigned_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();
        $row['history'] = $history;
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name'     => 'required|max_length[200]',
            'category' => 'required|in_list[' . implode(',', self::VALID_CATEGORIES) . ']',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $assetNumber = ! empty($data['asset_number'])
            ? strtoupper(preg_replace('/\s+/', '-', (string) $data['asset_number']))
            : Services::asset()->nextAssetNumber($companyId);

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'asset_number'    => $assetNumber,
            'category'        => $data['category'],
            'name'            => $data['name'],
            'brand'           => $data['brand'] ?? null,
            'model'           => $data['model'] ?? null,
            'serial_number'   => $data['serial_number'] ?? null,
            'description'     => $data['description'] ?? null,
            'purchase_date'   => $data['purchase_date'] ?? null,
            'purchase_price'  => (float) ($data['purchase_price'] ?? 0),
            'purchase_invoice'=> $data['purchase_invoice'] ?? null,
            'condition'       => in_array(strtoupper((string) ($data['condition'] ?? 'NEW')), self::VALID_CONDITIONS, true)
                ? strtoupper((string) ($data['condition'] ?? 'NEW')) : 'NEW',
            'status'          => 'AVAILABLE',
            'branch_id'       => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
            'notes'           => $data['notes'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db = \Config\Database::connect();
        $db->table('assets')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('ASSET')->logCreate('asset', $id, $insert,
            "Asset baru: {$assetNumber} ({$insert['name']})");
        return $this->ok(['id' => $id, 'asset_number' => $assetNumber], 'Asset created', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadAssetOrFail((int) $id);
        if (! $row) return $this->response;
        if (! empty($row['approval_action'])) {
            return $this->fail('Asset sedang menunggu approval dan tidak dapat diedit', 409);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'name', 'category', 'brand', 'model', 'serial_number', 'description',
            'purchase_date', 'purchase_price', 'purchase_invoice', 'condition',
            'branch_id', 'notes', 'attachment_path',
        ]));
        if (isset($update['category']) && ! in_array($update['category'], self::VALID_CATEGORIES, true)) {
            return $this->fail('Kategori tidak valid', 422);
        }
        if (isset($update['condition']) && ! in_array(strtoupper((string) $update['condition']), self::VALID_CONDITIONS, true)) {
            return $this->fail('Condition tidak valid', 422);
        }
        // Status hanya boleh diubah lewat aksi khusus (assign/return) atau retire
        if (isset($data['status']) && in_array($data['status'], ['RETIRED', 'AVAILABLE'], true)
            && in_array($row['status'], ['AVAILABLE','RETIRED'], true)) {
            $update['status'] = $data['status'];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('assets')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('ASSET')->logUpdate('asset', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadAssetOrFail((int) $id);
        if (! $row) return $this->response;
        if (! empty($row['approval_action'])) {
            return $this->fail('Asset sedang menunggu approval dan tidak dapat dihapus', 409);
        }
        if (! in_array($row['status'], ['AVAILABLE','RETIRED'], true)) {
            return $this->fail('Asset masih ASSIGNED/REPAIR/LOST. Return atau retire dulu sebelum delete.', 422);
        }
        \Config\Database::connect()->table('assets')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('ASSET')->logDelete('asset', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Assign / Return
    // ────────────────────────────────────────────────────────────────

    public function assign($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadAssetOrFail((int) $id);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id' => 'required|integer',
        ]);
        if (! $valid) return null;

        try {
            $result = (new \App\Libraries\Asset\AssetApprovalWorkflowService())->submitAssign(
                (int) $id, (int) $row['company_id'], $this->userId(), (int) $data['employee_id'], [
                'assigned_at'      => $data['assigned_at'] ?? null,
                'expected_return'  => $data['expected_return'] ?? null,
                'purpose'          => $data['purpose'] ?? null,
                'condition_when_assigned' => strtoupper((string) ($data['condition_when_assigned'] ?? $row['condition'])),
            ]);
        } catch (\App\Libraries\Asset\AssetApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }

        Services::audit()->forModule('ASSET')->log(
            action: 'asset.assign.submit',
            entity: 'asset', entityId: (int) $id,
            after: ['status' => 'PENDING_APPROVAL', 'employee_id' => (int) $data['employee_id']],
            description: "Ajukan assignment asset {$row['asset_number']} ke employee #{$data['employee_id']}",
            severity: 'NOTICE'
        );
        return $this->ok($result, 'Assignment asset diajukan untuk approval', 201);
    }

    public function returnAsset($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadAssetOrFail((int) $id);
        if (! $row) return $this->response;

        if (empty($row['current_assignment_id'])) {
            return $this->fail('Asset ini tidak sedang di-assign ke siapa pun', 422);
        }

        $data = $this->getJsonInput();
        try {
            $result = (new \App\Libraries\Asset\AssetApprovalWorkflowService())->submitReturn(
                (int) $id, (int) $row['company_id'], $this->userId(), [
                'returned_at'             => $data['returned_at'] ?? null,
                'condition_when_returned' => $data['condition_when_returned'] ?? 'GOOD',
                'damage_note'             => $data['damage_note'] ?? null,
            ]);
        } catch (\App\Libraries\Asset\AssetApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }

        Services::audit()->forModule('ASSET')->log(
            action: 'asset.return.submit',
            entity: 'asset', entityId: (int) $id,
            after: ['condition' => $data['condition_when_returned'] ?? 'GOOD'],
            description: "Ajukan return asset {$row['asset_number']}",
            severity: 'NOTICE'
        );
        return $this->ok($result, 'Return asset diajukan untuk approval');
    }

    /**
     * GET /asset-assignments/(:num)/bast?format=pdf|html&mode=ASSIGN|RETURN
     */
    public function bast($assignmentId = null)
    {
        $assignmentId = (int) $assignmentId;
        $db = \Config\Database::connect();
        $assignment = $db->table('asset_assignments aa')
            ->select('aa.*, a.asset_number, a.name AS asset_name, a.category, a.brand, a.model, a.serial_number, a.condition AS asset_condition,'
                . ' e.full_name AS employee_name, e.employee_number, e.position_id, e.department_id, e.user_id AS employee_user_id')
            ->join('assets a', 'a.id = aa.asset_id')
            ->join('employees e', 'e.id = aa.employee_id')
            ->where('aa.id', $assignmentId)
            ->get()->getRowArray();
        if (! $assignment) return $this->fail('Assignment not found', 404);

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $assignment['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $mode = strtoupper((string) ($this->request->getGet('mode') ?? 'ASSIGN'));
        if (! in_array($mode, ['ASSIGN', 'RETURN'], true)) $mode = 'ASSIGN';

        $html = $mode === 'RETURN'
            ? ($assignment['bast_return_html'] ?? '')
            : ($assignment['bast_html'] ?? '');

        if ($html === '') {
            // Render on-the-fly kalau snapshot belum ada
            $asset = [
                'asset_number' => $assignment['asset_number'],
                'name'         => $assignment['asset_name'],
                'category'     => $assignment['category'],
                'brand'        => $assignment['brand'],
                'model'        => $assignment['model'],
                'serial_number'=> $assignment['serial_number'],
            ];
            $emp = [
                'full_name'       => $assignment['employee_name'],
                'employee_number' => $assignment['employee_number'],
                'position_id'     => $assignment['position_id'],
                'department_id'   => $assignment['department_id'],
            ];
            $html = Services::asset()->renderBastHtml($assignment, $asset, $emp, $mode);
        }

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        if ($format === 'html') {
            return $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($html);
        }
        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        $filename = sprintf('BAST_%s_%s_%s.pdf', $mode,
            preg_replace('/[^A-Za-z0-9]+/', '_', (string) $assignment['asset_number']),
            preg_replace('/[^A-Za-z0-9]+/', '_', (string) $assignment['employee_number']));
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($output);
    }

    // ────────────────────────────────────────────────────────────────
    // Asset assignment query endpoints
    // ────────────────────────────────────────────────────────────────

    public function listAssignments()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $assetId = (int) ($this->request->getGet('asset_id') ?? 0);
        $returnedOnly = (int) ($this->request->getGet('returned') ?? 0) === 1;
        $activeOnly = (int) ($this->request->getGet('active') ?? 0) === 1;

        $builder = $db->table('asset_assignments aa')
            ->select('aa.*, a.asset_number, a.name AS asset_name, a.category,'
                . ' e.full_name AS employee_name, e.employee_number')
            ->join('assets a', 'a.id = aa.asset_id')
            ->join('employees e', 'e.id = aa.employee_id');

        // Privacy scope: tanpa permission HR, employee biasa hanya boleh
        // lihat history assignment miliknya.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'asset',
            companyColumn: 'aa.company_id',
            employeeColumn: 'aa.employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($assetId) $builder->where('aa.asset_id', $assetId);
        if ($returnedOnly) $builder->where('aa.returned_at IS NOT NULL');
        if ($activeOnly) $builder->where('aa.returned_at IS NULL');

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('aa.assigned_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /asset-assignments/employee/(:num)?include_returned=1
     */
    public function forEmployee($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $tenant = $this->tenant();
        $emp = \Config\Database::connect()->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        // Pegawai biasa hanya boleh lihat aset miliknya sendiri
        $canViewAll = $tenant->isSuperAdmin() || $tenant->hasPermission('asset.manage');
        if (! $canViewAll && $tenant->getEmployeeId() !== $employeeId) {
            return $this->fail('Forbidden', 403);
        }

        $includeReturned = (int) ($this->request->getGet('include_returned') ?? 0) === 1;
        return $this->ok(Services::asset()->listForEmployee($employeeId, $includeReturned));
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('asset.manage');
    }

    private function loadAssetOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('assets')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Asset not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }
}
