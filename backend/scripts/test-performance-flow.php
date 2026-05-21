<?php

/**
 * Smoke test untuk Performance Management.
 *
 * Skenario:
 *  1. Schema check: 4 tabel + 7 permissions
 *  2. Create cycle + 3 goals dengan weights 40/40/20
 *  3. Validate weights = 100
 *  4. Approve goals
 *  5. Submit check-in → recompute progress
 *  6. Compute overall score dari weighted goal scores
 *  7. Submit review + auto-derive rating
 *  8. Acknowledge by employee
 *  9. Finalize by HR
 * 10. Cycle stats
 */

use CodeIgniter\Boot;
use Config\Paths;

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('FCPATH', __DIR__ . '/../public/');
chdir(FCPATH);

require __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootSpark($paths);

$db = \Config\Database::connect();
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "  [OK]   {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; }
}

echo "=== Performance Management Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel performance_cycles ada', $db->tableExists('performance_cycles'));
check('Tabel goals ada', $db->tableExists('goals'));
check('Tabel goal_check_ins ada', $db->tableExists('goal_check_ins'));
check('Tabel performance_reviews ada', $db->tableExists('performance_reviews'));

$perms = ['performance.view','performance.manage','goal.view','goal.manage','goal.approve','review.conduct','review.view_team'];
$cnt = $db->table('permissions')->whereIn('slug', $perms)->countAllResults();
check("7 permission performance terdaftar (got {$cnt})", $cnt === 7);

// 2. Setup
echo "\n2. Setup\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$emp = $db->table('employees')->where('company_id', $company['id'])->where('is_active', 1)
    ->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$user = $db->table('users')->where('company_id', $company['id'])->where('is_active', 1)
    ->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$reviewer = $db->table('users')->where('company_id', $company['id'])->where('is_active', 1)
    ->where('id !=', (int) $user['id'])->orderBy('id','ASC')->limit(1)->get()->getRowArray();
if (! $reviewer) $reviewer = $user; // fallback

if (! $emp || ! $user) { echo "  ⚠ Butuh employee + user. Skip.\n"; exit($fail > 0 ? 1 : 0); }

// Cleanup
$db->table('performance_reviews')->where('cycle_id IN (SELECT id FROM performance_cycles WHERE code LIKE "TEST_PERF%")')->delete();
$db->table('goal_check_ins')->where('goal_id IN (SELECT id FROM goals WHERE title LIKE "TEST_GOAL%")')->delete();
$db->table('goals')->where('title LIKE', 'TEST_GOAL%')->delete();
$db->table('performance_cycles')->where('code LIKE', 'TEST_PERF%')->delete();

$svc = new \App\Libraries\PerformanceService();

// 3. Create cycle
echo "\n3. Create cycle\n";
$now = date('Y-m-d H:i:s');
$db->table('performance_cycles')->insert([
    'company_id' => $company['id'],
    'code' => 'TEST_PERF_Q1_' . date('Y'),
    'name' => 'TEST Q1 ' . date('Y'),
    'cycle_type' => 'QUARTERLY',
    'start_date' => date('Y-01-01'),
    'end_date' => date('Y-03-31'),
    'status' => 'IN_PROGRESS',
    'created_at' => $now, 'updated_at' => $now,
]);
$cycleId = (int) $db->insertID();
check('Cycle created', $cycleId > 0);

