<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Repositories\Recruitment\OnlineTestRepository;
use App\Libraries\Recruitment\OnlineTestAdminException;
use App\Libraries\Recruitment\OnlineTestAdminService;
use App\Libraries\Recruitment\OnlineTestBankImportParser;
use App\Libraries\Recruitment\OnlineTestSeedCatalog;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

final class OnlineTestTenantPolicyTest extends TestCase
{
    public function testAdminOnlineTestReadsAndMutationsFailClosedByCompany(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/OnlineTestController.php');
        self::assertNotFalse($source);
        self::assertGreaterThanOrEqual(20, substr_count($source, 'requireCompanyId()'));
        self::assertStringContainsString('new OnlineTestRepository()', $source);
        self::assertStringContainsString('->findTest(', $source);
        self::assertStringContainsString('->findAttempt(', $source);
        self::assertStringNotContainsString('Database::connect', $source);
        self::assertStringNotContainsString('->table(', $source);
        self::assertStringNotContainsString('ownsCompany(', $source);
        self::assertStringNotContainsString('tenantRow(', $source);

        $repository = file_get_contents(APPPATH . 'Repositories/Recruitment/OnlineTestRepository.php');
        self::assertNotFalse($repository);
        self::assertStringContainsString('findQuestion(int $id, int $companyId)', $repository);
    }

    public function testAssignmentAndAssessmentInvariantsAreValidatedServerSide(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/OnlineTestController.php');
        $adminService = file_get_contents(APPPATH . 'Libraries/Recruitment/OnlineTestAdminService.php');
        self::assertNotFalse($controller);
        self::assertNotFalse($adminService);
        self::assertStringContainsString('less_than_equal_to[100]', $controller);
        self::assertStringContainsString('less_than_equal_to[10]', $controller);
        self::assertStringContainsString("'points' => 'permit_empty|numeric|greater_than[0]'", $controller);
        self::assertStringContainsString('INVALID_TEST_NAME', $adminService);
        self::assertStringContainsString('INVALID_QUESTION_PROMPT', $adminService);
        self::assertStringContainsString('INVALID_DATE_RANGE', $adminService);
        self::assertStringContainsString('INVALID_ATTEMPT_LIMIT', $adminService);
    }

    public function testCandidatePortalDerivesTenantFromOwnedAssignment(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/CandidateOnlineTestController.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('ot.company_id = ota.company_id', $source);
        self::assertStringContainsString('jp.company_id = ota.company_id', $source);
        self::assertStringContainsString("->where('candidate_account_id', \$accountId)", $source);
        self::assertStringContainsString("->where('company_id >', 0)", $source);
        self::assertStringContainsString("->where('x.candidate_account_id', (int) \$account['id'])", $source);
        self::assertStringContainsString("->where('x.company_id', (int) \$assignment['company_id'])", $source);
        self::assertStringContainsString('getAssignmentForAttempt(int $attemptId, int $accountId)', $source);
        self::assertStringContainsString("->where('oa.candidate_account_id', \$accountId)", $source);
        self::assertStringNotContainsString('ownsAttempt(', $source);
    }

