<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Performance\PerformanceGoalApprovalService;

final class PerformanceGoalApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?PerformanceGoalApprovalService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'PERFORMANCE_GOAL';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new PerformanceGoalApprovalService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
