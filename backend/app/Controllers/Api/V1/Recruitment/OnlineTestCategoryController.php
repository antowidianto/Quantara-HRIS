<?php

namespace App\Controllers\Api\V1\Recruitment;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * OnlineTestCategoryController — master jenis online test.
 *
 * Built-in categories (`is_built_in=1`, `company_id IS NULL`) di-seed di
 * migration #065 dan tidak boleh dihapus / diubah codenya. HR boleh tambah
 * category custom per company (mis. "Tes Mengetik", "Tes Driving Skill").
 *
 * Lihat docs/RECRUITMENT_PIPELINE.md.
 */
class OnlineTestCategoryController extends ApiController
{
    /**
     * GET /online-test-categories
     *
     * List built-in + company-scoped, paginated, dengan filter group_label.
     */
    public function index()
    {
        $db = \Config\Database::connect();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $search  = trim((string) $this->request->getGet('search'));
        $group   = trim((string) $this->request->getGet('group_label'));
        $scope   = strtolower(trim((string) ($this->request->getGet('scope') ?? 'all')));

        $companyId = $this->companyId();
        $isSuper   = $this->tenant()->isSuperAdmin();

        $builder = $db->table('online_test_categories')
            ->where('deleted_at IS NULL');

        // Default scope: tampilkan built-in + custom milik tenant aktif.
        // scope=builtin → hanya built-in. scope=custom → hanya company-scoped.
        if ($scope === 'builtin') {
            $builder->where('company_id IS NULL');
        } elseif ($scope === 'custom') {
            $builder->where('company_id', $companyId);
        } else {
            $builder->groupStart()->where('company_id IS NULL');
            if (! $isSuper) {
                $builder->orWhere('company_id', $companyId);
            } else {
                // Super admin lihat semua kalau tidak filter scope
                $builder->orWhere('company_id IS NOT NULL');
            }
            $builder->groupEnd();
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('code', $search)
                ->orLike('description', $search)
                ->groupEnd();
        }
        if ($group !== '') $builder->where('group_label', strtoupper($group));

        $total = $builder->countAllResults(false);
        $rows = $builder
            ->orderBy('is_built_in', 'DESC')
            ->orderBy('group_label', 'ASC')
            ->orderBy('name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Annotate is_editable untuk frontend
        foreach ($rows as &$r) {
            $r['is_editable'] = ((int) $r['is_built_in']) === 0
                || ($isSuper && (int) ($r['company_id'] ?? 0) === 0);
        }

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /online-test-categories/picker
     *
     * Endpoint ringkas untuk dropdown (tidak paginated, hanya aktif), dengan
     * grouping by `group_label` untuk UI.
     */
    public function picker()
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();

        $rows = $db->table('online_test_categories')
            ->where('deleted_at IS NULL')
            ->where('is_active', 1)
            ->groupStart()
                ->where('company_id IS NULL')
                ->orWhere('company_id', $companyId)
            ->groupEnd()
            ->orderBy('group_label', 'ASC')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $r) {
            $g = $r['group_label'] ?: 'LAINNYA';
            $grouped[$g][] = [
                'id'           => (int) $r['id'],
                'code'         => $r['code'],
                'name'         => $r['name'],
                'scoring_type' => $r['scoring_type'],
                'is_built_in'  => (int) $r['is_built_in'] === 1,
            ];
        }

        return $this->ok([
            'items'   => $rows,
            'grouped' => $grouped,
            'total'   => count($rows),
        ]);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('online_test_categories')
            ->where('id', (int) $id)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if ($row['company_id'] !== null
            && ! $this->tenant()->isSuperAdmin()
            && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code'         => 'required|alpha_dash|max_length[32]',
            'name'         => 'required|max_length[200]',
            'group_label'  => 'permit_empty|max_length[50]',
            'scoring_type' => 'permit_empty|in_list[SCORE,NO_RIGHT_ANSWER,PROFILE]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $code = strtoupper((string) $data['code']);

        // Code harus unik di scope (company + global built-in)
        $dup = $db->table('online_test_categories')
            ->where('deleted_at IS NULL')
            ->where('code', $code)
            ->groupStart()
                ->where('company_id IS NULL')
                ->orWhere('company_id', $companyId)
            ->groupEnd()
            ->countAllResults();
        if ($dup > 0) {
            return $this->fail("Code '{$code}' sudah dipakai", 422, 'DUPLICATE_CODE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'   => $companyId,
            'code'         => $code,
            'name'         => trim((string) $data['name']),
            'description'  => $data['description'] ?? null,
            'group_label'  => isset($data['group_label']) && $data['group_label'] !== ''
                ? strtoupper((string) $data['group_label']) : null,
            'scoring_type' => $data['scoring_type'] ?? 'SCORE',
            'icon'         => $data['icon'] ?? null,
            'is_built_in'  => 0,
            'is_active'    => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        $db->table('online_test_categories')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()->forModule('MASTER')->logCreate(
            'online_test_category',
            $id,
            $insert,
            "Tambah kategori test: {$insert['name']} ({$code})"
        );

        return $this->ok($db->table('online_test_categories')->where('id', $id)->get()->getRowArray(),
            'Created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('online_test_categories')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);

        // Built-in cannot edit kecuali super admin (untuk benerin typo dll)
        if ((int) $existing['is_built_in'] === 1 && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Kategori built-in hanya boleh diedit super admin', 403);
        }
        if ($existing['company_id'] !== null
            && ! $this->tenant()->isSuperAdmin()
            && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'name'         => 'permit_empty|max_length[200]',
            'group_label'  => 'permit_empty|max_length[50]',
            'scoring_type' => 'permit_empty|in_list[SCORE,NO_RIGHT_ANSWER,PROFILE]',
        ]);
        if (! $valid) return null;

        $update = array_intersect_key($data, array_flip([
            'name', 'description', 'icon', 'is_active', 'scoring_type',
        ]));
        if (isset($data['group_label'])) {
            $update['group_label'] = $data['group_label'] !== ''
                ? strtoupper((string) $data['group_label']) : null;
        }
        // Code immutable untuk built-in supaya FK tidak rusak; non-built-in
        // boleh ganti tapi cek dup.
        if ((int) $existing['is_built_in'] === 0 && isset($data['code'])) {
            $newCode = strtoupper((string) $data['code']);
            if ($newCode !== $existing['code']) {
                $companyId = (int) $existing['company_id'];
                $dup = $db->table('online_test_categories')
                    ->where('deleted_at IS NULL')
                    ->where('code', $newCode)
                    ->where('id !=', $id)
                    ->groupStart()
                        ->where('company_id IS NULL')
                        ->orWhere('company_id', $companyId)
                    ->groupEnd()
                    ->countAllResults();
                if ($dup > 0) return $this->fail("Code '{$newCode}' sudah dipakai", 422);
                $update['code'] = $newCode;
            }
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $db->table('online_test_categories')->where('id', $id)->update($update);
        Services::audit()->forModule('MASTER')->logUpdate(
            'online_test_category',
            $id,
            array_intersect_key($existing, $update),
            $update,
            "Update kategori test: {$existing['name']}"
        );

        return $this->ok($db->table('online_test_categories')->where('id', $id)->get()->getRowArray(), 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('online_test_categories')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);

        if ((int) $existing['is_built_in'] === 1) {
            return $this->fail('Kategori built-in tidak boleh dihapus', 422,
                'CANNOT_DELETE_BUILTIN');
        }
        if ($existing['company_id'] !== null
            && ! $this->tenant()->isSuperAdmin()
            && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        // Cek penggunaan sebelum hapus
        $usedByTests = $db->table('online_tests')->where('category_id', $id)->countAllResults();
        $usedByBank  = $db->table('online_test_question_bank')->where('category_id', $id)->countAllResults();
        $usedByStage = $db->table('recruitment_pipeline_stages')
            ->where('required_test_category_id', $id)->countAllResults();
        if ($usedByTests + $usedByBank + $usedByStage > 0) {
            return $this->fail(
                "Kategori masih dipakai: {$usedByTests} test, {$usedByBank} soal, {$usedByStage} stage. "
                . "Pindahkan dulu sebelum hapus.",
                409, 'CATEGORY_IN_USE'
            );
        }

        $db->table('online_test_categories')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()->forModule('MASTER')->logDelete(
            'online_test_category',
            $id,
            $existing,
            "Hapus kategori test: {$existing['name']}"
        );
        return $this->ok(null, 'Deleted');
    }
}