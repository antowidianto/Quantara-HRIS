<?php

namespace App\Controllers\Api\V1\Identity;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

class UserController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $search = trim((string) $this->request->getGet('search'));

        $builder = $db->table('users u')
            ->select('u.id, u.email, u.name, u.is_active, u.role_id, u.company_id, u.employee_id, u.last_login_at, u.twofa_enabled, r.slug AS role_slug, r.name AS role_name, e.employee_number, e.full_name AS employee_full_name')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->join('employees e', 'e.user_id = u.id AND e.deleted_at IS NULL', 'left')
            ->where('u.deleted_at IS NULL');

        if (! $this->tenant()->isSuperAdmin()) {
            $builder->where('u.company_id', $this->companyId());
        }
        if ($search !== '') {
            $builder->groupStart()->like('u.email', $search)->orLike('u.name', $search)->groupEnd();
        }
        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('u.name')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('users')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        unset($row['password_hash'], $row['twofa_secret']);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'email'    => 'required|valid_email|max_length[191]|is_unique[users.email]',
            'name'     => 'required|max_length[150]',
            'role_id'  => 'required|integer',
            'password' => 'required|min_length[10]',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'company_id'    => $this->companyId() ?: null,
            'role_id'       => $data['role_id'],
            'employee_id'   => $data['employee_id'] ?? null,
            'email'         => strtolower($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_ARGON2ID),
            'name'          => $data['name'],
            'is_active'     => $data['is_active'] ?? 1,
            'must_change_password' => $data['must_change_password'] ?? 1,
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        Services::audit()->log('user.create', 'user', $id);
        return $this->ok(['id' => $id], 'User created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('users')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['name','role_id','is_active','employee_id']));
        if (! empty($data['password']) && strlen($data['password']) >= 10) {
            $update['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
            $update['password_changed_at'] = date('Y-m-d H:i:s');
            // Force re-login
            Services::jwt()->revokeAllForUser($id);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('users')->where('id', $id)->update($update);
        Services::audit()->log('user.update', 'user', $id);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        if ($id === $this->userId()) return $this->fail('Cannot delete yourself', 422);
        $db = \Config\Database::connect();
        $db->table('users')->where('id', $id)->update(['is_active' => 0, 'deleted_at' => date('Y-m-d H:i:s')]);
        Services::jwt()->revokeAllForUser($id);
        Services::audit()->log('user.delete', 'user', $id);
        return $this->ok(null, 'Deleted');
    }
}