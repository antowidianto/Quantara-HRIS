<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Repositories\Employee\JicQueryRepository;
use App\Repositories\Employee\JicWorkflowRepository;
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
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Employee/JicController.php');
        $repository = file_get_contents(APPPATH . 'Repositories/Employee/JicWorkflowRepository.php');
        self::assertIsString($controller);
        self::assertIsString($repository);
        self::assertStringNotContainsString('Database::connect', $controller);
        self::assertStringNotContainsString('->table(', $controller);
        self::assertStringContainsString('new JicWorkflowService()', $controller);
        self::assertStringContainsString("table('job_information_changes')->where('id', \$id)", $repository);
        self::assertStringContainsString("where('company_id', \$companyId)->update(\$payload)", $repository);
    }

    public function testDraftMutationsDelegateToFailClosedTenantRepository(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $repository = new JicWorkflowRepository($db);
        foreach ([
            fn () => $repository->employee(1, 0),
            fn () => $repository->hasActiveRequest(1, 0),
            fn () => $repository->create(['company_id' => 0], 0),
            fn () => $repository->update(1, 0, []),
        ] as $operation) {
            try {
                $operation();
                self::fail('JIC draft mutation accepted a missing tenant context');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('company context', $exception->getMessage());
            }
        }

        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Employee/JicController.php');
        self::assertIsString($controller);
        $draftBlock = substr(
            $controller,
            strpos($controller, 'public function create()'),
            strpos($controller, '// ─── WORKFLOW ACTIONS') - strpos($controller, 'public function create()'),
        );
        self::assertStringContainsString('new JicWorkflowRepository()', $draftBlock);
        self::assertStringNotContainsString('Database::connect', $draftBlock);
    }
}
