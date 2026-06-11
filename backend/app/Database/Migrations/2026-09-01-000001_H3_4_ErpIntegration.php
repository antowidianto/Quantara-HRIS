<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H3.4 — Integrasi ERP / Accounting Journal.
 *
 * Modul ini mempersiapkan tiga tabel untuk posting jurnal akuntansi
 * otomatis dari payroll/bonus/THR ke sistem ERP eksternal:
 *
 *   - erp_connections        : kredensial koneksi per company per provider
 *                              (Accurate Online, Zahir, Odoo, SAP, CSV_ONLY)
 *   - erp_journal_mappings   : pemetaan cost_center × account_type → COA
 *                              debit/kredit. Mapping ini sumber kebenaran
 *                              untuk konversi line item HR ke entry jurnal.
 *   - erp_journal_exports    : log per-export. Menyimpan source (payroll
 *                              period / bonus run / thr run), payload
 *                              jurnal lengkap, status posting, dan response
 *                              log untuk audit + retry.
 *
 * Idempotent: cek tableExists sebelum create, fieldExists sebelum addColumn.
 *
 * Permission baru:
 *   - erp.mapping.manage    : kelola COA mapping
 *   - erp.journal.post      : trigger posting / re-post
 *   - erp.journal.view      : view export log
 */
class H3_4_ErpIntegration extends Migration
{
    public function up(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        // ─── erp_connections ────────────────────────────────────────
        if (! $db->tableExists('erp_connections')) {
            $forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'unsigned' => true],
                'provider'   => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'comment'    => 'ACCURATE | ZAHIR | ODOO | SAP_IDOC | CSV_ONLY',
                ],
                'base_url'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'api_key_encrypted'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'AES-256-GCM via App\\Libraries\\Security\\EncryptionService'],
                'extra_config_json'    => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'Per-provider config: db_id, branch_code, mapping_currency, dst'],
                'is_active'            => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'last_test_status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                    'comment' => 'OK | FAILED | UNKNOWN — diisi saat user klik "Test Connection"'],
                'last_test_at'         => ['type' => 'DATETIME', 'null' => true],
                'last_test_message'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'           => ['type' => 'DATETIME', 'null' => true],
                'updated_at'           => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'           => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'is_active']);
            $forge->addUniqueKey(['company_id', 'provider']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('erp_connections');
        }

        // ─── erp_journal_mappings ───────────────────────────────────
        if (! $db->tableExists('erp_journal_mappings')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'cost_center_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL = mapping fallback (catch-all) ketika cost center karyawan tidak ada mapping spesifik'],
                'account_type'   => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'comment'    => 'SALARY | OVERTIME | THR | BONUS | BPJS_KES | BPJS_TK | PPH21 | ALLOWANCE_CASH | ALLOWANCE_NATURA | LOAN_DEDUCTION | OTHER_DEDUCTION',
                ],
                'debit_coa'      => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Kode akun debit di ERP (mis. 5101.001 untuk Beban Gaji)'],
                'credit_coa'     => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Kode akun kredit di ERP (mis. 2103.001 untuk Hutang Gaji)'],
                'description_template' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true,
                    'comment' => 'Template narasi: dapat berisi placeholder {period}, {employee_count}, {cost_center_name}'],
                'is_active'      => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'account_type', 'is_active']);
            $forge->addKey(['company_id', 'cost_center_id', 'account_type']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('erp_journal_mappings');
        }

        // ─── erp_journal_exports ────────────────────────────────────
        if (! $db->tableExists('erp_journal_exports')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true],
                'connection_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'NULL untuk CSV-only export'],
                'source_type'    => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'comment'    => 'PAYROLL_PERIOD | BONUS_RUN | THR_RUN',
                ],
                'source_id'      => ['type' => 'INT', 'unsigned' => true],
                'period_label'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                    'comment' => 'Mis. "2026-09" untuk human readability'],
                'format'         => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'comment'    => 'ACCURATE_API | ZAHIR_CSV | ODOO_API | SAP_IDOC | GENERIC_CSV',
                ],
                'total_debit'    => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
                'total_credit'   => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
                'line_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'lines_json'     => ['type' => 'LONGTEXT',
                    'comment' => 'Snapshot lengkap entries jurnal (debit/credit/coa/description per line)'],
                'status'         => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'DRAFT',
                    'comment'    => 'DRAFT | POSTED | FAILED | REVERSED',
                ],
                'posted_at'      => ['type' => 'DATETIME', 'null' => true],
                'posted_by_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'reversed_at'    => ['type' => 'DATETIME', 'null' => true],
                'response_log'   => ['type' => 'TEXT', 'null' => true,
                    'comment' => 'External response (request/response body untuk audit + retry)'],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'source_type', 'source_id']);
            $forge->addKey(['company_id', 'status', 'created_at']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('connection_id', 'erp_connections', 'id', 'SET NULL', 'CASCADE');
            $forge->createTable('erp_journal_exports');
        }

        // ─── Seed permissions (idempotent) ──────────────────────────
        if ($db->tableExists('permissions')) {
            $perms = [
                ['slug' => 'erp.mapping.manage', 'name' => 'Manage ERP COA Mapping', 'group' => 'erp', 'description' => 'Kelola COA mapping ERP per cost center / account type'],
                ['slug' => 'erp.journal.post',   'name' => 'Post ERP Journal',       'group' => 'erp', 'description' => 'Trigger posting jurnal payroll → ERP eksternal'],
                ['slug' => 'erp.journal.view',   'name' => 'View ERP Journal',       'group' => 'erp', 'description' => 'Lihat log export jurnal (read-only)'],
            ];
            $now = date('Y-m-d H:i:s');
            foreach ($perms as $p) {
                $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
                if (! $exists) {
                    $db->table('permissions')->insert($p + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $db    = $this->db;
        $forge = $this->forge;

        foreach (['erp_journal_exports', 'erp_journal_mappings', 'erp_connections'] as $tbl) {
            if ($db->tableExists($tbl)) {
                $forge->dropTable($tbl, true);
            }
        }

        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('slug', [
                'erp.mapping.manage',
                'erp.journal.post',
                'erp.journal.view',
            ])->delete();
        }
    }
}