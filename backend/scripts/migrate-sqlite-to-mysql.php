<?php
/**
 * migrate-sqlite-to-mysql.php
 * ==========================
 *
 * One-shot migrator untuk memindahkan data dari `writable/hris.sqlite`
 * ke database MySQL/MariaDB (Laragon). Dipakai sekali saat transisi
 * driver (lihat docs/MYSQL_MIGRATION.md).
 *
 * Workflow yang direkomendasikan:
 *   1. Backup `writable/hris.sqlite` dulu (rename / copy ke aman).
 *   2. Pastikan MySQL Laragon up, database kosong `hris_db` siap.
 *   3. Sementara, set .env database.default.* tetap MySQL & jalankan
 *      `php spark migrate` dulu — supaya skema MySQL terbentuk lengkap
 *      (kolom, FK, indeks, types yang sesuai). Tabel akan kosong.
 *   4. Run script ini dari root backend/. Script akan:
 *        - Membuka SQLite langsung pakai PDO sqlite (tidak via CI4).
 *        - Membuka MySQL via CI4 default group (sudah pakai .env baru).
 *        - Untuk tiap tabel di SQLite, copy semua row ke tabel MySQL
 *          dengan `INSERT IGNORE` (skip kalau PK conflict — biasa
 *          untuk lookup tables yang sudah ke-seed migrasi).
 *        - Refresh AUTO_INCREMENT MySQL ke MAX(id)+1 supaya insert
 *          baru tidak tabrakan.
 *   5. Verifikasi: `php scripts/show-counts.php` — angka sebaiknya
 *      sama di kedua DB.
 *
 * Catatan teknis:
 *   - SQLite tidak punya FK strict. MySQL strict. Karena kita import
 *     pakai urutan tabel yang sudah disusun (parents dulu), umumnya
 *     aman. Tapi kalau ada orphan row, script akan SET FOREIGN_KEY_CHECKS=0
 *     dulu lalu re-enable di akhir.
 *   - Tipe BOOLEAN/INT(1) di SQLite biasanya disimpan sebagai 0/1 — sudah
 *     kompatibel TINYINT(1) MySQL.
 *   - JSON disimpan TEXT di SQLite, MySQL menerima sebagai string JSON
 *     (kolom tipe TEXT). Kalau migrasi-nya pakai JSON column, MySQL akan
 *     parse otomatis.
 *   - Datetime ISO `YYYY-MM-DD HH:MM:SS` sudah cross-DB compatible.
 *
 * Penggunaan:
 *   php scripts/migrate-sqlite-to-mysql.php
 *   php scripts/migrate-sqlite-to-mysql.php --dry-run    (tampilkan plan)
 *   php scripts/migrate-sqlite-to-mysql.php --truncate   (kosongkan MySQL dulu)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
chdir(__DIR__ . '/..');
CodeIgniter\Boot::bootConsole($paths);

use Config\Database;

// ─── Config ──────────────────────────────────────────────────────────
$sqlitePath = __DIR__ . '/../writable/hris.sqlite';
$dryRun     = in_array('--dry-run', $argv ?? [], true);
$truncate   = in_array('--truncate', $argv ?? [], true);

if (! file_exists($sqlitePath)) {
    fwrite(STDERR, "[ERROR] SQLite tidak ditemukan: $sqlitePath\n");
    exit(1);
}

// Order penting: parents dulu, anak belakangan, supaya FK MySQL happy.
// Migrate_history selalu di-skip karena CI4 sudah menangani sendiri.
$tableOrder = [
    // Tenant root
    'companies', 'roles', 'permissions', 'role_permissions',
    'users', 'user_roles',

    // Master
    'branches', 'departments', 'positions', 'job_grades',
    'work_locations', 'shifts', 'shift_assignments', 'shift_patterns',
    'shift_pattern_assignments', 'leave_types', 'payroll_components',
    'allowance_types', 'allowance_rates', 'company_regulations',
    'contract_templates', 'task_templates', 'task_template_items',
    'organization_roles', 'organization_role_assignments',
    'organization_structures',

    // Approval chains
    'approval_chains', 'approval_chain_steps', 'approval_chain_assignments',
    'approval_delegations',

    // Employee + dependents
    'employees', 'employee_documents', 'employee_contracts',
    'employee_allowance_assignments', 'employee_work_locations',
    'employee_tasks',

    // Attendance
    'attendances', 'attendance_change_requests', 'attendance_devices',
    'roster',

    // Leave
    'leaves', 'leave_balances',

    // Payroll
    'overtimes', 'reimbursements', 'loans',
    'payroll_periods', 'payslips', 'payslip_items',
    'pph21_brackets', 'ptkp_amounts', 'ter_rates',

    // HR ops
    'disciplinary_categories', 'disciplinary_records',
    'severance_calculations',
    'business_trips', 'business_trip_expenses',
    'assets', 'asset_assignments',
    'trainings', 'training_registrations', 'training_budgets', 'certificates',
    'performance_cycles', 'goals', 'goal_check_ins',
    'performance_reviews', 'performance_kpis',
    'manpower_requests', 'manpower_request_steps',
    'job_information_changes', 'employee_transfers',
    'resignation_requests',

    // Recruitment / Career
    'job_postings', 'candidates', 'candidate_stages',
    'candidate_documents', 'candidate_attempts',
    'career_pages',

    // Helpdesk
    'helpdesk_tickets', 'helpdesk_ticket_comments',

    // Notifications + audit (paling akhir, sering banyak)
    'notifications', 'notification_preferences',
    'audit_logs',

    // System
    'settings', 'login_attempts', 'password_reset_tokens',
    'two_factor_secrets',
];

// ─── Connect ─────────────────────────────────────────────────────────
echo "=== Migrate SQLite → MySQL ===\n";
echo "Source : $sqlitePath\n";

$mysql = Database::connect();
$mysqlConn = $mysql->getDatabase();
echo "Target : MySQL `$mysqlConn` (driver: " . $mysql->getPlatform() . ")\n\n";

if (! str_contains(strtolower($mysql->getPlatform()), 'mysql')) {
    fwrite(STDERR, "[ERROR] Target bukan MySQL. Pastikan .env database.default.DBDriver = MySQLi.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $sqlitePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Discover semua tabel di SQLite, lalu reorder pakai $tableOrder
$tables = $pdo->query(
    "SELECT name FROM sqlite_master
       WHERE type='table'
         AND name NOT LIKE 'sqlite_%'
         AND name NOT LIKE 'migrations%'
       ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$ordered = [];
foreach ($tableOrder as $t) {
    if (in_array($t, $tables, true)) $ordered[] = $t;
}
foreach ($tables as $t) {
    if (! in_array($t, $ordered, true)) $ordered[] = $t; // sisanya, urutan apapun
}

// Buang tabel yang tidak ada di MySQL (skema ke-deprecate)
$ordered = array_filter($ordered, function ($t) use ($mysql) {
    return $mysql->tableExists($t);
});

if ($dryRun) {
    echo "[DRY-RUN] Akan memproses " . count($ordered) . " tabel:\n";
    foreach ($ordered as $t) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo sprintf("  - %-40s %6d row\n", $t, $count);
    }
    exit(0);
}

// ─── Apply ───────────────────────────────────────────────────────────
$mysql->query('SET FOREIGN_KEY_CHECKS=0');
$mysql->query('SET UNIQUE_CHECKS=0');

$totalCopied = 0;
$totalSkipped = 0;
$errors = [];

foreach ($ordered as $table) {
    try {
        // Optional truncate sebelum import
        if ($truncate) {
            $mysql->query("TRUNCATE TABLE `$table`");
        }

        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo sprintf("  · %-40s 0 row (skip)\n", $table);
            continue;
        }

        // Filter kolom: hanya kolom yang ada di MySQL (drop kolom yg sudah dibuang skema baru)
        $mysqlCols = $mysql->getFieldNames($table);
        $colMap = array_intersect(array_keys($rows[0]), $mysqlCols);

        $copied = 0;
        $skipped = 0;
        foreach (array_chunk($rows, 200) as $batch) {
            $values = [];
            foreach ($batch as $r) {
                $filtered = [];
                foreach ($colMap as $c) {
                    $filtered[$c] = $r[$c] ?? null;
                }
                $values[] = $filtered;
            }
            // INSERT IGNORE supaya konflik PK (mis. lookup di-seed migrasi) tidak fatal
            try {
                $builder = $mysql->table($table);
                $builder->ignore(true)->insertBatch($values);
                $copied += count($values);
            } catch (\Throwable $e) {
                // Coba per-row supaya tahu mana yang gagal
                foreach ($values as $v) {
                    try {
                        $mysql->table($table)->ignore(true)->insert($v);
                        $copied++;
                    } catch (\Throwable $e2) {
                        $skipped++;
                    }
                }
            }
        }

        // Reset AUTO_INCREMENT supaya next insert tidak tabrakan
        $maxIdRow = $mysql->query("SELECT MAX(id) AS m FROM `$table`")->getRowArray();
        if ($maxIdRow && isset($maxIdRow['m']) && $maxIdRow['m'] !== null) {
            $next = (int) $maxIdRow['m'] + 1;
            $mysql->query("ALTER TABLE `$table` AUTO_INCREMENT = $next");
        }

        echo sprintf("  ✓ %-40s %6d copied, %d skipped\n", $table, $copied, $skipped);
        $totalCopied  += $copied;
        $totalSkipped += $skipped;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        echo sprintf("  ✗ %-40s ERROR: %s\n", $table, substr($msg, 0, 120));
        $errors[$table] = $msg;
    }
}

$mysql->query('SET UNIQUE_CHECKS=1');
$mysql->query('SET FOREIGN_KEY_CHECKS=1');

// ─── Summary ────────────────────────────────────────────────────────
echo "\n=== Done ===\n";
echo "Total copied : $totalCopied\n";
echo "Total skipped: $totalSkipped\n";
if (! empty($errors)) {
    echo "Errors (" . count($errors) . " tabel):\n";
    foreach ($errors as $t => $m) {
        echo "  - $t: " . substr($m, 0, 200) . "\n";
    }
    exit(2);
}

echo "\nVerifikasi count: php scripts/show-counts.php\n";
exit(0);