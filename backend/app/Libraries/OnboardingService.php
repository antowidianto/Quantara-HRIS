<?php

namespace App\Libraries;

use Config\Services;

/**
 * OnboardingService
 *
 * Logika domain Onboarding & Offboarding tasks.
 *
 * Tanggung jawab:
 *   1. `applyTemplate($employeeId, $templateId|$type, $anchorDate)` —
 *      generate `employee_tasks` dari `task_templates.tasks` JSON, dengan
 *      `due_date = anchorDate + due_offset_days` per item.
 *   2. `resolveAssignee($task, $employee)` — petakan owner_role ke user
 *      konkret (HR → role 2, IT → permission khusus, SUPERVISOR → atasan
 *      langsung, EMPLOYEE → user karyawan ybs).
 *   3. `progress($employeeId, $type)` — total, completed, mandatory, pct.
 *   4. `notifyAssignee($task)` — kirim notifikasi ke user yang ditugaskan.
 *
 * Owner role yang dikenal:
 *   - HR        → semua user dengan role hr_admin (role 2)
 *   - IT        → user dengan slug 'admin_it' (kalau ada) atau role hr_admin
 *   - FINANCE   → role 5 (finance)
 *   - SUPERVISOR→ atasan langsung karyawan (employee.supervisor_id → user)
 *   - MANAGER   → manager karyawan (employee.manager_id → user)
 *   - EMPLOYEE  → user karyawan ybs
 */
class OnboardingService
{
    /**
     * Apply template ke karyawan. Idempotent: kalau task dengan
     * (employee_id, type, task_key) sudah ada, akan di-skip — tidak duplikasi.
     *
     * @param int      $employeeId
     * @param int|null $templateId  Kalau null, otomatis pakai default per type.
     * @param string   $type        ONBOARD|OFFBOARD
     * @param string   $anchorDate  Tanggal acuan untuk hitung due_date (Y-m-d)
     * @return array {created: int, skipped: int, tasks: array}
     */
    public function applyTemplate(int $employeeId, ?int $templateId, string $type, string $anchorDate): array
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
        if (! $emp) {
            throw new \InvalidArgumentException("Employee #{$employeeId} tidak ditemukan");
        }
        if (! in_array($type, ['ONBOARD', 'OFFBOARD'], true)) {
            throw new \InvalidArgumentException("Type harus ONBOARD atau OFFBOARD");
        }

        // Resolve template
        $template = null;
        if ($templateId) {
            $template = $db->table('task_templates')
                ->where('id', $templateId)
                ->where('company_id', (int) $emp['company_id'])
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->get()->getRowArray();
        }
        if (! $template) {
            $template = $db->table('task_templates')
                ->where('company_id', (int) $emp['company_id'])
                ->where('type', $type)
                ->where('is_active', 1)
                ->where('deleted_at IS NULL')
                ->orderBy('is_default', 'DESC')
                ->orderBy('id', 'ASC')
                ->limit(1)
                ->get()->getRowArray();
        }
        if (! $template) {
            return ['created' => 0, 'skipped' => 0, 'tasks' => [], 'note' => 'Tidak ada template aktif untuk type ' . $type];
        }

        $tasksJson = $template['tasks'] ?? '[]';
        $tasks = is_string($tasksJson) ? (json_decode($tasksJson, true) ?: []) : (array) $tasksJson;
        if (empty($tasks)) {
            return ['created' => 0, 'skipped' => 0, 'tasks' => [], 'note' => 'Template kosong'];
        }

