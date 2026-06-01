<?php

namespace App\Controllers\Api\V1\Employee;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * Employee360Controller — aggregator endpoint untuk Employee 360 view
 * (Sprint 4 / CC-4).
 *
 * Endpoint:
 *   GET /employees/(:num)/360 — full 360 view dengan 12 section
 *
 * Authorization:
 *   - HR/manager dengan `employee.view_360`
 *   - Karyawan ybs auto-allowed (transparency — lihat 360 dirinya sendiri)
 *   - Supervisor/manager bisa lihat 360 dari direct report-nya
 *
 * Audit log: setiap akses 360 view di-log severity NOTICE karena
 * mengembalikan PII komprehensif (UU PDP).
 */
class Employee360Controller extends ApiController
{
    public function show($id = null)
    {
        $employeeId = (int) $id;
        if ($employeeId <= 0) return $this->fail('Invalid employee ID', 422);

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Employee tidak ditemukan', 404);

        $tenant = $this->tenant();
        if (! $tenant->isSuperAdmin() && (int) $emp['company_id'] !== $tenant->getCompanyId()) {
            return $this->fail('Forbidden — beda company', 403);
        }

        if (! $this->canView360($employeeId, $emp)) {
            return $this->fail(
                'Forbidden — butuh permission employee.view_360, '
                . 'atau Anda adalah supervisor/karyawan ybs',
                403
            );
        }

        try {
            $view = Services::employee360()->build($employeeId);
        } catch (\Throwable $e) {
            return $this->fail('Gagal build 360 view: ' . $e->getMessage(), 500);
        }

        // Audit access — UU PDP wajib log akses ke PII komprehensif
        try {
            Services::audit()->forModule('PRIVACY')->log(
                action: 'employee.view_360',
                entity: 'employee',
                entityId: $employeeId,
                description: "Akses Employee 360 view untuk #{$employeeId} ({$emp['full_name']})",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        return $this->ok($view);
    }

    /**
     * Akses allowed kalau:
     *   1. Karyawan ybs (lihat dirinya sendiri)
     *   2. Permission employee.view_360
     *   3. Supervisor/manager dari karyawan ybs (lihat direct reports)
     */
    private function canView360(int $employeeId, array $emp): bool
    {
        $t = $this->tenant();
        if ($t->isSuperAdmin()) return true;

        // Karyawan ybs
        $myEmpId = (int) $t->getEmployeeId();
        if ($myEmpId > 0 && $myEmpId === $employeeId) return true;

        // Permission HR
        if ($t->hasPermission('employee.view_360')) return true;

        // Supervisor / manager
        if ($myEmpId > 0) {
            $supId = (int) ($emp['supervisor_id'] ?? 0);
            $mgrId = (int) ($emp['manager_id'] ?? 0);
            if ($myEmpId === $supId || $myEmpId === $mgrId) return true;
        }

        return false;
    }
}