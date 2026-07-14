<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Filters\ImportPermissionFilter;
use App\Libraries\System\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class ImportPermissionFilterTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('tenant');
        parent::tearDown();
    }

    public function testRegistryPermissionIsEnforcedAtRouteBoundary(): void
    {
        $request = Services::request();
        $request->getUri()->setPath('/api/v1/imports/employees/template');
        Services::injectMock('tenant', $this->tenantWith([]));

        $result = (new ImportPermissionFilter())->before($request);
        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame(403, $result->getStatusCode());
        self::assertStringContainsString('employees.bulk_import', $result->getBody());
    }

    public function testMatchingRegistryPermissionAllowsRequest(): void
    {
        $request = Services::request();
        $request->getUri()->setPath('/api/v1/imports/employees/template');
        Services::injectMock('tenant', $this->tenantWith(['employees.bulk_import']));

        self::assertNull((new ImportPermissionFilter())->before($request));
    }

    private function tenantWith(array $permissions): TenantContext
    {
        return new class($permissions) extends TenantContext {
            public function __construct(private readonly array $permissions)
            {
                $this->setCompanyId(1);
                $this->setUserId(2);
                $this->setRoleId(3);
            }

            public function hasPermission(string $slug): bool
            {
                return in_array($slug, $this->permissions, true);
            }
        };
    }
}
