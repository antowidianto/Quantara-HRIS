<?php

namespace Tests\Unit;

use App\Libraries\Leave\HalfDayLeaveCalculator;
use PHPUnit\Framework\TestCase;

/**
 * HalfDayLeaveCalculatorTest — Sprint C+1 / C10.
 *
 * Verifikasi tiga API publik:
 *   - effectiveWorkHours: jam kerja yang seharusnya hadir
 *   - deductionDays: hari deduction berdasarkan present hours
 *   - validateHalfDayConflict: cek konflik dengan leave existing di tanggal sama
 */
final class HalfDayLeaveCalculatorTest extends TestCase
{
    private HalfDayLeaveCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new HalfDayLeaveCalculator();
    }

    // ─── effectiveWorkHours ──────────────────────────────────────────

    public function testNoLeaveReturnsFullShiftHours(): void
    {
        $this->assertSame(8.0, $this->calc->effectiveWorkHours(null));
        $this->assertSame(7.5, $this->calc->effectiveWorkHours(null, 7.5));
    }

    public function testFullDayLeaveReturnsZero(): void
    {
        $leave = ['is_half_day' => 0];
        $this->assertSame(0.0, $this->calc->effectiveWorkHours($leave));
    }

    public function testHalfDayMorningReturnsHalfShift(): void
    {
        $leave = ['is_half_day' => 1, 'half_day_part' => 'MORNING'];
        $this->assertSame(4.0, $this->calc->effectiveWorkHours($leave));
        $this->assertSame(3.5, $this->calc->effectiveWorkHours($leave, 7.0));
    }

    public function testHalfDayAfternoonReturnsHalfShift(): void
    {
        $leave = ['is_half_day' => 1, 'half_day_part' => 'AFTERNOON'];
        $this->assertSame(4.0, $this->calc->effectiveWorkHours($leave));
    }

    public function testInvalidHalfDayPartTreatedAsFullLeave(): void
    {
        // Defensive: half_day=1 tapi part missing/invalid → fail-safe = 0
        $leave = ['is_half_day' => 1, 'half_day_part' => 'EVENING'];
        $this->assertSame(0.0, $this->calc->effectiveWorkHours($leave));
    }

    public function testStringFlagsAreCoerced(): void
    {
        // PDO/MySQLi sering balikkan "1" string instead of int
        $leave = ['is_half_day' => '1', 'half_day_part' => 'morning'];
        $this->assertSame(4.0, $this->calc->effectiveWorkHours($leave));
    }

    // ─── deductionDays ───────────────────────────────────────────────

    public function testNoLeaveFullPresentNoDeduction(): void
    {
        $this->assertSame(0.0, $this->calc->deductionDays(null, 8.0));
    }

    public function testNoLeaveAbsentFullDeduction(): void
    {
        $this->assertSame(1.0, $this->calc->deductionDays(null, 0.0));
    }

    public function testNoLeavePartialPresenceProrata(): void
    {
        // Hadir 6 dari 8 jam → deduction 2/8 = 0.25
        $this->assertSame(0.25, $this->calc->deductionDays(null, 6.0));
    }

    public function testFullLeaveAbsentNoDeduction(): void
    {
        // Sudah cover oleh leave balance — tidak boleh double-count
        $leave = ['is_half_day' => 0];
        $this->assertSame(0.0, $this->calc->deductionDays($leave, 0.0));
    }

    public function testHalfDayMorningPresentAfternoonNoDeduction(): void
    {
        // MORNING leave = pulang siang... eh, salah konvensi.
        // Konvensi kita: MORNING=08-12 leave, masuk siang afternoon penuh.
        // Expected work = 4 jam (afternoon). Hadir 4 jam → 0 deduction.
        $leave = ['is_half_day' => 1, 'half_day_part' => 'MORNING'];
        $this->assertSame(0.0, $this->calc->deductionDays($leave, 4.0));
    }

    public function testHalfDayMorningTotalAbsentHalfDeduction(): void
    {
        // MORNING leave, expected 4 jam afternoon. Hadir 0 → missing 4/8 = 0.5
        $leave = ['is_half_day' => 1, 'half_day_part' => 'MORNING'];
        $this->assertSame(0.5, $this->calc->deductionDays($leave, 0.0));
    }

    public function testHalfDayAfternoonPresentMorningNoDeduction(): void
    {
        // AFTERNOON leave = pulang siang setelah morning. Expected 4 jam morning.
        $leave = ['is_half_day' => 1, 'half_day_part' => 'AFTERNOON'];
        $this->assertSame(0.0, $this->calc->deductionDays($leave, 4.0));
    }

    public function testHalfDayAfternoonPartialAbsentProrata(): void
    {
        // AFTERNOON leave, hadir 2 jam morning saja → missing 2/8 = 0.25
        $leave = ['is_half_day' => 1, 'half_day_part' => 'AFTERNOON'];
        $this->assertSame(0.25, $this->calc->deductionDays($leave, 2.0));
    }

    public function testZeroShiftHoursDoesNotDivideByZero(): void
    {
        // Defensive: shiftHours 0 di-clamp ke 0.01 di internal
        $result = $this->calc->deductionDays(null, 0.0, 0.0);
        $this->assertGreaterThanOrEqual(0.0, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    // ─── validateHalfDayConflict ─────────────────────────────────────

    public function testValidateHalfDayValidWhenNoExisting(): void
    {
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 1, 'half_day_part' => 'MORNING'],
            [],
        );
        $this->assertTrue($r['valid']);
    }

    public function testValidateHalfDayInvalidPartReturnsError(): void
    {
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 1, 'half_day_part' => 'EVENING'],
            [],
        );
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('half_day_part', $r['reason']);
    }

    public function testValidateConflictWithExistingFullDay(): void
    {
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 1, 'half_day_part' => 'MORNING'],
            [['is_half_day' => 0]],
        );
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('full-day', $r['reason']);
    }

    public function testValidateConflictWithSamePart(): void
    {
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 1, 'half_day_part' => 'MORNING'],
            [['is_half_day' => 1, 'half_day_part' => 'MORNING']],
        );
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('MORNING', $r['reason']);
    }

    public function testValidateAllowsComplementaryHalfDays(): void
    {
        // MORNING existing + AFTERNOON new — strictly speaking sebenarnya
        // jadi full leave, tapi karena beda leave_type (mis. SICK morning +
        // ANNUAL afternoon) bisa valid. Caller layer atas yang putuskan.
        // Calculator hanya cek konflik mekanis.
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 1, 'half_day_part' => 'AFTERNOON'],
            [['is_half_day' => 1, 'half_day_part' => 'MORNING']],
        );
        $this->assertTrue($r['valid']);
    }

    public function testValidateNewFullConflictWithExistingHalf(): void
    {
        $r = $this->calc->validateHalfDayConflict(
            ['is_half_day' => 0],
            [['is_half_day' => 1, 'half_day_part' => 'MORNING']],
        );
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('full-day', $r['reason']);
    }
}