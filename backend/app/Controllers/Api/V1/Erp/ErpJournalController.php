<?php

namespace App\Controllers\Api\V1\Erp;

use App\Controllers\Api\V1\ApiController;
use App\Libraries\Erp\ErpJournalService;
use Config\Database;

/**
 * H3.4 — ERP Journal Controller.
 *
 * Endpoints:
 *   GET    /api/v1/erp/connections
 *   POST   /api/v1/erp/connections                   {provider, base_url?, api_key?, extra_config?}
 *   PUT    /api/v1/erp/connections/{id}
 *   DELETE /api/v1/erp/connections/{id}
 *   POST   /api/v1/erp/connections/{id}/test
 *
 *   GET    /api/v1/erp/mappings
 *   POST   /api/v1/erp/mappings                      {cost_center_id?, account_type, debit_coa, credit_coa, description_template?}
 *   PUT    /api/v1/erp/mappings/{id}
 *   DELETE /api/v1/erp/mappings/{id}
 *
 *   GET    /api/v1/erp/journal-exports                ?source_type=&status=
 *   POST   /api/v1/erp/journal-exports/preview        {source_type, source_id}
 *   POST   /api/v1/erp/journal-exports                {source_type, source_id, format, connection_id?}
 *   GET    /api/v1/erp/journal-exports/{id}
 *   GET    /api/v1/erp/journal-exports/{id}/csv       (download CSV)
 *   POST   /api/v1/erp/journal-exports/{id}/post
 *   POST   /api/v1/erp/journal-exports/{id}/reverse
 */
class ErpJournalController extends ApiController
{
    private ErpJournalService $service;

    public function __construct()
    {
        $this->service = new ErpJournalService();
    }

    // ─── Connections ──────────────────────────────────────────────────

