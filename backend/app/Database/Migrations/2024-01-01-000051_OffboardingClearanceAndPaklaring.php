<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sprint 1 Compliance: Offboarding Clearance, Paklaring, Final Settlement,
 * dan BPJS SLA tracking.
 *
 * Menambahkan field-field baru ke tabel existing (tidak buat tabel baru
 * supaya minimal disruption ke flow yang sudah jalan):
 *
 * 1. `resignation_requests`:
 *    - `assets_returned_at`            — timestamp saat HR konfirmasi semua aset balik
 *    - `assets_clearance_note`         — catatan kalau ada exception (mis. laptop hilang dengan polisi report)
 *    - `paklaring_id`                  — link ke generated_contracts row (tipe PAKLARING)
 *    - `paklaring_generated_at`        — kapan paklaring diterbitkan
 *    - `final_settlement_payslip_id`   — link ke payslips kalau gaji final + UPH sudah masuk payroll periode
 *    - `final_settlement_amount`       — total uang yang akan / sudah dibayar (gaji prorata + sisa cuti + UPH + pesangon)
 *    - `final_settlement_paid_at`      — kapan terbayar
 *    - `bpjs_deactivated_at`           — kapan HR deactivate BPJS Kes & TK ke BPJS
 *
 * 2. `contract_templates.contract_type` ENUM tambah `PAKLARING` dan `OFFER_LETTER`
 *    supaya bisa dipakai untuk template surat keterangan kerja & surat penawaran.
 *    (Future-proof — OFFER_LETTER untuk Sprint 2.)
 *
 * 3. Permissions baru:
 *    - `offboarding.clearance.view`    — lihat status clearance (HR + ybs)
 *    - `offboarding.clearance.manage`  — toggle clearance asset/finance
 *    - `paklaring.generate`            — generate paklaring (HR)
 *
 * Idempotent: cek field sudah ada sebelum addColumn.
 */
class OffboardingClearanceAndPaklaring extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. resignation_requests: tambah field clearance & link ──────
        if ($db->tableExists('resignation_requests')) {
            $existing = $db->getFieldNames('resignation_requests');

            $newFields = [];
            if (! in_array('assets_returned_at', $existing, true)) {
                $newFields['assets_returned_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                    'comment' => 'Timestamp HR konfirmasi semua aset karyawan sudah dikembalikan',
                ];
            }
            if (! in_array('assets_clearance_note', $existing, true)) {
                $newFields['assets_clearance_note'] = [
                    'type' => 'TEXT', 'null' => true,
                    'comment' => 'Catatan exception kalau ada (mis. laptop hilang dgn laporan polisi)',
                ];
            }
            if (! in_array('paklaring_id', $existing, true)) {
                $newFields['paklaring_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke generated_contracts row tipe PAKLARING',
                ];
            }
            if (! in_array('paklaring_generated_at', $existing, true)) {
                $newFields['paklaring_generated_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                ];
            }
            if (! in_array('final_settlement_payslip_id', $existing, true)) {
                $newFields['final_settlement_payslip_id'] = [
                    'type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'FK ke payslips: link ke payslip final settlement (gaji prorata + UPH + pesangon)',
                ];
            }
            if (! in_array('final_settlement_amount', $existing, true)) {
                $newFields['final_settlement_amount'] = [
                    'type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true,
                    'comment' => 'Total uang yang dibayar di final settlement (cache, source of truth tetap di payslip)',
                ];
            }
            if (! in_array('final_settlement_paid_at', $existing, true)) {
                $newFields['final_settlement_paid_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                ];
            }
            if (! in_array('bpjs_deactivated_at', $existing, true)) {
                $newFields['bpjs_deactivated_at'] = [
                    'type' => 'DATETIME', 'null' => true,
                    'comment' => 'Timestamp HR deactivate BPJS Kes & TK (wajib H+7 dari resign_date)',
                ];
            }

            if (! empty($newFields)) {
                $forge->addColumn('resignation_requests', $newFields);
            }
        }

        // ─── 2. contract_templates.contract_type ENUM extend ─────────────
        // CodeIgniter Forge tidak support modify ENUM lewat method dedicated,
        // jadi pakai raw query MySQL. Aman untuk SQLite dev (skip silently).
        if ($db->tableExists('contract_templates')
            && $db->DBDriver === 'MySQLi') {
            try {
                // Cek dulu enum saat ini supaya tidak overwrite kalau sudah include
                $row = $db->query(
                    "SHOW COLUMNS FROM contract_templates WHERE Field = 'contract_type'"
                )->getRowArray();
                $current = $row['Type'] ?? '';
                $needed = ['PAKLARING', 'OFFER_LETTER'];
                $needsUpdate = false;
                foreach ($needed as $val) {
                    if (stripos($current, "'{$val}'") === false) {
                        $needsUpdate = true; break;
                    }
                }
                if ($needsUpdate) {
                    $db->query(
                        "ALTER TABLE contract_templates MODIFY contract_type "
                        . "ENUM('PKWT','PKWTT','PROBATION','PKHL','MAGANG','OUTSOURCING',"
                        . "'PAKLARING','OFFER_LETTER','GENERIC') NOT NULL"
                    );
                }
            } catch (\Throwable $e) {
                log_message('warning', 'Could not extend contract_templates.contract_type ENUM: ' . $e->getMessage());
            }
        }

        // ─── 3. Permissions baru ─────────────────────────────────────────
        $newPermissions = [
            ['slug' => 'offboarding.clearance.view',   'name' => 'View Offboarding Clearance Status', 'group' => 'hr'],
            ['slug' => 'offboarding.clearance.manage', 'name' => 'Manage Offboarding Clearance (asset/finance/BPJS)', 'group' => 'hr'],
            ['slug' => 'paklaring.generate',           'name' => 'Generate Paklaring (Surat Keterangan Kerja)', 'group' => 'hr'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Grant ke role 1 (super admin) & 2 (HR admin).
        // Role 4 (employee) dapat clearance.view supaya bisa lihat status clearance-nya sendiri.
        $grantMap = [
            1 => ['offboarding.clearance.view', 'offboarding.clearance.manage', 'paklaring.generate'],
            2 => ['offboarding.clearance.view', 'offboarding.clearance.manage', 'paklaring.generate'],
            4 => ['offboarding.clearance.view'],
        ];
        foreach ($grantMap as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $exists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if ($db->tableExists('resignation_requests')) {
            $cols = [
                'assets_returned_at', 'assets_clearance_note',
                'paklaring_id', 'paklaring_generated_at',
                'final_settlement_payslip_id', 'final_settlement_amount', 'final_settlement_paid_at',
                'bpjs_deactivated_at',
            ];
            $existing = $db->getFieldNames('resignation_requests');
            foreach ($cols as $c) {
                if (in_array($c, $existing, true)) {
                    $this->forge->dropColumn('resignation_requests', $c);
                }
            }
        }
        // Permissions tidak di-drop di down — biarkan, tidak harm.
    }
}