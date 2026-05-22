<?php
/**
 * fix-org-assignments.php — assign penjabat tambahan ke org_structures
 * untuk role_key yang belum di-handle script regenerate (CEO, DIRECTOR_*, GM_*, OPS_MANAGER, dll.).
 *
 * Map dari position name di tabel positions ke role_key di organization_structures.
 */

$db = new SQLite3(__DIR__ . '/../writable/hris.sqlite');
$db->enableExceptions(true);

$now = date('Y-m-d H:i:s');

/**
 * Cari employee by company_id + position name pattern.
 */
function findByPosition(SQLite3 $db, int $companyId, array $patterns): ?int {
    foreach ($patterns as $pattern) {
        $row = $db->querySingle(
            "SELECT e.id FROM employees e
             JOIN positions p ON p.id = e.position_id
             WHERE e.company_id = $companyId
               AND p.name LIKE '%" . SQLite3::escapeString($pattern) . "%'
             ORDER BY e.id LIMIT 1", true);
        if ($row && isset($row['id'])) return (int) $row['id'];
    }
    return null;
}

// Map role_key → list patterns untuk lookup employee
$mapping = [
    'CEO'              => ['Presiden Direktur'],
    'DIRECTOR_FINANCE' => ['Direktur Finance'],
    'DIRECTOR_HR'      => ['Direktur HR'],
    'DIRECTOR_OPS'     => ['Direktur Operations', 'Direktur Operasional'],
    'GM_FINANCE'       => ['Direktur Finance', 'Finance Manager'],
    'GM_HR'            => ['Direktur HR', 'HR Manager'],
    'OPS_MANAGER'      => ['Operations Manager', 'Direktur Operations', 'Direktur Operasional'],
    'PAYROLL_LEAD'     => ['Payroll Manager', 'HR Manager'],
    'TALENT_ACQ_LEAD'  => ['Recruitment Manager', 'HR Manager'],
    'PEOPLE_DEV_LEAD'  => ['Training Manager', 'HR Manager'],
    'COMP_BEN_LEAD'    => ['Payroll Manager', 'HR Manager'],
    'HR_OPS_LEAD'      => ['HR Manager'],
    'TAX_LEAD'         => ['Finance Manager', 'Accounting Manager'],
];

echo "Filling vacant organization_structures roles...\n\n";

$res = $db->query("
    SELECT id, company_id, role_key
    FROM organization_structures
    WHERE employee_id IS NULL
    ORDER BY company_id, role_key
");

$filled = 0;
$stillVacant = 0;
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $roleKey = $r['role_key'];
    $companyId = (int) $r['company_id'];
    if (! isset($mapping[$roleKey])) {
        echo "  · [{$companyId}] $roleKey — no mapping, skip\n";
        $stillVacant++;
        continue;
    }
    $empId = findByPosition($db, $companyId, $mapping[$roleKey]);
    if ($empId === null) {
        echo "  · [{$companyId}] $roleKey — no matching employee\n";
        $stillVacant++;
        continue;
    }

    $db->exec("UPDATE organization_structures
        SET employee_id = $empId, updated_at = '$now'
        WHERE id = " . (int) $r['id']);

    $name = $db->querySingle("SELECT full_name FROM employees WHERE id = $empId");
    echo "  ✓ [{$companyId}] $roleKey → $name\n";
    $filled++;
}

echo "\nDone. Filled: $filled, still vacant: $stillVacant\n";