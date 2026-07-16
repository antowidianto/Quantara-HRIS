<?php

namespace App\Libraries\Performance;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class PerformanceGoalApprovalService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $approvalStarter;

    public function __construct(
        private readonly ?BaseConnection $connection = null,
        ?callable $approvalStarter = null,
    ) {
        $this->approvalStarter = $approvalStarter;
    }

    /** @return array<string,mixed> */
    public function submit(int $goalId, int $companyId, int $employeeId, int $userId): array
    {
        $context = $this->context($goalId, $companyId);
        $goal = $context['goal'];
        if ((int) $goal['employee_id'] !== $employeeId) {
            throw new \RuntimeException('Hanya pemilik goal yang dapat mengajukan approval');
        }
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if (! $actor || (int) ($actor['employee_id'] ?? 0) !== $employeeId) {
            throw new \RuntimeException('User pengaju tidak terhubung ke pemilik goal aktif');
        }
        if (! in_array((string) $goal['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            throw new \RuntimeException("Goal status {$goal['status']} tidak dapat diajukan");
        }
        $this->validate($context);
        $snapshotHash = $this->snapshotHash($context);
        $now = date('Y-m-d H:i:s');
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('goals')->where('id', $goalId)->where('company_id', $companyId)
                ->whereIn('status', ['DRAFT', 'APPROVAL_REJECTED'])->update([
                    'status' => 'PENDING_APPROVAL',
                    'submitted_by' => $userId,
                    'submitted_at' => $now,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_rejected_by' => null,
                    'approval_rejected_at' => null,
                    'approval_rejection_reason' => null,
                    'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('Goal berubah; muat ulang data');
            $params = [
                'company_id' => $companyId,
                'module' => 'PERFORMANCE_GOAL',
                'entity_table' => 'goals',
                'entity_id' => $goalId,
                'employee_id' => $employeeId,
                'meta' => [
                    'submitted_by' => $userId,
                    'goal_snapshot_hash' => $snapshotHash,
                    'cycle_id' => (int) $goal['cycle_id'],
                    'weight' => (float) $goal['weight'],
                    'goal_type' => (string) $goal['goal_type'],
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval goal');
            $db->transCommit();
            return ['goal' => $this->goal($goalId, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $goalId = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $context = $this->context($goalId, $companyId);
        if (($context['goal']['status'] ?? null) !== 'PENDING_APPROVAL') {
            throw new \RuntimeException('Goal tidak sedang menunggu approval');
        }
        $meta = $this->meta($request);
        if (! hash_equals((string) ($meta['goal_snapshot_hash'] ?? ''), $this->snapshotHash($context))) {
            throw new \RuntimeException('Isi goal atau cycle berubah setelah submit');
        }
        $this->validate($context);
        $now = date('Y-m-d H:i:s');
        $this->db()->table('goals')->where('id', $goalId)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => $now,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Goal berubah saat finalisasi');
        return ['goal_id' => $goalId, 'status' => 'APPROVED'];
    }

    /** @return array<string,mixed> */
    public function reject(int $goalId, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');
        $goal = $this->goal($goalId, $companyId);
        if (($goal['status'] ?? null) !== 'PENDING_APPROVAL') {
            throw new \RuntimeException('Goal tidak sedang menunggu approval');
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('goals')->where('id', $goalId)->where('company_id', $companyId)
            ->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVAL_REJECTED',
                'approval_rejected_by' => $userId,
                'approval_rejected_at' => $now,
                'approval_rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('Goal berubah saat penolakan');
        return ['goal_id' => $goalId, 'status' => 'APPROVAL_REJECTED'];
    }

    /** @param array{goal:array<string,mixed>,cycle:array<string,mixed>} $context */
    private function validate(array $context): void
    {
        $goal = $context['goal'];
        $cycle = $context['cycle'];
        if (! in_array((string) $cycle['status'], ['OPEN', 'GOAL_SETTING'], true)) {
            throw new \RuntimeException("Cycle status {$cycle['status']} tidak menerima pengajuan goal");
        }
        if (! empty($cycle['goal_setting_deadline']) && (string) $cycle['goal_setting_deadline'] < date('Y-m-d')) {
            throw new \RuntimeException('Deadline pengajuan goal sudah lewat');
        }
        $weight = (float) $goal['weight'];
        if ($weight <= 0 || $weight > 100) throw new \RuntimeException('Bobot goal harus lebih dari 0 dan maksimal 100');
        if (trim((string) $goal['title']) === '') throw new \RuntimeException('Judul goal wajib diisi');
        $totalWeight = (float) ($this->db()->table('goals')->selectSum('weight', 'total_weight')
            ->where('company_id', (int) $goal['company_id'])->where('cycle_id', (int) $goal['cycle_id'])
            ->where('employee_id', (int) $goal['employee_id'])->where('deleted_at IS NULL')
            ->where('status !=', 'CANCELLED')->get()->getRowArray()['total_weight'] ?? 0);
        if (abs($totalWeight - 100.0) > 0.01) {
            throw new \RuntimeException("Total bobot goal dalam cycle harus 100%; saat ini {$totalWeight}%");
        }
    }

    /** @return array{goal:array<string,mixed>,cycle:array<string,mixed>} */
    private function context(int $goalId, int $companyId): array
    {
        $goal = $this->goal($goalId, $companyId);
        $cycle = $this->db()->table('performance_cycles')->where('id', (int) $goal['cycle_id'])
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $cycle) throw new \RuntimeException('Performance cycle tidak ditemukan di tenant aktif');
        return ['goal' => $goal, 'cycle' => $cycle];
    }

    /** @return array<string,mixed> */
    private function goal(int $goalId, int $companyId): array
    {
        $goal = $this->db()->table('goals')->where('id', $goalId)->where('company_id', $companyId)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $goal) throw new \RuntimeException('Goal tidak ditemukan di tenant aktif');
        return $goal;
    }

    /** @param array{goal:array<string,mixed>,cycle:array<string,mixed>} $context */
    private function snapshotHash(array $context): string
    {
        $goalFields = ['id', 'company_id', 'cycle_id', 'employee_id', 'parent_id', 'goal_type', 'category',
            'title', 'description', 'measurement_type', 'target_value', 'unit', 'weight', 'due_date'];
        $cycleFields = ['id', 'company_id', 'start_date', 'end_date', 'goal_setting_deadline', 'status'];
        $snapshot = [
            'goal' => array_intersect_key($context['goal'], array_flip($goalFields)),
            'cycle' => array_intersect_key($context['cycle'], array_flip($cycleFields)),
        ];
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function meta(array $request): array
    {
        $meta = $request['meta'] ?? [];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        return is_array($meta) ? $meta : [];
    }

    private function db(): BaseConnection
    {
        return $this->connection ?? Database::connect();
    }
}
