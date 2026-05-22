<?php

/**
 * End-to-end smoke test untuk Career Portal module.
 *
 * Skenario:
 *   1. Schema check (career_pages, application_track_tokens) + kolom baru
 *   2. CareerService: slugifyCompany, slugifyJob, getOrCreateCareerPage
 *   3. Public landing — resolve via slug
 *   4. submitApplication: payload lengkap (NIK, NPWP, BPJS, dokumen)
 *      → candidate dibuat + stage NEW + token + notifikasi HR
 *   5. Validasi: CV wajib, NIK 16 digit, dedup email
 *   6. Track token: resolve OK, masking email & nik
 *   7. Move stage NEW → SCREENING → INTERVIEW_HR → OFFER → HIRED
 *   8. convertToEmployee: data Indonesia (nik/npwp/religion/dst) ter-copy
 *      ke `employees`, dokumen ter-copy ke `employee_documents` (kalau ada),
 *      onboarding tasks otomatis ter-trigger.
 *   9. Cleanup
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

echo "=== Career Portal End-to-End Smoke Test ===\n\n";

// 1. Schema
echo "1. Schema check\n";
check('Tabel career_pages ada',                $db->tableExists('career_pages'));
check('Tabel application_track_tokens ada',    $db->tableExists('application_track_tokens'));
$companyCols = $db->getFieldNames('companies');
check('companies.career_slug ada',             in_array('career_slug', $companyCols, true));
$jobCols = $db->getFieldNames('job_postings');
check('job_postings.is_published_to_career ada', in_array('is_published_to_career', $jobCols, true));
check('job_postings.public_slug ada',          in_array('public_slug', $jobCols, true));
check('job_postings.work_arrangement ada',     in_array('work_arrangement', $jobCols, true));
$candCols = $db->getFieldNames('candidates');
check('candidates.nik ada',                    in_array('nik', $candCols, true));
check('candidates.npwp ada',                   in_array('npwp', $candCols, true));
check('candidates.bpjs_kesehatan_no ada',      in_array('bpjs_kesehatan_no', $candCols, true));
check('candidates.religion ada',               in_array('religion', $candCols, true));
check('candidates.applied_via_career ada',     in_array('applied_via_career', $candCols, true));
check('candidates.cv_path ada',                in_array('cv_path', $candCols, true));
$perm = $db->table('permissions')->where('slug', 'careers.manage')->countAllResults();
check('Permission careers.manage terdaftar',  $perm === 1);

$svc = \Config\Services::career(false);

// 2. Setup test
echo "\n2. Setup test\n";
$company = $db->table('companies')->where('is_active', 1)->where('deleted_at IS NULL')
    ->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
echo "  Pakai company #{$company['id']} ({$company['name']})\n";
$cid = (int) $company['id'];

// Cleanup leftover dari run sebelumnya
$db->table('candidates')->where('email', 'budi.test@example.com')->delete();
$db->table('job_postings')->like('title', '[CAREER TEST]', 'after')->delete();
// Cleanup employee dari run sebelumnya
$db->table('employees')->where('full_name', 'Budi Santoso')->delete();
// Cleanup user akun yang dibuat auto oleh test sebelumnya
$db->table('users')->where('email', 'budi.test@example.com')->delete();

// 3. CareerService primitives
echo "\n3. CareerService primitives\n";
$companySlug = $svc->slugifyCompany('PT Demo Karier Indonesia', $cid);
check('slugifyCompany return slug valid', preg_match('/^[a-z0-9-]+$/', $companySlug) === 1);

$page = $svc->getOrCreateCareerPage($cid);
check('getOrCreateCareerPage return ID', ! empty($page['id']));
check('career_page punya brand_color',   ! empty($page['brand_color']));

// Pastikan slug company di-set untuk landing test
if (empty($company['career_slug'])) {
    $newSlug = $svc->slugifyCompany((string) $company['name'], $cid);
    $db->table('companies')->where('id', $cid)->update(['career_slug' => $newSlug]);
    $company['career_slug'] = $newSlug;
}

// 4. Buat job posting dipublish
echo "\n4. Create job posting (published)\n";
$svcRecruit = \Config\Services::recruitment(false);
$ref = $svcRecruit->nextJobReferenceNumber($cid);
$publicSlug = $svc->slugifyJob('[CAREER TEST] Senior Backend Engineer', $ref, $cid);
$now = date('Y-m-d H:i:s');
$db->table('job_postings')->insert([
    'company_id' => $cid,
    'reference_number' => $ref,
    'title' => '[CAREER TEST] Senior Backend Engineer',
    'description' => 'Test posting career portal',
    'requirements' => 'PHP, Vue, MySQL',
    'employment_type' => 'PKWTT',
    'salary_min' => 12000000,
    'salary_max' => 20000000,
    'salary_visible' => 1,
    'headcount' => 1,
    'city' => 'Jakarta',
    'work_arrangement' => 'HYBRID',
    'min_education' => 'S1',
    'min_experience_years' => 3,
    'tags' => json_encode(['Engineering','Senior'], JSON_UNESCAPED_UNICODE),
    'status' => 'OPEN',
    'opened_at' => date('Y-m-d'),
    'is_published_to_career' => 1,
    'career_published_at' => $now,
    'public_slug' => $publicSlug,
    'created_at' => $now, 'updated_at' => $now,
]);
$jobId = (int) $db->insertID();
check('Job posting dibuat', $jobId > 0);

// 5. Public landing
echo "\n5. Public landing\n";
$landing = $svc->getPublicLanding($company['career_slug']);
check('Landing resolved by slug',          $landing !== null);
check('Landing punya jobs (>=1)',          ! empty($landing['jobs']));
check('Stats.open_jobs > 0',               $landing['stats']['open_jobs'] > 0);

$detail = $svc->getPublicJobDetail($cid, $publicSlug);
check('Job detail resolved',               $detail !== null);
check('Detail punya tags (array)',         is_array($detail['tags']));

// 6. Submit application
echo "\n6. Submit application via CareerService\n";
$payload = [
    'full_name' => 'Budi Santoso',
    'email'     => 'budi.test@example.com',
    'phone'     => '+62-812-3456-7890',
    'birth_place' => 'Bandung',
    'birth_date'  => '1995-04-15',
    'gender'    => 'M',
    'nik'       => '3273011504950001',
    'npwp'      => '12.345.678.9-001.000',
    'kk_number' => '3273011504950002',
    'bpjs_kesehatan_no' => '0001234567890',
    'religion'  => 'ISLAM',
    'marital_status' => 'BELUM_KAWIN',
    'address'   => 'Jl. Sudirman No. 1',
    'city'      => 'Jakarta',
    'province'  => 'DKI Jakarta',
    'current_position' => 'Backend Engineer',
    'current_company'  => 'PT Awesome Tech',
    'years_experience' => 5,
    'expected_salary'  => 18000000,
    'available_from'   => date('Y-m-d', strtotime('+30 days')),
    'last_education_level' => 'S1',
    'last_education_institution' => 'Universitas Indonesia',
    'last_education_major' => 'Teknik Informatika',
    'last_education_year'  => 2018,
    'gpa' => 3.65,
    'linkedin_url'  => 'linkedin.com/in/budi',
    'portfolio_url' => 'github.com/budi',
    'cover_letter'  => 'Saya tertarik dengan posisi ini karena alignment dengan stack saya.',
    'cv_path'    => 'careers/2026-05/cv/test_cv.pdf',
    'photo_path' => 'careers/2026-05/photo/test_photo.jpg',
    'ktp_path'   => 'careers/2026-05/ktp/test_ktp.jpg',
    'extra_answers' => [
        'why_us' => 'Kultur kerja kolaboratif & produknya keren',
        'available_overtime' => 'Ya, fleksibel',
    ],
];
$result = $svc->submitApplication($cid, $jobId, $payload, '127.0.0.1');
check('submitApplication return candidate_id',  ! empty($result['candidate_id']));
check('Token panjang 64 hex',                   strlen($result['token']) === 64 && ctype_xdigit($result['token']));

$candId = (int) $result['candidate_id'];
$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('candidate.applied_via_career = 1',       (int) $cand['applied_via_career'] === 1);
check('candidate.nik tersimpan 16 digit',       strlen((string) $cand['nik']) === 16);
check('candidate.npwp digit-only',              ctype_digit((string) $cand['npwp']));
check('candidate.religion = ISLAM',             $cand['religion'] === 'ISLAM');
check('candidate.gpa = 3.65',                   abs((float) $cand['gpa'] - 3.65) < 0.01);
check('candidate.cv_path tersimpan',            ! empty($cand['cv_path']));
check('candidate.extra_answers JSON valid',     is_array(json_decode((string) $cand['extra_answers'], true)));

// candidate_documents harus terisi
$docs = $db->table('candidate_documents')->where('candidate_id', $candId)->countAllResults();
check('candidate_documents created (CV+KTP+photo = 3)', $docs >= 3);

// Initial stage NEW
$stages = $db->table('candidate_stages')->where('candidate_id', $candId)->countAllResults();
check('Initial stage NEW dibuat',               $stages === 1);

// 7. Validasi error scenarios
echo "\n7. Validasi error\n";
try {
    $svc->submitApplication($cid, $jobId, [
        'full_name' => 'Tanpa Email',
        // email kosong
        'phone' => '08123',
        'cv_path' => 'careers/x.pdf',
    ], '127.0.0.2');
    check('Email kosong harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Email kosong → exception',     str_contains($e->getMessage(), 'Email'));
}

try {
    $svc->submitApplication($cid, $jobId, [
        'full_name' => 'NIK Invalid',
        'email' => 'niktest@example.com',
        'phone' => '08123',
        'cv_path' => 'careers/x.pdf',
        'nik' => '12345', // invalid
    ], '127.0.0.3');
    check('NIK <16 digit harusnya throw', false);
} catch (\RuntimeException $e) {
    check('NIK <16 digit → exception',    str_contains($e->getMessage(), 'NIK'));
}

// Dedup
try {
    $svc->submitApplication($cid, $jobId, $payload, '127.0.0.4');
    check('Dedup email harusnya throw', false);
} catch (\RuntimeException $e) {
    check('Dedup email → exception',      str_contains($e->getMessage(), 'sudah melamar'));
}

// CV missing
try {
    $svc->submitApplication($cid, $jobId, [
        'full_name' => 'Tanpa CV',
        'email' => 'tanpacv@example.com',
        'phone' => '08123',
        // cv_path kosong
    ], '127.0.0.5');
    check('CV kosong harusnya throw', false);
} catch (\RuntimeException $e) {
    check('CV wajib → exception',         str_contains($e->getMessage(), 'CV'));
}

// 8. Track token
echo "\n8. Track token\n";
$resolved = $svc->resolveTrackToken($result['token']);
check('Token resolved',                  $resolved !== null);
check('Token punya candidate + job',     ! empty($resolved['candidate']['id']) && ! empty($resolved['job']['id']));

$invalid = $svc->resolveTrackToken('invalidtoken');
check('Invalid token → null',            $invalid === null);

// 9. Move stages → HIRED
echo "\n9. Move stages → HIRED\n";
$svcRecruit->moveStage($candId, 'SCREENING', ['feedback' => 'CV bagus']);
$svcRecruit->moveStage($candId, 'INTERVIEW_HR', ['scheduled_at' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
$svcRecruit->moveStage($candId, 'INTERVIEW_USER', ['feedback' => 'Pass HR interview']);
$svcRecruit->moveStage($candId, 'OFFER', ['feedback' => 'Pass technical, ready for offer']);
$svcRecruit->moveStage($candId, 'HIRED');
$cand = $db->table('candidates')->where('id', $candId)->get()->getRowArray();
check('Stage = HIRED',                   $cand['current_stage'] === 'HIRED');

// 10. Convert ke employee
echo "\n10. Convert candidate → employee (carry-over data Indonesia)\n";
$hireResult = $svcRecruit->convertToEmployee($candId, [
    'basic_salary' => 18000000,
    'join_date'    => date('Y-m-d', strtotime('+14 days')),
]);
$employeeId = $hireResult['employee_id'];
check('Employee created',               $employeeId > 0);
check('Hire response berisi temp_password (one-time)',
    is_string($hireResult['temp_password']) && strlen($hireResult['temp_password']) >= 12);
check('Hire response berisi user_id user yang dibuat',
    is_int($hireResult['user_id']) && $hireResult['user_id'] > 0);
check('Hire response berisi user_email lowercase',
    $hireResult['user_email'] === 'budi.test@example.com');

$emp = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
check('emp.full_name = candidate name', $emp['full_name'] === 'Budi Santoso');
check('emp.email = candidate email',    $emp['email'] === 'budi.test@example.com');

// Carry-over fields — cek yang ada di schema
$empCols = $db->getFieldNames('employees');
if (in_array('religion', $empCols, true)) {
    check('emp.religion = ISLAM',       $emp['religion'] === 'ISLAM');
}
if (in_array('marital_status', $empCols, true)) {
    check('emp.marital_status set',     ! empty($emp['marital_status']));
}
if (in_array('place_of_birth', $empCols, true)) {
    check('emp.place_of_birth set',     ! empty($emp['place_of_birth']));
} elseif (in_array('birth_place', $empCols, true)) {
    check('emp.birth_place set',        ! empty($emp['birth_place']));
}
if (in_array('nik', $empCols, true)) {
    check('emp.nik = 16 digit',         strlen((string) $emp['nik']) === 16);
}
if (in_array('npwp', $empCols, true)) {
    check('emp.npwp set',               ! empty($emp['npwp']));
}

// employee_documents kalau ada
if ($db->tableExists('employee_documents')) {
    $empDocs = $db->table('employee_documents')->where('employee_id', $employeeId)->countAllResults();
    check('employee_documents copied dari candidate (>0)', $empDocs > 0);
}

// Onboarding tasks ter-trigger
$onboardCount = $db->table('employee_tasks')
    ->where('employee_id', $employeeId)
    ->where('type', 'ONBOARD')
    ->countAllResults();
check('Onboarding tasks otomatis dibuat (>0 task)', $onboardCount > 0);

// Auto-completion: task SUBMIT_DOCS, NPWP_VERIFY, BPJS_REGISTER, SETUP_HRIS
echo "\n10b. Auto-completion onboarding tasks dari Career Portal data\n";
$autoCompletedKeys = $db->table('employee_tasks')
    ->select('task_key')
    ->where('employee_id', $employeeId)
    ->where('type', 'ONBOARD')
    ->where('status', 'COMPLETED')
    ->get()->getResultArray();
$autoKeys = array_column($autoCompletedKeys, 'task_key');
check('Task SUBMIT_DOCS auto-completed (dokumen sudah diupload)',  in_array('SUBMIT_DOCS', $autoKeys, true));
check('Task NPWP_VERIFY auto-completed (NPWP valid)',              in_array('NPWP_VERIFY', $autoKeys, true));
check('Task BPJS_REGISTER auto-completed (BPJS sudah ada)',        in_array('BPJS_REGISTER', $autoKeys, true));
check('Task SETUP_HRIS auto-completed (akun user dibuat)',         in_array('SETUP_HRIS', $autoKeys, true));

// Verifikasi user account otomatis dibuat
$userId = $db->table('users')->where('email', 'budi.test@example.com')->select('id')->get()->getRowArray();
check('User akun HRIS otomatis dibuat',  $userId !== null);
if ($userId) {
    $userRow = $db->table('users')->where('id', (int) $userId['id'])->get()->getRowArray();
    check('User.must_change_password = 1', (int) ($userRow['must_change_password'] ?? 0) === 1);
    check('User.employee_id link ke employee', (int) ($userRow['employee_id'] ?? 0) === $employeeId);
    check('User.role_id = 4 (employee)',    (int) ($userRow['role_id'] ?? 0) === 4);
}

// Welcome email ter-enqueue ke email_queue (asalkan tabelnya ada).
// Filter ke subject yang mengandung "Selamat datang" — karena untuk
// karyawan yang sama bisa ada email lain (mis. task assignment notif
// yang juga masuk queue).
if ($db->tableExists('email_queue')) {
    $welcomeMail = $db->table('email_queue')
        ->where('to_email', 'budi.test@example.com')
        ->like('subject', 'Selamat datang')
        ->orderBy('id', 'DESC')
        ->limit(1)
        ->get()->getRowArray();
    check('Welcome email ter-enqueue ke email_queue', $welcomeMail !== null);
    if ($welcomeMail) {
        check('Email subject berisi "Selamat datang"',
            stripos((string) $welcomeMail['subject'], 'selamat datang') !== false);
        check('Email body berisi password sementara user',
            $hireResult['temp_password']
            && str_contains((string) $welcomeMail['body_html'], $hireResult['temp_password']));
        check('Email body berisi email login',
            str_contains((string) $welcomeMail['body_html'], 'budi.test@example.com'));
        check('Email body berisi instruksi ganti password',
            stripos((string) $welcomeMail['body_html'], 'ganti password') !== false);
    }
}

// Confirm CONTRACT_SIGN tetap PENDING (manual)
$contractTask = $db->table('employee_tasks')
    ->where('employee_id', $employeeId)
    ->where('task_key', 'CONTRACT_SIGN')
    ->get()->getRowArray();
check('Task CONTRACT_SIGN tetap PENDING (HR perlu generate kontrak)',
    $contractTask && $contractTask['status'] === 'PENDING');

// Cleanup
echo "\n11. Cleanup\n";
$db->table('employee_tasks')->where('employee_id', $employeeId)->delete();
if ($db->tableExists('employee_documents')) {
    $db->table('employee_documents')->where('employee_id', $employeeId)->delete();
}
$db->table('application_track_tokens')->where('candidate_id', $candId)->delete();
$db->table('candidate_stages')->where('candidate_id', $candId)->delete();
$db->table('candidate_documents')->where('candidate_id', $candId)->delete();
$db->table('candidates')->where('id', $candId)->delete();
$db->table('employees')->where('id', $employeeId)->delete();
$db->table('job_postings')->where('id', $jobId)->delete();
if ($db->tableExists('email_queue')) {
    $db->table('email_queue')->where('to_email', 'budi.test@example.com')->delete();
}
echo "  ✓ Cleanup test data\n";

echo "\n=== Hasil: {$pass} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);