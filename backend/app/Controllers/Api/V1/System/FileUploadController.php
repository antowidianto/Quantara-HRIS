<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * FileUploadController — endpoint upload generik untuk 4 modul.
 *
 * Endpoint: POST /api/v1/upload?context=<ticket|candidate_doc|employee_doc|training_cert>&entity_id=<id>
 *
 * Body: multipart/form-data dengan field `file`. Optional: `title`, `notes`.
 *
 * Workflow:
 *   1. Validasi context + entity ownership (multi-tenant scope)
 *   2. Validasi MIME + size sesuai context whitelist
 *   3. Simpan via FileStorage abstraction
 *   4. Insert reference ke tabel attachment yang sesuai
 *   5. Return signed URL untuk preview
 *
 * Lihat docs/AUDIT.md item I.
 */
class FileUploadController extends ApiController
{
    /**
     * Whitelist per context. Format:
     *   table          → tabel tujuan untuk reference
     *   parent_table   → tabel entity (untuk validasi ownership)
     *   parent_col     → kolom FK
     *   path_prefix    → folder di storage
     *   allowed_mime   → mime types yang diizinkan
     *   max_bytes      → ukuran max
     *   permission     → permission yang dibutuhkan (opsional, default berdasar role)
     */
    private const CONTEXTS = [
        'ticket' => [
            'table'        => 'helpdesk_ticket_attachments',
            'parent_table' => 'helpdesk_tickets',
            'parent_col'   => 'ticket_id',
            'path_prefix'  => 'tickets',
            'allowed_mime' => ['image/jpeg','image/png','image/webp','application/pdf'],
            'max_bytes'    => 10 * 1024 * 1024, // 10 MB
        ],
        'candidate_doc' => [
            'table'        => 'candidate_documents',
            'parent_table' => 'candidates',
            'parent_col'   => 'candidate_id',
            'path_prefix'  => 'candidates',
            'allowed_mime' => ['application/pdf','image/jpeg','image/png',
                'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'max_bytes'    => 15 * 1024 * 1024, // 15 MB (CV bisa lebih besar)
        ],
        'employee_doc' => [
            'table'        => 'employee_documents',
            'parent_table' => 'employees',
            'parent_col'   => 'employee_id',
            'path_prefix'  => 'employees',
            'allowed_mime' => ['application/pdf','image/jpeg','image/png','image/webp'],
            'max_bytes'    => 10 * 1024 * 1024,
        ],
        'training_cert' => [
            'table'        => 'training_certificates',
            'parent_table' => 'employees',
            'parent_col'   => 'employee_id',
            'path_prefix'  => 'certificates',
            'allowed_mime' => ['application/pdf','image/jpeg','image/png'],
            'max_bytes'    => 10 * 1024 * 1024,
        ],
    ];

    public function upload()
    {
        $context  = (string) $this->request->getGet('context');
        $entityId = (int) $this->request->getGet('entity_id');

        if (! isset(self::CONTEXTS[$context])) {
            return $this->fail('Context tidak dikenal. Pilih: ' . implode(', ', array_keys(self::CONTEXTS)), 400);
        }
        if ($entityId <= 0) {
            return $this->fail('entity_id wajib', 400);
        }

        $spec = self::CONTEXTS[$context];

        // 1. Validasi ownership entity (multi-tenant safe)
        $db = \Config\Database::connect();
        if (! $db->tableExists($spec['parent_table'])) {
            return $this->fail("Tabel parent {$spec['parent_table']} tidak ada di skema saat ini", 400);
        }
        $parent = $db->table($spec['parent_table'])->where('id', $entityId)->get()->getRowArray();
        if (! $parent) {
            return $this->fail('Entity parent tidak ditemukan', 404);
        }
        if (! $this->tenant()->isSuperAdmin()
            && isset($parent['company_id'])
            && (int) $parent['company_id'] !== $this->companyId()
        ) {
            return $this->fail('Akses ditolak (multi-tenant scope)', 403);
        }

        // 2. Validasi file
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File upload tidak valid: ' . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        if ($file->getSize() > $spec['max_bytes']) {
            return $this->fail(sprintf(
                'Ukuran file %s melebihi batas %d MB',
                round($file->getSize() / 1024 / 1024, 2),
                $spec['max_bytes'] / 1024 / 1024
            ), 413);
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, $spec['allowed_mime'], true)) {
            return $this->fail(
                'MIME tidak diizinkan: ' . $mime . '. Allowed: ' . implode(', ', $spec['allowed_mime']),
                415
            );
        }

