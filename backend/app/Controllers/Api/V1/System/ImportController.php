<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Import\ImportRegistry;
use App\Libraries\Import\XlsxImportService;
use Config\Services;

/**
 * ImportController — generic bulk-import dispatcher untuk semua resource
 * yang terdaftar di ImportRegistry. Pola yang sama dipakai semua modul,
 * jadi penambahan resource baru tinggal tambah entry di ImportRegistry.
 *
 * Endpoint:
 *   GET    /api/v1/imports/resources                   — list resource yg bisa di-import
 *   GET    /api/v1/imports/(:segment)/template         — download xlsx kosong + sample
 *   POST   /api/v1/imports/(:segment)/preview          — upload + parse + validate (status PREVIEW)
 *   POST   /api/v1/imports/(:segment)/commit/(:num)    — commit batch ke DB target (status PREVIEW → COMMITTED)
 *   GET    /api/v1/imports/(:segment)/history          — list batch sebelumnya untuk resource ini
 *   GET    /api/v1/imports/batches/(:num)              — detail batch (errors, preview rows)
 *   POST   /api/v1/imports/batches/(:num)/cancel       — soft-cancel batch yg masih PREVIEW
 *
 * Permission per-resource: lihat ImportRegistry::find($slug)['permission'].
 * Audit: setiap commit di-log via AuditLogger sebagai action `<slug>.bulk_import`.
 */
class ImportController extends ApiController
{
    public function listResources()
    {
        $resources = [];
        foreach (ImportRegistry::all() as $slug => $spec) {
            $resources[] = [
                'slug'        => $slug,
                'label'       => $spec['label'],
                'permission'  => $spec['permission'],
                'allowed'     => $this->hasPermission($spec['permission']),
                'columns'     => array_map(fn ($c) => [
                    'header'   => $c['header'],
                    'field'    => $c['field'],
                    'type'     => $c['type'],
                    'required' => ! empty($c['required']),
                    'in'       => $c['in']        ?? null,
                    'fk'       => isset($c['fk_table']) ? $c['fk_table'] . '.' . $c['fk_column'] : null,
                    'help'     => $c['help']      ?? null,
                ], $spec['columns']),
                'notes_md'    => $spec['notes_md'] ?? null,
            ];
        }
        return $this->ok($resources);
    }

    /**
     * GET /imports/(:segment)/template — download xlsx template.
     */
    public function template(string $slug)
    {
        $spec = ImportRegistry::find($slug);
        if (! $spec) return $this->fail('Resource tidak dikenal', 404);
        if (! $this->hasPermission($spec['permission'])) {
            return $this->fail("Forbidden — butuh permission {$spec['permission']}", 403);
        }

        try {
            $path = Services::xlsxImport()->generateTemplate($spec);
        } catch (\Throwable $e) {
            return $this->fail('Gagal generate template: ' . $e->getMessage(), 500);
        }

        $filename = "import-template-{$slug}.xlsx";
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->setHeader('X-Filename', $filename)
            ->setBody(file_get_contents($path));
    }

