<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * IdpController — Individual Development Plan REST API (Sprint 3 / OD-2).
 *
 * Endpoint:
 *   GET    /idp                              — list (HR dashboard, dengan filter)
 *   GET    /idp/my                           — my IDP list (karyawan ybs)
 *   POST   /idp                              — create manual standalone
 *   POST   /idp/from-review/(:num)           — auto-create dari performance review
 *   GET    /idp/(:num)                       — detail
 *   PUT    /idp/(:num)                       — update (rules per status)
 *   DELETE /idp/(:num)                       — soft-delete
 *   POST   /idp/(:num)/finalize              — DRAFT → ACTIVE
 *   POST   /idp/(:num)/complete              — ACTIVE → COMPLETED
 *   POST   /idp/(:num)/cancel                — DRAFT/ACTIVE → CANCELLED
 *
 * Authorization:
 *   - View all: `idp.view`
 *   - View own: `idp.view_own` (karyawan ybs auto-allowed)
 *   - Manage: `idp.manage`
 */
class IdpController extends ApiController
{
    public function index()
    {
        if (! $this->canViewAll()) return $this->fail('Forbidden', 403);
        $filters = [
            'company_id'  => $this->tenant()->getCompanyId() ?: null,
            'employee_id' => (int) ($this->request->getGet('employee_id') ?? 0) ?: null,
            'cycle_id'    => (int) ($this->request->getGet('cycle_id') ?? 0) ?: null,
            'status'      => trim((string) ($this->request->getGet('status') ?? '')) ?: null,
        ];
        $rows = Services::idp()->listIdps(array_filter($filters, static fn ($v) => $v !== null));
        return $this->ok($rows);
    }

    /**
     * GET /idp/my — list IDP karyawan login.
     */
    public function my()
    {
        $myEmpId = (int) $this->tenant()->getEmployeeId();
        if ($myEmpId <= 0) {
            return $this->fail('Akun ini tidak terhubung ke record karyawan', 422, 'NO_EMPLOYEE_LINK');
        }
        $rows = Services::idp()->listIdps([
            'company_id'  => $this->tenant()->getCompanyId() ?: null,
            'employee_id' => $myEmpId,
        ]);
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        try {
            $idp = Services::idp()->load((int) $id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->canViewIdp($idp)) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($idp);
    }

    public function create()
    {
        if (! $this->canManage()) return $this->fail('Forbidden — butuh idp.manage', 403);
        $data = $this->getJsonInput();
        try {
            $idp = Services::idp()->create($data, $this->userId());
            return $this->ok($idp, 'IDP DRAFT dibuat', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * POST /idp/from-review/(:num) — auto-bootstrap dari performance review.
     * Idempotent — kalau review sudah punya IDP, return existing.
     */
    public function createFromReview($reviewId = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        try {
            $idp = Services::idp()->createFromReview((int) $reviewId, $this->userId());
            $msg = $idp['created_at'] === $idp['updated_at']
                ? "IDP DRAFT dibuat dari review #{$reviewId}"
                : "IDP existing dari review #{$reviewId} dikembalikan";
            return $this->ok($idp, $msg);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function update($id = null)
    {
        $id = (int) $id;
        try {
            $idp = Services::idp()->load($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        // Karyawan ybs boleh update field tertentu (employee_notes, action_items
        // status). Service yang enforce per-status. HR bisa update lebih luas.
        if (! $this->canEditIdp($idp)) {
            return $this->fail('Forbidden', 403);
        }
        try {
            $updated = Services::idp()->update($id, $this->getJsonInput(), $this->userId());
            return $this->ok($updated, 'IDP updated');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $idp = Services::idp()->load($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 404);
        }
        if (! $this->tenant()->isSuperAdmin() && (int) $idp['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('individual_development_plans')->where('id', $id)->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->ok(null, 'Deleted');
    }

    public function finalize($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        try {
            $result = Services::idp()->finalize($id, $this->userId());
            return $this->ok($result, 'IDP DRAFT → ACTIVE');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function complete($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $note = isset($data['manager_notes']) ? trim((string) $data['manager_notes']) : null;
        try {
            $result = Services::idp()->complete($id, $this->userId(), $note ?: null);
            return $this->ok($result, 'IDP ACTIVE → COMPLETED');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function cancel($id = null)
    {
        if (! $this->canManage()) return $this->fail('Forbidden', 403);
        $id = (int) $id;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'reason' => 'required|min_length[5]|max_length[1000]',
        ]);
        if (! $valid) return null;
        try {
            $result = Services::idp()->cancel($id, $this->userId(), $valid['reason']);
            return $this->ok($result, 'IDP cancelled');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function canViewAll(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('idp.view')
            || $t->hasPermission('idp.manage');
    }

    private function canManage(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('idp.manage');
    }

    /**
     * Karyawan ybs auto-allowed lihat IDP-nya sendiri (transparency).
     */
    private function canViewIdp(array $idp): bool
    {
        if ($this->canViewAll()) return true;
        $myEmpId = (int) $this->tenant()->getEmployeeId();
        return $myEmpId > 0 && (int) $idp['employee_id'] === $myEmpId;
    }

    /**
     * Edit allowed kalau:
     *   - HR dengan idp.manage, atau
     *   - Karyawan ybs (untuk update employee_notes & action_items progress)
     */
    private function canEditIdp(array $idp): bool
    {
        if ($this->canManage()) return true;
        $myEmpId = (int) $this->tenant()->getEmployeeId();
        return $myEmpId > 0 && (int) $idp['employee_id'] === $myEmpId;
    }
}