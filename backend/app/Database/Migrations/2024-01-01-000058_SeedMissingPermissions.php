<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SeedMissingPermissions (Sprint 6 polish)
 *
 * Audit dengan `php scripts/audit_permissions.php` mengungkap beberapa
 * permission yang dipakai di code (di Controller atau frontend sidebar)
 * tapi tidak pernah ter-seed ke tabel `permissions`. Akibatnya:
 *
 *   1. Permission tidak muncul di modal Edit Role super admin
 *      (endpoint `/permissions` hanya menampilkan yang ter-seed).
 *   2. Super admin tidak bisa memberi/mencabut izin tsb dari role manapun
 *      (di luar role 1 yang punya wildcard di middleware).
 *   3. Tenant baru otomatis kehilangan akses ke fitur (mis. Bonus Engine)
 *      sampai HR developer ingat menjalankan SQL manual.
 *
 * Migration ini menambahkan slug-slug yang hilang berikut grant default-nya
 * ke role bawaan supaya konsisten dengan modul-modul lain:
 *
 *   - bonus.view       — VIEW Bonus Engine (UI Bonus Runs / Schemes)
 *   - bonus.manage     — CRUD scheme + create run + finalize
 *   - bonus.approve    — approve run sebelum di-merge ke payroll
 *   - hr.manage        — umbrella HR permission (probation tracker, dll)
 *   - loan.create_for_others — HR/Finance create loan untuk karyawan lain
 *   - privacy.review   — review consent / DPO actions
 *
 * Idempotent: cek `slug` di `permissions` sebelum insert. Cocok dijalankan
 * di tenant lama tanpa risiko duplikat.
 */
class SeedMissingPermissions extends Migration
{
    /** @var list<array{slug:string,name:string,group:string,description?:string}> */
    private const PERMISSIONS = [
        // ─── Bonus Engine (Sprint 16+ backend, Sprint 19+ UI) ────────
        [
            'slug' => 'bonus.view',
            'name' => 'View Bonus',
            'group' => 'payroll',
            'description' => 'Lihat skema & run bonus, riwayat per karyawan',
        ],
        [
            'slug' => 'bonus.manage',
            'name' => 'Manage Bonus Scheme & Run',
            'group' => 'payroll',
            'description' => 'CRUD scheme, buat run, calculate, finalize',
        ],
        [
            'slug' => 'bonus.approve',
            'name' => 'Approve Bonus Run',
            'group' => 'payroll',
            'description' => 'Approve run sebelum di-merge ke payroll bulanan',
        ],

        // ─── HR umbrella permission ──────────────────────────────────
        // Dipakai sebagai catch-all fallback di ProbationController dan
        // calon controller HR lain (mutasi, transfer, lifecycle audit).
        // Saat ini super admin bypass via wildcard, tapi role HR Admin
        // perlu eksplisit punya permission ini supaya UI mereka konsisten.
        [
            'slug' => 'hr.manage',
            'name' => 'HR Operations Manage',
            'group' => 'hr',
            'description' => 'Umbrella permission untuk operasional HR (probation tracker, lifecycle decisions, dll)',
        ],

        // ─── Loan create on behalf ───────────────────────────────────
        // Distinct dari `loan.create` (self-service). Permission ini
        // memungkinkan HR/Finance bikin loan untuk karyawan lain via
        // form di /loans (tombol "Tambah Loan" hanya muncul kalau ada).
        [
            'slug' => 'loan.create_for_others',
            'name' => 'Create Loan For Others',
            'group' => 'finance',
            'description' => 'Buat pengajuan kasbon untuk karyawan lain (HR/Finance)',
        ],

        // ─── Privacy / DPO ───────────────────────────────────────────
        [
            'slug' => 'privacy.review',
            'name' => 'Review Privacy Requests',
            'group' => 'security',
            'description' => 'Review pengajuan akses data pribadi & consent (DPO actions)',
        ],
    ];

    /**
     * Grant default per role:
     *   1 super_admin → semua (tapi sebenarnya wildcard di middleware,
     *                     ditambah eksplisit di sini supaya UI tampak)
     *   2 hr_admin    → semua (HR perlu approve bonus, manage probation)
     *   3 manager     → bonus.view, hr.manage (untuk lihat & decide tim)
     *   5 finance     → bonus.view, bonus.manage, bonus.approve,
     *                   loan.create_for_others
     */
    private const GRANT_MAP = [
        1 => ['bonus.view', 'bonus.manage', 'bonus.approve',
              'hr.manage', 'loan.create_for_others', 'privacy.review'],
        2 => ['bonus.view', 'bonus.manage', 'bonus.approve',
              'hr.manage', 'loan.create_for_others', 'privacy.review'],
        3 => ['bonus.view', 'hr.manage'],
        5 => ['bonus.view', 'bonus.manage', 'bonus.approve',
              'loan.create_for_others'],
    ];

    public function up(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // 1. Insert permission yang belum ada
        $insertedSlugs = [];
        foreach (self::PERMISSIONS as $p) {
            $exists = $db->table('permissions')->where('slug', $p['slug'])->countAllResults();
            if ($exists > 0) continue;
            $row = $p;
            $row['created_at'] = $now;
            $db->table('permissions')->insert($row);
            $insertedSlugs[] = $p['slug'];
        }

        // 2. Grant ke role default — idempotent
        foreach (self::GRANT_MAP as $roleId => $slugs) {
            foreach ($slugs as $slug) {
                $perm = $db->table('permissions')->where('slug', $slug)->get()->getRowArray();
                if (! $perm) continue;
                $alreadyGranted = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', (int) $perm['id'])
                    ->countAllResults();
                if ($alreadyGranted > 0) continue;

                // Pastikan role-nya ada (di tenant tertentu, role bawaan
                // mungkin re-numbered saat seed enterprise — di-skip kalau
                // role tidak ditemukan biar tidak FK violation).
                $roleExists = $db->table('roles')->where('id', $roleId)->countAllResults();
                if ($roleExists === 0) continue;

                $db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => (int) $perm['id'],
                    'created_at'    => $now,
                ]);
            }
        }

        if (! empty($insertedSlugs)) {
            log_message(
                'notice',
                '[Migration 058] Seeded ' . count($insertedSlugs) . ' missing permissions: '
                . implode(', ', $insertedSlugs),
            );
        }
    }

    public function down(): void
    {
        $db = $this->db;
        if (! $db->tableExists('permissions')) return;

        foreach (self::PERMISSIONS as $p) {
            $perm = $db->table('permissions')->where('slug', $p['slug'])->get()->getRowArray();
            if (! $perm) continue;
            // Hapus grant terlebih dulu (FK)
            $db->table('role_permissions')->where('permission_id', (int) $perm['id'])->delete();
            $db->table('permissions')->where('id', (int) $perm['id'])->delete();
        }
    }
}