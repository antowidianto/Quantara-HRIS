<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Repositories\Employee\JicQueryRepository;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

final class JicQueryRepositoryPolicyTest extends TestCase
{
    public function testNonSuperAdminCannotQueryWithoutActiveCompany(): void
    {
        $repository = new JicQueryRepository($this->createMock(BaseConnection::class));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Active company context is required');
        $repository->paginate(0, 10, false, true, true, true, []);
    }

    public function testReadModelTenantQualifiesRootAndOrganizationJoins(): void
    {
        $source = file_get_contents(APPPATH . 'Repositories/Employee/JicQueryRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString("where('jic.company_id', \$companyId)", $source);
        foreach (['e.company_id', 'd_old.company_id', 'd_new.company_id', 'p_new.company_id', 'g_new.company_id'] as $scope) {
            self::assertStringContainsString($scope . ' = jic.company_id', $source);
        }
        self::assertStringContainsString("where('company_id', \$companyId)", $source);
        self::assertStringContainsString("where('jic.company_id', \$companyId)", $source);
        self::assertStringContainsString("where('company_id', \$companyId)->where('manager_employee_id'", $source);
    }

    public function testControllerDelegatesListQueryToRepository(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Employee/JicController.php');
        self::assertIsString($source);
        self::assertStringContainsString('new JicQueryRepository()', $source);
        $index = substr($source, strpos($source, 'public function index()'), strpos($source, 'public function show(') - strpos($source, 'public function index()'));
        self::assertStringNotContainsString('Database::connect', $index);
        $access = substr($source, strpos($source, 'private function loadJicForUser'), strpos($source, 'private function canActAsHr') - strpos($source, 'private function loadJicForUser'));
        self::assertStringNotContainsString('Database::connect', $access);
        self::assertStringContainsString('findDetail(', $access);
        self::assertStringContainsString('isSupervisor(', $access);
        self::assertStringContainsString('isDepartmentHead(', $access);
    }

    public function testJicIdMutationsCarryCompanyBoundary(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Employee/JicController.php');
        self::assertIsString($source);
        preg_match_all("/table\('job_information_changes'\).{0,180}?where\('id',.{0,220}?(?:update|delete)\(/s", $source, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[0] as $mutation) {
            self::assertStringContainsString("where('company_id'", $mutation, 'JIC mutation must retain the tenant key.');
        }
    }
}
