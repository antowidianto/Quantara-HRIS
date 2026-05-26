<?php

namespace Tests\Unit;

use App\Libraries\CostCenterService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk CostCenterService — fokus ke pure helpers
 * (resolveFromChainPure, validateCostCenterCodePure, isDescendantPure).
 *
 * Method DB-aware (resolveForEmployee, tree, actualsBreakdown, dll)
 * butuh CodeIgniter Database connection — di-test lewat integration
 * test atau manual QA.
 *
 * Run: vendor/bin/phpunit --filter CostCenterServiceTest
 */
class CostCenterServiceTest extends TestCase
{
    // ─── resolveFromChainPure() ────────────────────────────────────

    /**
     * Override eksplisit harus selalu menang dari chain mana pun.
     * Mis. HR specifically tag transaksi ke cost center kustom yang
     * berbeda dari default karyawan.
     */
    public function testOverrideWinsAboveAll(): void
    {
        $result = CostCenterService::resolveFromChainPure(
            ['cost_center_id' => 99],
            ['cost_center_id' => 11], // employee
            ['cost_center_id' => 12], // department
            ['cost_center_id' => 13], // branch
            14,                       // company root
        );
        $this->assertSame(99, $result);
    }

    /**
     * Tanpa override, employee.cost_center_id menang.
     * Ini case paling umum — karyawan punya cost center yang sudah
     * ter-set di profil-nya.
     */
    public function testEmployeeWinsAboveDeptBranchRoot(): void
    {
        $result = CostCenterService::resolveFromChainPure(
            [],
            ['cost_center_id' => 11],
            ['cost_center_id' => 12],
            ['cost_center_id' => 13],
            14,
        );
        $this->assertSame(11, $result);
    }

    /**
     * Karyawan baru atau yang belum di-tag, fallback ke department.
     * Ini scenario mass-onboarding tanpa per-employee setting.
     */
    public function testDepartmentWinsWhenEmployeeMissing(): void
    {
        $result = CostCenterService::resolveFromChainPure(
            [],
            [], // employee tidak punya
            ['cost_center_id' => 12],
            ['cost_center_id' => 13],
            14,
        );
        $this->assertSame(12, $result);
    }

    /**
     * Department tidak punya default → fallback ke branch.
     * Useful untuk perusahaan yang tag cost center per cabang
     * (misal kantor cabang punya P&L sendiri, departemen-nya share).
     */
    public function testBranchWinsWhenDeptMissing(): void
    {
        $result = CostCenterService::resolveFromChainPure(
            [],
            [],
            [],
            ['cost_center_id' => 13],
            14,
        );
        $this->assertSame(13, $result);
    }

    /**
     * Last resort: company root cost center "DEFAULT".
     * Mencegah transaksi tanpa cost_center_id sama sekali yang bisa
     * mengganggu reporting accounting.
     */
    public function testCompanyRootAsLastResort(): void
    {
        $result = CostCenterService::resolveFromChainPure([], [], [], [], 14);
        $this->assertSame(14, $result);
    }

    public function testReturnsNullIfNothingResolvable(): void
    {
        $result = CostCenterService::resolveFromChainPure([], [], [], [], null);
        $this->assertNull($result);
    }

    public function testZeroValuesTreatedAsNotSet(): void
    {
        // Defensive — saat caller pakai (int) cast, null kadang jadi 0
        $result = CostCenterService::resolveFromChainPure(
            ['cost_center_id' => 0],   // not-set
            ['cost_center_id' => 0],
            ['cost_center_id' => 0],
            ['cost_center_id' => 0],
            14,
        );
        $this->assertSame(14, $result);
    }

    public function testEmptyStringTreatedAsNotSet(): void
    {
        $result = CostCenterService::resolveFromChainPure(
            ['cost_center_id' => ''],
            ['cost_center_id' => 11],
            [], [], null,
        );
        $this->assertSame(11, $result);
    }

    public function testCoercesStringNumberToInt(): void
    {
        // JSON dari frontend sering kirim number sebagai string
        $result = CostCenterService::resolveFromChainPure(
            ['cost_center_id' => '99'],
            [], [], [], null,
        );
        $this->assertSame(99, $result);
        $this->assertIsInt($result);
    }

    // ─── validateCostCenterCodePure() ───────────────────────────────

    public function testValidCodeAccepted(): void
    {
        $this->assertSame([], CostCenterService::validateCostCenterCodePure('CC-IT-DEV'));
        $this->assertSame([], CostCenterService::validateCostCenterCodePure('OPS_JKT'));
        $this->assertSame([], CostCenterService::validateCostCenterCodePure('AB'));
    }

    public function testCodeMinLength(): void
    {
        $errors = CostCenterService::validateCostCenterCodePure('A');
        $this->assertContains('code minimal 2 karakter', $errors);
    }

    public function testCodeMaxLength(): void
    {
        $errors = CostCenterService::validateCostCenterCodePure(str_repeat('A', 33));
        $this->assertContains('code maksimal 32 karakter', $errors);
    }

