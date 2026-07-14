<?php

declare(strict_types=1);

/**
 * Apply every application migration to a fresh in-memory SQLite database.
 *
 * This deliberately boots the CodeIgniter test environment instead of Spark:
 * Spark loads the repository .env (MySQL) before per-command overrides can be
 * relied on, while this gate must never touch a developer or production DB.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use CodeIgniter\Config\Services;
use Config\Database;

function validateTenantTableClassification($db, array $nonTenantTables, bool $mysql): void
{
    $classificationPath = ROOTPATH . 'security/tenant-table-classification.php';
    $classification = is_file($classificationPath) ? require $classificationPath : [];
    $classified = array_values(array_unique(array_merge(
        array_keys($classification['global'] ?? []),
        array_keys($classification['tenant_via_parent'] ?? []),
    )));
    sort($classified);
    sort($nonTenantTables);
    $missing = array_values(array_diff($nonTenantTables, $classified));
    $stale = array_values(array_diff($classified, $nonTenantTables));
    if ($missing !== [] || $stale !== []) {
        throw new RuntimeException(sprintf(
            'Tenant table classification drift. Missing: [%s]; stale: [%s].',
            implode(', ', $missing),
            implode(', ', $stale),
        ));
    }

    foreach (($classification['tenant_via_parent'] ?? []) as $table => $boundary) {
        $parent = (string) ($boundary['parent'] ?? '');
        if ($parent === '' || ! $db->tableExists($parent)) {
            throw new RuntimeException("Invalid tenant parent classification for {$table}: {$parent}");
        }
        if (($boundary['foreign_key'] ?? false) !== true) {
            continue;
        }
        if ($mysql) {
            $exists = (int) $db->query(
                'SELECT COUNT(*) AS aggregate FROM information_schema.key_column_usage'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_name = ?',
                [$table, $parent],
            )->getRowArray()['aggregate'];
            if ($exists === 0) {
                throw new RuntimeException("Missing declared tenant parent FK: {$table} -> {$parent}");
            }
            continue;
        }
        $quotedTable = str_replace('"', '""', (string) $table);
        $parents = array_column(
            $db->query('PRAGMA foreign_key_list("' . $quotedTable . '")')->getResultArray(),
            'table',
        );
        if (! in_array($parent, $parents, true)) {
            throw new RuntimeException("Missing declared tenant parent FK: {$table} -> {$parent}");
        }
    }
}

function validateTenantUniqueClassification(array $uniqueKeys): void
{
    $path = ROOTPATH . 'security/tenant-unique-classification.php';
    $classified = is_file($path) ? array_keys(require $path) : [];
    $uniqueKeys = array_values(array_unique($uniqueKeys));
    sort($uniqueKeys);
    sort($classified);
    $missing = array_values(array_diff($uniqueKeys, $classified));
    $stale = array_values(array_diff($classified, $uniqueKeys));
    if ($missing !== [] || $stale !== []) {
        throw new RuntimeException(sprintf(
            'Tenant unique-constraint classification drift. Missing: [%s]; stale: [%s].',
            implode(', ', $missing),
            implode(', ', $stale),
        ));
    }
}

$databaseConfig = config(Database::class);
$mysql = in_array('--mysql', $argv, true);
$inventory = in_array('--inventory-non-tenant', $argv, true);
$inventoryUnique = in_array('--inventory-tenant-unique', $argv, true);
if ($mysql) {
    $databaseConfig->tests = array_replace($databaseConfig->tests, [
        'hostname' => getenv('MIGRATION_DB_HOST') ?: '127.0.0.1',
        'username' => getenv('MIGRATION_DB_USER') ?: 'root',
        'password' => getenv('MIGRATION_DB_PASSWORD') ?: '',
        'database' => getenv('MIGRATION_DB_NAME') ?: 'hris_migration',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'port' => (int) (getenv('MIGRATION_DB_PORT') ?: 3306),
        'charset' => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'strictOn' => true,
        'foreignKeys' => true,
    ]);
} else {
    // Foreign-key references in historical migrations use logical table
    // names, so the test-only `db_` prefix must not be used here.
    $databaseConfig->tests['DBPrefix'] = '';
}
// Keep this connection shared: several legacy migrations call db_connect()
// instead of using the Forge connection injected by MigrationRunner.
$db = Database::connect('tests');

try {
    if (! $mysql) {
        $db->query('PRAGMA foreign_keys = ON');
    }

    $runner = Services::migrations(null, $db, false);
    $runner->setNamespace('App');

    $available = $runner->findMigrations();
    if ($available === []) {
        throw new RuntimeException('No application migrations were discovered.');
    }

    if (! $runner->latest()) {
        throw new RuntimeException('Migration runner did not complete successfully.');
    }

    $history = $runner->getHistory('tests');
    if (count($history) !== count($available)) {
        throw new RuntimeException(sprintf(
            'Applied migration count mismatch: expected %d, got %d.',
            count($available),
            count($history)
        ));
    }

    if ($mysql) {
        $foreignKeyCount = (int) $db->query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()'
        )->getRowArray()['aggregate'];
        $indexCount = (int) $db->query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE()'
        )->getRowArray()['aggregate'];
        if ($foreignKeyCount === 0 || $indexCount === 0) {
            throw new RuntimeException('MySQL schema is missing expected foreign keys or indexes.');
        }
        $tenantTables = $db->query(
            "SELECT DISTINCT c.table_name
             FROM information_schema.columns c
             WHERE c.table_schema = DATABASE() AND c.column_name = 'company_id'"
        )->getResultArray();
        $unindexedTenantTables = [];
        foreach ($tenantTables as $tenantTable) {
            $table = (string) $tenantTable['table_name'];
            $indexed = (int) $db->query(
                "SELECT COUNT(*) AS aggregate
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'company_id'",
                [$table]
            )->getRowArray()['aggregate'];
            if ($indexed === 0) {
                $unindexedTenantTables[] = $table;
            }
        }
        if ($unindexedTenantTables !== []) {
            throw new RuntimeException(
                'Tenant tables without a company_id index: ' . implode(', ', $unindexedTenantTables)
            );
        }
        $nonTenantTables = array_column($db->query(
            "SELECT t.table_name
             FROM information_schema.tables t
             WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
               AND NOT EXISTS (
                   SELECT 1 FROM information_schema.columns c
                   WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name
                     AND c.column_name = 'company_id'
               )"
        )->getResultArray(), 'table_name');
        validateTenantTableClassification($db, $nonTenantTables, true);
        $uniqueRows = $db->query(
            "SELECT s.table_name, s.index_name,
                    GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index SEPARATOR ',') AS columns_list
             FROM information_schema.statistics s
             JOIN information_schema.columns c
               ON c.table_schema = s.table_schema AND c.table_name = s.table_name AND c.column_name = 'company_id'
             WHERE s.table_schema = DATABASE() AND s.non_unique = 0 AND s.index_name <> 'PRIMARY'
             GROUP BY s.table_name, s.index_name
             HAVING SUM(s.column_name = 'company_id') = 0"
        )->getResultArray();
        $tenantUniqueWithoutCompany = array_map(
            static fn (array $row): string => $row['table_name'] . ':' . $row['columns_list'],
            $uniqueRows,
        );
        validateTenantUniqueClassification($tenantUniqueWithoutCompany);
        fwrite(STDOUT, sprintf(
            "Fresh MySQL migration check passed: %d migrations; %d foreign keys; %d index entries; %d tenant tables indexed; %d non-tenant tables classified.\n",
            count($history),
            $foreignKeyCount,
            $indexCount,
            count($tenantTables),
            count($nonTenantTables)
        ));
    } else {
        $foreignKeys = (int) $db->query('PRAGMA foreign_keys')->getRowArray()['foreign_keys'];
        $foreignKeyViolations = $db->query('PRAGMA foreign_key_check')->getResultArray();
        $indexCount = (int) $db->query(
            "SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'index'"
        )->getRowArray()['aggregate'];
        $integrity = (string) array_values($db->query('PRAGMA integrity_check')->getRowArray())[0];
        if ($foreignKeys !== 1 || $foreignKeyViolations !== [] || $indexCount === 0 || strtolower($integrity) !== 'ok') {
            throw new RuntimeException('SQLite foreign-key/index/integrity verification failed.');
        }
        $tenantTableCount = 0;
        $unindexedTenantTables = [];
        $nonTenantTables = [];
        $tenantUniqueWithoutCompany = [];
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        )->getResultArray();
        foreach ($tables as $tableRow) {
            $table = (string) $tableRow['name'];
            $quotedTable = str_replace('"', '""', $table);
            $columns = $db->query('PRAGMA table_info("' . $quotedTable . '")')->getResultArray();
            if (! in_array('company_id', array_column($columns, 'name'), true)) {
                $nonTenantTables[] = $table;
                continue;
            }
            $tenantTableCount++;
            $indexed = false;
            foreach ($db->query('PRAGMA index_list("' . $quotedTable . '")')->getResultArray() as $index) {
                $indexName = str_replace('"', '""', (string) $index['name']);
                $indexColumns = $db->query('PRAGMA index_info("' . $indexName . '")')->getResultArray();
                $columnNames = array_column($indexColumns, 'name');
                if (in_array('company_id', $columnNames, true)) {
                    $indexed = true;
                } elseif ((int) ($index['unique'] ?? 0) === 1) {
                    $tenantUniqueWithoutCompany[] = $table . ':' . implode(',', $columnNames);
                }
            }
            if (! $indexed) {
                $unindexedTenantTables[] = $table;
            }
        }
        if ($unindexedTenantTables !== []) {
            throw new RuntimeException(
                'Tenant tables without a company_id index: ' . implode(', ', $unindexedTenantTables)
            );
        }
        validateTenantTableClassification($db, $nonTenantTables, false);
        validateTenantUniqueClassification($tenantUniqueWithoutCompany);
        if ($inventory) {
            sort($nonTenantTables);
            fwrite(STDOUT, "Tables without company_id:\n");
            foreach ($nonTenantTables as $table) {
                $quotedTable = str_replace('"', '""', $table);
                $parents = array_values(array_unique(array_column(
                    $db->query('PRAGMA foreign_key_list("' . $quotedTable . '")')->getResultArray(),
                    'table',
                )));
                fwrite(STDOUT, ' - ' . $table . ($parents !== [] ? ' -> ' . implode(', ', $parents) : '') . "\n");
            }
        }
        if ($inventoryUnique) {
            sort($tenantUniqueWithoutCompany);
            fwrite(STDOUT, "Tenant-table unique indexes without company_id:\n - "
                . implode("\n - ", $tenantUniqueWithoutCompany) . "\n");
        }
        fwrite(STDOUT, sprintf(
            "Fresh SQLite migration check passed: %d migrations; %d indexes; %d tenant tables indexed; %d non-tenant tables classified; foreign keys and integrity OK.\n",
            count($history),
            $indexCount,
            $tenantTableCount,
            count($nonTenantTables)
        ));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "Fresh SQLite migration check failed: %s at %s:%d\n%s\n",
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));
    exit(1);
} finally {
    $db->close();
}
