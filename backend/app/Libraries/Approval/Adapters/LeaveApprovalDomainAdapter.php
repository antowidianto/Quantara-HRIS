<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Leave\LeaveWorkflowService;

final class LeaveApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?LeaveWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'LEAVE';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new LeaveWorkflowService();
        $entityId = (int) $request['entity_id'];
        $companyId = (int) $request['company_id'];

        return $decision === 'APPROVED'
            ? $workflow->approve($entityId, $companyId, $actorUserId)
            : $workflow->reject($entityId, $companyId, $actorUserId, $note);
    }
}
