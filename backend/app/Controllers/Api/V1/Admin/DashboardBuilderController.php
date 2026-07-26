<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Admin\RoleDashboardService;
use Config\Database;
use Config\Services;

final class DashboardBuilderController extends ApiController
{
    public function catalog()
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->ok((new RoleDashboardService())->catalog());
    }

    public function index()
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->ok((new RoleDashboardService())->templates());
    }

    public function show($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->respond(fn () => (new RoleDashboardService())->find((int) $id));
    }

    public function create()
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->mutate('dashboard.template.create', null, function () {
            return (new RoleDashboardService())->create($this->getJsonInput(), $this->userId());
        }, 201);
    }

    public function update($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->mutate('dashboard.template.update', (int) $id, function () use ($id) {
            return (new RoleDashboardService())->update((int) $id, $this->getJsonInput(), $this->userId());
        });
    }

    public function publish($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->mutate('dashboard.template.publish', (int) $id, function () use ($id) {
            return (new RoleDashboardService())->publish((int) $id, $this->userId());
        });
    }

    public function delete($id = null)
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->mutate('dashboard.template.delete', (int) $id, function () use ($id) {
            (new RoleDashboardService())->delete((int) $id, $this->userId());
            return ['id' => (int) $id];
        });
    }

    public function preview()
    {
        if ($block = $this->ensureSuperAdmin()) return $block;
        return $this->respond(function () {
            $input = $this->getJsonInput();
            $companyId = (int) ($input['company_id'] ?? 0);
            if ($companyId <= 0) $companyId = $this->companyId();
            return (new RoleDashboardService())->preview($input, $companyId);
        });
    }

    public function mine()
    {
        try {
            $db = Database::connect();
            $role = $db->table('roles')->select('slug')->where('id', $this->tenant()->getRoleId())
                ->get()->getRowArray();
            if (! $role) return $this->ok(null);
            return $this->ok((new RoleDashboardService())->effective(
                $this->tenant()->getCompanyId(),
                (string) $role['slug'],
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 409);
        }
    }

    private function respond(callable $callback)
    {
        try {
            return $this->ok($callback());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'DASHBOARD_VALIDATION');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 409, 'DASHBOARD_ERROR');
        }
    }

    private function ensureSuperAdmin()
    {
        return $this->tenant()->isSuperAdmin()
            ? null
            : $this->fail('Forbidden', 403, 'SUPER_ADMIN_ONLY');
    }

    private function mutate(string $action, ?int $id, callable $callback, int $status = 200)
    {
        try {
            $result = $callback();
            Services::audit()->forModule('ADMIN')->log(
                action: $action,
                entity: 'dashboard_templates',
                entityId: $id ?: (int) ($result['id'] ?? 0),
                after: [
                    'target_role_slug' => $result['target_role_slug'] ?? null,
                    'status' => $result['status'] ?? null,
                    'version' => $result['version'] ?? null,
                ],
                severity: 'HIGH',
            );
            return $this->ok($result, 'Dashboard tersimpan', $status);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'DASHBOARD_VALIDATION');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 409, 'DASHBOARD_ERROR');
        }
    }
}
