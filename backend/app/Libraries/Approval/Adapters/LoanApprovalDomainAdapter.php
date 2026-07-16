<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Payroll\LoanApprovalWorkflowService;

final class LoanApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?LoanApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'LOAN';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new LoanApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
