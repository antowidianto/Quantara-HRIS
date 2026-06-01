<?php

namespace App\Controllers\Api\V1\System;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * FileServeController — serve file user-uploaded via signed URL.
 *
 * Endpoint: GET /api/v1/files/serve?path=<path>&expires=<unix>&token=<hmac>
 *
 * Public (tidak butuh JWT) karena tag <img> di Vue tidak bisa kirim Authorization
 * header. Keamanan dipastikan oleh signature HMAC + expiry pendek (default 5 menit
 * dari `FileStorage::signedUrl()`).
 *
 * Kontrol akses tambahan dilakukan saat URL DI-GENERATE (di controller pemilik
 * resource, mis. AnomalyController saat HR view selfie). Kalau token expired
 * → 410 Gone, kalau token tidak match → 403, kalau file tidak ada → 404.
 */
class FileServeController extends ApiController
{
    public function serve()
    {
        $path = (string) $this->request->getGet('path');
        $expires = (int) $this->request->getGet('expires');
        $token = (string) $this->request->getGet('token');

        if ($path === '' || $expires === 0 || $token === '') {
            return $this->fail('Missing parameters', 400);
        }
        if ($expires < time()) {
            return $this->fail('URL kadaluarsa', 410);
        }

        $storage = Services::fileStorage();
        if (! $storage->verifySignature($path, $token, $expires)) {
            return $this->fail('Token tidak valid', 403);
        }
        if (! $storage->exists($path)) {
            return $this->fail('File tidak ditemukan', 404);
        }

        $bytes = $storage->get($path);
        if ($bytes === null) {
            return $this->fail('Gagal baca file', 500);
        }

        // Tebak MIME dari ekstensi
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'pdf'         => 'application/pdf',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', (string) strlen($bytes))
            // Cache OK karena URL signed sudah include expiry — browser akan
            // request URL baru otomatis setelah expired.
            ->setHeader('Cache-Control', 'private, max-age=300')
            ->setBody($bytes);
    }
}