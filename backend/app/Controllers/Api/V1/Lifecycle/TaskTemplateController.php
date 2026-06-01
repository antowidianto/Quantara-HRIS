<?php

namespace App\Controllers\Api\V1\Lifecycle;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * TaskTemplateController
 *
 * Master CRUD untuk template checklist Onboarding/Offboarding.
 *
 * Format `tasks` JSON:
 *   [
 *     {key: "CONTRACT_SIGN", name: "Tanda tangan kontrak",
 *      description: "...", owner_role: "HR",
 *      due_offset_days: 0, mandatory: true},
 *     ...
 *   ]
 */
class TaskTemplateController extends ApiController
{
    private const VALID_OWNER_ROLES = ['HR','IT','FINANCE','SUPERVISOR','MANAGER','EMPLOYEE'];

    public function index()
    {
        $db = \Config\Database::connect();
        $type = strtoupper((string) $this->request->getGet('type'));
        $builder = $db->table('task_templates')->where('deleted_at IS NULL');
        $this->tenant()->applyScope($builder);
        if (in_array($type, ['ONBOARD','OFFBOARD'], true)) $builder->where('type', $type);
        $rows = $builder->orderBy('type')->orderBy('is_default','DESC')->orderBy('name')->get()->getResultArray();

        // Decode tasks JSON
        foreach ($rows as &$r) {
            $r['tasks'] = is_string($r['tasks']) ? (json_decode($r['tasks'], true) ?: []) : ($r['tasks'] ?: []);
        }
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        $row['tasks'] = is_string($row['tasks']) ? (json_decode($row['tasks'], true) ?: []) : ($row['tasks'] ?: []);
        return $this->ok($row);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|max_length[64]',
            'name' => 'required|max_length[200]',
            'type' => 'required|in_list[ONBOARD,OFFBOARD]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $tasks = $this->normalizeTasks($data['tasks'] ?? []);
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Kalau is_default=1, set yang lain jadi 0 untuk type yg sama
        if (! empty($data['is_default'])) {
            $db->table('task_templates')
                ->where('company_id', $companyId)
                ->where('type', $data['type'])
                ->update(['is_default' => 0, 'updated_at' => $now]);
        }

        $insert = [
            'company_id'  => $companyId,
            'code'        => strtoupper((string) $data['code']),
            'name'        => $data['name'],
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'is_default'  => ! empty($data['is_default']) ? 1 : 0,
            'is_active'   => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'tasks'       => json_encode($tasks, JSON_UNESCAPED_UNICODE),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $db->table('task_templates')->insert($insert);
        $id = (int) $db->insertID();
        Services::audit()->forModule('HR')->logCreate(
            'task_template', $id, $insert,
            "Membuat template task: {$insert['code']}"
        );
        return $this->ok(['id' => $id], 'Template dibuat', 201);
    }

    public function update($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'code', 'name', 'description', 'is_default', 'is_active', 'type',
        ]));
        if (isset($update['code'])) $update['code'] = strtoupper((string) $update['code']);
        if (isset($update['type']) && ! in_array($update['type'], ['ONBOARD','OFFBOARD'], true)) {
            return $this->fail('Type tidak valid', 422);
        }
        if (isset($update['is_default']) && $update['is_default']) {
            \Config\Database::connect()->table('task_templates')
                ->where('company_id', (int) $row['company_id'])
                ->where('type', $update['type'] ?? $row['type'])
                ->where('id !=', (int) $id)
                ->update(['is_default' => 0]);
            $update['is_default'] = 1;
        }
        if (array_key_exists('tasks', $data)) {
            $update['tasks'] = json_encode($this->normalizeTasks($data['tasks'] ?? []), JSON_UNESCAPED_UNICODE);
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('task_templates')->where('id', (int) $id)->update($update);
        Services::audit()->forModule('HR')->logUpdate('task_template', (int) $id, $row, $update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $row = $this->loadOrFail((int) $id);
        if (! $row) return $this->response;
        \Config\Database::connect()->table('task_templates')->where('id', (int) $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Services::audit()->forModule('HR')->logDelete('task_template', (int) $id, $row);
        return $this->ok(null, 'Deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('onboarding.manage');
    }

    private function loadOrFail(int $id): ?array
    {
        $db = \Config\Database::connect();
        $row = $db->table('task_templates')->where('id', $id)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) {
            $this->response = $this->fail('Not found', 404);
            return null;
        }
        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $row['company_id'] !== $tenant->getCompanyId()) {
            $this->response = $this->fail('Forbidden', 403);
            return null;
        }
        return $row;
    }

    /**
     * Normalize tasks input dari frontend ke struktur konsisten.
     * @return array
     */
    private function normalizeTasks(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') continue;

            $owner = strtoupper((string) ($t['owner_role'] ?? 'HR'));
            if (! in_array($owner, self::VALID_OWNER_ROLES, true)) $owner = 'HR';

            $out[] = [
                'key'             => preg_replace('/[^A-Z0-9_]/i', '', strtoupper((string) ($t['key'] ?? ''))) ?: null,
                'name'            => $name,
                'description'     => trim((string) ($t['description'] ?? '')) ?: null,
                'owner_role'      => $owner,
                'due_offset_days' => (int) ($t['due_offset_days'] ?? 0),
                'mandatory'       => isset($t['mandatory']) ? (bool) $t['mandatory'] : true,
            ];
        }
        return $out;
    }
}