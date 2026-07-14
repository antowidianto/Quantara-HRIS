<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Filters\OwnershipPolicyFilter;
use App\Libraries\System\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class OwnershipPolicyFilterTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('tenant');
        parent::tearDown();
    }

    public function testAccountPolicyRejectsMissingAuthenticatedPrincipal(): void
    {
        Services::injectMock('tenant', new TenantContext());
        $result = (new OwnershipPolicyFilter())->before(Services::request(), ['account']);
        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function testEmployeePolicyRequiresLinkedEmployee(): void
    {
        $tenant = new TenantContext();
        $tenant->setUserId(10);
        Services::injectMock('tenant', $tenant);
        self::assertSame(403, (new OwnershipPolicyFilter())->before(Services::request(), ['employee'])->getStatusCode());

        $tenant->setEmployeeId(20);
        self::assertNull((new OwnershipPolicyFilter())->before(Services::request(), ['employee']));
    }

    public function testAccountPolicyAcceptsAuthenticatedUser(): void
    {
        $tenant = new TenantContext();
        $tenant->setUserId(10);
        Services::injectMock('tenant', $tenant);
        self::assertNull((new OwnershipPolicyFilter())->before(Services::request(), ['account']));
    }
}
