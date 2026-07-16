<?php

namespace App\Libraries\Approval;

/**
 * Applies the final approval outcome to the governed domain entity.
 * Implementations must only mutate database state and must be idempotent when
 * protected by the approval request state/transaction owned by ApprovalEngine.
 */
interface ApprovalDomainAdapterInterface
{
    public function module(): string;

    /**
     * @param array<string,mixed> $request approval_requests row
     * @return array<string,mixed> domain result used by API/event publishers
     */
    public function apply(array $request, string $decision, int $actorUserId, string $note): array;
}