// 4. Create 3 goals dengan weights 40+40+20=100
echo "\n4. Create 3 goals\n";
$goalIds = [];
$goalSpecs = [
    ['title' => 'TEST_GOAL Capai 90% target sales', 'weight' => 40, 'measurement_type' => 'PERCENT', 'target_value' => 90],
    ['title' => 'TEST_GOAL Reduce defect rate < 2%', 'weight' => 40, 'measurement_type' => 'PERCENT', 'target_value' => 100], // inverted
    ['title' => 'TEST_GOAL Selesai 5 training', 'weight' => 20, 'measurement_type' => 'NUMBER', 'target_value' => 5],
];
foreach ($goalSpecs as $i => $spec) {
    $db->table('goals')->insert([
        'company_id' => $company['id'],
        'cycle_id' => $cycleId,
        'employee_id' => $emp['id'],
        'goal_type' => 'KPI',
        'title' => $spec['title'],
        'measurement_type' => $spec['measurement_type'],
        'target_value' => $spec['target_value'],
        'current_value' => 0,
        'weight' => $spec['weight'],
        'status' => 'DRAFT',
        'progress_percent' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $goalIds[] = (int) $db->insertID();
}
check('3 goals created', count($goalIds) === 3);

// 5. Validate weights
echo "\n5. Validate weights\n";
$v = $svc->validateWeights($cycleId, (int) $emp['id']);
check('Total weight = 100', abs($v['total'] - 100) < 0.01);
check('Validation ok=true', $v['ok']);
check('Missing = 0', $v['missing'] < 0.01);

// 6. Approve goals
echo "\n6. Approve goals (manual)\n";
foreach ($goalIds as $gid) {
    $db->table('goals')->where('id', $gid)->update([
        'status' => 'APPROVED',
        'approved_by' => $user['id'],
        'approved_at' => $now,
    ]);
}
$approved = $db->table('goals')->where('cycle_id', $cycleId)->where('status', 'APPROVED')->countAllResults();
check('All 3 goals APPROVED', $approved === 3);

// 7. Submit check-in
echo "\n7. Submit check-in untuk goal #1 (target 90, current 72)\n";
try {
    $r = $svc->submitCheckIn($goalIds[0], (int) $emp['id'], [
        'progress_value' => 72,
        'status_color' => 'YELLOW',
        'comment' => 'Test check-in',
    ]);
    check('check_in_id terbentuk', $r['check_in_id'] > 0);
    check('progress_percent = 80 (72/90)', abs($r['progress_percent'] - 80) < 0.01);
    check('goal_status = IN_PROGRESS', $r['goal_status'] === 'IN_PROGRESS');
} catch (\Throwable $e) {
    check('Check-in ERROR: ' . $e->getMessage(), false);
}

// 8. recomputeGoalProgress untuk goal lain
echo "\n8. recomputeGoalProgress\n";
$db->table('goals')->where('id', $goalIds[1])->update(['current_value' => 100]);
$r2 = $svc->recomputeGoalProgress($goalIds[1]);
check('Goal #2 (100/100) → 100%', abs($r2['progress'] - 100) < 0.01);
check('Goal #2 status = ACHIEVED', $r2['status'] === 'ACHIEVED');

$db->table('goals')->where('id', $goalIds[2])->update(['current_value' => 3]);
$r3 = $svc->recomputeGoalProgress($goalIds[2]);
check('Goal #3 (3/5) → 60%', abs($r3['progress'] - 60) < 0.01);

// 9. Compute overall score
echo "\n9. Compute overall score\n";
// Score: goal#1=80, goal#2=100, goal#3=60
// Weighted: (80*0.4) + (100*0.4) + (60*0.2) = 32 + 40 + 12 = 84
$comp = $svc->computeOverallScore($cycleId, (int) $emp['id'], [
    $goalIds[0] => 80,
    $goalIds[1] => 100,
    $goalIds[2] => 60,
]);
check("Overall score = 84 (got {$comp['overall_score']})", abs($comp['overall_score'] - 84) < 0.01);
check('Rating = EXCEEDS (>=80)', $comp['rating'] === 'EXCEEDS');
check('Breakdown 3 entries', count($comp['breakdown']) === 3);

// 9b. scoreToRating edge cases
echo "\n9b. scoreToRating\n";
check('95 → OUTSTANDING', $svc->scoreToRating(95) === 'OUTSTANDING');
check('85 → EXCEEDS', $svc->scoreToRating(85) === 'EXCEEDS');
check('70 → MEETS', $svc->scoreToRating(70) === 'MEETS');
check('55 → NEEDS_IMPROVEMENT', $svc->scoreToRating(55) === 'NEEDS_IMPROVEMENT');
check('30 → UNSATISFACTORY', $svc->scoreToRating(30) === 'UNSATISFACTORY');

// 10. Submit review
echo "\n10. Submit review\n";
$db->table('performance_reviews')->insert([
    'company_id' => $company['id'],
    'cycle_id' => $cycleId,
    'employee_id' => $emp['id'],
    'reviewer_id' => $reviewer['id'],
    'review_type' => 'MANAGER',
    'status' => 'DRAFT',
    'created_at' => $now, 'updated_at' => $now,
]);
$reviewId = (int) $db->insertID();

try {
    $sub = $svc->submitReview($reviewId, [
        'goal_scores' => [
            $goalIds[0] => 80,
            $goalIds[1] => 100,
            $goalIds[2] => 60,
        ],
        'strengths' => 'Komitmen tinggi pada goal',
        'improvements' => 'Time management',
        'reviewer_comment' => 'Performa baik secara keseluruhan',
        'recommendation' => 'RAISE',
        'recommended_increase_percent' => 5,
    ]);
    check('submitReview overall_score = 84', abs($sub['overall_score'] - 84) < 0.01);
    check('submitReview rating = EXCEEDS', $sub['rating'] === 'EXCEEDS');
} catch (\Throwable $e) {
    check('submitReview ERROR: ' . $e->getMessage(), false);
}

$reviewRow = $db->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
check('Review status = SUBMITTED', $reviewRow['status'] === 'SUBMITTED');
check('Review submitted_at terisi', ! empty($reviewRow['submitted_at']));
check('Goal#1 final_score=80 di-update', (float) $db->table('goals')->where('id', $goalIds[0])->get()->getRow('final_score') === 80.0);

// 11. Acknowledge by employee
echo "\n11. Acknowledge by employee\n";
try {
    $svc->acknowledgeReview($reviewId, (int) $emp['id'], [
        'employee_comment' => 'Setuju dengan review',
    ]);
    $reviewRow = $db->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
    check('Status → ACKNOWLEDGED', $reviewRow['status'] === 'ACKNOWLEDGED');
    check('acknowledged_at terisi', ! empty($reviewRow['acknowledged_at']));
} catch (\Throwable $e) {
    check('Acknowledge ERROR: ' . $e->getMessage(), false);
}

// 12. Finalize
echo "\n12. Finalize\n";
try {
    $svc->finalizeReview($reviewId);
    $reviewRow = $db->table('performance_reviews')->where('id', $reviewId)->get()->getRowArray();
    check('Status → FINALIZED', $reviewRow['status'] === 'FINALIZED');
    check('finalized_at terisi', ! empty($reviewRow['finalized_at']));
} catch (\Throwable $e) {
    check('Finalize ERROR: ' . $e->getMessage(), false);
}

// 13. Cycle stats
echo "\n13. Cycle stats\n";
$stats = $svc->cycleStats($cycleId);
check('Stats: goals.total = 3', $stats['goals']['total'] === 3);
check('Stats: goals.unique_employees = 1', $stats['goals']['unique_employees'] === 1);
check('Stats: avg_progress > 0', $stats['goals']['avg_progress'] > 0);
check('Stats: reviews.total = 1', $stats['reviews']['total'] === 1);
check('Stats: rating_distribution["EXCEEDS"] = 1', ($stats['reviews']['rating_distribution']['EXCEEDS'] ?? 0) === 1);

// 14. Class structure
echo "\n14. Class structure\n";
check('Class PerformanceService loaded', class_exists('\\App\\Libraries\\PerformanceService'));
check('Class PerformanceController loaded', class_exists('\\App\\Controllers\\Api\\V1\\PerformanceController'));
check('Service::performance() registered', method_exists(\Config\Services::class, 'performance'));

// Cleanup
$db->table('performance_reviews')->where('cycle_id', $cycleId)->delete();
$db->table('goal_check_ins')->whereIn('goal_id', $goalIds)->delete();
$db->table('goals')->where('cycle_id', $cycleId)->delete();
$db->table('performance_cycles')->where('id', $cycleId)->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);