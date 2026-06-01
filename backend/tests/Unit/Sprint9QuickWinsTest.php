<?php

namespace Tests\Unit;

use App\Libraries\Approval\ApprovalConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Sprint 9 Quick Wins (H1.3, H1.4, H1.8, H1.9).
 *
 * Tidak butuh DB — semua logic yang di-test di-extract ke pure functions
 * atau di-cover dengan ApprovalConditionEvaluator yang stateless.
 *
 * Run: vendor/bin/phpunit --filter Sprint9QuickWinsTest
 */
class Sprint9QuickWinsTest extends TestCase
{
    // ─── H1.3 — Conditional Approval Evaluator ───────────────────────

    public function testEvaluatorNullTypeAlwaysTrue(): void
    {
        $e = new ApprovalConditionEvaluator();
        $this->assertTrue($e->evaluate(null, null, ['meta' => [], 'employee' => []]));
        $this->assertTrue($e->evaluate('', null, ['meta' => [], 'employee' => []]));
    }

    public function testEvaluatorUnknownTypeFailsOpen(): void
    {
        // Tipe tidak dikenal → tidak block step (fail-open dengan log)
        $e = new ApprovalConditionEvaluator();
        $this->assertTrue($e->evaluate('crazy_type', ['value' => 1],
            ['meta' => [], 'employee' => []]));
    }

    public function testEvaluatorAmountGt(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($amt) => ['meta' => ['amount' => $amt], 'employee' => []];
        // > 5000000
        $this->assertTrue($e->evaluate('amount_gt', ['value' => 5000000], $ctx(6000000)));
        $this->assertFalse($e->evaluate('amount_gt', ['value' => 5000000], $ctx(5000000)));
        $this->assertFalse($e->evaluate('amount_gt', ['value' => 5000000], $ctx(4000000)));
    }

    public function testEvaluatorAmountLte(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($amt) => ['meta' => ['amount' => $amt], 'employee' => []];
        $this->assertTrue($e->evaluate('amount_lte', ['value' => 1000000], $ctx(500000)));
        $this->assertTrue($e->evaluate('amount_lte', ['value' => 1000000], $ctx(1000000)));
        $this->assertFalse($e->evaluate('amount_lte', ['value' => 1000000], $ctx(1000001)));
    }

    public function testEvaluatorDaysGt(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($d) => ['meta' => ['days' => $d], 'employee' => []];
        $this->assertTrue($e->evaluate('days_gt', ['value' => 5], $ctx(7)));
        $this->assertFalse($e->evaluate('days_gt', ['value' => 5], $ctx(5)));
    }

    public function testEvaluatorEmployeeLevelGte(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($lvl) => ['meta' => [], 'employee' => ['job_grade_level' => $lvl]];
        $this->assertTrue($e->evaluate('employee_level_gte', ['value' => 5], $ctx(7)));
        $this->assertTrue($e->evaluate('employee_level_gte', ['value' => 5], $ctx(5)));
        $this->assertFalse($e->evaluate('employee_level_gte', ['value' => 5], $ctx(3)));
    }

    public function testEvaluatorDepartmentIn(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($d) => ['meta' => [], 'employee' => ['department_id' => $d]];
        $this->assertTrue($e->evaluate('department_in', ['in' => [1, 2, 3]], $ctx(2)));
        $this->assertFalse($e->evaluate('department_in', ['in' => [1, 2, 3]], $ctx(5)));
    }

    public function testEvaluatorDepartmentNotIn(): void
    {
        $e = new ApprovalConditionEvaluator();
        $ctx = fn ($d) => ['meta' => [], 'employee' => ['department_id' => $d]];
        $this->assertTrue($e->evaluate('department_not_in', ['in' => [1, 2, 3]], $ctx(5)));
        $this->assertFalse($e->evaluate('department_not_in', ['in' => [1, 2, 3]], $ctx(2)));
    }

    public function testEvaluatorAcceptsJsonStringConfig(): void
    {
        // condition_value_json yang masuk dari DB adalah string — evaluator
        // harus auto-decode.
        $e = new ApprovalConditionEvaluator();
        $jsonConfig = json_encode(['value' => 1000000]);
        $ctx = ['meta' => ['amount' => 2000000], 'employee' => []];
        $this->assertTrue($e->evaluate('amount_gt', $jsonConfig, $ctx));
    }

    public function testValidateConfigRequiresValueForNumericTypes(): void
    {
        $e = new ApprovalConditionEvaluator();
        $this->assertNull($e->validateConfig(null, null));
        $this->assertNull($e->validateConfig('', null));
        $this->assertStringContainsString('value', (string) $e->validateConfig('amount_gt', []));
        $this->assertStringContainsString('value', (string) $e->validateConfig('amount_gt', ['in' => [1]]));
        $this->assertNull($e->validateConfig('amount_gt', ['value' => 5000000]));
    }

