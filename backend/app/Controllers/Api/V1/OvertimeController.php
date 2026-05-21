<?php

namespace App\Controllers\Api\V1;

use Config\Services;

class OvertimeController extends ApiController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $status = trim((string) $this->request->getGet('status'));
        $employeeId = (int) ($this->request->getGet('employee_id') ?? 0);

        $builder = $db->table('overtimes o')
            ->select('o.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = o.employee_id');
        $this->tenant()->applyScope($builder, 'o.company_id');

        if ($employeeId) $builder->where('o.employee_id', $employeeId);
        if ($status) $builder->where('o.status', $status);

        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('o.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show($id = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('overtimes')->where('id', (int) $id)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404);
        return $this->ok($row);
    }

    public function create()
    {
        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'work_date'  => 'required|valid_date',
            'start_time' => 'required',
            'end_time'   => 'required',
            'reason'     => 'required|max_length[1000]',
        ]);
        if (! $valid) return null;

        $employeeId = (int) ($data['employee_id'] ?? $this->tenant()->getEmployeeId());
        if (! $employeeId) return $this->fail('Employee not specified', 400);

        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) return $this->fail('Employee not found', 404);

        $startTs = strtotime($data['start_time']);
        $endTs   = strtotime($data['end_time']);
        if ($endTs <= $startTs) return $this->fail('End time must be after start time', 422);
        $hours = round(($endTs - $startTs) / 3600, 2);

        // Determine day type
        $dow = (int) date('N', strtotime($data['work_date']));
        $dayType = 'WEEKDAY';
        if ($dow >= 6) $dayType = 'WEEKEND';
        $isHoliday = $db->table('holidays')
            ->groupStart()
                ->where('company_id', $emp['company_id'])
                ->orWhere('company_id IS NULL')
            ->groupEnd()
            ->where('date', $data['work_date'])
            ->countAllResults();
        if ($isHoliday > 0) $dayType = 'HOLIDAY';

        // Compensation type: CASH (default, dibayar uang) atau LEAVE (cuti pengganti)
        $compType = strtoupper((string) ($data['compensation_type'] ?? 'CASH'));
        if (! in_array($compType, ['CASH', 'LEAVE'], true)) $compType = 'CASH';

        // Cek setting per company kalau LEAVE — kalau disabled, fallback ke CASH
        if ($compType === 'LEAVE') {
            $enabled = '1';
            if ($db->tableExists('settings')) {
                $st = $db->table('settings')->where('company_id', (int) $emp['company_id'])
                    ->where('`key`', 'overtime_to_leave_enabled')->get()->getRowArray();
                if ($st && (string) $st['value'] === '0') $enabled = '0';
            }
            if ($enabled !== '1') $compType = 'CASH';
        }

        $reqNumber = 'OT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $insert = [
            'company_id'        => $emp['company_id'],
            'employee_id'       => $employeeId,
            'request_number'    => $reqNumber,
            'work_date'         => $data['work_date'],
            'start_time'        => $data['start_time'],
            'end_time'          => $data['end_time'],
            'hours'             => $hours,
            'day_type'          => $dayType,
            'reason'            => $data['reason'],
            'status'            => 'PENDING',
            'compensation_type' => $compType,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        $db->table('overtimes')->insert($insert);
        $id = (int) $db->insertID();

        // Approval chain (kalau dikonfigurasi). Engine kirim notifikasi ke
        // approver step pertama; fallback ke role HR/Manager kalau tidak ada
        // chain.
        try {
            $approvalRes = Services::approval()->start([
                'company_id'   => (int) $emp['company_id'],
                'module'       => 'OVERTIME',
                'entity_table' => 'overtimes',
                'entity_id'    => $id,
                'employee_id'  => $employeeId,
                'meta'         => ['hours' => $hours, 'day_type' => $dayType],
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'ApprovalEngine.start failed: ' . $e->getMessage());
            $approvalRes = null;
        }
        if (! $approvalRes || empty($approvalRes['next_approvers'])) {
            $approvers = $db->table('users')->where('company_id', $emp['company_id'])->whereIn('role_id', [2, 3])->get()->getResultArray();
            foreach ($approvers as $u) {
                Services::notification()->send((int) $u['id'], (int) $emp['company_id'], 'overtime.requested',
                    'Pengajuan lembur baru', "{$emp['full_name']} mengajukan lembur {$hours} jam pada {$data['work_date']}",
                    ['overtime_id' => $id], '/overtimes/' . $id, true);
            }
        }

        Services::audit()
            ->forModule('OVERTIME')
            ->logCreate(
                'overtime',
                $id,
                $insert,
                "Pengajuan lembur: {$emp['full_name']} {$hours} jam pada {$data['work_date']} ({$dayType})"
            );
        return $this->ok([
            'id' => $id,
            'request_number' => $reqNumber,
            'hours' => $hours,
            'approval' => $approvalRes,
        ], 'Overtime requested', 201);
    }

    public function update($id = null)
    {
        return $this->fail('Cannot update overtime requests directly', 405);
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $existing = $db->table('overtimes')->where('id', $id)->get()->getRowArray();
        if (! $existing) return $this->fail('Not found', 404);
        if ($existing['status'] !== 'PENDING') return $this->fail('Can only cancel pending overtime', 422);
        $db->table('overtimes')->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Cancelled');
    }

    public function approve($id = null)
    {
        $id = (int) $id;
        $db = \Config\Database::connect();
        $ot = $db->table('overtimes')->where('id', $id)->get()->getRowArray();
        if (! $ot) return $this->fail('Not found', 404);
        if ($ot['status'] !== 'PENDING') return $this->fail('Not pending', 422);

        $emp = $db->table('employees')->where('id', $ot['employee_id'])->get()->getRowArray();
        $compType = strtoupper((string) ($ot['compensation_type'] ?? 'CASH'));

        $update = [
            'status'       => 'APPROVED',
            'approved_by'  => $this->userId(),
            'approved_at'  => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $description = '';
        $amount = 0.0;

        if ($compType === 'LEAVE') {
            // Konversi jam lembur ke jam cuti pengganti
            $ratio = 1.0;
            if ($db->tableExists('settings')) {
                $st = $db->table('settings')->where('company_id', (int) $ot['company_id'])
                    ->where('`key`', 'overtime_to_leave_ratio')->get()->getRowArray();
                if ($st && (float) $st['value'] > 0) $ratio = (float) $st['value'];
            }
            $hours = (float) $ot['hours'];
            $leaveHours = round($hours * $ratio, 2);

            // Cari leave_type CPL (source=OVERTIME_COMP) untuk company ini
            $leaveType = $db->table('leave_types')
                ->where('company_id', (int) $ot['company_id'])
                ->where('source', 'OVERTIME_COMP')
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
            if (! $leaveType) {
                return $this->fail('Leave type "Cuti Pengganti Lembur" belum dikonfigurasi. Hubungi admin.', 422);
            }

            // Cek max days per tahun (kalau ada setting)
            $maxDaysYear = 12;
            if ($db->tableExists('settings')) {
                $st = $db->table('settings')->where('company_id', (int) $ot['company_id'])
                    ->where('`key`', 'overtime_to_leave_max_days_year')->get()->getRowArray();
                if ($st && (int) $st['value'] > 0) $maxDaysYear = (int) $st['value'];
            }

            $year = (int) date('Y', strtotime((string) $ot['work_date']));
            // Hari kerja standar: 8 jam = 1 hari cuti
            $hoursPerDay = 8;

            // Ensure balance row ada
            $balance = $db->table('leave_balances')
                ->where('employee_id', (int) $ot['employee_id'])
                ->where('leave_type_id', (int) $leaveType['id'])
                ->where('period_year', $year)
                ->get()->getRowArray();

            if (! $balance) {
                $db->table('leave_balances')->insert([
                    'company_id'    => (int) $ot['company_id'],
                    'employee_id'   => (int) $ot['employee_id'],
                    'leave_type_id' => (int) $leaveType['id'],
                    'period_year'   => $year,
                    'allocated'     => 0,
                    'carried_over'  => 0,
                    'used'          => 0,
                    'remaining'     => 0,
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
                $balanceId = (int) $db->insertID();
                $balance = $db->table('leave_balances')->where('id', $balanceId)->get()->getRowArray();
            }

            // Cek total accumulated tahun ini (allocated source OT) — pakai
            // allocated + leaveHours baru (semua dlm satuan jam → hari)
            $newAllocatedHours = (float) $balance['allocated'] * $hoursPerDay + $leaveHours;
            $maxHoursYear = $maxDaysYear * $hoursPerDay;
            if ($newAllocatedHours > $maxHoursYear) {
                $availableHours = max(0, $maxHoursYear - (float) $balance['allocated'] * $hoursPerDay);
                if ($availableHours <= 0) {
                    return $this->fail("Quota cuti pengganti tahun {$year} sudah mencapai max {$maxDaysYear} hari", 422);
                }
                $leaveHours = $availableHours;
            }

            // Tambah ke balance — convert jam ke hari (allocated dlm satuan hari)
            $addDays = round($leaveHours / $hoursPerDay, 2);
            $newAllocated = (float) $balance['allocated'] + $addDays;
            $newRemaining = $newAllocated + (float) $balance['carried_over'] - (float) $balance['used'];
            $db->table('leave_balances')->where('id', (int) $balance['id'])->update([
                'allocated' => $newAllocated,
                'remaining' => $newRemaining,
                'updated_at'=> date('Y-m-d H:i:s'),
            ]);

            $update['leave_balance_id'] = (int) $balance['id'];
            $update['leave_hours_credited'] = $leaveHours;
            $update['leave_conversion_ratio'] = $ratio;
            $update['computed_amount'] = 0;
            $description = "Menyetujui lembur #{$ot['request_number']} ({$hours} jam → {$addDays} hari cuti pengganti)";
        } else {
            // CASH (behaviour lama)
            $base = (float) $emp['basic_salary'];
            $calc = new \App\Libraries\OvertimeCalculator();
            $amount = $calc->calculate($base, (float) $ot['hours'], $ot['day_type']);
            $update['computed_amount'] = $amount;
            $description = "Menyetujui lembur #{$ot['request_number']} ({$ot['hours']} jam = Rp " . number_format($amount, 0, ',', '.') . ")";
        }

        $db->table('overtimes')->where('id', $id)->update($update);

        if ($emp['user_id']) {
            $msg = $compType === 'LEAVE'
                ? "Lembur Anda disetujui dan dikonversi ke {$update['leave_hours_credited']} jam cuti pengganti"
                : 'Pengajuan lembur Anda telah disetujui';
            Services::notification()->send((int) $emp['user_id'], (int) $ot['company_id'], 'overtime.approved',
                'Lembur disetujui', $msg,
                ['overtime_id' => $id], '/overtimes/' . $id, true);
        }
        Services::audit()->forModule('OVERTIME')->logApprove('overtime', $id, $ot, $description);

        $resp = ['compensation_type' => $compType];
        if ($compType === 'LEAVE') {
            $resp['leave_hours_credited'] = $update['leave_hours_credited'];
            $resp['leave_balance_id'] = $update['leave_balance_id'];
        } else {
            $resp['amount'] = $amount;
        }
        return $this->ok($resp, 'Approved');
    }

    public function reject($id = null)
    {
        $id = (int) $id;
        $data = $this->getJsonInput();
        $db = \Config\Database::connect();
        $ot = $db->table('overtimes')->where('id', $id)->get()->getRowArray();
        if (! $ot) return $this->fail('Not found', 404);
        if ($ot['status'] !== 'PENDING') return $this->fail('Not pending', 422);

        $db->table('overtimes')->where('id', $id)->update([
            'status' => 'REJECTED',
            'approved_by' => $this->userId(),
            'approved_at' => date('Y-m-d H:i:s'),
            'rejection_note' => $data['reason'] ?? '',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        Services::audit()
            ->forModule('OVERTIME')
            ->logReject('overtime', $id, $ot, $data['reason'] ?? '(tanpa alasan)');
        return $this->ok(null, 'Rejected');
    }
}