    public function testCodeRejectsSpaces(): void
    {
        $errors = CostCenterService::validateCostCenterCodePure('CC IT');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('huruf, angka, hyphen, underscore', $errors[0]);
    }

    public function testCodeRejectsSpecialChars(): void
    {
        $errors = CostCenterService::validateCostCenterCodePure('CC@IT');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('huruf, angka, hyphen, underscore', $errors[0]);
    }

    public function testCodeMustHaveLetter(): void
    {
        // Numeric-only di-reject — kode "1234" terlalu generik & susah di-search
        $errors = CostCenterService::validateCostCenterCodePure('1234');
        $this->assertContains('code harus mengandung minimal 1 huruf', $errors);
    }

    public function testCodeAcceptsUnderscoreAndHyphen(): void
    {
        $this->assertSame([], CostCenterService::validateCostCenterCodePure('CC_IT-DEV_01'));
    }

    public function testCodeTrimsWhitespace(): void
    {
        // Caller mungkin tidak trim — service yang handle
        $errors = CostCenterService::validateCostCenterCodePure('  CC-IT  ');
        $this->assertSame([], $errors);
    }

    public function testCodeRejectsTrimmedToEmpty(): void
    {
        $errors = CostCenterService::validateCostCenterCodePure('   ');
        $this->assertNotEmpty($errors);
    }

    // ─── isDescendantPure() ────────────────────────────────────────

    /**
     * Build hirarki:
     *   1 (root)
     *   ├── 2 (DIRECTORATE)
     *   │   └── 4 (DEPT-IT)
     *   │       └── 5 (TEAM-DEV)
     *   └── 3 (DIRECTORATE-OPS)
     *
     * Dipakai untuk validasi cycle prevention saat reparent.
     */
    private function sampleHierarchy(): array
    {
        return [
            ['id' => 1, 'parent_id' => null],
            ['id' => 2, 'parent_id' => 1],
            ['id' => 3, 'parent_id' => 1],
            ['id' => 4, 'parent_id' => 2],
            ['id' => 5, 'parent_id' => 4],
        ];
    }

    public function testNodeIsDescendantOfItself(): void
    {
        // Edge case — self-reference akan di-block separately di controller,
        // tapi function ini handle as descendant (degenerate case).
        $this->assertTrue(CostCenterService::isDescendantPure(5, 5, $this->sampleHierarchy()));
    }

    public function testDirectChildIsDescendant(): void
    {
        $this->assertTrue(CostCenterService::isDescendantPure(2, 1, $this->sampleHierarchy()));
        $this->assertTrue(CostCenterService::isDescendantPure(4, 2, $this->sampleHierarchy()));
    }

    public function testDeepDescendantDetected(): void
    {
        // 5 → 4 → 2 → 1: 5 adalah descendant dari 1
        $this->assertTrue(CostCenterService::isDescendantPure(5, 1, $this->sampleHierarchy()));
    }

    public function testSiblingNotDescendant(): void
    {
        // 2 dan 3 adalah sibling (sama-sama child of 1)
        $this->assertFalse(CostCenterService::isDescendantPure(2, 3, $this->sampleHierarchy()));
        $this->assertFalse(CostCenterService::isDescendantPure(3, 2, $this->sampleHierarchy()));
    }

    public function testAncestorNotDescendantOfChild(): void
    {
        // 1 (root) bukan descendant dari 2
        $this->assertFalse(CostCenterService::isDescendantPure(1, 2, $this->sampleHierarchy()));
    }

    public function testCousinNotDescendant(): void
    {
        // 4 (di bawah 2) bukan descendant dari 3 (sibling 2)
        $this->assertFalse(CostCenterService::isDescendantPure(4, 3, $this->sampleHierarchy()));
    }

    public function testCycleProtectedAgainstInfiniteLoop(): void
    {
        // Bad data — 1 → 2 → 1 cycle. Function harus tidak hang.
        $bad = [
            ['id' => 1, 'parent_id' => 2],
            ['id' => 2, 'parent_id' => 1],
        ];
        $start = microtime(true);
        $this->assertFalse(CostCenterService::isDescendantPure(1, 99, $bad));
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(1.0, $elapsed, 'isDescendantPure should not hang on cycle');
    }

    // ─── BUDGET_CATEGORIES & PERIOD_TYPES sanity ────────────────────

    public function testBudgetCategoriesContainsExpectedSet(): void
    {
        $expected = ['SALARY', 'ALLOWANCE', 'OVERTIME', 'BONUS', 'BENEFIT',
            'TRAINING', 'TRAVEL', 'SEVERANCE', 'REIMBURSE', 'ASSET', 'OTHER'];
        foreach ($expected as $cat) {
            $this->assertContains($cat, CostCenterService::BUDGET_CATEGORIES,
                "BUDGET_CATEGORIES harus include {$cat}");
        }
    }

    public function testPeriodTypesContainsExpectedSet(): void
    {
        $expected = ['ANNUAL', 'QUARTERLY', 'MONTHLY'];
        foreach ($expected as $pt) {
            $this->assertContains($pt, CostCenterService::PERIOD_TYPES,
                "PERIOD_TYPES harus include {$pt}");
        }
    }
}