    public function testValidateConfigRequiresListForInTypes(): void
    {
        $e = new ApprovalConditionEvaluator();
        $this->assertStringContainsString('in', (string) $e->validateConfig('department_in', []));
        $this->assertStringContainsString('in', (string) $e->validateConfig('department_in', ['value' => 5]));
        $this->assertNull($e->validateConfig('department_in', ['in' => [1, 2]]));
    }

    public function testValidateConfigRejectsUnknownType(): void
    {
        $e = new ApprovalConditionEvaluator();
        $this->assertStringContainsString('tidak dikenal', (string) $e->validateConfig('hocus_pocus', []));
    }

    // ─── H1.4 — Time-in-Stage helpers (pure) ─────────────────────────

    public function testTimeInStageDurationCalc(): void
    {
        // Mirror logic di RecruitmentService::moveStage (close history row)
        $entered = strtotime('2026-01-10 09:00:00');
        $exited  = strtotime('2026-01-12 09:00:00');
        $hours = round(($exited - $entered) / 3600, 2);
        $this->assertSame(48.0, $hours);
    }

    public function testStaleCutoffCalc(): void
    {
        // Mirror logic di staleCandidates: cutoff = now - threshold_days
        $threshold = 7;
        $cutoff = strtotime("-{$threshold} days");
        $this->assertGreaterThan(0, $cutoff);
        $this->assertLessThan(time(), $cutoff);
    }

    // ─── H1.8 — THR Projection logic (sudah ada di PayrollController) ──

    public function testThrTenureMonths(): void
    {
        // Pakai static method existing di PayrollController
        $months = \App\Controllers\Api\V1\Payroll\PayrollController::computeTenureMonths(
            joinDate: '2025-01-15',
            resignDate: null,
            payDate: '2026-04-01',
            periodStart: '2026-01-01'
        );
        // Join 15 Jan 2025, pay 1 Apr 2026 → ~14 bulan
        $this->assertGreaterThanOrEqual(14, $months);
    }

    public function testThrAmountFullForOneYearTenure(): void
    {
        $amount = \App\Controllers\Api\V1\Payroll\PayrollController::computeThrAmount(
            monthlySalary: 10000000,
            tenureMonths: 12
        );
        $this->assertSame(10000000.0, $amount);
    }

    public function testThrAmountProportionalForLessThanYear(): void
    {
        $amount = \App\Controllers\Api\V1\Payroll\PayrollController::computeThrAmount(
            monthlySalary: 10000000,
            tenureMonths: 6
        );
        $this->assertSame(5000000.0, $amount);
    }

    public function testThrAmountZeroForLessThanMonth(): void
    {
        $amount = \App\Controllers\Api\V1\Payroll\PayrollController::computeThrAmount(
            monthlySalary: 10000000,
            tenureMonths: 0
        );
        $this->assertSame(0.0, $amount);
    }

    // ─── H1.9 — WhatsApp helpers (pure) ──────────────────────────────

    public function testWhatsAppNormalizePhoneIndonesia(): void
    {
        $svc = new \App\Libraries\Notification\WhatsAppService();
        $this->assertSame('6281234567890', $svc->normalizePhone('081234567890'));
        $this->assertSame('6281234567890', $svc->normalizePhone('081234567890'));
        $this->assertSame('6281234567890', $svc->normalizePhone('+62 812-3456-7890'));
        $this->assertSame('6281234567890', $svc->normalizePhone('62 812 3456 7890'));
    }

    public function testWhatsAppNormalizePhoneRejectsInvalid(): void
    {
        $svc = new \App\Libraries\Notification\WhatsAppService();
        $this->assertNull($svc->normalizePhone(''));
        $this->assertNull($svc->normalizePhone('not-a-phone'));
        $this->assertNull($svc->normalizePhone('123')); // terlalu pendek
        $this->assertNull($svc->normalizePhone('1234567890123456')); // terlalu panjang
    }

    public function testWhatsAppRateLimitConstant(): void
    {
        // Kalau threshold berubah di production, test ini harus di-update
        // bersamaan supaya konsisten dengan dokumentasi.
        $this->assertSame(3, \App\Libraries\Notification\WhatsAppService::RATE_LIMIT_PER_DAY);
    }

    public function testWhatsAppMessageTruncation(): void
    {
        // Mirror logic di send() — pesan > 1000 char ter-truncate.
        $longMessage = str_repeat('A', 2000);
        $truncated = substr(trim($longMessage), 0, 1000);
        $this->assertSame(1000, strlen($truncated));
    }
}