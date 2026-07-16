<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Attendance\AttendanceChangeApprovalWorkflowService;

final class AttendanceChangeApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?AttendanceChangeApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'ATTENDANCE_CHANGE';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new AttendanceChangeApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
