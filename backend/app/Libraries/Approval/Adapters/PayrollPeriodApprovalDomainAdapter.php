<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Payroll\PayrollApprovalWorkflowService;

final class PayrollPeriodApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?PayrollApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'PAYROLL';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new PayrollApprovalWorkflowService();

        return $workflow->applyFinalDecision(
            (int) $request['entity_id'],
            (int) $request['company_id'],
            $actorUserId,
            $decision,
            $note,
        );
    }
}
