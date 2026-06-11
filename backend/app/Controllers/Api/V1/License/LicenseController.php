<?php

namespace App\Controllers\Api\V1\License;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\License\LicenseTrackerService;
use Config\Database;

/**
 * License & Certification Tracker Controller.
 *
 * Endpoints:
 *   GET    /api/v1/license-types                    ?category=&active=
 *   POST   /api/v1/license-types
 *   PUT    /api/v1/license-types/{id}
 *   DELETE /api/v1/license-types/{id}
 *
 *   GET    /api/v1/employee-licenses                ?employee_id=&status=&expiring_in=
 *   POST   /api/v1/employee-licenses
 *   GET    /api/v1/employee-licenses/{id}
 *   PUT    /api/v1/employee-licenses/{id}
 *   POST   /api/v1/employee-licenses/{id}/renew
 *   POST   /api/v1/employee-licenses/{id}/revoke
 *   GET    /api/v1/employees/{id}/license-compliance ?position_id=&on_date=
 *
 *   GET    /api/v1/licenses/expiring-dashboard
 *   POST   /api/v1/licenses/scan-now                 (manual trigger cron)
 */
class LicenseController extends ApiController
{
    private LicenseTrackerService $service;

    public function __construct()
    {
        $this->service = new LicenseTrackerService();
    }

    // ─── License Types ────────────────────────────────────────────────

