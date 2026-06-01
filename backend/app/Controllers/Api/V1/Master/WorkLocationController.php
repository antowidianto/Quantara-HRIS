<?php

namespace App\Controllers\Api\V1\Master;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * WorkLocationController — master lokasi kerja yang lebih granular dari
 * `branches`. Mendukung tipe HEAD_OFFICE / BRANCH_OFFICE / SUB_OFFICE /
 * PROJECT_SITE / CLIENT_OFFICE / WAREHOUSE / FACTORY / REMOTE / WFH / OTHER
 * dengan koordinat & geofence sendiri.
 *
 * Tetap menulis ke audit log via parent flow (kita panggil Services::audit()
 * langsung agar penjelasan naratifnya enak dibaca).
 */
class WorkLocationController extends ApiController
{
    private array $fillable = [
        'branch_id', 'code', 'name', 'type', 'address', 'province', 'city',
        'postal_code', 'phone', 'lat', 'lng', 'geofence_radius_m',
        'allow_clock_here', 'manager_employee_id', 'notes', 'is_active',
    ];

    public function index()
    {
        $db   = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(200, max(1, (int) ($this->request->getGet('per_page') ?? 50)));
        $search  = trim((string) $this->request->getGet('search'));
        $type    = trim((string) $this->request->getGet('type'));
        $branchId= (int) ($this->request->getGet('branch_id') ?? 0);

        $builder = $db->table('work_locations w')
            ->select('w.*, b.name AS branch_name, e.full_name AS manager_name')
            ->join('branches b', 'b.id = w.branch_id', 'left')
            ->join('employees e', 'e.id = w.manager_employee_id', 'left')
            ->where('w.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'w.company_id');

        if ($search !== '') {
            $builder->groupStart()
                ->like('w.name', $search)
                ->orLike('w.code', $search)
                ->orLike('w.city', $search)
                ->groupEnd();
        }
        if ($type !== '')   $builder->where('w.type', $type);
        if ($branchId > 0)  $builder->where('w.branch_id', $branchId);

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('w.name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('work_locations')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code' => 'required|max_length[32]',
            'name' => 'required|max_length[200]',
            'type' => 'permit_empty|in_list[HEAD_OFFICE,BRANCH_OFFICE,SUB_OFFICE,PROJECT_SITE,CLIENT_OFFICE,WAREHOUSE,FACTORY,REMOTE,WFH,OTHER]',
            'lat'  => 'permit_empty|decimal',
            'lng'  => 'permit_empty|decimal',
            'geofence_radius_m' => 'permit_empty|is_natural',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $exists = $db->table('work_locations')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) return $this->fail('Kode lokasi sudah dipakai', 422, 'DUPLICATE_CODE');

        $now = date('Y-m-d H:i:s');
        $insert = array_intersect_key($data, array_flip($this->fillable));
        $insert['company_id'] = $companyId;
        $insert['type']       = $insert['type'] ?? 'BRANCH_OFFICE';
        $insert['is_active']  = $insert['is_active'] ?? 1;
        $insert['allow_clock_here'] = $insert['allow_clock_here'] ?? 1;
        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $db->table('work_locations')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('MASTER')
            ->logCreate('work_location', $id, $insert, "Menambah lokasi kerja: {$insert['name']} ({$insert['code']})");
        return $this->ok($db->table('work_locations')->where('id', $id)->get()->getRowArray(), 'Created', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('work_locations')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip($this->fillable));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('work_locations')->where('id', $id)->update($update);
        Services::audit()
            ->forModule('MASTER')
            ->logUpdate('work_location', $id, array_intersect_key($existing, $update), $update,
                "Mengubah lokasi kerja: {$existing['name']} ({$existing['code']})");
        return $this->ok($db->table('work_locations')->where('id', $id)->get()->getRowArray(), 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('work_locations')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('work_locations')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('work_location', $id, $existing,
                "Menghapus lokasi kerja: {$existing['name']} ({$existing['code']})");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Riwayat penugasan satu karyawan ke berbagai lokasi.
     */
    public function employeeHistory($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $db = \Config\Database::connect();
        $rows = $db->table('employee_work_locations ewl')
            ->select('ewl.*, w.name AS location_name, w.code AS location_code, w.type AS location_type')
            ->join('work_locations w', 'w.id = ewl.work_location_id')
            ->where('ewl.employee_id', $employeeId)
            ->orderBy('ewl.date_from', 'DESC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    /**
     * Tugaskan karyawan ke lokasi (otomatis menutup PRIMARY sebelumnya).
     */
    public function assignEmployee($employeeId = null)
    {
        $employeeId = (int) $employeeId;
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'work_location_id' => 'required|integer',
            'date_from'        => 'required|valid_date',
            'assignment_type'  => 'permit_empty|in_list[PRIMARY,SECONDARY,TEMPORARY,PROJECT,REMOTE]',
        ]);
        if (! $valid) return null;

        $db  = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Karyawan tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $emp['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $type = $data['assignment_type'] ?? 'PRIMARY';
        $now  = date('Y-m-d H:i:s');

        // Tutup penugasan PRIMARY sebelumnya kalau ada.
        if ($type === 'PRIMARY') {
            $db->table('employee_work_locations')
                ->where('employee_id', $employeeId)
                ->where('assignment_type', 'PRIMARY')
                ->where('date_to IS NULL')
                ->update([
                    'date_to'    => date('Y-m-d', strtotime($data['date_from'] . ' -1 day')),
                    'updated_at' => $now,
                ]);
            $db->table('employees')->where('id', $employeeId)->update([
                'work_location_id' => (int) $data['work_location_id'],
                'updated_at'       => $now,
            ]);
        }

        $db->table('employee_work_locations')->insert([
            'company_id'       => (int) $emp['company_id'],
            'employee_id'      => $employeeId,
            'work_location_id' => (int) $data['work_location_id'],
            'assignment_type'  => $type,
            'date_from'        => $data['date_from'],
            'date_to'          => $data['date_to'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $this->userId(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $id = (int) $db->insertID();
        Services::audit()
            ->forModule('EMPLOYEE')
            ->logCreate('employee_work_location', $id, $data,
                "Menugaskan {$emp['full_name']} ke lokasi #" . $data['work_location_id'] . " ({$type})");
        return $this->ok(['id' => $id], 'Penugasan disimpan', 201);
    }
}