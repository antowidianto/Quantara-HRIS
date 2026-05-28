<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #064 — tiga perbaikan operasional:
 *
 *  1. **Master `job_titles`** — sebelumnya nama jabatan ditulis bebas di
 *     `positions.name` (mis. "Senior Backend Engineer", "Sr Backend Eng"),
 *     sehingga sulit di-aggregate untuk laporan compa-ratio, salary
 *     benchmark, dan WLKP. Tabel master ini menstandarisasi nama
 *     jabatan + family + level. `positions.job_title_id` dan
 *     `employees.job_title_id` di-link supaya bisa dipakai di laporan.
 *
 *  2. **Auto-backfill** dari `positions` existing — generate satu job_title
 *     per (company, name) unik dari positions, lalu set FK. Tidak ada
 *     migrasi data yang merusak; kolom lama tetap.
 *
 *  3. **Anniversary carry-over config** — kolom baru di `leave_types`:
 *
 *     - `carry_over_basis` (`YEAR_END` | `ANNIVERSARY`):
 *       basis perhitungan saldo cuti. Default `YEAR_END` mempertahankan
 *       behaviour lama. `ANNIVERSARY` = saldo direset & carry-over saat
 *       tanggal yang sama dengan join_date karyawan.
 *
 *     UU 13/2003 Pasal 79 ayat (5): cuti tahunan timbul setelah karyawan
 *     bekerja 12 bulan terus-menerus. Mode ANNIVERSARY mengikuti aturan
 *     ini secara strict (saldo penuh muncul tepat di hari ulang tahun
 *     kerja). Mode YEAR_END adalah praktik umum perusahaan Indonesia
 *     yang reset semua karyawan tanggal 1 Januari (lebih simple
 *     administratif).
 *
 * Idempotent: aman dijalankan ulang.
 */
