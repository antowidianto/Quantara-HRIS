<?php
/**
 * show-companies.php — quick lookup of existing companies, branches, departments,
 * positions, and employees. Direct SQLite3 (no CI bootstrap) supaya simpel.
 */
$dbPath = __DIR__ . '/../writable/hris.sqlite';
$db = new SQLite3($dbPath);

echo "\n=== Companies ===\n";
$res = $db->query("SELECT id, code, name, city FROM companies");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  %-10s %s — %s\n", $r['id'], $r['code'], $r['name'], $r['city'] ?? '-');
}

echo "\n=== Branches ===\n";
$res = $db->query("
    SELECT b.id, b.code, b.name, b.city, c.name AS company
    FROM branches b LEFT JOIN companies c ON c.id = b.company_id
    ORDER BY b.company_id, b.id
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  [%s] %s — %s | %s\n", $r['id'], $r['code'], $r['name'], $r['city'] ?? '-', $r['company']);
}

echo "\n=== Departments ===\n";
$res = $db->query("
    SELECT d.id, d.name, c.name AS company
    FROM departments d LEFT JOIN companies c ON c.id = d.company_id
    ORDER BY d.company_id, d.id
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  %-30s — %s\n", $r['id'], $r['name'], $r['company']);
}

echo "\n=== Positions ===\n";
$res = $db->query("
    SELECT p.id, p.name, c.name AS company
    FROM positions p LEFT JOIN companies c ON c.id = p.company_id
    ORDER BY p.company_id, p.id
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  %-30s — %s\n", $r['id'], $r['name'], $r['company']);
}

echo "\n=== Job Grades ===\n";
$res = $db->query("
    SELECT jg.id, jg.code, jg.name, c.name AS company
    FROM job_grades jg LEFT JOIN companies c ON c.id = jg.company_id
    ORDER BY jg.company_id, jg.id
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  [%s] %s — %s\n", $r['id'], $r['code'], $r['name'], $r['company']);
}

echo "\n=== Employee count per company ===\n";
$res = $db->query("
    SELECT c.id, c.name, COUNT(e.id) AS total
    FROM companies c
    LEFT JOIN employees e ON e.company_id = c.id AND e.deleted_at IS NULL
    GROUP BY c.id, c.name
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  %-50s  %d karyawan\n", $r['id'], $r['name'], $r['total']);
}

echo "\n=== Users ===\n";
$res = $db->query("
    SELECT u.id, u.email, u.name, c.name AS company, r.name AS role
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    LEFT JOIN roles r ON r.id = u.role_id
    ORDER BY u.id
");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  #%d  %-35s %-25s %-15s %s\n", $r['id'], $r['email'], $r['name'], $r['role'] ?? '-', $r['company'] ?? 'global');
}
echo "\n";