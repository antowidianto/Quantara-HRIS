<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Organization Structures — master jabatan kunci perusahaan.
 *
 * Tabel ini menyimpan **struktur jabatan strategis** yang dipakai untuk
 * routing approval, lookup tanggung jawab, dan organogram. Berbeda
 * dengan `positions` (yang descriptive — semua posisi), tabel ini fokus
 * ke peran kunci (key role) yang punya kewenangan khusus, mis. siapa
 * yang approve MPP, siapa Direktur HR, siapa Finance Manager, dst.
 *
 * Pola umum sistem HRIS Indonesia:
 *  - Direksi: Direktur Utama, Direktur HR, Direktur Keuangan,
 *    Direktur Operasional/Komersial, dll
 *  - Senior management: General Manager, HR Manager, Finance Manager
 *  - Lini fungsional: TA Manager, Compensation Specialist, Payroll Lead
 *  - Operasional: Supervisor, Section Head
 *
 * Kolom `role_key` adalah enum string yang dipakai modul lain untuk
 * resolve approver. Mis. `mpp.approve` → cari user dengan
 * role_key = 'HRD_MANAGER' aktif di company.
 *
 * Lihat docs/ORG_STRUCTURE.md untuk daftar role_key bawaan.
 */
class CreateOrganizationStructures extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if (! $db->tableExists('organization_structures')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],

                // Key untuk lookup di kode (mis. ManpowerService cari HRD_MANAGER)
                'role_key'        => ['type' => 'VARCHAR', 'constraint' => 64,
                    'comment' => 'Stable identifier: CEO, HRD_DIRECTOR, HRD_MANAGER, FINANCE_MANAGER, dst.'],

                // Tampilan untuk UI dropdown
                'title'           => ['type' => 'VARCHAR', 'constraint' => 200,
                    'comment' => 'Label di UI, mis. "HR Manager" / "Direktur Keuangan"'],

                // Tier — sesuaikan dengan jenjang HR Indonesia
                'level' => [
                    'type' => 'ENUM',
                    'constraint' => ['BOD', 'EXECUTIVE', 'GENERAL_MANAGER', 'MANAGER', 'ASST_MANAGER', 'SUPERVISOR', 'OFFICER', 'STAFF'],
                    'default' => 'MANAGER',
                    'comment' => 'BOD=Direksi, GM=General Manager, dst.'],

                'description'     => ['type' => 'TEXT', 'null' => true],

                // Scope: department / branch yang dipimpin
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Dept yang dipimpin/scope (NULL = lintas dept / korporat)'],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Branch khusus (NULL = semua cabang)'],

                // Hirarki: atasan langsung di level strategis
                'parent_role_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Self-reference — atasan langsung di organogram strategis'],

                // Penjabat saat ini
                'employee_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Karyawan yang menempati posisi ini (NULL = vacant)'],

                // Bisa ber-rangkap (acting / merangkap jabatan) — daftar
                // employee_id alternatif sebagai backup approver. JSON array.
                'backup_employee_ids' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Daftar employee_id pengganti/PJS (acting)'],

                // Approval scope — modul/aksi apa yang dia approve.
                // Format: ["mpp.budget", "mpp.unbudget", "leave.long_term", ...]
                'approval_scopes' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Modul yang di-approve (untuk routing engine)'],

                'is_key_role'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1,
                    'comment' => 'Jika 1, muncul di dropdown approver / picker'],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'sort_order'      => ['type' => 'INT', 'default' => 0,
                    'comment' => 'Urutan tampil di organogram'],

                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'updated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'role_key']);
            $forge->addKey(['company_id', 'is_active', 'is_key_role']);
            $forge->addKey('employee_id');
            $forge->addKey('department_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('organization_structures');
        }

        // Seed default key roles per company aktif. Dijalankan idempoten:
        // hanya insert role_key yang belum ada, supaya migrate ulang aman.
        $companies = $db->table('companies')->where('is_active', 1)->get()->getResultArray();
        $now = date('Y-m-d H:i:s');

        // Daftar peran kunci default ala HRIS Indonesia. Bisa di-extend
        // per company lewat UI Master Data setelah ini.
        $defaultRoles = [
            // ─── Direksi (BOD) ──────────────────────────────────────
            ['role_key' => 'CEO',              'title' => 'Direktur Utama',           'level' => 'BOD',         'sort' => 10,
                'scopes' => ['mpp.unbudget']],
            ['role_key' => 'DIRECTOR_HR',      'title' => 'Direktur HR',              'level' => 'BOD',         'sort' => 20,
                'scopes' => ['mpp.unbudget', 'severance.high_value']],
            ['role_key' => 'DIRECTOR_FINANCE', 'title' => 'Direktur Keuangan',        'level' => 'BOD',         'sort' => 30,
                'scopes' => ['mpp.unbudget', 'reimbursement.high_value']],
            ['role_key' => 'DIRECTOR_OPS',     'title' => 'Direktur Operasional',     'level' => 'BOD',         'sort' => 40],

            // ─── General Management ─────────────────────────────────
            ['role_key' => 'GM_HR',            'title' => 'General Manager HR',       'level' => 'GENERAL_MANAGER', 'sort' => 100],
            ['role_key' => 'GM_FINANCE',       'title' => 'General Manager Finance',  'level' => 'GENERAL_MANAGER', 'sort' => 110],

            // ─── HR Department ──────────────────────────────────────
            ['role_key' => 'HRD_MANAGER',      'title' => 'HR Manager',               'level' => 'MANAGER', 'sort' => 200,
                'scopes' => ['mpp.budget', 'leave.long_term', 'resignation.approve', 'disciplinary.issue']],
            ['role_key' => 'TALENT_ACQ_LEAD',  'title' => 'Talent Acquisition Lead',  'level' => 'MANAGER', 'sort' => 210,
                'scopes' => ['recruitment.manage', 'mpp.fulfill']],
            ['role_key' => 'COMP_BEN_LEAD',    'title' => 'Compensation & Benefit Lead', 'level' => 'MANAGER', 'sort' => 220,
                'scopes' => ['payroll.manage', 'allowance.manage']],
            ['role_key' => 'HR_OPS_LEAD',      'title' => 'HR Operations Lead',       'level' => 'MANAGER', 'sort' => 230,
                'scopes' => ['attendance.review_anomaly']],
            ['role_key' => 'PEOPLE_DEV_LEAD',  'title' => 'People Development Lead',  'level' => 'MANAGER', 'sort' => 240,
                'scopes' => ['training.manage', 'performance.manage']],

            // ─── Finance Department ─────────────────────────────────
            ['role_key' => 'FINANCE_MANAGER',  'title' => 'Finance Manager',          'level' => 'MANAGER', 'sort' => 300,
                'scopes' => ['payroll.finalize', 'reimbursement.approve']],
            ['role_key' => 'PAYROLL_LEAD',     'title' => 'Payroll Lead',             'level' => 'SUPERVISOR', 'sort' => 310],
            ['role_key' => 'TAX_LEAD',         'title' => 'Tax Lead',                 'level' => 'SUPERVISOR', 'sort' => 320],

            // ─── IT / Operations ────────────────────────────────────
            ['role_key' => 'IT_MANAGER',       'title' => 'IT Manager',               'level' => 'MANAGER', 'sort' => 400,
                'scopes' => ['onboarding.it_setup']],
            ['role_key' => 'OPS_MANAGER',      'title' => 'Operations Manager',       'level' => 'MANAGER', 'sort' => 410],
        ];

        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            foreach ($defaultRoles as $r) {
                $exists = $db->table('organization_structures')
                    ->where('company_id', $companyId)
                    ->where('role_key', $r['role_key'])
                    ->countAllResults();
                if ($exists > 0) continue;
                $db->table('organization_structures')->insert([
                    'company_id'       => $companyId,
                    'role_key'         => $r['role_key'],
                    'title'            => $r['title'],
                    'level'            => $r['level'],
                    'description'      => null,
                    'department_id'    => null,
                    'branch_id'        => null,
                    'parent_role_id'   => null,
                    'employee_id'      => null,  // HR isi via UI
                    'backup_employee_ids' => null,
                    'approval_scopes'  => isset($r['scopes']) ? json_encode($r['scopes']) : null,
                    'is_key_role'      => 1,
                    'is_active'        => 1,
                    'sort_order'       => (int) $r['sort'],
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        }

        // Permissions baru — manage organization structure terpisah
        // dari edit master positions, supaya bisa dibatasi ke OD lead /
        // HR Director saja.
        $newPermissions = [
            ['slug' => 'org_structure.view',   'name' => 'View Organization Structure roles', 'group' => 'master'],
            ['slug' => 'org_structure.manage', 'name' => 'Manage Organization Structure (assign jabatan)', 'group' => 'master'],
        ];
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
        }
        // Auto-grant ke role super(1) + HR(2). Manager(3) hanya view.
        $grantMap = [
            1 => ['org_structure.view', 'org_structure.manage'],
            2 => ['org_structure.view', 'org_structure.manage'],
            3 => ['org_structure.view'],
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
        if ($this->db->tableExists('organization_structures')) {
            $this->forge->dropTable('organization_structures', true);
        }
    }
}