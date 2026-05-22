<?php
/**
 * _run-migrate-mysql.php — internal helper
 * =========================================
 *
 * Wrapper untuk `php spark migrate` yang menyiasati keterbatasan migrasi
 * lama di repo: beberapa migrasi lama (000010, 000012, dst.) menyisipkan
 * row `role_permissions` ke role_id 1/2 — padahal role itu baru ke-create
 * saat seeder `InitialSeeder` dijalankan. SQLite permissive (FK off by
 * default), MySQL strict.
 *
 * Solusi minimal-invasif: matikan foreign_key_checks di SESI MySQL yang
 * sama dengan yang dipakai migrator. Karena CI4 buka DB-nya sendiri saat
 * migrator jalan, kita perlu set lewat hook `Events::on('post_migration')`
 * atau lebih simple: jalankan SET via raw query lalu invoke MigrationRunner
 * langsung — semua dalam satu PHP process.
 *
 * Penggunaan: dipanggil oleh scripts/migrate-to-mysql.cmd, tidak perlu
 * di-trigger manual.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
chdir(__DIR__ . '/..');
CodeIgniter\Boot::bootConsole($paths);

use Config\Database;
use CodeIgniter\Database\MigrationRunner;

$db = Database::connect();
echo "Driver: " . $db->getPlatform() . "\n";

if (str_contains(strtolower($db->getPlatform()), 'mysql')) {
    // Kunci: matikan FK checks di sesi yang SAMA. CI4 reuse koneksi
    // singleton, jadi MigrationRunner di bawah pakai sesi ini juga.
    $db->query('SET foreign_key_checks = 0');
    echo "FK checks: OFF (session)\n";
}

$runner = new MigrationRunner(config('Migrations'));
$runner->setNamespace('App');

try {
    echo "Running migrations...\n";
    $runner->latest();
    echo "Migrations complete.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Migrate failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

if (str_contains(strtolower($db->getPlatform()), 'mysql')) {
    $db->query('SET foreign_key_checks = 1');
    echo "FK checks: ON (restored)\n";
}

exit(0);