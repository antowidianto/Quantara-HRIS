<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Sprint 7 / H1.1 (Bulk Approval) dan H1.2 (Carry-Over Cap).
 *
 * Mirror logic pure dari ApprovalChainController::bulkDecide dan
 * NotifyCarryOverCap::run, supaya bisa di-test tanpa DB. Kalau logic asli
 * berubah, tetap update fungsi pure di test ini sebagai canonical reference.
 *
 * Run: vendor/bin/phpunit --filter Sprint7BulkApprovalAndCarryOverTest
 */
class Sprint7BulkApprovalAndCarryOverTest extends TestCase
{
    // ─── H1.1 — Bulk Approval input validation ────────────────────────

    public function testBulkValidateRejectsEmptyArray(): void
    {
        $this->assertSame('INVALID_INPUT', $this->validateBulkInput([], 'APPROVED', ''));
    }

    public function testBulkValidateRejectsNonArray(): void
    {
        $this->assertSame('INVALID_INPUT', $this->validateBulkInput('not-array', 'APPROVED', ''));
        $this->assertSame('INVALID_INPUT', $this->validateBulkInput(null, 'APPROVED', ''));
    }

    public function testBulkValidateRejectsTooManyItems(): void
    {
        $ids = range(1, 51);
        $this->assertSame('TOO_MANY_ITEMS', $this->validateBulkInput($ids, 'APPROVED', ''));
    }

    public function testBulkValidateAccepts50ItemsExactly(): void
    {
        $ids = range(1, 50);
        $this->assertNull($this->validateBulkInput($ids, 'APPROVED', ''));
    }

    public function testBulkValidateRejectsRejectWithoutReason(): void
    {
        $this->assertSame('REASON_REQUIRED', $this->validateBulkInput([1, 2], 'REJECTED', ''));
        $this->assertSame('REASON_REQUIRED', $this->validateBulkInput([1, 2], 'REJECTED', '   '));
    }

    public function testBulkValidateAcceptsRejectWithReason(): void
    {
        $this->assertNull($this->validateBulkInput([1, 2], 'REJECTED', 'Tidak sesuai prosedur'));
    }

    public function testBulkValidateApproveAllowsEmptyNote(): void
    {
        // Approve tidak butuh note.
        $this->assertNull($this->validateBulkInput([1, 2], 'APPROVED', ''));
    }

    // ─── H1.1 — Normalisasi & dedup ID ────────────────────────────────

    public function testNormalizeIdsDeduplicates(): void
    {
        $this->assertSame([1, 2, 3], $this->normalizeIds([1, 2, 2, 3, 1]));
    }

    public function testNormalizeIdsCastsStringToInt(): void
    {
        $this->assertSame([5, 7, 9], $this->normalizeIds(['5', '7', '9']));
    }

    public function testNormalizeIdsFiltersOutZeroAndNegative(): void
    {
        // 0 dan negatif tidak boleh lolos — proteksi dari payload jahil.
        $this->assertSame([1, 2], $this->normalizeIds([0, 1, -3, 2, '0']));
    }

    public function testNormalizeIdsHandlesMixedTypes(): void
    {
        // Mixed int + string + duplicate
        $this->assertSame([10, 20, 30], $this->normalizeIds([10, '20', 10, 30, '20']));
    }

    // ─── H1.1 — Summary aggregation ──────────────────────────────────

