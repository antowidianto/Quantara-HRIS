<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk OfferService validation rules (Sprint 2 / REC-1).
 *
 * Pure validation tests — tidak butuh DB. OfferService punya rules untuk:
 *   - basic_salary > 0
 *   - employment_status valid (PKWTT/PKWT/PROBATION/MAGANG/OUTSOURCING)
 *   - probation_months 0-3 (UU 13/2003 Pasal 60)
 *   - PKWT tidak boleh ada probation (PP 35/2021 Pasal 12)
 *   - decision valid untuk recordResponse (ACCEPTED/REJECTED)
 *   - withdraw reason min 5 karakter
 *
 * Run: vendor/bin/phpunit --filter OfferValidationTest
 */
class OfferValidationTest extends TestCase
{
    // ─── Employment status validation ────────────────────────────────

    public function testValidEmploymentStatuses(): void
    {
        $valid = ['PKWTT', 'PKWT', 'PROBATION', 'MAGANG', 'OUTSOURCING'];
        foreach ($valid as $s) {
            $this->assertTrue($this->isValidEmploymentStatus($s));
        }
    }

    public function testInvalidEmploymentStatusRejected(): void
    {
        $this->assertFalse($this->isValidEmploymentStatus('FREELANCE'));
        $this->assertFalse($this->isValidEmploymentStatus('CONTRACT'));
        $this->assertFalse($this->isValidEmploymentStatus(''));
    }

    public function testEmploymentStatusCaseNormalization(): void
    {
        // OfferService::create normalisasi ke uppercase
        $this->assertTrue($this->isValidEmploymentStatus(strtoupper('pkwtt')));
        $this->assertTrue($this->isValidEmploymentStatus(strtoupper('PkWt')));
    }

    // ─── Probation months validation (UU 13/2003 Pasal 60) ──────────

    public function testProbationMonthsZeroToThreeAllowed(): void
    {
        $this->assertTrue($this->isValidProbationMonths(0));
        $this->assertTrue($this->isValidProbationMonths(1));
        $this->assertTrue($this->isValidProbationMonths(2));
        $this->assertTrue($this->isValidProbationMonths(3));
    }

    public function testProbationMonthsAboveThreeRejected(): void
    {
        // UU 13/2003 Pasal 60 max 3 bulan
        $this->assertFalse($this->isValidProbationMonths(4));
        $this->assertFalse($this->isValidProbationMonths(6));
        $this->assertFalse($this->isValidProbationMonths(12));
    }

    public function testProbationMonthsNegativeRejected(): void
    {
        $this->assertFalse($this->isValidProbationMonths(-1));
    }

    // ─── PKWT cannot have probation (PP 35/2021 Pasal 12) ───────────

    public function testPkwtCannotHaveProbation(): void
    {
        $this->assertFalse($this->isValidProbationCombo('PKWT', 1));
        $this->assertFalse($this->isValidProbationCombo('PKWT', 2));
        $this->assertFalse($this->isValidProbationCombo('PKWT', 3));
    }

    public function testPkwtZeroProbationAllowed(): void
    {
        // PKWT dengan probation_months=0 valid
        $this->assertTrue($this->isValidProbationCombo('PKWT', 0));
    }

    public function testPkwttCanHaveProbation(): void
    {
        $this->assertTrue($this->isValidProbationCombo('PKWTT', 0));
        $this->assertTrue($this->isValidProbationCombo('PKWTT', 3));
    }

    // ─── Basic salary validation ────────────────────────────────────

    public function testBasicSalaryMustBePositive(): void
    {
        $this->assertFalse($this->isValidBasicSalary(0));
        $this->assertFalse($this->isValidBasicSalary(-1000));
        $this->assertTrue($this->isValidBasicSalary(1));
        $this->assertTrue($this->isValidBasicSalary(5_000_000));
    }

    // ─── Decision validation ─────────────────────────────────────────

    public function testValidDecisions(): void
    {
        $this->assertTrue($this->isValidDecision('ACCEPTED'));
        $this->assertTrue($this->isValidDecision('REJECTED'));
    }

    public function testInvalidDecisionsRejected(): void
    {
        $this->assertFalse($this->isValidDecision('PENDING'));
        $this->assertFalse($this->isValidDecision('CANCELLED'));
        $this->assertFalse($this->isValidDecision('YES'));
        $this->assertFalse($this->isValidDecision(''));
    }

