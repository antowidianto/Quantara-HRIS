<?php

/**
 * Generate role-based login notes from the active local database.
 *
 * Usage:
 *   php scripts/generate-login-role-docs.php
 *
 * The script is intentionally read-only against the DB. Passwords are only
 * shown when the stored hash verifies against known seeded debug passwords.
 */

$root = dirname(__DIR__);
$repo = dirname($root);
$envFile = $root . '/.env';
$outDir = $repo . '/docs/debug-logins';

$dbConfig = [
    'hostname' => '127.0.0.1',
    'database' => 'hris_db',
    'username' => 'root',
    'password' => '',
];

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^database\.default\.(hostname|database|username|password)\s*=\s*(.*)$/', $line, $m)) {
            $dbConfig[$m[1]] = trim($m[2], " '\"");
        }
    }
}

$pdo = new PDO(
    "mysql:host={$dbConfig['hostname']};dbname={$dbConfig['database']};charset=utf8mb4",
    $dbConfig['username'],
    $dbConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if (! is_dir($outDir) && ! mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    throw new RuntimeException("Cannot create output directory: {$outDir}");
}

$knownPasswords = [
    'Quantara@2026',
    'Demo@12345',
    'Admin@12345',
    'Password123!',
];

function md(?string $value): string
{
    $value = $value === null || $value === '' ? '-' : $value;
    return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
}

function slugFile(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?: 'unknown';
    return trim($slug, '-');
}

function detectPassword(?string $hash, array $knownPasswords): ?string
{
    if (! $hash) {
        return null;
    }
    foreach ($knownPasswords as $plain) {
        if (password_verify($plain, $hash)) {
            return $plain;
        }
    }
    return null;
}

function table(array $headers, array $rows): string
{
    $out = '| ' . implode(' | ', array_map('md', $headers)) . " |\n";
    $out .= '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";
    foreach ($rows as $row) {
        $out .= '| ' . implode(' | ', array_map('md', $row)) . " |\n";
    }
    return $out;
}

$roles = $pdo->query(
    "SELECT r.id, r.slug, r.name, r.is_system,
            COUNT(DISTINCT u.id) AS user_count,
            COUNT(DISTINCT CASE WHEN u.is_active = 1 THEN u.id END) AS active_user_count,
            COUNT(DISTINCT rp.permission_id) AS permission_count
       FROM roles r
       LEFT JOIN users u ON u.role_id = r.id AND u.deleted_at IS NULL
       LEFT JOIN role_permissions rp ON rp.role_id = r.id
      GROUP BY r.id
      ORDER BY r.id"
)->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query(
    "SELECT u.id AS user_id, u.email, u.name, u.is_active, u.must_change_password,
            u.failed_attempts, u.locked_until, u.twofa_enabled, u.last_login_at,
            u.password_hash, r.id AS role_id, r.slug AS role_slug, r.name AS role_name,
            c.code AS company_code, c.name AS company_name,
            e.id AS employee_id, e.employee_number, e.full_name AS employee_name,
            b.code AS branch_code, d.code AS department_code,
            p.code AS position_code, p.name AS position_name
       FROM users u
       LEFT JOIN roles r ON r.id = u.role_id
       LEFT JOIN companies c ON c.id = u.company_id
       LEFT JOIN employees e ON e.id = u.employee_id OR e.user_id = u.id
       LEFT JOIN branches b ON b.id = e.branch_id
       LEFT JOIN departments d ON d.id = e.department_id
       LEFT JOIN positions p ON p.id = e.position_id
      WHERE u.deleted_at IS NULL
      GROUP BY u.id, e.id
      ORDER BY COALESCE(r.id, 999), u.id"
)->fetchAll(PDO::FETCH_ASSOC);

$usersByRole = [];
$passwordStats = [];
foreach ($users as $row) {
    $roleSlug = $row['role_slug'] ?: 'no_role';
    $password = detectPassword($row['password_hash'] ?? null, $knownPasswords);
    $row['verified_password'] = $password ?: 'UNKNOWN - reset needed';
    $passwordStats[$row['verified_password']] = ($passwordStats[$row['verified_password']] ?? 0) + 1;
    $usersByRole[$roleSlug][] = $row;
}

foreach ($roles as $role) {
    $roleSlug = $role['slug'] ?: 'no_role';
    $rows = $usersByRole[$roleSlug] ?? [];

    $content = "# Role: {$role['name']} (`{$roleSlug}`)\n\n";
    $content .= "Generated from DB `{$dbConfig['database']}` on " . date('Y-m-d H:i:s T') . ".\n\n";
    $content .= "- Role ID: {$role['id']}\n";
    $content .= "- System role: " . ((int) $role['is_system'] === 1 ? 'yes' : 'no') . "\n";
    $content .= "- Users: {$role['user_count']} total, {$role['active_user_count']} active\n";
    $content .= "- Permissions: {$role['permission_count']}\n\n";
    $content .= "Internal login accepts either `email` or `employee_number` as `identifier`.\n\n";

    if (! $rows) {
        $content .= "No user account is currently assigned to this role in the active DB.\n";
    } else {
        $content .= table(
            [
                'User ID',
                'Name',
                'Email identifier',
                'NIP identifier',
                'Password',
                'Active',
                'Must change',
                '2FA',
                'Lock',
                'Branch',
                'Dept',
                'Position',
            ],
            array_map(static fn (array $u): array => [
                $u['user_id'],
                $u['employee_name'] ?: $u['name'],
                $u['email'],
                $u['employee_number'],
                $u['verified_password'],
                (int) $u['is_active'] === 1 ? 'yes' : 'no',
                (int) $u['must_change_password'] === 1 ? 'yes' : 'no',
                (int) $u['twofa_enabled'] === 1 ? 'yes' : 'no',
                $u['locked_until'] ?: '-',
                $u['branch_code'],
                $u['department_code'],
                $u['position_name'] ?: $u['position_code'],
            ], $rows)
        );
    }

    file_put_contents($outDir . '/role-' . slugFile($roleSlug) . '.md', $content);
}

$candidateAccounts = $pdo->query(
    "SELECT ca.id, ca.email, ca.full_name, ca.phone, ca.is_active,
            ca.failed_login_count, ca.locked_until, ca.last_login_at,
            ca.password_hash, c.code AS company_code, c.name AS company_name,
            c.career_slug
       FROM candidate_accounts ca
       LEFT JOIN companies c ON c.id = ca.company_id
      WHERE ca.deleted_at IS NULL
      ORDER BY ca.id"
)->fetchAll(PDO::FETCH_ASSOC);

$candidates = $pdo->query(
    "SELECT cand.id, cand.full_name, cand.email, cand.phone, cand.current_stage,
            cand.applied_at, cand.candidate_account_id,
            jp.title AS job_title, jp.reference_number, c.code AS company_code
       FROM candidates cand
       LEFT JOIN job_postings jp ON jp.id = cand.job_posting_id
       LEFT JOIN companies c ON c.id = cand.company_id
      WHERE cand.deleted_at IS NULL
      ORDER BY cand.id"
)->fetchAll(PDO::FETCH_ASSOC);

$testAssignments = $pdo->query(
    "SELECT ota.id, ota.status, ota.available_from, ota.due_at, ota.max_attempts,
            ota.attempts_used, ota.best_percentage, ota.best_result, ota.completed_at,
            ot.code AS test_code, ot.name AS test_name,
            cand.full_name AS candidate_name, cand.email AS candidate_email,
            ca.email AS account_email, jp.title AS job_title
       FROM online_test_assignments ota
       LEFT JOIN online_tests ot ON ot.id = ota.online_test_id
       LEFT JOIN candidates cand ON cand.id = ota.candidate_id
       LEFT JOIN candidate_accounts ca ON ca.id = ota.candidate_account_id
       LEFT JOIN job_postings jp ON jp.id = ota.job_posting_id
      ORDER BY ota.id"
)->fetchAll(PDO::FETCH_ASSOC);

$jobs = $pdo->query(
    "SELECT jp.id, jp.reference_number, jp.title, jp.status, jp.public_slug,
            jp.public_url_slug, jp.city, jp.employment_type, jp.opened_at,
            jp.closed_at, jp.is_published_to_career, jp.career_published_at,
            c.code AS company_code, c.career_slug
       FROM job_postings jp
       LEFT JOIN companies c ON c.id = jp.company_id
      WHERE jp.deleted_at IS NULL
      ORDER BY jp.id"
)->fetchAll(PDO::FETCH_ASSOC);

$candidateContent = "# Candidate Portal And Online Tests\n\n";
$candidateContent .= "Generated from DB `{$dbConfig['database']}` on " . date('Y-m-d H:i:s T') . ".\n\n";
$candidateContent .= "Candidate login route pattern: `/careers/{career_slug}/account/login`. Login uses `email` only.\n\n";
$candidateContent .= "## Candidate Accounts\n\n";
$candidateContent .= table(
    ['ID', 'Name', 'Email', 'Password', 'Active', 'Failed', 'Lock', 'Career slug'],
    array_map(static function (array $row) use ($knownPasswords): array {
        return [
            $row['id'],
            $row['full_name'],
            $row['email'],
            detectPassword($row['password_hash'] ?? null, $knownPasswords) ?: 'UNKNOWN - reset needed',
            (int) $row['is_active'] === 1 ? 'yes' : 'no',
            $row['failed_login_count'],
            $row['locked_until'] ?: '-',
            $row['career_slug'],
        ];
    }, $candidateAccounts)
);
$candidateContent .= "\n## Applications\n\n";
$candidateContent .= table(
    ['ID', 'Candidate', 'Email', 'Stage', 'Job', 'Reference', 'Applied at', 'Account ID'],
    array_map(static fn (array $row): array => [
        $row['id'],
        $row['full_name'],
        $row['email'],
        $row['current_stage'],
        $row['job_title'],
        $row['reference_number'],
        $row['applied_at'],
        $row['candidate_account_id'],
    ], $candidates)
);
$candidateContent .= "\n## Online Test Assignments\n\n";
$candidateContent .= table(
    ['ID', 'Candidate', 'Account email', 'Job', 'Test', 'Status', 'Attempts', 'Best', 'Completed at', 'Due at'],
    array_map(static fn (array $row): array => [
        $row['id'],
        $row['candidate_name'],
        $row['account_email'],
        $row['job_title'],
        trim(($row['test_code'] ?: '-') . ' ' . ($row['test_name'] ?: '')),
        $row['status'],
        ($row['attempts_used'] ?: '0') . '/' . ($row['max_attempts'] ?: '-'),
        trim(($row['best_percentage'] ?: '-') . ' ' . ($row['best_result'] ?: '')),
        $row['completed_at'],
        $row['due_at'],
    ], $testAssignments)
);
$candidateContent .= "\n## Job Postings\n\n";
$candidateContent .= table(
    ['ID', 'Reference', 'Title', 'Status', 'Published', 'Public slug', 'Career slug', 'City'],
    array_map(static fn (array $row): array => [
        $row['id'],
        $row['reference_number'],
        $row['title'],
        $row['status'],
        (int) $row['is_published_to_career'] === 1 ? 'yes' : 'no',
        $row['public_slug'] ?: $row['public_url_slug'],
        $row['career_slug'],
        $row['city'],
    ], $jobs)
);
file_put_contents($outDir . '/candidate-portal-and-online-tests.md', $candidateContent);

$counts = [];
foreach (['companies', 'roles', 'users', 'employees', 'candidate_accounts', 'candidates', 'job_postings', 'online_tests', 'online_test_assignments'] as $table) {
    $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

$readmeRows = [];
foreach ($roles as $role) {
    $file = 'role-' . slugFile($role['slug'] ?: 'no_role') . '.md';
    $readmeRows[] = [
        $role['slug'],
        $role['name'],
        $role['user_count'],
        $role['active_user_count'],
        "[{$file}]({$file})",
    ];
}

$readme = "# Debug Login Accounts\n\n";
$readme .= "Generated from DB `{$dbConfig['database']}` on " . date('Y-m-d H:i:s T') . ".\n\n";
$readme .= "This folder is for local trial/debug only. It contains verified seeded passwords where the DB hash matched a known seed password. If a password is marked `UNKNOWN - reset needed`, the plaintext cannot be recovered from the hash.\n\n";
$readme .= "Internal HRIS login accepts either `email` or `employee_number` as the `identifier`. Candidate portal login uses email and company `career_slug`.\n\n";
$readme .= "## DB Counts\n\n";
$readme .= table(['Table', 'Rows'], array_map(static fn ($k, $v): array => [$k, (string) $v], array_keys($counts), $counts));
$readme .= "\n## Password Hash Matches\n\n";
$readme .= table(['Password status', 'Users'], array_map(static fn ($k, $v): array => [$k, (string) $v], array_keys($passwordStats), $passwordStats));
$readme .= "\n## Role Files\n\n";
$readme .= table(['Role slug', 'Role name', 'Users', 'Active', 'File'], $readmeRows);
$readme .= "\n## Recruitment / Online Test\n\n";
$readme .= "- Candidate and online-test data: [candidate-portal-and-online-tests.md](candidate-portal-and-online-tests.md)\n";
file_put_contents($outDir . '/README.md', $readme);

echo "Generated login docs in {$outDir}\n";
