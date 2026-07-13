<?php

namespace App\Filters;

use App\Models\System\IdempotencyKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * IdempotencyFilter — Mobile Prep / MOB-3
 *
 * Anti-duplikat untuk POST critical (clock-in, leave create, reimbursement,
 * dll.). Dipasang di route per-endpoint via `['filter' => 'idempotency']`.
 *
 * Flow:
 *
 *   1. **Tidak ada header** Idempotency-Key:
 *      - GET / HEAD / OPTIONS → bypass (idempotent secara HTTP)
 *      - POST / PUT / PATCH / DELETE → ditolak ketika route memakai
 *        argumen filter `required`.
 *
 *   2. **Header ada, key BARU**: reservasi state IN_FLIGHT, lanjut ke
 *      controller. Setelah controller selesai, simpan response di tabel.
 *
 *   3. **Header ada, key SAMA + body SAMA + state COMPLETED**: replay
 *      cached response (status code + body) dari tabel. Tambah header
 *      `Idempotency-Replay: true` supaya client tahu ini bukan re-execute.
 *
 *   4. **Header ada, key SAMA + body SAMA + state IN_FLIGHT**: return
 *      409 Conflict supaya client tahu request asli masih jalan dan tidak
 *      flood retry.
 *
 *   5. **Header ada, key SAMA + body BERBEDA**: return 422 — pelanggaran
 *      kontrak idempotency (key sama harus body sama).
 *
 * Header validasi: opaque string sepanjang 8–100 karakter.
 *
 * Catatan implementasi: kita pakai 2-pass via `before` dan `after`.
 * `before` reservasi key, `after` save response. Karena CI4 filter `after`
 * masih bisa modify response, kita pakai itu untuk inject replay header.
 */
class IdempotencyFilter implements FilterInterface
{
    private const HEADER       = 'Idempotency-Key';
    private const REPLAY_HEAD  = 'Idempotency-Replay';
    private const STATUS_HEAD  = 'Idempotency-Status';
    private const MIN_LEN      = 8;
    private const MAX_LEN      = 100;
    /** Methods yang reservasi key. */
    private const ELIGIBLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        if (! in_array($method, self::ELIGIBLE_METHODS, true)) {
            return; // GET/HEAD/OPTIONS — biarkan
        }

        $rawKey = trim($request->getHeaderLine(self::HEADER));
        if ($rawKey === '') {
            if (in_array('required', $arguments ?? [], true)) {
                return $this->fail(
                    'Idempotency-Key is required for this operation',
                    400,
                    'IDEMPOTENCY_KEY_REQUIRED'
                );
            }
            // Tetap mendukung mode opsional untuk endpoint non-kritis.
            return;
        }
        if (strlen($rawKey) < self::MIN_LEN || strlen($rawKey) > self::MAX_LEN) {
            return $this->fail(
                'Idempotency-Key length must be between ' . self::MIN_LEN . ' and ' . self::MAX_LEN,
                400,
                'IDEMPOTENCY_KEY_INVALID'
            );
        }

        $companyId = (int) Services::tenant()->getCompanyId();
        if ($companyId <= 0) {
            // Filter `jwt` belum berhasil set tenant context — abort.
            // Dalam praktek IdempotencyFilter selalu di-chain setelah `jwt`,
            // jadi ini defense in depth.
            return $this->fail(
                'Tenant context is required for idempotent operations',
                400,
                'TENANT_CONTEXT_REQUIRED'
            );
        }

        $keyHash = IdempotencyKeyModel::hashKey($rawKey);
        $body    = (string) $request->getBody();
        $bodyHash = IdempotencyKeyModel::hashBody($body);
        $path    = (string) $request->getUri()->getPath();
        $userId  = (int) Services::tenant()->getUserId() ?: null;

        $model = new IdempotencyKeyModel();

        // Cek existing
        $existing = $model->findByHash($companyId, $keyHash);
        if ($existing !== null) {
            // Body harus identik — kalau beda, kontrak rusak
            if ($existing['request_hash'] !== null && $existing['request_hash'] !== $bodyHash) {
                return $this->fail(
                    'Idempotency-Key reused with different request body',
                    422,
                    'IDEMPOTENCY_KEY_MISMATCH'
                );
            }
            // Method/path harus match juga (defensive)
            if (
                strtoupper((string) $existing['method']) !== $method ||
                $existing['path'] !== $path
            ) {
                return $this->fail(
                    'Idempotency-Key reused on different endpoint',
                    422,
                    'IDEMPOTENCY_KEY_MISMATCH'
                );
            }

            if ($existing['state'] === IdempotencyKeyModel::STATE_IN_FLIGHT) {
                return $this->conflict('Original request still in-flight, retry shortly');
            }

            if (in_array($existing['state'], [
                IdempotencyKeyModel::STATE_COMPLETED,
                IdempotencyKeyModel::STATE_FAILED,
            ], true)) {
                // Replay: return cached response apa adanya
                $resp = Services::response();
                $resp->setStatusCode((int) ($existing['status_code'] ?? 200));
                $resp->setHeader(self::REPLAY_HEAD, 'true');
                $resp->setHeader(self::STATUS_HEAD, (string) $existing['state']);
                if (! empty($existing['response_headers'])) {
                    $headers = json_decode((string) $existing['response_headers'], true);
                    if (is_array($headers)) {
                        foreach ($headers as $h => $v) {
                            // Skip control headers
                            if (in_array(strtolower((string) $h), ['content-length', 'transfer-encoding'], true)) {
                                continue;
                            }
                            $resp->setHeader((string) $h, (string) $v);
                        }
                    }
                }
                $resp->setBody((string) ($existing['response_body'] ?? ''));
                return $resp;
            }
        }

        // Key baru — reservasi
        $newId = $model->reserve([
            'company_id'   => $companyId,
            'user_id'      => $userId,
            'key_hash'     => $keyHash,
            'method'       => $method,
            'path'         => $path,
            'request_hash' => $bodyHash,
        ]);

        if ($newId === false) {
            // Race lost — fetch lagi & replay sebagai IN_FLIGHT
            return $this->conflict('Concurrent request with same Idempotency-Key');
        }

        // Stash id ke request supaya `after` bisa update response
        $request->idempotencyKeyId = $newId;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $id = $request->idempotencyKeyId ?? null;
        if (! $id) {
            return;
        }
        $model = new IdempotencyKeyModel();

        $headers = [];
        foreach ($response->headers() as $h) {
            $headers[$h->getName()] = $h->getValueLine();
        }

        $model->complete(
            (int) $id,
            $response->getStatusCode(),
            (string) $response->getBody(),
            $headers,
        );
    }

    private function fail(string $message, int $status, string $code): ResponseInterface
    {
        return Services::response()
            ->setStatusCode($status)
            ->setJSON([
                'status'  => 'error',
                'code'    => $code,
                'message' => $message,
            ]);
    }

    private function conflict(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(409)
            ->setHeader(self::STATUS_HEAD, IdempotencyKeyModel::STATE_IN_FLIGHT)
            ->setJSON([
                'status'  => 'error',
                'code'    => 'IDEMPOTENCY_IN_FLIGHT',
                'message' => $message,
            ]);
    }
}
