<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * CompanyRegulationController — library klausul peraturan perusahaan.
 * Setiap baris adalah satu pasal/klausul yang bisa di-include ke kontrak
 * via placeholder {{regulations}} atau {{regulations.<CATEGORY>}}.
 */
class CompanyRegulationController extends ApiController
{
    private array $fillable = [
        'category', 'code', 'title', 'order_no', 'body_html',
        'effective_from', 'effective_to', 'is_active',
    ];

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $category = trim((string) $this->request->getGet('category'));
        $search   = trim((string) $this->request->getGet('search'));

        $builder = $db->table('company_regulations')
            ->where('deleted_at IS NULL');
        $this->tenant()->applyScope($builder);
        if ($category !== '') $builder->where('category', $category);
        if ($search !== '') {
            $builder->groupStart()
                ->like('title', $search)
                ->orLike('code', $search)
                ->groupEnd();
        }
        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('category', 'ASC')
            ->orderBy('order_no', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('company_regulations')->where('id', (int) $id)->get()->getRowArray();
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
            'category'  => 'required|in_list[GENERAL,WORKING_HOURS,LEAVE,SALARY,OVERTIME,DISCIPLINE,RESIGNATION,CONFIDENTIALITY,INTELLECTUAL_PROPERTY,BENEFITS,TERMINATION,OTHER]',
            'title'     => 'required|max_length[255]',
            'body_html' => 'required',
        ]);
        if (! $valid) return null;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $now = date('Y-m-d H:i:s');
        $insert = array_intersect_key($data, array_flip($this->fillable));
        $insert['company_id'] = $companyId;
        $insert['created_by'] = $this->userId();
        $insert['is_active']  = $insert['is_active'] ?? 1;
        $insert['order_no']   = $insert['order_no'] ?? 100;
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $db = \Config\Database::connect();
        $db->table('company_regulations')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('SETTING')
            ->logCreate('company_regulation', $id, $insert,
                "Menambah peraturan perusahaan: {$insert['title']} ({$insert['category']})");
        return $this->ok(['id' => $id], 'Created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('company_regulations')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip($this->fillable));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('company_regulations')->where('id', $id)->update($update);
        Services::audit()
            ->forModule('SETTING')
            ->logUpdate('company_regulation', $id, array_intersect_key($existing, $update), $update,
                "Mengubah peraturan: {$existing['title']}");
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('company_regulations')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('company_regulations')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()
            ->forModule('SETTING')
            ->logDelete('company_regulation', $id, $existing, "Menghapus peraturan: {$existing['title']}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Render preview seluruh peraturan aktif sebagai HTML — berguna untuk
     * pratinjau di halaman peraturan & verifikasi sebelum di-include
     * ke kontrak.
     */
    public function preview()
    {
        $companyId = $this->companyId();
        $category  = $this->request->getGet('category');
        $html = Services::contractRenderer()->buildRegulationsHtml(
            $companyId,
            $category ?: null
        );
        return $this->ok(['html' => $html]);
    }
}