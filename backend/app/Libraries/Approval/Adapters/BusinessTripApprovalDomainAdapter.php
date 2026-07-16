<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Attendance\BusinessTripApprovalWorkflowService;

final class BusinessTripApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?BusinessTripApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'BUSINESS_TRIP';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new BusinessTripApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
