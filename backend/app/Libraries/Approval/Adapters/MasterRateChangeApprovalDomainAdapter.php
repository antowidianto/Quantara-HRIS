<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Payroll\MasterRateApprovalService;

final class MasterRateChangeApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?MasterRateApprovalService $workflow = null) {}
    public function module(): string { return 'MASTER_RATE_CHANGE'; }
    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new MasterRateApprovalService();
        return $decision === 'APPROVED' ? $workflow->approve($request, $actorUserId) : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
