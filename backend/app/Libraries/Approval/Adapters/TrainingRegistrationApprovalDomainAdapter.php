<?php

namespace App\Libraries\Approval\Adapters;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Training\TrainingService;

final class TrainingRegistrationApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?TrainingService $service = null)
    {
    }

    public function module(): string
    {
        return 'TRAINING_REGISTRATION';
    }

    public function apply(array $request, string $decision, int $actorUserId, string $note): array
    {
        $service = $this->service ?? new TrainingService();
        return $decision === 'APPROVED'
            ? $service->approveRegistration(
                (int) $request['entity_id'],
                (int) $request['company_id'],
                $actorUserId
            )
            : $service->rejectRegistration(
                (int) $request['entity_id'],
                (int) $request['company_id'],
                $actorUserId,
                $note
            );
    }
}
