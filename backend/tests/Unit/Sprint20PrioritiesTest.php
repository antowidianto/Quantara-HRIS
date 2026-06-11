<?php

namespace Tests\Unit;

use App\Libraries\Api\ApiKeyService;
use App\Libraries\Erp\ErpJournalService;
use App\Libraries\License\LicenseTrackerService;
use App\Libraries\Signing\PsreSigningService;
use App\Libraries\Timesheet\TimesheetService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk 5 modul prioritas Sprint 20:
 *  - H3.4 ERP Journal Integration
 *  - H3.7 Digital Signing PSrE
 *  - H3.8 Open API Portal
 *  - Project Timesheet
 *  - License Expiry Tracker
 *
 * Hanya pure helpers (non-DB) yang di-test di sini supaya tidak butuh
 * fixture database. Integration test menyusul setelah QA env siap.
 *
 * Run: vendor/bin/phpunit --filter Sprint20PrioritiesTest
 */
class Sprint20PrioritiesTest extends TestCase
{
    // ─── ErpJournalService ────────────────────────────────────────────

    public function testErpPickMappingPrefersSpecificCostCenter(): void
    {
        $mappings = [
            ['cost_center_id' => null, 'account_type' => 'SALARY', 'debit_coa' => '5101', 'credit_coa' => '2101', 'is_active' => 1],
            ['cost_center_id' => 7,    'account_type' => 'SALARY', 'debit_coa' => '5101.007', 'credit_coa' => '2101.007', 'is_active' => 1],
        ];
        $picked = ErpJournalService::pickMappingPure($mappings, 7, 'SALARY');
        $this->assertNotNull($picked);
        $this->assertSame('5101.007', $picked['debit_coa']);
    }

    public function testErpPickMappingFallsBackToCatchAll(): void
    {
        $mappings = [
            ['cost_center_id' => null, 'account_type' => 'BPJS_TK', 'debit_coa' => '5103', 'credit_coa' => '2103', 'is_active' => 1],
            ['cost_center_id' => 99,   'account_type' => 'BPJS_TK', 'debit_coa' => 'XXX',  'credit_coa' => 'XXX',  'is_active' => 1],
        ];
        $picked = ErpJournalService::pickMappingPure($mappings, 7, 'BPJS_TK');
        $this->assertSame('5103', $picked['debit_coa']);
    }

    public function testErpPickMappingSkipsInactive(): void
    {
        $mappings = [
            ['cost_center_id' => 7, 'account_type' => 'PPH21', 'debit_coa' => 'X', 'credit_coa' => 'Y', 'is_active' => 0],
            ['cost_center_id' => null, 'account_type' => 'PPH21', 'debit_coa' => '2106', 'credit_coa' => '2107', 'is_active' => 1],
        ];
        $picked = ErpJournalService::pickMappingPure($mappings, 7, 'PPH21');
        $this->assertSame('2106', $picked['debit_coa']);
    }

    public function testErpPickMappingReturnsNullWhenNoMatch(): void
    {
        $this->assertNull(ErpJournalService::pickMappingPure([], 1, 'SALARY'));
    }

    public function testErpIsBalanced(): void
    {
        $this->assertTrue(ErpJournalService::isBalancedPure(1000.00, 1000.00));
        $this->assertTrue(ErpJournalService::isBalancedPure(1000.00, 1000.01)); // dalam tolerance 0.01
        $this->assertFalse(ErpJournalService::isBalancedPure(1000.00, 1500.00));
    }

    public function testErpToCsvEscapesProperly(): void
    {
        $rows = [
            ['code', 'desc'],
            ['A;1', 'Hello "World"'],
            ['B', "Line\nBreak"],
        ];
        $csv = ErpJournalService::toCsvPure($rows);
        $this->assertStringContainsString('"A;1"', $csv);
        $this->assertStringContainsString('"Hello ""World"""', $csv);
        $this->assertStringContainsString('"Line', $csv);
    }

