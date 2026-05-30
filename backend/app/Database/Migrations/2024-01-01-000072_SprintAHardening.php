<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint A — Stabilization & Safety Net
 *
 * Tiga area hardening keamanan & operasional sekaligus dalam 1 migration:
 *
 * 1. AUDIT LOG HASH CHAIN (B6 — immutability)
 *    Setiap row punya `entry_hash` (SHA256 dari kolom kanonik + previous_hash).
 *    Admin dengan akses DB tidak bisa edit/delete row tanpa break chain.
 *    Verifier: `php spark audit:verify-chain`.
 *
 * 2. REFRESH TOKEN DEVICE TRACKING (B5)
 *    Tambah `device_fingerprint`, `device_name`, `last_used_at` di refresh_tokens
 *    + index. UI "Active Sessions" bisa list device user dan revoke per device.
 *
 * 3. COMPOSITE INDEX TAMBAHAN (D2 — query performance)
 *    Index untuk query yang paling sering: audit log per tenant per waktu,
 *    attendance per karyawan per tanggal, payslip per period+employee.
 *    Pakai try/catch supaya idempotent (kalau index sudah ada, skip).
 */
class SprintAHardening extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        // ─── 1. Audit log hash chain ──────────────────────────────────
        // entry_hash = sha256(canonical_payload || previous_hash)
        // canonical_payload = json kanonik dari (id, action, entity, entity_id,
        //   user_id, company_id, created_at, before_data, after_data, severity).
        // Field `chain_id` memungkinkan multi-chain (per company atau per tahun)
        // supaya verifier tidak harus scan semua history saat hanya cek 1 segmen.
        if ($db->fieldExists('previous_hash', 'audit_logs') === false) {
            $this->forge->addColumn('audit_logs', [
                'chain_id'      => [
                    'type' => 'VARCHAR', 'constraint' => 32,
                    'null' => true, 'after' => 'created_at',
                    'comment' => 'partisi chain (mis. company-2026)',
                ],
                'previous_hash' => [
                    'type' => 'CHAR', 'constraint' => 64,
                    'null' => true, 'after' => 'chain_id',
                    'comment' => 'sha256 hex dari row sebelumnya di chain',
                ],
                'entry_hash'    => [
                    'type' => 'CHAR', 'constraint' => 64,
                    'null' => true, 'after' => 'previous_hash',
                    'comment' => 'sha256 hex dari payload + previous_hash',
                ],
            ]);
            // Index untuk fast lookup chain head
            $this->safeAddKey('audit_logs', ['chain_id', 'id'], 'idx_audit_chain_id');
        }

        // ─── 2. Refresh token device tracking ─────────────────────────
        if ($db->fieldExists('device_fingerprint', 'refresh_tokens') === false) {
            $this->forge->addColumn('refresh_tokens', [
                'device_fingerprint' => [
                    'type' => 'VARCHAR', 'constraint' => 64,
                    'null' => true, 'after' => 'ip_address',
                    'comment' => 'sha256 fingerprint dari (UA + accept-language) atau client-supplied',
                ],
                'device_name'        => [
                    'type' => 'VARCHAR', 'constraint' => 100,
                    'null' => true, 'after' => 'device_fingerprint',
                    'comment' => 'label human-readable: "Chrome di Windows", "Mobile Android"',
                ],
                'last_used_at'       => [
                    'type' => 'DATETIME',
                    'null' => true, 'after' => 'device_name',
                    'comment' => 'updated setiap rotate / validate sukses',
                ],
            ]);
            $this->safeAddKey('refresh_tokens', ['user_id', 'revoked', 'last_used_at'],
                'idx_refresh_user_active');
        }

        // ─── 3. Composite index tambahan ──────────────────────────────
        // Index untuk query Dashboard & report yang paling kena beban.
        // Pakai try/catch supaya idempotent kalau index sudah ada via #040.
        $this->safeAddKey('audit_logs', ['company_id', 'created_at'],
            'idx_audit_company_created');
        $this->safeAddKey('audit_logs', ['user_id', 'created_at'],
            'idx_audit_user_created');
        $this->safeAddKey('audit_logs', ['module', 'severity', 'created_at'],
            'idx_audit_module_severity');

        // Cek dulu apakah tabel attendances ada (tergantung urutan migrasi)
        if ($db->tableExists('attendances')) {
            $this->safeAddKey('attendances', ['employee_id', 'work_date'],
                'idx_attendance_emp_date');
        }
        if ($db->tableExists('payslips')) {
            $this->safeAddKey('payslips', ['payroll_period_id', 'employee_id'],
                'idx_payslip_period_emp');
        }
        if ($db->tableExists('leaves')) {
            $this->safeAddKey('leaves', ['employee_id', 'start_date'],
                'idx_leave_emp_start');
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        // Drop index dulu (sebagian DB engine require ini sebelum drop kolom)
        $this->safeDropKey('audit_logs', 'idx_audit_chain_id');
        $this->safeDropKey('audit_logs', 'idx_audit_company_created');
        $this->safeDropKey('audit_logs', 'idx_audit_user_created');
        $this->safeDropKey('audit_logs', 'idx_audit_module_severity');
        $this->safeDropKey('refresh_tokens', 'idx_refresh_user_active');
        if ($db->tableExists('attendances')) {
            $this->safeDropKey('attendances', 'idx_attendance_emp_date');
        }
        if ($db->tableExists('payslips')) {
            $this->safeDropKey('payslips', 'idx_payslip_period_emp');
        }
        if ($db->tableExists('leaves')) {
            $this->safeDropKey('leaves', 'idx_leave_emp_start');
        }

        if ($db->fieldExists('previous_hash', 'audit_logs')) {
            $this->forge->dropColumn('audit_logs', ['chain_id', 'previous_hash', 'entry_hash']);
        }
        if ($db->fieldExists('device_fingerprint', 'refresh_tokens')) {
            $this->forge->dropColumn('refresh_tokens',
                ['device_fingerprint', 'device_name', 'last_used_at']);
        }
    }

    /**
     * Tambah index dengan idempotency. Kalau sudah ada, skip diam-diam.
     */
    private function safeAddKey(string $table, array $cols, string $name): void
    {
        try {
            $this->forge->addKey($cols, false, false, $name);
            $this->forge->processIndexes($table);
        } catch (\Throwable $e) {
            // Index mungkin sudah ada — tidak fatal
            log_message('info', "[migration #072] safeAddKey skip {$name}: " . $e->getMessage());
        }
    }

    private function safeDropKey(string $table, string $name): void
    {
        try {
            $this->forge->dropKey($table, $name, false);
        } catch (\Throwable $e) {
            // Index mungkin tidak ada — tidak fatal
        }
    }
}