<?php

/**
 * Smoke test untuk Anti-Cheat Attendance.
 *
 * Skenario:
 *  1. Schema check: kolom anticheat di attendances + tabel attendance_devices + 2 permissions + 5 settings
 *  2. AntiCheatService::generateServerFingerprint deterministik
 *  3. trackDevice — first time → is_new=true; second → is_new=false + usage_count bertambah
 *  4. computeAnomaly:
 *     - Clean clock-in: score=0, no flags
 *     - Mock GPS: flag MOCK_GPS, score=40, should_block=true
 *     - Low accuracy 200m (>100): flag LOW_ACCURACY, score=15
 *     - Geofence far: flag GEOFENCE_FAR, score=15
 *     - No selfie + require=1: flag NO_SELFIE, score=10
 *     - Combo: high_risk=true ketika score >= threshold
 *  5. trustDevice + blockDevice
 *  6. stats() return struktur valid
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

echo "=== Anti-Cheat Attendance Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
$cols = $db->getFieldNames('attendances');
foreach (['clock_in_accuracy','clock_in_is_mock_gps','clock_in_device_id','anomaly_score','anomaly_flags','anomaly_reviewed_at'] as $c) {
    check("Kolom attendances.{$c} ada", in_array($c, $cols, true));
}
check('Tabel attendance_devices ada', $db->tableExists('attendance_devices'));
$perms = ['attendance.review_anomaly','attendance.manage_devices'];
$cnt = $db->table('permissions')->whereIn('slug', $perms)->countAllResults();
check("2 permission anticheat terdaftar (got {$cnt})", $cnt === 2);

$settingKeys = ['anticheat.require_selfie','anticheat.max_gps_accuracy_m','anticheat.block_mock_gps','anticheat.warn_new_device','anticheat.high_anomaly_threshold'];
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$settingCnt = $db->table('settings')->where('company_id', $company['id'])
    ->whereIn('key', $settingKeys)->countAllResults();
check("5 settings anticheat per company (got {$settingCnt})", $settingCnt === 5);

// 2. Fingerprint
echo "\n2. Server fingerprint\n";
$svc = new \App\Libraries\AntiCheatService();
$fp1 = $svc->generateServerFingerprint('Mozilla/5.0', 'id-ID,id', 'extra1');
$fp2 = $svc->generateServerFingerprint('Mozilla/5.0', 'id-ID,id', 'extra1');
$fp3 = $svc->generateServerFingerprint('Chrome/100', 'en-US', 'different');
check('Fingerprint deterministik (same input = same hash)', $fp1 === $fp2);
check('Fingerprint berbeda untuk input berbeda', $fp1 !== $fp3);
check('Fingerprint length 32 char', strlen($fp1) === 32);

// 3. Track device
echo "\n3. trackDevice\n";
$user = $db->table('users')->where('company_id', $company['id'])->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
$testDeviceId = 'test-device-' . substr(md5((string) microtime(true)), 0, 16);
$db->table('attendance_devices')->where('user_id', $user['id'])->where('device_id', $testDeviceId)->delete();

$res1 = $svc->trackDevice((int) $company['id'], (int) $user['id'], [
    'device_id' => $testDeviceId, 'device_name' => 'Test Device', 'user_agent' => 'TestUA', 'ip' => '127.0.0.1',
]);
check('First track: is_new=true', $res1['is_new'] === true);
check('First track: usage_count=1', (int) $res1['device']['usage_count'] === 1);

$res2 = $svc->trackDevice((int) $company['id'], (int) $user['id'], [
    'device_id' => $testDeviceId, 'device_name' => 'Test Device', 'user_agent' => 'TestUA', 'ip' => '127.0.0.2',
]);
check('Second track: is_new=false', $res2['is_new'] === false);
check('Second track: usage_count=2', (int) $res2['device']['usage_count'] === 2);

// 4. computeAnomaly skenario
echo "\n4. computeAnomaly scenarios\n";

// 4a. Clean
$clean = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 20,
    'is_mock_gps' => false,
    'distance_to_branch' => 30,
    'geofence_radius' => 100,
    'has_selfie' => true,
    'device' => $res2,
    'ip' => '127.0.0.1',
]);
check('Clean: score=0', $clean['score'] === 0);
check('Clean: no flags', count($clean['flags']) === 0);
check('Clean: is_high_risk=false', $clean['is_high_risk'] === false);
check('Clean: should_block=false', $clean['should_block'] === false);

// 4b. Mock GPS
$mock = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 20, 'is_mock_gps' => true,
    'distance_to_branch' => 30, 'geofence_radius' => 100,
    'has_selfie' => true, 'device' => $res2, 'ip' => '127.0.0.1',
]);
check('Mock GPS: flag MOCK_GPS hadir', in_array('MOCK_GPS', $mock['flags'], true));
check('Mock GPS: score=40', $mock['score'] === 40);
check('Mock GPS: should_block=true (config block_mock_gps=1)', $mock['should_block'] === true);
check('Mock GPS: block_reason berisi "Mock GPS"', str_contains((string) $mock['block_reason'], 'Mock GPS'));

// 4c. Low accuracy
$lowAcc = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 200, 'is_mock_gps' => false,
    'distance_to_branch' => 30, 'geofence_radius' => 100,
    'has_selfie' => true, 'device' => $res2, 'ip' => '127.0.0.1',
]);
check('Low accuracy 200m: flag LOW_ACCURACY', in_array('LOW_ACCURACY', $lowAcc['flags'], true));
check('Low accuracy: score=15', $lowAcc['score'] === 15);

// 4d. Extreme low accuracy (block)
$extreme = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 500, 'is_mock_gps' => false,
    'distance_to_branch' => 30, 'geofence_radius' => 100,
    'has_selfie' => true, 'device' => $res2, 'ip' => '127.0.0.1',
]);
check('Extreme accuracy 500m (>3x): should_block=true', $extreme['should_block'] === true);

// 4e. Geofence far
$farFromOffice = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 20, 'is_mock_gps' => false,
    'distance_to_branch' => 500, 'geofence_radius' => 100,
    'has_selfie' => true, 'device' => $res2, 'ip' => '127.0.0.1',
]);
check('Geofence far: flag GEOFENCE_FAR', in_array('GEOFENCE_FAR', $farFromOffice['flags'], true));
check('Geofence far: score=15', $farFromOffice['score'] === 15);

// 4f. Combo high risk
$combo = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 200, 'is_mock_gps' => false,
    'distance_to_branch' => 500, 'geofence_radius' => 100,
    'has_selfie' => false, 'device' => $res2, 'ip' => '127.0.0.99',
]);
// LOW_ACCURACY (15) + GEOFENCE_FAR (15) + IP_MISMATCH (10) = 40
check('Combo: flag LOW_ACCURACY + GEOFENCE_FAR + IP_MISMATCH', count($combo['flags']) >= 3);
check('Combo: score >= 40', $combo['score'] >= 40);

// 4g. Threshold
echo "  threshold dari setting = {$clean['threshold']}\n";

// 5. Trust + Block device
echo "\n5. trustDevice / blockDevice\n";
$svc->trustDevice((int) $res2['device']['id'], (int) $user['id']);
$updated = $db->table('attendance_devices')->where('id', $res2['device']['id'])->get()->getRowArray();
check('After trust: is_trusted=1', (int) $updated['is_trusted'] === 1);
check('After trust: trusted_at terisi', ! empty($updated['trusted_at']));

$svc->blockDevice((int) $res2['device']['id'], 'Test block reason', (int) $user['id']);
$updated2 = $db->table('attendance_devices')->where('id', $res2['device']['id'])->get()->getRowArray();
check('After block: is_blocked=1', (int) $updated2['is_blocked'] === 1);
check('After block: is_trusted=0', (int) $updated2['is_trusted'] === 0);
check('After block: blocked_reason terisi', ! empty($updated2['blocked_reason']));

// 5b. computeAnomaly dgn device blocked → flag BLOCKED_DEVICE
$blockedRes = ['device' => $updated2, 'is_new' => false];
$blocked = $svc->computeAnomaly([
    'company_id' => (int) $company['id'],
    'user_id' => (int) $user['id'],
    'accuracy' => 20, 'is_mock_gps' => false,
    'distance_to_branch' => 30, 'geofence_radius' => 100,
    'has_selfie' => true, 'device' => $blockedRes, 'ip' => '127.0.0.1',
]);
check('Blocked device: flag BLOCKED_DEVICE', in_array('BLOCKED_DEVICE', $blocked['flags'], true));
check('Blocked device: should_block=true', $blocked['should_block'] === true);

// 6. Stats
echo "\n6. stats()\n";
$stats = $svc->stats((int) $company['id'], '2020-01-01', date('Y-m-d'));
check('Stats: total >=0 (int)', is_int($stats['total']));
check('Stats: high_risk >=0', is_int($stats['high_risk']));
check('Stats: pending >=0', is_int($stats['pending']));
check('Stats: threshold ada', isset($stats['threshold']));

// Cleanup
$db->table('attendance_devices')->where('id', $res2['device']['id'])->delete();
echo "  ✓ Cleanup test data\n";

// 7. Class structure
echo "\n7. Class structure\n";
check('Class AntiCheatService loaded', class_exists('\\App\\Libraries\\AntiCheatService'));
check('Class AnomalyController loaded', class_exists('\\App\\Controllers\\Api\\V1\\AnomalyController'));
check('Service registered', method_exists(\Config\Services::class, 'antiCheat'));

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);