<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Payroll\BonusApprovalWorkflowService;

final class BonusApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?BonusApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'BONUS';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new BonusApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