    public function testOnlineTestEngineRequiresTenantContextForEveryAttemptOperation(): void
    {
        $source = file_get_contents(APPPATH . 'Libraries/Recruitment/OnlineTestService.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('startAttempt(int $assignmentId, int $companyId', $source);
        self::assertStringContainsString('buildAttemptView(int $attemptId, int $companyId)', $source);
        self::assertStringContainsString('saveAnswer(int $attemptId, int $answerId, int $companyId', $source);
        self::assertStringContainsString('submitAttempt(int $attemptId, int $companyId)', $source);
        self::assertStringContainsString('gradeManualAnswer(int $attemptId, int $answerId, float $pointsAwarded, ?string $feedback, int $graderUserId, int $companyId)', $source);
        self::assertStringContainsString('autoSubmitExpired(int $attemptId, int $companyId)', $source);
        self::assertStringContainsString('buildResultView(int $attemptId, int $companyId', $source);
        self::assertStringContainsString('tenantAttempt(BaseConnection $db, int $attemptId, int $companyId)', $source);
        self::assertStringContainsString('tenantAnswers(BaseConnection $db, int $attemptId, int $companyId)', $source);
        self::assertStringContainsString("->where('x.company_id', \$companyId)", $source);
    }

    public function testAdminReadRepositoryFailsClosedAndTenantQualifiesJoins(): void
    {
        $repository = new OnlineTestRepository($this->createMock(BaseConnection::class));
        try {
            $repository->paginateTests(0, 1, 20);
            self::fail('Repository must reject a missing company context.');
        } catch (\DomainException $exception) {
            self::assertSame('Active company context is required', $exception->getMessage());
        }

        $source = file_get_contents(APPPATH . 'Repositories/Recruitment/OnlineTestRepository.php');
        self::assertNotFalse($source);
        self::assertStringContainsString("->where('company_id', \$companyId)", $source);
        self::assertStringContainsString("->where('oa.company_id', \$companyId)", $source);
        self::assertStringContainsString('c.company_id = oa.company_id', $source);
        self::assertStringContainsString('ot.company_id = oa.company_id', $source);
        self::assertStringContainsString('jp.company_id = oa.company_id', $source);
        self::assertStringContainsString("->where('t.company_id', \$companyId)", $source);
        self::assertStringContainsString("->where('x.company_id', \$companyId)", $source);
        self::assertStringContainsString('paginateBank(int $companyId', $source);
    }

    public function testAdminMutationUseCaseFailsClosedWithoutCompanyContext(): void
    {
        $service = new OnlineTestAdminService($this->createMock(BaseConnection::class));

        try {
            $service->createTest(0, 10, ['name' => 'Test', 'duration_minutes' => 30]);
            self::fail('Mutation use case must reject a missing company context.');
        } catch (OnlineTestAdminException $exception) {
            self::assertSame(403, $exception->httpStatus);
            self::assertSame('Company context tidak tersedia', $exception->getMessage());
        }

        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/OnlineTestController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('new OnlineTestAdminService()', $controller);
        foreach ([
            'createTest(', 'updateTest(', 'deleteTest(',
            'createQuestion(', 'updateQuestion(', 'deleteQuestion(',
            'seedQuestions(', 'importQuestionsFromBank(', 'importBankRows(',
            'createBankQuestion(', 'updateBankQuestion(', 'deleteBankQuestion(',
            'createAssignment(', 'updateAssignment(', 'cancelAssignment(', 'deleteAssignment(',
        ] as $operation) {
            self::assertStringContainsString('adminService()->' . $operation, $controller);
        }

        $serviceSource = file_get_contents(APPPATH . 'Libraries/Recruitment/OnlineTestAdminService.php');
        self::assertNotFalse($serviceSource);
        self::assertStringContainsString('count($bankRows) !== count($ids)', $serviceSource);
        self::assertStringContainsString("->where('company_id', \$companyId)->where('deleted_at IS NULL')", $serviceSource);
    }

    public function testQuestionBankSpreadsheetParserNormalizesRowsOutsideController(): void
    {
        $parser = new OnlineTestBankImportParser();
        $result = $parser->parseRows([
            ['question_type *', 'prompt *', 'difficulty', 'choices', 'correct_answer', 'tags'],
            ['MCQ_SINGLE', '  Dua tambah tiga? ', 'invalid', 'A=5|B=6', 'A', 'math|basic'],
            ['UNKNOWN', 'Invalid type', 'EASY', '', '', ''],
            ['', '', '', '', '', ''],
        ]);

        self::assertCount(1, $result['rows']);
        self::assertSame('Dua tambah tiga?', $result['rows'][0]['prompt']);
        self::assertSame('MEDIUM', $result['rows'][0]['difficulty']);
        self::assertSame('[{"key":"A","text":"5"},{"key":"B","text":"6"}]', $result['rows'][0]['choices']);
        self::assertSame('"A"', $result['rows'][0]['correct_answer']);
        self::assertSame('["math","basic"]', $result['rows'][0]['tags']);
        self::assertSame([['row' => 3, 'message' => 'question_type tidak valid']], $result['errors']);

        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/OnlineTestController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('new OnlineTestBankImportParser()', $controller);
        self::assertStringNotContainsString('normalizeBankImportPayload', $controller);
        self::assertStringNotContainsString('parseBankChoices', $controller);
    }

    public function testBuiltInQuestionCatalogIsIndependentFromHttpController(): void
    {
        $catalog = new OnlineTestSeedCatalog();
        self::assertCount(5, $catalog->forTemplate('TPA'));
        self::assertCount(5, $catalog->forTemplate('ENGLISH'));
        self::assertCount(8, $catalog->forTemplate('DISC'));
        self::assertSame([], $catalog->forTemplate('CUSTOM'));

        $controller = file_get_contents(APPPATH . 'Controllers/Api/V1/Recruitment/OnlineTestController.php');
        self::assertNotFalse($controller);
        self::assertStringContainsString('new OnlineTestSeedCatalog()', $controller);
        self::assertStringNotContainsString('PUSAKA = ?', $controller);
        self::assertStringNotContainsString('builtinSeed', $controller);
    }
}