class CreateJobTitlesAndCarryOverConfig extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. Tabel `job_titles` ──────────────────────────────────
        if (! $db->tableExists('job_titles')) {
            $forge->addField([
                'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'unsigned' => true],
                'code'         => ['type' => 'VARCHAR', 'constraint' => 32,
                                   'comment' => 'Kode unik per company, mis. SR_BE_ENG'],
                'name'         => ['type' => 'VARCHAR', 'constraint' => 200,
                                   'comment' => 'Nama jabatan resmi, mis. Senior Backend Engineer'],
                'family'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true,
                                   'comment' => 'Job family (Engineering, Sales, Finance, ...)'],
                'level'        => ['type' => 'ENUM',
                                   'constraint' => ['JUNIOR','MID','SENIOR','LEAD','MANAGER','DIRECTOR','EXECUTIVE'],
                                   'default'    => 'MID',
                                   'comment'    => 'Career level (untuk band salary)'],
                'description'  => ['type' => 'TEXT', 'null' => true],
                'is_active'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addUniqueKey(['company_id', 'code']);
            $forge->addKey('company_id');
            $forge->addKey('family');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('job_titles');
        }

        // ─── 2. Kolom job_title_id di positions & employees ─────────
        if ($db->tableExists('positions')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('positions'));
            if (! in_array('job_title_id', $cols, true)) {
                $forge->addColumn('positions', [
                    'job_title_id' => [
                        'type'     => 'INT',
                        'unsigned' => true,
                        'null'     => true,
                        'after'    => 'job_grade_id',
                        'comment'  => 'FK ke job_titles untuk standardisasi laporan',
                    ],
                ]);
            }
            if (! $this->indexExists('positions', 'idx_positions_job_title')) {
                $db->query('CREATE INDEX idx_positions_job_title ON positions (job_title_id)');
            }
        }

        if ($db->tableExists('employees')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
            if (! in_array('job_title_id', $cols, true)) {
                $forge->addColumn('employees', [
                    'job_title_id' => [
                        'type'     => 'INT',
                        'unsigned' => true,
                        'null'     => true,
                        'after'    => 'job_grade_id',
                        'comment'  => 'Snapshot FK ke job_titles. Boleh berbeda dari position.job_title_id (mis. acting role).',
                    ],
                ]);
            }
            if (! $this->indexExists('employees', 'idx_employees_job_title')) {
                $db->query('CREATE INDEX idx_employees_job_title ON employees (job_title_id)');
            }
        }

        // ─── 3. Backfill dari positions existing ────────────────────
        // Ambil daftar (company_id, name) unik dari positions yang aktif,
        // generate job_title baru kalau belum ada, lalu set FK ke
        // positions + employees yang punya position_id sama.
        if ($db->tableExists('positions') && $db->tableExists('job_titles')) {
            $now = date('Y-m-d H:i:s');
            $rows = $db->table('positions')
                ->select('id, company_id, code, name')
                ->where('deleted_at IS NULL')
                ->where('job_title_id IS NULL')
                ->get()->getResultArray();

            // Group by company + nama untuk dapat 1 job_title per kombinasi unik
            $seen = []; // key "{company_id}|{name}" → job_title_id
            foreach ($rows as $r) {
                $companyId = (int) $r['company_id'];
                $name      = trim((string) $r['name']);
                if ($name === '') continue;

                $key = $companyId . '|' . mb_strtolower($name);
                if (isset($seen[$key])) {
                    $jtId = $seen[$key];
                } else {
                    // Cek apakah sudah ada title dengan nama sama (mis. dari run sebelumnya)
                    $existing = $db->table('job_titles')
                        ->where('company_id', $companyId)
                        ->where('name', $name)
                        ->where('deleted_at IS NULL')
                        ->get()->getRowArray();

                    if ($existing) {
                        $jtId = (int) $existing['id'];
                    } else {
                        // Generate code unik dari nama (uppercase, alphanumeric+underscore)
                        $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
                        $baseCode = trim($baseCode, '_');
                        if ($baseCode === '') $baseCode = 'JT';
                        $baseCode = substr($baseCode, 0, 28); // sisakan ruang untuk suffix

                        $code = $baseCode;
                        $i = 1;
                        while ($db->table('job_titles')
                            ->where('company_id', $companyId)
                            ->where('code', $code)
                            ->countAllResults() > 0) {
                            $code = $baseCode . '_' . $i++;
                            if (strlen($code) > 32) {
                                $code = substr($baseCode, 0, 28) . '_' . $i;
                            }
                        }

                        $db->table('job_titles')->insert([
                            'company_id'  => $companyId,
                            'code'        => $code,
                            'name'        => $name,
                            'level'       => $this->guessLevelFromName($name),
                            'family'      => null,
                            'is_active'   => 1,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ]);
                        $jtId = (int) $db->insertID();
                    }
                    $seen[$key] = $jtId;
                }

                // Update position
                $db->table('positions')->where('id', (int) $r['id'])->update([
                    'job_title_id' => $jtId,
                    'updated_at'   => $now,
                ]);
            }

            // Sekarang propagasi ke employees yang punya position_id ber-job_title_id
            if ($db->tableExists('employees')) {
                $db->query(
                    'UPDATE employees e
                     INNER JOIN positions p ON p.id = e.position_id
                     SET e.job_title_id = p.job_title_id, e.updated_at = ?
                     WHERE e.job_title_id IS NULL
                       AND p.job_title_id IS NOT NULL
                       AND e.deleted_at IS NULL',
                    [$now]
                );
            }
        }

        // ─── 4. Permissions baru ────────────────────────────────────
        $this->seedPermissions([
            ['slug' => 'job_title.view',   'name' => 'View Job Titles',   'group' => 'master',
             'description' => 'Melihat master jabatan'],
            ['slug' => 'job_title.manage', 'name' => 'Manage Job Titles', 'group' => 'master',
             'description' => 'CRUD master jabatan'],
        ]);

        // ─── 5. Konfigurasi anniversary carry-over di leave_types ───
        if ($db->tableExists('leave_types')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_types'));
            $additions = [];
            if (! in_array('carry_over_basis', $cols, true)) {
                $additions['carry_over_basis'] = [
                    'type'       => 'ENUM',
                    'constraint' => ['YEAR_END', 'ANNIVERSARY'],
                    'default'    => 'YEAR_END',
                    'comment'    => 'YEAR_END = reset 1 Januari, ANNIVERSARY = reset di tanggal join_date (UU 13/2003 Pasal 79 ayat 5)',
                ];
            }
            if (! empty($additions)) {
                $forge->addColumn('leave_types', $additions);
            }
        }

        // ─── 6. Tracking tanggal anniversary terakhir di leave_balances ──
        if ($db->tableExists('leave_balances')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_balances'));
            $additions = [];
            if (! in_array('anniversary_processed_at', $cols, true)) {
                $additions['anniversary_processed_at'] = [
                    'type'    => 'DATE',
                    'null'    => true,
                    'comment' => 'Tanggal anniversary terakhir saat saldo direset (mode ANNIVERSARY).',
                ];
            }
            if (! in_array('basis', $cols, true)) {
                $additions['basis'] = [
                    'type'       => 'ENUM',
                    'constraint' => ['YEAR_END', 'ANNIVERSARY'],
                    'default'    => 'YEAR_END',
                    'comment'    => 'Basis saldo ini (snapshot dari leave_types saat dibuat)',
                ];
            }
            if (! empty($additions)) {
                $forge->addColumn('leave_balances', $additions);
            }
        }
    }

    public function down(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        if ($db->tableExists('leave_balances')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_balances'));
            foreach (['anniversary_processed_at', 'basis'] as $c) {
                if (in_array($c, $cols, true)) {
                    try { $forge->dropColumn('leave_balances', $c); } catch (\Throwable $e) {}
                }
            }
        }

        if ($db->tableExists('leave_types')) {
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('leave_types'));
            if (in_array('carry_over_basis', $cols, true)) {
                try { $forge->dropColumn('leave_types', 'carry_over_basis'); } catch (\Throwable $e) {}
            }
        }

        if ($db->tableExists('employees')) {
            if ($this->indexExists('employees', 'idx_employees_job_title')) {
                try { $db->query('DROP INDEX idx_employees_job_title ON employees'); } catch (\Throwable $e) {}
            }
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('employees'));
            if (in_array('job_title_id', $cols, true)) {
                try { $forge->dropColumn('employees', 'job_title_id'); } catch (\Throwable $e) {}
            }
        }

        if ($db->tableExists('positions')) {
            if ($this->indexExists('positions', 'idx_positions_job_title')) {
                try { $db->query('DROP INDEX idx_positions_job_title ON positions'); } catch (\Throwable $e) {}
            }
            $cols = array_map(fn ($c) => strtolower($c->name), $db->getFieldData('positions'));
            if (in_array('job_title_id', $cols, true)) {
                try { $forge->dropColumn('positions', 'job_title_id'); } catch (\Throwable $e) {}
            }
        }

        if ($db->tableExists('job_titles')) {
            try { $forge->dropTable('job_titles', true); } catch (\Throwable $e) {}
        }
    }

    /**
     * Tebak level dari nama jabatan supaya backfill tidak random.
     * Heuristik sederhana berdasarkan kata kunci yang lazim dipakai.
     */
    private function guessLevelFromName(string $name): string
    {
        $u = mb_strtolower($name);
        if (preg_match('/\b(direktur|director|cto|ceo|cfo|coo|chief)\b/u', $u)) return 'EXECUTIVE';
        if (preg_match('/\b(vp|vice\s*president|gm|general\s*manager)\b/u', $u)) return 'DIRECTOR';
        if (preg_match('/\b(manager|kepala|head\s+of)\b/u', $u))                  return 'MANAGER';
        if (preg_match('/\b(lead|principal|asst|asisten)\b/u', $u))               return 'LEAD';
        if (preg_match('/\b(senior|sr\.?|spv|supervisor)\b/u', $u))               return 'SENIOR';
        if (preg_match('/\b(junior|jr\.?|intern|magang|trainee)\b/u', $u))        return 'JUNIOR';
        return 'MID';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $indexes = $this->db->getIndexData($table);
            foreach ($indexes as $idx) {
                if ($idx->name === $indexName) return true;
            }
        } catch (\Throwable $e) {}
        return false;
    }

    /**
     * Tambah permissions baru + auto-grant ke role super_admin (id=1) dan
     * hr_admin (id=2). Idempotent.
     */
    private function seedPermissions(array $permissions): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($permissions as $p) {
            $exists = $this->db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists === 0) {
                $p['created_at'] = $now;
                $this->db->table('permissions')->insert($p);
            }
            $perm = $this->db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
            if (! $perm) continue;
            foreach ([1, 2] as $roleId) {
                $hasPerm = $this->db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($hasPerm > 0) continue;
                $this->db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }
    }
}