    /**
     * POST /imports/(:segment)/preview — upload + validate.
     * Multipart body: field `file`.
     */
    public function preview(string $slug)
    {
        $spec = ImportRegistry::find($slug);
        if (! $spec) return $this->fail('Resource tidak dikenal', 404);
        if (! $this->hasPermission($spec['permission'])) {
            return $this->fail("Forbidden — butuh permission {$spec['permission']}", 403);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File upload tidak valid: '
                . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        if ($file->getSize() > XlsxImportService::MAX_BYTES) {
            return $this->fail(sprintf(
                'Ukuran file %.1f MB melebihi batas %d MB',
                $file->getSize() / 1024 / 1024,
                XlsxImportService::MAX_BYTES / 1024 / 1024,
            ), 413);
        }
        $ext = strtolower(pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: '');
        if ($ext !== 'xlsx') {
            return $this->fail('Hanya file .xlsx yang didukung. Untuk file .xls, save-as ke .xlsx dulu.', 415);
        }

        try {
            $result = Services::xlsxImport()->preview(
                $file->getTempName(),
                $file->getClientName(),
                $file->getSize(),
                $spec,
                $companyId,
                $this->userId(),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $msg = $result['idempotent'] ?? false
            ? 'File ini sudah pernah di-upload. Menampilkan batch sebelumnya.'
            : 'Preview siap. Cek tab Errors di bawah lalu klik Commit kalau valid.';
        return $this->ok($result, $msg);
    }

    /**
     * POST /imports/(:segment)/commit/(:num) — commit batch ke DB.
     */
    public function commit(string $slug, $batchId = null)
    {
        $spec = ImportRegistry::find($slug);
        if (! $spec) return $this->fail('Resource tidak dikenal', 404);
        if (! $this->hasPermission($spec['permission'])) {
            return $this->fail("Forbidden — butuh permission {$spec['permission']}", 403);
        }

        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        try {
            $result = Services::xlsxImport()->commit(
                (int) $batchId, $spec, $companyId, $this->userId()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($result, $result['inserted']
            . " baris berhasil di-commit ke {$spec['label']}.");
    }

    /**
     * GET /imports/(:segment)/history — list batch untuk resource.
     */
    public function history(string $slug)
    {
        $spec = ImportRegistry::find($slug);
        if (! $spec) return $this->fail('Resource tidak dikenal', 404);
        if (! $this->hasPermission($spec['permission'])) {
            return $this->fail("Forbidden", 403);
        }

        $db = \Config\Database::connect();
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));

        $b = $db->table('import_batches ib')
            ->select('ib.id, ib.resource, ib.original_name, ib.file_size, ib.status,'
                . ' ib.total_rows, ib.valid_rows, ib.error_rows, ib.inserted_count, ib.skipped_count,'
                . ' ib.created_at, ib.committed_at, ib.user_id, u.name AS user_name')
            ->join('users u', 'u.id = ib.user_id', 'left')
            ->where('ib.resource', $slug)
            ->where('ib.deleted_at IS NULL');
        $this->tenant()->applyScope($b, 'ib.company_id');

        $total = $b->countAllResults(false);
        $rows = $b->orderBy('ib.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * GET /imports/batches/(:num) — detail batch lengkap.
     */
    public function batch($batchId = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('import_batches')
            ->where('id', (int) $batchId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) return $this->fail('Batch tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        $spec = ImportRegistry::find($row['resource']);
        if (! $this->hasPermission($spec['permission'] ?? '')) {
            return $this->fail("Forbidden", 403);
        }

        $row['errors_list'] = json_decode($row['errors_json']  ?? '[]', true) ?: [];
        $previewPayload     = json_decode($row['preview_json'] ?? '{}', true) ?: [];
        $row['preview']     = $previewPayload['preview'] ?? [];
        // Sembunyikan rows full dari payload — hanya butuh saat commit.
        unset($row['errors_json'], $row['preview_json']);

        return $this->ok($row);
    }

    /**
     * POST /imports/batches/(:num)/cancel — soft-cancel batch.
     */
    public function cancel($batchId = null)
    {
        $db = \Config\Database::connect();
        $row = $db->table('import_batches')
            ->where('id', (int) $batchId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $row) return $this->fail('Batch tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->companyId()) {
            return $this->fail('Forbidden', 403);
        }
        if ($row['status'] === 'COMMITTED') {
            return $this->fail('Batch sudah COMMITTED, tidak bisa dibatalkan', 422);
        }

        $db->table('import_batches')->where('id', $row['id'])->update([
            'status'     => 'CANCELLED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Services::audit()->forModule('IMPORT')->log(
            action: 'import.cancel',
            entity: 'import_batch',
            entityId: (int) $row['id'],
            description: "Cancel import batch #{$row['id']} ({$row['resource']})",
            severity: 'NOTICE',
        );

        return $this->ok(null, 'Batch dibatalkan');
    }

    // ─── Permission helper ─────────────────────────────────────────
    private function hasPermission(string $slug): bool
    {
        if ($slug === '') return false;
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission($slug);
    }
}