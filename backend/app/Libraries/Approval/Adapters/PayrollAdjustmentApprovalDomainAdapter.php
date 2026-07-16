<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Payroll\PayrollAdjustmentApprovalWorkflowService;

final class PayrollAdjustmentApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?PayrollAdjustmentApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'PAYROLL_ADJUSTMENT';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new PayrollAdjustmentApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
