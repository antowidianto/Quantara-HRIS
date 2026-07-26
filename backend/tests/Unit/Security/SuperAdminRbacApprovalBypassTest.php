<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Filters\PrivilegedActionApprovalFilter;
use App\Libraries\System\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class SuperAdminRbacApprovalBypassTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('tenant');
        parent::tearDown();
    }

    public function testSuperAdminBypassesRbacApproval(): void
    {
        $tenant = new TenantContext();
        $tenant->setUserId(1);
        $tenant->setRoleId(1);
        Services::injectMock('tenant', $tenant);

        self::assertNull(
            (new PrivilegedActionApprovalFilter())->before(Services::request(), ['RBAC'])
        );
    }

    public function testRegularUserStillRequiresPrivilegedActionFlow(): void
    {
        $tenant = new TenantContext();
        $tenant->setUserId(20);
        $tenant->setRoleId(2);
        Services::injectMock('tenant', $tenant);

        $result = (new PrivilegedActionApprovalFilter())->before(Services::request(), ['RBAC']);

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(422, $result->getStatusCode());
        self::assertStringContainsString('Company target wajib', $result->getBody());
    }

    public function testSuperAdminDoesNotBypassOtherPrivilegedActionGroups(): void
    {
        $tenant = new TenantContext();
        $tenant->setUserId(1);
        $tenant->setRoleId(1);
        Services::injectMock('tenant', $tenant);

        $result = (new PrivilegedActionApprovalFilter())->before(Services::request(), ['PII_EXPORT']);

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(422, $result->getStatusCode());
    }
}
