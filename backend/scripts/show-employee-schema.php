<?php
/**
 * Show employee table schema for crafting bulk seed.
 */
$dbPath = __DIR__ . '/../writable/hris.sqlite';
$db = new SQLite3($dbPath);

$res = $db->query("PRAGMA table_info(employees)");
echo "=== employees columns ===\n";
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    printf("  %-30s %-15s null=%s default=%s\n",
        $r['name'], $r['type'], $r['notnull'] ? 'NO' : 'YES',
        $r['dflt_value'] ?? '-');
}

echo "\n=== Sample employee row ===\n";
$row = $db->query("SELECT * FROM employees LIMIT 1")->fetchArray(SQLITE3_ASSOC);
if ($row) {
    foreach ($row as $k => $v) {
        $disp = is_string($v) && strlen($v) > 60 ? substr($v, 0, 60) . '...' : $v;
        printf("  %-30s = %s\n", $k, $disp ?? 'NULL');
    }
}