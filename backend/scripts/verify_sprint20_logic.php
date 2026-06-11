<?php

/**
 * Standalone verification script untuk pure helpers Sprint 20.
 * Tidak butuh PHPUnit framework — pure assertion via if/else.
 *
 * Run: cd backend; php scripts/verify_sprint20_logic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\Api\ApiKeyService;
use App\Libraries\Erp\ErpJournalService;
use App\Libraries\License\LicenseTrackerService;
use App\Libraries\Signing\PsreSigningService;
use App\Libraries\Timesheet\TimesheetService;

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, $expected, $actual): void
{
    global $pass, $fail, $failures;
    $eq = is_array($expected) ? ($expected === $actual) : ($expected === $actual);
    if ($eq) {
        echo "  ✓ {$label}\n";
        $pass++;
    } else {
        echo "  ✗ {$label}\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
        $failures[] = $label;
        $fail++;
    }
}

echo "=== Sprint 20 Pure Helper Verification ===\n\n";

// ─── ErpJournalService ───────────────────────────────────────────
echo "[ErpJournalService]\n";
$mappings = [
    ['cost_center_id' => null, 'account_type' => 'SALARY', 'debit_coa' => '5101', 'credit_coa' => '2101', 'is_active' => 1],
    ['cost_center_id' => 7, 'account_type' => 'SALARY', 'debit_coa' => '5101.007', 'credit_coa' => '2101.007', 'is_active' => 1],
];
$picked = ErpJournalService::pickMappingPure($mappings, 7, 'SALARY');
check('pickMapping prefers specific cost_center', '5101.007', $picked['debit_coa']);

$picked2 = ErpJournalService::pickMappingPure($mappings, 99, 'SALARY');
check('pickMapping fallback to catch-all', '5101', $picked2['debit_coa']);

check('isBalanced exact', true, ErpJournalService::isBalancedPure(1000.00, 1000.00));
check('isBalanced tolerance', true, ErpJournalService::isBalancedPure(1000.00, 1000.01));
check('isBalanced unbalanced', false, ErpJournalService::isBalancedPure(1000.00, 1500.00));

$errs = ErpJournalService::validateMappingPure(['account_type' => 'INVALID', 'debit_coa' => '', 'credit_coa' => '']);
check('validateMapping detects all errors (count >= 3)', true, count($errs) >= 3);

$ok = ErpJournalService::validateMappingPure(['account_type' => 'SALARY', 'debit_coa' => '5101', 'credit_coa' => '2101']);
check('validateMapping accepts valid', [], $ok);

// ─── PsreSigningService ─────────────────────────────────────────
echo "\n[PsreSigningService]\n";
check('aggregate ALL_SIGNED → COMPLETED', 'COMPLETED', PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'SIGNED']));
check('aggregate ANY_DECLINED → FAILED',   'FAILED',    PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'DECLINED']));
check('aggregate MIXED → PARTIALLY',       'PARTIALLY_SIGNED', PsreSigningService::aggregateEnvelopeStatusPure(['SIGNED', 'PENDING']));
check('aggregate ALL_EXPIRED → EXPIRED',   'EXPIRED',   PsreSigningService::aggregateEnvelopeStatusPure(['EXPIRED', 'EXPIRED']));
check('aggregate empty → SENT',            'SENT',      PsreSigningService::aggregateEnvelopeStatusPure([]));

$errs = PsreSigningService::validateEnvelopePayloadPure([
    'document_type' => 'INVALID', 'subject' => 'AB', 'document_path' => '', 'expires_at' => '2020-01-01',
]);
check('validateEnvelope detects errors', true, count($errs) >= 4);

$ok = PsreSigningService::validateEnvelopePayloadPure([
    'document_type' => 'CONTRACT', 'subject' => 'Kontrak A', 'document_path' => '/x.pdf',
]);
check('validateEnvelope accepts valid', [], $ok);

$h1 = PsreSigningService::hashDocumentPure('/tmp/abc.pdf');
$h2 = PsreSigningService::hashDocumentPure('/tmp/abc.pdf');
check('hashDocument deterministic', true, $h1 === $h2 && strlen($h1) === 64);

// ─── ApiKeyService ──────────────────────────────────────────────
echo "\n[ApiKeyService]\n";
check('isValidKeyFormat live valid',    true,  ApiKeyService::isValidKeyFormat('qhk_live_' . str_repeat('a', 40)));
check('isValidKeyFormat sandbox valid', true,  ApiKeyService::isValidKeyFormat('qhk_sand_' . str_repeat('b', 30)));
check('isValidKeyFormat plain rejected', false, ApiKeyService::isValidKeyFormat('plain_key'));
check('isValidKeyFormat too short rejected', false, ApiKeyService::isValidKeyFormat('qhk_live_abc'));
check('isValidKeyFormat empty rejected', false, ApiKeyService::isValidKeyFormat(''));

check('isUsable null/null', true, ApiKeyService::isUsablePure(['revoked_at' => null, 'expires_at' => null]));
check('isUsable revoked',   false, ApiKeyService::isUsablePure(['revoked_at' => '2026-09-01', 'expires_at' => null]));
check('isUsable expired',   false, ApiKeyService::isUsablePure(['revoked_at' => null, 'expires_at' => '2020-01-01']));

$clean = ApiKeyService::sanitizeScopesPure([
    'employees.read', 'employees.read', 'INVALID', ' attendance.read ', '',
]);
sort($clean);
check('sanitizeScopes dedupes & normalizes', ['attendance.read', 'employees.read'], $clean);

check('hasScope match',    true,  ApiKeyService::hasScopePure(['x.y'], 'x.y'));
check('hasScope mismatch', false, ApiKeyService::hasScopePure(['x.y'], 'a.b'));

// ─── TimesheetService ───────────────────────────────────────────
echo "\n[TimesheetService]\n";
check('roundHours 0',    0.0,  TimesheetService::roundHoursPure(0));
check('roundHours 0.1',  0.25, TimesheetService::roundHoursPure(0.1));
check('roundHours 0.4',  0.5,  TimesheetService::roundHoursPure(0.4));
check('roundHours 8.0',  8.0,  TimesheetService::roundHoursPure(8.0));
check('roundHours -1',   0.0,  TimesheetService::roundHoursPure(-1));

$errs = TimesheetService::validateEntryPayloadPure([
    'project_id' => 0, 'work_date' => 'invalid', 'hours' => 0, 'task_description' => 'ab',
]);
check('validateEntry detects errors', true, count($errs) >= 4);

$ok = TimesheetService::validateEntryPayloadPure([
    'project_id' => 1, 'work_date' => date('Y-m-d'), 'hours' => 8, 'task_description' => 'Valid task',
]);
check('validateEntry accepts valid', [], $ok);

check('utilization 80%', 80.0, TimesheetService::utilizationPctPure(8.0, 10.0));
check('utilization 0/0', 0.0,  TimesheetService::utilizationPctPure(0, 0));

check('burnPct 50%',     50.0, TimesheetService::burnPctPure(50.0, 100.0));
check('marginPct 40%',   40.0, TimesheetService::marginPctPure(100.0, 60.0));
check('marginPct -50%',  -50.0, TimesheetService::marginPctPure(100.0, 150.0));

// ─── LicenseTrackerService ─────────────────────────────────────
echo "\n[LicenseTrackerService]\n";
check('action EXPIRE when days≤0',   'EXPIRE', LicenseTrackerService::resolveActionPure(0, [30, 7, 1], -1));
check('action skip when already expired', null, LicenseTrackerService::resolveActionPure(-1, [30, 7, 1], 0));
check('action 30 when 25 days left', 30, LicenseTrackerService::resolveActionPure(25, [30, 7, 1], -1));
check('action 7 when 5 days & H30 done', 7, LicenseTrackerService::resolveActionPure(5, [30, 7, 1], 30));
check('action skip when 60 days left', null, LicenseTrackerService::resolveActionPure(60, [30, 7, 1], -1));

check('parseThresholds [30,7,1]', [30, 7, 1], LicenseTrackerService::parseThresholdsPure('[30,7,1]'));
check('parseThresholds empty',    [],         LicenseTrackerService::parseThresholdsPure(''));
check('parseThresholds invalid',  [],         LicenseTrackerService::parseThresholdsPure('not-json'));
check('parseThresholds filter & dedup', [30, 60], LicenseTrackerService::parseThresholdsPure('[30,-5,60,400,30]'));

check('computeExpiry 12mo',  '2027-01-15', LicenseTrackerService::computeExpiryPure('2026-01-15', 12));
check('computeExpiry null',  null,         LicenseTrackerService::computeExpiryPure(null, 12));
check('computeExpiry zero',  null,         LicenseTrackerService::computeExpiryPure('2026-01-15', 0));

check('isLicenseValid lifetime', true, LicenseTrackerService::isLicenseValidPure(['status' => 'ACTIVE', 'expires_on' => null], '2030-01-01'));
check('isLicenseValid before expiry', true, LicenseTrackerService::isLicenseValidPure(['status' => 'ACTIVE', 'expires_on' => '2027-01-15'], '2026-12-31'));
check('isLicenseValid after expiry', false, LicenseTrackerService::isLicenseValidPure(['status' => 'ACTIVE', 'expires_on' => '2026-01-15'], '2026-12-31'));
check('isLicenseValid revoked', false, LicenseTrackerService::isLicenseValidPure(['status' => 'REVOKED', 'expires_on' => '2030-01-15'], '2026-01-01'));

// ─── Summary ───────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
if ($failures !== []) {
    echo "Failed cases:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);