<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

abstract class ApiController extends BaseController
{
    protected function ok($data = null, string $message = 'OK', int $status = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function fail(string $message, int $status = 400, ?string $code = null, $errors = null): ResponseInterface
    {
        $payload = [
            'status'  => 'error',
            'message' => $message,
        ];
        if ($code) $payload['code'] = $code;
        if ($errors) $payload['errors'] = $errors;
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function paginated($items, int $total, int $page, int $perPage, string $message = 'OK'): ResponseInterface
    {
        return $this->response->setJSON([
            'status'  => 'success',
            'message' => $message,
            'data'    => $items,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    protected function tenant()
    {
        return Services::tenant();
    }

    /**
     * PrivacyScope helper — pakai untuk endpoint list/show yang menampilkan
     * data personal employee (leave, payslip, overtime, attendance, dsb).
     * Lihat docs/DATA_PRIVACY.md untuk pola penggunaan.
     */
    protected function privacy(): \App\Libraries\PrivacyScope
    {
        return Services::privacy();
    }

    protected function userId(): int
    {
        return $this->tenant()->getUserId();
    }

    /**
     * Company ID untuk operasi tulis.
     *
     * Untuk user biasa: ambil dari context tenant.
     * Untuk super admin (company_id NULL): ambil dari body request `company_id`,
     * atau dari header `X-Company-Id`. Mengembalikan 0 berarti super admin
     * belum menentukan target company — controller harus melempar error jelas.
     */
    protected function companyId(): int
    {
        $cid = $this->tenant()->getCompanyId();
        if ($cid > 0) return $cid;

        // Super admin: cek body / header
        if ($this->tenant()->isSuperAdmin() && isset($this->request)) {
            $body = $this->request->getJSON(true) ?: [];
            if (! is_array($body)) $body = $this->request->getPost();
            if (! empty($body['company_id'])) {
                return (int) $body['company_id'];
            }
            $hdr = (int) $this->request->getHeaderLine('X-Company-Id');
            if ($hdr > 0) return $hdr;
        }
        return 0;
    }

    /**
     * Pastikan ada company_id valid sebelum operasi tulis.
     * Mengembalikan ID atau null bila terkendala (response error sudah dikirim).
     */
    protected function requireCompanyId(): ?int
    {
        $id = $this->companyId();
        if ($id > 0) return $id;
        $this->fail(
            'Super admin harus memilih perusahaan target. Sertakan company_id di body atau header X-Company-Id.',
            422,
            'COMPANY_REQUIRED'
        )->send();
        return null;
    }

    protected function getJsonInput(): array
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body)) {
            $body = $this->request->getPost();
        }
        return $body ?: [];
    }

    /**
     * Validate input against rules. Returns null if invalid (response is set), array if valid.
     */
    protected function validateOrFail(array $data, array $rules): ?array
    {
        $validation = \Config\Services::validation();
        $validation->setRules($rules);
        if (! $validation->run($data)) {
            $this->fail('Validation failed', 422, 'VALIDATION_ERROR', $validation->getErrors())->send();
            return null;
        }
        return $data;
    }
}