        // 3. Simpan via FileStorage
        $storage = Services::fileStorage();
        $ext = pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: 'bin';
        $relativePath = sprintf(
            '%s/%d/%s_%s.%s',
            $spec['path_prefix'],
            $entityId,
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
            preg_replace('/[^A-Za-z0-9]+/', '', strtolower($ext)),
        );
        $bytes = file_get_contents($file->getTempName());
        if ($bytes === false || ! $storage->put($relativePath, $bytes)) {
            return $this->fail('Gagal menyimpan file', 500);
        }

        // 4. Insert ke tabel attachment
        $now = date('Y-m-d H:i:s');
        $title = trim((string) ($this->request->getPost('title') ?? '')) ?: $file->getClientName();
        $notes = trim((string) ($this->request->getPost('notes') ?? '')) ?: null;

        $insert = [
            $spec['parent_col'] => $entityId,
            'title'             => mb_substr($title, 0, 200),
            'notes'             => $notes,
            'file_path'         => $relativePath,
            'file_mime'         => $mime,
            'file_size'         => $file->getSize(),
            'uploaded_by'       => $this->userId(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
        // Inherit company_id kalau parent punya
        if (isset($parent['company_id'])) {
            $insert['company_id'] = (int) $parent['company_id'];
        }

        // Cek field yang ada di tabel attachment, biar tidak error untuk
        // schema yang sedikit beda antar modul
        $existingFields = $db->getFieldNames($spec['table']);
        $insert = array_intersect_key($insert, array_flip($existingFields));

        if (! $db->table($spec['table'])->insert($insert)) {
            // Cleanup file kalau insert DB gagal supaya tidak orphan
            $storage->delete($relativePath);
            return $this->fail('Gagal menyimpan referensi file ke database', 500);
        }
        $attachmentId = (int) $db->insertID();

        // 5. Audit + return signed URL
        Services::audit()->forModule(strtoupper($context))->logCreate(
            $spec['table'],
            $attachmentId,
            $insert,
            "Upload file '{$title}' ({$context} #{$entityId}, " . round($file->getSize() / 1024) . ' KB)'
        );

        return $this->ok([
            'id'         => $attachmentId,
            'title'      => $title,
            'file_path'  => $relativePath,
            'file_mime'  => $mime,
            'file_size'  => $file->getSize(),
            'signed_url' => $storage->signedUrl($relativePath, 600), // 10 menit
        ], 'File terupload', 201);
    }

    /**
     * Whitelist context untuk attachment sederhana yang ditempel ke field
     * `attachment_path` / `receipt_path` / `evidence_path` di tabel utama
     * (cuti, lembur, reimbursement, change-request, dst.) — TIDAK pakai
     * tabel attachment terpisah.
     *
     * Kunci penting: endpoint ini boleh dipanggil SEBELUM parent record dibuat
     * (mis. user upload bukti sakit dulu, baru klik "Ajukan Cuti"). Karena itu
     * tidak ada validasi entity_id di sini — hanya MIME + size + tenant scope.
     */
    private const ATTACHMENT_CONTEXTS = [
        'leave'          => ['prefix' => 'leaves',      'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'overtime'       => ['prefix' => 'overtimes',   'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'reimbursement'  => ['prefix' => 'reimburse',   'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'loan'           => ['prefix' => 'loans',       'mime' => ['application/pdf','image/jpeg','image/png'],               'max' => 5 * 1024 * 1024],
        'change_request' => ['prefix' => 'attendance-changes', 'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'attendance'     => ['prefix' => 'attendance-corr',    'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'trip_expense'   => ['prefix' => 'trip-receipts','mime'=> ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'trip'           => ['prefix' => 'trips',       'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'asset'          => ['prefix' => 'assets',      'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 8 * 1024 * 1024],
        'task_evidence'  => ['prefix' => 'task-evidence','mime'=> ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
        'severance'      => ['prefix' => 'severance',   'mime' => ['application/pdf','image/jpeg','image/png'],               'max' => 5 * 1024 * 1024],
        'disciplinary'   => ['prefix' => 'disciplinary','mime' => ['application/pdf','image/jpeg','image/png'],               'max' => 5 * 1024 * 1024],
        'training'       => ['prefix' => 'training',    'mime' => ['application/pdf','image/jpeg','image/png'],               'max' => 8 * 1024 * 1024],
        'helpdesk'       => ['prefix' => 'tickets',     'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 10 * 1024 * 1024],
        'recruitment'    => ['prefix' => 'recruitment', 'mime' => ['application/pdf','application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','image/png'], 'max' => 15 * 1024 * 1024],
        'misc'           => ['prefix' => 'misc',        'mime' => ['application/pdf','image/jpeg','image/png','image/webp'], 'max' => 5 * 1024 * 1024],
    ];

    /**
     * Upload attachment sederhana — return path saja, caller yang masukkan ke
     * payload modul (mis. body POST /leaves dengan `attachment_path` di-set).
     *
     * Endpoint:  POST /api/v1/upload/attachment?context=<key>
     * Body:      multipart/form-data dengan field `file`
     * Response:  { path, signed_url, mime, size, original_name }
     *
     * File disimpan di `writable/uploads/<prefix>/<company_id>/<user_id>/...`
     * supaya cleanup per-user/per-company mudah, dan tidak ada user A yang
     * bisa nebak path file user B (karena segmen random 8 hex).
     */
    public function uploadAttachment()
    {
        $context = strtolower((string) $this->request->getGet('context'));
        if (! isset(self::ATTACHMENT_CONTEXTS[$context])) {
            return $this->fail('Context tidak dikenal. Pilih salah satu: '
                . implode(', ', array_keys(self::ATTACHMENT_CONTEXTS)), 400);
        }
        $spec = self::ATTACHMENT_CONTEXTS[$context];

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('File upload tidak valid: '
                . ($file?->getErrorString() ?? 'tidak ada file'), 400);
        }
        if ($file->getSize() > $spec['max']) {
            return $this->fail(sprintf(
                'Ukuran file %.1f MB melebihi batas %d MB',
                $file->getSize() / 1024 / 1024,
                $spec['max'] / 1024 / 1024,
            ), 413);
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, $spec['mime'], true)) {
            return $this->fail(
                'Tipe file tidak diizinkan. Allowed: ' . implode(', ', $spec['mime']),
                415
            );
        }

        $companyId = (int) ($this->companyId() ?? 0) ?: 'shared';
        $userId    = (int) ($this->userId() ?? 0) ?: 'guest';
        $ext = pathinfo($file->getClientName(), PATHINFO_EXTENSION) ?: 'bin';
        $ext = preg_replace('/[^A-Za-z0-9]+/', '', strtolower($ext));

        $relativePath = sprintf(
            '%s/%s/%s/%s_%s.%s',
            $spec['prefix'],
            $companyId,
            $userId,
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
            $ext,
        );

        $storage = Services::fileStorage();
        $bytes = file_get_contents($file->getTempName());
        if ($bytes === false || ! $storage->put($relativePath, $bytes)) {
            return $this->fail('Gagal menyimpan file', 500);
        }

        // Audit log NOTICE — supaya bisa trace upload yang akhirnya tidak
        // dipakai (orphan) untuk dibersihkan command cleanup-attachments.
        Services::audit()->forModule(strtoupper($context))->log(
            action: 'attachment.upload',
            entity: 'attachment',
            description: "Upload {$context} attachment: " . $file->getClientName()
                . ' (' . round($file->getSize() / 1024) . ' KB)',
            severity: 'NOTICE',
        );

        return $this->ok([
            'path'          => $relativePath,
            'signed_url'    => $storage->signedUrl($relativePath, 600),
            'mime'          => $mime,
            'size'          => $file->getSize(),
            'original_name' => $file->getClientName(),
        ], 'File terupload', 201);
    }
}
