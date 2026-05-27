<?php

/**
 * Quick verifier: count question bank + tests setelah seeder dijalankan.
 * Jalankan: php scripts/verify-online-tests.php
 */

$envFile = __DIR__ . '/../.env';
$config  = ['hostname' => '127.0.0.1', 'database' => 'hris_db', 'username' => 'root', 'password' => ''];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (! preg_match('/^database\.default\.(hostname|database|username|password)\s*=\s*(.*)$/', $line, $m)) continue;
        $config[$m[1]] = trim($m[2], " '\"");
    }
}
$pdo = new PDO(
    "mysql:host={$config['hostname']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password']
);

echo "\n=== Online Test Bank — Verification ===\n\n";

echo "Row counts:\n";
foreach (['online_test_question_bank', 'online_tests', 'online_test_questions'] as $t) {
    $r = $pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch(PDO::FETCH_ASSOC);
    echo '  ' . str_pad($t, 30) . ' = ' . $r['c'] . "\n";
}

echo "\nQuestion bank per category:\n";
$sql = "SELECT category, difficulty, COUNT(*) AS c
        FROM online_test_question_bank
        WHERE deleted_at IS NULL AND is_active = 1
        GROUP BY category, difficulty
        ORDER BY category, difficulty";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-15s %-8s = %d\n", $r['category'], $r['difficulty'], (int) $r['c']);
}

echo "\nTotal soal aktif:\n";
$r = $pdo->query("SELECT COUNT(*) c FROM online_test_question_bank WHERE is_active=1 AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
echo "  " . $r['c'] . " soal\n";

echo "\nTests yang tersedia:\n";
$sql = "SELECT t.code, t.name, t.duration_minutes, t.passing_score_pct, t.use_pool,
            (SELECT COUNT(*) FROM online_test_questions q WHERE q.online_test_id = t.id) AS attached
        FROM online_tests t
        WHERE t.deleted_at IS NULL
        ORDER BY t.id";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-22s %-50s %3d min  pass=%2d%%  pool=%d  attached=%d\n",
        $r['code'], substr($r['name'], 0, 50),
        (int) $r['duration_minutes'], (int) $r['passing_score_pct'],
        (int) $r['use_pool'], (int) $r['attached']);
}

echo "\n";