<?php

namespace Tests\Unit;

use App\Libraries\Recruitment\RecruitmentService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk gate online-test sebelum kandidat boleh maju
 * dari ONLINE_TEST ke INTERVIEW_HR / INTERVIEW_USER / OFFER / HIRED.
 *
 * Hanya menguji analyzer murni (RecruitmentService::analyzeOnlineTestRows)
 * — tidak butuh DB, jadi cocok di test suite Unit yang sekarang.
 *
 * Run: vendor/bin/phpunit --filter RecruitmentOnlineTestGateTest
 */
class RecruitmentOnlineTestGateTest extends TestCase
{
    // ─── Tidak ada assignment → dilewatkan (posisi tanpa test) ───────

    public function testNoAssignmentsReturnsAllDoneWithoutBlocker(): void
    {
        $result = RecruitmentService::analyzeOnlineTestRows([]);
        $this->assertFalse($result['has_assignments']);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame(0, $result['in_progress']);
        $this->assertSame(0, $result['awaiting_review']);
    }

    // ─── Semua selesai & lulus ───────────────────────────────────────

    public function testAllCompletedAndPassedAllowsMove(): void
    {
        $rows = [
            ['status' => 'COMPLETED', 'best_result' => 'PASS', 'test_name' => 'TPA'],
            ['status' => 'COMPLETED', 'best_result' => 'PASS', 'test_name' => 'English'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['has_assignments']);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
    }

    public function testCompletedFailedStillCountsAsDone(): void
    {
        // Test gagal tetap "done" — HR yang putuskan tindak lanjutnya
        // (mau di-reject atau tetap maju tergantung kebijakan).
        $rows = [
            ['status' => 'COMPLETED', 'best_result' => 'FAIL', 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
    }

    // ─── Status PENDING / IN_PROGRESS → blocked ──────────────────────

    public function testPendingAssignmentBlocks(): void
    {
        $rows = [
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(1, $result['pending']);
        $this->assertNotNull($result['blocker_reason']);
        $this->assertStringContainsString('TPA', $result['blocker_reason']);
        $this->assertStringContainsString('belum dikerjakan', $result['blocker_reason']);
    }

    public function testInProgressAssignmentBlocks(): void
    {
        $rows = [
            ['status' => 'IN_PROGRESS', 'best_result' => null, 'test_name' => 'DISC'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(1, $result['in_progress']);
        $this->assertStringContainsString('sedang dikerjakan', $result['blocker_reason']);
    }

    // ─── Status COMPLETED + result REVIEW → menunggu HR review ──────

    public function testCompletedAwaitingReviewBlocks(): void
    {
        // Test punya soal essay → status COMPLETED tapi best_result REVIEW
        // (HR belum manual grade).
        $rows = [
            ['status' => 'COMPLETED', 'best_result' => 'REVIEW', 'test_name' => 'Essay Coding'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(1, $result['awaiting_review']);
        $this->assertStringContainsString('menunggu review', $result['blocker_reason']);
    }

    public function testCompletedPendingResultBlocks(): void
    {
        // best_result PENDING juga menandakan grading belum selesai
        // (mis. job runner async belum jalan).
        $rows = [
            ['status' => 'COMPLETED', 'best_result' => 'PENDING', 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(1, $result['awaiting_review']);
    }

    // ─── EXPIRED & CANCELLED tidak block ─────────────────────────────

    public function testExpiredAssignmentDoesNotBlock(): void
    {
        // Kandidat tidak mengerjakan tepat waktu — HR tetap boleh
        // maju (mungkin keputusan waiver test).
        $rows = [
            ['status' => 'EXPIRED', 'best_result' => null, 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
    }

    public function testCancelledAssignmentDoesNotBlock(): void
    {
        $rows = [
            ['status' => 'CANCELLED', 'best_result' => null, 'test_name' => 'DISC'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
    }

    // ─── Mixed scenarios ─────────────────────────────────────────────

    public function testMixedOneCompleteOnePendingBlocks(): void
    {
        $rows = [
            ['status' => 'COMPLETED', 'best_result' => 'PASS', 'test_name' => 'TPA'],
            ['status' => 'PENDING',   'best_result' => null,   'test_name' => 'English'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(1, $result['pending']);
        $this->assertStringContainsString('English', $result['blocker_reason']);
    }

    public function testMixedExpiredAndCompletedAllowsMove(): void
    {
        // EXPIRED + PASS → boleh lewat (test wajib sudah ada hasilnya,
        // yang expired di-anggap waiver).
        $rows = [
            ['status' => 'EXPIRED',   'best_result' => null,   'test_name' => 'Optional Test'],
            ['status' => 'COMPLETED', 'best_result' => 'PASS', 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['all_done']);
        $this->assertNull($result['blocker_reason']);
    }

    // ─── Blocker reason summarization (>3 issues) ────────────────────

    public function testBlockerReasonSummarizesWhenMoreThanThreeIssues(): void
    {
        $rows = [
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'Test 1'],
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'Test 2'],
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'Test 3'],
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'Test 4'],
            ['status' => 'PENDING', 'best_result' => null, 'test_name' => 'Test 5'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertFalse($result['all_done']);
        $this->assertSame(5, $result['pending']);
        // Hanya 3 nama pertama yang muncul + counter "+N lainnya"
        $this->assertStringContainsString('Test 1', $result['blocker_reason']);
        $this->assertStringContainsString('Test 2', $result['blocker_reason']);
        $this->assertStringContainsString('Test 3', $result['blocker_reason']);
        $this->assertStringNotContainsString('Test 4', $result['blocker_reason']);
        $this->assertStringContainsString('+2 lainnya', $result['blocker_reason']);
    }

    // ─── Constants sanity ────────────────────────────────────────────

    public function testPostOnlineTestStagesContainsExpectedStages(): void
    {
        $this->assertContains('INTERVIEW_HR',   RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertContains('INTERVIEW_USER', RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertContains('OFFER',          RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertContains('HIRED',          RecruitmentService::POST_ONLINE_TEST_STAGES);
        // Stage di bawah ONLINE_TEST tidak boleh di-gate
        $this->assertNotContains('NEW',         RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertNotContains('SCREENING',   RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertNotContains('ONLINE_TEST', RecruitmentService::POST_ONLINE_TEST_STAGES);
    }

    public function testRejectedAndWithdrawnAreNotGated(): void
    {
        // HR harus bisa reject/withdraw kandidat kapan saja, termasuk
        // saat assignment online test masih PENDING (mis. ghosting).
        $this->assertNotContains('REJECTED',  RecruitmentService::POST_ONLINE_TEST_STAGES);
        $this->assertNotContains('WITHDRAWN', RecruitmentService::POST_ONLINE_TEST_STAGES);
    }

    // ─── Edge cases ──────────────────────────────────────────────────

    public function testMissingStatusFieldIsHandledGracefully(): void
    {
        // Defensive: kalau row array entah kenapa miss `status`, jangan
        // throw — anggap saja "non-blocking" (status dianggap ''/unknown
        // yang tidak masuk PENDING/IN_PROGRESS/COMPLETED).
        $rows = [
            ['best_result' => 'PASS', 'test_name' => 'TPA'],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertTrue($result['has_assignments']);
        $this->assertTrue($result['all_done']);
    }

    public function testMissingTestNameFallsBackToDefault(): void
    {
        $rows = [
            ['status' => 'PENDING', 'best_result' => null],
        ];
        $result = RecruitmentService::analyzeOnlineTestRows($rows);
        $this->assertStringContainsString('Online Test', $result['blocker_reason']);
    }
}