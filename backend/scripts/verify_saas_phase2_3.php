<?php
/**
 * Smoke verification script untuk batch S2.2/S2.3/S2.4/S3.1 (Roadmap SaaS).
 *
 * Yang dilakukan:
 *   1. PHP syntax lint per file (php -l replacement via php_check_syntax via tokens)
 *   2. Pure helper unit checks tanpa boot CI4:
 *      - BackupService::retentionClassPure
 *      - BackupService::isExpiredPure
 *      - BackupService::buildFilenamePure
 *      - DataExportService::rowsToCsvPure
 *      - DataExportService::buildManifestPure
 *      - BrandingService::normalizeColorPure
 *      - BrandingService::mergePure
 *
 * Run: php backend/scripts/verify_saas_phase2_3.php
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);

$filesToLint = [
    // Phase 2 + 3 dari sprint sebelumnya
    'app/Libraries/Backup/BackupService.php',
    'app/Libraries/Backup/DataExportService.php',
    'app/Commands/DbBackupEncrypted.php',
    'app/Commands/DbBackupVerify.php',
    'app/Commands/SecurityScanTenantLeaks.php',
    'app/Controllers/Api/V1/Backup/DataExportController.php',
    'app/Libraries/Notification/EmailProviders/EmailProviderInterface.php',
    'app/Libraries/Notification/EmailProviders/SesAdapter.php',
    'app/Controllers/Api/V1/Webhook/EmailWebhookController.php',
    'app/Libraries/WhiteLabel/BrandingService.php',
    'app/Controllers/Api/V1/WhiteLabel/BrandingController.php',
    'app/Database/Migrations/2026-07-15-000001_S2_3_BackupRuns.php',
    'app/Database/Migrations/2026-07-20-000001_S2_4_EmailDeliverability.php',
    'app/Database/Migrations/2026-07-25-000001_S3_1_WhiteLabel.php',
    // Phase 1 lanjutan: S1.4 Quota + S1.6 Dunning
    'app/Database/Migrations/2026-07-30-000001_S1_4_UsageMeters.php',
    'app/Libraries/Billing/QuotaExceededException.php',
    'app/Libraries/Billing/UsageMeterService.php',
    'app/Commands/BillingResetMonthlyMeters.php',
    'app/Database/Migrations/2026-08-01-000001_S1_6_DunningActions.php',
    'app/Libraries/Billing/DunningService.php',
    'app/Commands/BillingDunning.php',
    'app/Filters/SuspendedTenantFilter.php',
    // S2.5 Rate limit
    'app/Libraries/Security/RedisRateLimiter.php',
    // S3.4 Customer Success
    'app/Database/Migrations/2026-08-05-000001_S3_4_CustomerSuccess.php',
    'app/Libraries/CustomerSuccess/CustomerHealthService.php',
    'app/Commands/CsComputeHealthScores.php',
    'app/Controllers/Api/V1/CustomerSuccess/CustomerSuccessController.php',
    // Config touched
    'app/Config/Routes.php',
    'app/Config/Filters.php',
    'app/Controllers/Api/V1/Billing/BillingController.php',
    'app/Controllers/Api/V1/Employee/EmployeeController.php',
];

echo "=== SaaS Roadmap Phase 2/3 Smoke Test ===\n\n";

// ─── 1. Syntax lint ──────────────────────────────────────────────
echo "Step 1: PHP syntax lint\n";
echo str_repeat('-', 50) . "\n";
$lintFails = 0;
foreach ($filesToLint as $rel) {
    $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (! file_exists($path)) {
        echo "  ✗ MISSING: {$rel}\n";
        $lintFails++;
        continue;
    }
    // exec php -l. Pakai escapeshellarg supaya path dengan space aman.
    $cmd = 'php -l ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $line = implode("\n", $output);
    if ($code === 0 && stripos($line, 'No syntax errors') !== false) {
        echo "  ✓ OK   {$rel}\n";
    } else {
        echo "  ✗ FAIL {$rel}\n     {$line}\n";
        $lintFails++;
    }
}

// ─── 2. Load pure helpers manually ───────────────────────────────
echo "\nStep 2: Pure helper logic tests\n";
echo str_repeat('-', 50) . "\n";
$assertionFails = 0;
$assertionTotal = 0;

function assertEq($actual, $expected, string $label): void
{
    global $assertionFails, $assertionTotal;
    $assertionTotal++;
    if ($actual === $expected) {
        echo "  ✓ {$label}\n";
    } else {
        $a = is_scalar($actual) ? var_export($actual, true) : json_encode($actual);
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        echo "  ✗ {$label}\n     expected={$e}\n     actual={$a}\n";
        $assertionFails++;
    }
}

// Stub kalau Config\Database / Services tidak available — pure helpers tidak butuh.
require_once $baseDir . '/app/Libraries/Backup/BackupService.php';
require_once $baseDir . '/app/Libraries/Backup/DataExportService.php';
require_once $baseDir . '/app/Libraries/WhiteLabel/BrandingService.php';
require_once $baseDir . '/app/Libraries/Billing/UsageMeterService.php';
require_once $baseDir . '/app/Libraries/Billing/DunningService.php';
require_once $baseDir . '/app/Libraries/Security/RedisRateLimiter.php';
require_once $baseDir . '/app/Libraries/CustomerSuccess/CustomerHealthService.php';

use App\Libraries\Backup\BackupService;
use App\Libraries\Backup\DataExportService;
use App\Libraries\WhiteLabel\BrandingService;
use App\Libraries\Billing\UsageMeterService;
use App\Libraries\Billing\DunningService;
use App\Libraries\Security\RedisRateLimiter;
use App\Libraries\CustomerSuccess\CustomerHealthService;

// ─── BackupService::retentionClassPure ──────────────────────────
assertEq(BackupService::retentionClassPure('2026-07-01'), 'MONTHLY', 'retentionClassPure: 1st of month → MONTHLY');
assertEq(BackupService::retentionClassPure('2026-07-05'), 'WEEKLY', 'retentionClassPure: Sunday non-1st → WEEKLY');
assertEq(BackupService::retentionClassPure('2026-07-06'), 'DAILY', 'retentionClassPure: Monday → DAILY');
assertEq(BackupService::retentionClassPure('2026-07-12'), 'WEEKLY', 'retentionClassPure: Sunday non-1st → WEEKLY');

// ─── BackupService::isExpiredPure ───────────────────────────────
$policy = ['daily' => 7, 'weekly' => 4, 'monthly' => 12];
assertEq(BackupService::isExpiredPure('DAILY', '2026-06-01 00:00:00', '2026-06-09 00:00:00', $policy),
    true, 'isExpiredPure: DAILY 8 days old → expired');
assertEq(BackupService::isExpiredPure('DAILY', '2026-06-01 00:00:00', '2026-06-05 00:00:00', $policy),
    false, 'isExpiredPure: DAILY 4 days old → still alive');
assertEq(BackupService::isExpiredPure('WEEKLY', '2026-05-01 00:00:00', '2026-06-15 00:00:00', $policy),
    true, 'isExpiredPure: WEEKLY 45 days old (>28) → expired');
assertEq(BackupService::isExpiredPure('MONTHLY', '2025-01-01 00:00:00', '2026-06-01 00:00:00', $policy),
    true, 'isExpiredPure: MONTHLY 17 months old → expired');
assertEq(BackupService::isExpiredPure('MONTHLY', '2025-12-01 00:00:00', '2026-06-01 00:00:00', $policy),
    false, 'isExpiredPure: MONTHLY 6 months old → still alive');

// ─── BackupService::buildFilenamePure ───────────────────────────
$fn = BackupService::buildFilenamePure('production', '2026-07-15 02:00:00', 'DAILY', false);
assertEq(str_starts_with($fn, 'hris_backup_production_'), true, 'buildFilenamePure: prefix correct');
assertEq(str_ends_with($fn, '_daily.sql.gz'), true, 'buildFilenamePure: DAILY suffix .sql.gz');
$fnGpg = BackupService::buildFilenamePure('production', '2026-07-15 02:00:00', 'WEEKLY', true);
assertEq(str_ends_with($fnGpg, '_weekly.sql.gz.gpg'), true, 'buildFilenamePure: WEEKLY + GPG suffix .gpg');

// ─── DataExportService::rowsToCsvPure ───────────────────────────
$csv = DataExportService::rowsToCsvPure([
    ['id' => 1, 'name' => 'Budi', 'active' => true],
    ['id' => 2, 'name' => 'Ani, Wati', 'active' => false],
]);
assertEq(strpos($csv, 'id,name,active') === 0 || strpos($csv, "id,name,active\n") !== false, true,
    'rowsToCsvPure: header present');
assertEq(str_contains($csv, '"Ani, Wati"'), true, 'rowsToCsvPure: comma in field auto-escaped');
assertEq(str_contains($csv, '1' . "\n") || str_contains($csv, '1' . "\r\n"), true, 'rowsToCsvPure: bool true → 1');

assertEq(DataExportService::rowsToCsvPure([]), '', 'rowsToCsvPure: empty input → empty string');

// ─── DataExportService::buildManifestPure ───────────────────────
$manifest = DataExportService::buildManifestPure(
    42,
    [
        ['name' => 'employees', 'rows' => 100, 'bytes' => 50000],
        ['name' => 'payslips',  'rows' => 1200, 'bytes' => 800000],
    ],
    '2026-07-15 03:00:00'
);
assertEq($manifest['company_id'], 42, 'buildManifestPure: company_id passthrough');
assertEq($manifest['manifest_version'], '1.0', 'buildManifestPure: default version 1.0');
assertEq(count($manifest['tables']), 2, 'buildManifestPure: tables count');
assertEq($manifest['tables'][0]['name'], 'employees', 'buildManifestPure: first table name');
assertEq(is_array($manifest['notes']), true, 'buildManifestPure: notes array present');

// ─── BrandingService::normalizeColorPure ────────────────────────
assertEq(BrandingService::normalizeColorPure('#1E40AF'), '#1E40AF', 'normalizeColorPure: valid 6-digit passthrough');
assertEq(BrandingService::normalizeColorPure('1e40af'),  '#1E40AF', 'normalizeColorPure: lowercase + missing # → uppercase + #');
assertEq(BrandingService::normalizeColorPure('#1E4'),    '#11EE44', 'normalizeColorPure: 3-digit shorthand → 6-digit');
assertEq(BrandingService::normalizeColorPure('not-a-color'), null, 'normalizeColorPure: invalid → null');
assertEq(BrandingService::normalizeColorPure(''),        null, 'normalizeColorPure: empty → null');
assertEq(BrandingService::normalizeColorPure(null),      null, 'normalizeColorPure: null → null');

// ─── BrandingService::mergePure ─────────────────────────────────
$defaults = BrandingService::DEFAULTS;
$row = [
    'id' => 7, 'code' => 'ACME', 'name' => 'Acme Corp',
    'brand_logo_path' => '/uploads/logo.png',
    'brand_primary_color' => '#FF5733',
    'brand_app_name' => 'Acme HR Portal',
    'custom_domain' => 'hr.acme.com',
    'custom_domain_verified_at' => '2026-07-10 12:00:00',
];
$merged = BrandingService::mergePure($defaults, $row);
assertEq($merged['app_name'], 'Acme HR Portal', 'mergePure: brand_app_name override');
assertEq($merged['logo_path'], '/uploads/logo.png', 'mergePure: logo_path passthrough');
assertEq($merged['primary_color'], '#FF5733', 'mergePure: primary_color normalized');
assertEq($merged['secondary_color'], $defaults['secondary_color'], 'mergePure: secondary_color fallback to default');
assertEq($merged['has_custom_domain'], true, 'mergePure: has_custom_domain = true (verified)');
assertEq($merged['company_id'], 7, 'mergePure: company_id passthrough');

// ─── UsageMeterService::computePeriodPure ───────────────────────
$periodMonthly = UsageMeterService::computePeriodPure('MONTHLY', '2026-07-15 14:30:00');
assertEq($periodMonthly['period_start'], '2026-07-01 00:00:00', 'computePeriodPure: MONTHLY start = 1st of month');
assertEq($periodMonthly['period_end'],   '2026-07-31 23:59:59', 'computePeriodPure: MONTHLY end = last day');

$periodNever = UsageMeterService::computePeriodPure('NEVER', '2026-07-15 14:30:00');
assertEq($periodNever['period_start'], null, 'computePeriodPure: NEVER period_start = null');
assertEq($periodNever['period_end'],   null, 'computePeriodPure: NEVER period_end = null');

// ─── UsageMeterService::wouldExceedPure ─────────────────────────
assertEq(UsageMeterService::wouldExceedPure(49, 50, 1),  false, 'wouldExceedPure: 49+1 ≤ 50 = ok');
assertEq(UsageMeterService::wouldExceedPure(50, 50, 1),  true,  'wouldExceedPure: 50+1 > 50 = exceeded');
assertEq(UsageMeterService::wouldExceedPure(100, -1, 1), false, 'wouldExceedPure: limit=-1 = unlimited');
assertEq(UsageMeterService::wouldExceedPure(0, 50, 5),   false, 'wouldExceedPure: 0+5 ≤ 50 = ok');

// ─── DunningService::computeActionForOverduePure ────────────────
assertEq(DunningService::computeActionForOverduePure(0),  null, 'computeAction: H+0 → no action');
assertEq(DunningService::computeActionForOverduePure(1),  'REMINDER_FRIENDLY', 'computeAction: H+1 → REMINDER_FRIENDLY');
assertEq(DunningService::computeActionForOverduePure(3),  'WARNING', 'computeAction: H+3 → WARNING');
assertEq(DunningService::computeActionForOverduePure(7),  'SUSPEND_NOTICE', 'computeAction: H+7 → SUSPEND_NOTICE');
assertEq(DunningService::computeActionForOverduePure(10), 'SUSPENDED', 'computeAction: H+10 → SUSPENDED');
assertEq(DunningService::computeActionForOverduePure(35), 'HARD_CANCELLED', 'computeAction: H+35 → HARD_CANCELLED');

// ─── DunningService::daysOverduePure ────────────────────────────
assertEq(DunningService::daysOverduePure('2026-06-30 00:00:00', '2026-07-05 00:00:00'), 5,
    'daysOverduePure: 5 hari setelah period_end');
assertEq(DunningService::daysOverduePure('2026-07-15 00:00:00', '2026-07-10 00:00:00'), 0,
    'daysOverduePure: period_end belum lewat → 0');

// ─── RedisRateLimiter::consumePure ──────────────────────────────
$rlOk = RedisRateLimiter::consumePure(100, 100, 1.0, 1000, 1000, 1);
assertEq($rlOk['allowed'], true, 'consumePure: full bucket allow consume');
assertEq($rlOk['remaining'], 99, 'consumePure: remaining = 99 after consume 1');

$rlEmpty = RedisRateLimiter::consumePure(0, 100, 1.0, 1000, 1000, 1);
assertEq($rlEmpty['allowed'], false, 'consumePure: empty bucket reject');
assertEq($rlEmpty['retry_after'] >= 1, true, 'consumePure: retry_after ≥ 1 second');

// Refill: 0 token, elapsed 50 sec, refill_rate 1/sec → should refill 50
$rlRefill = RedisRateLimiter::consumePure(0, 100, 1.0, 1000, 1050, 10);
assertEq($rlRefill['allowed'], true, 'consumePure: refilled bucket allow consume');
assertEq($rlRefill['remaining'], 40, 'consumePure: remaining 40 (0+50 refill -10 consume)');

// ─── CustomerHealthService::computeScorePure ────────────────────
$health = CustomerHealthService::computeScorePure([
    'login_frequency'      => 1.0,
    'feature_adoption'     => 1.0,
    'payslip_activity'     => 1.0,
    'support_tickets'      => 1.0,
    'subscription_health'  => 1.0,
]);
assertEq($health['score'], 100.0, 'computeScorePure: all max → 100');
assertEq($health['tier'], 'GREEN', 'computeScorePure: 100 = GREEN');

$healthLow = CustomerHealthService::computeScorePure([
    'login_frequency'      => 0.0,
    'feature_adoption'     => 0.0,
    'payslip_activity'     => 0.0,
    'support_tickets'      => 0.5,
    'subscription_health'  => 0.5,
]);
assertEq($healthLow['tier'], 'RED', 'computeScorePure: low score → RED');
assertEq($healthLow['churn_risk'], 'HIGH', 'computeScorePure: RED tier → HIGH churn');

$healthMid = CustomerHealthService::computeScorePure([
    'login_frequency'      => 0.6,
    'feature_adoption'     => 0.5,
    'payslip_activity'     => 0.7,
    'support_tickets'      => 0.6,
    'subscription_health'  => 0.7,
]);
assertEq($healthMid['tier'], 'YELLOW', 'computeScorePure: mid score → YELLOW');

// ─── Summary ────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 50) . "\n";
echo "Summary:\n";
echo "  Lint failures      : {$lintFails} / " . count($filesToLint) . "\n";
echo "  Assertion failures : {$assertionFails} / {$assertionTotal}\n";

if ($lintFails === 0 && $assertionFails === 0) {
    echo "\n✓ ALL CHECKS PASSED\n";
    exit(0);
} else {
    echo "\n✗ FAILURES DETECTED\n";
    exit(1);
}