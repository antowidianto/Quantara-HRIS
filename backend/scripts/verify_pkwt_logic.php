<?php
/**
 * Quick smoke test untuk H1.12 PkwtCompensationService dan
 * H2.10 NaturaTaxCalculator pure helpers.
 *
 * Run: php scripts/verify_pkwt_logic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\Lifecycle\PkwtCompensationService;
use App\Libraries\Payroll\NaturaTaxCalculator;
use App\Libraries\Billing\FeatureGateService;

$tests = [];

function check(string $label, $expected, $actual): void {
    global $tests;
    $pass = $expected === $actual;
    $tests[] = ['label' => $label, 'pass' => $pass, 'expected' => $expected, 'actual' => $actual];
}

echo "=== PkwtCompensationService (H1.12) ===\n";

// daysBetween
check('daysBetween same day = 1',
    1, PkwtCompensationService::daysBetween('2025-01-01', '2025-01-01'));
check('daysBetween Jan inklusif = 31',
    31, PkwtCompensationService::daysBetween('2025-01-01', '2025-01-31'));
check('daysBetween 2024 leap = 366',
    366, PkwtCompensationService::daysBetween('2024-01-01', '2024-12-31'));
check('daysBetween 2025 reguler = 365',
    365, PkwtCompensationService::daysBetween('2025-01-01', '2025-12-31'));
check('daysBetween empty = 0',
    0, PkwtCompensationService::daysBetween('', '2025-01-01'));
check('daysBetween reversed = 0',
    0, PkwtCompensationService::daysBetween('2025-12-31', '2025-01-01'));

// accumulatedDaysPure
check('accumulatedDaysPure multi = 366+365+181',
    366 + 365 + 181, PkwtCompensationService::accumulatedDaysPure([
        ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'],
        ['start_date' => '2025-01-01', 'end_date' => '2025-12-31'],
        ['start_date' => '2026-01-01', 'end_date' => '2026-06-30'],
    ]));

// tenureMonthsPure
check('tenureMonths 12 bulan exact',
    12, PkwtCompensationService::tenureMonthsPure('2025-01-15', '2026-01-15'));
check('tenureMonths 11 bulan (1 hari kurang)',
    11, PkwtCompensationService::tenureMonthsPure('2025-01-15', '2026-01-14'));
check('tenureMonths < 1 bulan = 0',
    0, PkwtCompensationService::tenureMonthsPure('2025-03-10', '2025-03-25'));

// calculatePure (PP 35/2021 Pasal 16)
check('calculatePure 0 bulan = 0',
    0.0, PkwtCompensationService::calculatePure(0, 5_000_000.0));
check('calculatePure 12 bulan × 5jt = 5jt',
    5_000_000.0, PkwtCompensationService::calculatePure(12, 5_000_000.0));
check('calculatePure 6 bulan × 6jt = 3jt',
    3_000_000.0, PkwtCompensationService::calculatePure(6, 6_000_000.0));
check('calculatePure 24 bulan × 4jt = 8jt',
    8_000_000.0, PkwtCompensationService::calculatePure(24, 4_000_000.0));
check('calculatePure salary 0 = 0',
    0.0, PkwtCompensationService::calculatePure(12, 0.0));

// constants
check('MAX_PKWT_DAYS = 1825',
    1825, PkwtCompensationService::MAX_PKWT_DAYS);

echo "\n=== NaturaTaxCalculator (H2.10 — PMK 66/2023) ===\n";

// monthlyTaxBase
check('Natura no threshold → fully taxable',
    1_000_000.0, NaturaTaxCalculator::monthlyTaxBase(1_000_000, 0));
check('Natura below monthly threshold → 0',
    0.0, NaturaTaxCalculator::monthlyTaxBase(200_000, 3_000_000));
check('Natura at threshold/12 → 0',
    0.0, NaturaTaxCalculator::monthlyTaxBase(250_000, 3_000_000));
check('Natura above threshold/12 → excess',
    750_000.0, NaturaTaxCalculator::monthlyTaxBase(1_000_000, 3_000_000));
check('Natura zero amount → 0',
    0.0, NaturaTaxCalculator::monthlyTaxBase(0, 3_000_000));

// buildEntry
$entry1 = NaturaTaxCalculator::buildEntry(['amount' => 500_000, 'is_taxable' => 1], 0);
check('buildEntry no threshold → tax_base = amount',
    500_000.0, $entry1['natura_tax_base']);
check('buildEntry no threshold → is_natura = 1',
    1, $entry1['is_natura']);

$entry2 = NaturaTaxCalculator::buildEntry(['amount' => 200_000, 'is_taxable' => 1], 3_000_000);
check('buildEntry below ambang → is_taxable override 0',
    0, $entry2['is_taxable']);

$entry3 = NaturaTaxCalculator::buildEntry(['amount' => 1_000_000, 'is_taxable' => 1], 3_000_000);
check('buildEntry above ambang → tax_base = excess',
    750_000.0, $entry3['natura_tax_base']);
check('buildEntry above ambang → is_taxable tetap 1',
    1, $entry3['is_taxable']);

// aggregate — pure cash only
$agg1 = NaturaTaxCalculator::aggregate([
    ['amount' => 5_000_000, 'is_taxable' => 1],
    ['amount' => 1_000_000, 'is_taxable' => 1],
    ['amount' => 500_000, 'is_taxable' => 0],
]);
check('aggregate cash-only gross = 6.5jt',
    6_500_000.0, $agg1['gross_earning']);
check('aggregate cash-only cash = 6.5jt',
    6_500_000.0, $agg1['cash_earning']);
check('aggregate cash-only taxable = 6jt',
    6_000_000.0, $agg1['gross_taxable']);
check('aggregate cash-only natura = 0',
    0.0, $agg1['natura_total']);

// aggregate — mixed
$agg2 = NaturaTaxCalculator::aggregate([
    ['amount' => 8_000_000, 'is_taxable' => 1, 'is_natura' => 0],
    ['amount' => 1_500_000, 'is_taxable' => 1, 'is_natura' => 0],
    ['amount' => 500_000, 'is_taxable' => 0, 'is_natura' => 0],
    ['amount' => 3_000_000, 'is_taxable' => 1, 'is_natura' => 1, 'natura_tax_base' => 2_750_000.0],
]);
check('aggregate mixed gross = 13jt',
    13_000_000.0, $agg2['gross_earning']);
check('aggregate mixed cash = 10jt (kecuali natura)',
    10_000_000.0, $agg2['cash_earning']);
check('aggregate mixed taxable = 12.25jt',
    12_250_000.0, $agg2['gross_taxable']);
check('aggregate mixed natura_total = 3jt',
    3_000_000.0, $agg2['natura_total']);

// aggregate — empty
$aggEmpty = NaturaTaxCalculator::aggregate([]);
check('aggregate empty gross = 0',
    0.0, $aggEmpty['gross_earning']);

echo "\n=== FeatureGateService (S1.1 — SaaS Feature Flags) ===\n";

// Default fallback
$r = FeatureGateService::resolvePure([], [], ['payroll.basic' => true, 'ai.copilot' => false]);
check('default registry source = DEFAULT',
    'DEFAULT', $r['payroll.basic']['source']);
check('default registry enabled propagated',
    true, $r['payroll.basic']['enabled']);

// Plan overrides default
$r = FeatureGateService::resolvePure(
    [],
    ['ai.copilot' => ['is_included' => true, 'quota_value' => null]],
    ['ai.copilot' => false],
);
check('plan menang dari default',
    'PLAN', $r['ai.copilot']['source']);
check('plan enable jadi true',
    true, $r['ai.copilot']['enabled']);

// Override wins
$r = FeatureGateService::resolvePure(
    ['ai.copilot' => ['is_enabled' => true, 'quota_value' => 100, 'expires_at' => null]],
    ['ai.copilot' => ['is_included' => false, 'quota_value' => null]],
    ['ai.copilot' => false],
);
check('override menang dari plan',
    'OVERRIDE', $r['ai.copilot']['source']);
check('override enabled true',
    true, $r['ai.copilot']['enabled']);
check('override quota propagated',
    100, $r['ai.copilot']['quota_value']);

// Expired override fallback
$yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
$r = FeatureGateService::resolvePure(
    ['feat.x' => ['is_enabled' => true, 'quota_value' => null, 'expires_at' => $yesterday]],
    ['feat.x' => ['is_included' => false, 'quota_value' => null]],
    ['feat.x' => true],
);
check('override expired → fallback ke plan',
    'PLAN', $r['feat.x']['source']);
check('override expired → enabled = plan value',
    false, $r['feat.x']['enabled']);

// Override force-disable
$r = FeatureGateService::resolvePure(
    ['feat.y' => ['is_enabled' => false, 'quota_value' => null, 'expires_at' => null]],
    ['feat.y' => ['is_included' => true, 'quota_value' => null]],
    [],
);
check('override force-disable walau plan include',
    false, $r['feat.y']['enabled']);

echo "\n";

$pass = 0;
$fail = 0;
foreach ($tests as $t) {
    $sym = $t['pass'] ? '✓' : '✗';
    echo $sym . ' ' . $t['label'];
    if (! $t['pass']) {
        echo ' [expected: ' . var_export($t['expected'], true)
            . ', actual: ' . var_export($t['actual'], true) . ']';
        $fail++;
    } else {
        $pass++;
    }
    echo PHP_EOL;
}
echo PHP_EOL;
echo "Summary: {$pass} passed, {$fail} failed (total " . count($tests) . ")\n";
exit($fail > 0 ? 1 : 0);