<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * DisciplinaryController
 *
 * REST API untuk Surat Peringatan (SP) & catatan disipliner.
 *
 * Authorization:
 *   - `disciplinary.view`   → list/show semua SP (HR/admin)
 *   - `disciplinary.manage` → buat / edit / revoke SP
 *   - Pegawai biasa: hanya bisa lihat SP miliknya sendiri (lewat `mine=1`)
 *
 * Workflow:
 *   1. HR create SP dgn status DRAFT (atau langsung ACTIVE kalau confirmed)
 *   2. HR generate surat (HTML/PDF) untuk dicetak/diserahkan ke karyawan
 *   3. Karyawan acknowledge → set acknowledged_at + acknowledgment_note
 *   4. Setelah `expires_at` lewat → command harian set status EXPIRED
 *   5. HR boleh revoke kapan saja (status REVOKED + revocation_reason)
 */
class DisciplinaryController extends ApiController
{
    private const VALID_LEVELS = ['VERBAL', 'SP1', 'SP2', 'SP3', 'SUSPEND', 'TERMINATION'];
    private const VALID_STATUSES = ['DRAFT', 'ACTIVE', 'EXPIRED', 'REVOKED'];

    // ────────────────────────────────────────────────────────────────
    // List & detail
    // ────────────────────────────────────────────────────────────────

    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $level      = (string) ($this->request->getGet('level') ?? '');
        $status     = (string) ($this->request->getGet('status') ?? '');
        $mine       = (int) ($this->request->getGet('mine') ?? 0) === 1;
        $q          = trim((string) ($this->request->getGet('q') ?? ''));

