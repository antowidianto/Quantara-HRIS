<?php

namespace App\Controllers\Api\V1\Identity;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Identity\RbacMenuCatalogService;
use App\Models\Identity\MenuModel;
use Config\Services;

/**
 * MenuController — endpoint untuk sidebar dinamis.
 *
 * Dua jalur utama:
 *
 *   1. **GET /menus/me** — dipanggil saat layout sidebar mount.
 *      Mengembalikan tree menu yang SUDAH difilter sesuai permission +
 *      role user saat ini. Frontend tinggal render tanpa cek permission
 *      tambahan. Endpoint ini cuma butuh JWT (no permission required) —
 *      semua user dapat menu sesuai akses-nya.
 *
 *   2. **CRUD admin** (/menus, /menus/(:num), reorder, dst) — butuh
 *      permission `menu.manage`. Lihat Routes.php.
 *
 * Strategi multi-tenant:
 *   - Tabel `menus` boleh punya record per company (company_id != NULL).
 *     Kalau company aktif tidak punya record sama sekali, fallback ke
 *     baris global (company_id IS NULL).
 *   - Saat HR di company X edit menu pertama kali, controller akan
 *     "fork" baris global ke baris per-company (lihat ensureCompanyFork)
 *     supaya edit lokal tidak mengubah default global yang shared.
 */
class MenuController extends ApiController
{
    /**
     * GET /menus/me — tree menu untuk user saat ini (sudah difilter).
     *
     * Output:
     *   {
     *     "data": [
     *       { "id": 1, "type": "SECTION", "code": "home", "label": ...,
     *         "translation_key": "nav.home",
     *         "children": [ {ITEM/PARENT...} ] }
     *     ]
     *   }
     */
    public function me()
    {
        $tenant = $this->tenant();
        $companyId = $tenant->getCompanyId();
        $isSuperAdmin = $tenant->isSuperAdmin();

        // Defensive: kalau migration #076 belum dijalankan, kembalikan tree
        // kosong supaya frontend pakai fallback minimum dari MenuStore
        // (dashboard saja). Tidak melempar 500 supaya halaman lain tetap
        // bisa diakses dan admin bisa segera jalan migrate.
        if (! $this->menusTableExists()) {
            log_message('warning', 'MenuController::me — tabel `menus` tidak ada. Jalankan php spark migrate.');
            return $this->ok([]);
        }

        // Ambil daftar permission user (kalau super admin, anggap semua granted).
        $permissions = $tenant->getPermissions();

        $rows = $this->loadEffectiveMenus($companyId);

        // Filter per row sesuai permission/super_admin_only/is_active.
        $allowed = [];
        foreach ($rows as $r) {
            if ((int) $r['is_active'] !== 1) continue;
            if ((int) $r['super_admin_only'] === 1 && ! $isSuperAdmin) continue;

            $perm = $r['permission'] ? trim((string) $r['permission']) : '';
            if ($perm !== '' && ! $isSuperAdmin && ! RbacMenuCatalogService::allows($perm, $permissions)) {
                continue;
            }
            $allowed[(int) $r['id']] = $r;
        }

        // Susun tree dari flat list. Drop SECTION/PARENT yang setelah
        // filter children-nya kosong supaya sidebar tidak nampilin
        // header tanpa isi.
        $tree = $this->buildTreeAndPrune($allowed);

        return $this->ok($tree);
    }

    /**
     * GET /menus — list flat untuk admin UI (manage).
     * Hasil sudah include kolom audit + kosong/null untuk format yang konsisten.
     */
    public function index()
    {
        $tenant = $this->tenant();
        $companyId = $tenant->getCompanyId();

        if (! $this->menusTableExists()) {
            return $this->fail(
                'Tabel menus belum tersedia. Jalankan: php spark migrate',
                503,
                'MIGRATION_PENDING'
            );
        }

        $rows = $this->loadEffectiveMenus($companyId, /*adminMode*/ true);
        $tree = $this->buildTree($rows);
        return $this->ok([
            'tree' => $tree,
            'flat' => $rows,
        ]);
    }

