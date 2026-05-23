<?php

namespace Tests\Unit;

use App\Libraries\BonusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 17+ — Test untuk BonusCalculator::bonusEarningEntries.
 *
 * Helper ini di-pakai PayrollService::calculatePeriod untuk auto-merge
 * bonus run yang APPROVED/PAID ke payslip karyawan. Tax treatment
 * mapping harus konsisten supaya gross_taxable terhitung benar.
 */
final class BonusEarningEntriesTest extends TestCase
{
    public function testNullBonusInfoReturnsEmptyArray(): void
    {
        $this->assertSame([], BonusCalculator::bonusEarningEntries(null));
    }

    public function testEmptyBonusInfoReturnsEmptyArray(): void
    {
        $this->assertSame([], BonusCalculator::bonusEarningEntries([]));
    }

    public function testZeroAmountReturnsEmptyArray(): void
    {
        $this->assertSame([], BonusCalculator::bonusEarningEntries([
            'amount' => 0,
            'breakdown' => [],
        ]));
    }

    public function testNegativeAmountReturnsEmptyArray(): void
    {
        $this->assertSame([], BonusCalculator::bonusEarningEntries([
            'amount' => -1000,
            'breakdown' => [],
        ]));
    }

    public function testAmountWithoutBreakdownDefaultsToNormalTaxable(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 5000000,
            'breakdown' => [],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS', $entries[0]['code']);
        $this->assertSame('Bonus', $entries[0]['name']);
        $this->assertSame('BONUS', $entries[0]['category']);
        $this->assertSame(5000000.0, $entries[0]['amount']);
        $this->assertSame(1, $entries[0]['is_taxable']);
    }

    public function testSingleNormalTreatmentTaxable(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 3000000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 3000000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]['is_taxable']);
        $this->assertSame(3000000.0, $entries[0]['amount']);
    }

    public function testGrossTreatmentNormalizedToTaxable(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 2000000,
            'breakdown' => [
                ['tax_treatment' => 'GROSS', 'amount' => 2000000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS', $entries[0]['code']);
        $this->assertSame(1, $entries[0]['is_taxable']);
    }

    public function testNonTaxableTreatmentSeparateEntry(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 1000000,
            'breakdown' => [
                ['tax_treatment' => 'NON_TAXABLE', 'amount' => 1000000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS_NT', $entries[0]['code']);
        $this->assertSame('Bonus (Non-Pajak)', $entries[0]['name']);
        $this->assertSame(0, $entries[0]['is_taxable']);
        $this->assertSame(1000000.0, $entries[0]['amount']);
    }

    public function testMixedTaxableAndNonTaxableProducesTwoEntries(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 7000000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 5000000],
                ['tax_treatment' => 'NON_TAXABLE', 'amount' => 2000000],
            ],
        ]);

        $this->assertCount(2, $entries);

        $byCode = [];
        foreach ($entries as $e) $byCode[$e['code']] = $e;

        $this->assertArrayHasKey('BONUS', $byCode);
        $this->assertArrayHasKey('BONUS_NT', $byCode);
        $this->assertSame(5000000.0, $byCode['BONUS']['amount']);
        $this->assertSame(1, $byCode['BONUS']['is_taxable']);
        $this->assertSame(2000000.0, $byCode['BONUS_NT']['amount']);
        $this->assertSame(0, $byCode['BONUS_NT']['is_taxable']);
    }

    public function testMultipleNormalRunsAggregatedToOneEntry(): void
    {
        // Skenario realistis: KPI bonus + retention bonus di period yang sama
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 8000000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 3000000, 'run_name' => 'KPI Q1'],
                ['tax_treatment' => 'NORMAL', 'amount' => 5000000, 'run_name' => 'Retention Bonus'],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS', $entries[0]['code']);
        $this->assertSame(8000000.0, $entries[0]['amount']);
        $this->assertSame(1, $entries[0]['is_taxable']);
    }

    public function testGrossUpTreatmentTreatedAsTaxableForNow(): void
    {
        // Sprint 17+: GROSS_UP belum di-pipe ke PPh21 iteration
        // → fallback ke taxable=1 supaya tidak under-tax
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 4000000,
            'breakdown' => [
                ['tax_treatment' => 'GROSS_UP', 'amount' => 4000000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]['is_taxable']);
    }

    public function testEmptyTreatmentNormalizedToNormal(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 1500000,
            'breakdown' => [
                ['tax_treatment' => '', 'amount' => 1500000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS', $entries[0]['code']);
        $this->assertSame(1, $entries[0]['is_taxable']);
    }

    public function testLowercaseTreatmentNormalizedViaUpper(): void
    {
        // tax_treatment dari DB kadang bisa lowercase kalau seed legacy
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 1000000,
            'breakdown' => [
                ['tax_treatment' => 'non_taxable', 'amount' => 1000000],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS_NT', $entries[0]['code']);
        $this->assertSame(0, $entries[0]['is_taxable']);
    }

    public function testZeroAmountInBreakdownSkipped(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 5000000,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 5000000],
                ['tax_treatment' => 'NON_TAXABLE', 'amount' => 0],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('BONUS', $entries[0]['code']);
    }

    public function testFractionalAmountRoundedToTwoDecimals(): void
    {
        $entries = BonusCalculator::bonusEarningEntries([
            'amount' => 1234567.456,
            'breakdown' => [
                ['tax_treatment' => 'NORMAL', 'amount' => 1234567.456],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame(1234567.46, $entries[0]['amount']);
    }
}