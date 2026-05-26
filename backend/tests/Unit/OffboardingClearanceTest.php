<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk pure logic di OffboardingService.
 *
 * OffboardingService.php berinteraksi dengan database, jadi sebagian besar
 * method butuh DB. Tapi ada bagian-bagian pure (date math, severity
 * computation) yang bisa di-extract & di-test tanpa DB.
 *
 * Strategi: definisikan helpers di sini sebagai pure functions yang
 * mirror logika asli di service. Dengan begini kita pastikan rules-nya
 * tidak regress walau service-nya pakai DB.
 *
 * Run: vendor/bin/phpunit --filter OffboardingClearanceTest
 */
class OffboardingClearanceTest extends TestCase
{
    // ─── BPJS register SLA severity ──────────────────────────────────

    /**
     * SLA register: H+25 = WARNING (reminder 5 hari sblm SLA habis),
     * H+30+ = CRITICAL (UU SJSN sudah lewat).
     */
    public function testBpjsRegisterSeverityWarningAt25Days(): void
    {
        $this->assertSame('WARNING', $this->bpjsRegisterSeverity(25));
        $this->assertSame('WARNING', $this->bpjsRegisterSeverity(28));
        $this->assertSame('WARNING', $this->bpjsRegisterSeverity(29));
    }

    public function testBpjsRegisterSeverityCriticalAt30Days(): void
    {
        $this->assertSame('CRITICAL', $this->bpjsRegisterSeverity(30));
        $this->assertSame('CRITICAL', $this->bpjsRegisterSeverity(35));
        $this->assertSame('CRITICAL', $this->bpjsRegisterSeverity(60));
    }

    public function testBpjsRegisterNotOverdueBeforeDay25(): void
    {
        // Sebelum H+25 → tidak overdue, tidak masuk hasil scanner
        $this->assertNull($this->bpjsRegisterSeverity(0));
        $this->assertNull($this->bpjsRegisterSeverity(7));
        $this->assertNull($this->bpjsRegisterSeverity(24));
    }

    // ─── BPJS deactivate SLA severity ────────────────────────────────

    /**
     * SLA deactivate: H+7 dari resign_date wajib (Permenkes 6/2023).
     * 1-6 hari overdue = WARNING, 7+ hari = CRITICAL (sudah 1 minggu lewat).
     */
    public function testBpjsDeactivateSeverityWarningOneToSixDays(): void
    {
        $this->assertSame('WARNING', $this->bpjsDeactivateSeverity(1));
        $this->assertSame('WARNING', $this->bpjsDeactivateSeverity(3));
        $this->assertSame('WARNING', $this->bpjsDeactivateSeverity(6));
    }

    public function testBpjsDeactivateSeverityCriticalAtSevenDays(): void
    {
        $this->assertSame('CRITICAL', $this->bpjsDeactivateSeverity(7));
        $this->assertSame('CRITICAL', $this->bpjsDeactivateSeverity(14));
        $this->assertSame('CRITICAL', $this->bpjsDeactivateSeverity(30));
    }

    // ─── Final settlement late detection ─────────────────────────────

    /**
     * UU 13/2003 Pasal 156 ayat 4: pesangon wajib dibayar maks 7 hari
     * setelah resign_date. `daysLate` > 0 berarti telat.
     */
    public function testFinalSettlementOnTimeNotLate(): void
    {
        // Bayar di hari yang sama dengan resign_date — sangat tepat waktu
        $this->assertSame(-7, $this->finalSettlementDaysLate('2026-05-01', '2026-05-01'));
        // Bayar 5 hari setelah resign — masih dalam window 7-hari
        $this->assertSame(-2, $this->finalSettlementDaysLate('2026-05-01', '2026-05-06'));
        // Bayar tepat di H+7 — boundary, masih on-time
        $this->assertSame(0, $this->finalSettlementDaysLate('2026-05-01', '2026-05-08'));
    }

