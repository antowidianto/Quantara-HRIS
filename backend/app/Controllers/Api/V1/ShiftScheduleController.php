<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class ShiftScheduleController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);
        $month = (string) ($this->request->getGet('month') ?? date('Y-m'));
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $builder = $db->table('shift_schedules ss')
            ->select('ss.*, s.code AS shift_code, s.name AS shift_name, s.start_time, s.end_time, e.full_name AS employee_name')
            ->join('shifts s', 's.id = ss.shift_id', 'left')
            ->join('employees e', 'e.id = ss.employee_id');
        $this->tenant()->applyScope($builder, 'ss.company_id');
        if ($employeeId) $builder->where('ss.employee_id', $employeeId);
        $builder->where('ss.work_date >=', $start)->where('ss.work_date <=', $end);

        $rows = $builder->orderBy('ss.work_date')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('shift_schedules')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();

        // Bulk: { schedules: [{employee_id, shift_id, work_date}, ...] }
        if (isset($data['schedules']) && is_array($data['schedules'])) {
            return $this->bulkCreate($data['schedules']);
        }

        $valid = $this->validateOrFail($data, [
            'employee_id' => 'required|integer',
            'work_date'   => 'required|valid_date',
        ]);
        if (! $valid) return null;

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $data['employee_id'])->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $existing = $db->table('shift_schedules')
            ->where('employee_id', $data['employee_id'])
            ->where('work_date', $data['work_date'])
            ->get()->getRowArray();

        $row = [
            'company_id'  => $emp['company_id'],
            'employee_id' => $data['employee_id'],
            'shift_id'    => $data['shift_id'] ?? null,
            'work_date'   => $data['work_date'],
            'is_off'      => $data['is_off'] ?? 0,
            'is_holiday'  => $data['is_holiday'] ?? 0,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $db->table('shift_schedules')->where('id', $existing['id'])->update($row);
            return $this->ok(['id' => $existing['id']], 'Updated');
        }
        $row['created_at'] = date('Y-m-d H:i:s');
        $db->table('shift_schedules')->insert($row);
        return $this->ok(['id' => $db->insertID()], 'Created', 201);
    }

    private function bulkCreate(array $schedules)
    {
        $db = \Config\Database::connect();
        $companyId = $this->companyId();
        $count = 0;
        foreach ($schedules as $s) {
            if (empty($s['employee_id']) || empty($s['work_date'])) continue;
            $existing = $db->table('shift_schedules')
                ->where('employee_id', $s['employee_id'])
                ->where('work_date', $s['work_date'])
                ->get()->getRowArray();
            $row = [
                'company_id'  => $companyId,
                'employee_id' => $s['employee_id'],
                'shift_id'    => $s['shift_id'] ?? null,
                'work_date'   => $s['work_date'],
                'is_off'      => $s['is_off'] ?? 0,
                'is_holiday'  => $s['is_holiday'] ?? 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $db->table('shift_schedules')->where('id', $existing['id'])->update($row);
            } else {
                $row['created_at'] = date('Y-m-d H:i:s');
                $db->table('shift_schedules')->insert($row);
            }
            $count++;
        }
        return $this->ok(['count' => $count], 'Bulk schedule applied');
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $data = $this->getJsonInput();
        $update = array_intersect_key($data, array_flip(['shift_id', 'is_off', 'is_holiday']));
        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('shift_schedules')->where('id', $id)->update($update);
        return $this->ok(null, 'Updated');
    }

    public function delete($id = null)
    {
        $db = \Config\Database::connect();
        $db->table('shift_schedules')->where('id', (int) $id)->delete();
        return $this->ok(null, 'Deleted');
    }
}