        $builder = $db->table('disciplinary_records dr')
            ->select(
                'dr.*, e.full_name AS employee_name, e.employee_number, '
                . 'd.name AS department_name, '
                . 'cat.name AS category_name, '
                . 'iu.name AS issued_by_name'
            )
            ->join('employees e', 'e.id = dr.employee_id')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('disciplinary_categories cat', 'cat.id = dr.category_id', 'left')
            ->join('users iu', 'iu.id = dr.issued_by', 'left')
            ->where('dr.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): SP karyawan adalah
        // sangat sensitif — pegawai biasa hanya boleh lihat miliknya.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'disciplinary',
            companyColumn: 'dr.company_id',
            employeeColumn: 'dr.employee_id',
            requestedEmployeeId: $mine
                ? $tenant->getEmployeeId()
                : ($requestedEmpId ?: null),
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($level !== '' && in_array($level, self::VALID_LEVELS, true)) $builder->where('dr.level', $level);
        if ($status !== '' && in_array($status, self::VALID_STATUSES, true)) $builder->where('dr.status', $status);
        if ($q !== '') {
            $builder->groupStart()
                ->like('dr.subject', $q)
                ->orLike('dr.reference_number', $q)
                ->orLike('e.full_name', $q)
                ->orLike('e.employee_number', $q)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('dr.effective_date', 'DESC')
            ->orderBy('dr.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        return $this->ok($row);
    }

    /**
     * GET /api/v1/disciplinary-records/employee-summary/(:num)
     * Ringkasan SP per karyawan (untuk panel di profil karyawan).
     */
    public function employeeSummary($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        // Authorization: HR view OR diri sendiri
        $tenant = $this->tenant();
        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('disciplinary.view')
            || $tenant->hasPermission('disciplinary.manage');
        if (! $canViewAll && $tenant->getEmployeeId() !== $employeeId) {
            return $this->fail('Forbidden', 403);
        }
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $summary = Services::disciplinary()->summary($employeeId);
        return $this->ok($summary);
    }

    // ────────────────────────────────────────────────────────────────
    // Create / Update / Revoke
    // ────────────────────────────────────────────────────────────────

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'employee_id'    => 'required|integer',
            'level'          => 'required|in_list[' . implode(',', self::VALID_LEVELS) . ']',
            'subject'        => 'required|max_length[255]',
            'description'    => 'required',
            'incident_date'  => 'required|valid_date',
            'effective_date' => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $emp = $db->table('employees')->where('id', (int) $data['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if ((int) $emp['company_id'] !== $companyId && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Karyawan dari perusahaan lain', 422);
        }

        // Validasi category_id (kalau dikirim)
        if (! empty($data['category_id'])) {
            $cat = $db->table('disciplinary_categories')
                ->where('id', (int) $data['category_id'])
                ->where('company_id', $companyId)
                ->get()->getRowArray();
            if (! $cat) return $this->fail('Kategori tidak valid untuk perusahaan ini', 422);
        }

        $service = Services::disciplinary();

        $level = $data['level'];
        $effectiveDate = $data['effective_date'];
        $expiresAt = $data['expires_at'] ?? $service->defaultExpiresAt($level, $effectiveDate);
        $referenceNumber = $data['reference_number'] ?? $service->nextReferenceNumber($companyId, $level);
        $statusInitial = ($data['status'] ?? 'ACTIVE');
        if (! in_array($statusInitial, ['DRAFT', 'ACTIVE'], true)) $statusInitial = 'ACTIVE';

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'       => $companyId,
            'employee_id'      => (int) $data['employee_id'],
            'category_id'      => ! empty($data['category_id']) ? (int) $data['category_id'] : null,
            'level'            => $level,
            'reference_number' => $referenceNumber,
            'subject'          => $data['subject'],
            'description'      => $data['description'],
            'incident_date'    => $data['incident_date'],
            'effective_date'   => $effectiveDate,
            'expires_at'       => $expiresAt,
            'status'           => $statusInitial,
            'template_id'      => ! empty($data['template_id']) ? (int) $data['template_id'] : null,
            'issued_by'        => $this->userId(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        $db->table('disciplinary_records')->insert($insert);
        $id = (int) $db->insertID();

        // Render snapshot HTML supaya kalau template berubah surat asli tetap stabil
        $insertWithCat = $insert;
        $insertWithCat['category_name'] = $cat['name'] ?? null;
        $html = $service->renderLetter(array_merge($insertWithCat, ['id' => $id]), $insert['template_id']);
        $db->table('disciplinary_records')->where('id', $id)->update([
            'letter_html' => $html,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Notifikasi karyawan + audit log
        $this->notifyEmployee($emp, $insert);
        Services::audit()->forModule('HR')->logCreate(
            'disciplinary_record', $id, $insert,
            "Menerbitkan {$level} {$referenceNumber} untuk {$emp['full_name']}",
            severity: 'WARNING'
        );

        // Kalau levelnya SP3, beri tahu user bahwa pelanggaran selanjutnya
        // mengarah ke PHK
        $row = $db->table('disciplinary_records')->where('id', $id)->get()->getRowArray();
        $extraWarning = null;
        if ($service->shouldSuggestTermination((int) $insert['employee_id'])) {
            $extraWarning = 'Karyawan ini sudah memiliki SP3 aktif. Pelanggaran berikutnya '
                . 'memenuhi syarat untuk diproses sebagai PHK sesuai PP 35/2021.';
        }
        return $this->ok([
            'record'  => $row,
            'warning' => $extraWarning,
        ], 'Surat Peringatan dibuat', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);

        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Yang sudah diakui karyawan tidak boleh diubah substansial-nya
        if (! empty($row['acknowledged_at']) && ! in_array($row['status'], ['DRAFT'], true)) {
            return $this->fail('SP sudah diakui karyawan, tidak boleh diubah substansi-nya. Pakai endpoint revoke untuk membatalkan.', 422);
        }
        if (in_array($row['status'], ['REVOKED'], true)) {
            return $this->fail('SP sudah dibatalkan, tidak bisa diedit', 422);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'category_id', 'subject', 'description', 'incident_date',
            'effective_date', 'expires_at', 'level', 'status', 'template_id',
        ]));
        if (isset($update['level']) && ! in_array($update['level'], self::VALID_LEVELS, true)) {
            return $this->fail('Level tidak valid', 422);
        }
        if (isset($update['status']) && ! in_array($update['status'], ['DRAFT', 'ACTIVE'], true)) {
            return $this->fail('Status hanya boleh DRAFT atau ACTIVE lewat update; pakai revoke untuk REVOKED', 422);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table('disciplinary_records')->where('id', (int) $id)->update($update);

        // Re-render letter HTML kalau ada perubahan substantif
        if (count(array_intersect_key($update, array_flip([
            'subject', 'description', 'level', 'effective_date', 'expires_at', 'incident_date', 'template_id',
        ]))) > 0) {
            $fresh = $db->table('disciplinary_records')->where('id', (int) $id)->get()->getRowArray();
            $cat = $fresh['category_id']
                ? $db->table('disciplinary_categories')->where('id', (int) $fresh['category_id'])->get()->getRowArray()
                : null;
            $fresh['category_name'] = $cat['name'] ?? null;
            $html = Services::disciplinary()->renderLetter($fresh, (int) ($fresh['template_id'] ?? 0) ?: null);
            $db->table('disciplinary_records')->where('id', (int) $id)->update([
                'letter_html' => $html,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        Services::audit()->forModule('HR')->logUpdate(
            'disciplinary_record', (int) $id, $row, $update,
            "Update SP #{$row['reference_number']}"
        );
        return $this->ok($db->table('disciplinary_records')->where('id', (int) $id)->get()->getRowArray(), 'Updated');
    }

    /**
     * Revoke SP (membatalkan).
     * POST /api/v1/disciplinary-records/(:num)/revoke
     */
    public function revoke($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        if ($row['status'] === 'REVOKED') return $this->fail('SP sudah dibatalkan', 422);

        $data = $this->getJsonInput();
        if (empty($data['reason']) || strlen(trim($data['reason'])) < 10) {
            return $this->fail('Alasan pembatalan minimal 10 karakter', 422);
        }
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('disciplinary_records')->where('id', (int) $id)->update([
            'status'             => 'REVOKED',
            'revoked_by'         => $this->userId(),
            'revoked_at'         => $now,
            'revocation_reason'  => trim($data['reason']),
            'updated_at'         => $now,
        ]);

        Services::audit()->forModule('HR')->log(
            action: 'disciplinary.revoke',
            entity: 'disciplinary_record',
            entityId: (int) $id,
            after: ['reason' => trim($data['reason'])],
            description: "Membatalkan {$row['level']} {$row['reference_number']}",
            severity: 'WARNING'
        );
        return $this->ok(null, 'SP dibatalkan');
    }

    /**
     * Karyawan acknowledge SP (dengan opsi tanggapan/pembelaan).
     * POST /api/v1/disciplinary-records/(:num)/acknowledge
     */
    public function acknowledge($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Hanya karyawan ybs (atau super admin) yang boleh acknowledge
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['employee_id'] !== $tenant->getEmployeeId()) {
            return $this->fail('Hanya karyawan ybs yang dapat acknowledge', 403);
        }
        if (! empty($row['acknowledged_at'])) return $this->fail('Sudah diakui sebelumnya', 422);
        if ($row['status'] !== 'ACTIVE') return $this->fail('Hanya SP berstatus ACTIVE yg bisa di-acknowledge', 422);

        $data = $this->getJsonInput();
        $note = trim((string) ($data['note'] ?? ''));
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('disciplinary_records')->where('id', (int) $id)->update([
            'acknowledged_at'      => $now,
            'acknowledgment_note'  => $note,
            'updated_at'           => $now,
        ]);

        Services::audit()->forModule('HR')->log(
            action: 'disciplinary.acknowledge',
            entity: 'disciplinary_record',
            entityId: (int) $id,
            after: ['note' => $note],
            description: "Karyawan acknowledge {$row['level']} {$row['reference_number']}",
            severity: 'NOTICE'
        );
        return $this->ok(null, 'SP diakui');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Hanya boleh hard-delete kalau masih DRAFT; lainnya soft-delete
        $db = \Config\Database::connect();
        if ($row['status'] === 'DRAFT') {
            $db->table('disciplinary_records')->where('id', (int) $id)->delete();
        } else {
            $db->table('disciplinary_records')->where('id', (int) $id)->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Services::audit()->forModule('HR')->logDelete(
            'disciplinary_record', (int) $id, $row,
            "Hapus SP #{$row['reference_number']}"
        );
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Output: HTML & PDF
    // ────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/disciplinary-records/(:num)/letter?format=html|pdf
     * Render surat SP. PDF default; HTML untuk preview/iframe.
     */
    public function letter($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        // Kalau snapshot HTML belum ada, render on-the-fly
        $html = $row['letter_html'] ?? '';
        if ($html === '') {
            $html = Services::disciplinary()->renderLetter($row, (int) ($row['template_id'] ?? 0) ?: null);
        }

        $format = strtolower((string) $this->request->getGet('format')) ?: 'pdf';
        if ($format === 'html') {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody($html);
        }

        $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
        $filename = 'SP_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['reference_number']) . '.pdf';
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($output);
    }

    // ────────────────────────────────────────────────────────────────
    // Categories CRUD
    // ────────────────────────────────────────────────────────────────

    public function listCategories()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('disciplinary_categories')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($builder);
        $rows = $builder->orderBy('severity', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createCategory()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code'              => 'required|max_length[32]',
            'name'              => 'required|max_length[200]',
            'severity'          => 'permit_empty|in_list[MINOR,MODERATE,MAJOR,SEVERE]',
            'recommended_level' => 'permit_empty|in_list[VERBAL,SP1,SP2,SP3,SUSPEND,TERMINATION]',
        ]);
        if (! $valid) return null;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('disciplinary_categories')->insert([
            'company_id'        => $companyId,
            'code'              => strtoupper($data['code']),
            'name'              => $data['name'],
            'severity'          => $data['severity'] ?? 'MINOR',
            'recommended_level' => $data['recommended_level'] ?? 'VERBAL',
            'description'       => $data['description'] ?? null,
            'is_active'         => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $id = (int) $db->insertID();
        return $this->ok(['id' => $id], 'Kategori dibuat', 201);
    }

    public function updateCategory($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('disciplinary_categories')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'code', 'name', 'severity', 'recommended_level', 'description', 'is_active',
        ]));
        if (isset($update['code'])) $update['code'] = strtoupper($update['code']);
        if (isset($update['is_active'])) $update['is_active'] = (int) (bool) $update['is_active'];
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('disciplinary_categories')->where('id', $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function deleteCategory($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('disciplinary_categories')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        $db->table('disciplinary_categories')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('disciplinary.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('disciplinary_records dr')
            ->select('dr.*, e.full_name AS employee_name, e.employee_number, e.user_id AS employee_user_id, '
                . 'cat.name AS category_name')
            ->join('employees e', 'e.id = dr.employee_id')
            ->join('disciplinary_categories cat', 'cat.id = dr.category_id', 'left')
            ->where('dr.id', $id)
            ->where('dr.deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        // Pegawai biasa hanya boleh akses SP-nya sendiri
        $canViewAll = $tenant->isSuperAdmin()
            || $tenant->hasPermission('disciplinary.view')
            || $tenant->hasPermission('disciplinary.manage');
        if (! $canViewAll && (int) $row['employee_id'] !== $tenant->getEmployeeId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    private function notifyEmployee(array $emp, array $sp): void
    {
        if (empty($emp['user_id'])) return;
        $titleMap = [
            'VERBAL' => 'Teguran Lisan',
            'SP1' => 'Surat Peringatan 1',
            'SP2' => 'Surat Peringatan 2',
            'SP3' => 'Surat Peringatan 3 (Final)',
            'SUSPEND' => 'Skorsing',
            'TERMINATION' => 'Pemutusan Hubungan Kerja',
        ];
        $label = $titleMap[$sp['level']] ?? $sp['level'];
        Services::notification()->send(
            (int) $emp['user_id'],
            (int) $sp['company_id'],
            'disciplinary.issued',
            "Anda menerima {$label}",
            $sp['subject'] . ' (Berlaku sejak ' . $sp['effective_date'] . ')',
            ['record_level' => $sp['level'], 'reference' => $sp['reference_number']],
            null,
            true
        );
    }
}