<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Asset\AssetApprovalWorkflowService;

final class AssetTransactionApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?AssetApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'ASSET_TRANSACTION';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new AssetApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject((int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note);
    }
}