        $now = date('Y-m-d H:i:s');
        $created = 0; $skipped = 0;
        $output = [];
        $sortOrder = 0;
        foreach ($tasks as $t) {
            $sortOrder++;
            $key = (string) ($t['key'] ?? '');
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') continue;

            // Idempotent: skip kalau task dengan key+type+employee sudah ada
            // (kecuali key kosong → selalu create)
            if ($key !== '') {
                $exists = $db->table('employee_tasks')
                    ->where('employee_id', $employeeId)
                    ->where('type', $type)
                    ->where('task_key', $key)
                    ->where('deleted_at IS NULL')
                    ->countAllResults();
                if ($exists > 0) { $skipped++; continue; }
            }

            $offset = (int) ($t['due_offset_days'] ?? 0);
            $due = date('Y-m-d', strtotime($anchorDate . " +{$offset} days"));
            $assigneeId = $this->resolveAssignee((string) ($t['owner_role'] ?? ''), $emp);

            $insert = [
                'company_id'      => (int) $emp['company_id'],
                'employee_id'     => $employeeId,
                'template_id'     => (int) $template['id'],
                'type'            => $type,
                'task_key'        => $key !== '' ? $key : null,
                'task_name'       => $name,
                'description'     => $t['description'] ?? null,
                'owner_role'      => $t['owner_role'] ?? null,
                'assignee_user_id'=> $assigneeId,
                'due_date'        => $due,
                'mandatory'       => isset($t['mandatory']) ? (int) (bool) $t['mandatory'] : 1,
                'sort_order'      => $sortOrder,
                'status'          => 'PENDING',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
            $db->table('employee_tasks')->insert($insert);
            $insert['id'] = (int) $db->insertID();
            $output[] = $insert;
            $created++;

            // Notifikasi ke assignee
            if ($assigneeId) {
                $this->notifyAssignee($insert);
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'tasks' => $output, 'template' => $template];
    }

    /**
     * Resolve owner_role → user_id konkret.
     */
    public function resolveAssignee(string $ownerRole, array $employee): ?int
    {
        $db = \Config\Database::connect();
        $companyId = (int) $employee['company_id'];
        $role = strtoupper($ownerRole);

        switch ($role) {
            case 'EMPLOYEE':
                return ! empty($employee['user_id']) ? (int) $employee['user_id'] : null;

            case 'SUPERVISOR':
                if (! empty($employee['supervisor_id'])) {
                    $sup = $db->table('employees')->where('id', (int) $employee['supervisor_id'])->get()->getRowArray();
                    if ($sup && ! empty($sup['user_id'])) return (int) $sup['user_id'];
                }
                return null;

            case 'MANAGER':
                if (! empty($employee['manager_id'])) {
                    $mgr = $db->table('employees')->where('id', (int) $employee['manager_id'])->get()->getRowArray();
                    if ($mgr && ! empty($mgr['user_id'])) return (int) $mgr['user_id'];
                }
                return null;

            case 'HR':
            case 'HR_ADMIN':
                $row = $db->table('users')
                    ->where('company_id', $companyId)
                    ->where('role_id', 2)
                    ->where('is_active', 1)
                    ->orderBy('id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                return $row ? (int) $row['id'] : null;

            case 'FINANCE':
                $row = $db->table('users')
                    ->where('company_id', $companyId)
                    ->where('role_id', 5)
                    ->where('is_active', 1)
                    ->orderBy('id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                return $row ? (int) $row['id'] : null;

            case 'IT':
                // Cari user dgn slug 'it_admin' kalau ada; fallback ke HR
                $row = $db->table('users u')
                    ->select('u.id')
                    ->join('roles r', 'r.id = u.role_id', 'left')
                    ->where('u.company_id', $companyId)
                    ->where('u.is_active', 1)
                    ->where('r.slug', 'it_admin')
                    ->orderBy('u.id', 'ASC')->limit(1)
                    ->get()->getRowArray();
                if ($row) return (int) $row['id'];
                return $this->resolveAssignee('HR', $employee); // fallback

            default:
                return null;
        }
    }

    /**
     * Ringkasan progress task per karyawan untuk type tertentu.
     */
    public function progress(int $employeeId, ?string $type = null): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('employee_tasks')
            ->where('employee_id', $employeeId)
            ->where('deleted_at IS NULL');
        if ($type) $builder->where('type', $type);

        $rows = $builder->get()->getResultArray();
        $total = count($rows);
        $completed = 0;
        $mandatory = 0;
        $mandatoryDone = 0;
        $overdue = 0;
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            if ($r['status'] === 'COMPLETED') $completed++;
            if ((int) $r['mandatory'] === 1) {
                $mandatory++;
                if ($r['status'] === 'COMPLETED' || $r['status'] === 'SKIPPED') $mandatoryDone++;
            }
            if ($r['status'] === 'PENDING' && ! empty($r['due_date']) && $r['due_date'] < $today) {
                $overdue++;
            }
        }
        return [
            'total'          => $total,
            'completed'      => $completed,
            'mandatory'      => $mandatory,
            'mandatory_done' => $mandatoryDone,
            'overdue'        => $overdue,
            'percent'        => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'mandatory_percent' => $mandatory > 0 ? (int) round(($mandatoryDone / $mandatory) * 100) : 0,
            'is_complete'    => $mandatory === 0 || $mandatoryDone >= $mandatory,
        ];
    }

    /**
     * Cari task overdue lintas perusahaan / company tertentu.
     * @return array<int,array>
     */
    public function findOverdue(?int $companyId = null, int $limit = 200): array
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');
        $builder = $db->table('employee_tasks et')
            ->select('et.*, e.full_name AS employee_name, e.employee_number')
            ->join('employees e', 'e.id = et.employee_id')
            ->where('et.deleted_at IS NULL')
            ->where('et.status', 'PENDING')
            ->where('et.due_date IS NOT NULL')
            ->where('et.due_date <', $today);
        if ($companyId) $builder->where('et.company_id', $companyId);
        return $builder->orderBy('et.due_date', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function notifyAssignee(array $task): void
    {
        if (empty($task['assignee_user_id'])) return;
        $type = $task['type'] === 'OFFBOARD' ? 'Offboarding' : 'Onboarding';
        Services::notification()->send(
            (int) $task['assignee_user_id'],
            (int) $task['company_id'],
            'task.assigned',
            "Tugas {$type} baru ditugaskan ke Anda",
            $task['task_name'] . (! empty($task['due_date']) ? ' (jatuh tempo: ' . $task['due_date'] . ')' : ''),
            ['task_id' => (int) ($task['id'] ?? 0), 'type' => $task['type']],
            null,
            true
        );
    }
}