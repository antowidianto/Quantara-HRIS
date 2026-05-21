<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: enhance audit_logs + Indonesian-specific master tables.
 *
 *  - audit_logs: tambahan kolom severity, module, description, changes,
 *    source, request_id, success, error_code, duration_ms.
 *  - regional_minimum_wages: tabel UMP/UMK referensi per provinsi/kota.
 *  - id_holidays: dipastikan ada (sudah ada di migrasi 003 sebagai `holidays`),
 *    di sini hanya menambah index yang berguna.
 */
class EnhanceAuditAndIndonesianMasters extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── ENHANCE audit_logs ─────────────────────────────────────────
        // Tambah kolom hanya jika belum ada (idempotent).
        $existingCols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('audit_logs'));

        $additions = [];
        if (! in_array('severity', $existingCols, true)) {
            $additions['severity'] = [
                'type'       => 'ENUM',
                'constraint' => ['DEBUG','INFO','NOTICE','WARNING','CRITICAL'],
                'default'    => 'INFO',
                'after'      => 'action',
            ];
        }
        if (! in_array('module', $existingCols, true)) {
            $additions['module'] = [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => 'AUTH/EMPLOYEE/LEAVE/OVERTIME/PAYROLL/MASTER/SETTING/...',
                'after'      => 'severity',
            ];
        }
        if (! in_array('description', $existingCols, true)) {
            $additions['description'] = [
                'type'    => 'VARCHAR',
                'constraint' => 500,
                'null'    => true,
                'comment' => 'Deskripsi naratif Bahasa Indonesia',
                'after'   => 'module',
            ];
        }
        if (! in_array('changes', $existingCols, true)) {
            $additions['changes'] = [
                'type'    => 'JSON',
                'null'    => true,
                'comment' => 'Diff hanya field yg berubah: {field: {from, to}}',
                'after'   => 'after_data',
            ];
        }
        if (! in_array('source', $existingCols, true)) {
            $additions['source'] = [
                'type'       => 'ENUM',
                'constraint' => ['WEB','MOBILE','API','CRON','SYSTEM','IMPORT'],
                'default'    => 'WEB',
                'after'      => 'user_agent',
            ];
        }
        if (! in_array('request_id', $existingCols, true)) {
            $additions['request_id'] = [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
                'comment'    => 'UUID korelasi multi-step',
                'after'      => 'source',
            ];
        }
        if (! in_array('http_method', $existingCols, true)) {
            $additions['http_method'] = [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'request_id',
            ];
        }
        if (! in_array('http_path', $existingCols, true)) {
            $additions['http_path'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'http_method',
            ];
        }
        if (! in_array('success', $existingCols, true)) {
            $additions['success'] = [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 1,
                'comment'    => '1=success, 0=failure',
                'after'      => 'status_code',
            ];
        }
        if (! in_array('error_code', $existingCols, true)) {
            $additions['error_code'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'success',
            ];
        }
        if (! in_array('duration_ms', $existingCols, true)) {
            $additions['duration_ms'] = [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'error_code',
            ];
        }

        if (! empty($additions)) {
            $forge->addColumn('audit_logs', $additions);
        }

        // Index baru untuk query yang sering dipakai (idempotent + DB-agnostic)
        $this->createIndexIfMissing($db, 'audit_logs', 'audit_logs_module_created_idx', ['module', 'created_at']);
        $this->createIndexIfMissing($db, 'audit_logs', 'audit_logs_severity_idx', ['severity']);
        $this->createIndexIfMissing($db, 'audit_logs', 'audit_logs_request_id_idx', ['request_id']);
        $this->createIndexIfMissing($db, 'audit_logs', 'audit_logs_success_idx', ['success']);

        // ─── REGIONAL MINIMUM WAGES (UMP/UMK) ───────────────────────────
        if (! $db->tableExists('regional_minimum_wages')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'province'       => ['type' => 'VARCHAR', 'constraint' => 100],
                'city'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'comment' => 'NULL = UMP provinsi'],
                'level'          => ['type' => 'ENUM', 'constraint' => ['UMP','UMK'], 'default' => 'UMP'],
                'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'effective_year' => ['type' => 'YEAR'],
                'reference'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'SK Gubernur/Kepmenaker'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['province', 'city', 'level', 'effective_year']);
            $forge->addKey('effective_year');
            $forge->createTable('regional_minimum_wages');
        }

        // ─── DOCUMENT EXPIRY TRACKING (untuk reminder otomatis) ────────
        if ($db->tableExists('employee_documents')) {
            $this->createIndexIfMissing($db, 'employee_documents', 'employee_documents_expires_idx', ['expires_at']);
        }

        // ─── CONTRACT EXPIRY INDEX ──────────────────────────────────────
        if ($db->tableExists('employees')) {
            $this->createIndexIfMissing($db, 'employees', 'employees_contract_end_idx', ['contract_end_date']);
            $this->createIndexIfMissing($db, 'employees', 'employees_probation_end_idx', ['probation_end_date']);
        }
    }

    /**
     * Buat index kalau belum ada. Bekerja di MySQL & SQLite.
     */
    private function createIndexIfMissing($db, string $table, string $indexName, array $columns): void
    {
        try {
            $driver = strtolower($db->getPlatform());
            if (str_contains($driver, 'sqlite')) {
                $rows = $db->query("PRAGMA index_list('{$table}')")->getResultArray();
                foreach ($rows as $r) {
                    if (strtolower($r['name']) === strtolower($indexName)) return;
                }
            } else {
                // MySQL / MariaDB
                $rows = $db->query("SHOW INDEX FROM `{$table}`")->getResultArray();
                foreach ($rows as $r) {
                    if (strtolower($r['Key_name']) === strtolower($indexName)) return;
                }
            }
            $cols = '`' . implode('`, `', $columns) . '`';
            $db->query("CREATE INDEX {$indexName} ON {$table} ({$cols})");
        } catch (\Throwable $e) {
            log_message('warning', "createIndexIfMissing({$indexName}): " . $e->getMessage());
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if ($db->tableExists('regional_minimum_wages')) {
            $forge->dropTable('regional_minimum_wages', true);
        }

        $existingCols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('audit_logs'));
        foreach (['severity','module','description','changes','source','request_id',
                  'http_method','http_path','success','error_code','duration_ms'] as $col) {
            if (in_array($col, $existingCols, true)) {
                try { $forge->dropColumn('audit_logs', $col); } catch (\Throwable $e) {}
            }
        }
    }
}