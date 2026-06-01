<?php

namespace Tests\Unit;

use App\Libraries\Performance\CompetencyService;
use App\Libraries\Performance\SuccessionService;
use App\Libraries\Recruitment\TalentPoolService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk pure-function helpers di Horizon 3 services
 * (Sprint 14 + 17). Coverage:
 *
 *   H3.1 CompetencyService::calcGapScore + normalizeSlugCode
 *   H3.2 SuccessionService::readinessFromGap
 *   H3.6 TalentPoolService::matchScore + boxFromGrid + boxLabel +
 *        normalizeSkillSlug
 */
class Horizon3ServicesTest extends TestCase
{
    // ─── H3.1 CompetencyService ─────────────────────────────────

    public function testCalcGapScoreNoProfile(): void
    {
        $r = CompetencyService::calcGapScore([], []);
        $this->assertSame(0.0, $r['gap_score']);
        $this->assertSame(100.0, $r['readiness_pct']);
        $this->assertSame(0, $r['missing_critical']);
    }

    public function testCalcGapScorePerfectMatch(): void
    {
        // Profile butuh competency 1 level 4 (weight 1) dan competency 2 level 3 (weight 2).
        // Karyawan punya level persis sesuai → gap = 0.
        $profile = [
            ['competency_id' => 1, 'target_level' => 4, 'weight' => 1, 'is_critical' => 0],
            ['competency_id' => 2, 'target_level' => 3, 'weight' => 2, 'is_critical' => 0],
        ];
        $current = [1 => 4, 2 => 3];
        $r = CompetencyService::calcGapScore($profile, $current);
        $this->assertSame(0.0, $r['gap_score']);
        $this->assertSame(100.0, $r['readiness_pct']);
        $this->assertSame(0, $r['missing_critical']);
    }

    public function testCalcGapScorePartialMatch(): void
    {
        // Comp 1 target 4, current 2 → gap 2 × weight 1 = 2
        // Comp 2 target 3, current 3 → gap 0
        // Max gap weighted = 4×1 + 3×2 = 10. Actual = 2. Score = 20%.
        $profile = [
            ['competency_id' => 1, 'target_level' => 4, 'weight' => 1, 'is_critical' => 0],
            ['competency_id' => 2, 'target_level' => 3, 'weight' => 2, 'is_critical' => 0],
        ];
        $current = [1 => 2, 2 => 3];
        $r = CompetencyService::calcGapScore($profile, $current);
        $this->assertEqualsWithDelta(20.0, $r['gap_score'], 0.01);
        $this->assertEqualsWithDelta(80.0, $r['readiness_pct'], 0.01);
    }

    public function testCalcGapScoreCriticalCount(): void
    {
        $profile = [
            ['competency_id' => 1, 'target_level' => 4, 'weight' => 1, 'is_critical' => 1],
            ['competency_id' => 2, 'target_level' => 3, 'weight' => 1, 'is_critical' => 1],
            ['competency_id' => 3, 'target_level' => 2, 'weight' => 1, 'is_critical' => 0],
        ];
        $current = [1 => 2, 2 => 3, 3 => 1]; // gap di 1 (critical) & 3 (non-critical)
        $r = CompetencyService::calcGapScore($profile, $current);
        $this->assertSame(1, $r['missing_critical']);
    }

    public function testCalcGapScoreMissingCompetencyEntirely(): void
    {
        // Comp 1 target 4 tapi karyawan tidak ada assessment → current 0
        $profile = [['competency_id' => 1, 'target_level' => 4, 'weight' => 1, 'is_critical' => 1]];
        $r = CompetencyService::calcGapScore($profile, []);
        $this->assertEqualsWithDelta(100.0, $r['gap_score'], 0.01);
        $this->assertEqualsWithDelta(0.0, $r['readiness_pct'], 0.01);
        $this->assertSame(1, $r['missing_critical']);
    }

    public function testNormalizeSlugCode(): void
    {
        $this->assertSame('COMMUNICATION_LEADERSHIP',
            CompetencyService::normalizeSlugCode('Communication Leadership'));
        $this->assertSame('TECH_PHP',
            CompetencyService::normalizeSlugCode('  Tech / PHP  '));
        $this->assertSame('CORE_VALUES_INTEGRITY',
            CompetencyService::normalizeSlugCode('Core Values: Integrity'));
        // Truncated ke 32 char max
        $long = CompetencyService::normalizeSlugCode(
            'A Very Long Competency Name That Exceeds Limit'
        );
        $this->assertLessThanOrEqual(32, strlen($long));
    }

    // ─── H3.2 SuccessionService ─────────────────────────────────

