<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Config/Paths.php';

$paths = new Config\Paths();

// Bootstrap minimal CI config
require $paths->systemDirectory . '/Boot.php';

$app = Config\Services::codeigniter();
$app->initialize();

$db = \Config\Database::connect();

$company = $db->table('companies')
    ->select('id, name, career_slug, is_active, deleted_at')
    ->where('career_slug', 'pt-demo-hris-indonesia')
    ->get()->getRowArray();

$jobs = $db->table('job_postings')
    ->select('id, company_id, reference_number, title, status, is_published_to_career, public_slug, career_published_at, city, work_arrangement, deleted_at')
    ->where('company_id', (int) ($company['id'] ?? 0))
    ->orderBy('id', 'DESC')
    ->get()->getResultArray();

$matchingPublicQuery = $db->table('job_postings')
    ->select('id, reference_number, title, status, is_published_to_career, public_slug, career_published_at, city, work_arrangement, deleted_at')
    ->where('company_id', (int) ($company['id'] ?? 0))
    ->where('is_published_to_career', 1)
    ->where('status', 'OPEN')
    ->where('deleted_at IS NULL')
    ->orderBy('career_published_at', 'DESC')
    ->get()->getResultArray();

echo json_encode([
    'company' => $company,
    'all_jobs' => $jobs,
    'matching_public_query' => $matchingPublicQuery,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;