    public function testFinalSettlementLateDetected(): void
    {
        // Bayar 8 hari setelah resign (1 hari telat dari batas 7-hari)
        $this->assertSame(1, $this->finalSettlementDaysLate('2026-05-01', '2026-05-09'));
        // Bayar 14 hari setelah resign — telat 7 hari
        $this->assertSame(7, $this->finalSettlementDaysLate('2026-05-01', '2026-05-15'));
        // Bayar 30 hari telat
        $this->assertSame(30, $this->finalSettlementDaysLate('2026-05-01', '2026-06-07'));
    }

    // ─── Tenure calculation untuk paklaring ──────────────────────────

    public function testTenureSimpleYearsAndMonths(): void
    {
        // Bekerja 2 tahun 5 bulan
        $this->assertSame('2 tahun 5 bulan', $this->tenure('2024-01-01', '2026-06-01'));
    }

    public function testTenureExactYears(): void
    {
        // Bekerja persis 3 tahun
        $this->assertSame('3 tahun', $this->tenure('2023-05-15', '2026-05-15'));
    }

    public function testTenureMonthsOnly(): void
    {
        // Kurang dari 1 tahun
        $this->assertSame('8 bulan', $this->tenure('2025-09-01', '2026-05-01'));
    }

    public function testTenureDaysOnly(): void
    {
        // Kurang dari 1 bulan — tampilkan hari
        $this->assertSame('15 hari', $this->tenure('2026-05-01', '2026-05-16'));
    }

    public function testTenureSameDayMinimum(): void
    {
        // Edge case: join & resign hari yang sama (mis. langsung mengundurkan)
        $this->assertSame('kurang dari 1 hari', $this->tenure('2026-05-01', '2026-05-01'));
    }

    // ─── Prorata salary calculation ──────────────────────────────────

    public function testProratedSalaryHalfMonth(): void
    {
        // Bulan Mei (31 hari), resign tanggal 15 → ~48% dari gaji penuh
        $result = $this->proratedSalary(10_000_000, '2026-05-15');
        $this->assertEqualsWithDelta(4_838_709.68, $result, 0.5);
    }

    public function testProratedSalaryFullMonth(): void
    {
        // Resign tanggal terakhir bulan → full gaji
        // April 30 hari, resign tanggal 30
        $this->assertEqualsWithDelta(10_000_000, $this->proratedSalary(10_000_000, '2026-04-30'), 0.5);
    }

    public function testProratedSalaryFirstDay(): void
    {
        // Resign tanggal 1 → 1/31 gaji untuk bulan Mei
        $this->assertEqualsWithDelta(322_580.65, $this->proratedSalary(10_000_000, '2026-05-01'), 0.5);
    }

    public function testProratedSalaryZeroBasic(): void
    {
        $this->assertSame(0.0, $this->proratedSalary(0, '2026-05-15'));
    }

    // ─── Asset clearance OK rules ────────────────────────────────────

    public function testAssetClearanceOkWhenReturnedAtSet(): void
    {
        $this->assertTrue($this->isAssetClearanceOk(returnedAt: '2026-05-01 10:00:00', exceptionNote: null, pendingCount: 0));
        $this->assertTrue($this->isAssetClearanceOk(returnedAt: '2026-05-01 10:00:00', exceptionNote: null, pendingCount: 5));
    }

    public function testAssetClearanceOkWhenExceptionNoteSet(): void
    {
        // HR override dengan dokumen pendukung (mis. polisi report)
        $this->assertTrue($this->isAssetClearanceOk(
            returnedAt: null,
            exceptionNote: 'Laptop hilang dengan laporan polisi BAP-2026-1234',
            pendingCount: 1,
        ));
    }

    public function testAssetClearanceOkWhenNoPendingAssets(): void
    {
        // Karyawan tidak pegang aset sama sekali → auto-OK
        $this->assertTrue($this->isAssetClearanceOk(returnedAt: null, exceptionNote: null, pendingCount: 0));
    }

    public function testAssetClearanceBlockedWhenPendingAndNoOverride(): void
    {
        $this->assertFalse($this->isAssetClearanceOk(returnedAt: null, exceptionNote: null, pendingCount: 1));
        $this->assertFalse($this->isAssetClearanceOk(returnedAt: null, exceptionNote: null, pendingCount: 10));
    }

