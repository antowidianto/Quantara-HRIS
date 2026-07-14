<?php

namespace App\Controllers\Api\V1\Backup;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Backup\DataExportService;
use Config\Database;

/**
 * S2.3 — Data Export controller (UU PDP Pasal 16 + GDPR Art. 20).
 *
 * Endpoint:
 *   POST /api/v1/companies/me/data-exports        — buat request export baru
 *   GET  /api/v1/companies/me/data-exports        — list export request milik tenant
 *   GET  /api/v1/companies/me/data-exports/{id}   — detail status
 *   GET  /api/v1/data-exports/download?token=…    — public download (token-protected)
 *
 * Permission:
 *   - create / list / show:  butuh super_company_admin (lihat tenant->isSuperAdmin
 *     untuk Quantara internal, atau permission `data_export.manage` untuk tenant).
 *   - download: token-based, no JWT required (token expire 7 hari).
 *
 * Audit log severity HIGH karena ekspor PII massal.
 */
class DataExportController extends ApiController
{
    /**
     * Permission check kombinasi: super admin Quantara, atau user tenant
     * dengan permission `data_export.manage`.
     */
    private function requireExportPermission(): bool
    {
        $tenant = $this->tenant();
        if ($tenant->isSuperAdmin()) {
            return true;
        }
        // Cek permission tenant — fallback ke role super_company_admin kalau
        // permission slug belum di-seed.
        if (method_exists($tenant, 'hasPermission') && $tenant->hasPermission('data_export.manage')) {
            return true;
        }
        if (method_exists($tenant, 'getRole')) {
            $role = strtolower((string) $tenant->getRole());
            if (in_array($role, ['super_company_admin', 'super_admin', 'company_admin'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * POST /api/v1/companies/me/data-exports
     * Body (opsional): { "include": ["employees", "payslips"] }
     */
    public function create()
    {
        if (! $this->requireExportPermission()) {
            return $this->fail('Hanya super company admin yang boleh request data export', 403, 'FORBIDDEN');
        }

        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }
        $userId = $this->userId() ?: 0;

        $payload = $this->getJsonInput();
        $scope = null;
        if (! empty($payload['include']) && is_array($payload['include'])) {
            $allowed = array_keys(DataExportService::ALLOWED_TABLES);
            $clean   = array_values(array_intersect($payload['include'], $allowed));
            $scope   = ['include' => $clean];
        }

        // Throttle: maksimal 1 export aktif per tenant supaya tidak overload
        $db = Database::connect();
        $active = $db->table('data_export_requests')
            ->where('company_id', $companyId)
            ->whereIn('status', ['QUEUED', 'RUNNING'])
            ->countAllResults();
        if ($active > 0) {
            return $this->fail('Ada export request yang masih berjalan. Tunggu sampai selesai.', 409, 'EXPORT_IN_PROGRESS');
        }

        $svc = new DataExportService();
        $id  = $svc->createRequest($companyId, $userId, $scope);

        $this->writeAudit($companyId, $userId, 'data_export.requested', $id, ['scope' => $scope]);

        // Eksekusi sync untuk demo / dev. Production: ganti ke queue
        $runMode = (string) env('EXPORT_RUN_MODE', 'sync'); // sync | queue
        if ($runMode === 'sync') {
            try {
                $svc->execute($id);
            } catch (\Throwable $e) {
                log_message('error', '[DataExport] sync execute failed: ' . $e->getMessage());
                return $this->fail('Export gagal: ' . $e->getMessage(), 500, 'EXPORT_FAILED');
            }
        } else {
            // Queue mode: dispatch via QueueService kalau ada
            try {
                if (class_exists('\\App\\Libraries\\Queue\\QueueService')
                    && class_exists('\\App\\Jobs\\ExportTenantDataJob')) {
                    $queue = new \App\Libraries\Queue\QueueService();
                    $queue->dispatch('\\App\\Jobs\\ExportTenantDataJob', [
                        'request_id' => $id,
                        'company_id' => $companyId,
                    ], 'default');
                }
            } catch (\Throwable $e) {
                log_message('warning', '[DataExport] queue dispatch failed: ' . $e->getMessage());
            }
        }

        return $this->ok($this->loadRequestSafe($id), 'Export request created', 201);
    }

    /**
     * GET /api/v1/companies/me/data-exports
     */
    public function index()
    {
        if (! $this->requireExportPermission()) {
            return $this->fail('Forbidden', 403, 'FORBIDDEN');
        }
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }

        $db = Database::connect();
        $rows = $db->table('data_export_requests')
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $rows = array_map([$this, 'sanitizeRow'], $rows);
        return $this->ok($rows);
    }

    /**
     * GET /api/v1/companies/me/data-exports/{id}
     */
    public function show(int $id)
    {
        if (! $this->requireExportPermission()) {
            return $this->fail('Forbidden', 403, 'FORBIDDEN');
        }
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('Tenant context unavailable', 401, 'NO_TENANT');
        }

        $db = Database::connect();
        $req = $db->table('data_export_requests')->where('id', $id)->get()->getRowArray();
        if (! $req || (int) $req['company_id'] !== $companyId) {
            return $this->fail('Export request not found', 404, 'NOT_FOUND');
        }

        $fullToken = (string) ($req['download_token'] ?? '');
        $sanitized = $this->sanitizeRow($req);

        if ($req['status'] === 'READY' && $req['expires_at'] >= date('Y-m-d H:i:s') && $fullToken !== '') {
            $sanitized['download_url'] = base_url('api/v1/data-exports/download?token=' . $fullToken);
        }

        return $this->ok($sanitized);
    }

    /**
     * GET /api/v1/data-exports/download?token=…
     * PUBLIC endpoint dengan token validation.
     */
    public function download()
    {
        $token = (string) $this->request->getGet('token');
        if ($token === '') {
            return $this->fail('Token required', 422, 'TOKEN_REQUIRED');
        }

        $db = Database::connect();
        $req = $db->table('data_export_requests')
            ->where('download_token', $token)
            ->get()
            ->getRowArray();

        if (! $req) {
            return $this->fail('Invalid token', 404, 'INVALID_TOKEN');
        }
        if ($req['status'] !== 'READY') {
            return $this->fail('Export belum siap. Status: ' . $req['status'], 409, 'NOT_READY');
        }
        if ($req['expires_at'] < date('Y-m-d H:i:s')) {
            $db->table('data_export_requests')->where('id', $req['id'])->update([
                'status'     => 'EXPIRED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->fail('Token sudah expired', 410, 'EXPIRED');
        }

        $path = (string) ($req['file_path'] ?? '');
        if (! $path || ! file_exists($path)) {
            return $this->fail('File backup tidak ditemukan di server', 404, 'FILE_NOT_FOUND');
        }

        $this->writeAudit((int) $req['company_id'], (int) $req['requested_by'], 'data_export.downloaded', (int) $req['id']);

        return $this->response
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate')
            ->setBody(file_get_contents($path));
    }

    /**
     * Hide file_path & mask download_token in API responses.
     */
    private function sanitizeRow(array $r): array
    {
        unset($r['file_path']);
        if (! empty($r['download_token'])) {
            $r['download_token'] = '…' . substr((string) $r['download_token'], -8);
        }
        return $r;
    }

    private function loadRequestSafe(int $id): ?array
    {
        $db = Database::connect();
        $row = $db->table('data_export_requests')->where('id', $id)->get()->getRowArray();
        return $row ? $this->sanitizeRow($row) : null;
    }

    private function writeAudit(int $companyId, int $userId, string $event, int $requestId, array $meta = []): void
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }
            $db->table('audit_logs')->insert([
                'company_id'    => $companyId,
                'user_id'       => $userId,
                'event'         => $event,
                'entity_type'   => 'data_export_requests',
                'entity_id'     => $requestId,
                'severity'      => 'HIGH',
                'metadata_json' => json_encode($meta),
                'ip_address'    => $this->request->getIPAddress(),
                'user_agent'    => (string) $this->request->getUserAgent(),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[DataExport] audit insert failed: ' . $e->getMessage());
        }
    }
}