    public function listTypes()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        $b = Database::connect()->table('license_types')
            ->where('company_id', $companyId)->where('deleted_at', null);
        if ($c = $this->request->getGet('category')) $b->where('category', $c);
        if ($this->request->getGet('active') !== null) {
            $b->where('is_active', (int) $this->request->getGet('active'));
        }
        $rows = $b->orderBy('category', 'ASC')->orderBy('code', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createType()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'code' => 'required|min_length[2]|max_length[64]',
            'name' => 'required|min_length[3]|max_length[200]',
        ]);
        if ($body === null) return null;

        if (! empty($body['category']) && ! in_array($body['category'], LicenseTrackerService::CATEGORIES, true)) {
            return $this->fail('category tidak valid', 422, 'VALIDATION_ERROR');
        }

        $db = Database::connect();
        $exists = $db->table('license_types')
            ->where('company_id', $companyId)->where('code', $body['code'])->where('deleted_at', null)
            ->countAllResults();
        if ($exists) return $this->fail('Code sudah ada', 409, 'DUPLICATE_CODE');

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'        => $companyId,
            'code'              => $body['code'],
            'name'              => $body['name'],
            'description'       => $body['description'] ?? null,
            'category'          => $body['category']    ?? 'PROFESSIONAL',
            'issuing_authority' => $body['issuing_authority'] ?? null,
            'validity_months'   => isset($body['validity_months']) ? (int) $body['validity_months'] : null,
            'is_required_for_position_ids' => isset($body['is_required_for_position_ids'])
                ? json_encode((array) $body['is_required_for_position_ids'])
                : null,
            'warn_before_days_json' => isset($body['warn_before_days'])
                ? json_encode((array) $body['warn_before_days'])
                : '[30,7,1]',
            'block_assignment_on_expiry' => isset($body['block_assignment_on_expiry'])
                ? (int) (bool) $body['block_assignment_on_expiry']
                : 0,
            'is_active'         => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        $db->table('license_types')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Created', 201);
    }

    public function updateType(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('license_types')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ([
            'name', 'description', 'category', 'issuing_authority',
            'validity_months', 'block_assignment_on_expiry', 'is_active',
        ] as $f) {
            if (array_key_exists($f, $body)) {
                $upd[$f] = $body[$f] === '' ? null : $body[$f];
            }
        }
        if (isset($body['is_required_for_position_ids'])) {
            $upd['is_required_for_position_ids'] = json_encode((array) $body['is_required_for_position_ids']);
        }
        if (isset($body['warn_before_days'])) {
            $upd['warn_before_days_json'] = json_encode((array) $body['warn_before_days']);
        }
        $db->table('license_types')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function deleteType(int $id)
    {
        $companyId = $this->companyId();
        Database::connect()->table('license_types')
            ->where('id', $id)->where('company_id', $companyId)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0]);
        return $this->ok(null, 'Deleted');
    }

    // ─── Employee Licenses ────────────────────────────────────────────

    public function listLicenses()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        $b = Database::connect()->table('employee_licenses el')
            ->select('el.*, lt.code AS license_code, lt.name AS license_name, lt.category')
            ->join('license_types lt', 'lt.id = el.license_type_id', 'left')
            ->where('el.company_id', $companyId)
            ->where('el.deleted_at', null);
        if ($eid = $this->request->getGet('employee_id')) $b->where('el.employee_id', (int) $eid);
        if ($s   = $this->request->getGet('status'))      $b->where('el.status', $s);
        if ($exp = $this->request->getGet('expiring_in')) {
            $cutoff = date('Y-m-d', strtotime("+{$exp} days"));
            $b->where('el.expires_on <=', $cutoff);
            $b->whereIn('el.status', ['ACTIVE', 'EXPIRING_SOON']);
        }
        $rows = $b->orderBy('el.expires_on', 'ASC')->limit(500)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createLicense()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'employee_id'     => 'required|is_natural_no_zero',
            'license_type_id' => 'required|is_natural_no_zero',
            'license_number'  => 'required|min_length[1]|max_length[100]',
            'issued_on'       => 'required|valid_date',
        ]);
        if ($body === null) return null;

        $db = Database::connect();
        // Auto-compute expires_on dari validity_months kalau tidak di-set
        $expires = $body['expires_on'] ?? null;
        if (empty($expires)) {
            $lt = $db->table('license_types')
                ->where('id', (int) $body['license_type_id'])
                ->where('company_id', $companyId)
                ->get()->getRowArray();
            if ($lt && ! empty($lt['validity_months'])) {
                $expires = LicenseTrackerService::computeExpiryPure(
                    (string) $body['issued_on'],
                    (int) $lt['validity_months'],
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'      => $companyId,
            'employee_id'     => (int) $body['employee_id'],
            'license_type_id' => (int) $body['license_type_id'],
            'license_number'  => $body['license_number'],
            'issued_on'       => $body['issued_on'],
            'expires_on'      => $expires,
            'document_path'   => $body['document_path'] ?? null,
            'status'          => 'ACTIVE',
            'note'            => $body['note'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('employee_licenses')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'License recorded', 201);
    }

    public function showLicense(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('employee_licenses')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        return $this->ok($row);
    }

    public function updateLicense(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('employee_licenses')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['license_number', 'issued_on', 'expires_on', 'document_path', 'status', 'note'] as $f) {
            if (array_key_exists($f, $body)) {
                $upd[$f] = $body[$f] === '' ? null : $body[$f];
            }
        }
        $db->table('employee_licenses')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function renewLicense(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('employee_licenses')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        try {
            $newId = $this->service->renew($id, $this->getJsonInput());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'RENEW_FAILED');
        }
        return $this->ok(['old_id' => $id, 'new_id' => $newId], 'Renewed', 201);
    }

    public function revokeLicense(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('employee_licenses')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404, 'NOT_FOUND');

        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        if ($reason === '') return $this->fail('reason wajib diisi', 422, 'REASON_REQUIRED');

        $db->table('employee_licenses')->where('id', $id)->update([
            'status'     => 'REVOKED',
            'note'       => mb_substr('REVOKED: ' . $reason, 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['id' => $id, 'status' => 'REVOKED']);
    }

    public function compliance(int $employeeId)
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');

        $positionId = (int) ($this->request->getGet('position_id') ?: 0);
        if ($positionId <= 0) {
            // Fallback: ambil position dari employee
            $emp = Database::connect()->table('employees')
                ->select('position_id')
                ->where('id', $employeeId)->where('company_id', $companyId)
                ->get()->getRowArray();
            $positionId = (int) ($emp['position_id'] ?? 0);
        }
        if ($positionId <= 0) {
            return $this->fail('position_id required (atau employee tidak punya position)', 422, 'POSITION_REQUIRED');
        }
        $onDate = (string) ($this->request->getGet('on_date') ?: date('Y-m-d'));
        return $this->ok($this->service->complianceFor($employeeId, $positionId, $onDate));
    }

    public function expiringDashboard()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');

        $db = Database::connect();
        $today    = date('Y-m-d');
        $in7      = date('Y-m-d', strtotime('+7 days'));
        $in30     = date('Y-m-d', strtotime('+30 days'));
        $in90     = date('Y-m-d', strtotime('+90 days'));

        $count = function (array $where) use ($db, $companyId) {
            $b = $db->table('employee_licenses')
                ->where('company_id', $companyId)
                ->where('deleted_at', null);
            foreach ($where as $k => $v) {
                $b->where($k, $v);
            }
            return (int) $b->countAllResults();
        };

        $expired      = $count(['status' => 'EXPIRED']);
        $expiringIn7  = (int) $db->table('employee_licenses')
            ->where('company_id', $companyId)->where('deleted_at', null)
            ->whereIn('status', ['ACTIVE', 'EXPIRING_SOON'])
            ->where('expires_on >=', $today)->where('expires_on <=', $in7)
            ->countAllResults();
        $expiringIn30 = (int) $db->table('employee_licenses')
            ->where('company_id', $companyId)->where('deleted_at', null)
            ->whereIn('status', ['ACTIVE', 'EXPIRING_SOON'])
            ->where('expires_on >=', $today)->where('expires_on <=', $in30)
            ->countAllResults();
        $expiringIn90 = (int) $db->table('employee_licenses')
            ->where('company_id', $companyId)->where('deleted_at', null)
            ->whereIn('status', ['ACTIVE', 'EXPIRING_SOON'])
            ->where('expires_on >=', $today)->where('expires_on <=', $in90)
            ->countAllResults();

        return $this->ok([
            'expired'        => $expired,
            'expiring_in_7'  => $expiringIn7,
            'expiring_in_30' => $expiringIn30,
            'expiring_in_90' => $expiringIn90,
        ]);
    }

    public function scanNow()
    {
        $scan = $this->service->scanForActions();
        $applied = $this->service->applyScanResults($scan);
        return $this->ok([
            'to_expire' => count($scan['to_expire'] ?? []),
            'to_warn'   => count($scan['to_warn']   ?? []),
            'applied'   => $applied,
        ]);
    }
}