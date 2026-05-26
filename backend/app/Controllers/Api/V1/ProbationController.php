<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * ProbationController — REST API untuk manage probation karyawan PKWTT
 * (Sprint 2 / HIRE-1).
 *
 * Endpoint:
 *   GET  /probation/expiring             — list karyawan probation berakhir H+14
 *   GET  /probation/expired              — list yang sudah lewat tanpa keputusan
 *   POST /employees/(:num)/probation/decide — record PASS/FAIL/EXTEND
 *
 * Authorization:
 *   - View: HR (`hr.manage` / `probation.manage`)
 *   - Decide: `probation.manage` (manager + HR — supervisor langsung yang
 *     biasanya memutuskan)
 *
 * Lihat docs/LIFECYCLE_AUDIT_2026.md item HIRE-1.
 */
class ProbationController extends ApiController
{
    /**
     * GET /api/v1/probation/expiring — probation berakhir dalam 14 hari ke depan
     */
    public function expiring()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->tenant()->getCompanyId() ?: null;
        $rows = Services::probation()->findExpiringSoon($companyId);

        // Compute days_remaining untuk display
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['days_remaining'] = $r['probation_end_date']
                ? (int) round((strtotime($r['probation_end_date']) - strtotime($today)) / 86400)
                : null;
        }
        unset($r);

        return $this->ok($rows);
    }

    /**
     * GET /api/v1/probation/expired — sudah lewat probation_end_date tapi belum diputuskan
     */
    public function expired()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $companyId = $this->tenant()->getCompanyId() ?: null;
        $rows = Services::probation()->findExpiredWithoutDecision($companyId);

        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['days_overdue'] = $r['probation_end_date']
                ? (int) round((strtotime($today) - strtotime($r['probation_end_date'])) / 86400)
                : null;
        }
        unset($r);

        return $this->ok($rows);
    }

    /**
     * POST /api/v1/employees/(:num)/probation/decide
     *
     * Body: { decision: 'PASSED'|'FAILED'|'EXTENDED', note: string,
     *         new_end_date: 'YYYY-MM-DD' (untuk EXTENDED) }
     */
    public function decide($employeeId = null)
    {
        if (! $this->canDecide()) {
            return $this->fail('Forbidden — butuh permission probation.manage', 403);
        }
        $employeeId = (int) $employeeId;
        if ($employeeId <= 0) return $this->fail('Invalid employee id', 400);

        // Verify ownership
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'decision' => 'required|in_list[PASSED,FAILED,EXTENDED]',
            'note'     => 'permit_empty|max_length[2000]',
            'new_end_date' => 'permit_empty|valid_date[Y-m-d]',
        ]);
        if (! $valid) return null;

        try {
            $result = Services::probation()->decide(
                $employeeId,
                $valid['decision'],
                $this->userId(),
                $valid['note'] ?? null,
                $valid['new_end_date'] ?? null,
            );
            $msg = match ($valid['decision']) {
                'PASSED'   => "Probation untuk {$emp['full_name']} dinyatakan PASSED. Karyawan resmi tetap.",
                'FAILED'   => "Probation FAILED. resign_date di-set ke today. Lanjut ke modul Severance untuk hitung kompensasi PP 35/2021.",
                'EXTENDED' => "Probation diperpanjang sampai {$valid['new_end_date']}. Status reset ke ONGOING.",
            };
            return $this->ok($result, $msg);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'INVALID_INPUT');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, 'PROBATION_BLOCKED');
        }
    }

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('probation.manage')
            || $t->hasPermission('employee.view')
            || $t->hasPermission('hr.manage');
    }

    private function canDecide(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('probation.manage');
    }
}