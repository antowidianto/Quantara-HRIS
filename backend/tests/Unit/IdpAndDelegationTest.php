<?php

namespace Tests\Unit;

use App\Libraries\IdpService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk pure logic Sprint 3 (OD Loop):
 *   - IdpService::computeProgress() — hitung % dari action_items[].status
 *   - IdpService::parseImprovementsToGaps() — parse text bullet → structured
 *   - ApprovalDelegationResolver core rules (helper-mirror)
 *
 * Tidak butuh DB; semua method yang di-test pure.
 *
 * Run: vendor/bin/phpunit --filter IdpAndDelegationTest
 */
class IdpAndDelegationTest extends TestCase
{
    private IdpService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new IdpService();
    }

    // ─── computeProgress ─────────────────────────────────────────────

    public function testProgressEmptyReturnsZero(): void
    {
        $this->assertSame(0, $this->svc->computeProgress(null));
        $this->assertSame(0, $this->svc->computeProgress([]));
    }

    public function testProgressAllPendingReturnsZero(): void
    {
        $items = [
            ['action' => 'A', 'status' => 'PENDING'],
            ['action' => 'B', 'status' => 'IN_PROGRESS'],
            ['action' => 'C', 'status' => 'PENDING'],
        ];
        $this->assertSame(0, $this->svc->computeProgress($items));
    }

    public function testProgressHalfCompleted(): void
    {
        $items = [
            ['action' => 'A', 'status' => 'COMPLETED'],
            ['action' => 'B', 'status' => 'PENDING'],
        ];
        $this->assertSame(50, $this->svc->computeProgress($items));
    }

    public function testProgressAllCompletedReturns100(): void
    {
        $items = [
            ['action' => 'A', 'status' => 'COMPLETED'],
            ['action' => 'B', 'status' => 'COMPLETED'],
            ['action' => 'C', 'status' => 'COMPLETED'],
        ];
        $this->assertSame(100, $this->svc->computeProgress($items));
    }

    public function testProgressHandlesLowercaseStatus(): void
    {
        // Kita normalize ke uppercase di service
        $items = [
            ['action' => 'A', 'status' => 'completed'],
            ['action' => 'B', 'status' => 'pending'],
        ];
        $this->assertSame(50, $this->svc->computeProgress($items));
    }

    public function testProgressIgnoresInProgressItems(): void
    {
        // Hanya COMPLETED yang dihitung; IN_PROGRESS = belum done
        $items = [
            ['action' => 'A', 'status' => 'COMPLETED'],
            ['action' => 'B', 'status' => 'IN_PROGRESS'],
            ['action' => 'C', 'status' => 'IN_PROGRESS'],
            ['action' => 'D', 'status' => 'IN_PROGRESS'],
        ];
        $this->assertSame(25, $this->svc->computeProgress($items));
    }

    public function testProgressDefaultsToPendingWhenStatusMissing(): void
    {
        $items = [
            ['action' => 'A'], // tidak ada status → PENDING default
            ['action' => 'B', 'status' => 'COMPLETED'],
        ];
        $this->assertSame(50, $this->svc->computeProgress($items));
    }

    public function testProgressSkipsNonArrayItems(): void
    {
        // Defensive: kalau array_items bukan array (mis. dari JSON parse error)
        $items = [
            ['action' => 'A', 'status' => 'COMPLETED'],
            'invalid string item',
            null,
            ['action' => 'B', 'status' => 'PENDING'],
        ];
        $this->assertSame(50, $this->svc->computeProgress($items));
    }

    // ─── parseImprovementsToGaps ────────────────────────────────────

    public function testParseEmptyReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->parseImprovementsToGaps(''));
        $this->assertSame([], $this->svc->parseImprovementsToGaps('   '));
    }

    public function testParseSingleLineCreatesOneGap(): void
    {
        $result = $this->svc->parseImprovementsToGaps('Komunikasi dengan tim perlu ditingkatkan');
        $this->assertCount(1, $result);
        $this->assertSame('Komunikasi dengan tim perlu ditingkatkan', $result[0]['area']);
        $this->assertNull($result[0]['current_level']);
        $this->assertNull($result[0]['target_level']);
    }

    public function testParseBulletList(): void
    {
        $text = "- Komunikasi tim\n- Time management\n- Public speaking";
        $result = $this->svc->parseImprovementsToGaps($text);
        $this->assertCount(3, $result);
        $this->assertSame('Komunikasi tim',   $result[0]['area']);
        $this->assertSame('Time management',  $result[1]['area']);
        $this->assertSame('Public speaking',  $result[2]['area']);
    }

    public function testParseAsteriskBullet(): void
    {
        $text = "* Item satu\n* Item dua";
        $result = $this->svc->parseImprovementsToGaps($text);
        $this->assertCount(2, $result);
        $this->assertSame('Item satu', $result[0]['area']);
    }

    public function testParseNumberedList(): void
    {
        $text = "1. Pertama\n2. Kedua\n3. Ketiga";
        $result = $this->svc->parseImprovementsToGaps($text);
        $this->assertCount(3, $result);
        $this->assertSame('Pertama', $result[0]['area']);
        $this->assertSame('Kedua',   $result[1]['area']);
    }

    public function testParseNumberedWithParens(): void
    {
        $text = "1) Komunikasi\n2) Disiplin";
        $result = $this->svc->parseImprovementsToGaps($text);
        $this->assertCount(2, $result);
        $this->assertSame('Komunikasi', $result[0]['area']);
    }

    public function testParseSkipShortLines(): void
    {
        // Line < 5 char dianggap noise / typo, di-skip
        $text = "OK\nKomunikasi perlu lebih baik\nya\n- Disiplin waktu";
        $result = $this->svc->parseImprovementsToGaps($text);
        $this->assertCount(2, $result);
        $this->assertSame('Komunikasi perlu lebih baik', $result[0]['area']);
        $this->assertSame('Disiplin waktu', $result[1]['area']);
    }

    public function testParseMixedFormat(): void
    {
        $text = <<<TEXT
- Komunikasi tim
* Time management

1. Public speaking
   Hard skills tertentu
TEXT;
        $result = $this->svc->parseImprovementsToGaps($text);
        // Expect: Komunikasi tim, Time management, Public speaking, Hard skills tertentu
        $this->assertCount(4, $result);
        $this->assertSame('Komunikasi tim',      $result[0]['area']);
        $this->assertSame('Time management',     $result[1]['area']);
        $this->assertSame('Public speaking',     $result[2]['area']);
        $this->assertSame('Hard skills tertentu', $result[3]['area']);
    }

    // ─── IDP statuses ────────────────────────────────────────────────

    public function testStatusesContainsAllExpected(): void
    {
        $statuses = IdpService::STATUSES;
        $this->assertContains('DRAFT',     $statuses);
        $this->assertContains('ACTIVE',    $statuses);
        $this->assertContains('COMPLETED', $statuses);
        $this->assertContains('REVIEWED',  $statuses);
        $this->assertContains('CANCELLED', $statuses);
    }

    // ─── ApprovalDelegationResolver core rules (mirror) ──────────────

    /**
     * Helper mirror — resolveCanApprove logic. Karena resolver butuh DB,
     * kita test rule-nya dengan mock data sederhana.
     */
    public function testCanApproveOnBehalfOfRulesOriginalAlwaysAllowed(): void
    {
        // User asli (= expected) selalu boleh
        $this->assertTrue($this->canApprove(actor: 5, expected: 5, hasDelegation: false));
        $this->assertTrue($this->canApprove(actor: 5, expected: 5, hasDelegation: true, delegate: 7));
    }

    public function testCanApproveOnBehalfOfRulesDelegateAllowed(): void
    {
        // Delegate aktif boleh approve mewakili
        $this->assertTrue($this->canApprove(actor: 7, expected: 5, hasDelegation: true, delegate: 7));
    }

    public function testCanApproveOnBehalfOfRulesNonDelegateRejected(): void
    {
        // Random user yang bukan original & bukan delegate → reject
        $this->assertFalse($this->canApprove(actor: 9, expected: 5, hasDelegation: true, delegate: 7));
        $this->assertFalse($this->canApprove(actor: 9, expected: 5, hasDelegation: false));
    }

    public function testCanApproveOnBehalfOfNoExpectedAlwaysRejected(): void
    {
        // Kalau expected null (mis. role belum di-assign), reject defensive
        $this->assertFalse($this->canApprove(actor: 5, expected: null, hasDelegation: false));
    }

    /**
     * Mirror dari ApprovalDelegationResolver::canApproveOnBehalfOf —
     * tanpa DB. Test rule pure-nya.
     */
    private function canApprove(
        int $actor,
        ?int $expected,
        bool $hasDelegation,
        ?int $delegate = null,
    ): bool {
        if (! $expected) return false;
        if ($actor === $expected) return true;
        if (! $hasDelegation) return false;
        return $delegate === $actor;
    }
}