<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Lifecycle\GeneratedContractApprovalWorkflowService;

final class GeneratedContractApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?GeneratedContractApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'GENERATED_CONTRACT';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new GeneratedContractApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject(
                (int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note
            );
    }
}
