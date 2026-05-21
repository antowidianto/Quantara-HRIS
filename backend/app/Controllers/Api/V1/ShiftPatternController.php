<?php

namespace App\Controllers\Api\V1;

use Config\Services;

/**
 * ShiftPatternController
 *
 * CRUD untuk pola siklus shift + assignment-nya ke scope tertentu.
 * Pattern adalah array sepanjang `cycle_days`, indeks 0..(cycle_days-1).
 * Setiap slot:
 *   { "shift_id": int|null, "is_off": bool, "is_holiday": bool, "label": string? }
 *
 * Contoh pola "5-2 Pagi":
 *   cycle_days = 7
 *   pattern_data = [
 *     {shift_id:1}, {shift_id:1}, {shift_id:1}, {shift_id:1}, {shift_id:1},
 *     {is_off:true}, {is_off:true}
 *   ]
 *
 * Contoh "3 Shift Pabrik" (siklus 21 hari, 7 hari per shift, tanpa off):
 *   cycle_days = 21
 *   pattern_data = [Pagi×7, Sore×7, Malam×7]
 */
class ShiftPatternController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $search = trim((string) $this->request->getGet('search'));
        $isActive = $this->request->getGet('is_active');

        $builder = $db->table('shift_patterns p')
            ->select('p.*, (SELECT COUNT(*) FROM shift_pattern_assignments spa WHERE spa.pattern_id = p.id AND spa.is_active = 1) AS assignment_count')
            ->where('p.deleted_at IS NULL');
        $this->tenant()->applyScope($builder, 'p.company_id');

        if ($search !== '') {
            $builder->groupStart()->like('p.name', $search)->orLike('p.code', $search)->groupEnd();
        }
        if ($isActive !== null && $isActive !== '') $builder->where('p.is_active', (int) $isActive);

        $rows = $builder->orderBy('p.name', 'ASC')->get()->getResultArray();
        // Decode JSON pattern_data agar siap pakai di FE.
        foreach ($rows as &$r) {
            $r['pattern_data'] = $r['pattern_data'] ? json_decode((string) $r['pattern_data'], true) : [];
        }
        unset($r);
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('shift_patterns')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $row['pattern_data'] = $row['pattern_data'] ? json_decode((string) $row['pattern_data'], true) : [];

        // Sertakan assignments + nama referensi-nya
        $assignments = $db->table('shift_pattern_assignments spa')
            ->select('spa.*, e.full_name AS employee_name, b.name AS branch_name, d.name AS department_name, w.name AS work_location_name, g.name AS grade_name')
            ->join('employees e', 'e.id = spa.employee_id', 'left')
            ->join('branches b', 'b.id = spa.branch_id', 'left')
            ->join('departments d', 'd.id = spa.department_id', 'left')
            ->join('work_locations w', 'w.id = spa.work_location_id', 'left')
            ->join('job_grades g', 'g.id = spa.job_grade_id', 'left')
            ->where('spa.pattern_id', (int) $id)
            ->orderBy('spa.priority', 'ASC')
            ->get()->getResultArray();
        $row['assignments'] = $assignments;

        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'code'       => 'required|max_length[64]',
            'name'       => 'required|max_length[200]',
            'cycle_days' => 'required|integer|greater_than[0]|less_than[366]',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $patternData = $this->normalizePatternData($data['pattern_data'] ?? [], (int) $data['cycle_days']);

        $db = \Config\Database::connect();
        $exists = $db->table('shift_patterns')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->where('deleted_at IS NULL')
            ->countAllResults();
        if ($exists > 0) return $this->fail('Kode pola sudah ada', 422, 'DUPLICATE_CODE');

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'   => $companyId,
            'code'         => $data['code'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'cycle_days'   => (int) $data['cycle_days'],
            'pattern_data' => json_encode($patternData, JSON_UNESCAPED_UNICODE),
            'is_active'    => (int) ($data['is_active'] ?? 1),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $db->table('shift_patterns')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('MASTER')
            ->logCreate('shift_pattern', $id, $insert, "Membuat pola shift: {$insert['name']}");
        return $this->ok(['id' => $id], 'Pola shift dibuat', 201);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('shift_patterns')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }

        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip([
            'name','description','cycle_days','is_active',
        ]));
        if (array_key_exists('pattern_data', $data)) {
            $cycle = (int) ($data['cycle_days'] ?? $existing['cycle_days']);
            $update['pattern_data'] = json_encode(
                $this->normalizePatternData($data['pattern_data'], $cycle),
                JSON_UNESCAPED_UNICODE
            );
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('shift_patterns')->where('id', $id)->update($update);

        Services::audit()
            ->forModule('MASTER')
            ->logUpdate('shift_pattern', $id,
                array_intersect_key($existing, $update), $update,
                "Mengubah pola shift: {$existing['name']}");
        return $this->ok(['id' => $id], 'Updated');
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('shift_patterns')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('shift_patterns')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ]);
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('shift_pattern', $id, $existing,
                "Menghapus pola shift: {$existing['name']}");
        return $this->ok(null, 'Deleted');
    }

    // ─── Pattern assignments ────────────────────────────────────────

    public function listAssignments()
    {
        $db = \Config\Database::connect();
        $patternId = (int) ($this->request->getGet('pattern_id') ?? 0);
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $builder = $db->table('shift_pattern_assignments spa')
            ->select('spa.*, p.name AS pattern_name, p.code AS pattern_code,'
                . ' e.full_name AS employee_name, b.name AS branch_name,'
                . ' d.name AS department_name, w.name AS work_location_name,'
                . ' g.name AS grade_name')
            ->join('shift_patterns p', 'p.id = spa.pattern_id', 'left')
            ->join('employees e', 'e.id = spa.employee_id', 'left')
            ->join('branches b', 'b.id = spa.branch_id', 'left')
            ->join('departments d', 'd.id = spa.department_id', 'left')
            ->join('work_locations w', 'w.id = spa.work_location_id', 'left')
            ->join('job_grades g', 'g.id = spa.job_grade_id', 'left');
        $this->tenant()->applyScope($builder, 'spa.company_id');
        if ($patternId)  $builder->where('spa.pattern_id', $patternId);
        if ($employeeId) $builder->where('spa.employee_id', $employeeId);
        $rows = $builder->orderBy('spa.priority', 'ASC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createAssignment()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'pattern_id'  => 'required|integer',
            'date_from'   => 'required|valid_date',
            'anchor_date' => 'permit_empty|valid_date',
            'date_to'     => 'permit_empty|valid_date',
            'priority'    => 'permit_empty|integer',
        ]);
        if (! $valid) return null;

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $db = \Config\Database::connect();
        $pattern = $db->table('shift_patterns')->where('id', (int) $data['pattern_id'])->get()->getRowArray();
        if (! $pattern) return $this->fail('Pola tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $pattern['company_id'] !== $companyId) {
            return $this->fail('Pola di luar perusahaan Anda', 403);
        }

        // Validasi: minimal salah satu scope diisi.
        $hasScope = ! empty($data['employee_id'])
            || ! empty($data['department_id'])
            || ! empty($data['branch_id'])
            || ! empty($data['work_location_id'])
            || ! empty($data['job_grade_id']);
        if (! $hasScope) {
            return $this->fail('Minimal pilih salah satu: employee, department, branch, work_location, atau job_grade.', 422, 'SCOPE_REQUIRED');
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'company_id'      => $companyId,
            'pattern_id'      => (int) $data['pattern_id'],
            'employee_id'     => $data['employee_id'] ?? null,
            'department_id'   => $data['department_id'] ?? null,
            'branch_id'       => $data['branch_id'] ?? null,
            'work_location_id'=> $data['work_location_id'] ?? null,
            'job_grade_id'    => $data['job_grade_id'] ?? null,
            'anchor_date'     => $data['anchor_date'] ?? $data['date_from'],
            'date_from'       => $data['date_from'],
            'date_to'         => $data['date_to'] ?? null,
            'priority'        => (int) ($data['priority'] ?? 100),
            'is_active'       => (int) ($data['is_active'] ?? 1),
            'created_by'      => (int) $this->userId(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $db->table('shift_pattern_assignments')->insert($insert);
        $id = (int) $db->insertID();

        Services::audit()
            ->forModule('MASTER')
            ->logCreate('shift_pattern_assignment', $id, $insert,
                "Memetakan pola {$pattern['name']} ke scope tertentu");
        return $this->ok(['id' => $id], 'Pattern assignment dibuat', 201);
    }

    public function deleteAssignment($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('shift_pattern_assignments')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $existing['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $db->table('shift_pattern_assignments')->where('id', $id)->delete();
        Services::audit()
            ->forModule('MASTER')
            ->logDelete('shift_pattern_assignment', $id, $existing,
                "Menghapus pattern assignment #{$id}");
        return $this->ok(null, 'Deleted');
    }

    /**
     * Pastikan pattern_data berbentuk array dengan tepat $cycleDays slot.
     * Kalau kurang → padding dengan {is_off:true}; kalau lebih → potong.
     */
    private function normalizePatternData($input, int $cycleDays): array
    {
        if (! is_array($input)) $input = [];
        $out = [];
        for ($i = 0; $i < $cycleDays; $i++) {
            $slot = $input[$i] ?? null;
            if (! is_array($slot)) {
                $out[] = ['shift_id' => null, 'is_off' => true, 'is_holiday' => false];
                continue;
            }
            $out[] = [
                'shift_id'   => isset($slot['shift_id']) && $slot['shift_id'] !== '' ? (int) $slot['shift_id'] : null,
                'is_off'     => ! empty($slot['is_off']),
                'is_holiday' => ! empty($slot['is_holiday']),
                'label'      => isset($slot['label']) ? (string) $slot['label'] : null,
            ];
        }
        return $out;
    }
}