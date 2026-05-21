<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * AuditFilter
 *
 * Memasang request_id ke setiap request masuk dan mencatat ringkasan
 * akses untuk operasi tulis (POST/PUT/PATCH/DELETE) atau respons error.
 *
 * Detail before/after CRUD tetap dicatat oleh masing-masing controller
 * lewat AuditLogger karena di sanalah konteks domain tersedia.
 */
class AuditFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Tag request dengan UUID korelasi. Header X-Request-Id diterima
        // bila client (mis. mobile app) mengirimkannya, jika tidak kita
        // generate sendiri.
        $incoming = (string) ($request->getHeaderLine('X-Request-Id') ?? '');
        $reqId    = preg_match('/^[a-f0-9-]{8,64}$/i', $incoming) === 1
            ? $incoming
            : $this->uuid();

        Services::audit()->withRequestId($reqId)->start();

        // Simpan di GLOBALS supaya controller bisa baca lewat helper jika perlu
        $GLOBALS['__request_id'] = $reqId;

        // Beri header balik agar client bisa korelasi log
        Services::response()->setHeader('X-Request-Id', $reqId);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        try {
            $method = strtoupper($request->getMethod());
            $status = $response->getStatusCode();

            // Catat hanya operasi tulis ATAU respons error.
            $isWrite = in_array($method, ['POST','PUT','PATCH','DELETE'], true);
            $isError = $status >= 400;
            if (! $isWrite && ! $isError) {
                return;
            }

            $tenant = Services::tenant();
            $reqId  = $GLOBALS['__request_id'] ?? null;

            $payload = [
                'company_id'  => $tenant->getCompanyId() ?: null,
                'user_id'     => $tenant->getUserId() ?: null,
                'action'      => 'http.request',
                'severity'    => $isError ? ($status >= 500 ? 'CRITICAL' : 'WARNING') : 'DEBUG',
                'module'      => $arguments[0] ?? 'HTTP',
                'description' => sprintf('%s %s -> %d', $method, $request->getUri()->getPath(), $status),
                'entity'      => $arguments[1] ?? null,
                'ip_address'  => $request->getIPAddress(),
                'user_agent'  => substr((string) $request->getUserAgent(), 0, 255),
                'http_method' => $method,
                'http_path'   => substr($request->getUri()->getPath(), 0, 255),
                'status_code' => $status,
                'success'     => $isError ? 0 : 1,
                'request_id'  => $reqId,
                'metadata'    => json_encode([
                    'query' => $request->getGet(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at'  => date('Y-m-d H:i:s'),
            ];

            $db = \Config\Database::connect();
            // Hanya insert kolom yang ada di skema (compatible dengan DB
            // yang belum migrasi #007).
            try {
                $existing = array_map(static fn ($f) => strtolower($f->name), $db->getFieldData('audit_logs'));
                $payload  = array_intersect_key($payload, array_flip($existing));
            } catch (\Throwable $e) {
                // ignore — fallback insert with full payload
            }

            $db->table('audit_logs')->insert($payload);
        } catch (\Throwable $e) {
            log_message('error', 'AuditFilter failed: ' . $e->getMessage());
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}