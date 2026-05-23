<?php

namespace Tests\Unit;

use App\Constants\ApprovalStatus;
use App\Constants\AttendanceStatus;
use App\Constants\EmploymentStatus;
use App\Constants\ErrorCode;
use App\Constants\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests untuk Constants class — pastikan value tidak berubah
 * (breaking change) dan helper method bekerja sesuai design.
 */
class ConstantsTest extends TestCase
{
    // ─── AttendanceStatus ────────────────────────────────────────────

    public function testAttendanceStatusValuesMatchEnum(): void
    {
        // Pastikan match dengan ENUM di migrations/000003 + 000008
        $this->assertSame('PRESENT', AttendanceStatus::PRESENT);
        $this->assertSame('LATE', AttendanceStatus::LATE);
        $this->assertSame('ABSENT', AttendanceStatus::ABSENT);
        $this->assertSame('LEAVE', AttendanceStatus::LEAVE);
        $this->assertSame('HOLIDAY', AttendanceStatus::HOLIDAY);
        $this->assertSame('SICK', AttendanceStatus::SICK);
        $this->assertSame('HALF_DAY', AttendanceStatus::HALF_DAY);
    }

    public function testAttendanceStatusAllContainsAllValues(): void
    {
        $this->assertCount(7, AttendanceStatus::ALL);
        $this->assertContains(AttendanceStatus::PRESENT, AttendanceStatus::ALL);
        $this->assertContains(AttendanceStatus::HALF_DAY, AttendanceStatus::ALL);
    }

    public function testAttendanceStatusIsValid(): void
    {
        $this->assertTrue(AttendanceStatus::isValid('PRESENT'));
        $this->assertTrue(AttendanceStatus::isValid('LATE'));
        $this->assertFalse(AttendanceStatus::isValid('FOO'));
        $this->assertFalse(AttendanceStatus::isValid('present'));  // case-sensitive
    }

    public function testAttendanceStatusPresentLike(): void
    {
        // PayrollService::calculatePeriod logic: PRESENT|LATE|HALF_DAY|LEAVE|HOLIDAY hadir
        $this->assertTrue(AttendanceStatus::isPresentLike('PRESENT'));
        $this->assertTrue(AttendanceStatus::isPresentLike('LATE'));
        $this->assertTrue(AttendanceStatus::isPresentLike('HALF_DAY'));
        $this->assertTrue(AttendanceStatus::isPresentLike('LEAVE'));
        $this->assertTrue(AttendanceStatus::isPresentLike('HOLIDAY'));
        $this->assertFalse(AttendanceStatus::isPresentLike('ABSENT'));
        $this->assertFalse(AttendanceStatus::isPresentLike('SICK'));
    }

    public function testAttendanceStatusLabel(): void
    {
        [$label, $color] = AttendanceStatus::label('PRESENT');
        $this->assertSame('Hadir', $label);
        $this->assertSame('green', $color);

        [$label, $color] = AttendanceStatus::label('ABSENT');
        $this->assertSame('Tanpa Kabar', $label);
        $this->assertSame('red', $color);

        [$label, $color] = AttendanceStatus::label('UNKNOWN_STATUS');
        $this->assertSame('Unknown', $label);
        $this->assertSame('gray', $color);
    }

    // ─── EmploymentStatus ────────────────────────────────────────────

    public function testEmploymentStatusValues(): void
    {
        $this->assertSame('PKWT', EmploymentStatus::PKWT);
        $this->assertSame('PKWTT', EmploymentStatus::PKWTT);
        $this->assertSame('PROBATION', EmploymentStatus::PROBATION);
        $this->assertSame('HARIAN', EmploymentStatus::HARIAN);
        $this->assertSame('MAGANG', EmploymentStatus::MAGANG);
        $this->assertSame('OUTSOURCING', EmploymentStatus::OUTSOURCING);
    }

    public function testEmploymentStatusJkpEligibility(): void
    {
        // PP 37/2021 — JKP eligible: PKWT & PKWTT only
        $this->assertTrue(EmploymentStatus::isJkpEligible('PKWT'));
        $this->assertTrue(EmploymentStatus::isJkpEligible('PKWTT'));
        $this->assertTrue(EmploymentStatus::isJkpEligible('pkwt'));  // lowercase OK
        // Excluded: probation, magang, harian, outsourcing
        $this->assertFalse(EmploymentStatus::isJkpEligible('PROBATION'));
        $this->assertFalse(EmploymentStatus::isJkpEligible('MAGANG'));
        $this->assertFalse(EmploymentStatus::isJkpEligible('HARIAN'));
        $this->assertFalse(EmploymentStatus::isJkpEligible('OUTSOURCING'));
        // null = backward-compat (treated eligible)
        $this->assertTrue(EmploymentStatus::isJkpEligible(null));
    }

