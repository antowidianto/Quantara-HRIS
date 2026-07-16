<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Recruitment\CandidateOfferApprovalWorkflowService;

final class CandidateOfferApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?CandidateOfferApprovalWorkflowService $workflow = null)
    {
    }

    public function module(): string
    {
        return 'CANDIDATE_OFFER';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $workflow = $this->workflow ?? new CandidateOfferApprovalWorkflowService();
        return $decision === 'APPROVED'
            ? $workflow->approve($request, $actorUserId)
            : $workflow->reject(
                (int) $request['entity_id'], (int) $request['company_id'], $actorUserId, $note
            );
    }
}
