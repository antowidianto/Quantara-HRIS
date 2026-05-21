<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiSecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // No-op
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(self), microphone=(), camera=(self)');
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');

        // Content-Security-Policy: dimulai dari report-only di staging supaya
        // kita bisa observe dulu sebelum enforce. Set env `csp.enforce=true`
        // untuk switch ke enforce mode setelah verifikasi.
        //
        // - default-src 'self'                  → semua resource dari origin sendiri
        // - script-src 'self'                   → tidak ada inline script + tidak ada CDN luar
        // - style-src 'self' 'unsafe-inline'    → Tailwind generate inline style minor
        // - img-src 'self' data: blob:          → izinkan base64 selfie + map tile
        // - connect-src 'self'                  → API call hanya ke origin
        // - frame-ancestors 'none'              → tidak boleh di-iframe (clickjacking)
        // - object-src 'none'                   → blok Flash/applet
        // - base-uri 'self'                     → cegah base tag injection
        // - form-action 'self'                  → form tidak bisa POST ke domain luar
        $cspParts = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "report-uri /api/v1/csp-report",
        ];
        $csp = implode('; ', $cspParts);

        $enforce = (bool) (env('csp.enforce') ?? false);
        $headerName = $enforce
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
        $response->setHeader($headerName, $csp);

        if (ENVIRONMENT === 'production') {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Remove server identification
        $response->removeHeader('X-Powered-By');
        $response->removeHeader('Server');
    }
}