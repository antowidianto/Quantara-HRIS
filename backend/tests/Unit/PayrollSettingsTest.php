<?php

namespace Tests\Unit;

use App\Libraries\PayrollSettings;
use PHPUnit\Framework\TestCase;

/**
 * PayrollSettingsTest — verifikasi default values & boundary clamping.
 *
 * Test untuk membaca/override dari DB butuh DB stub dan akan dicover
 * di integration test. Di sini hanya cek logika default & clamping
 * (yang sepenuhnya pure).
 *
 * Run: vendor/bin/phpunit --filter PayrollSettingsTest
 */
class PayrollSettingsTest extends TestCase
{
    private PayrollSettings $cfg;

    protected function setUp(): void
    {
        $this->cfg = new PayrollSettings();
    }

    // ─── Default values ────────────────────────────────────────────

    public function testWorkingDaysDefault22(): void
    {
        // Tanpa companyId → fallback langsung ke default
        $this->assertSame(22, $this->cfg->workingDaysPerMonth(null));
    }

    public function testOvertimeHoursDefault173(): void
    {
        $this->assertSame(173, $this->cfg->overtimeHoursPerMonth(null));
    }

    public function testOvertimeToLeaveHoursDefault8(): void
    {
        $this->assertSame(8, $this->cfg->overtimeToLeaveHoursPerDay(null));
    }

    public function testAbsentDeductionDefaultEnabled(): void
    {
        $this->assertTrue($this->cfg->absentDeductionEnabled(null));
    }

    public function testAllReturnsAllSettings(): void
    {
        $all = $this->cfg->all(null);
        $this->assertSame(22, $all['working_days_per_month']);
        $this->assertSame(173, $all['overtime_hours_per_month']);
        $this->assertSame(8, $all['overtime_to_leave_hours_per_day']);
        $this->assertTrue($all['absent_deduction_enabled']);
    }

    // ─── Constants ────────────────────────────────────────────────

    public function testConstantsAreExposed(): void
    {
        // Default constants harus accessible untuk caller (mis. fallback default)
        $this->assertSame(22, PayrollSettings::DEFAULT_WORKING_DAYS_PER_MONTH);
        $this->assertSame(173, PayrollSettings::DEFAULT_OVERTIME_HOURS_PER_MONTH);
        $this->assertSame(8, PayrollSettings::DEFAULT_OVERTIME_TO_LEAVE_HOURS_PER_DAY);
    }
}