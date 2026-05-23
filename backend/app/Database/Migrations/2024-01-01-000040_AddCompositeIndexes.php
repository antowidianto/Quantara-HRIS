<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 10+ — composite indexes untuk query pattern yang paling sering.
 *
 * Index ini ditambah berdasar analisa controller utama:
 *
 * 1. **`attendances(company_id, work_date, employee_id)`**
 *    - `AttendanceController::index` filter by company + work_date range
 *    - `AttendanceController::my` filter by employee + work_date range
 *    - `PayrollService::calculatePeriod` per-employee per-period scan
 *    - `Reports/headcount`, `attendance-summary`, `attendance-heatmap`
 *
 * 2. **`leaves(company_id, employee_id, status, start_date)`**
 *    - `LeaveController::index` filter by company + status + date range
 *    - `LeaveBalanceService::summary` per-employee approved leaves
 *    - `Reports/leave-summary` per-period aggregation
 *
 * 3. **`overtimes(company_id, employee_id, status, work_date)`**
 *    - Pattern sama dengan leaves
 *
 * 4. **`audit_logs(company_id, created_at)`**
 *    - `AuditLogController::index` paginate by recency per tenant
 *    - `AuditLogController::stats` time-window aggregation
 *
 * 5. **`notifications(user_id, is_read, created_at)`**
 *    - `NotificationController::index` filter by unread + recency
 *    - `unreadCount` query count
 *
 * Idempotent — cek index existence sebelum buat (MySQL `INFORMATION_SCHEMA`,
 * SQLite `pragma_index_list`). Indexes ditambah dengan `ALTER TABLE ADD INDEX`
 * langsung tanpa drop existing (additive only).
 *
 * Catatan SQLite: composite index dibuat dengan SQL plain (Forge tidak punya
 * helper) — di-skip kalau driver bukan MySQL (di dev pakai SQLite, indexes
 * tidak signifikan untuk dataset kecil).
 */
class AddCompositeIndexes extends Migration
{
    /**
     * Definisi index: tabel → [index_name => [col1, col2, ...]]
     *
     * @var array<string, array<string, string[]>>
     */
    private array $indexes = [
        'attendances' => [
            'idx_att_company_date_emp'  => ['company_id', 'work_date', 'employee_id'],
        ],
        'leaves' => [
            'idx_leaves_scope_status'   => ['company_id', 'employee_id', 'status', 'start_date'],
        ],
        'overtimes' => [
            'idx_ot_scope_status'       => ['company_id', 'employee_id', 'status', 'work_date'],
        ],
        'audit_logs' => [
            'idx_audit_company_created' => ['company_id', 'created_at'],
        ],
        'notifications' => [
            'idx_notif_user_read'       => ['user_id', 'is_read', 'created_at'],
        ],
    ];

    public function up()
    {
        $driver = strtolower($this->db->getPlatform());
        if (! in_array($driver, ['mysqli', 'mysql'], true)) {
            // SQLite/Postgres — skip (dev env, indexes tidak signifikan untuk
            // dataset kecil, dan Forge::addKey API tidak konsisten lintas driver)
            log_message('info', 'AddCompositeIndexes: skip non-MySQL driver (' . $driver . ')');
            return;
        }

        foreach ($this->indexes as $table => $defs) {
            if (! $this->db->tableExists($table)) {
                log_message('info', "AddCompositeIndexes: table {$table} tidak ada, skip");
                continue;
            }
            foreach ($defs as $name => $cols) {
                if ($this->indexExists($table, $name)) continue;
                // Validate semua kolom ada
                $missing = array_filter($cols, fn ($c) => ! $this->db->fieldExists($c, $table));
                if (! empty($missing)) {
                    log_message('warning', "AddCompositeIndexes: kolom hilang di {$table}: " . implode(',', $missing));
                    continue;
                }
                $colList = implode(',', array_map(fn ($c) => "`{$c}`", $cols));
                $sql = "ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$colList})";
                $this->db->query($sql);
            }
        }
    }

    public function down()
    {
        $driver = strtolower($this->db->getPlatform());
        if (! in_array($driver, ['mysqli', 'mysql'], true)) return;

        foreach ($this->indexes as $table => $defs) {
            if (! $this->db->tableExists($table)) continue;
            foreach ($defs as $name => $cols) {
                if (! $this->indexExists($table, $name)) continue;
                $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    /**
     * Cek apakah index dengan nama tertentu sudah ada di tabel.
     * MySQL only.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = $this->db->database;
        $row = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$dbName, $table, $indexName]
        )->getRowArray();
        return (int) ($row['c'] ?? 0) > 0;
    }
}