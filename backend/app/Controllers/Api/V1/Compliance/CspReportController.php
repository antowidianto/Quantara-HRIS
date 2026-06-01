<?php

namespace App\Controllers\Api\V1\Compliance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * CspReportController — terima laporan pelanggaran Content-Security-Policy
 * dari browser.
 *
 * Browser akan POST JSON ke endpoint ini setiap kali ada resource yang di-block
 * (atau seharusnya di-block kalau mode enforce). Body bentuk:
 *
 *   { "csp-report": { "document-uri": "...", "blocked-uri": "...",
 *                     "violated-directive": "script-src", ... } }
 *
 * Kita simpan ke audit_logs (severity NOTICE) supaya HR/security bisa review
 * di /admin/audit-logs filter module=SECURITY action=csp.violation.
 *
 * Endpoint ini PUBLIC (tidak butuh JWT) karena browser tidak forward auth
 * header saat report. Tapi rate limit lewat ThrottleFilter tetap berlaku
 * supaya tidak bisa di-flood.
 */
class CspReportController extends ApiController
{
    public function report()
    {
        // Browser kirim Content-Type: application/csp-report (bukan JSON murni).
        // Body raw masih JSON, jadi parse manual.
        $raw = (string) $this->request->getBody();
        $data = json_decode($raw, true);

        if (! is_array($data) || empty($data['csp-report'])) {
            // Tidak return 400 supaya tidak bocorkan info — cukup ack.
            return $this->response->setStatusCode(204);
        }

        $report = $data['csp-report'];
        $blocked = (string) ($report['blocked-uri'] ?? 'unknown');
        $directive = (string) ($report['violated-directive'] ?? '');
        $docUri = (string) ($report['document-uri'] ?? '');

        // Skip noise umum: chrome-extension, moz-extension, about:, data: URI,
        // dst. Browser kirim ini ribuan dan tidak actionable.
        $noisePatterns = ['chrome-extension://', 'moz-extension://', 'about:',
            'safari-extension://', 'webkit-masked-url:'];
        foreach ($noisePatterns as $p) {
            if (str_starts_with($blocked, $p)) {
                return $this->response->setStatusCode(204);
            }
        }

        try {
            Services::audit()->forModule('SECURITY')->log(
                action: 'csp.violation',
                entity: 'csp_report',
                description: sprintf(
                    'CSP violation: directive=%s blocked=%s di %s',
                    $directive, $blocked, $docUri
                ),
                metadata: [
                    'directive'      => $directive,
                    'blocked_uri'    => $blocked,
                    'document_uri'   => $docUri,
                    'referrer'       => $report['referrer'] ?? null,
                    'source_file'    => $report['source-file'] ?? null,
                    'line_number'    => $report['line-number'] ?? null,
                    'user_agent'     => $this->request->getUserAgent()->__toString(),
                    'ip'             => $this->request->getIPAddress(),
                ],
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {
            // Audit gagal tidak boleh balikin 500 — browser akan retry bebas
        }

        return $this->response->setStatusCode(204);
    }
}