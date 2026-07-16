<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Performance\IdpApprovalWorkflowService;

final class IdpActivationApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?IdpApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'IDP_ACTIVATION';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new IdpApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
