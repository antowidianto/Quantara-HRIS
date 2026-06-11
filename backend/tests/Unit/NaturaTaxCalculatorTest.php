<?php

namespace Tests\Unit;

use App\Libraries\Payroll\NaturaTaxCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk NaturaTaxCalculator (H2.10 — PMK 66/2023).
 *
 * Run: vendor/bin/phpunit --filter NaturaTaxCalculatorTest
 */
class NaturaTaxCalculatorTest extends TestCase
{
    // ─── monthlyTaxBase ──────────────────────────────────────────────

    public function testMonthlyTaxBaseNoThresholdFullyTaxable(): void
    {
        // Threshold 0 → seluruh nominal jadi tax base
        $this->assertSame(1_000_000.0, NaturaTaxCalculator::monthlyTaxBase(1_000_000, 0));
    }

    public function testMonthlyTaxBaseBelowMonthlyThresholdReturnsZero(): void
    {
        // Threshold 3jt/tahun = 250rb/bulan; nominal 200rb < 250rb → 0
        $this->assertSame(0.0, NaturaTaxCalculator::monthlyTaxBase(200_000, 3_000_000));
    }

    public function testMonthlyTaxBaseAtMonthlyThresholdReturnsZero(): void
    {
        // Edge: persis sama dengan threshold/12
        $this->assertSame(0.0, NaturaTaxCalculator::monthlyTaxBase(250_000, 3_000_000));
    }

    public function testMonthlyTaxBaseAboveMonthlyThresholdReturnsExcess(): void
    {
        // Threshold 3jt/tahun = 250rb/bulan; nominal 1jt → tax base 750rb
        $this->assertSame(750_000.0, NaturaTaxCalculator::monthlyTaxBase(1_000_000, 3_000_000));
    }

    public function testMonthlyTaxBaseZeroAmountReturnsZero(): void
    {
        $this->assertSame(0.0, NaturaTaxCalculator::monthlyTaxBase(0, 3_000_000));
        $this->assertSame(0.0, NaturaTaxCalculator::monthlyTaxBase(-100, 3_000_000));
    }

    // ─── buildEntry ──────────────────────────────────────────────────

    public function testBuildEntryNaturaWithoutThresholdFullyTaxable(): void
    {
        $base = ['code' => 'GYM', 'name' => 'Gym', 'category' => 'OTHER',
                 'amount' => 500_000, 'is_taxable' => 1];
        $entry = NaturaTaxCalculator::buildEntry($base, 0);
        $this->assertSame(1, $entry['is_natura']);
        $this->assertSame(500_000.0, $entry['natura_tax_base']);
        $this->assertSame(1, $entry['is_taxable']);
    }

    public function testBuildEntryNaturaBelowThresholdNotTaxable(): void
    {
        $base = ['amount' => 200_000, 'is_taxable' => 1];
        $entry = NaturaTaxCalculator::buildEntry($base, 3_000_000);
        $this->assertSame(1, $entry['is_natura']);
        $this->assertSame(0.0, $entry['natura_tax_base']);
        // Override is_taxable → 0 karena seluruhnya di bawah ambang
        $this->assertSame(0, $entry['is_taxable']);
    }

    public function testBuildEntryNaturaAboveThresholdPartialTaxable(): void
    {
        $base = ['amount' => 1_000_000, 'is_taxable' => 1];
        $entry = NaturaTaxCalculator::buildEntry($base, 3_000_000);
        $this->assertSame(1, $entry['is_natura']);
        $this->assertSame(750_000.0, $entry['natura_tax_base']);
        // is_taxable tetap 1 karena ada porsi taxable
        $this->assertSame(1, $entry['is_taxable']);
    }

    public function testBuildEntryPreservesOtherFields(): void
    {
        $base = ['code' => 'CAR', 'name' => 'Mobil Dinas',
                 'category' => 'TRANSPORT', 'amount' => 5_000_000, 'is_taxable' => 1];
        $entry = NaturaTaxCalculator::buildEntry($base, 0);
        $this->assertSame('CAR', $entry['code']);
        $this->assertSame('Mobil Dinas', $entry['name']);
        $this->assertSame('TRANSPORT', $entry['category']);
        $this->assertSame(5_000_000, $entry['amount']);
    }

