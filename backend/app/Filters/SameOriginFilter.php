<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * SameOriginFilter — Sprint A / B3
 *
 * Mitigasi CSRF untuk endpoint multipart upload (avatar, file upload,
 * import xlsx, attendance selfie). Karena auth utama HRIS pakai JWT
 * Bearer (bukan cookie), risiko CSRF teoretis kecil — tapi kalau ada
 * extension browser yang nge-leak Authorization header, atau attacker
 * berhasil compromise JWT singkat, multipart upload menjadi vektor
 * yang berbahaya (bisa upload malware, KTP fake, dll).
 *
 * Strategi: cek Origin header (atau Referer fallback) match dengan
 * whitelist `cors.allowedOrigins` di .env. Sama dengan CORS allow list
 * supaya tidak ada policy ganda.
 *
 * Skip:
 *   - OPTIONS request (preflight)
 *   - Request dari CLI / cron (tanpa Origin & Referer)
 *   - Test environment (hrisOk untuk PHPUnit + spark serve lokal)
 *
 * Pakai di Routes:
 *   $routes->post('upload',           'FileUploadController::upload',
 *       ['filter' => 'sameorigin']);
 *   $routes->post('upload/attachment','FileUploadController::uploadAttachment',
 *       ['filter' => 'sameorigin']);
 *   $routes->post('imports/(:segment)/preview','ImportController::preview/$1',
 *       ['filter' => 'sameorigin']);
 *   $routes->post('attendance/clock-in','AttendanceController::clockIn',
 *       ['filter' => 'sameorigin']);
 */
class SameOriginFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return; // preflight
        }

        $origin  = (string) $request->getHeaderLine('Origin');
        $referer = (string) $request->getHeaderLine('Referer');

        // CLI / health check / curl tanpa header → skip (akses internal).
        // Tetap protect karena attacker tidak bisa fake "no Origin" dari
        // browser cross-site context.
        if ($origin === '' && $referer === '') {
            return;
        }

        $allowedRaw = (string) env('cors.allowedOrigins', '');
        $allowed = array_filter(array_map('trim', explode(',', $allowedRaw)));

        // Dev fallback: kalau allow list kosong, terima localhost saja.
        // Match dengan ApiCorsFilter::before behavior agar konsisten.
        if (empty($allowed)) {
            $localOk = preg_match(
                '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
                $origin ?: $this->originFromReferer($referer)
            );
            if ($localOk) return;
            return $this->forbidden('Origin tidak dikenal (allow list kosong dan bukan localhost)');
        }

        $candidate = $origin !== '' ? $origin : $this->originFromReferer($referer);
        if ($candidate === '' || ! in_array($candidate, $allowed, true)) {
            return $this->forbidden(
                "Origin tidak diizinkan untuk endpoint ini: {$candidate}"
            );
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }

    /**
     * Extract origin (scheme://host[:port]) dari Referer URL.
     * Return '' kalau parse gagal.
     */
    private function originFromReferer(string $referer): string
    {
        if ($referer === '') return '';
        $p = parse_url($referer);
        if (! $p || empty($p['scheme']) || empty($p['host'])) return '';
        $origin = $p['scheme'] . '://' . $p['host'];
        if (! empty($p['port'])) $origin .= ':' . $p['port'];
        return $origin;
    }

    private function forbidden(string $reason): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(403)
            ->setJSON([
                'status'  => 'error',
                'code'    => 'INVALID_ORIGIN',
                'message' => 'Cross-origin request ditolak. ' . $reason,
            ]);
    }
}