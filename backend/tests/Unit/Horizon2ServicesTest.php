<?php

namespace Tests\Unit;

use App\Libraries\Recruitment\CandidateScoringService;
use App\Libraries\Performance\PerformanceCascadingService;
use App\Libraries\Recruitment\ReferralProgramService;
use App\Libraries\Compliance\TurnoverAnalyticsService;
use App\Libraries\System\OcrService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk pure-function helpers di Horizon 2 services
 * (Sprint 11-13). Fokus: method static yang tidak butuh DB connection.
 *
 * Coverage:
 *   H2.4 CandidateScoringService::computeScore + per-criterion scorers
 *   H2.2 PerformanceCascadingService::treeFromRows, rollupProgress, computeFinalRating
 *   H2.5 ReferralProgramService::pickBestRule
 *   H2.6 TurnoverAnalyticsService::pctRate, classifyType, resolvePeriodRange
 *   H2.8 OcrService::parseText, normalizeNumber
 */
class Horizon2ServicesTest extends TestCase
{
    // ─── H2.4 Candidate Scoring ──────────────────────────────────

    public function testCandidateScoreComputeWeightedAverage(): void
    {
        // Two criteria with equal weight: online test 80, salary fit 100 (in range).
        // Expected: (80*1 + 100*1) / (1+1) = 90.
        $criteria = [
            ['criterion_type' => 'ONLINE_TEST_SCORE', 'weight' => 1, 'target_value' => 100, 'config_json' => null],
            ['criterion_type' => 'SALARY_FIT',        'weight' => 1, 'target_value' => null, 'config_json' => null],
        ];
        $context = [
            'online_test_score' => 80,
            'candidate' => ['expected_salary' => 11_000_000],
            'job'       => ['salary_min' => 10_000_000, 'salary_max' => 12_000_000],
        ];
        $result = CandidateScoringService::computeScore($criteria, $context);
        $this->assertEqualsWithDelta(90.0, $result['composite'], 0.01);
        $this->assertCount(2, $result['breakdown']);
    }

    public function testCandidateScoreOnlineTest(): void
    {
        // Score 80 / target 100 → 80%
        $score = CandidateScoringService::scoreOnlineTest(['online_test_score' => 80]);
        $this->assertSame(80.0, $score);

        // Tidak ada score → null
        $this->assertNull(CandidateScoringService::scoreOnlineTest([]));
    }

    public function testCandidateScoreSalaryFitInRange(): void
    {
        // Expected 11jt, range 10-12jt → fit 100
        $score = CandidateScoringService::scoreSalaryFit([
            'candidate' => ['expected_salary' => 11_000_000],
            'job'       => ['salary_min' => 10_000_000, 'salary_max' => 12_000_000],
        ]);
        $this->assertSame(100.0, $score);
    }

    public function testCandidateScoreSalaryFitAboveRange(): void
    {
        // Expected 15jt, range 10-12jt → over budget, score < 100
        $score = CandidateScoringService::scoreSalaryFit([
            'candidate' => ['expected_salary' => 15_000_000],
            'job'       => ['salary_min' => 10_000_000, 'salary_max' => 12_000_000],
        ]);
        $this->assertNotNull($score);
        $this->assertLessThan(100, $score);
    }

    public function testCandidateScoreExperience(): void
    {
        // 5 years pengalaman, target 5 → 100
        $score = CandidateScoringService::scoreExperience(
            ['candidate' => ['years_experience' => 5]],
            5
        );
        $this->assertSame(100.0, $score);

        // 2 years vs target 5 → 40
        $score = CandidateScoringService::scoreExperience(
            ['candidate' => ['years_experience' => 2]],
            5
        );
        $this->assertSame(40.0, $score);
    }

    // ─── H2.2 Performance Cascading ─────────────────────────────

    public function testTreeFromRowsBuildsHierarchy(): void
    {
        $rows = [
            ['id' => 1, 'parent_id' => null, 'progress_percent' => 0, 'weight' => 1],
            ['id' => 2, 'parent_id' => 1,    'progress_percent' => 80, 'weight' => 2],
            ['id' => 3, 'parent_id' => 1,    'progress_percent' => 40, 'weight' => 1],
            ['id' => 4, 'parent_id' => 2,    'progress_percent' => 100, 'weight' => 1],
        ];
        $tree = PerformanceCascadingService::treeFromRows($rows);
        $this->assertCount(1, $tree);
        $this->assertSame(1, $tree[0]['id']);
        $this->assertCount(2, $tree[0]['children']);
        $this->assertCount(1, $tree[0]['children'][0]['children']); // node 4 di bawah node 2
    }