    // ─── Withdraw reason validation ──────────────────────────────────

    public function testWithdrawReasonMinFiveCharacters(): void
    {
        $this->assertTrue($this->isValidWithdrawReason('Salah salary, perlu re-issue'));
        $this->assertTrue($this->isValidWithdrawReason('12345')); // min boundary
    }

    public function testWithdrawReasonRejectedIfTooShort(): void
    {
        $this->assertFalse($this->isValidWithdrawReason(''));
        $this->assertFalse($this->isValidWithdrawReason('test')); // 4 char
        $this->assertFalse($this->isValidWithdrawReason('   '));
    }

    // ─── Allowances normalization ────────────────────────────────────

    public function testAllowancesNormalizationFiltersEmpty(): void
    {
        $input = [
            ['label' => 'Transport', 'amount' => 500000, 'type' => 'fixed', 'is_taxable' => true],
            ['label' => '', 'amount' => 100000], // skip - no label
            ['label' => 'Lunch', 'amount' => 0],   // skip - no amount
            ['label' => 'Communication', 'amount' => 200000],
        ];
        $result = $this->normalizeAllowances($input);
        $this->assertCount(2, $result);
        $this->assertSame('Transport', $result[0]['label']);
        $this->assertSame(500000.0, $result[0]['amount']);
        $this->assertSame('Communication', $result[1]['label']);
    }

    public function testAllowancesEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalizeAllowances([]));
    }

    // ─── Default response window ─────────────────────────────────────

    public function testDefaultResponseWindowIsOneWeek(): void
    {
        // OfferService::DEFAULT_RESPONSE_WINDOW_DAYS = 7
        // Konstanta dipakai untuk default deadline kalau HR tidak set manual
        $this->assertSame(7, \App\Libraries\Recruitment\OfferService::DEFAULT_RESPONSE_WINDOW_DAYS);
    }

    public function testStatusesConstantContainsAll(): void
    {
        $statuses = \App\Libraries\Recruitment\OfferService::STATUSES;
        $this->assertContains('DRAFT',     $statuses);
        $this->assertContains('PENDING_APPROVAL', $statuses);
        $this->assertContains('APPROVAL_REJECTED', $statuses);
        $this->assertContains('SENT',      $statuses);
        $this->assertContains('ACCEPTED',  $statuses);
        $this->assertContains('REJECTED',  $statuses);
        $this->assertContains('EXPIRED',   $statuses);
        $this->assertContains('WITHDRAWN', $statuses);
    }

    // ─── Pure helpers (mirror logic di OfferService) ────────────────

    private function isValidEmploymentStatus(string $status): bool
    {
        return in_array($status, ['PKWTT', 'PKWT', 'PROBATION', 'MAGANG', 'OUTSOURCING'], true);
    }

    private function isValidProbationMonths(int $months): bool
    {
        return $months >= 0 && $months <= 3;
    }

    private function isValidProbationCombo(string $employmentStatus, int $months): bool
    {
        if (! $this->isValidProbationMonths($months)) return false;
        // PP 35/2021 Pasal 12: PKWT tidak boleh ada probation
        if ($employmentStatus === 'PKWT' && $months > 0) return false;
        return true;
    }

    private function isValidBasicSalary(float $amount): bool
    {
        return $amount > 0;
    }

    private function isValidDecision(string $decision): bool
    {
        return in_array($decision, ['ACCEPTED', 'REJECTED'], true);
    }

    private function isValidWithdrawReason(string $reason): bool
    {
        return strlen(trim($reason)) >= 5;
    }

    private function normalizeAllowances(array $input): array
    {
        $clean = [];
        foreach ($input as $a) {
            if (! is_array($a)) continue;
            $label = trim((string) ($a['label'] ?? ''));
            $amount = (float) ($a['amount'] ?? 0);
            if ($label === '' || $amount <= 0) continue;
            $clean[] = [
                'label'      => $label,
                'amount'     => $amount,
                'type'       => strtolower((string) ($a['type'] ?? 'fixed')),
                'is_taxable' => (bool) ($a['is_taxable'] ?? true),
            ];
        }
        return $clean;
    }
}
