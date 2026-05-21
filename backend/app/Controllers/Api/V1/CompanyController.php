<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class CompanyController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $tenant = $this->tenant();

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search = trim((string) $this->request->getGet('search'));

        $builder = $db->table('companies')->where('deleted_at IS NULL');

        // Non-super admin sees only their own company
        if (! $tenant->isSuperAdmin()) {
            $builder->where('id', $tenant->getCompanyId());
        }
        if ($search !== '') {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('code', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('companies')->where('id', (int) $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) return $this->fail('Company not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Only super admin can create companies', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|alpha_dash|max_length[32]|is_unique[companies.code]',
            'name' => 'required|max_length[200]',
            'email' => 'permit_empty|valid_email|max_length[191]',
            'npwp' => 'permit_empty|max_length[32]',
        ]);
        if (! $valid) return null;

        $now = date('Y-m-d H:i:s');
        $insertData = array_intersect_key($data, array_flip([
            'code', 'name', 'legal_name', 'npwp', 'phone', 'email', 'address',
            'province', 'city', 'postal_code', 'timezone', 'currency', 'fiscal_year_start',
        ]));
        $insertData['is_active']  = 1;
        $insertData['created_at'] = $now;
        $insertData['updated_at'] = $now;

        $db = \Config\Database::connect();
        $db->table('companies')->insert($insertData);
        $id = (int) $db->insertID();

        // Default BPJS rates
        $db->table('bpjs_rates')->insert([
            'company_id' => $id,
            'kes_employee_rate' => 0.01, 'kes_company_rate' => 0.04, 'kes_max_salary' => 12000000,
            'jht_employee_rate' => 0.02, 'jht_company_rate' => 0.037,
            'jp_employee_rate'  => 0.01, 'jp_company_rate'  => 0.02,  'jp_max_salary'  => 10547400,
            'jkk_company_rate'  => 0.0024, 'jkm_company_rate' => 0.003,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Services::audit()->log('company.create', 'company', $id, null, $insertData);
        return $this->ok($db->table('companies')->where('id', $id)->get()->getRowArray(), 'Company created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('companies')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Company not found', 404);

        if (! $this->tenant()->isSuperAdmin() && $id !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name' => 'permit_empty|max_length[200]',
            'email' => 'permit_empty|valid_email|max_length[191]',
        ]);
        if (! $valid) return null;

        $update = array_intersect_key($data, array_flip([
            'name', 'legal_name', 'npwp', 'phone', 'email', 'address',
            'province', 'city', 'postal_code', 'timezone', 'currency',
            'fiscal_year_start', 'is_active', 'logo_path', 'settings',
        ]));
        if (isset($update['settings']) && is_array($update['settings'])) {
            $update['settings'] = json_encode($update['settings'], JSON_UNESCAPED_UNICODE);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $db->table('companies')->where('id', $id)->update($update);
        Services::audit()->log('company.update', 'company', $id, $existing, $update);

        return $this->ok($db->table('companies')->where('id', $id)->get()->getRowArray(), 'Company updated');
    }

    public function delete($id = null)
    {
        if (! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Forbidden', 403);
        }
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('companies')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Company not found', 404);

        $db->table('companies')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()->log('company.delete', 'company', $id, $existing, null);
        return $this->ok(null, 'Company deleted');
    }
}