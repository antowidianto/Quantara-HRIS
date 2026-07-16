<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Recruitment\HireApprovalWorkflowService;

final class HireConversionApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?HireApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'HIRE_CONVERSION';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new HireApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