    public function testRollupProgressWeightedAverage(): void
    {
        // Parent dengan 2 child: weight 2 progress 80, weight 1 progress 40
        // Expected: (80*2 + 40*1) / (2+1) = 200/3 ≈ 66.67
        $node = [
            'id' => 1, 'progress_percent' => 0, 'weight' => 1,
            'children' => [
                ['id' => 2, 'progress_percent' => 80, 'weight' => 2, 'children' => []],
                ['id' => 3, 'progress_percent' => 40, 'weight' => 1, 'children' => []],
            ],
        ];
        $progress = PerformanceCascadingService::rollupProgress($node);
        $this->assertEqualsWithDelta(66.67, $progress, 0.01);
    }

    public function testRollupProgressLeafReturnsOwn(): void
    {
        $leaf = ['id' => 5, 'progress_percent' => 75, 'weight' => 1];
        $this->assertSame(75.0, PerformanceCascadingService::rollupProgress($leaf));
    }

    public function testComputeFinalRatingWithAllSources(): void
    {
        // self=4, supervisor=4.5, peer=4.2 dengan bobot 20/60/20
        // Expected: (4*20 + 4.5*60 + 4.2*20) / 100 = (80+270+84)/100 = 4.34
        $rating = PerformanceCascadingService::computeFinalRating(
            ['self' => 4, 'supervisor' => 4.5, 'peer' => 4.2],
            ['self_weight' => 20, 'supervisor_weight' => 60, 'peer_weight' => 20],
        );
        $this->assertEqualsWithDelta(4.34, $rating, 0.01);
    }

    public function testComputeFinalRatingPeerNullExcludesPeerWeight(): void
    {
        // Peer null → bobot peer di-skip, normalisasi self/supervisor saja
        // self=4, supervisor=4.5 dengan bobot 20/60 → (80+270)/(20+60) = 4.375
        $rating = PerformanceCascadingService::computeFinalRating(
            ['self' => 4, 'supervisor' => 4.5, 'peer' => null],
            ['self_weight' => 20, 'supervisor_weight' => 60, 'peer_weight' => 20],
        );
        $this->assertEqualsWithDelta(4.375, $rating, 0.01);
    }

    public function testComputeFinalRatingAllNullReturnsNull(): void
    {
        $rating = PerformanceCascadingService::computeFinalRating(
            ['self' => null, 'supervisor' => null, 'peer' => null],
            ['self_weight' => 20, 'supervisor_weight' => 60, 'peer_weight' => 20],
        );
        $this->assertNull($rating);
    }

    // ─── H2.5 Referral Bonus Rule Picker ────────────────────────

    public function testPickBestRulePositionMatchWins(): void
    {
        $rules = [
            ['id' => 1, 'is_active' => 1, 'position_id' => null, 'job_grade_id' => null,
                'bonus_amount' => 1_000_000],  // catch-all
            ['id' => 2, 'is_active' => 1, 'position_id' => null, 'job_grade_id' => 5,
                'bonus_amount' => 2_000_000],  // grade match
            ['id' => 3, 'is_active' => 1, 'position_id' => 10, 'job_grade_id' => null,
                'bonus_amount' => 3_000_000],  // position match
            ['id' => 4, 'is_active' => 1, 'position_id' => 10, 'job_grade_id' => 5,
                'bonus_amount' => 5_000_000],  // both match (most specific)
        ];
        $best = ReferralProgramService::pickBestRule($rules, 10, 5);
        $this->assertSame(4, $best['id']);
    }

    public function testPickBestRuleFallbackCatchAll(): void
    {
        $rules = [
            ['id' => 1, 'is_active' => 1, 'position_id' => null, 'job_grade_id' => null,
                'bonus_amount' => 1_000_000],
            ['id' => 2, 'is_active' => 1, 'position_id' => 99, 'job_grade_id' => null,
                'bonus_amount' => 2_000_000],
        ];
        // Position 50 tidak match rule #2 → fallback ke catch-all #1
        $best = ReferralProgramService::pickBestRule($rules, 50, null);
        $this->assertSame(1, $best['id']);
    }