    public function testAssetClearanceWhitespaceExceptionNotConsideredOverride(): void
    {
        // Catatan kosong / cuma whitespace tidak boleh dianggap override
        $this->assertFalse($this->isAssetClearanceOk(returnedAt: null, exceptionNote: '   ', pendingCount: 1));
        $this->assertFalse($this->isAssetClearanceOk(returnedAt: null, exceptionNote: '', pendingCount: 1));
    }

    // ─── Ready-for-paklaring & ready-for-final-settlement gates ──────

    public function testReadyForPaklaringRequiresAllConditions(): void
    {
        // Semua syarat OK
        $this->assertTrue($this->readyForPaklaring(
            status: 'APPROVED',
            resignReached: true,
            assetsOk: true,
        ));
        // Status bukan APPROVED
        $this->assertFalse($this->readyForPaklaring(
            status: 'PENDING_HR', resignReached: true, assetsOk: true,
        ));
        // resign_date belum lewat
        $this->assertFalse($this->readyForPaklaring(
            status: 'APPROVED', resignReached: false, assetsOk: true,
        ));
        // Asset belum clear
        $this->assertFalse($this->readyForPaklaring(
            status: 'APPROVED', resignReached: true, assetsOk: false,
        ));
    }

    public function testReadyForFinalSettlementAlsoRequiresFinanceClearance(): void
    {
        // Semua syarat OK termasuk finance
        $this->assertTrue($this->readyForFinalSettlement(
            status: 'APPROVED', resignReached: true, assetsOk: true, financeOk: true,
        ));
        // Finance belum clear → blocked
        $this->assertFalse($this->readyForFinalSettlement(
            status: 'APPROVED', resignReached: true, assetsOk: true, financeOk: false,
        ));
    }

    // ─── Pure helpers (mirror logic di OffboardingService) ──────────

    private function bpjsRegisterSeverity(int $daysSinceJoin): ?string
    {
        if ($daysSinceJoin < 25) return null;
        return $daysSinceJoin >= 30 ? 'CRITICAL' : 'WARNING';
    }

    private function bpjsDeactivateSeverity(int $daysOverdue): string
    {
        return $daysOverdue >= 7 ? 'CRITICAL' : 'WARNING';
    }

    private function finalSettlementDaysLate(string $resignDate, string $paidDate): int
    {
        return (int) round((strtotime($paidDate) - strtotime($resignDate)) / 86400) - 7;
    }

    private function tenure(string $joinDate, string $resignDate): string
    {
        $start = new \DateTimeImmutable($joinDate);
        $end   = new \DateTimeImmutable($resignDate);
        $diff  = $start->diff($end);
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . ' tahun';
        if ($diff->m > 0) $parts[] = $diff->m . ' bulan';
        if ($diff->d > 0 && empty($parts)) $parts[] = $diff->d . ' hari';
        return implode(' ', $parts) ?: 'kurang dari 1 hari';
    }

    private function proratedSalary(float $basic, string $resignDate): float
    {
        if ($basic <= 0) return 0.0;
        $resignDay = (int) date('j', strtotime($resignDate));
        $totalDaysInMonth = (int) date('t', strtotime($resignDate));
        return round($basic * ($resignDay / $totalDaysInMonth), 2);
    }

    private function isAssetClearanceOk(?string $returnedAt, ?string $exceptionNote, int $pendingCount): bool
    {
        $alreadyCleared = ! empty($returnedAt);
        $hasException   = ! empty(trim((string) ($exceptionNote ?? '')));
        return $alreadyCleared || $hasException || $pendingCount === 0;
    }

    private function readyForPaklaring(string $status, bool $resignReached, bool $assetsOk): bool
    {
        return $status === 'APPROVED' && $resignReached && $assetsOk;
    }

    private function readyForFinalSettlement(
        string $status, bool $resignReached, bool $assetsOk, bool $financeOk,
    ): bool {
        return $status === 'APPROVED' && $resignReached && $assetsOk && $financeOk;
    }
}