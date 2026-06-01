<?php

namespace Tests\Unit;

use App\Libraries\Payroll\Pph21TerCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pph21TerCategoryTest — verifikasi kategori TER dari status PTKP
 * sesuai PMK 168/2023.
 *
 * Mapping:
 *  - Kategori A: TK/0, TK/1, K/0
 *  - Kategori B: TK/2, TK/3, K/1, K/2
 *  - Kategori C: K/3
 *  - HB (suami-istri digabung) → fall back ke A (konservatif)
 *
 * Method `calculateMonthlyTer()` & `calculateYearlyProgressive()` butuh
 * DB (tabel `ter_rates`, `ptkp_rates`, `pph21_brackets`) → akan ditest
 * via CodeIgniter integration test di tahap berikutnya. Lihat ROADMAP.
 *
 * Run: vendor/bin/phpunit --filter Pph21TerCategoryTest
 */
class Pph21TerCategoryTest extends TestCase
{
    private Pph21TerCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new Pph21TerCalculator();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ptkpToCategoryProvider(): array
    {
        return [
            // Kategori A
            'TK0 → A' => ['TK0', 'A'],
            'TK1 → A' => ['TK1', 'A'],
            'K0  → A' => ['K0',  'A'],

            // Kategori B
            'TK2 → B' => ['TK2', 'B'],
            'TK3 → B' => ['TK3', 'B'],
            'K1  → B' => ['K1',  'B'],
            'K2  → B' => ['K2',  'B'],

            // Kategori C
            'K3  → C' => ['K3', 'C'],

            // HB (Hidup Berpisah) — fallback ke A
            'HB0 → A (fallback)' => ['HB0', 'A'],
            'HB1 → A (fallback)' => ['HB1', 'A'],
            'HB2 → A (fallback)' => ['HB2', 'A'],
            'HB3 → A (fallback)' => ['HB3', 'A'],

            // Lowercase input → normalize ke uppercase
            'tk0 (lowercase) → A' => ['tk0', 'A'],
            'k3  (lowercase) → C' => ['k3',  'C'],

            // Unknown → fallback ke A
            'unknown → A' => ['XYZ', 'A'],
            'empty → A'   => ['', 'A'],
        ];
    }

    #[DataProvider('ptkpToCategoryProvider')]
    public function testCategoryFromPtkp(string $ptkp, string $expectedCategory): void
    {
        $this->assertSame(
            $expectedCategory,
            $this->calc->categoryFromPtkp($ptkp),
            "PTKP '{$ptkp}' harus map ke kategori TER '{$expectedCategory}'"
        );
    }
}