<?php

namespace App\Libraries\Approval;

/**
 * Membentuk timeline lengkap approval dari workflow snapshot + keputusan aktual.
 *
 * Snapshot dipakai supaya tahap/rule/PIC yang berlaku saat submit tidak berubah
 * ketika konfigurasi chain atau struktur organisasi diedit kemudian.
 */
final class ApprovalTimelineService
{
    /**
     * @return array<string, mixed>|null
     */
    public function forEntity(
        int $companyId,
        string $module,
        string $entityTable,
        int $entityId
    ): ?array {
        $db = \Config\Database::connect();

        $approval = $db->table('approval_requests ar')
            ->select('ar.*, ac.name AS chain_name')
            ->join('approval_chains ac', 'ac.id = ar.chain_id', 'left')
            ->where('ar.company_id', $companyId)
            ->where('ar.module', strtoupper($module))
            ->where('ar.entity_table', $entityTable)
            ->where('ar.entity_id', $entityId)
            ->orderBy('ar.id', 'DESC')
            ->get()->getRowArray();

        if ($approval === null) {
            return null;
        }

        $approval['meta'] = ! empty($approval['meta'])
            ? json_decode((string) $approval['meta'], true)
            : null;

        $snapshot = ! empty($approval['workflow_snapshot'])
            ? json_decode((string) $approval['workflow_snapshot'], true)
            : null;
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $decisions = $db->table('approval_decisions ad')
            ->select('ad.*, u.name AS approver_user_name')
            ->join('users u', 'u.id = ad.approver_user_id', 'left')
            ->where('ad.company_id', $companyId)
            ->where('ad.approval_request_id', (int) $approval['id'])
            ->orderBy('ad.step_order', 'ASC')
            ->orderBy('ad.id', 'ASC')
            ->get()->getResultArray();

        $decisionByStepId = [];
        foreach ($decisions as $decision) {
            if ($decision['chain_step_id'] !== null) {
                $decisionByStepId[(int) $decision['chain_step_id']] = $decision;
            }
        }

        $steps = $snapshot['steps'] ?? [];
        if ($steps === [] && ! empty($approval['chain_id'])) {
            $steps = $db->table('approval_chain_steps')
                ->where('company_id', $companyId)
                ->where('chain_id', (int) $approval['chain_id'])
                ->orderBy('step_order', 'ASC')
                ->get()->getResultArray();
        }

        $expectedUserIds = [];
        foreach ($steps as $step) {
            foreach ((array) ($step['expected_approver_user_ids'] ?? []) as $userId) {
                if ((int) $userId > 0) {
                    $expectedUserIds[] = (int) $userId;
                }
            }
        }
        $expectedUserIds = array_values(array_unique($expectedUserIds));

        $expectedUserNames = [];
        if ($expectedUserIds !== []) {
            $users = $db->table('users')
                ->select('id, name')
                ->where('company_id', $companyId)
                ->whereIn('id', $expectedUserIds)
                ->get()->getResultArray();

            foreach ($users as $user) {
                $expectedUserNames[(int) $user['id']] = (string) $user['name'];
            }
        }

        $timeline = [];
        foreach ($steps as $step) {
            $stepId = (int) ($step['id'] ?? 0);
            $conditionMatched = ! array_key_exists('condition_matched', $step)
                || (bool) $step['condition_matched'];
            $decision = $decisionByStepId[$stepId] ?? null;
            $approverIds = array_values(array_map(
                'intval',
                (array) ($step['expected_approver_user_ids'] ?? [])
            ));
            $approverNames = array_values(array_filter(array_map(
                static fn (int $userId): ?string => $expectedUserNames[$userId] ?? null,
                $approverIds
            )));

            $decisionStatus = $decision['decision'] ?? null;
            $timeline[] = [
                'step_id'                    => $stepId,
                'step_order'                 => (int) ($step['step_order'] ?? 0),
                'step_name'                  => (string) ($step['name'] ?? 'Tahap approval'),
                'approver_type'              => (string) ($step['approver_type'] ?? ''),
                'condition_matched'           => $conditionMatched,
                'condition_field'             => $step['condition_field'] ?? null,
                'condition_op'                => $step['condition_op'] ?? null,
                'condition_value'             => $step['condition_value'] ?? null,
                'expected_approver_user_ids'  => $approverIds,
                'expected_approver_names'     => $approverNames,
                'status'                      => $decisionStatus === 'PENDING'
                    ? 'WAITING_APPROVAL'
                    : ($decisionStatus ?? ($conditionMatched ? 'WAITING_APPROVAL' : 'SKIPPED')),
                'approver_user_name'          => $decision['approver_user_name'] ?? null,
                'decided_at'                  => $decision['decided_at'] ?? null,
                'note'                        => $decision['note'] ?? null,
                'is_current'                  => $conditionMatched
                    && (int) ($approval['current_step_id'] ?? 0) === $stepId,
            ];
        }

        // Workflow tanpa chain memakai fallback HR dengan tahap sintetis.
        if ($timeline === [] && $decisions !== []) {
            foreach ($decisions as $decision) {
                $timeline[] = [
                    'step_id'                    => null,
                    'step_order'                 => (int) $decision['step_order'],
                    'step_name'                  => 'Persetujuan HR (fallback)',
                    'approver_type'              => 'HR',
                    'condition_matched'           => true,
                    'expected_approver_user_ids'  => [],
                    'expected_approver_names'     => [],
                    'status'                      => $decision['decision'] === 'PENDING'
                        ? 'WAITING_APPROVAL'
                        : $decision['decision'],
                    'approver_user_name'          => $decision['approver_user_name'] ?? null,
                    'decided_at'                  => $decision['decided_at'] ?? null,
                    'note'                        => $decision['note'] ?? null,
                    'is_current'                  => $decision['decision'] === 'PENDING',
                ];
            }
        }

        $approval['workflow_snapshot'] = $snapshot;
        $approval['decisions'] = $decisions;
        $approval['timeline'] = $timeline;

        return $approval;
    }
}