<?php

namespace Tests\Unit;

use App\Libraries\Recruitment\OnlineTestService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test untuk auto-grading logic OnlineTestService.
 *
 * Logic ini pure (no DB) — bisa dites tanpa setup migration.
 * Run: vendor/bin/phpunit --filter OnlineTestGradingTest
 */
class OnlineTestGradingTest extends TestCase
{
    // ─── MCQ_SINGLE ──────────────────────────────────────────────────

    public function testMcqSingleCorrect(): void
    {
        $snap = ['correct_answer' => 'B'];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('MCQ_SINGLE', 'B', $snap));
    }

    public function testMcqSingleWrong(): void
    {
        $snap = ['correct_answer' => 'B'];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('MCQ_SINGLE', 'A', $snap));
    }

    public function testMcqSingleCaseInsensitive(): void
    {
        $snap = ['correct_answer' => 'B'];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('MCQ_SINGLE', 'b', $snap));
    }

    public function testMcqSingleEmptyResponseFails(): void
    {
        $snap = ['correct_answer' => 'B'];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('MCQ_SINGLE', null, $snap));
    }

    // ─── MCQ_MULTI ───────────────────────────────────────────────────

    public function testMcqMultiCorrectExactMatch(): void
    {
        $snap = ['correct_answer' => ['A', 'C']];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('MCQ_MULTI', ['A', 'C'], $snap));
    }

    public function testMcqMultiCorrectOrderInsensitive(): void
    {
        $snap = ['correct_answer' => ['A', 'C']];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('MCQ_MULTI', ['C', 'A'], $snap));
    }

    public function testMcqMultiPartialFails(): void
    {
        $snap = ['correct_answer' => ['A', 'C']];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('MCQ_MULTI', ['A'], $snap));
    }

    public function testMcqMultiExtraFails(): void
    {
        $snap = ['correct_answer' => ['A', 'C']];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('MCQ_MULTI', ['A', 'B', 'C'], $snap));
    }

    public function testMcqMultiNonArrayResponseFails(): void
    {
        $snap = ['correct_answer' => ['A', 'C']];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('MCQ_MULTI', 'A', $snap));
    }

    // ─── TRUE_FALSE ──────────────────────────────────────────────────

    public function testTrueFalseTrueCorrect(): void
    {
        $snap = ['correct_answer' => true];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('TRUE_FALSE', true, $snap));
    }

    public function testTrueFalseFalseCorrect(): void
    {
        $snap = ['correct_answer' => false];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('TRUE_FALSE', false, $snap));
    }

    public function testTrueFalseStringCoercion(): void
    {
        $snap = ['correct_answer' => true];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('TRUE_FALSE', 'true', $snap));
        $this->assertTrue(OnlineTestService::autoGradeAnswer('TRUE_FALSE', 'benar', $snap));
        $this->assertTrue(OnlineTestService::autoGradeAnswer('TRUE_FALSE', '1', $snap));
    }

    public function testTrueFalseNullResponseFails(): void
    {
        $snap = ['correct_answer' => true];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('TRUE_FALSE', null, $snap));
    }

    // ─── NUMERIC ─────────────────────────────────────────────────────

    public function testNumericExactMatch(): void
    {
        $snap = ['correct_answer' => 100000];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('NUMERIC', 100000, $snap));
    }

    public function testNumericFloatTolerance(): void
    {
        $snap = ['correct_answer' => 3.14];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('NUMERIC', 3.14000001, $snap));
    }

    public function testNumericWrong(): void
    {
        $snap = ['correct_answer' => 100];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('NUMERIC', 99, $snap));
    }

    public function testNumericStringCoercion(): void
    {
        $snap = ['correct_answer' => '42'];
        $this->assertTrue(OnlineTestService::autoGradeAnswer('NUMERIC', 42, $snap));
        $this->assertTrue(OnlineTestService::autoGradeAnswer('NUMERIC', '42', $snap));
    }

    public function testNumericNonNumericResponseFails(): void
    {
        $snap = ['correct_answer' => 100];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('NUMERIC', 'one hundred', $snap));
    }

    // ─── LIKERT_1_5 (DISC) ───────────────────────────────────────────

    public function testLikertAlwaysReturnsFalseFromAutoGrade(): void
    {
        // LIKERT_1_5 doesn't have right/wrong — caller handles DISC tally separately.
        // autoGradeAnswer returning false is intentional; submission logic checks
        // type and awards points + tallies DISC.
        $snap = ['disc_weight' => 'D'];
        $this->assertFalse(OnlineTestService::autoGradeAnswer('LIKERT_1_5', 5, $snap));
    }

    // ─── Unknown type ────────────────────────────────────────────────

    public function testUnknownTypeReturnsFalse(): void
    {
        $this->assertFalse(OnlineTestService::autoGradeAnswer('SHORT_ANSWER', 'jawaban panjang', []));
        $this->assertFalse(OnlineTestService::autoGradeAnswer('UNKNOWN_TYPE', 'foo', []));
    }

    // ─── Constants sanity ────────────────────────────────────────────

    public function testAutoGradeTypesIncludeMcq(): void
    {
        $this->assertContains('MCQ_SINGLE', OnlineTestService::AUTO_GRADE_TYPES);
        $this->assertContains('MCQ_MULTI', OnlineTestService::AUTO_GRADE_TYPES);
        $this->assertContains('TRUE_FALSE', OnlineTestService::AUTO_GRADE_TYPES);
        $this->assertContains('NUMERIC', OnlineTestService::AUTO_GRADE_TYPES);
        $this->assertContains('LIKERT_1_5', OnlineTestService::AUTO_GRADE_TYPES);
    }

    public function testManualTypesIncludeShortAnswer(): void
    {
        $this->assertContains('SHORT_ANSWER', OnlineTestService::MANUAL_GRADE_TYPES);
    }

    public function testCategoriesContainsTpa(): void
    {
        $this->assertArrayHasKey('TPA', OnlineTestService::CATEGORIES);
        $this->assertArrayHasKey('DISC', OnlineTestService::CATEGORIES);
        $this->assertArrayHasKey('ENGLISH', OnlineTestService::CATEGORIES);
    }

    public function testBankCategoriesIncludeIndonesianTpaSegments(): void
    {
        $cats = OnlineTestService::QUESTION_BANK_CATEGORIES;
        $this->assertArrayHasKey('TPA_VERBAL', $cats);
        $this->assertArrayHasKey('TPA_NUMERIK', $cats);
        $this->assertArrayHasKey('TPA_LOGIKA', $cats);
        $this->assertArrayHasKey('DISC', $cats);
    }

    // ─── Invitation token ────────────────────────────────────────────

    public function testInvitationTokenIs64HexChars(): void
    {
        $svc = new OnlineTestService();
        $token = $svc->generateInvitationToken();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testInvitationTokenIsUnique(): void
    {
        $svc = new OnlineTestService();
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $svc->generateInvitationToken();
        }
        $this->assertCount(5, array_unique($tokens));
    }
}