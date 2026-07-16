<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Attendance\OvertimeApprovalWorkflowService;

final class OvertimeApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?OvertimeApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'OVERTIME';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new OvertimeApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
