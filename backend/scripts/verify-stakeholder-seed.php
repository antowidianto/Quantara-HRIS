<?php

/**
 * Quick verification: count rows + spot-check reporting tree.
 * Jalankan: php scripts/verify-stakeholder-seed.php
 */

// Parse .env manually — kita hanya butuh kredensial DB.
$envFile = __DIR__ . '/../.env';
$config  = ['hostname' => '127.0.0.1', 'database' => 'hris_db', 'username' => 'root', 'password' => ''];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (! preg_match('/^database\.default\.(hostname|database|username|password)\s*=\s*(.*)$/', $line, $m)) continue;
        $val = trim($m[2], " '\"");
        $config[$m[1]] = $val;
    }
}
$host = $config['hostname'];
$db   = $config['database'];
$user = $config['username'];
$pass = $config['password'];

$pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass);

echo "\n=== Row counts ===\n";
foreach ([
    'companies', 'branches', 'departments', 'positions', 'job_grades',
    'users', 'employees', 'employee_contracts',
    'candidates', 'candidate_accounts', 'candidate_stages',
    'job_postings', 'online_test_assignments', 'online_test_attempts',
    'shifts', 'leave_types', 'payroll_components', 'bpjs_rates',
] as $t) {
    $row = $pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch(PDO::FETCH_ASSOC);
    echo str_pad($t, 28) . ' = ' . str_pad((string)$row['c'], 5, ' ', STR_PAD_LEFT) . "\n";
}

echo "\n=== Top 12 stakeholders + atasan ===\n";
$sql = "SELECT
            e.employee_number AS num,
            e.full_name AS name,
            p.name AS position,
            d.name AS department,
            jg.code AS grade,
            mgr.full_name AS manager_name,
            u.email AS email,
            r.slug AS role
        FROM employees e
        LEFT JOIN positions p ON p.id = e.position_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN job_grades jg ON jg.id = e.job_grade_id
        LEFT JOIN employees mgr ON mgr.id = e.manager_id
        LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY jg.level DESC, e.employee_number
        LIMIT 12";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-9s %-22s %-45s grade=%-3s atasan=%-22s role=%s\n",
        $r['num'], $r['name'], substr($r['position'], 0, 45),
        $r['grade'], (string) ($r['manager_name'] ?? '—'), $r['role']);
}

echo "\n=== Direct reports per Direksi ===\n";
$sql = "SELECT mgr.employee_number, mgr.full_name AS dir, COUNT(sub.id) AS subordinates
        FROM employees mgr
        LEFT JOIN employees sub ON sub.manager_id = mgr.id
        WHERE mgr.position_id IN (
            SELECT id FROM positions WHERE name LIKE 'Direktur%' OR name LIKE 'Komisaris%'
        )
        GROUP BY mgr.id
        ORDER BY mgr.employee_number";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-9s %-30s subordinates = %d\n",
        $r['employee_number'], $r['dir'], (int) $r['subordinates']);
}

echo "\n=== Department heads ===\n";
$sql = "SELECT d.code, d.name AS department, e.full_name AS head, p.name AS title
        FROM departments d
        LEFT JOIN employees e ON e.id = d.manager_employee_id
        LEFT JOIN positions p ON p.id = e.position_id
        WHERE d.parent_id IS NULL
        ORDER BY d.code";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-6s %-40s head=%s (%s)\n",
        $r['code'], $r['department'], (string) ($r['head'] ?? '—'),
        (string) ($r['title'] ?? '—'));
}

echo "\n";