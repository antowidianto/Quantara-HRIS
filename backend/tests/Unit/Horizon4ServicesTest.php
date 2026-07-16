<?php

namespace Tests\Unit;

use App\Libraries\Analytics\AiAssistantService;
use App\Libraries\Manpower\WorkforceSimulationService;
use App\Libraries\Performance\PulseSurveyService;
use App\Libraries\Analytics\CompensationIntelligenceService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk pure-function helpers di Horizon 4 services
 * (Sprint 19+). Coverage:
 *
 *   H4.1 AiAssistantService::redactPII, estimateTokens, buildSystemPrompt
 *   H4.3 WorkforceSimulationService::poissonRandom, percentile, simulateScenarioPure
 *   H4.4 PulseSurveyService::submissionHash, tenureBucketFromMonths,
 *        calcENPS, calcBurnoutScore
 *   H4.5 CompensationIntelligenceService::calcCompaRatio, compaBand,
 *        calcGenderGap, detectOutliers, coefficientOfVariation
 */
class Horizon4ServicesTest extends TestCase
{
    // ─── H4.1 AiAssistantService ────────────────────────────────

    public function testRedactPiiDetectsNik(): void
    {
        $r = AiAssistantService::redactPII('NIK saya 1234567890123456 mohon dicek');
        $this->assertTrue($r['redacted']);
        $this->assertStringContainsString('[REDACTED_NIK]', $r['text']);
        $this->assertStringNotContainsString('1234567890123456', $r['text']);
    }

    public function testRedactPiiDetectsNpwp(): void
    {
        $r = AiAssistantService::redactPII('NPWP saya 12.345.678.9-012.345');
        $this->assertTrue($r['redacted']);
        $this->assertStringContainsString('[REDACTED_NPWP]', $r['text']);
    }

    public function testRedactPiiDetectsEmail(): void
    {
        $r = AiAssistantService::redactPII('Email saya budi.santoso@example.com untuk dihubungi');
        $this->assertTrue($r['redacted']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $r['text']);
    }

    public function testRedactPiiDetectsPhone(): void
    {
        $r = AiAssistantService::redactPII('HP 081234567890 saya');
        $this->assertTrue($r['redacted']);
        $this->assertStringContainsString('[REDACTED_PHONE]', $r['text']);
    }

    public function testRedactPiiCleanInputUnchanged(): void
    {
        $r = AiAssistantService::redactPII('Bagaimana cara mengajukan cuti?');
        $this->assertFalse($r['redacted']);
        $this->assertSame(0, $r['hits']);
        $this->assertSame('Bagaimana cara mengajukan cuti?', $r['text']);
    }

    public function testEstimateTokens(): void
    {
        // 35 chars / 3.5 = 10 tokens
        $this->assertSame(10, AiAssistantService::estimateTokens('Hello world this is a test sentence'));
        $this->assertSame(0, AiAssistantService::estimateTokens(''));
    }

    public function testBuildSystemPromptIncludesUserName(): void
    {
        $prompt = AiAssistantService::buildSystemPrompt([
            'full_name' => 'Budi Santoso', 'role' => 'hr_admin', 'permissions' => ['payroll.view'],
        ]);
        $this->assertStringContainsString('Budi Santoso', $prompt);
        $this->assertStringContainsString('hr_admin', $prompt);
        $this->assertStringContainsString('payroll', $prompt);
    }

    public function testAiResponsePolicyBlocksUnauthorizedLinksAndApiPaths(): void
    {
        $reply = 'Coba [Admin Users](/admin/users), [Payroll](/payroll/periods), '
            . '[Cuti](/leaves/new), atau GET /api/v1/admin/users.';
        $filtered = AiAssistantService::enforceResponsePolicy($reply, [
            'role' => 'employee', 'permissions' => [],
        ]);

        $this->assertStringNotContainsString('](/admin/users)', $filtered);
        $this->assertStringNotContainsString('](/payroll/periods)', $filtered);
        $this->assertStringContainsString('[Cuti](/leaves/new)', $filtered);
        $this->assertStringNotContainsString('/api/v1/admin/users', $filtered);
        $this->assertStringContainsString('[akses API dibatasi]', $filtered);
    }

    public function testAiResponsePolicyAllowsPermissionedLinks(): void
    {
        $filtered = AiAssistantService::enforceResponsePolicy(
            '[Payroll](/payroll/periods) dan [Profil](/profile)',
            ['role' => 'hr_admin', 'permissions' => ['payroll.view']],
        );

        $this->assertStringContainsString('[Payroll](/payroll/periods)', $filtered);
        $this->assertStringContainsString('[Profil](/profile)', $filtered);
    }

    // ─── H4.3 WorkforceSimulationService ────────────────────────

