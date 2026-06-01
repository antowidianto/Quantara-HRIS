<?php

// Integration test untuk fitur allowance mapping berbasis cabang & grade.
// Jalankan dari project root:
//   php backend/scripts/test-allowance-flow.php

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
function check($label, $expected, $actual) {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "  [OK]   {$label}\n"; $pass++;
    } else {
        echo "  [FAIL] {$label}\n";
        echo "         expected: " . json_encode($expected) . "\n";
        echo "         actual:   " . json_encode($actual) . "\n";
        $fail++;
    }
}

echo str_repeat('-', 60) . "\n";
echo " Smoke test: Allowance mapping schema & service\n";
echo str_repeat('-', 60) . "\n";

// 1) Tabel ada
foreach (['allowance_types','allowance_rates','employee_allowance_assignments'] as $t) {
    $exists = $db->tableExists($t);
    check("tabel {$t} ada", true, $exists);
}

// 2) Kolom home_lat / home_lng di employees
check('employees.home_lat ada', true, $db->fieldExists('home_lat', 'employees'));
check('employees.home_lng ada', true, $db->fieldExists('home_lng', 'employees'));

// 3) AllowanceService::haversineKm
$d = \App\Libraries\Payroll\AllowanceService::haversineKm(-6.2088, 106.8456, -6.9175, 107.6191);
// Jakarta → Bandung ≈ 122 km
check('Haversine Jakarta→Bandung dalam range 110–135 km', true, $d > 110 && $d < 135);

// 4) Setup test data: type + rate + 2 employees (dekat & jauh dari cabang)
$companyId = (int) $db->table('companies')->where('code', 'DEMO')->get()->getRowArray()['id'];
$branch = $db->table('branches')->where('company_id', $companyId)->limit(1)->get()->getRowArray();
if (! $branch) {
    echo "  [skip] Demo company belum punya cabang.\n";
    exit(0);
}

// Pastikan branch punya koordinat
if (! $branch['lat'] || ! $branch['lng']) {
    $db->table('branches')->where('id', $branch['id'])->update([
        'lat' => -6.2088, 'lng' => 106.8456, // Jakarta
    ]);
    $branch = $db->table('branches')->where('id', $branch['id'])->get()->getRowArray();
}
echo "  [info] branch={$branch['name']} ({$branch['lat']}, {$branch['lng']})\n";

$grade = $db->table('job_grades')->where('company_id', $companyId)->limit(1)->get()->getRowArray();

