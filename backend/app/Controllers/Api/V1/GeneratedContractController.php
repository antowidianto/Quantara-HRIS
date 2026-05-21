<?php

namespace App\Controllers\Api\V1;

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
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $status = trim((string) $this->request->getGet('status'));

        $builder = $db->table('generated_contracts gc')
            ->select('gc.id, gc.contract_number, gc.subject, gc.status, gc.created_at, gc.signed_at, '
                  . 'gc.employee_id, gc.template_id, gc.employee_contract_id, '
                  . 'e.full_name AS employee_name, e.employee_number, '
                  . 't.name AS template_name, t.contract_type AS template_type')
            ->join('employees e', 'e.id = gc.employee_id', 'left')
            ->join('contract_templates t', 't.id = gc.template_id', 'left')
            ->where('gc.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'gc.company_id');
        if ($employeeId) $builder->where('gc.employee_id', $employeeId);
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

    /**
     * Tandai status kontrak (mis. SENT setelah dikirim ke karyawan, atau
     * SIGNED ketika diunggah versi tertanda-tangan).
     */
    public function updateStatus($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'status' => 'required|in_list[DRAFT,SENT,SIGNED,REVOKED]',
        ]);
        if (! $valid) return null;

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'     => $data['status'],
            'updated_at' => $now,
        ];
        if ($data['status'] === 'SENT')   $update['sent_at']    = $now;
        if ($data['status'] === 'SIGNED') $update['signed_at']  = $now;
        if (! empty($data['signed_url'])) $update['signed_url'] = $data['signed_url'];

        $db = \Config\Database::connect();
        $db->table('generated_contracts')->where('id', (int) $id)->update($update);
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logUpdate('generated_contract', (int) $id, ['status' => $row['status']], $update,
                "Status kontrak {$row['contract_number']} diubah ke {$data['status']}");
        return $this->ok(null, 'Status diperbarui');
    }

    public function delete($id = null)
    {
        $row = $this->loadOwned((int) $id);
        if ($row instanceof \CodeIgniter\HTTP\ResponseInterface) return $row;
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