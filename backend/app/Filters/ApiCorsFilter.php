<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ApiCorsFilter
 *
 * Handles CORS for the API. Runs as the FIRST global before filter so that
 * CORS preflight (OPTIONS) requests are answered before any throttling, JWT
 * checks, or other filters can potentially fail and break the preflight.
 */
class ApiCorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $allowedRaw = (string) env('cors.allowedOrigins', '');
        $allowed    = array_filter(array_map('trim', explode(',', $allowedRaw)));
        $origin     = $request->getHeaderLine('Origin');
        $method     = strtoupper($request->getMethod());
        $isOptions  = $method === 'OPTIONS';

        $response = Services::response();

        // Determine allowed origin to echo back
        $allowOrigin = null;
        if ($origin !== '') {
            // Exact match against allowed list
            if (in_array($origin, $allowed, true)) {
                $allowOrigin = $origin;
            }
            // In development, also allow common localhost dev ports if list is empty/missing
            elseif (empty($allowed) && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
                $allowOrigin = $origin;
            }
        }

        if ($allowOrigin !== null) {
            $response->setHeader('Access-Control-Allow-Origin', $allowOrigin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

            // Echo back requested headers when present, otherwise set sensible defaults
            $reqHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
            $response->setHeader(
                'Access-Control-Allow-Headers',
                $reqHeaders !== '' ? $reqHeaders : 'Authorization, Content-Type, X-Requested-With, X-CSRF-TOKEN, X-Company-Id, X-Request-Id, Accept',
            );
            $response->setHeader('Access-Control-Max-Age', '86400');
        }

        // Always short-circuit OPTIONS to a 204 — the preflight should never be
        // subject to authentication, rate limiting, or any further filter.
        if ($isOptions) {
            return $response->setStatusCode(204)->setBody('');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Re-echo CORS headers on the actual response in case other layers stripped them.
        $allowedRaw = (string) env('cors.allowedOrigins', '');
        $allowed    = array_filter(array_map('trim', explode(',', $allowedRaw)));
        $origin     = $request->getHeaderLine('Origin');

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }
    }
}