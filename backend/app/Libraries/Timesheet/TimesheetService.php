<?php

namespace App\Libraries\Timesheet;

use Config\Database;

/**
 * Project Timesheet Service.
 *
 * Tanggung jawab:
 *  1. CRUD project + assignment.
 *  2. Submit / approve / reject timesheet entries dengan validasi:
 *     - Hours per hari ≤ 24 (cek total per work_date per employee).
 *     - Project assignment masih aktif untuk karyawan tsb.
 *     - work_date di range project (jika project punya start/end).
 *  3. Reporting:
 *     - Utilization rate per karyawan periode tertentu.
 *     - Burn rate per project (actual vs budget).
 *     - Margin per project (revenue - cost).
 *
 * Pure helpers di akhir untuk unit-test.
 */
class TimesheetService
{
    public const MAX_HOURS_PER_DAY = 24.0;
    public const HOURS_INCREMENT   = 0.25;

    public const ENTRY_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'];

    /**
     * Buat / update entry timesheet.
     */
    public function upsertEntry(
        int $companyId,
        int $employeeId,
        array $payload
    ): int {
        $errors = self::validateEntryPayloadPure($payload);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        $hours = self::roundHoursPure((float) $payload['hours']);
        $db = Database::connect();

        // Cek total jam per hari per employee — jangan melebihi 24.
        $existingTotal = $this->totalHoursOnDate(
            $companyId,
            $employeeId,
            (string) $payload['work_date'],
            isset($payload['id']) ? (int) $payload['id'] : null,
        );
        if ($existingTotal + $hours > self::MAX_HOURS_PER_DAY + 0.001) {
            throw new \RuntimeException(sprintf(
                'Total jam pada %s sudah %.2f, tambahan %.2f akan melebihi 24 jam.',
                (string) $payload['work_date'],
                $existingTotal,
                $hours,
            ));
        }

        // Cek assignment aktif
        if (! $this->hasActiveAssignment($employeeId, (int) $payload['project_id'], (string) $payload['work_date'])) {
            throw new \RuntimeException('Karyawan tidak punya assignment aktif pada project ini di tanggal tersebut.');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'      => $companyId,
            'employee_id'     => $employeeId,
            'project_id'      => (int) $payload['project_id'],
            'work_date'       => $payload['work_date'],
            'hours'           => $hours,
            'task_description'=> mb_substr((string) $payload['task_description'], 0, 500),
            'is_billable'     => isset($payload['is_billable']) ? (int) (bool) $payload['is_billable'] : 1,
            'is_overtime'     => isset($payload['is_overtime']) ? (int) (bool) $payload['is_overtime'] : 0,
            'status'          => 'DRAFT',
            'updated_at'      => $now,
        ];

        if (! empty($payload['id'])) {
            $existing = $db->table('timesheet_entries')
                ->where('id', (int) $payload['id'])
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->get()->getRowArray();
            if (! $existing) {
                throw new \RuntimeException('Entry tidak ditemukan');
            }
            if (! in_array($existing['status'], ['DRAFT', 'REJECTED'], true)) {
                throw new \RuntimeException('Entry sudah ' . $existing['status'] . ' — tidak dapat diubah');
            }
            $db->table('timesheet_entries')->where('id', (int) $payload['id'])->update($row);
            return (int) $payload['id'];
        }
        $row['created_at'] = $now;
        $db->table('timesheet_entries')->insert($row);
        return (int) $db->insertID();
    }

    public function submit(int $entryId, int $employeeId): void
    {
        $db = Database::connect();
        $entry = $db->table('timesheet_entries')
            ->where('id', $entryId)->where('employee_id', $employeeId)
            ->get()->getRowArray();
        if (! $entry) {
            throw new \RuntimeException('Entry tidak ditemukan');
        }
        if (! in_array($entry['status'], ['DRAFT', 'REJECTED'], true)) {
            throw new \RuntimeException('Entry tidak bisa di-submit dari status ' . $entry['status']);
        }
        $now = date('Y-m-d H:i:s');
        $db->table('timesheet_entries')->where('id', $entryId)->update([
            'status'       => 'SUBMITTED',
            'submitted_at' => $now,
            'updated_at'   => $now,
        ]);
    }

