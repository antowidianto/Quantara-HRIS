<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * MasterCrudController — generic CRUD base for simple master-data resources.
 * Children only need to set $table, $fillable, $auditEntity, and validation rules.
 */
abstract class MasterCrudController extends ApiController
{
    protected string $table = '';
    protected array $fillable = [];
    protected array $createRules = [];
    protected array $updateRules = [];
    protected string $auditEntity = '';
    protected bool $hasDeletedAt = true;
    protected bool $hasIsActive = true;
    protected string $orderBy = 'name';

    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search = trim((string) $this->request->getGet('search'));

        $builder = $db->table($this->table);
        if ($this->hasDeletedAt) {
            $builder->where('deleted_at IS NULL');
        }
        $this->tenant()->applyScope($builder);

        if ($search !== '') {
            $builder->groupStart();
            foreach ($this->searchableColumns() as $i => $col) {
                if ($i === 0) {
                    $builder->like($col, $search);
                } else {
                    $builder->orLike($col, $search);
                }
            }
            $builder->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy($this->orderBy, 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        $row = $db->table($this->table)->where('id', (int) $id)->where('company_id', $companyId)->get()->getRowArray();
        if (!$row) {
            return $this->fail('Not found', 404);
        }

        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        if ($this->createRules) {
            $valid = $this->validateOrFail($data, $this->createRules);
            if (!$valid) {
                return null;
            }
        }
        $insert = array_intersect_key($data, array_flip($this->fillable));
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $insert['company_id'] = $companyId;
        if ($this->hasIsActive && !isset($insert['is_active'])) {
            $insert['is_active'] = 1;
        }
        $insert['created_at'] = date('Y-m-d H:i:s');
        $insert['updated_at'] = date('Y-m-d H:i:s');

        $db = \Config\Database::connect();
        $db->table($this->table)->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('MASTER')
            ->logCreate(
                $this->auditEntity,
                $id,
                $insert,
                "Membuat data master {$this->auditEntity}: " . ($insert['name'] ?? $insert['code'] ?? '#' . $id)
            );

        return $this->ok($db->table($this->table)->where('id', $id)->where('company_id', $companyId)->get()->getRowArray(), 'Created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        $existing = $db->table($this->table)->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        $data = $this->getJsonInput();
        if ($this->updateRules) {
            $valid = $this->validateOrFail($data, $this->updateRules);
            if (!$valid) {
                return null;
            }
        }
        $update = array_intersect_key($data, array_flip($this->fillable));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table($this->table)->where('id', $id)->where('company_id', $companyId)->update($update);
        // Catat hanya field yang benar-benar di-submit untuk diff yang bersih
        $beforeFiltered = array_intersect_key($existing, $update);
        Services::audit()
            ->forModule('MASTER')
            ->logUpdate(
                $this->auditEntity,
                $id,
                $beforeFiltered,
                $update,
                "Mengubah data master {$this->auditEntity}: " . ($existing['name'] ?? $existing['code'] ?? '#' . $id)
            );

        return $this->ok($db->table($this->table)->where('id', $id)->where('company_id', $companyId)->get()->getRowArray(), 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) {
            return null;
        }
        $db = \Config\Database::connect();
        $existing = $db->table($this->table)->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (!$existing) {
            return $this->fail('Not found', 404);
        }
        if ($this->hasDeletedAt) {
            $db->table($this->table)->where('id', $id)->where('company_id', $companyId)->update([
                'deleted_at' => date('Y-m-d H:i:s'),
                'is_active' => 0,
            ]);
        } else {
            $db->table($this->table)->where('id', $id)->where('company_id', $companyId)->delete();
        }
        Services::audit()
            ->forModule('MASTER')
            ->logDelete(
                $this->auditEntity,
                $id,
                $existing,
                "Menghapus data master {$this->auditEntity}: " . ($existing['name'] ?? $existing['code'] ?? '#' . $id)
            );

        return $this->ok(null, 'Deleted');
    }

    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