    public function testPercentile(): void
    {
        $sorted = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        $this->assertEqualsWithDelta(19.0, WorkforceSimulationService::percentile($sorted, 0.10), 1);
        $this->assertEqualsWithDelta(55.0, WorkforceSimulationService::percentile($sorted, 0.50), 1);
        $this->assertEqualsWithDelta(91.0, WorkforceSimulationService::percentile($sorted, 0.90), 1);
    }

    public function testPercentileEmpty(): void
    {
        $this->assertSame(0.0, WorkforceSimulationService::percentile([], 0.50));
    }

    public function testPercentileSingle(): void
    {
        $this->assertSame(42.0, WorkforceSimulationService::percentile([42], 0.10));
    }

    public function testPoissonRandomZeroLambda(): void
    {
        $this->assertSame(0, WorkforceSimulationService::poissonRandom(0));
        $this->assertSame(0, WorkforceSimulationService::poissonRandom(-5));
    }

    public function testPoissonRandomDistribution(): void
    {
        // Sample 1000 with lambda=5, expect mean ≈ 5
        mt_srand(42);
        $samples = [];
        for ($i = 0; $i < 1000; $i++) {
            $samples[] = WorkforceSimulationService::poissonRandom(5);
        }
        $mean = array_sum($samples) / count($samples);
        $this->assertGreaterThan(4.0, $mean);
        $this->assertLessThan(6.0, $mean);
    }

    public function testSimulateScenarioPureBasic(): void
    {
        $result = WorkforceSimulationService::simulateScenarioPure(
            startHeadcount: 100,
            avgSalary: 10_000_000,
            assumptions: [
                'horizon_months' => 12,
                'monthly_attrition_rate' => 0.01,
                'hiring_plan' => array_fill(0, 12, 1),
                'salary_inflation_annual_pct' => 0.0,
                'iterations' => 200,
            ],
            seed: 42,
        );
        // p10 ≤ p50 ≤ p90 (ascending)
        $this->assertLessThanOrEqual($result['p50_headcount_end'], $result['p10_headcount_end']);
        $this->assertGreaterThanOrEqual($result['p50_headcount_end'], $result['p90_headcount_end']);
        // 12 month distribution
        $this->assertCount(12, $result['distribution']);
        $this->assertSame(200, $result['iterations_run']);
    }

    // ─── H4.4 PulseSurveyService ────────────────────────────────

    public function testSubmissionHashIsDeterministic(): void
    {
        $h1 = PulseSurveyService::submissionHash(123, 5, 'salt');
        $h2 = PulseSurveyService::submissionHash(123, 5, 'salt');
        $h3 = PulseSurveyService::submissionHash(124, 5, 'salt');
        $this->assertSame($h1, $h2);
        $this->assertNotSame($h1, $h3);
        $this->assertSame(64, strlen($h1)); // SHA256 hex
    }

    public function testTenureBucketFromMonths(): void
    {
        $this->assertSame('LESS_1Y', PulseSurveyService::tenureBucketFromMonths(0));
        $this->assertSame('LESS_1Y', PulseSurveyService::tenureBucketFromMonths(11));
        $this->assertSame('1_3Y', PulseSurveyService::tenureBucketFromMonths(12));
        $this->assertSame('1_3Y', PulseSurveyService::tenureBucketFromMonths(35));
        $this->assertSame('3_5Y', PulseSurveyService::tenureBucketFromMonths(36));
        $this->assertSame('5_10Y', PulseSurveyService::tenureBucketFromMonths(60));
        $this->assertSame('OVER_10Y', PulseSurveyService::tenureBucketFromMonths(120));
    }

    public function testCalcENPSAllPromoter(): void
    {
        // 5 score 9-10 → 100% promoter, 0% detractor → 100
        $this->assertSame(100.0, PulseSurveyService::calcENPS([10, 10, 9, 9, 10]));
    }

    public function testCalcENPSAllDetractor(): void
    {
        // 5 score 0-6 → 0% promoter, 100% detractor → -100
        $this->assertSame(-100.0, PulseSurveyService::calcENPS([1, 2, 3, 4, 5]));
    }

    public function testCalcENPSMixed(): void
    {
        // 2 promoter (9, 10), 1 passive (8), 2 detractor (3, 5)
        // → (40% - 40%) = 0
        $this->assertSame(0.0, PulseSurveyService::calcENPS([9, 10, 8, 3, 5]));
    }

    public function testCalcENPSEmpty(): void
    {
        $this->assertSame(0.0, PulseSurveyService::calcENPS([]));
    }

    public function testCalcBurnoutScoreSimple(): void
    {
        // 3 question semua jawaban 4 (1-5 skala) → avg 4 → (4-1)/4 × 100 = 75
        $questions = [
            ['id' => 1, 'question_type' => 'BURNOUT', 'reverse_score' => 0],
            ['id' => 2, 'question_type' => 'BURNOUT', 'reverse_score' => 0],
            ['id' => 3, 'question_type' => 'BURNOUT', 'reverse_score' => 0],
        ];
        $answers = [1 => 4, 2 => 4, 3 => 4];
        $score = PulseSurveyService::calcBurnoutScore($answers, $questions);
        $this->assertSame(75.0, $score);
    }

