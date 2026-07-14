<?php

namespace App\Controllers\Api\V1\Attendance;

use App\Controllers\Api\V1\ApiController;
use App\Models\Employee\EmployeeModel;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * ShiftAssignmentController
 *
 * Aturan pemetaan shift ke scope (department/branch/work_location/job_grade).
 * Engine `ShiftResolver` membaca tabel ini saat menentukan shift untuk
 * karyawan tertentu pada tanggal tertentu, ketika tidak ada
 * shift_schedules eksplisit dan employee belum punya default_shift_id.
 *
 * Pola spesifisitas (semakin spesifik = prioritas tinggi):
 *   job_grade > department > branch > work_location > company-wide
 * Kalau dua rule punya spesifisitas sama, `priority` ASC menentukan.
 */
class ShiftAssignmentController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $shiftId  = (int) ($this->request->getGet('shift_id') ?? 0);
        $branchId = (int) ($this->request->getGet('branch_id') ?? 0);
        $deptId   = (int) ($this->request->getGet('department_id') ?? 0);
        $isActive = $this->request->getGet('is_active');

        $builder = $db->table('shift_assignments sa')
            ->select('sa.*, s.code AS shift_code, s.name AS shift_name, s.start_time, s.end_time,'
                . ' b.name AS branch_name, w.name AS work_location_name,'
                . ' d.name AS department_name, g.name AS grade_name')
            ->join('shifts s', 's.id = sa.shift_id AND s.company_id = sa.company_id', 'left')
            ->join('branches b', 'b.id = sa.branch_id AND b.company_id = sa.company_id', 'left')
            ->join('work_locations w', 'w.id = sa.work_location_id AND w.company_id = sa.company_id', 'left')
            ->join('departments d', 'd.id = sa.department_id AND d.company_id = sa.company_id', 'left')
            ->join('job_grades g', 'g.id = sa.job_grade_id AND g.company_id = sa.company_id', 'left');

        $this->tenant()->applyScope($builder, 'sa.company_id');

        if ($shiftId)  $builder->where('sa.shift_id', $shiftId);
        if ($branchId) $builder->where('sa.branch_id', $branchId);
        if ($deptId)   $builder->where('sa.department_id', $deptId);
        if ($isActive !== null && $isActive !== '') $builder->where('sa.is_active', (int) $isActive);

        $rows = $builder->orderBy('sa.priority', 'ASC')->orderBy('sa.id', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $row = $db->table('shift_assignments')->where('id', (int) $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'shift_id' => 'required|integer',
            'priority' => 'permit_empty|integer',
            'effective_from' => 'permit_empty|valid_date',
            'effective_to' => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        // Pastikan minimal salah satu scope diisi (kalau semua null,
        // ini = company-wide rule, dan itu boleh tapi spam → kasih warning
        // ringan via flag, tidak diblokir).
        $db = \Config\Database::connect();

        // Verifikasi shift ada & sesuai company.
        $shift = $db->table('shifts')->where('id', (int) $data['shift_id'])->where('company_id', $companyId)->get()->getRowArray();
        if (! $shift) return $this->fail('Shift tidak ditemukan', 404);
        $invalidScope = $this->invalidScopeReference($db, $data, $companyId);
        if ($invalidScope !== null) return $this->fail("Referensi {$invalidScope} tidak tersedia pada tenant aktif", 422, 'INVALID_SCOPE');
        if (! empty($data['effective_from']) && ! empty($data['effective_to']) && $data['effective_to'] < $data['effective_from']) {
            return $this->fail('effective_to harus setelah atau sama dengan effective_from', 422, 'INVALID_DATE_RANGE');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'shift_id'        => (int) $data['shift_id'],
            'department_id'   => $data['department_id'] ?? null,
            'branch_id'       => $data['branch_id'] ?? null,
            'work_location_id'=> $data['work_location_id'] ?? null,
            'job_grade_id'    => $data['job_grade_id'] ?? null,
            'priority'        => (int) ($data['priority'] ?? 100),
            'is_active'       => (int) ($data['is_active'] ?? 1),
            'effective_from'  => $data['effective_from'] ?? null,
            'effective_to'    => $data['effective_to'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('shift_assignments')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('MASTER')
            ->logCreate('shift_assignment', $id, $insert,
                "Memetakan shift {$shift['name']} ke scope tertentu");
        return $this->ok(['id' => $id], 'Shift assignment dibuat', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('shift_assignments')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'shift_id' => 'permit_empty|integer',
            'priority' => 'permit_empty|integer',
            'effective_from' => 'permit_empty|valid_date',
            'effective_to' => 'permit_empty|valid_date',
        ]);
        if (! $valid) return null;
        if (isset($data['shift_id'])) {
            if ((int) $data['shift_id'] <= 0) return $this->fail('shift_id wajib', 422);
            $shiftExists = $db->table('shifts')->where('id', (int) $data['shift_id'])->where('company_id', $companyId)->countAllResults();
            if ($shiftExists === 0) return $this->fail('Shift tidak ditemukan', 404);
        }
        $invalidScope = $this->invalidScopeReference($db, $data, $companyId);
        if ($invalidScope !== null) return $this->fail("Referensi {$invalidScope} tidak tersedia pada tenant aktif", 422, 'INVALID_SCOPE');
        $effectiveFrom = $data['effective_from'] ?? $existing['effective_from'];
        $effectiveTo = $data['effective_to'] ?? $existing['effective_to'];
        if ($effectiveFrom && $effectiveTo && $effectiveTo < $effectiveFrom) {
            return $this->fail('effective_to harus setelah atau sama dengan effective_from', 422, 'INVALID_DATE_RANGE');
        }
        $update = array_intersect_key($data, array_flip([
            'shift_id','department_id','branch_id','work_location_id',
            'job_grade_id','priority','is_active','effective_from',
            'effective_to','notes',
        ]));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('shift_assignments')->where('id', $id)->where('company_id', $companyId)->update($update);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate('shift_assignment', $id,
                array_intersect_key($existing, $update), $update,
                "Mengubah shift assignment #{$id}");
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;
        $db = \Config\Database::connect();
        $existing = $db->table('shift_assignments')->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        $db->table('shift_assignments')->where('id', $id)->where('company_id', $companyId)->delete();
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('shift_assignment', $id, $existing,
                "Menghapus shift assignment #{$id}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Helper: cek shift apa yang akan diberikan ke karyawan tertentu di
     * tanggal tertentu — berguna untuk debugging UI.
     *  GET /shift-assignments/preview?employee_id=1&date=2026-05-20
     */
    public function preview()
    {
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        if (! $employeeId) return $this->fail('employee_id wajib', 422);
        if (! (new EmployeeModel())->findScoped($employeeId)) {
            return $this->fail('Karyawan tidak tersedia pada tenant aktif', 404);
        }

        $resolver = Services::shiftResolver(false); // fresh instance untuk request ini
        $result = $resolver->resolve($employeeId, $date);
        return $this->ok($result);
    }

    private function invalidScopeReference(BaseConnection $db, array $data, int $companyId): ?string
    {
        $scopes = [
            'department_id' => 'departments',
            'branch_id' => 'branches',
            'work_location_id' => 'work_locations',
            'job_grade_id' => 'job_grades',
        ];
        foreach ($scopes as $field => $table) {
            if (empty($data[$field])) continue;
            $exists = $db->table($table)->where('id', (int) $data[$field])->where('company_id', $companyId)->countAllResults();
            if ($exists === 0) return $field;
        }
        return null;
    }
}
