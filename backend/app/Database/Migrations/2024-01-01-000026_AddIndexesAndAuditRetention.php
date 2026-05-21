<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 2 — composite indexes (item J) + audit retention (item N).
 *
 * Composite indexes mempercepat 5 query terberat:
 *   - MyAttendance calendar          → attendances(employee_id, work_date)
 *   - Leave team-calendar            → leaves(status, start_date)
 *   - Notification unread badge      → notifications(user_id, is_read)
 *   - AuditLogs filter               → audit_logs(module, created_at)
 *   - Performance dashboard          → goals(cycle_id, employee_id)
 *
 * Audit retention pakai kolom `archived_at` — nilai NULL = aktif, NOT NULL =
 * sudah di-archive (data tetap di-tabel sama tapi di-exclude default queries).
 *
 * Idempotent: cek existing index lewat `getIndexData()` sebelum CREATE.
 *
 * Lihat docs/AUDIT.md item J & N.
 */
class AddIndexesAndAuditRetention extends Migration
{
    public function up()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        // ─── J. Composite indexes ───────────────────────────────────
        $indexes = [
            ['attendances',  'idx_attendances_emp_date',  ['employee_id', 'work_date']],
            ['leaves',       'idx_leaves_status_start',   ['status', 'start_date']],
            ['notifications','idx_notif_user_read',       ['user_id', 'is_read']],
            ['audit_logs',   'idx_audit_module_date',     ['module', 'created_at']],
            ['goals',        'idx_goals_cycle_emp',       ['cycle_id', 'employee_id']],
        ];

        foreach ($indexes as [$table, $name, $cols]) {
            if (! $db->tableExists($table)) {
                continue;
            }
            if ($this->indexExists($db, $table, $name)) {
                continue;
            }
            // CodeIgniter forge tidak support CREATE INDEX langsung; pakai raw.
            $colList = implode(',', array_map(fn ($c) => "`{$c}`", $cols));
            $db->query("CREATE INDEX `{$name}` ON `{$table}` ({$colList})");
        }

        // ─── N. Audit retention — kolom archived_at ─────────────────
        if ($db->tableExists('audit_logs')) {
            $fields = $db->getFieldNames('audit_logs');
            if (! in_array('archived_at', $fields, true)) {
                $forge->addColumn('audit_logs', [
                    'archived_at' => [
                        'type'    => 'DATETIME',
                        'null'    => true,
                        'default' => null,
                        'after'   => 'created_at',
                        'comment' => 'Waktu archive (NULL = aktif). Diisi oleh hr:archive-audit.',
                    ],
                ]);
            }
            // Index untuk query "WHERE archived_at IS NULL" supaya cepat
            if (! $this->indexExists($db, 'audit_logs', 'idx_audit_archived')) {
                $db->query("CREATE INDEX `idx_audit_archived` ON `audit_logs` (`archived_at`)");
            }
        }
    }

    public function down()
    {
        $forge = \Config\Database::forge();
        $db    = \Config\Database::connect();

        $indexes = [
            ['attendances',  'idx_attendances_emp_date'],
            ['leaves',       'idx_leaves_status_start'],
            ['notifications','idx_notif_user_read'],
            ['audit_logs',   'idx_audit_module_date'],
            ['audit_logs',   'idx_audit_archived'],
            ['goals',        'idx_goals_cycle_emp'],
        ];
        foreach ($indexes as [$table, $name]) {
            if (! $db->tableExists($table)) continue;
            if ($this->indexExists($db, $table, $name)) {
                $db->query("DROP INDEX `{$name}` ON `{$table}`");
            }
        }

        if ($db->tableExists('audit_logs')) {
            $fields = $db->getFieldNames('audit_logs');
            if (in_array('archived_at', $fields, true)) {
                $forge->dropColumn('audit_logs', 'archived_at');
            }
        }
    }

    private function indexExists(\CodeIgniter\Database\BaseConnection $db, string $table, string $indexName): bool
    {
        $indexes = $db->getIndexData($table);
        foreach ($indexes as $idx) {
            if ($idx->name === $indexName) {
                return true;
            }
        }
        return false;
    }
}