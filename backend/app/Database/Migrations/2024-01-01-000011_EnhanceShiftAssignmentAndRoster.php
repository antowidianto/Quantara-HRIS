<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Improvement set untuk Shift, Roster, dan Pola Shift.
 *
 * Latar belakang:
 *  - Sebelumnya `shifts` hanya scoped ke `company_id`, padahal tiap cabang /
 *    pabrik / lokasi kerja sering punya shift sendiri (kantor pusat 1 shift,
 *    pabrik 3 shift, gudang 2 shift).
 *  - `shift_schedules` 1-row-per-hari/karyawan memang fleksibel, tapi tidak
 *    ada konsep "pola siklus" (5-2 Senin–Jumat, 4-2 rotasi pabrik) sehingga
 *    HR harus input manual setiap hari.
 *
 * Yang ditambahkan:
 *  1. Kolom branch/work_location/working_days/color/is_default di `shifts`.
 *  2. Tabel `shift_assignments` (mirror `approval_assignments`) untuk memetakan
 *     shift ke scope branch/department/job_grade/work_location.
 *  3. Tabel `shift_patterns` — definisi pola siklus (cycle_days + JSON daily).
 *  4. Tabel `shift_pattern_assignments` — apply pattern ke employee/scope
 *     dengan anchor_date sebagai patokan day-1 siklus.
 *  5. Kolom `default_shift_id` & `shift_pattern_id` di `employees`.
 *  6. Permission baru + auto-grant ke role super admin (1) & HR admin (2).
 *
 * Migration ini idempotent — aman dijalankan ulang.
 */
