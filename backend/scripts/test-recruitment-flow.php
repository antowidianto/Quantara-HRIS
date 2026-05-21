<?php

/**
 * Smoke test untuk Recruitment / ATS module.
 *
 * Skenario:
 *  1. Schema check (4 tabel + permissions)
 *  2. Generator: nextJobReferenceNumber, nextEmployeeNumber
 *  3. Create job posting → status DRAFT
 *  4. Create candidate → stage NEW + initial stage row
 *  5. Move stage NEW → SCREENING → INTERVIEW_HR → OFFER → HIRED
 *  6. Pipeline stats counter
 *  7. Convert candidate HIRED → employee + auto trigger onboarding
 *  8. Re-convert → exception
 *  9. Reject candidate dengan alasan
 * 10. Cleanup
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

echo "=== Recruitment / ATS Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel job_postings ada',          $db->tableExists('job_postings'));
check('Tabel candidates ada',            $db->tableExists('candidates'));
check('Tabel candidate_stages ada',      $db->tableExists('candidate_stages'));
check('Tabel candidate_documents ada',   $db->tableExists('candidate_documents'));
$perms = $db->table('permissions')->whereIn('slug', [
    'recruitment.view','recruitment.manage','recruitment.hire'
])->countAllResults();
check('3 permission recruitment terdaftar', $perms === 3);

$svc = \Config\Services::recruitment(false);

// 2. Setup test
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->orderBy('id','ASC')->limit(1)->get()->getRowArray();
echo "  Pakai company #{$company['id']} ({$company['name']})\n";

// Cleanup leftover
$db->table('candidate_stages')->where('feedback', 'SMOKE_TEST')->delete();
$db->table('candidate_documents')->like('notes', 'SMOKE_TEST', 'after')->delete();
$db->table('candidates')->where('full_name', 'SMOKE_TEST_CAND')->delete();
$db->table('candidates')->where('full_name', 'SMOKE_TEST_REJECT')->delete();
$db->table('candidates')->where('full_name', 'Test Hire Candidate')->delete();
$db->table('candidates')->where('full_name', 'Test Reject Candidate')->delete();
$db->table('job_postings')->like('title', '[SMOKE TEST]', 'after')->delete();
// Cleanup employee yang dibuat dari test sebelumnya
$db->table('employees')->where('full_name', 'Test Hire Candidate')->delete();

// 3. Generator
echo "\n3. nextJobReferenceNumber & nextEmployeeNumber\n";
$jobRef = $svc->nextJobReferenceNumber((int) $company['id']);
check('Job ref format JOB-YEAR-XXXXX', preg_match('/^JOB-\d{4}-\d{5}$/', $jobRef) === 1);
$empNum = $svc->nextEmployeeNumber((int) $company['id']);
check('Employee number format', preg_match('/^EMP-\d{4}-\d{5}$/', $empNum) === 1);

// 4. Create job posting
echo "\n4. Create job posting\n";
$now = date('Y-m-d H:i:s');
$db->table('job_postings')->insert([
    'company_id' => (int) $company['id'],
    'reference_number' => $jobRef,
    'title' => '[SMOKE TEST] Backend Developer',
    'employment_type' => 'PKWTT',
    'description' => 'Test posting',
    'requirements' => 'PHP, Vue.js',
    'salary_min' => 8000000,
    'salary_max' => 12000000,
    'headcount' => 1,
    'status' => 'OPEN',
    'opened_at' => date('Y-m-d'),
    'created_at' => $now, 'updated_at' => $now,
]);
$jobId = (int) $db->insertID();
check('Job posting dibuat', $jobId > 0);
$job = $db->table('job_postings')->where('id', $jobId)->get()->getRowArray();
check('Status OPEN', $job['status'] === 'OPEN');

// 5. Create candidate
echo "\n5. Create candidate\n";
$db->table('candidates')->insert([
    'company_id' => (int) $company['id'],
    'job_posting_id' => $jobId,
    'full_name' => 'Test Hire Candidate',
    'email' => 'testhire@example.com',
    'phone' => '081234567890',
    'gender' => 'M',
    'expected_salary' => 10000000,
    'available_from' => date('Y-m-d', strtotime('+30 days')),
    'source' => 'WEBSITE',
    'current_stage' => 'NEW',
    'created_at' => $now, 'updated_at' => $now,
]);
$candId = (int) $db->insertID();
check('Candidate dibuat', $candId > 0);

// Insert initial stage NEW
$db->table('candidate_stages')->insert([
    'company_id' => (int) $company['id'],
    'candidate_id' => $candId,
    'stage' => 'NEW',
    'result' => 'PENDING',
    'created_at' => $now, 'updated_at' => $now,
]);

// 6. Move stages
echo "\n6. Move stages: NEW → SCREENING → INTERVIEW_HR → OFFER → HIRED\n";
$svc->moveStage($candId, 'SCREENING', ['feedback' => 'CV oke']);
$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('Stage berubah ke SCREENING', $cand['current_stage'] === 'SCREENING');

$svc->moveStage($candId, 'INTERVIEW_HR', ['scheduled_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('Stage berubah ke INTERVIEW_HR', $cand['current_stage'] === 'INTERVIEW_HR');

$svc->moveStage($candId, 'OFFER', ['feedback' => 'Pass interview HR']);
$svc->moveStage($candId, 'HIRED');
$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('Stage berubah ke HIRED', $cand['current_stage'] === 'HIRED');

$stagesCount = $db->table('candidate_stages')->where('candidate_id', $candId)->countAllResults();
check('Total stages history = 5 (NEW + SCREENING + INTERVIEW_HR + OFFER + HIRED)', $stagesCount === 5);

// 7. Pipeline stats
echo "\n7. pipelineStats()\n";
$stats = $svc->pipelineStats((int) $company['id'], $jobId);
check('stats[HIRED] = 1', $stats['HIRED'] === 1);
check('stats[NEW] = 0',   $stats['NEW'] === 0);
check('Total stages mengandung 8 keys', count($stats) === 8);

// 8. Convert HIRED → employee
echo "\n8. convertToEmployee()\n";
$employeeId = $svc->convertToEmployee($candId, [
    'basic_salary' => 10000000,
    'join_date' => date('Y-m-d', strtotime('+14 days')),
]);
check('Employee dibuat (id ' . $employeeId . ')', $employeeId > 0);
$emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
check('Employee.full_name dari candidate', $emp['full_name'] === 'Test Hire Candidate');
check('Employee.is_active = 1', (int) $emp['is_active'] === 1);
check('Employee number format', preg_match('/^EMP-\d{4}-\d{5}$/', $emp['employee_number']) === 1);

$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('candidate.converted_employee_id = employeeId', (int) $cand['converted_employee_id'] === $employeeId);
check('candidate.converted_at ter-set', ! empty($cand['converted_at']));

// Cek onboarding tasks dibuat (kalau template ada)
$onboardCount = $db->table('employee_tasks')
    ->where('employee_id', $employeeId)
    ->where('type', 'ONBOARD')
    ->countAllResults();
check('Onboarding tasks otomatis dibuat (>0 task)', $onboardCount > 0);

// Job posting status berubah ke FILLED (karena headcount=1 sudah filled)
$jobAfter = $db->table('job_postings')->where('id', $jobId)->get()->getRowArray();
check('Job status FILLED (headcount terpenuhi)', $jobAfter['status'] === 'FILLED');

// 9. Re-convert → exception
echo "\n9. Re-convert HIRED candidate yang sudah di-convert\n";
try {
    $svc->convertToEmployee($candId);
    check('Re-convert harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Re-convert → exception', str_contains($e->getMessage(), 'sudah di-convert'));
}

// 10. Reject candidate
echo "\n10. Reject candidate\n";
$db->table('candidates')->insert([
    'company_id' => (int) $company['id'],
    'job_posting_id' => $jobId,
    'full_name' => 'Test Reject Candidate',
    'email' => 'reject@example.com',
    'current_stage' => 'NEW',
    'created_at' => $now, 'updated_at' => $now,
]);
$candRejId = (int) $db->insertID();
$db->table('candidate_stages')->insert([
    'company_id' => (int) $company['id'],
    'candidate_id' => $candRejId,
    'stage' => 'NEW',
    'result' => 'PENDING',
    'created_at' => $now, 'updated_at' => $now,
]);

$svc->moveStage($candRejId, 'SCREENING');
$svc->reject($candRejId, 'Tidak memenuhi pengalaman minimum');
$candRej = $db->table('candidates')->where('id', $candRejId)->get()->getRowArray();
check('Reject: stage REJECTED', $candRej['current_stage'] === 'REJECTED');
check('Reject: rejection_reason terisi', ! empty($candRej['rejection_reason']));

// Convert REJECTED → exception
try {
    $svc->convertToEmployee($candRejId);
    check('Convert REJECTED harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Convert non-HIRED → exception', str_contains($e->getMessage(), 'HIRED'));
}

// Move dari REJECTED ke non-terminal → exception
try {
    $svc->moveStage($candRejId, 'INTERVIEW_HR');
    check('Move dari REJECTED ke non-terminal harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Move dari terminal → exception', str_contains($e->getMessage(), 'final'));
}

// Cleanup
$db->table('employee_tasks')->where('employee_id', $employeeId)->delete();
$db->table('candidate_stages')->whereIn('candidate_id', [$candId, $candRejId])->delete();
$db->table('candidates')->whereIn('id', [$candId, $candRejId])->delete();
$db->table('employees')->where('id', $employeeId)->delete();
$db->table('job_postings')->where('id', $jobId)->delete();
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);