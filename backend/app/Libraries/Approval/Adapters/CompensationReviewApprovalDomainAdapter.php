<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Compensation\CompensationReviewApprovalWorkflowService;

final class CompensationReviewApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?CompensationReviewApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'COMPENSATION_REVIEW';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new CompensationReviewApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
