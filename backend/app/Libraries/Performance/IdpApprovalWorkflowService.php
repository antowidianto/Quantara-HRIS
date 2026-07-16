<?php

namespace App\Libraries\Performance;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class IdpApprovalWorkflowService
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
    public function submitActivation(int $idpId, int $companyId, int $userId): array
    {
        $context = $this->context($idpId, $companyId);
        $idp = $context['idp'];
        if (! in_array((string) $idp['status'], ['DRAFT', 'APPROVAL_REJECTED'], true)) {
            throw new \RuntimeException("IDP status {$idp['status']} tidak dapat diajukan");
        }
        $actor = $this->db()->table('users')->where('id', $userId)->where('company_id', $companyId)
            ->where('is_active', 1)->get()->getRowArray();
        if (! $actor || (int) ($actor['employee_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Pembuat aktivasi IDP harus user aktif yang terhubung ke employee');
        }
        $this->validate($context);
        $snapshotHash = $this->snapshotHash($context);
        $now = date('Y-m-d H:i:s');
        $db = $this->db();
        $db->transBegin();
        try {
            $db->table('individual_development_plans')->where('id', $idpId)
                ->where('company_id', $companyId)->whereIn('status', ['DRAFT', 'APPROVAL_REJECTED'])
                ->update([
                    'status' => 'PENDING_APPROVAL',
                    'activation_submitted_by' => $userId,
                    'activation_submitted_at' => $now,
                    'activation_approved_by' => null,
                    'activation_approved_at' => null,
                    'activation_rejected_by' => null,
                    'activation_rejected_at' => null,
                    'activation_rejection_reason' => null,
                    'updated_at' => $now,
                ]);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('IDP berubah; muat ulang data');
            $params = [
                'company_id' => $companyId,
                'module' => 'IDP_ACTIVATION',
                'entity_table' => 'individual_development_plans',
                'entity_id' => $idpId,
                'employee_id' => (int) $idp['employee_id'],
                'meta' => [
                    'submitted_by' => $userId,
                    'idp_snapshot_hash' => $snapshotHash,
                    'cycle_id' => (int) ($idp['cycle_id'] ?? 0) ?: null,
                    'review_id' => (int) ($idp['review_id'] ?? 0) ?: null,
                    'action_item_count' => count($this->actionItems($idp)),
                ],
            ];
            $approval = $this->approvalStarter !== null
                ? ($this->approvalStarter)($params)
                : Services::approval()->start($params);
            if (! $db->transStatus()) throw new \RuntimeException('Gagal membuat workflow approval IDP');
            $db->transCommit();
            return ['idp' => $this->idp($idpId, $companyId), 'approval' => $approval];
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function approve(array $request, int $userId): array
    {
        $idpId = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];
        $context = $this->context($idpId, $companyId);
        if (($context['idp']['status'] ?? null) !== 'PENDING_APPROVAL') {
            throw new \RuntimeException('IDP tidak sedang menunggu approval aktivasi');
        }
        $meta = $this->meta($request);
        if (! hash_equals((string) ($meta['idp_snapshot_hash'] ?? ''), $this->snapshotHash($context))) {
            throw new \RuntimeException('Isi IDP, review, atau cycle berubah setelah submit');
        }
        $this->validate($context);
        $now = date('Y-m-d H:i:s');
        $this->db()->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'ACTIVE', 'finalized_at' => $now,
                'activation_approved_by' => $userId, 'activation_approved_at' => $now,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('IDP berubah saat aktivasi final');
        return ['idp_id' => $idpId, 'status' => 'ACTIVE'];
    }

    /** @return array<string,mixed> */
    public function reject(int $idpId, int $companyId, int $userId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');
        $idp = $this->idp($idpId, $companyId);
        if (($idp['status'] ?? null) !== 'PENDING_APPROVAL') {
            throw new \RuntimeException('IDP tidak sedang menunggu approval aktivasi');
        }
        $now = date('Y-m-d H:i:s');
        $this->db()->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('status', 'PENDING_APPROVAL')->update([
                'status' => 'APPROVAL_REJECTED',
                'activation_rejected_by' => $userId,
                'activation_rejected_at' => $now,
                'activation_rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
        if ($this->db()->affectedRows() !== 1) throw new \RuntimeException('IDP berubah saat penolakan');
        return ['idp_id' => $idpId, 'status' => 'APPROVAL_REJECTED'];
    }

    /** @param array{idp:array<string,mixed>,cycle:?array<string,mixed>,review:?array<string,mixed>} $context */
    private function validate(array $context): void
    {
        $idp = $context['idp'];
        if (strtotime((string) $idp['plan_period_end']) <= strtotime((string) $idp['plan_period_start'])) {
            throw new \RuntimeException('Periode akhir IDP harus setelah periode mulai');
        }
        $items = $this->actionItems($idp);
        if ($items === []) throw new \RuntimeException('IDP harus memiliki minimum satu action item');
        foreach ($items as $index => $item) {
            if (! is_array($item) || trim((string) ($item['action'] ?? '')) === '') {
                throw new \RuntimeException('Action item #' . ($index + 1) . ' belum memiliki aksi yang jelas');
            }
            if (empty($item['owner']) || empty($item['due_date'])) {
                throw new \RuntimeException('Action item #' . ($index + 1) . ' wajib memiliki owner dan due_date');
            }
        }
    }

    /** @return array{idp:array<string,mixed>,cycle:?array<string,mixed>,review:?array<string,mixed>} */
    private function context(int $idpId, int $companyId): array
    {
        $idp = $this->idp($idpId, $companyId);
        $cycle = null;
        if (! empty($idp['cycle_id'])) {
            $cycle = $this->db()->table('performance_cycles')->where('id', (int) $idp['cycle_id'])
                ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $cycle) throw new \RuntimeException('Cycle IDP tidak ditemukan di tenant aktif');
        }
        $review = null;
        if (! empty($idp['review_id'])) {
            $review = $this->db()->table('performance_reviews')->where('id', (int) $idp['review_id'])
                ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
            if (! $review) throw new \RuntimeException('Review IDP tidak ditemukan di tenant aktif');
        }
        return ['idp' => $idp, 'cycle' => $cycle, 'review' => $review];
    }

    /** @return array<string,mixed> */
    private function idp(int $idpId, int $companyId): array
    {
        $idp = $this->db()->table('individual_development_plans')->where('id', $idpId)
            ->where('company_id', $companyId)->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $idp) throw new \RuntimeException('IDP tidak ditemukan di tenant aktif');
        return $idp;
    }

    /** @param array<string,mixed> $idp @return list<mixed> */
    private function actionItems(array $idp): array
    {
        $items = json_decode((string) ($idp['action_items_json'] ?? '[]'), true);
        return is_array($items) ? array_values($items) : [];
    }

    /** @param array{idp:array<string,mixed>,cycle:?array<string,mixed>,review:?array<string,mixed>} $context */
    private function snapshotHash(array $context): string
    {
        $idpFields = ['id', 'company_id', 'employee_id', 'cycle_id', 'review_id', 'plan_period_start',
            'plan_period_end', 'gaps_json', 'target_competencies_json', 'recommended_trainings_json',
            'action_items_json', 'manager_notes', 'employee_notes'];
        $cycleFields = ['id', 'company_id', 'start_date', 'end_date', 'status'];
        $reviewFields = ['id', 'company_id', 'employee_id', 'cycle_id', 'status', 'final_score', 'improvements'];
        $snapshot = [
            'idp' => array_intersect_key($context['idp'], array_flip($idpFields)),
            'cycle' => $context['cycle'] ? array_intersect_key($context['cycle'], array_flip($cycleFields)) : null,
            'review' => $context['review'] ? array_intersect_key($context['review'], array_flip($reviewFields)) : null,
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
