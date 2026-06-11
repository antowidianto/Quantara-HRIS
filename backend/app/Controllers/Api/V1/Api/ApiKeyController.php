<?php

namespace App\Controllers\Api\V1\Api;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Api\ApiKeyService;
use Config\Database;

/**
 * H3.8 — API Key Management Controller (Developer Portal).
 *
 * Endpoints (semua butuh JWT user — bukan API key auth):
 *   GET    /api/v1/api-keys
 *   POST   /api/v1/api-keys
 *   GET    /api/v1/api-keys/{id}
 *   POST   /api/v1/api-keys/{id}/rotate
 *   POST   /api/v1/api-keys/{id}/revoke
 *   GET    /api/v1/api-keys/{id}/usage           ?since=
 *
 *   GET    /api/v1/api-scopes                    (catalog publik untuk UI picker)
 */
class ApiKeyController extends ApiController
{
    private ApiKeyService $service;

    public function __construct()
    {
        $this->service = new ApiKeyService();
    }

    public function listScopes()
    {
        $rows = Database::connect()->table('api_scopes')
            ->orderBy('group_label', 'ASC')
            ->orderBy('scope', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function listKeys()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $rows = Database::connect()->table('api_keys')
            ->select('id, name, key_prefix, tier, rate_limit_per_minute, is_sandbox, last_used_at, last_used_ip, expires_at, revoked_at, created_at')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();
        // Attach scopes
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $scopes = [];
        if ($ids !== []) {
            $rs = Database::connect()->table('api_key_scopes')
                ->whereIn('api_key_id', $ids)->get()->getResultArray();
            foreach ($rs as $s) {
                $scopes[(int) $s['api_key_id']][] = $s['scope'];
            }
        }
        foreach ($rows as &$r) {
            $r['scopes'] = $scopes[(int) $r['id']] ?? [];
        }
        return $this->ok($rows);
    }

    public function createKey()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'name'   => 'required|min_length[3]|max_length[200]',
            'tier'   => 'permit_empty|in_list[BASIC,PRO,ENTERPRISE]',
            'scopes' => 'required',
        ]);
        if ($body === null) return null;
        if (! is_array($body['scopes']) || $body['scopes'] === []) {
            return $this->fail('scopes minimal 1', 422, 'VALIDATION_ERROR', ['scopes' => 'minimal 1']);
        }

        try {
            $result = $this->service->generate(
                $companyId,
                $this->userId(),
                (string) $body['name'],
                (array)  $body['scopes'],
                (string) ($body['tier'] ?? 'BASIC'),
                (bool)   ($body['is_sandbox'] ?? false),
                $body['expires_at'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'CREATE_FAILED');
        }

        // SECURITY: plain_key hanya tampil sekali. UI harus tunjukkan
        // dengan jelas dan tidak boleh re-fetch.
        return $this->ok($result, 'API key created — simpan plain_key, hanya ditampilkan sekali', 201);
    }

    public function showKey(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('api_keys')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        unset($row['key_hash']);
        $row['scopes'] = array_column(
            Database::connect()->table('api_key_scopes')->where('api_key_id', $id)->get()->getResultArray(),
            'scope'
        );
        return $this->ok($row);
    }

    public function rotateKey(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('api_keys')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');

        try {
            $result = $this->service->rotate(
                $id,
                $this->userId(),
                (string) ($this->getJsonInput()['reason'] ?? 'Manual rotation'),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'ROTATE_FAILED');
        }
        return $this->ok($result, 'Key rotated. Plain key baru hanya ditampilkan sekali.');
    }

    public function revokeKey(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('api_keys')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        if (! empty($row['revoked_at'])) {
            return $this->fail('Already revoked', 409, 'ALREADY_REVOKED');
        }
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        if ($reason === '') {
            return $this->fail('reason wajib diisi', 422, 'REASON_REQUIRED');
        }
        $this->service->revoke($id, $reason);
        return $this->ok(['id' => $id, 'revoked' => true]);
    }

    public function usage(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('api_keys')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');

        $since = (string) $this->request->getGet('since');
        $sinceTs = $since !== '' ? strtotime($since) : strtotime('-7 days');
        if ($sinceTs === false) $sinceTs = strtotime('-7 days');
        $sinceFmt = date('Y-m-d H:i:s', (int) $sinceTs);

        $db = Database::connect();
        $total = (int) $db->table('api_request_logs')
            ->where('api_key_id', $id)
            ->where('created_at >=', $sinceFmt)
            ->countAllResults();
        $rateLimited = (int) $db->table('api_request_logs')
            ->where('api_key_id', $id)
            ->where('created_at >=', $sinceFmt)
            ->where('rate_limited', 1)
            ->countAllResults();
        $byEndpoint = $db->table('api_request_logs')
            ->select('endpoint, COUNT(*) AS hits, AVG(duration_ms) AS avg_ms')
            ->where('api_key_id', $id)
            ->where('created_at >=', $sinceFmt)
            ->groupBy('endpoint')
            ->orderBy('hits', 'DESC')
            ->limit(20)
            ->get()->getResultArray();
        $byStatus = $db->table('api_request_logs')
            ->select('status_code, COUNT(*) AS hits')
            ->where('api_key_id', $id)
            ->where('created_at >=', $sinceFmt)
            ->groupBy('status_code')
            ->orderBy('hits', 'DESC')
            ->get()->getResultArray();

        return $this->ok([
            'since'        => $sinceFmt,
            'total'        => $total,
            'rate_limited' => $rateLimited,
            'by_endpoint'  => $byEndpoint,
            'by_status'    => $byStatus,
        ]);
    }
}