    // ─── aggregate ───────────────────────────────────────────────────

    public function testAggregatePureCashOnly(): void
    {
        $earnings = [
            ['amount' => 5_000_000, 'is_taxable' => 1],
            ['amount' => 1_000_000, 'is_taxable' => 1],
            ['amount' => 500_000,   'is_taxable' => 0],
        ];
        $r = NaturaTaxCalculator::aggregate($earnings);
        $this->assertSame(6_500_000.0, $r['gross_earning']);
        $this->assertSame(6_500_000.0, $r['cash_earning']);
        $this->assertSame(6_000_000.0, $r['gross_taxable']); // hanya 2 entry pertama
        $this->assertSame(0.0, $r['natura_total']);
    }

    public function testAggregateWithNaturaSeparated(): void
    {
        $earnings = [
            ['amount' => 5_000_000, 'is_taxable' => 1, 'is_natura' => 0],
            ['amount' => 1_000_000, 'is_taxable' => 1, 'is_natura' => 1, 'natura_tax_base' => 750_000.0],
        ];
        $r = NaturaTaxCalculator::aggregate($earnings);
        $this->assertSame(6_000_000.0, $r['gross_earning']);   // total
        $this->assertSame(5_000_000.0, $r['cash_earning']);    // tanpa natura
        $this->assertSame(5_750_000.0, $r['gross_taxable']);   // 5jt + 750k tax base
        $this->assertSame(1_000_000.0, $r['natura_total']);
    }

    public function testAggregateNaturaBelowThresholdNotTaxable(): void
    {
        $earnings = [
            ['amount' => 5_000_000, 'is_taxable' => 1, 'is_natura' => 0],
            // Natura yang sudah di-set is_taxable=0 (di bawah ambang)
            ['amount' => 200_000, 'is_taxable' => 0, 'is_natura' => 1, 'natura_tax_base' => 0.0],
        ];
        $r = NaturaTaxCalculator::aggregate($earnings);
        $this->assertSame(5_200_000.0, $r['gross_earning']);
        $this->assertSame(5_000_000.0, $r['cash_earning']);
        $this->assertSame(5_000_000.0, $r['gross_taxable']);   // natura tidak nambah
        $this->assertSame(200_000.0, $r['natura_total']);
    }

    public function testAggregateMixedComplexScenario(): void
    {
        $earnings = [
            ['amount' => 8_000_000,  'is_taxable' => 1, 'is_natura' => 0], // BASIC
            ['amount' => 1_500_000,  'is_taxable' => 1, 'is_natura' => 0], // ALLOWANCE cash
            ['amount' => 500_000,    'is_taxable' => 0, 'is_natura' => 0], // REIMBURSEMENT
            ['amount' => 3_000_000,  'is_taxable' => 1, 'is_natura' => 1, 'natura_tax_base' => 2_750_000.0], // mobil dinas
        ];
        $r = NaturaTaxCalculator::aggregate($earnings);
        $this->assertSame(13_000_000.0, $r['gross_earning']);
        $this->assertSame(10_000_000.0, $r['cash_earning']); // 8jt + 1.5jt + 500rb
        $this->assertSame(12_250_000.0, $r['gross_taxable']); // 8jt + 1.5jt + 2.75jt natura
        $this->assertSame(3_000_000.0, $r['natura_total']);
    }

    public function testAggregateEmptyArrayReturnsZeros(): void
    {
        $r = NaturaTaxCalculator::aggregate([]);
        $this->assertSame(0.0, $r['gross_earning']);
        $this->assertSame(0.0, $r['cash_earning']);
        $this->assertSame(0.0, $r['gross_taxable']);
        $this->assertSame(0.0, $r['natura_total']);
    }
}