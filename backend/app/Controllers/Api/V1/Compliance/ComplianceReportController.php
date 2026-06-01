<?php

namespace App\Controllers\Api\V1\Compliance;

use App\Controllers\Api\V1\ApiController;
use Config\Services;

/**
 * ComplianceReportController — REST API laporan kepatuhan HR
 * (Sprint 4 / CC-5).
 *
 * Endpoint:
 *   GET  /compliance-reports/types               — list available report types
 *   GET  /compliance-reports/runs                — history hasil generate
 *   POST /compliance-reports/generate            — trigger generate (cache-aware)
 *   GET  /compliance-reports/runs/(:num)         — load cached result
 *   GET  /compliance-reports/runs/(:num)/csv     — download CSV
 *
 * Authorization:
 *   - View:   `compliance.report.view`
 *   - Export: `compliance.report.export`
 */
class ComplianceReportController extends ApiController
{
    /**
     * GET /compliance-reports/types — daftar report yang tersedia.
     */
    public function types()
    {
        $descriptions = [
            'WLKP_ANNUAL'       => 'Wajib Lapor Ketenagakerjaan tahunan (UU 7/1981) — HC, gender, age groups',
            'BPJS_MONTHLY'      => 'BPJS bulanan — list aktif + estimasi iuran (Kesehatan + TK)',
            'PKWT_EXPIRING'     => 'PKWT yang akan habis kontrak (default 60 hari ke depan)',
            'PHK_LIST'          => 'Daftar PHK dalam periode (resignation + termination)',
            'AUDIT_SUMMARY'     => 'Audit log summary per severity & module (UU PDP)',
            'PROBATION_OVERDUE' => 'PKWTT yang probation_end_date lewat tapi belum diputuskan',
            'BPJS_OVERDUE'      => 'Karyawan baru yang BPJS_REGISTER overdue (UU SJSN)',
            'TURNOVER_SUMMARY'  => 'Turnover rate, retention, monthly trend',
        ];
        $out = [];
        foreach (\App\Libraries\Lifecycle\ComplianceReportService::REPORT_TYPES as $t) {
            $out[] = ['type' => $t, 'description' => $descriptions[$t] ?? ''];
        }
        return $this->ok($out);
    }

    /**
     * GET /compliance-reports/runs — history hasil generate.
     */
    public function runs()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $db = \Config\Database::connect();
        $b = $db->table('compliance_report_runs')
            ->select('id, report_type, period_year, period_month, row_count, '
                . 'export_format, generated_at, expires_at, generated_by')
            ->where('deleted_at IS NULL');

        $companyId = $this->tenant()->getCompanyId();
        if (! $this->tenant()->isSuperAdmin() && $companyId > 0) {
            $b->where('company_id', $companyId);
        }
        if ($type = (string) ($this->request->getGet('report_type') ?? '')) {
            $b->where('report_type', $type);
        }

        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $total   = (clone $b)->countAllResults(false);
        $rows    = $b->orderBy('generated_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     * POST /compliance-reports/generate
     *
     * Body: {
     *   report_type: string,
     *   year?: int, month?: int,
     *   parameters?: { days_ahead: int, ... },
     *   force_refresh?: bool
     * }
     */
    public function generate()
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);

        $data = $this->getJsonInput();
        $valid = $this->validateOrFail($data, [
            'report_type' => 'required|max_length[64]',
        ]);
        if (! $valid) return null;

        $companyId = (int) $this->tenant()->getCompanyId();
        if ($companyId <= 0 && ! $this->tenant()->isSuperAdmin()) {
            return $this->fail('Tenant context tidak ditemukan', 422);
        }
        // Super admin can specify company_id explicitly
        if ($this->tenant()->isSuperAdmin() && ! empty($data['company_id'])) {
            $companyId = (int) $data['company_id'];
        }

        $year         = isset($data['year']) ? (int) $data['year'] : null;
        $month        = isset($data['month']) ? (int) $data['month'] : null;
        $parameters   = isset($data['parameters']) && is_array($data['parameters']) ? $data['parameters'] : [];
        $forceRefresh = ! empty($data['force_refresh']);

        try {
            $result = Services::complianceReport()->generate(
                $companyId,
                (string) $valid['report_type'],
                $year, $month, $parameters, $this->userId(), $forceRefresh,
            );
            return $this->ok($result, $result['cached'] ? 'Returned cached report' : 'Report generated');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Gagal generate report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /compliance-reports/runs/(:num) — load cached result.
     */
    public function showRun($id = null)
    {
        if (! $this->canView()) return $this->fail('Forbidden', 403);
        $db = \Config\Database::connect();
        $row = $db->table('compliance_report_runs')->where('id', (int) $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) return $this->fail('Run tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }
        $row['result'] = ! empty($row['result_json'])
            ? (json_decode((string) $row['result_json'], true) ?: [])
            : [];
        $row['parameters'] = ! empty($row['parameters_json'])
            ? (json_decode((string) $row['parameters_json'], true) ?: [])
            : [];
        unset($row['result_json'], $row['parameters_json']);
        return $this->ok($row);
    }

    /**
     * GET /compliance-reports/runs/(:num)/csv?columns=col1,col2 — download CSV.
     */
    public function exportCsv($id = null)
    {
        if (! $this->canExport()) return $this->fail('Forbidden — butuh compliance.report.export', 403);

        $db = \Config\Database::connect();
        $row = $db->table('compliance_report_runs')->where('id', (int) $id)
            ->where('deleted_at IS NULL')->get()->getRowArray();
        if (! $row) return $this->fail('Run tidak ditemukan', 404);
        if (! $this->tenant()->isSuperAdmin() && (int) $row['company_id'] !== $this->tenant()->getCompanyId()) {
            return $this->fail('Forbidden', 403);
        }

        $result = ! empty($row['result_json'])
            ? (json_decode((string) $row['result_json'], true) ?: [])
            : [];
        $rows = $result['rows'] ?? [];
        if (empty($rows)) {
            return $this->fail('Report tidak punya rows untuk di-export', 422);
        }

        // Optional columns filter dari query string
        $colsQ = trim((string) ($this->request->getGet('columns') ?? ''));
        $columns = $colsQ ? array_map('trim', explode(',', $colsQ)) : [];

        $csv = Services::complianceReport()->toCsv($rows, $columns);

        // Audit export
        try {
            Services::audit()->forModule('COMPLIANCE')->log(
                action: 'compliance.report_export',
                entity: 'compliance_report_run',
                entityId: (int) $row['id'],
                description: "Export CSV {$row['report_type']} ({$row['row_count']} rows)",
                severity: 'NOTICE',
            );
        } catch (\Throwable $e) {}

        $filename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['report_type']))
            . '_' . date('Ymd_His') . '.csv';
        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function canView(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin()
            || $t->hasPermission('compliance.report.view')
            || $t->hasPermission('compliance.report.export');
    }

    private function canExport(): bool
    {
        $t = $this->tenant();
        return $t->isSuperAdmin() || $t->hasPermission('compliance.report.export');
    }
}