class EnhanceShiftAssignmentAndRoster extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. Extend `shifts` ──────────────────────────────────────
        $shiftAdditions = [
            'branch_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'Shift terikat ke cabang tertentu (NULL = berlaku company-wide)'],
            'work_location_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'Optional: terikat ke work_location spesifik'],
            'department_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'Optional: shift khusus departemen'],
            'is_default'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0,
                'comment' => '1 = default fallback untuk scope-nya'],
            'color'            => ['type' => 'VARCHAR', 'constraint' => 9, 'null' => true,
                'comment' => 'Hex color untuk roster calendar, mis. #2563eb'],
            'working_days'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true,
                'comment' => 'CSV ISO weekday 1-7, mis. 1,2,3,4,5'],
            'description'      => ['type' => 'TEXT', 'null' => true],
        ];
        $existingShiftCols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('shifts'));
        $toAdd = [];
        foreach ($shiftAdditions as $col => $def) {
            if (! in_array(strtolower($col), $existingShiftCols, true)) {
                $toAdd[$col] = $def;
            }
        }
        if (! empty($toAdd)) {
            $forge->addColumn('shifts', $toAdd);
        }
        $this->createIndexIfMissing($db, 'shifts', 'shifts_branch_idx', ['branch_id']);
        $this->createIndexIfMissing($db, 'shifts', 'shifts_worklocation_idx', ['work_location_id']);
        $this->createIndexIfMissing($db, 'shifts', 'shifts_department_idx', ['department_id']);

        // ─── 2. Extend `employees` ───────────────────────────────────
        $empAdditions = [
            'default_shift_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'Shift default karyawan (override scope)'],
            'shift_pattern_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                'comment' => 'Pola siklus shift yang aktif untuk karyawan'],
            'shift_pattern_anchor' => ['type' => 'DATE', 'null' => true,
                'comment' => 'Tanggal patokan day-1 pola (default = join_date)'],
        ];
        $existingEmpCols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
        $empToAdd = [];
        foreach ($empAdditions as $col => $def) {
            if (! in_array(strtolower($col), $existingEmpCols, true)) {
                $empToAdd[$col] = $def;
            }
        }
        if (! empty($empToAdd)) {
            $forge->addColumn('employees', $empToAdd);
        }
        $this->createIndexIfMissing($db, 'employees', 'employees_default_shift_idx', ['default_shift_id']);

        // ─── 3. SHIFT_ASSIGNMENTS ───────────────────────────────────
        // Rule pemetaan: shift mana yang berlaku untuk scope tertentu.
        // Prioritas (kecil = tinggi): employee.default → assignment by
        // job_grade → department → branch → work_location → company default.
        if (! $db->tableExists('shift_assignments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'shift_id'        => ['type' => 'INT', 'unsigned' => true],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'work_location_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'job_grade_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'priority'        => ['type' => 'INT', 'unsigned' => true, 'default' => 100,
                    'comment' => 'Lebih kecil = prioritas lebih tinggi'],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'effective_from'  => ['type' => 'DATE', 'null' => true],
                'effective_to'    => ['type' => 'DATE', 'null' => true],
                'notes'           => ['type' => 'TEXT', 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'priority']);
            $forge->addKey('shift_id');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('shift_id', 'shifts', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('shift_assignments');
        }

        // ─── 4. SHIFT_PATTERNS ──────────────────────────────────────
        // Pola siklus mis. 5-2 (Pagi×5, OFF×2), 4-2 rotasi pabrik (Pagi×4,
        // OFF×2, Sore×4, OFF×2, Malam×4, OFF×2 → cycle 18 hari), 24-jam
        // dokter (1 ON, 2 OFF). pattern_data adalah JSON array sepanjang
        // cycle_days, indeks 0..(cycle_days-1):
        //   [
        //     {"shift_id": 1, "is_off": false, "is_holiday": false},
        //     ...
        //   ]
        if (! $db->tableExists('shift_patterns')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'cycle_days'   => ['type' => 'INT', 'unsigned' => true, 'default' => 7,
                    'comment' => 'Panjang siklus dalam hari'],
                'pattern_data' => ['type' => 'JSON', 'null' => true,
                    'comment' => 'Array sepanjang cycle_days berisi {shift_id, is_off, is_holiday}'],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('shift_patterns');
        }

        // ─── 5. SHIFT_PATTERN_ASSIGNMENTS ───────────────────────────
        // Apply pattern ke scope: employee tertentu, atau gabungan
        // department/branch/work_location/job_grade. anchor_date adalah
        // patokan "day-0" siklus.
        if (! $db->tableExists('shift_pattern_assignments')) {
            $forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'      => ['type' => 'INT', 'unsigned' => true],
                'pattern_id'      => ['type' => 'INT', 'unsigned' => true],
                'employee_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true,
                    'comment' => 'Kalau diisi → hanya untuk karyawan ini'],
                'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'branch_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'work_location_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'job_grade_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'anchor_date'     => ['type' => 'DATE', 'comment' => 'Patokan hari ke-1 siklus'],
                'date_from'       => ['type' => 'DATE'],
                'date_to'         => ['type' => 'DATE', 'null' => true,
                    'comment' => 'NULL = aktif terus'],
                'priority'        => ['type' => 'INT', 'unsigned' => true, 'default' => 100],
                'is_active'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'priority']);
            $forge->addKey('pattern_id');
            $forge->addKey(['employee_id', 'date_from']);
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->addForeignKey('pattern_id', 'shift_patterns', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('shift_pattern_assignments');
        }

        // ─── 6. PERMISSIONS BARU ────────────────────────────────────
        $newPermissions = [
            ['slug' => 'shift.view',      'name' => 'View Shifts',           'group' => 'master'],
            ['slug' => 'shift.manage',    'name' => 'Manage Shifts',         'group' => 'master'],
            ['slug' => 'roster.view',     'name' => 'View Roster',           'group' => 'attendance'],
            ['slug' => 'roster.manage',   'name' => 'Manage Roster',         'group' => 'attendance'],
            ['slug' => 'shift_pattern.view',   'name' => 'View Shift Patterns',   'group' => 'master'],
            ['slug' => 'shift_pattern.manage', 'name' => 'Manage Shift Patterns', 'group' => 'master'],
        ];
        $now = date('Y-m-d H:i:s');
        $insertedSlugs = [];
        foreach ($newPermissions as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $p['created_at'] = $now;
            $db->table('permissions')->insert($p);
            $insertedSlugs[] = $p['slug'];
        }

        // Auto-grant ke Super Admin (1) & HR Admin (2).
        $allSlugs = array_column($newPermissions, 'slug');
        foreach ([1, 2] as $roleId) {
            foreach ($allSlugs as $slug) {
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
        $forge = $this->forge;
        $db    = $this->db;

        foreach ([
            'shift_pattern_assignments',
            'shift_patterns',
            'shift_assignments',
        ] as $tbl) {
            if ($db->tableExists($tbl)) $forge->dropTable($tbl, true);
        }

        $shiftCols = ['branch_id','work_location_id','department_id','is_default','color','working_days','description'];
        $existing = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('shifts'));
        foreach ($shiftCols as $c) {
            if (in_array(strtolower($c), $existing, true)) {
                try { $forge->dropColumn('shifts', $c); } catch (\Throwable $e) {}
            }
        }

        $empCols = ['default_shift_id','shift_pattern_id','shift_pattern_anchor'];
        $existingEmp = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
        foreach ($empCols as $c) {
            if (in_array(strtolower($c), $existingEmp, true)) {
                try { $forge->dropColumn('employees', $c); } catch (\Throwable $e) {}
            }
        }
    }

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
}