    public function approve(int $entryId, int $approverUserId): void
    {
        $db = Database::connect();
        $entry = $db->table('timesheet_entries')->where('id', $entryId)->get()->getRowArray();
        if (! $entry) throw new \RuntimeException('Entry tidak ditemukan');
        if ($entry['status'] !== 'SUBMITTED') {
            throw new \RuntimeException('Hanya entry SUBMITTED yang bisa di-approve');
        }
        $now = date('Y-m-d H:i:s');
        $db->table('timesheet_entries')->where('id', $entryId)->update([
            'status'           => 'APPROVED',
            'approver_user_id' => $approverUserId,
            'approved_at'      => $now,
            'updated_at'       => $now,
        ]);
    }

    public function reject(int $entryId, int $approverUserId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason wajib diisi saat reject');
        }
        $db = Database::connect();
        $entry = $db->table('timesheet_entries')->where('id', $entryId)->get()->getRowArray();
        if (! $entry) throw new \RuntimeException('Entry tidak ditemukan');
        if ($entry['status'] !== 'SUBMITTED') {
            throw new \RuntimeException('Hanya entry SUBMITTED yang bisa di-reject');
        }
        $now = date('Y-m-d H:i:s');
        $db->table('timesheet_entries')->where('id', $entryId)->update([
            'status'           => 'REJECTED',
            'approver_user_id' => $approverUserId,
            'rejection_reason' => mb_substr($reason, 0, 500),
            'updated_at'       => $now,
        ]);
    }

    public function totalHoursOnDate(int $companyId, int $employeeId, string $date, ?int $excludeEntryId = null): float
    {
        $b = Database::connect()->table('timesheet_entries')
            ->selectSum('hours', 'total')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->whereNotIn('status', ['REJECTED']);
        if ($excludeEntryId !== null) {
            $b->where('id !=', $excludeEntryId);
        }
        $row = $b->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }

    public function hasActiveAssignment(int $employeeId, int $projectId, string $workDate): bool
    {
        $db = Database::connect();
        $row = $db->table('project_assignments')
            ->where('employee_id', $employeeId)
            ->where('project_id', $projectId)
            ->where('is_active', 1)
            ->groupStart()
                ->where('starts_on', null)
                ->orWhere('starts_on <=', $workDate)
            ->groupEnd()
            ->groupStart()
                ->where('ends_on', null)
                ->orWhere('ends_on >=', $workDate)
            ->groupEnd()
            ->get()->getRowArray();
        return $row !== null && $row !== [];
    }

    /**
     * Utilization per karyawan: billable/total.
     *
     * @return array{
     *   employee_id: int,
     *   total_hours: float,
     *   billable_hours: float,
     *   utilization_pct: float,
     *   by_project: array<int, array<string,mixed>>
     * }
     */
    public function utilizationFor(
        int $companyId,
        int $employeeId,
        string $from,
        string $to
    ): array {
        $db = Database::connect();
        $rows = $db->table('timesheet_entries')
            ->select('project_id, SUM(hours) AS total_hours, SUM(CASE WHEN is_billable=1 THEN hours ELSE 0 END) AS billable_hours')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['SUBMITTED', 'APPROVED'])
            ->where('work_date >=', $from)
            ->where('work_date <=', $to)
            ->groupBy('project_id')
            ->get()->getResultArray();

        $totalH = 0.0;
        $billH  = 0.0;
        foreach ($rows as $r) {
            $totalH += (float) $r['total_hours'];
            $billH  += (float) $r['billable_hours'];
        }
        return [
            'employee_id'     => $employeeId,
            'total_hours'     => round($totalH, 2),
            'billable_hours'  => round($billH, 2),
            'utilization_pct' => self::utilizationPctPure($billH, $totalH),
            'by_project'      => $rows,
        ];
    }

    /**
     * Burn rate per project: actual hours/cost vs budget.
     */
    public function burnRateForProject(int $companyId, int $projectId): array
    {
        $db = Database::connect();
        $project = $db->table('projects')
            ->where('id', $projectId)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $project) {
            return ['error' => 'Project tidak ditemukan'];
        }

        // Aggregate hours dari entries APPROVED + assignments untuk cost rate
        $entries = $db->table('timesheet_entries')
            ->select('employee_id, SUM(hours) AS total_hours, SUM(CASE WHEN is_billable=1 THEN hours ELSE 0 END) AS billable_hours')
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->where('status', 'APPROVED')
            ->groupBy('employee_id')
            ->get()->getResultArray();

        $assignments = $db->table('project_assignments')
            ->where('project_id', $projectId)
            ->get()->getResultArray();
        $assignMap = [];
        foreach ($assignments as $a) {
            $assignMap[(int) $a['employee_id']] = $a;
        }

        $totalHours = 0.0;
        $billHours  = 0.0;
        $costAmt    = 0.0;
        $revenueAmt = 0.0;
        foreach ($entries as $e) {
            $empId = (int) $e['employee_id'];
            $h     = (float) $e['total_hours'];
            $bh    = (float) $e['billable_hours'];
            $totalHours += $h;
            $billHours  += $bh;
            $assign = $assignMap[$empId] ?? null;
            if ($assign) {
                $costAmt    += $h  * (float) ($assign['cost_rate']     ?? 0);
                $revenueAmt += $bh * (float) ($assign['billable_rate'] ?? 0);
            }
        }

        $budgetH = (float) ($project['budget_hours']  ?? 0);
        $budgetA = (float) ($project['budget_amount'] ?? 0);

        return [
            'project'        => $project,
            'total_hours'    => round($totalHours, 2),
            'billable_hours' => round($billHours, 2),
            'cost_amount'    => round($costAmt, 2),
            'revenue_amount' => round($revenueAmt, 2),
            'margin_amount'  => round($revenueAmt - $costAmt, 2),
            'margin_pct'     => self::marginPctPure($revenueAmt, $costAmt),
            'hours_burn_pct' => self::burnPctPure($totalHours, $budgetH),
            'cost_burn_pct'  => self::burnPctPure($costAmt, $budgetA),
        ];
    }

    // ─── PURE HELPERS ──────────────────────────────────────────────────

    /**
     * Round hours ke kelipatan 0.25 ke atas (ceil ke 15 menit terdekat).
     * Industri standard untuk billable timesheet — kalau user log waktu
     * sekecil apapun, charge minimal 15 menit. Misal 0.1 hour (6 menit)
     * → 0.25 (15 menit), 0.3 hour → 0.5 (30 menit).
     */
    public static function roundHoursPure(float $hours): float
    {
        if ($hours <= 0) return 0.0;
        return round(ceil($hours / self::HOURS_INCREMENT) * self::HOURS_INCREMENT, 2);
    }

    /**
     * @return string[] error list
     */
    public static function validateEntryPayloadPure(array $payload): array
    {
        $errors = [];
        if (empty($payload['project_id']) || (int) $payload['project_id'] <= 0) {
            $errors[] = 'project_id wajib';
        }
        if (empty($payload['work_date']) || strtotime((string) $payload['work_date']) === false) {
            $errors[] = 'work_date wajib & format YYYY-MM-DD';
        }
        $hours = (float) ($payload['hours'] ?? 0);
        if ($hours <= 0) {
            $errors[] = 'hours harus > 0';
        }
        if ($hours > self::MAX_HOURS_PER_DAY) {
            $errors[] = 'hours tidak boleh > 24';
        }
        if (empty($payload['task_description']) || mb_strlen((string) $payload['task_description']) < 3) {
            $errors[] = 'task_description minimal 3 karakter';
        }
        // Future date guard: tidak boleh entry untuk masa depan > 1 hari
        if (! empty($payload['work_date'])) {
            $ts = strtotime((string) $payload['work_date']);
            if ($ts !== false && $ts > strtotime('+1 day')) {
                $errors[] = 'work_date tidak boleh di masa depan';
            }
        }
        return $errors;
    }

    public static function utilizationPctPure(float $billable, float $total): float
    {
        if ($total <= 0) return 0.0;
        return round(($billable / $total) * 100, 2);
    }

    public static function burnPctPure(float $actual, float $budget): float
    {
        if ($budget <= 0) return 0.0;
        return round(($actual / $budget) * 100, 2);
    }

    public static function marginPctPure(float $revenue, float $cost): float
    {
        if ($revenue <= 0) return 0.0;
        return round((($revenue - $cost) / $revenue) * 100, 2);
    }
}