// Insert (atau pastikan ada) test allowance type
$db->table('allowance_types')->where('code', 'TEST_DIST')->where('company_id', $companyId)->delete();
$db->table('allowance_types')->insert([
    'company_id' => $companyId,
    'code'       => 'TEST_DIST',
    'name'       => 'Tunjangan Jarak (test)',
    'category'   => 'TRANSPORT',
    'is_distance_based' => 1,
    'is_taxable' => 1,
    'default_threshold_km' => 30.00,
    'is_active'  => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
$typeId = (int) $db->insertID();
echo "  [info] allowance_type id={$typeId}\n";

// Insert rate fallback (job_grade=NULL, branch=NULL) supaya cocok untuk
// semua karyawan dalam company tanpa harus tahu grade-nya.
$db->table('allowance_rates')->where('allowance_type_id', $typeId)->delete();
$db->table('allowance_rates')->insert([
    'company_id'        => $companyId,
    'allowance_type_id' => $typeId,
    'job_grade_id'      => null,
    'branch_id'         => null,
    'threshold_km'      => 30.00,
    'rate_below'        => 0,
    'rate_above'        => 500000,
    'created_at'        => date('Y-m-d H:i:s'),
    'updated_at'        => date('Y-m-d H:i:s'),
]);
$rateId = (int) $db->insertID();

$svc = \Config\Services::allowance();

// Simulasi: karyawan jauh (Bandung — > 100 km dari Jakarta)
$farEmp = [
    'id' => 1,
    'company_id' => $companyId,
    'branch_id'  => (int) $branch['id'],
    'job_grade_id' => $grade ? (int) $grade['id'] : null,
    'home_lat' => -6.9175,
    'home_lng' => 107.6191, // Bandung
];
$type = $db->table('allowance_types')->where('id', $typeId)->get()->getRowArray();
$result = $svc->computeForEmployee($farEmp, $type, $branch);
check('jarak jauh: distance > threshold', true, $result['distance_km'] > 30);
check('jarak jauh: eligible = true', true, $result['eligible']);
check('jarak jauh: amount = 500000', 500000.0, $result['amount']);

// Simulasi: karyawan dekat (Jakarta — < 5 km dari kantor)
$nearEmp = [
    'id' => 2,
    'company_id' => $companyId,
    'branch_id'  => (int) $branch['id'],
    'job_grade_id' => $grade ? (int) $grade['id'] : null,
    'home_lat' => -6.2189,  // sekitar 1.5 km dari Sudirman
    'home_lng' => 106.8520,
];
$result2 = $svc->computeForEmployee($nearEmp, $type, $branch);
check('jarak dekat: distance < threshold', true, $result2['distance_km'] < 30);
check('jarak dekat: eligible = false', false, $result2['eligible']);
check('jarak dekat: amount = 0 (rate_below)', 0.0, $result2['amount']);

// Karyawan tanpa koordinat → rate_below dengan note
$noCoordEmp = ['id' => 3, 'company_id' => $companyId, 'branch_id' => $branch['id'], 'job_grade_id' => $grade ? $grade['id'] : null];
$result3 = $svc->computeForEmployee($noCoordEmp, $type, $branch);
check('tanpa koordinat: eligible = false', false, $result3['eligible']);
check('tanpa koordinat: ada note', true, ! empty($result3['note']));

// 5) End-to-end: pilih karyawan demo, set koordinat & branch, recompute
$realEmp = $db->table('employees')
    ->where('company_id', $companyId)
    ->where('is_active', 1)
    ->where('branch_id IS NOT NULL')
    ->limit(1)
    ->get()->getRowArray();
if ($realEmp) {
    // Set koordinat rumah ke Bandung (jauh)
    $db->table('employees')->where('id', $realEmp['id'])->update([
        'home_lat' => -6.9175,
        'home_lng' => 107.6191,
    ]);
    $count = $svc->recomputeEmployee((int) $realEmp['id']);
    check('recomputeEmployee return > 0', true, $count > 0);

    $assignment = $db->table('employee_allowance_assignments')
        ->where('employee_id', $realEmp['id'])
        ->where('allowance_type_id', $typeId)
        ->get()->getRowArray();
    check('snapshot tersimpan di DB', true, $assignment !== null);
    if ($assignment) {
        check('snapshot.amount = 500000', 500000.0, (float) $assignment['amount']);
        check('snapshot.eligible = 1', 1, (int) $assignment['eligible']);
    }

    // Reset koordinat ke dekat — recompute → snapshot harus turun ke 0
    // dan branch-nya juga harus dipakai. Tapi karyawan demo bisa jadi
    // punya branch dengan koordinat berbeda dari Jakarta Pusat. Kita
    // pakai branch karyawan tersebut sebagai referensi.
    $empBranch = $db->table('branches')->where('id', $realEmp['branch_id'])->get()->getRowArray();
    $nearLat = (float) ($empBranch['lat'] ?? -6.2088) + 0.005;
    $nearLng = (float) ($empBranch['lng'] ?? 106.8456) + 0.005;
    $db->table('employees')->where('id', $realEmp['id'])->update([
        'home_lat' => $nearLat,
        'home_lng' => $nearLng,
    ]);
    $svc->recomputeEmployee((int) $realEmp['id']);
    $assignment2 = $db->table('employee_allowance_assignments')
        ->where('employee_id', $realEmp['id'])
        ->where('allowance_type_id', $typeId)
        ->get()->getRowArray();
    check('setelah pindah dekat: amount = 0', 0.0, (float) $assignment2['amount']);
    check('setelah pindah dekat: eligible = 0', 0, (int) $assignment2['eligible']);

    // Cleanup snapshot
    $db->table('employee_allowance_assignments')
        ->where('employee_id', $realEmp['id'])
        ->where('allowance_type_id', $typeId)
        ->delete();
}

// Cleanup
$db->table('allowance_rates')->where('id', $rateId)->delete();
$db->table('allowance_types')->where('id', $typeId)->delete();

echo "\n" . str_repeat('-', 60) . "\n";
echo " Pass: {$pass}   Fail: {$fail}\n";
echo str_repeat('-', 60) . "\n";
exit($fail === 0 ? 0 : 1);