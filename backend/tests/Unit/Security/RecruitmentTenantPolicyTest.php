<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Libraries\Recruitment\RecruitmentHireService;
use App\Repositories\Recruitment\RecruitmentRepository;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

final class RecruitmentTenantPolicyTest extends TestCase
{
    public function testRecruitmentReadRepositoryFailsClosedWithoutCompanyContext(): void
    {
        $repository = new RecruitmentRepository($this->createMock(BaseConnection::class));

        foreach ([
            static fn () => $repository->findCandidate(10, 0),
            static fn () => $repository->funnelMetrics(null, 0, '2026-01-01'),
            static fn () => $repository->staleCandidates(0, '2026-01-01', ['HIRED']),
            static fn () => $repository->onlineTestAssignments(10, 0),
            static fn () => $repository->pipelineCounts(0, null),
            static fn () => $repository->resolvePipelineStages(10, 0),
        ] as $operation) {
            try {
                $operation();
                self::fail('Repository must reject a missing company context.');
            } catch (\DomainException $exception) {
                self::assertSame('Active company context is required', $exception->getMessage());
            }
        }
    }

    public function testRecruitmentReadsAndStageGatesAreTenantQualified(): void
    {
        $repository = file_get_contents(APPPATH . 'Repositories/Recruitment/RecruitmentRepository.php');
        $service = file_get_contents(APPPATH . 'Libraries/Recruitment/RecruitmentService.php');
        $stageService = file_get_contents(APPPATH . 'Libraries/Recruitment/RecruitmentStageService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/RecruitmentController.php');
        $offers = file_get_contents(APPPATH . 'Libraries/Recruitment/OfferService.php');
        self::assertNotFalse($repository);
        self::assertNotFalse($service);
        self::assertNotFalse($stageService);
        self::assertNotFalse($controller);
        self::assertNotFalse($offers);

        self::assertStringContainsString('c.company_id = h.company_id', $repository);
        self::assertStringContainsString('jp.company_id = h.company_id', $repository);
        self::assertStringContainsString('ot.company_id = ota.company_id', $repository);
        self::assertStringContainsString("->where('ota.company_id', \$companyId)", $repository);
        self::assertStringContainsString("->where('company_id', \$companyId)", $repository);
        self::assertStringContainsString('moveStage(int $candidateId, int $companyId', $service);
        self::assertStringContainsString('onlineTestCompletionStatus(int $candidateId, int $companyId)', $service);
        self::assertStringContainsString('resolvePipelineStages(int $jobPostingId, int $companyId)', $service);
        self::assertStringContainsString('new RecruitmentStageService()', $service);
        self::assertStringContainsString("->where('company_id', \$companyId)", $stageService);
        self::assertStringContainsString('transBegin()', $stageService);
        self::assertStringContainsString('transCommit()', $stageService);
        self::assertStringContainsString('transRollback()', $stageService);
        self::assertStringContainsString("(int) \$row['company_id'], \$stage", $controller);
        self::assertGreaterThanOrEqual(2, substr_count($offers, "(int) \$offer['company_id']"));
    }

    public function testHireUseCaseRequiresTenantAndUsesTenantQualifiedLock(): void
    {
        $service = new RecruitmentHireService($this->createMock(BaseConnection::class));
        try {
            $service->convertToEmployee(10, 0);
            self::fail('Hire must reject a missing company context before database access.');
        } catch (\DomainException $exception) {
            self::assertSame('Active company context is required', $exception->getMessage());
        }

        $hire = file_get_contents(APPPATH . 'Libraries/Recruitment/RecruitmentHireService.php');
        $facade = file_get_contents(APPPATH . 'Libraries/Recruitment/RecruitmentService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/RecruitmentController.php');
        self::assertNotFalse($hire);
        self::assertNotFalse($facade);
        self::assertNotFalse($controller);
        self::assertStringContainsString('WHERE id = ? AND company_id = ? AND deleted_at IS NULL FOR UPDATE', $hire);
        self::assertStringContainsString("->where('company_id', \$companyId)", $hire);
        self::assertStringContainsString('convertToEmployee(int $candidateId, int $companyId', $facade);
        self::assertStringContainsString('new RecruitmentHireService()', $facade);
        self::assertStringContainsString("(int) \$row['company_id']", $controller);
        self::assertStringNotContainsString('copyCandidateDocsToEmployee', $facade);
        self::assertStringNotContainsString('autoGenerateContract', $facade);
    }
}
