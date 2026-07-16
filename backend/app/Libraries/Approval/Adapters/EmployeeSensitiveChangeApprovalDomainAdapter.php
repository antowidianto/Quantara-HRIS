<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Employee\EmployeeSensitiveChangeApprovalService;

final class EmployeeSensitiveChangeApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?EmployeeSensitiveChangeApprovalService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'EMPLOYEE_SENSITIVE_CHANGE';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new EmployeeSensitiveChangeApprovalService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