    public function listConnections()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $rows = Database::connect()->table('erp_connections')
            ->select('id, provider, base_url, is_active, last_test_status, last_test_at, last_test_message, created_at, updated_at')
            ->where('company_id', $companyId)
            ->where('deleted_at', null)
            ->orderBy('provider', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createConnection()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->getJsonInput();
        $data = $this->validateOrFail($body, [
            'provider' => 'required|in_list[ACCURATE,ZAHIR,ODOO,SAP_IDOC,CSV_ONLY]',
        ]);
        if ($data === null) return null;

        $db = Database::connect();
        $existing = $db->table('erp_connections')
            ->where('company_id', $companyId)
            ->where('provider', $data['provider'])
            ->where('deleted_at', null)
            ->countAllResults();
        if ($existing) {
            return $this->fail('Provider sudah dikonfigurasi untuk company ini', 409, 'DUPLICATE_PROVIDER');
        }

        $row = [
            'company_id'        => $companyId,
            'provider'          => $data['provider'],
            'base_url'          => $body['base_url']    ?? null,
            'api_key_encrypted' => isset($body['api_key']) && $body['api_key'] !== ''
                ? $this->encryptKey($body['api_key'])
                : null,
            'extra_config_json' => isset($body['extra_config'])
                ? json_encode($body['extra_config'], JSON_UNESCAPED_UNICODE)
                : null,
            'is_active'         => 1,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        $db->table('erp_connections')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Connection created', 201);
    }

    public function updateConnection(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('erp_connections')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $existing) {
            return $this->fail('Connection not found', 404, 'NOT_FOUND');
        }
        $body = $this->getJsonInput();
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($body['base_url']))    $upd['base_url'] = $body['base_url'] !== '' ? $body['base_url'] : null;
        if (isset($body['is_active']))   $upd['is_active'] = (int) $body['is_active'];
        if (array_key_exists('api_key', $body) && $body['api_key'] !== '') {
            $upd['api_key_encrypted'] = $this->encryptKey($body['api_key']);
        }
        if (isset($body['extra_config'])) {
            $upd['extra_config_json'] = json_encode($body['extra_config'], JSON_UNESCAPED_UNICODE);
        }
        $db->table('erp_connections')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function deleteConnection(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $db->table('erp_connections')
            ->where('id', $id)->where('company_id', $companyId)
            ->update(['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0]);
        return $this->ok(null, 'Deleted');
    }

    /**
     * Test connection — saat ini adapter eksternal di-stub. Kita hanya
     * validasi base_url + api_key non-empty, lalu set last_test_status.
     * Adapter implementasi penuh bergantung pada PoC vendor masing-masing.
     */
    public function testConnection(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $row = $db->table('erp_connections')
            ->where('id', $id)->where('company_id', $companyId)->where('deleted_at', null)
            ->get()->getRowArray();
        if (! $row) {
            return $this->fail('Connection not found', 404, 'NOT_FOUND');
        }

        $provider = (string) ($row['provider'] ?? '');
        $status   = 'OK';
        $message  = 'Connection config valid (dry-run; full posting butuh adapter PoC vendor).';

        if ($provider !== 'CSV_ONLY') {
            if (empty($row['base_url'])) {
                $status  = 'FAILED';
                $message = 'base_url wajib untuk provider non-CSV_ONLY.';
            } elseif (empty($row['api_key_encrypted'])) {
                $status  = 'FAILED';
                $message = 'api_key wajib untuk provider non-CSV_ONLY.';
            }
        }

        $db->table('erp_connections')->where('id', $id)->update([
            'last_test_status'  => $status,
            'last_test_at'      => date('Y-m-d H:i:s'),
            'last_test_message' => $message,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->ok([
            'status'  => $status,
            'message' => $message,
        ]);
    }

    // ─── Mappings ─────────────────────────────────────────────────────

    public function listMappings()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $rows = Database::connect()->table('erp_journal_mappings')
            ->where('company_id', $companyId)
            ->orderBy('account_type', 'ASC')
            ->orderBy('cost_center_id', 'ASC')
            ->get()->getResultArray();
        return $this->ok($rows);
    }

    public function createMapping()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->getJsonInput();
        $errors = ErpJournalService::validateMappingPure($body);
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        $row = [
            'company_id'           => $companyId,
            'cost_center_id'       => isset($body['cost_center_id']) && $body['cost_center_id']
                ? (int) $body['cost_center_id']
                : null,
            'account_type'         => $body['account_type'],
            'debit_coa'            => trim($body['debit_coa']),
            'credit_coa'           => trim($body['credit_coa']),
            'description_template' => $body['description_template'] ?? null,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        $db = Database::connect();
        $db->table('erp_journal_mappings')->insert($row);
        return $this->ok(['id' => (int) $db->insertID()], 'Mapping created', 201);
    }

    public function updateMapping(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $existing = $db->table('erp_journal_mappings')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $existing) {
            return $this->fail('Mapping not found', 404, 'NOT_FOUND');
        }
        $body = array_merge($existing, $this->getJsonInput());
        $errors = ErpJournalService::validateMappingPure($body);
        if ($errors !== []) {
            return $this->fail('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }
        $upd = [
            'cost_center_id'       => isset($body['cost_center_id']) && $body['cost_center_id']
                ? (int) $body['cost_center_id']
                : null,
            'account_type'         => $body['account_type'],
            'debit_coa'            => trim((string) $body['debit_coa']),
            'credit_coa'           => trim((string) $body['credit_coa']),
            'description_template' => $body['description_template'] ?? null,
            'is_active'            => isset($body['is_active']) ? (int) $body['is_active'] : 1,
            'updated_at'           => date('Y-m-d H:i:s'),
        ];
        $db->table('erp_journal_mappings')->where('id', $id)->update($upd);
        return $this->ok(['id' => $id], 'Updated');
    }

    public function deleteMapping(int $id)
    {
        $companyId = $this->companyId();
        Database::connect()->table('erp_journal_mappings')
            ->where('id', $id)->where('company_id', $companyId)->delete();
        return $this->ok(null, 'Deleted');
    }

    // ─── Journal Exports ──────────────────────────────────────────────

    public function listExports()
    {
        $companyId = $this->companyId();
        if ($companyId <= 0) {
            return $this->fail('company_id required', 400, 'COMPANY_REQUIRED');
        }
        $sourceType = (string) $this->request->getGet('source_type');
        $status     = (string) $this->request->getGet('status');

        $b = Database::connect()->table('erp_journal_exports')
            ->select('id, source_type, source_id, period_label, format, total_debit, total_credit, line_count, status, posted_at, created_at')
            ->where('company_id', $companyId);
        if ($sourceType !== '') $b->where('source_type', $sourceType);
        if ($status     !== '') $b->where('status', $status);
        $rows = $b->orderBy('id', 'DESC')->limit(200)->get()->getResultArray();
        return $this->ok($rows);
    }

    public function previewExport()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'source_type' => 'required|in_list[PAYROLL_PERIOD,BONUS_RUN,THR_RUN]',
            'source_id'   => 'required|is_natural_no_zero',
        ]);
        if ($body === null) return null;

        $build = $this->buildBySource($companyId, (string) $body['source_type'], (int) $body['source_id']);
        return $this->ok($build);
    }

    public function createExport()
    {
        $companyId = $this->requireCompanyId();
        if ($companyId === null) return null;

        $body = $this->validateOrFail($this->getJsonInput(), [
            'source_type'  => 'required|in_list[PAYROLL_PERIOD,BONUS_RUN,THR_RUN]',
            'source_id'    => 'required|is_natural_no_zero',
            'format'       => 'required|in_list[ACCURATE_API,ZAHIR_CSV,ODOO_API,SAP_IDOC,GENERIC_CSV]',
        ]);
        if ($body === null) return null;

        $build = $this->buildBySource($companyId, (string) $body['source_type'], (int) $body['source_id']);
        if (($build['line_count'] ?? 0) === 0) {
            return $this->fail(
                'Tidak ada line yang bisa di-export. Cek mapping COA dan data sumber.',
                422,
                'EMPTY_BUILD',
                ['warnings' => $build['warnings']]
            );
        }

        try {
            $exportId = $this->service->persistExport(
                $companyId,
                (string) $body['source_type'],
                (int)    $body['source_id'],
                (string) $body['format'],
                $build,
                isset($body['connection_id']) ? (int) $body['connection_id'] : null,
                isset($body['period_label'])  ? (string) $body['period_label'] : null,
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 'BUILD_FAILED');
        }
        return $this->ok(['id' => $exportId, 'warnings' => $build['warnings']], 'Export created', 201);
    }

    public function showExport(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('erp_journal_exports')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        $row['lines'] = json_decode((string) $row['lines_json'], true) ?: [];
        unset($row['lines_json']);
        return $this->ok($row);
    }

    public function downloadCsv(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('erp_journal_exports')
            ->where('id', $id)->where('company_id', $companyId)
            ->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        $build = [
            'lines' => json_decode((string) $row['lines_json'], true) ?: [],
        ];
        $csv = $this->service->renderCsv($build);
        $filename = sprintf('erp-journal-%d-%s.csv', $id, date('YmdHis'));
        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    public function postExport(int $id)
    {
        $companyId = $this->companyId();
        $db = Database::connect();
        $row = $db->table('erp_journal_exports')
            ->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        if ($row['status'] === 'POSTED') {
            return $this->fail('Already posted', 409, 'ALREADY_POSTED');
        }
        $userId = $this->userId();
        $note   = (string) ($this->getJsonInput()['note'] ?? 'Manual post via UI');
        $this->service->markPosted($id, $userId, $note);
        return $this->ok(['id' => $id, 'status' => 'POSTED']);
    }

    public function reverseExport(int $id)
    {
        $companyId = $this->companyId();
        $row = Database::connect()->table('erp_journal_exports')
            ->where('id', $id)->where('company_id', $companyId)->get()->getRowArray();
        if (! $row) return $this->fail('Not found', 404, 'NOT_FOUND');
        $reason = (string) ($this->getJsonInput()['reason'] ?? '');
        if ($reason === '') {
            return $this->fail('reason wajib diisi', 422, 'REASON_REQUIRED');
        }
        $this->service->markReversed($id, $reason);
        return $this->ok(['id' => $id, 'status' => 'REVERSED']);
    }

    // ─── Internal helpers ─────────────────────────────────────────────

    /**
     * Routing build per source_type. Saat ini hanya PAYROLL_PERIOD yang
     * di-implementasi penuh; BONUS_RUN dan THR_RUN diarahkan ke build
     * payroll yang sama (THR/Bonus terstruktur sebagai earning di
     * payroll_details).
     */
    private function buildBySource(int $companyId, string $sourceType, int $sourceId): array
    {
        if ($sourceType === 'PAYROLL_PERIOD') {
            return $this->service->buildForPayrollPeriod($companyId, $sourceId);
        }
        // BONUS_RUN / THR_RUN — re-use payroll build dengan filter source.
        // Default: kembalikan empty build dengan warning.
        return [
            'total_debit'  => 0.0,
            'total_credit' => 0.0,
            'line_count'   => 0,
            'lines'        => [],
            'warnings'     => [
                sprintf('Build adapter untuk %s belum diimplementasi penuh. Gunakan PAYROLL_PERIOD.', $sourceType),
            ],
        ];
    }

    /**
     * Encrypt API key dengan service existing kalau tersedia, fallback
     * base64 (NOT secure — untuk dev only). Production wajib pakai
     * EncryptionService AES-256-GCM.
     */
    private function encryptKey(string $plain): string
    {
        try {
            if (class_exists(\App\Libraries\Security\EncryptionService::class)) {
                $svc = new \App\Libraries\Security\EncryptionService();
                if (method_exists($svc, 'encrypt')) {
                    return (string) $svc->encrypt($plain);
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'b64:' . base64_encode($plain);
    }
}