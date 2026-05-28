<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration #067 — Bulk import infrastructure (xlsx).
 *
 * Latar belakang. Lihat docs/XLSX_IMPORT_AUDIT.md. Aplikasi belum punya
 * fasilitas bulk import dari `.xlsx` untuk operasi yang volumenya besar
 * (master karyawan, master org, allowance, roster, dll). Migration ini
 * menambahkan dua hal:
 *
 *   1. Tabel `import_batches` — audit trail setiap upload xlsx:
 *      file aslinya disimpan, jumlah baris, hasil parse + commit, daftar
 *      error per-baris. Idempotency by content_hash (re-upload file yang
 *      sama → return existing batch, tidak duplikasi).
 *
 *   2. Permissions baru `<resource>.bulk_import` untuk 6 modul yang siap
 *      dipakai (employees, departments, branches, positions, job_titles,
 *      job_grades). Modul lain bisa nyusul nanti.
 *
 * Tetap aman kalau dijalankan dua kali (idempotent).
 */
class CreateImportBatches extends Migration
{
    public function up(): void
    {
        $forge = $this->forge;
        $db    = $this->db;

        // ─── 1. Tabel import_batches ──────────────────────────────────
        if (! $db->tableExists('import_batches')) {
            $forge->addField([
                'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'resource'       => [
                    'type' => 'VARCHAR', 'constraint' => 64, 'null' => false,
                    'comment' => 'Slug resource (employees, departments, dll). Lihat ImportRegistry.',
                ],
                'user_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'original_name'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'file_path'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'file_size'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'content_hash'   => [
                    'type' => 'CHAR', 'constraint' => 64, 'null' => true,
                    'comment' => 'sha256 isi file untuk idempotency.',
                ],
                'status' => [
                    'type' => 'ENUM',
                    'constraint' => ['PENDING', 'PREVIEW', 'COMMITTED', 'FAILED', 'CANCELLED'],
                    'default' => 'PENDING',
                ],
                'total_rows'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'valid_rows'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'error_rows'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'inserted_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'skipped_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'errors_json'    => ['type' => 'MEDIUMTEXT', 'null' => true,
                    'comment' => 'Array {row_index, column, error} per baris invalid.'],
                'preview_json'   => ['type' => 'MEDIUMTEXT', 'null' => true,
                    'comment' => 'Snapshot 50 row pertama untuk konfirmasi UI.'],
                'previewed_at'   => ['type' => 'DATETIME', 'null' => true],
                'committed_at'   => ['type' => 'DATETIME', 'null' => true],
                'failed_reason'  => ['type' => 'TEXT', 'null' => true],

                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
                'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey(['company_id', 'resource', 'status']);
            $forge->addKey(['company_id', 'user_id']);
            $forge->addKey('content_hash');
            $forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
            $forge->createTable('import_batches');
        }

        // ─── 2. Permissions baru per modul ────────────────────────────
        $newPermissions = [
            ['slug' => 'employees.bulk_import',  'name' => 'Bulk Import Karyawan dari Excel',     'group' => 'hr'],
            ['slug' => 'departments.bulk_import','name' => 'Bulk Import Department dari Excel',   'group' => 'master'],
            ['slug' => 'branches.bulk_import',   'name' => 'Bulk Import Cabang dari Excel',       'group' => 'master'],
            ['slug' => 'positions.bulk_import',  'name' => 'Bulk Import Posisi dari Excel',       'group' => 'master'],
            ['slug' => 'job_titles.bulk_import', 'name' => 'Bulk Import Master Jabatan dari Excel','group' => 'master'],
            ['slug' => 'job_grades.bulk_import', 'name' => 'Bulk Import Job Grade dari Excel',    'group' => 'master'],
        ];

        if ($db->tableExists('permissions')) {
            // Skema `permissions` sedikit beda antar instance — beberapa
            // tidak punya `created_at`/`updated_at`. Filter ke kolom yang
            // benar-benar ada supaya migration tidak crash.
            $permCols = $db->getFieldNames('permissions');
            $now = date('Y-m-d H:i:s');
            foreach ($newPermissions as $p) {
                $existing = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
                if (! $existing) {
                    $insert = $p;
                    if (in_array('created_at', $permCols, true)) $insert['created_at'] = $now;
                    if (in_array('updated_at', $permCols, true)) $insert['updated_at'] = $now;
                    $insert = array_intersect_key($insert, array_flip($permCols));
                    $db->table('permissions')->insert($insert);
                }
            }

            // Auto-grant ke role super_admin & hr_admin (kalau ada).
            // Role lain harus eksplisit assign permission ini dari UI.
            if ($db->tableExists('roles') && $db->tableExists('role_permissions')) {
                $rpCols = $db->getFieldNames('role_permissions');
                $roles = $db->table('roles')
                    ->whereIn('slug', ['super_admin', 'hr_admin', 'system_admin'])
                    ->get()->getResultArray();
                foreach ($roles as $r) {
                    foreach ($newPermissions as $p) {
                        $perm = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
                        if (! $perm) continue;
                        $exists = $db->table('role_permissions')
                            ->where('role_id', $r['id'])
                            ->where('permission_id', $perm['id'])
                            ->countAllResults();
                        if ($exists === 0) {
                            $rpInsert = [
                                'role_id'       => $r['id'],
                                'permission_id' => $perm['id'],
                            ];
                            if (in_array('created_at', $rpCols, true)) $rpInsert['created_at'] = $now;
                            $rpInsert = array_intersect_key($rpInsert, array_flip($rpCols));
                            $db->table('role_permissions')->insert($rpInsert);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $db = $this->db;

        if ($db->tableExists('import_batches')) {
            try { $this->forge->dropTable('import_batches', true); } catch (\Throwable $e) {}
        }

        if ($db->tableExists('permissions')) {
            $slugs = [
                'employees.bulk_import', 'departments.bulk_import', 'branches.bulk_import',
                'positions.bulk_import', 'job_titles.bulk_import',  'job_grades.bulk_import',
            ];
            $perms = $db->table('permissions')->whereIn('slug', $slugs)->get()->getResultArray();
            $ids = array_column($perms, 'id');
            if (! empty($ids) && $db->tableExists('role_permissions')) {
                $db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            }
            $db->table('permissions')->whereIn('slug', $slugs)->delete();
        }
    }
}