    public function testCalcBurnoutScoreReverse(): void
    {
        // Question dengan reverse_score=1: jawaban 5 berarti LOW burnout
        // Reverse jadi 6-5=1 → avg 1 → 0% burnout
        $questions = [
            ['id' => 1, 'question_type' => 'BURNOUT', 'reverse_score' => 1],
        ];
        $answers = [1 => 5];
        $score = PulseSurveyService::calcBurnoutScore($answers, $questions);
        $this->assertSame(0.0, $score);
    }

    public function testCalcBurnoutScoreNoBurnoutQuestions(): void
    {
        $questions = [['id' => 1, 'question_type' => 'ENPS', 'reverse_score' => 0]];
        $answers = [1 => 8];
        $this->assertNull(PulseSurveyService::calcBurnoutScore($answers, $questions));
    }

    // ─── H4.5 CompensationIntelligenceService ───────────────────

    public function testCalcCompaRatio(): void
    {
        $this->assertSame(1.0, CompensationIntelligenceService::calcCompaRatio(10_000_000, 10_000_000));
        $this->assertSame(0.8, CompensationIntelligenceService::calcCompaRatio(8_000_000, 10_000_000));
        $this->assertSame(1.25, CompensationIntelligenceService::calcCompaRatio(12_500_000, 10_000_000));
        $this->assertSame(0.0, CompensationIntelligenceService::calcCompaRatio(5_000_000, 0));
    }

    public function testCompaBand(): void
    {
        $this->assertSame('UNDERPAID', CompensationIntelligenceService::compaBand(0.70));
        $this->assertSame('UNDERPAID', CompensationIntelligenceService::compaBand(0.79));
        $this->assertSame('DEVELOPING', CompensationIntelligenceService::compaBand(0.85));
        $this->assertSame('ON_BAND', CompensationIntelligenceService::compaBand(1.00));
        $this->assertSame('ON_BAND', CompensationIntelligenceService::compaBand(0.95));
        $this->assertSame('EXCEEDS', CompensationIntelligenceService::compaBand(1.10));
        $this->assertSame('OVERPAID', CompensationIntelligenceService::compaBand(1.30));
        $this->assertSame('OVERPAID', CompensationIntelligenceService::compaBand(2.00));
    }

    public function testCalcGenderGap(): void
    {
        $r = CompensationIntelligenceService::calcGenderGap(10_000_000, 9_000_000);
        $this->assertSame(1_000_000.0, $r['gap_amount']);
        $this->assertSame(10.0, $r['gap_pct']);
        $this->assertSame('MALE', $r['baseline']);
    }

    public function testCalcGenderGapNoMaleData(): void
    {
        $r = CompensationIntelligenceService::calcGenderGap(0, 9_000_000);
        $this->assertSame('NONE', $r['baseline']);
    }

    public function testDetectOutliersIQR(): void
    {
        // 9 nilai cluster sekitar 10jt + 1 outlier extreme 100jt
        $salaries = [10_000_000, 11_000_000, 10_500_000, 9_500_000, 11_500_000,
            10_200_000, 10_800_000, 9_800_000, 10_100_000, 100_000_000];
        $r = CompensationIntelligenceService::detectOutliers($salaries);
        $this->assertGreaterThan(0, count($r['outliers_high']),
            'Outlier 100M harus di atas Q3 + 1.5×IQR');
        // Cek nilai 100M ada di outliers (cek dengan in_array karena type
        // setelah filter bisa float atau int)
        $found = false;
        foreach ($r['outliers_high'] as $v) {
            if ((int) $v === 100_000_000) $found = true;
        }
        $this->assertTrue($found, 'Outlier 100M harus terdeteksi');
    }

    public function testDetectOutliersTooFewSamples(): void
    {
        $r = CompensationIntelligenceService::detectOutliers([10_000_000, 11_000_000]);
        $this->assertEmpty($r['outliers_low']);
        $this->assertEmpty($r['outliers_high']);
    }

    public function testCoefficientOfVariation(): void
    {
        // Identical values → CV = 0
        $this->assertSame(0.0, CompensationIntelligenceService::coefficientOfVariation([100, 100, 100]));
        // Some dispersion
        $cv = CompensationIntelligenceService::coefficientOfVariation([80, 100, 120]);
        $this->assertGreaterThan(0, $cv);
        $this->assertLessThan(0.5, $cv);
    }

    public function testCoefficientOfVariationSingleValue(): void
    {
        $this->assertSame(0.0, CompensationIntelligenceService::coefficientOfVariation([100]));
        $this->assertSame(0.0, CompensationIntelligenceService::coefficientOfVariation([]));
    }
}