    public function testPickBestRuleSkipsInactive(): void
    {
        $rules = [
            ['id' => 1, 'is_active' => 0, 'position_id' => 10, 'job_grade_id' => 5,
                'bonus_amount' => 9_999_000],  // inactive — should be skipped
            ['id' => 2, 'is_active' => 1, 'position_id' => null, 'job_grade_id' => null,
                'bonus_amount' => 1_000_000],
        ];
        $best = ReferralProgramService::pickBestRule($rules, 10, 5);
        $this->assertSame(2, $best['id']);
    }

    // ─── H2.6 Turnover Analytics ────────────────────────────────

    public function testPctRate(): void
    {
        $this->assertSame(10.0, TurnoverAnalyticsService::pctRate(5, 50));
        $this->assertSame(0.0, TurnoverAnalyticsService::pctRate(5, 0));
    }

    public function testClassifyTypeResignationIsVoluntary(): void
    {
        $this->assertSame('VOLUNTARY',
            TurnoverAnalyticsService::classifyType(['source' => 'RESIGNATION']));
    }

    public function testClassifyTypeTerminationDefaultIsInvoluntary(): void
    {
        $this->assertSame('INVOLUNTARY',
            TurnoverAnalyticsService::classifyType([
                'source' => 'TERMINATION',
                'termination_type' => 'UNILATERAL',
            ]));
    }

    public function testClassifyTypeRetirementIsVoluntary(): void
    {
        $this->assertSame('VOLUNTARY',
            TurnoverAnalyticsService::classifyType([
                'source' => 'TERMINATION',
                'termination_type' => 'RETIREMENT',
            ]));
    }

    public function testResolvePeriodRangeMonth(): void
    {
        [$start, $end] = TurnoverAnalyticsService::resolvePeriodRange('MONTH', '2026-02');
        $this->assertSame('2026-02-01', $start);
        $this->assertSame('2026-02-28', $end);
    }

    public function testResolvePeriodRangeYear(): void
    {
        [$start, $end] = TurnoverAnalyticsService::resolvePeriodRange('YEAR', '2026');
        $this->assertSame('2026-01-01', $start);
        $this->assertSame('2026-12-31', $end);
    }

    // ─── H2.8 OCR Parser ────────────────────────────────────────

    public function testNormalizeNumberIndonesianFormat(): void
    {
        // Format ID: 1.234.567,89 → 1234567.89
        $this->assertEqualsWithDelta(1234567.89,
            OcrService::normalizeNumber('1.234.567,89'), 0.01);
    }

    public function testNormalizeNumberUsFormat(): void
    {
        // Format US: 1,234,567.89 → 1234567.89
        $this->assertEqualsWithDelta(1234567.89,
            OcrService::normalizeNumber('1,234,567.89'), 0.01);
    }

    public function testNormalizeNumberThousandSeparatorOnlyComma(): void
    {
        // 1,234 dengan 3 digit setelah koma → thousand separator (1234)
        $this->assertSame(1234.0, OcrService::normalizeNumber('1,234'));
    }

    public function testNormalizeNumberDecimalOnlyComma(): void
    {
        // 1,5 → decimal (Indonesian style)
        $this->assertSame(1.5, OcrService::normalizeNumber('1,5'));
    }

    public function testParseTextExtractsAllFields(): void
    {
        $text = "TOKO BUKU GRAMEDIA\n"
            . "Jl Sudirman 123\n"
            . "Tanggal: 15/03/2026\n"
            . "Item 1            Rp 50.000\n"
            . "Item 2            Rp 30.000\n"
            . "TOTAL: Rp 80.000\n";
        $result = OcrService::parseText($text);
        $this->assertSame('TOKO BUKU GRAMEDIA', $result['vendor']);
        $this->assertSame(80000.0, $result['total']);
        $this->assertSame('2026-03-15', $result['date']);
        $this->assertSame('IDR', $result['currency']);
        $this->assertGreaterThan(0.5, $result['confidence']);
    }

    public function testParseTextWithGrandTotalPrefersLargest(): void
    {
        $text = "Subtotal: 50000\nDiskon: 5000\nGrand Total: 45000\n";
        $result = OcrService::parseText($text);
        $this->assertSame(50000.0, $result['total']); // 50000 > 45000 (paling besar)
    }

    public function testParseTextEmptyReturnsLowConfidence(): void
    {
        $result = OcrService::parseText('');
        $this->assertNull($result['vendor']);
        $this->assertNull($result['total']);
        $this->assertNull($result['date']);
        $this->assertSame(0.0, $result['confidence']);
    }
}