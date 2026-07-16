<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * GeneratedContractController — kontrak yang sudah digenerate dari template.
 * Snapshot HTML disimpan di kolom `rendered_html` agar tidak terpengaruh
 * perubahan template selanjutnya.
 */
class GeneratedContractController extends ApiController
{
    public function index()
    {
        $db   = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));

        $builder = $db->table('generated_contracts gc')
            ->select('gc.id, gc.contract_number, gc.subject, gc.status, gc.created_at, gc.signed_at, '
                  . 'gc.employee_id, gc.template_id, gc.employee_contract_id, '
                  . 'e.full_name AS employee_name, e.employee_number, '
                  . 't.name AS template_name, t.contract_type AS template_type')
            ->join('employees e', 'e.id = gc.employee_id', 'left')
            ->join('contract_templates t', 't.id = gc.template_id', 'left')
            ->where('gc.deleted_at IS NULL');

        // Privacy scope (lihat docs/DATA_PRIVACY.md): kontrak = sangat sensitif
        // (memuat gaji, term & condition). Tanpa permission HR
        // (`contract.view`/`manage`/`employee.view`), karyawan hanya boleh
        // lihat kontraknya sendiri.
        $effective = $this->privacy()->applyListScope(
            $builder, module: 'contract',
            companyColumn: 'gc.company_id',
            employeeColumn: 'gc.employee_id',
            requestedEmployeeId: $requestedEmpId ?: null,
        );
        if ($effective === null) return $this->fail('Forbidden', 403);

        if ($status !== '') $builder->where('gc.status', $status);

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('gc.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;
        // Buang HTML yang besar dari payload JSON; gunakan endpoint /html.
        unset($row['rendered_html']);
        $row['variables_snapshot'] = is_string($row['variables_snapshot'])
            ? (json_decode($row['variables_snapshot'], true) ?: null)
            : $row['variables_snapshot'];
        return $this->ok($row);
    }

    public function html($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;
        return $this->response
            ->setContentType('text/html; charset=utf-8')
            ->setBody($this->wrapPrintableHtml($row));
    }

    /** Ajukan pelepasan atau pencabutan kontrak untuk approval. */
    public function updateStatus($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'status' => 'required|in_list[SENT,REVOKED]',
        ]);
        if (! $valid) return null;
        $action = $data['status'] === 'SENT' ? 'SEND' : 'REVOKE';
        try {
            $result = (new \App\Libraries\Lifecycle\GeneratedContractApprovalWorkflowService())
                ->submit((int) $id, (int) $row['company_id'], $this->userId(), $action);
            Services::audit()->forModule('EMPLOYEE')->logUpdate(
                'generated_contract', (int) $id, ['status' => $row['status']],
                ['status' => 'PENDING_APPROVAL', 'approval_action' => $action],
                "Kontrak {$row['contract_number']} diajukan untuk approval {$action}"
            );
            return $this->ok($result, 'Kontrak diajukan untuk approval');
        } catch (\App\Libraries\Lifecycle\GeneratedContractApprovalException $exception) {
            return $this->fail($exception->getMessage(), $exception->httpStatus, $exception->errorCode);
        }
    }

    public function delete($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;
        if (! in_array($row['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            return $this->fail('Hanya kontrak DRAFT atau yang ditolak approval yang dapat dihapus', 422);
        }
        $db = \Config\Database::connect();
        $db->table('generated_contracts')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logDelete('generated_contract', (int) $id, $row, "Menghapus kontrak {$row['contract_number']}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Tanda tangan digital kontrak oleh karyawan.
     * Menerima signature sebagai base64 data-URL PNG atau upload file.
     */
    public function sign($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;

        if ($row['status'] !== 'SENT') {
            return $this->fail('Kontrak sudah ditandatangani atau tidak dalam status SENT', 422);
        }

        $body = $this->getJsonInput();
        $signatureData = $body['signature_data'] ?? null; // base64 data-URL

        if (! $signatureData) {
            return $this->fail('signature_data (base64) wajib diisi', 422);
        }

        // Decode base64
        if (! preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/', $signatureData, $m)) {
            return $this->fail('Format signature tidak valid. Gunakan data:image/png;base64,...', 422);
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $binary = base64_decode(str_replace(' ', '+', $m[2]), true);
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024) {
            return $this->fail('File signature tidak valid atau terlalu besar (maks 2 MB)', 422);
        }

        // Simpan ke FileStorage
        $relativePath = 'contracts/signatures/' . (int) $row['company_id']
            . '/' . $id . '_' . date('YmdHis') . '.' . $ext;
        $storage = Services::fileStorage();
        $storage->put($relativePath, $binary);

        $now = date('Y-m-d H:i:s');
        $db = \Config\Database::connect();
        $db->table('generated_contracts')->where('id', (int) $id)->update([
            'status'     => 'SIGNED',
            'signed_at'  => $now,
            'signed_url' => $relativePath,
            'updated_at' => $now,
        ]);

        // Auto-complete CONTRACT_SIGN onboarding task
        try {
            $onboarding = Services::onboarding();
            $onboarding->completeTaskByKey((int) $row['employee_id'], 'ONBOARD', 'CONTRACT_SIGN');
        } catch (\Throwable $e) {
            log_message('warning', "Auto-complete CONTRACT_SIGN failed: " . $e->getMessage());
        }

        Services::audit()
            ->forModule('EMPLOYEE')
            ->logUpdate('generated_contract', (int) $id, ['status' => 'SENT'], ['status' => 'SIGNED'],
                "Kontrak {$row['contract_number']} ditandatangani digital oleh karyawan");

        return $this->ok([
            'signed_url' => $storage->signedUrl($relativePath, 86400),
            'signed_at'  => $now,
        ], 'Kontrak berhasil ditandatangani');
    }

    /**
     * Daftar kontrak yang menunggu tanda tangan untuk employee yang sedang login.
     */
    public function pendingForEmployee()
    {
        $user = $this->tenant()->getUser();
        $employeeId = (int) ($user['employee_id'] ?? 0);
        if (! $employeeId) {
            return $this->fail('User ini tidak terhubung ke data karyawan', 400);
        }

        $db = \Config\Database::connect();
        $rows = $db->table('generated_contracts')
            ->where('employee_id', $employeeId)
            ->where('status', 'SENT')
            ->where('deleted_at IS NULL')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        foreach ($rows as &$r) {
            unset($r['rendered_html'], $r['variables_snapshot']);
        }
        unset($r);

        return $this->ok($rows);
    }

    /**
     * Helper: cek scope + load row.
     */
    private function loadOwned(int $id)
    {
        $db = \Config\Database::connect();
        $row = $db->table('generated_contracts')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $row;
    }

    private function wrapPrintableHtml(array $row): string
    {
        $title = htmlspecialchars((string) ($row['subject'] ?? $row['contract_number']), ENT_QUOTES, 'UTF-8');
        $css = <<<CSS
        body { font-family: 'Times New Roman', serif; line-height: 1.5; color: #111; max-width: 720px; margin: 24px auto; padding: 0 20px; }
        h1, h2, h3 { font-weight: bold; }
        h1 { font-size: 18pt; text-align: center; }
        h2 { font-size: 14pt; }
        h3 { font-size: 12pt; }
        table { border-collapse: collapse; width: 100%; margin: 12px 0; }
        th, td { border: 1px solid #aaa; padding: 6px 8px; text-align: left; }
        ol, ul { padding-left: 20px; }
        .regulations h3 { margin-top: 20px; }
        @media print { body { margin: 0; } }
        CSS;
        $body = $row['rendered_html'] ?? '';
        return "<!doctype html><html lang=\"id\"><head><meta charset=\"utf-8\"><title>{$title}</title><style>{$css}</style></head><body>{$body}</body></html>";
    }
}
