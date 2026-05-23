<?php

$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'hris_db';
$port = 3306;

$db = @new mysqli($host, $user, $pass, $dbName, $port);
if ($db->connect_errno) {
    fwrite(STDERR, 'CONNECT_FAIL: ' . $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

$slug = 'pt-demo-hris-indonesia';

$companyRes = $db->query(
    "SELECT id, name, career_slug, is_active, deleted_at
     FROM companies
     WHERE career_slug = '{$db->real_escape_string($slug)}'
     LIMIT 1"
);
$company = $companyRes ? $companyRes->fetch_assoc() : null;
$companyId = (int) ($company['id'] ?? 0);

$jobs = [];
$jobRes = $db->query(
    "SELECT id, company_id, reference_number, title, status,
            is_published_to_career, public_slug, career_published_at,
            city, work_arrangement, deleted_at
     FROM job_postings
     WHERE company_id = {$companyId}
     ORDER BY id DESC
     LIMIT 10"
);
if ($jobRes) {
    while ($row = $jobRes->fetch_assoc()) {
        $jobs[] = $row;
    }
}

$publicMatch = [];
$publicRes = $db->query(
    "SELECT id, company_id, reference_number, title, status,
            is_published_to_career, public_slug, career_published_at,
            city, work_arrangement, deleted_at
     FROM job_postings
     WHERE company_id = {$companyId}
       AND is_published_to_career = 1
       AND status = 'OPEN'
       AND deleted_at IS NULL
     ORDER BY career_published_at DESC"
);
if ($publicRes) {
    while ($row = $publicRes->fetch_assoc()) {
        $publicMatch[] = $row;
    }
}

echo json_encode([
    'company' => $company,
    'jobs' => $jobs,
    'public_match' => $publicMatch,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;