    public function testReadinessFromGap(): void
    {
        $this->assertSame('READY_NOW',     SuccessionService::readinessFromGap(0));
        $this->assertSame('READY_NOW',     SuccessionService::readinessFromGap(9.99));
        $this->assertSame('READY_1Y',      SuccessionService::readinessFromGap(10));
        $this->assertSame('READY_1Y',      SuccessionService::readinessFromGap(24.99));
        $this->assertSame('READY_2Y',      SuccessionService::readinessFromGap(25));
        $this->assertSame('READY_2Y',      SuccessionService::readinessFromGap(44.99));
        $this->assertSame('READY_3Y_PLUS', SuccessionService::readinessFromGap(45));
        $this->assertSame('READY_3Y_PLUS', SuccessionService::readinessFromGap(69.99));
        $this->assertSame('NOT_READY',     SuccessionService::readinessFromGap(70));
        $this->assertSame('NOT_READY',     SuccessionService::readinessFromGap(100));
    }

    // ─── H3.6 TalentPoolService ─────────────────────────────────

    public function testNormalizeSkillSlug(): void
    {
        $this->assertSame('php_laravel',
            TalentPoolService::normalizeSkillSlug('PHP / Laravel'));
        $this->assertSame('vue_js',
            TalentPoolService::normalizeSkillSlug('Vue.js'));
        $this->assertSame('aws_solutions_architect',
            TalentPoolService::normalizeSkillSlug('AWS Solutions Architect'));
    }

    public function testMatchScoreNoRequired(): void
    {
        $r = TalentPoolService::matchScore([], []);
        $this->assertSame(0.0, $r['score']);
    }

    public function testMatchScorePerfectMatch(): void
    {
        $candidate = [
            ['slug' => 'php', 'level' => 3],
            ['slug' => 'mysql', 'level' => 3],
        ];
        $required = [
            ['slug' => 'php', 'min_level' => 3, 'weight' => 1],
            ['slug' => 'mysql', 'min_level' => 3, 'weight' => 1],
        ];
        $r = TalentPoolService::matchScore($candidate, $required);
        $this->assertSame(100.0, $r['score']);
        $this->assertSame(2, $r['matches']);
        $this->assertSame(0, $r['missing']);
    }

    public function testMatchScoreSurplusCappedAt100(): void
    {
        // Candidate level 4 vs required min 2 → surplus 2 → multiplier 1.2
        // Earned weight = 1 × 1.2 = 1.2, total weight = 1 → 120% capped ke 100
        $candidate = [['slug' => 'php', 'level' => 4]];
        $required = [['slug' => 'php', 'min_level' => 2, 'weight' => 1]];
        $r = TalentPoolService::matchScore($candidate, $required);
        $this->assertSame(100.0, $r['score']);
        $this->assertSame(1, $r['matches']);
    }

    public function testMatchScoreMissingSkillCountedAsZero(): void
    {
        $candidate = [['slug' => 'php', 'level' => 3]];
        $required = [
            ['slug' => 'php', 'min_level' => 3, 'weight' => 1],
            ['slug' => 'docker', 'min_level' => 3, 'weight' => 1],
        ];
        $r = TalentPoolService::matchScore($candidate, $required);
        // 1 match dari 2 required dengan weight equal → score ≈ 50%
        $this->assertEqualsWithDelta(50.0, $r['score'], 5);
        $this->assertSame(1, $r['matches']);
        $this->assertSame(1, $r['missing']);
    }

    public function testMatchScorePartialBelowMin(): void
    {
        // Candidate level 2 vs min 4 → partial credit 50%
        $candidate = [['slug' => 'php', 'level' => 2]];
        $required = [['slug' => 'php', 'min_level' => 4, 'weight' => 1]];
        $r = TalentPoolService::matchScore($candidate, $required);
        $this->assertEqualsWithDelta(50.0, $r['score'], 0.01);
        $this->assertSame(0, $r['matches']); // partial = tetap di "missing"
    }

    public function testBoxFromGrid(): void
    {
        // Lower-left: low perf low potential → box 1
        $this->assertSame(1, TalentPoolService::boxFromGrid(1.5, 1.5));
        $this->assertSame(1, TalentPoolService::boxFromGrid(2, 2));
        // Center: med perf med potential → box 5
        $this->assertSame(5, TalentPoolService::boxFromGrid(3, 3));
        // Upper-right: star → box 9
        $this->assertSame(9, TalentPoolService::boxFromGrid(5, 5));
        $this->assertSame(9, TalentPoolService::boxFromGrid(4.5, 4.5));
        // Bottom-right: high perf low potential → box 3 (Solid Performer)
        $this->assertSame(3, TalentPoolService::boxFromGrid(5, 1));
        // Top-left: low perf high potential → box 7 (Latent Talent)
        $this->assertSame(7, TalentPoolService::boxFromGrid(1, 5));
    }

    public function testBoxLabel(): void
    {
        $this->assertSame('Underperformer', TalentPoolService::boxLabel(1));
        $this->assertSame('Core Player', TalentPoolService::boxLabel(5));
        $this->assertSame('Star', TalentPoolService::boxLabel(9));
        $this->assertSame('Latent Talent', TalentPoolService::boxLabel(7));
        $this->assertSame('Unknown', TalentPoolService::boxLabel(99));
    }
}