    public function testEmploymentStatusIsValid(): void
    {
        $this->assertTrue(EmploymentStatus::isValid('PKWTT'));
        $this->assertFalse(EmploymentStatus::isValid('FREELANCE'));
    }

    // ─── ApprovalStatus ──────────────────────────────────────────────

    public function testApprovalStatusValues(): void
    {
        $this->assertSame('DRAFT', ApprovalStatus::DRAFT);
        $this->assertSame('PENDING', ApprovalStatus::PENDING);
        $this->assertSame('APPROVED', ApprovalStatus::APPROVED);
        $this->assertSame('REJECTED', ApprovalStatus::REJECTED);
        $this->assertSame('CANCELED', ApprovalStatus::CANCELED);
    }

    public function testApprovalStatusIsPending(): void
    {
        $this->assertTrue(ApprovalStatus::isPending('DRAFT'));
        $this->assertTrue(ApprovalStatus::isPending('PENDING'));
        $this->assertTrue(ApprovalStatus::isPending('CONSENT_REQUIRED'));
        $this->assertFalse(ApprovalStatus::isPending('APPROVED'));
        $this->assertFalse(ApprovalStatus::isPending('REJECTED'));
    }

    public function testApprovalStatusIsFinal(): void
    {
        $this->assertTrue(ApprovalStatus::isFinal('APPROVED'));
        $this->assertTrue(ApprovalStatus::isFinal('REJECTED'));
        $this->assertTrue(ApprovalStatus::isFinal('CANCELED'));
        $this->assertTrue(ApprovalStatus::isFinal('IMPLEMENTED'));
        $this->assertFalse(ApprovalStatus::isFinal('PENDING'));
        $this->assertFalse(ApprovalStatus::isFinal('DRAFT'));
    }

    // ─── ErrorCode ───────────────────────────────────────────────────

    public function testErrorCodeUsesLowerSnakeCase(): void
    {
        // Konsistensi: semua snake_case lowercase
        $this->assertSame('validation_failed', ErrorCode::VALIDATION_FAILED);
        $this->assertSame('permission_denied', ErrorCode::PERMISSION_DENIED);
        $this->assertSame('rate_limit_exceeded', ErrorCode::RATE_LIMIT_EXCEEDED);
        $this->assertSame('attendance_outside_geofence', ErrorCode::ATTENDANCE_OUTSIDE_GEOFENCE);
    }

    public function testErrorCodeAllAreLowerSnakeCase(): void
    {
        $reflect = new \ReflectionClass(ErrorCode::class);
        $constants = $reflect->getConstants();
        foreach ($constants as $name => $value) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $value,
                "ErrorCode::{$name} = '{$value}' should be lower snake_case"
            );
        }
    }

    // ─── WebhookEvent ────────────────────────────────────────────────

    public function testWebhookEventValuesUseModuleActionPattern(): void
    {
        // Convention: {module}.{action} lowercase + dot separator
        foreach (WebhookEvent::ALL as $event) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                $event,
                "WebhookEvent '{$event}' must follow module.action snake_case pattern"
            );
        }
    }

    public function testWebhookEventIsValid(): void
    {
        $this->assertTrue(WebhookEvent::isValid('leave.approved'));
        $this->assertTrue(WebhookEvent::isValid('payroll.period_finalized'));
        $this->assertTrue(WebhookEvent::isValid('attendance.late_absent'));
        $this->assertFalse(WebhookEvent::isValid('unknown.event'));
        $this->assertFalse(WebhookEvent::isValid('LEAVE.APPROVED'));  // case-sensitive
        $this->assertFalse(WebhookEvent::isValid(''));
    }

    public function testWebhookEventFilterValidDropsUnknown(): void
    {
        $input = [
            'leave.approved',
            'unknown.event',
            'overtime.requested',
            'malicious; DROP TABLE users',
            123,  // non-string
        ];
        $filtered = WebhookEvent::filterValid($input);
        $this->assertSame(
            ['leave.approved', 'overtime.requested'],
            $filtered
        );
    }

    public function testWebhookEventConstantsExist(): void
    {
        $this->assertSame('leave.approved', WebhookEvent::LEAVE_APPROVED);
        $this->assertSame('employee.created', WebhookEvent::EMPLOYEE_CREATED);
        $this->assertSame('payroll.period_finalized', WebhookEvent::PAYROLL_PERIOD_FINALIZED);
        $this->assertSame('disciplinary.sp_issued', WebhookEvent::SP_ISSUED);
    }
}