    public function testSummaryAllSuccess(): void
    {
        $summary = $this->summarize(success: 5, failed: 0, requested: 5);
        $this->assertSame(5, $summary['success']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(5, $summary['requested']);
    }

    public function testSummaryPartialFailure(): void
    {
        $summary = $this->summarize(success: 3, failed: 2, requested: 5);
        $this->assertSame(3, $summary['success']);
        $this->assertSame(2, $summary['failed']);
    }

    public function testSummaryAllFailed(): void
    {
        $summary = $this->summarize(success: 0, failed: 5, requested: 5);
        $this->assertSame(0, $summary['success']);
        $this->assertSame(5, $summary['failed']);
    }

    // ─── H1.2 — Carry-Over Cap calculation ───────────────────────────

    public function testAtRiskZeroWhenCarryOverNotAllowed(): void
    {
        $this->assertSame(0.0, $this->atRiskDays(remaining: 20, capDays: 12, allowed: false));
    }

    public function testAtRiskZeroWhenCapIsNull(): void
    {
        // Cap NULL = unlimited → tidak ada hari hangus
        $this->assertSame(0.0, $this->atRiskDays(remaining: 50, capDays: null, allowed: true));
    }

    public function testAtRiskZeroWhenRemainingBelowCap(): void
    {
        $this->assertSame(0.0, $this->atRiskDays(remaining: 5, capDays: 12, allowed: true));
    }

    public function testAtRiskZeroWhenRemainingEqualCap(): void
    {
        $this->assertSame(0.0, $this->atRiskDays(remaining: 12, capDays: 12, allowed: true));
    }

    public function testAtRiskCalculatedWhenRemainingExceedsCap(): void
    {
        // Sisa 20, cap 12 → 8 hari akan hangus
        $this->assertSame(8.0, $this->atRiskDays(remaining: 20, capDays: 12, allowed: true));
    }

    public function testAtRiskRoundsToOneDecimal(): void
    {
        // Sisa 15.75, cap 12 → 3.75 → bulatkan ke 3.8
        $this->assertSame(3.8, $this->atRiskDays(remaining: 15.75, capDays: 12, allowed: true));
    }

    public function testAtRiskFractionRoundedDown(): void
    {
        // Sisa 12.34, cap 12 → 0.34 → bulatkan ke 0.3
        $this->assertSame(0.3, $this->atRiskDays(remaining: 12.34, capDays: 12, allowed: true));
    }

    public function testAtRiskHandlesZeroCap(): void
    {
        // Cap = 0 berarti TIDAK ada carry-over allowed walaupun flag true
        // (semua sisa akan hangus)
        $this->assertSame(15.0, $this->atRiskDays(remaining: 15, capDays: 0, allowed: true));
    }

    // ─── H1.2 — Severity threshold untuk audit log ───────────────────

    public function testCarryOverSeverityNoticeForSmallAtRisk(): void
    {
        // ≤ 5 hari → NOTICE saja, tidak urgent
        $this->assertSame('NOTICE', $this->carryOverSeverity(0.5));
        $this->assertSame('NOTICE', $this->carryOverSeverity(3.0));
        $this->assertSame('NOTICE', $this->carryOverSeverity(5.0));
    }

    public function testCarryOverSeverityWarningForLargeAtRisk(): void
    {
        // > 5 hari → WARNING
        $this->assertSame('WARNING', $this->carryOverSeverity(5.1));
        $this->assertSame('WARNING', $this->carryOverSeverity(10.0));
        $this->assertSame('WARNING', $this->carryOverSeverity(20.0));
    }

    // ─── Pure helpers (mirror logic dari controller / command) ───────

    /**
     * Mirror ApprovalChainController::bulkDecide validation.
     * Return null kalau valid, atau code error string kalau invalid.
     */
    private function validateBulkInput(mixed $ids, string $decision, string $note): ?string
    {
        if (! is_array($ids) || empty($ids)) return 'INVALID_INPUT';
        if (count($ids) > 50) return 'TOO_MANY_ITEMS';
        if ($decision === 'REJECTED' && trim($note) === '') return 'REASON_REQUIRED';
        return null;
    }

    /**
     * Mirror normalisasi ID di bulkDecide:
     *   - cast int
     *   - dedup
     *   - filter > 0
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_values(array_filter($ids, fn ($v) => $v > 0));
    }

    private function summarize(int $success, int $failed, int $requested): array
    {
        return [
            'requested' => $requested,
            'success'   => $success,
            'failed'    => $failed,
        ];
    }

    /**
     * Mirror logic dari NotifyCarryOverCap untuk hitung at-risk days.
     */
    private function atRiskDays(float $remaining, ?float $capDays, bool $allowed): float
    {
        if (! $allowed) return 0.0;
        if ($capDays === null) return 0.0;
        $diff = $remaining - $capDays;
        if ($diff <= 0) return 0.0;
        return round($diff, 1);
    }

    /**
     * Threshold yang dipakai NotifyCarryOverCap::notifyEmployee untuk
     * pilih severity audit log: > 5 hari = WARNING, sisanya NOTICE.
     */
    private function carryOverSeverity(float $atRiskDays): string
    {
        return $atRiskDays > 5 ? 'WARNING' : 'NOTICE';
    }
}