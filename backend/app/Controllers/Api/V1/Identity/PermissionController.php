<?php

namespace App\Controllers\Api\V1\Identity;

use App\Controllers\Api\V1\ApiController;
class PermissionController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $rows = $db->table('permissions')->orderBy('group')->orderBy('slug')->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['group']][] = $r;
        }
        return $this->ok(['permissions' => $rows, 'grouped' => $grouped]);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('permissions')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function create()  { return $this->fail('Permissions are seeded', 405); }
    public function update($id = null) { return $this->fail('Permissions are seeded', 405); }
    public function delete($id = null) { return $this->fail('Permissions are seeded', 405); }
}