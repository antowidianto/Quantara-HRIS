<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * ContractTemplateController — CRUD template kontrak HTML + endpoint
 * untuk generate kontrak dari template ke karyawan.
 *
 * Generator kontrak menyimpan snapshot HTML akhir di `generated_contracts`
 * sehingga perubahan template selanjutnya tidak mempengaruhi dokumen yang
 * sudah dirilis.
 */
class ContractTemplateController extends ApiController
{
    private array $fillable = [
        'code', 'name', 'contract_type', 'language',
        'header_html', 'body_html', 'footer_html', 'description',
        'include_regulations', 'is_default', 'is_active',
    ];

    public function index()
    {
        $db = \Config\Database::connect();
        $type = trim((string) $this->request->getGet('contract_type'));
        $builder = $db->table('contract_templates')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($builder);
        if ($type !== '') $builder->where('contract_type', $type);
        $rows = $builder->orderBy('contract_type', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
        // Buang body untuk listing — terlalu besar.
        foreach ($rows as &$r) {
            $r['has_body'] = ! empty($r['body_html']);
            unset($r['body_html'], $r['header_html'], $r['footer_html']);
        }
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('contract_templates')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code'          => 'required|max_length[64]',
            'name'          => 'required|max_length[200]',
            'contract_type' => 'required|in_list[PKWT,PKWTT,PROBATION,PKHL,MAGANG,OUTSOURCING,GENERIC]',
            'body_html'     => 'required',
        ]);
        if (! $valid) return null;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $exists = $db->table('contract_templates')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) return $this->fail('Kode template sudah dipakai', 422, 'DUPLICATE_CODE');

        $now = date('Y-m-d H:i:s');
        $insert = array_intersect_key($data, array_flip($this->fillable));
        $insert['company_id'] = $companyId;
        $insert['created_by'] = $this->userId();
        $insert['language']   = $insert['language'] ?? 'id';
        $insert['include_regulations'] = $insert['include_regulations'] ?? 1;
        $insert['is_default'] = $insert['is_default'] ?? 0;
        $insert['is_active']  = $insert['is_active'] ?? 1;
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;

        if ((int) $insert['is_default'] === 1) {
            // Hanya satu default per contract_type per company.
            $db->table('contract_templates')
                ->where('company_id', $companyId)
                ->where('contract_type', $insert['contract_type'])
                ->update(['is_default' => 0, 'updated_at' => $now]);
        }
        $db->table('contract_templates')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('SETTING')
            ->logCreate('contract_template', $id, array_diff_key($insert, array_flip(['header_html','body_html','footer_html'])),
                "Membuat template kontrak: {$insert['name']} ({$insert['contract_type']})");
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('contract_templates')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip($this->fillable));
        $update['updated_at'] = date('Y-m-d H:i:s');
        if (isset($update['is_default']) && (int) $update['is_default'] === 1) {
            $type = $update['contract_type'] ?? $existing['contract_type'];
            $db->table('contract_templates')
                ->where('company_id', (int) $existing['company_id'])
                ->where('contract_type', $type)
                ->where('id !=', $id)
                ->update(['is_default' => 0, 'updated_at' => $update['updated_at']]);
        }
        $db->table('contract_templates')->where('id', $id)->update($update);
        Services::audit()
            ->forModule('SETTING')
            ->logUpdate('contract_template', $id, [], array_diff_key($update, array_flip(['header_html','body_html','footer_html'])),
                "Mengubah template kontrak: {$existing['name']}");
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('contract_templates')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('contract_templates')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()
            ->forModule('SETTING')
            ->logDelete('contract_template', $id, $existing, "Menghapus template kontrak: {$existing['name']}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Variabel & filter yang tersedia. Dipakai UI editor untuk side-panel
     * "Klik untuk insert variabel".
     */
    public function variables()
    {
        return $this->ok([
            'variables' => \App\Libraries\Lifecycle\ContractRenderer::availableVariables(),
            'filters'   => \App\Libraries\Lifecycle\ContractRenderer::availableFilters(),
        ]);
    }

    /**
     * Preview template dengan data karyawan tertentu (tanpa menyimpan).
     *
     *   POST /api/v1/contract-templates/{id}/preview
     *   { "employee_id": 5, "employee_contract_id": 12 }
     */
    public function preview($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $tpl = $db->table('contract_templates')->where('id', $id)->get()->getRowArray();
        if (! $tpl) return $this->fail('Template not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $tpl['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        $empId = (int) ($data['employee_id'] ?? 0);
        if (! $empId) return $this->fail('employee_id wajib', 422);

        $renderer = Services::contractRenderer();
        $context  = $renderer->buildContext(
            $empId,
            isset($data['employee_contract_id']) ? (int) $data['employee_contract_id'] : null,
            $data['extras'] ?? []
        );
        $html = $renderer->render($tpl, $context);
        return $this->ok([
            'html'    => $html,
            'context' => $context,
        ]);
    }

    /**
     * Generate kontrak final → simpan snapshot di generated_contracts dan
     * kembalikan ID + URL pratinjau.
     *
     *   POST /api/v1/contract-templates/{id}/generate
     *   { "employee_id": 5, "employee_contract_id": 12, "subject": "Kontrak PKWT" }
     */
    public function generate($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $tpl = $db->table('contract_templates')->where('id', $id)->get()->getRowArray();
        if (! $tpl) return $this->fail('Template not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $tpl['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        $empId    = (int) ($data['employee_id'] ?? 0);
        $contractId = isset($data['employee_contract_id']) ? (int) $data['employee_contract_id'] : null;
        if (! $empId) return $this->fail('employee_id wajib', 422);

        $emp = $db->table('employees')->where('id', $empId)->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);

        $renderer = Services::contractRenderer();
        $context  = $renderer->buildContext($empId, $contractId, $data['extras'] ?? []);
        $html     = $renderer->render($tpl, $context);

        $now = date('Y-m-d H:i:s');
        $contractNumber = (string) ($data['contract_number']
            ?? ('GC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)))));

        $insert = [
            'company_id'           => (int) $tpl['company_id'],
            'employee_id'          => $empId,
            'employee_contract_id' => $contractId,
            'template_id'          => $id,
            'contract_number'      => $contractNumber,
            'subject'              => $data['subject'] ?? ($tpl['name'] . ' - ' . $emp['full_name']),
            'rendered_html'        => $html,
            'variables_snapshot'   => json_encode($context, JSON_UNESCAPED_UNICODE),
            'status'               => 'DRAFT',
            'created_by'           => $this->userId(),
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        $db->table('generated_contracts')->insert($insert);
        $genId = (int) $db->insertID();

        Services::audit()
            ->forModule('EMPLOYEE')
            ->logCreate('generated_contract', $genId, array_diff_key($insert, array_flip(['rendered_html','variables_snapshot'])),
                "Generate kontrak {$contractNumber} untuk {$emp['full_name']} (template: {$tpl['name']})");

        return $this->ok([
            'id'              => $genId,
            'contract_number' => $contractNumber,
            'preview_url'     => "/api/v1/generated-contracts/{$genId}/html",
        ], 'Kontrak digenerate', 201);
    }
}