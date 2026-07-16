<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Timesheet\TimesheetService;

final class TimesheetApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?TimesheetService $service = null)
    {
    }

    public function module(): string
    {
        return 'TIMESHEET';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $service = $this->service ?? new TimesheetService();
        return $decision === 'APPROVED'
            ? $service->approve((int) $request['entity_id'], (int) $request['company_id'], $actorUserId)
            : $service->reject(
                (int) $request['entity_id'],
                (int) $request['company_id'],
                $actorUserId,
                $note
            );
    }
}
