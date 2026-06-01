<?php

namespace Tests\Unit;

use App\Libraries\Employee\Employee360Service;
use App\Libraries\Lifecycle\ExitInterviewService;
use App\Libraries\Lifecycle\SeveranceCalculator;
use App\Libraries\Lifecycle\TerminationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Sprint 4 (Termination & Polish):
 *   - TerminationService: requiresBipartite() rules, status enum sanity
 *   - ExitInterviewService: computeNps() formula, score validation
 *   - Employee360Service: humanizeTenure()
 *
 * Tidak butuh DB; semua method yang di-test pure helper.
 *
 * Run: vendor/bin/phpunit --filter Sprint4TerminationPolishTest
 */
class Sprint4TerminationPolishTest extends TestCase
{
    // ─── TerminationService::requiresBipartite() ─────────────────────

    public function testRequiresBipartiteForUnilateralEfficiency(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'UNILATERAL', 'reason_code' => 'EFFICIENCY_LOSS'];
        $this->assertTrue($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForMutual(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'MUTUAL', 'reason_code' => 'EFFICIENCY_LOSS'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForViolationGross(): void
    {
        // Pelanggaran berat tidak butuh bipartite (PP 35/2021 Pasal 52)
        $svc = new TerminationService();
        $row = ['termination_type' => 'UNILATERAL', 'reason_code' => 'VIOLATION_GROSS'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForLongAbsent(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'UNILATERAL', 'reason_code' => 'LONG_ABSENT_PROHIBIT'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForRetirement(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'RETIREMENT', 'reason_code' => 'PENSION_RETIRE'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForContractEnd(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'CONTRACT_END', 'reason_code' => 'END_OF_PKWT'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testNoBipartiteForDeath(): void
    {
        $svc = new TerminationService();
        $row = ['termination_type' => 'DEATH', 'reason_code' => 'DEATH'];
        $this->assertFalse($svc->requiresBipartite($row));
    }

    public function testTerminationStatusesContainsAllExpected(): void
    {
        $expected = [
            'DRAFT', 'PENDING_BIPARTITE', 'PENDING_HR_DIRECTOR', 'PENDING_BOD',
            'APPROVED', 'EXECUTED', 'REJECTED', 'CANCELLED',
        ];
        foreach ($expected as $s) {
            $this->assertContains($s, TerminationService::STATUSES);
        }
    }

    public function testTerminationTypesContainsAllExpected(): void
    {
        $expected = ['UNILATERAL', 'MUTUAL', 'CONTRACT_END', 'RETIREMENT', 'DEATH', 'DISABILITY'];
        foreach ($expected as $t) {
            $this->assertContains($t, TerminationService::TYPES);
        }
    }

    public function testSeveranceCalculatorReasonsCoverAllTerminationCodes(): void
    {
        // Pastikan reason code di-recognize oleh SeveranceCalculator
        $checked = [
            'EFFICIENCY_LOSS', 'EFFICIENCY_PREVENT', 'CLOSURE_LOSS_2YR',
            'PKPU_BANKRUPTCY', 'PENSION_RETIRE', 'DEATH',
            'VIOLATION_GROSS', 'LONG_ABSENT_PROHIBIT', 'RESIGN',
            'END_OF_PKWT', 'CONTRACT_EXPIRY',
        ];
        foreach ($checked as $code) {
            $this->assertArrayHasKey($code, SeveranceCalculator::REASONS,
                "Reason {$code} harus tersedia di SeveranceCalculator::REASONS");
        }
    }

    // ─── ExitInterviewService::computeNps() ──────────────────────────

    public function testNpsEmptyArrayReturnsNull(): void
    {
        $svc = new ExitInterviewService();
        $this->assertNull($svc->computeNps([]));
        $this->assertNull($svc->computeNps([null, null]));
    }

    public function testNpsAllPromotersReturns100(): void
    {
        $svc = new ExitInterviewService();
        $this->assertSame(100.0, $svc->computeNps([9, 10, 9, 10]));
    }

    public function testNpsAllDetractorsReturnsNeg100(): void
    {
        $svc = new ExitInterviewService();
        $this->assertSame(-100.0, $svc->computeNps([0, 1, 5, 6]));
    }

    public function testNpsMixedComputesCorrectly(): void
    {
        $svc = new ExitInterviewService();
        // 2 promoters (9,10), 2 passives (7,8), 1 detractor (5) = 5 total
        // NPS = (40% promoter - 20% detractor) * 100 = 20%
        $this->assertSame(20.0, $svc->computeNps([9, 10, 7, 8, 5]));
    }

    public function testNpsAllPassivesReturnsZero(): void
    {
        $svc = new ExitInterviewService();
        // 7-8 = passive, no promoter no detractor
        $this->assertSame(0.0, $svc->computeNps([7, 7, 8, 8]));
    }

    public function testNpsIgnoresNullScores(): void
    {
        $svc = new ExitInterviewService();
        // null harus di-skip; sisa: 9 (promoter) + 10 (promoter) = 100% promoter
        $this->assertSame(100.0, $svc->computeNps([9, null, 10, null]));
    }

    public function testNpsCutoffsAreCorrect(): void
    {
        $svc = new ExitInterviewService();
        // 9 = promoter, 6 = detractor, 7 = passive, 8 = passive
        $this->assertSame(0.0, $svc->computeNps([9, 6])); // 50% - 50%
        // 8 is passive, not detractor
        $this->assertSame(50.0, $svc->computeNps([9, 8])); // 50% - 0%
    }

    // ─── ExitInterviewService constants sanity ───────────────────────

    public function testExitInterviewModesContainsAllExpected(): void
    {
        $expected = ['IN_PERSON', 'VIDEO', 'PHONE', 'WRITTEN', 'SELF_FILL'];
        foreach ($expected as $m) {
            $this->assertContains($m, ExitInterviewService::MODES);
        }
    }

    public function testExitInterviewRehireOptionsContainsAllExpected(): void
    {
        $expected = ['YES', 'NO', 'CONDITIONAL', 'UNKNOWN'];
        foreach ($expected as $r) {
            $this->assertContains($r, ExitInterviewService::REHIRE_OPTIONS);
        }
    }

    public function testPrimaryReasonOptionsHasMinimumExpectedKeys(): void
    {
        // Beberapa keys yang harus ada untuk fitur core
        $expectedKeys = [
            'BETTER_OFFER', 'CAREER_GROWTH', 'WORK_LIFE_BALANCE',
            'MANAGEMENT_ISSUE', 'COMPENSATION', 'PERSONAL', 'OTHER',
        ];
        foreach ($expectedKeys as $k) {
            $this->assertArrayHasKey($k, ExitInterviewService::PRIMARY_REASON_OPTIONS,
                "Primary reason {$k} harus ada");
        }
    }

    // ─── Employee360Service::humanizeTenure() ────────────────────────

    public function testTenureZeroOrLessReturnsKurangDariSatuBulan(): void
    {
        $svc = new Employee360Service();
        $this->assertSame('kurang dari 1 bulan', $svc->humanizeTenure(0));
        $this->assertSame('kurang dari 1 bulan', $svc->humanizeTenure(-1));
    }

    public function testTenureOneMonth(): void
    {
        $svc = new Employee360Service();
        $this->assertSame('1 bulan', $svc->humanizeTenure(1));
    }

    public function testTenureExactlyOneYear(): void
    {
        $svc = new Employee360Service();
        $this->assertSame('1 tahun', $svc->humanizeTenure(12));
    }

    public function testTenureYearsAndMonths(): void
    {
        $svc = new Employee360Service();
        $this->assertSame('2 tahun 3 bulan', $svc->humanizeTenure(27));
        $this->assertSame('5 tahun 11 bulan', $svc->humanizeTenure(71));
    }

    public function testTenureMultipleYearsExact(): void
    {
        $svc = new Employee360Service();
        $this->assertSame('10 tahun', $svc->humanizeTenure(120));
    }
}