    /**
     * GET /menus/(:num)
     */
    public function show($id = null)
    {
        $id = (int) $id;
        $row = $this->findMenuForActiveTenant(new MenuModel(), $id);
        if (! $row) return $this->fail('Menu tidak ditemukan', 404);
        if (! $this->canAccessRow($row)) return $this->fail('Forbidden', 403);
        return $this->ok($row);
    }

    /**
     * POST /menus — buat menu baru di scope company aktif.
     * Super admin tanpa company aktif buat di scope global (company_id NULL).
     */
    public function create()
    {
        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'type'       => 'required|in_list[SECTION,PARENT,ITEM]',
            'code'       => 'required|max_length[100]',
            'label'      => 'required|max_length[150]',
            'parent_id'  => 'permit_empty|integer',
            'route_path' => 'permit_empty|max_length[255]',
            'permission' => 'permit_empty|max_length[100]',
            'icon'       => 'permit_empty|max_length[80]',
            'translation_key' => 'permit_empty|max_length[150]',
            'sort_order' => 'permit_empty|integer',
        ]);
        if (! $valid) return null;

        $tenant = $this->tenant();
        // Super admin: kalau tidak pilih company, simpan ke global (NULL).
        // User biasa: WAJIB punya company_id (di-resolve dari tenant).
        $companyId = $tenant->isSuperAdmin()
            ? ((int) ($body['company_id'] ?? 0) ?: null)
            : $tenant->getCompanyId();

        // Cek unik (company_id, code)
        $exists = $this->db()->table('menus')
            ->where('code', $body['code']);
        if ($companyId === null) $exists->where('company_id IS NULL');
        else                     $exists->where('company_id', $companyId);
        if ($exists->where('deleted_at IS NULL')->countAllResults() > 0) {
            return $this->fail('Kode menu sudah dipakai', 422, 'DUPLICATE_CODE');
        }

        $row = [
            'company_id'       => $companyId,
            'parent_id'        => ! empty($body['parent_id']) ? (int) $body['parent_id'] : null,
            'type'             => $body['type'],
            'code'             => trim($body['code']),
            'label'            => trim($body['label']),
            'translation_key'  => $body['translation_key'] ?? null,
            'icon'             => $body['icon'] ?? null,
            'route_path'       => $body['route_path'] ?? null,
            'permission'       => $body['permission'] ?? null,
            'super_admin_only' => ! empty($body['super_admin_only']) ? 1 : 0,
            'sort_order'       => (int) ($body['sort_order'] ?? 0),
            'is_active'        => isset($body['is_active']) ? (int) ((bool) $body['is_active']) : 1,
        ];
        $id = (new MenuModel())->insert($row, true);
        Services::audit()->forModule('MENU')->logCreate('menu', (int) $id, $row, 'Menu dibuat: ' . $row['code']);
        return $this->ok($this->findMenuForActiveTenant(new MenuModel(), (int) $id), 'Menu dibuat', 201);
    }

    /**
     * PUT /menus/(:num) — kalau menu yang di-edit masih global (company_id
     * NULL) tetapi user bukan super admin, lakukan fork dulu (clone ke
     * scope company aktif) supaya edit lokal tidak menyentuh default.
     */
    public function update($id = null)
    {
        $id = (int) $id;
        $model = new MenuModel();
        $row = $this->findMenuForActiveTenant($model, $id);
        if (! $row) return $this->fail('Menu tidak ditemukan', 404);
        if (! $this->canAccessRow($row)) return $this->fail('Forbidden', 403);

        $body = $this->getJsonInput();
        $valid = $this->validateOrFail($body, [
            'type'       => 'permit_empty|in_list[SECTION,PARENT,ITEM]',
            'code'       => 'permit_empty|max_length[100]',
            'label'      => 'permit_empty|max_length[150]',
            'parent_id'  => 'permit_empty|integer',
            'route_path' => 'permit_empty|max_length[255]',
            'permission' => 'permit_empty|max_length[100]',
            'icon'       => 'permit_empty|max_length[80]',
            'translation_key' => 'permit_empty|max_length[150]',
            'sort_order' => 'permit_empty|integer',
        ]);
        if (! $valid) return null;

        // Fork global → company kalau perlu
        $targetId = $this->maybeForkGlobal($row);
        $row = $this->findMenuForActiveTenant($model, $targetId);

        $update = [];
        $allowedKeys = ['type', 'code', 'label', 'translation_key', 'icon',
                        'route_path', 'permission', 'super_admin_only',
                        'sort_order', 'is_active', 'parent_id'];
        foreach ($allowedKeys as $k) {
            if (! array_key_exists($k, $body)) continue;
            if ($k === 'parent_id') {
                $update[$k] = empty($body[$k]) ? null : (int) $body[$k];
                continue;
            }
            if ($k === 'super_admin_only' || $k === 'is_active') {
                $update[$k] = (int) ((bool) $body[$k]);
                continue;
            }
            $update[$k] = $body[$k] === '' ? null : $body[$k];
        }
        if (empty($update)) return $this->fail('Tidak ada perubahan', 422);

        $model->update($targetId, $update);
        Services::audit()->forModule('MENU')->logUpdate('menu', $targetId, $row, $update, 'Menu diperbarui: ' . ($row['code'] ?? ''));
        return $this->ok($this->findMenuForActiveTenant($model, $targetId), 'Menu diperbarui');
    }

    /**
     * DELETE /menus/(:num) — soft delete. Anak-anak ikut di-soft-delete
     * supaya tidak ada orphan.
     */
    public function delete($id = null)
    {
        $id = (int) $id;
        $model = new MenuModel();
        $row = $this->findMenuForActiveTenant($model, $id);
        if (! $row) return $this->fail('Menu tidak ditemukan', 404);
        if (! $this->canAccessRow($row)) return $this->fail('Forbidden', 403);

        $targetId = $this->maybeForkGlobal($row);

        // Cascade soft delete ke seluruh keturunan
        $ids = $this->collectDescendantIds($targetId);
        $ids[] = $targetId;
        $model->whereIn('id', $ids)->delete();

        Services::audit()->forModule('MENU')->logDelete('menu', $targetId, $row, 'Menu dihapus: ' . ($row['code'] ?? ''));
        return $this->ok(['deleted_ids' => $ids], 'Menu dihapus');
    }

    /**
     * POST /menus/reorder — body: { items: [{id, parent_id, sort_order}, ...] }
     * Update batch urutan & parent. Kalau ada baris global yang ke-touch,
     * fork-kan dulu supaya safe untuk multi-tenant.
     */
    public function reorder()
    {
        $body = $this->getJsonInput();
        $items = $body['items'] ?? [];
        if (! is_array($items) || empty($items)) {
            return $this->fail('items harus array tidak kosong', 422);
        }

        $model = new MenuModel();
        $touched = [];
        foreach ($items as $it) {
            $id = (int) ($it['id'] ?? 0);
            if ($id <= 0) continue;
            $row = $this->findMenuForActiveTenant($model, $id);
            if (! $row || ! $this->canAccessRow($row)) continue;

            $targetId = $this->maybeForkGlobal($row);
            $update = [
                'sort_order' => (int) ($it['sort_order'] ?? 0),
                'parent_id'  => empty($it['parent_id']) ? null : (int) $it['parent_id'],
            ];
            $model->update($targetId, $update);
            $touched[] = $targetId;
        }
        // Reorder tidak punya helper khusus; pakai log() langsung.
        // Signature: action, entity, entityId, before, after, metadata,
        // changes, description.
        Services::audit()->forModule('MENU')->log(
            'menu.reorder',                  // action
            'menu',                          // entity
            null,                            // entityId (multi-row)
            null,                            // before
            null,                            // after
            ['touched_ids' => $touched],     // metadata
            null,                            // changes
            'Urutan menu diubah',            // description
        );
        return $this->ok(['updated' => $touched], 'Urutan menu diperbarui');
    }

    // ─── Helpers ────────────────────────────────────────────────

    /** Connection helper. */
    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    /**
     * Cek keberadaan tabel `menus`. Hasil di-cache static supaya request
     * yang sama tidak SHOW TABLES berulang. Reset cache cukup dengan
     * restart proses (deploy) atau migrate.
     */
    private function menusTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) return $exists;
        try {
            $exists = $this->db()->tableExists('menus');
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $exists;
    }

    /**
     * Cek user boleh akses row. Aturan:
     *   - Super admin: boleh semua.
     *   - Row global (company_id NULL): hanya super admin yg bisa edit;
     *     user biasa tetap boleh "lihat", tapi saat edit akan auto fork.
     *   - Row punya company tertentu: harus sama dengan tenant.companyId.
     */
    private function canAccessRow(array $row): bool
    {
        $tenant = $this->tenant();
        if ($tenant->isSuperAdmin()) return true;
        if ($row['company_id'] === null) return true;
        return (int) $row['company_id'] === (int) $tenant->getCompanyId();
    }

    private function findMenuForActiveTenant(MenuModel $model, int $id): ?array
    {
        $tenant = $this->tenant();
        return $model->findForTenantOrGlobal(
            $id,
            $tenant->getCompanyId(),
            $tenant->isSuperAdmin(),
        );
    }

    /**
     * Kalau row masih global (company_id NULL) dan user bukan super admin,
     * clone row ke scope company aktif. Anak-anak tidak ikut di-fork
     * sampai admin mengeditnya juga (lazy fork). Ini menjaga overhead
     * minimal sambil memungkinkan local override.
     *
     * Return: ID row yang harus di-update (bisa baru kalau di-fork).
     */
    private function maybeForkGlobal(array $row): int
    {
        $tenant = $this->tenant();
        if ($row['company_id'] !== null) return (int) $row['id'];
        if ($tenant->isSuperAdmin()) return (int) $row['id']; // super admin edit global langsung

        $companyId = $tenant->getCompanyId();
        if ($companyId <= 0) return (int) $row['id'];

        // Cek kalau sudah pernah di-fork (cari row dengan code sama di company)
        $existing = $this->db()->table('menus')
            ->where('company_id', $companyId)
            ->where('code', $row['code'])
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if ($existing) return (int) $existing['id'];

        // Buat clone
        $clone = $row;
        unset($clone['id'], $clone['created_at'], $clone['updated_at'], $clone['deleted_at']);
        $clone['company_id'] = $companyId;
        $clone['created_at'] = date('Y-m-d H:i:s');
        $clone['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->table('menus')->insert($clone);
        return (int) $this->db()->insertID();
    }

    /**
     * Kumpulkan ID semua keturunan (recursive). Pakai BFS supaya cepat
     * dan tidak stack-overflow di tree dalam.
     */
    private function collectDescendantIds(int $rootId): array
    {
        $out = [];
        $queue = [$rootId];
        while ($queue) {
            $batch = $queue;
            $queue = [];
            $children = $this->db()->table('menus')
                ->select('id')
                ->whereIn('parent_id', $batch)
                ->where('deleted_at IS NULL')
                ->get()->getResultArray();
            foreach ($children as $c) {
                $cid = (int) $c['id'];
                $out[] = $cid;
                $queue[] = $cid;
            }
        }
        return $out;
    }

    /**
     * Load daftar menu efektif untuk satu company:
     *   - Ambil semua row company tersebut (override).
     *   - Lengkapi dengan row global yang `code` tidak ter-override.
     *
     * Untuk admin mode (manage page) kita selalu kembalikan kedua source
     * supaya admin bisa lihat baseline + override.
     */
    private function loadEffectiveMenus(int $companyId, bool $adminMode = false): array
    {
        $db = $this->db();

        // Ambil global
        $globals = $db->table('menus')
            ->where('company_id IS NULL')
            ->where('deleted_at IS NULL')
            ->orderBy('parent_id', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        if ($companyId <= 0) {
            // Super admin tanpa pilih company → cuma lihat global
            return $globals;
        }

        $localRows = $db->table('menus')
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->orderBy('parent_id', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        if (empty($localRows)) {
            return $globals;
        }

        // Index local by code
        $localByCode = [];
        foreach ($localRows as $r) {
            $localByCode[$r['code']] = $r;
        }

        // Saat sebuah parent global sudah di-fork ke company, child global
        // baru masih menyimpan parent_id global. Remap ke parent lokal agar
        // child tersebut tidak menjadi orphan pada runtime tree.
        $globalCodeById = [];
        foreach ($globals as $g) {
            $globalCodeById[(int) $g['id']] = $g['code'];
        }

        // Untuk admin mode, kembalikan semuanya (global + local) supaya
        // tampak baseline; saat render frontend dia akan bedakan.
        if ($adminMode) {
            return array_merge(
                array_filter($globals, fn ($g) => ! isset($localByCode[$g['code']])),
                $localRows,
            );
        }

        // Untuk runtime: prefer local. Global yang sudah ada local-nya
        // di-skip supaya tidak dobel.
        $merged = $localRows;
        foreach ($globals as $g) {
            if (isset($localByCode[$g['code']])) continue;
            $globalParentId = (int) ($g['parent_id'] ?? 0);
            if ($globalParentId > 0) {
                $parentCode = $globalCodeById[$globalParentId] ?? null;
                if ($parentCode && isset($localByCode[$parentCode])) {
                    $g['parent_id'] = (int) $localByCode[$parentCode]['id'];
                }
            }
            $merged[] = $g;
        }
        return $merged;
    }

    /**
     * Susun tree dari flat list dan buang SECTION/PARENT yang akhirnya
     * tidak punya anak ITEM (mis. karena difilter permission).
     *
     * @param array<int,array> $rowsById key = id
     * @return array<int,array>
     */
    private function buildTreeAndPrune(array $rowsById): array
    {
        $byParent = [];
        foreach ($rowsById as $row) {
            $pid = $row['parent_id'] ?: 0;
            $byParent[$pid][] = $row;
        }
        // Sort tiap bucket by sort_order, lalu id
        foreach ($byParent as &$arr) {
            usort($arr, function ($a, $b) {
                $cmp = (int) $a['sort_order'] <=> (int) $b['sort_order'];
                return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
            });
        }
        unset($arr);

        $build = function (int $parentId) use (&$build, $byParent) {
            $nodes = $byParent[$parentId] ?? [];
            $out = [];
            foreach ($nodes as $n) {
                $children = $build((int) $n['id']);
                $type = $n['type'];
                // Buang SECTION/PARENT yang tidak punya anak terpakai
                if (($type === 'SECTION' || $type === 'PARENT') && empty($children)) {
                    continue;
                }
                $n['children'] = $children;
                $out[] = $this->shapeForClient($n);
            }
            return $out;
        };
        return $build(0);
    }

    /**
     * Susun tree apa adanya tanpa pruning (untuk admin manage view).
     */
    private function buildTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] ?: 0;
            $byParent[$pid][] = $r;
        }
        foreach ($byParent as &$arr) {
            usort($arr, function ($a, $b) {
                $cmp = (int) $a['sort_order'] <=> (int) $b['sort_order'];
                return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
            });
        }
        unset($arr);
        $build = function (int $parentId) use (&$build, $byParent) {
            $nodes = $byParent[$parentId] ?? [];
            $out = [];
            foreach ($nodes as $n) {
                $n['children'] = $build((int) $n['id']);
                $out[] = $n;
            }
            return $out;
        };
        return $build(0);
    }

    /**
     * Bentuk row untuk dikirim ke client — drop kolom internal yang
     * tidak perlu dibawa-bawa di payload sidebar.
     */
    private function shapeForClient(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'parent_id'       => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'type'            => $r['type'],
            'code'            => $r['code'],
            'label'           => $r['label'],
            'translation_key' => $r['translation_key'] ?: null,
            'icon'            => $r['icon'] ?: null,
            'route_path'      => $r['route_path'] ?: null,
            'children'        => $r['children'] ?? [],
        ];
    }
}
