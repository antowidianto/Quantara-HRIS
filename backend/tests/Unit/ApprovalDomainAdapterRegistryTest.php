<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Approval\ApprovalDomainAdapterRegistry;
use PHPUnit\Framework\TestCase;

final class ApprovalDomainAdapterRegistryTest extends TestCase
{
    public function testResolvesRegisteredAdapter(): void
    {
        $adapter = new class implements ApprovalDomainAdapterInterface {
            public function module(): string
            {
                return 'TEST';
            }

            public function apply(array $request, string $decision, int $actorUserId, string $note): array
            {
                return ['entity_id' => $request['entity_id'], 'decision' => $decision];
            }
        };

        $registry = new ApprovalDomainAdapterRegistry([$adapter]);
        $this->assertSame($adapter, $registry->forModule('test'));
    }

    public function testBlocksFinalizationWhenAdapterIsMissing(): void
    {
        $registry = new ApprovalDomainAdapterRegistry([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('belum memiliki domain adapter');
        $registry->forModule('OVERTIME');
    }
}