    public function testErpValidateMappingDetectsErrors(): void
    {
        $errors = ErpJournalService::validateMappingPure([
            'account_type' => 'INVALID_TYPE',
            'debit_coa'    => '',
            'credit_coa'   => '',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertContains('account_type tidak valid', $errors);
        $this->assertContains('debit_coa wajib diisi', $errors);
        $this->assertContains('credit_coa wajib diisi', $errors);

        $errSame = ErpJournalService::validateMappingPure([
            'account_type' => 'SALARY',
            'debit_coa'    => '5101',
            'credit_coa'   => '5101',
        ]);
        $this->assertContains('debit_coa dan credit_coa tidak boleh sama', $errSame);

        $ok = ErpJournalService::validateMappingPure([
            'account_type' => 'SALARY',
            'debit_coa'    => '5101',
            'credit_coa'   => '2101',
        ]);
        $this->assertSame([], $ok);
    }

    // ─── PsreSigningService ───────────────────────────────────────────

    public function testPsreAggregateAllSignedReturnsCompleted(): void
    {
        $this->assertSame('COMPLETED', PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'SIGNED', 'SIGNED']));
    }

    public function testPsreAggregateAnyDeclinedReturnsFailed(): void
    {
        $this->assertSame('FAILED', PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'DECLINED', 'PENDING']));
    }

    public function testPsreAggregatePartiallySigned(): void
    {
        $this->assertSame('PARTIALLY_SIGNED', PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'PENDING']));
    }

    public function testPsreAggregateAllExpiredReturnsExpired(): void
    {
        $this->assertSame('EXPIRED', PsreSigningService::aggregateEnvelopeStatusPure(['EXPIRED', 'EXPIRED']));
    }

    public function testPsreAggregateEmptyDefaultsToSent(): void
    {
        $this->assertSame('SENT', PsreSigningService::aggregateEnvelopeStatusPure([]));
    }

    public function testPsreValidateEnvelopeRejectsInvalidPayload(): void
    {
        $errors = PsreSigningService::validateEnvelopePayloadPure([
            'document_type' => 'INVALID',
            'subject'       => 'AB',
            'document_path' => '',
            'expires_at'    => '2020-01-01',
        ]);
        $this->assertContains('document_type tidak valid', $errors);
        $this->assertContains('subject minimal 3 karakter', $errors);
        $this->assertContains('document_path wajib', $errors);
        $this->assertContains('expires_at sudah lewat', $errors);
    }

    public function testPsreValidateEnvelopeAcceptsValid(): void
    {
        $errors = PsreSigningService::validateEnvelopePayloadPure([
            'document_type' => 'CONTRACT',
            'subject'       => 'Kontrak Karyawan A',
            'document_path' => '/storage/contracts/abc.pdf',
            'expires_at'    => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->assertSame([], $errors);
    }

    public function testPsreHashDocumentDeterministic(): void
    {
        $a = PsreSigningService::hashDocumentPure('/tmp/nonexistent.pdf');
        $b = PsreSigningService::hashDocumentPure('/tmp/nonexistent.pdf');
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a)); // SHA-256 hex
    }

    // ─── ApiKeyService ────────────────────────────────────────────────

    public function testApiKeyValidFormat(): void
    {
        $this->assertTrue(ApiKeyService::isValidKeyFormat('qhk_live_abcdef0123456789abcdef0123456789ab'));
        $this->assertTrue(ApiKeyService::isValidKeyFormat('qhk_sand_abcdef0123456789abcdef0123456789ab'));
    }

    public function testApiKeyInvalidFormatRejected(): void
    {
        $this->assertFalse(ApiKeyService::isValidKeyFormat('plain_text_key'));
        $this->assertFalse(ApiKeyService::isValidKeyFormat('qhk_prod_abc')); // too short
        $this->assertFalse(ApiKeyService::isValidKeyFormat('qhk_xxx_' . str_repeat('a', 40))); // wrong env
        $this->assertFalse(ApiKeyService::isValidKeyFormat(''));
    }

    public function testApiKeyIsUsableHandlesRevokedAndExpired(): void
    {
        $this->assertTrue(ApiKeyService::isUsablePure([
            'revoked_at' => null,
            'expires_at' => null,
        ]));
        $this->assertFalse(ApiKeyService::isUsablePure([
            'revoked_at' => '2026-09-01 00:00:00',
            'expires_at' => null,
        ]));
        $this->assertFalse(ApiKeyService::isUsablePure([
            'revoked_at' => null,
            'expires_at' => '2020-01-01 00:00:00',
        ]));
        $this->assertTrue(ApiKeyService::isUsablePure([
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]));
    }

    public function testApiKeySanitizeScopes(): void
    {
        $clean = ApiKeyService::sanitizeScopesPure([
            'employees.read',
            'employees.read', // duplicate
            'INVALID FORMAT', // rejected (uppercase + space)
            ' attendance.read ', // trim
            '',
            'webhooks.subscribe',
        ]);
        sort($clean);
        $this->assertSame(['attendance.read', 'employees.read', 'webhooks.subscribe'], $clean);
    }

    public function testApiKeyHasScope(): void
    {
        $granted = ['employees.read', 'leaves.read'];
        $this->assertTrue(ApiKeyService::hasScopePure($granted, 'employees.read'));
        $this->assertFalse(ApiKeyService::hasScopePure($granted, 'employees.write'));
    }

    // ─── TimesheetService ────────────────────────────────────────────

    public function testTimesheetRoundHoursTo15Min(): void
    {
        // Round-up (ceil) ke kelipatan 0.25 — billable industri standard.
        $this->assertSame(0.0, TimesheetService::roundHoursPure(0));
        $this->assertSame(0.25, TimesheetService::roundHoursPure(0.1));   // 6 min → 15 min
        $this->assertSame(0.5, TimesheetService::roundHoursPure(0.3));    // 18 min → 30 min
        $this->assertSame(0.5, TimesheetService::roundHoursPure(0.4));    // 24 min → 30 min
        $this->assertSame(2.75, TimesheetService::roundHoursPure(2.6));   // 156 min → 165 min
        $this->assertSame(8.0, TimesheetService::roundHoursPure(8.0));    // exact
        $this->assertSame(0.0, TimesheetService::roundHoursPure(-1.0));   // guard
    }

    public function testTimesheetValidateEntryPayload(): void
    {
        $errors = TimesheetService::validateEntryPayloadPure([
            'project_id'       => 0,
            'work_date'        => 'invalid-date',
            'hours'            => 0,
            'task_description' => 'ab',
        ]);
        $this->assertContains('project_id wajib', $errors);
        $this->assertContains('work_date wajib & format YYYY-MM-DD', $errors);
        $this->assertContains('hours harus > 0', $errors);
        $this->assertContains('task_description minimal 3 karakter', $errors);

        $futureErrors = TimesheetService::validateEntryPayloadPure([
            'project_id'       => 1,
            'work_date'        => date('Y-m-d', strtotime('+30 days')),
            'hours'            => 8,
            'task_description' => 'Some valid task description',
        ]);
        $this->assertContains('work_date tidak boleh di masa depan', $futureErrors);

        $tooMuchHours = TimesheetService::validateEntryPayloadPure([
            'project_id'       => 1,
            'work_date'        => date('Y-m-d'),
            'hours'            => 25,
            'task_description' => 'Some valid task description',
        ]);
        $this->assertContains('hours tidak boleh > 24', $tooMuchHours);

        $valid = TimesheetService::validateEntryPayloadPure([
            'project_id'       => 1,
            'work_date'        => date('Y-m-d'),
            'hours'            => 8,
            'task_description' => 'Code review for project X',
        ]);
        $this->assertSame([], $valid);
    }

    public function testTimesheetUtilizationPct(): void
    {
        $this->assertSame(80.0, TimesheetService::utilizationPctPure(8.0, 10.0));
        $this->assertSame(0.0, TimesheetService::utilizationPctPure(0.0, 0.0));
        $this->assertSame(100.0, TimesheetService::utilizationPctPure(8.0, 8.0));
    }

    public function testTimesheetBurnPct(): void
    {
        $this->assertSame(50.0, TimesheetService::burnPctPure(50.0, 100.0));
        $this->assertSame(0.0, TimesheetService::burnPctPure(50.0, 0.0));
    }

    public function testTimesheetMarginPct(): void
    {
        // Revenue 100, Cost 60 → margin 40%
        $this->assertSame(40.0, TimesheetService::marginPctPure(100.0, 60.0));
        // Revenue 0 → 0%
        $this->assertSame(0.0, TimesheetService::marginPctPure(0.0, 50.0));
        // Negative margin (cost > revenue)
        $this->assertSame(-50.0, TimesheetService::marginPctPure(100.0, 150.0));
    }

    // ─── LicenseTrackerService ───────────────────────────────────────

    public function testLicenseResolveActionExpired(): void
    {
        // Sudah lewat, belum dinotif → EXPIRE
        $this->assertSame('EXPIRE', LicenseTrackerService::resolveActionPure(0, [30, 7, 1], -1));
        $this->assertSame('EXPIRE', LicenseTrackerService::resolveActionPure(-5, [30, 7, 1], 30));
        // Sudah dinotif sebagai expired (0) → tidak perlu lagi
        $this->assertNull(LicenseTrackerService::resolveActionPure(-1, [30, 7, 1], 0));
    }

    public function testLicenseResolveActionThreshold(): void
    {
        // Days_left 25, thresholds [30,7,1], belum pernah notif → 30
        $this->assertSame(30, LicenseTrackerService::resolveActionPure(25, [30, 7, 1], -1));
        // Sudah notif H-30, days_left 5 → harus turun ke 7
        $this->assertSame(7, LicenseTrackerService::resolveActionPure(5, [30, 7, 1], 30));
        // Sudah notif H-7, days_left 5 → tidak perlu (5 belum trigger 1)
        $this->assertNull(LicenseTrackerService::resolveActionPure(5, [30, 7, 1], 7));
        // Days_left 1, sudah notif H-7 → trigger H-1
        $this->assertSame(1, LicenseTrackerService::resolveActionPure(1, [30, 7, 1], 7));
        // Days_left > 30 → tidak ada threshold ter-cross
        $this->assertNull(LicenseTrackerService::resolveActionPure(60, [30, 7, 1], -1));
    }

    public function testLicenseParseThresholds(): void
    {
        $this->assertSame([30, 7, 1], LicenseTrackerService::parseThresholdsPure('[30,7,1]'));
        $this->assertSame([], LicenseTrackerService::parseThresholdsPure(''));
        $this->assertSame([], LicenseTrackerService::parseThresholdsPure('not-json'));
        // Filter invalid (≤0 atau >365)
        $this->assertSame([30, 60], LicenseTrackerService::parseThresholdsPure('[30,-5,60,400,"abc"]'));
        // Dedupe
        $this->assertSame([30, 7], LicenseTrackerService::parseThresholdsPure('[30,7,30]'));
    }

    public function testLicenseComputeExpiry(): void
    {
        $this->assertSame('2027-01-15', LicenseTrackerService::computeExpiryPure('2026-01-15', 12));
        $this->assertSame('2026-07-15', LicenseTrackerService::computeExpiryPure('2026-01-15', 6));
        $this->assertNull(LicenseTrackerService::computeExpiryPure(null, 12));
        $this->assertNull(LicenseTrackerService::computeExpiryPure('2026-01-15', null));
        $this->assertNull(LicenseTrackerService::computeExpiryPure('2026-01-15', 0));
        $this->assertNull(LicenseTrackerService::computeExpiryPure('invalid-date', 12));
    }

    public function testLicenseIsValidLifetimeAlwaysValid(): void
    {
        $this->assertTrue(LicenseTrackerService::isLicenseValidPure([
            'status'     => 'ACTIVE',
            'expires_on' => null,
        ], '2030-12-31'));
    }

    public function testLicenseIsValidActiveBeforeExpiry(): void
    {
        $this->assertTrue(LicenseTrackerService::isLicenseValidPure([
            'status'     => 'ACTIVE',
            'expires_on' => '2027-01-15',
        ], '2026-12-31'));
    }

    public function testLicenseIsInvalidAfterExpiry(): void
    {
        $this->assertFalse(LicenseTrackerService::isLicenseValidPure([
            'status'     => 'ACTIVE',
            'expires_on' => '2026-01-15',
        ], '2026-12-31'));
    }

    public function testLicenseIsInvalidWhenRevoked(): void
    {
        $this->assertFalse(LicenseTrackerService::isLicenseValidPure([
            'status'     => 'REVOKED',
            'expires_on' => '2030-01-15',
        ], '2026-01-01'));
    }
}