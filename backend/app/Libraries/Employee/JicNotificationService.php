<?php

declare(strict_types=1);

namespace App\Libraries\Employee;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class JicNotificationService
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $connection = null)
    {
        $this->db = $connection ?? Database::connect();
    }

    public function notifyApprover(int $jicId, string $stage, int $companyId): void
    {
        $this->requireCompany($companyId);
        $row = $this->db->table('job_information_changes jic')
            ->select('jic.*, e.full_name AS employee_name, e.supervisor_id, e.manager_id, e.department_id')
            ->join('employees e', 'e.id = jic.employee_id AND e.company_id = jic.company_id', 'left')
            ->where('jic.id', $jicId)->where('jic.company_id', $companyId)->get()->getRowArray();
        if (! $row) return;

        $targets = [];
        if ($stage === 'PENDING_SUPERVISOR') {
            $supervisorId = (int) ($row['supervisor_id'] ?? 0) ?: (int) ($row['manager_id'] ?? 0);
            if ($supervisorId > 0) {
                $target = $this->db->table('employees')->select('user_id')->where('id', $supervisorId)
                    ->where('company_id', $companyId)->get()->getRowArray();
                if (! empty($target['user_id'])) $targets[] = (int) $target['user_id'];
            }
        } elseif ($stage === 'PENDING_DEPT_HEAD') {
            $departments = array_values(array_filter([(int) ($row['old_department_id'] ?? 0), (int) ($row['new_department_id'] ?? 0)]));
            if ($departments !== []) {
                $managers = $this->db->table('departments d')->select('e.user_id')
                    ->join('employees e', 'e.id = d.manager_employee_id AND e.company_id = d.company_id', 'left')
                    ->whereIn('d.id', $departments)->where('d.company_id', $companyId)->get()->getResultArray();
                foreach ($managers as $manager) if (! empty($manager['user_id'])) $targets[] = (int) $manager['user_id'];
            }
        } elseif (in_array($stage, ['PENDING_HR', 'PENDING_BOD'], true)) {
            $permission = $stage === 'PENDING_BOD' ? 'jic.manage' : 'jic.approve';
            $users = $this->db->table('users u')->select('u.id')
                ->join('role_permissions rp', 'rp.role_id = u.role_id', 'left')
                ->join('permissions p', 'p.id = rp.permission_id', 'left')
                ->where('u.company_id', $companyId)->where('u.is_active', 1)->where('p.slug', $permission)
                ->groupBy('u.id')->get()->getResultArray();
            foreach ($users as $user) $targets[] = (int) $user['id'];
        }

        $body = sprintf('Pengajuan %s untuk %s, efektif %s.', $this->changeTypeLabel((string) $row['change_type']), $row['employee_name'] ?? 'karyawan', $row['effective_date']);
        foreach (array_unique(array_filter($targets)) as $userId) {
            Services::notification()->send($userId, $companyId, 'jic.pending', 'Persetujuan JIC diperlukan', $body, [
                'jic_id' => $jicId, 'jic_number' => $row['jic_number'], 'change_type' => $row['change_type'], 'stage' => $stage,
            ], null, true);
        }
    }

    /** @param array<string,mixed> $row */
    public function notifyEmployeeConsent(array $row): void
    {
        $companyId = $this->companyId($row);
        $employee = $this->db->table('employees')->where('id', (int) $row['employee_id'])
            ->where('company_id', $companyId)->get()->getRowArray();
        if (empty($employee['user_id'])) return;
        Services::notification()->send((int) $employee['user_id'], $companyId, 'jic.consent_required', 'Persetujuan Anda dibutuhkan',
            'HR mengajukan demosi yang menyangkut posisi/upah Anda. Silakan baca detail dan berikan persetujuan/penolakan.',
            ['jic_id' => (int) $row['id'], 'jic_number' => $row['jic_number']], null, true);
    }

    public function notifyEmployeeApproved(int $jicId, int $companyId): void
    {
        $this->requireCompany($companyId);
        $row = $this->db->table('job_information_changes')->where('id', $jicId)->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) return;
        $employee = $this->db->table('employees')->where('id', (int) $row['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        if (empty($employee['user_id'])) return;
        Services::notification()->send((int) $employee['user_id'], $companyId, 'jic.approved', 'JIC Anda disetujui',
            sprintf('Pengajuan %s untuk Anda telah disetujui (SK %s). Berlaku efektif %s.', $this->changeTypeLabel((string) $row['change_type']), $row['sk_number'] ?? '-', $row['effective_date']),
            ['jic_id' => $jicId, 'jic_number' => $row['jic_number'], 'sk_number' => $row['sk_number'], 'change_type' => $row['change_type']], null, true);
    }

    /** @param array<string,mixed> $row */
    public function notifyRejected(array $row, string $stage, string $note): void
    {
        $companyId = $this->companyId($row);
        $employee = $this->db->table('employees')->where('id', (int) $row['employee_id'])->where('company_id', $companyId)->get()->getRowArray();
        $targets = [];
        if (! empty($employee['user_id'])) $targets[] = (int) $employee['user_id'];
        if (! empty($row['created_by'])) $targets[] = (int) $row['created_by'];
        foreach (array_unique(array_filter($targets)) as $userId) {
            Services::notification()->send($userId, $companyId, 'jic.rejected', 'JIC ditolak',
                "Pengajuan JIC #{$row['id']} ditolak di tahap {$stage}. Alasan: " . mb_substr($note, 0, 200),
                ['jic_id' => (int) $row['id'], 'stage' => $stage], null, true);
        }
    }

    /** @param array<string,mixed> $row */
    private function companyId(array $row): int
    {
        $companyId = (int) ($row['company_id'] ?? 0);
        $this->requireCompany($companyId);
        return $companyId;
    }

    private function requireCompany(int $companyId): void
    {
        if ($companyId <= 0) throw new \DomainException('Active company context is required for JIC notifications');
    }

    private function changeTypeLabel(string $code): string
    {
        return ['PROMOTION' => 'Promosi', 'DEMOTION' => 'Demosi', 'LATERAL' => 'Mutasi Lateral', 'REORG' => 'Reorganisasi', 'SALARY_ADJUSTMENT' => 'Penyesuaian Gaji', 'STATUS_CHANGE' => 'Perubahan Status'][$code] ?? $code;
    }
}
