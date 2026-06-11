<?php

namespace App\Libraries\Backup;

/**
 * S2.3 — Data Portability (GDPR Art. 20 / UU PDP).
 *
 * Tenant admin bisa request "saya mau export semua data perusahaan saya".
 * Kita generate ZIP yang berisi CSV per tabel + manifest JSON, lalu kasih
 * link download signed (expires 7 hari).
 *
 * Eksekusi async (queue) karena bisa berat untuk tenant dengan 10K karyawan.
 *
 * Tabel yang di-export (default):
 *   - employees, employee_contacts, employee_documents
 *   - attendances, leave_requests, leave_balances
 *   - payslips, payslip_details
 *   - users (tanpa password_hash, tanpa 2fa secret)
 *   - audit_logs (last 90 hari saja, supaya size manageable)
 *
 * Pure helpers tersedia untuk testing logic CSV serialization.
 */
class DataExportService
{
    /**
     * Tabel yang diizinkan di-export. Setiap entry:
     *   key   = nama tabel
     *   value = ['filter_column' => string, 'columns' => array|null, 'where_extra' => array]
     *
     * - filter_column = kolom yang di-WHERE = company_id
     * - columns = kolom yang di-SELECT (null = semua)
     * - where_extra = filter tambahan (mis. created_at >= '...')
     *
     * NOTE: SENGAJA EXCLUDE kolom sensitif (password_hash, twofa_secret,
     * encryption_key) supaya export tidak bocorkan kredensial.
     */
    public const ALLOWED_TABLES = [
        'employees' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
        ],
        'attendances' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
        ],
        'leave_requests' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
        ],
        'leave_balances' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
        ],
        'payslips' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
        ],
        'payslip_details' => [
            // payslip_details join via payslips.company_id — handled custom
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => [],
            'requires_join' => true,
        ],
        'users' => [
            'filter_column' => 'company_id',
            'columns'       => ['id', 'company_id', 'employee_id', 'username', 'email', 'is_active', 'created_at', 'updated_at'],
            'where_extra'   => [],
        ],
        'audit_logs' => [
            'filter_column' => 'company_id',
            'columns'       => null,
            'where_extra'   => ['created_at >=' => null], // diisi runtime: 90 hari ke belakang
        ],
    ];

    /**
     * Pure helper: serialize array of associative rows ke CSV string.
     * Header diambil dari keys row pertama.
     *
     * - null → empty string
     * - bool → 0 / 1
     * - array/object → JSON encoded
     */
    public static function rowsToCsvPure(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        // PHP 8.4: $escape sekarang wajib eksplisit untuk hindari deprecation
        // warning. Pakai default ',' enclosure '"' escape '\\' supaya RFC4180-ish.
        $header = array_keys($rows[0]);
        fputcsv($fp, $header, ',', '"', '\\');

        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $col) {
                $v = $row[$col] ?? null;
                if ($v === null) {
                    $line[] = '';
                } elseif (is_bool($v)) {
                    $line[] = $v ? '1' : '0';
                } elseif (is_array($v) || is_object($v)) {
                    $line[] = json_encode($v, JSON_UNESCAPED_UNICODE);
                } else {
                    $line[] = (string) $v;
                }
            }
            fputcsv($fp, $line, ',', '"', '\\');
        }

        rewind($fp);
        $csv = stream_get_contents($fp) ?: '';
        fclose($fp);
        return $csv;
    }

    /**
     * Pure helper: build manifest payload untuk export.
     */
    public static function buildManifestPure(int $companyId, array $tables, string $generatedAt, string $version = '1.0'): array
    {
        return [
            'manifest_version' => $version,
            'company_id'       => $companyId,
            'generated_at'     => $generatedAt,
            'tables'           => array_map(static function ($t) {
                return [
                    'name'  => $t['name'],
                    'rows'  => $t['rows'],
                    'bytes' => $t['bytes'],
                ];
            }, $tables),
            'notes' => [
                'kolom-sensitif (password_hash, twofa_secret) sengaja diexclude',
                'audit_logs hanya 90 hari terakhir untuk batasi size',
                'file ini digenerate untuk memenuhi UU PDP Pasal 16 (hak portabilitas data)',
            ],
        ];
    }

    /**
     * Buat request export baru. Status awal QUEUED.
     */
    public function createRequest(int $companyId, int $userId, ?array $scope = null): int
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));

        $db->table('data_export_requests')->insert([
            'company_id'     => $companyId,
            'requested_by'   => $userId,
            'status'         => 'QUEUED',
            'scope_json'     => $scope ? json_encode($scope) : null,
            'download_token' => $token,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+7 days')),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        return (int) $db->insertID();
    }

    /**
     * Eksekusi export — generate ZIP berisi CSV per tabel + manifest.json.
     *
     * Idealnya dipanggil dari queue worker (BaseJob). Lihat ExportTenantDataJob.
     *
     * @throws \RuntimeException kalau export gagal.
     */
    public function execute(int $requestId): array
    {
        $db = \Config\Database::connect();
        $req = $db->table('data_export_requests')->where('id', $requestId)->get()->getRowArray();
        if (! $req) {
            throw new \RuntimeException('Export request not found: ' . $requestId);
        }
        if ($req['status'] === 'READY' || $req['status'] === 'EXPIRED') {
            return ['skipped' => true, 'reason' => 'already ' . $req['status']];
        }

        $companyId = (int) $req['company_id'];
        $now = date('Y-m-d H:i:s');

        $db->table('data_export_requests')->where('id', $requestId)->update([
            'status'     => 'RUNNING',
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $exportDir = rtrim((string) env('EXPORT_LOCAL_PATH', WRITEPATH . 'exports'), DIRECTORY_SEPARATOR);
            if (! is_dir($exportDir)) {
                @mkdir($exportDir, 0750, true);
            }
            $zipName = sprintf('hris_export_company_%d_%s.zip', $companyId, date('Ymd_His'));
            $zipPath = $exportDir . DIRECTORY_SEPARATOR . $zipName;

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open zip for write: ' . $zipPath);
            }

            $scope = ! empty($req['scope_json']) ? json_decode($req['scope_json'], true) : null;
            $tablesToExport = $this->resolveTables($scope);

            $tableManifest = [];
            foreach ($tablesToExport as $tableName => $opts) {
                $rows = $this->fetchTableRows($db, $tableName, $companyId, $opts);
                $csv  = self::rowsToCsvPure($rows);
                $entry = $tableName . '.csv';
                $zip->addFromString($entry, $csv);

                $tableManifest[] = [
                    'name'  => $tableName,
                    'rows'  => count($rows),
                    'bytes' => strlen($csv),
                ];
            }

            $manifest = self::buildManifestPure($companyId, $tableManifest, $now);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();

            $size   = filesize($zipPath) ?: 0;
            $sha256 = hash_file('sha256', $zipPath);

            $db->table('data_export_requests')->where('id', $requestId)->update([
                'status'          => 'READY',
                'finished_at'     => date('Y-m-d H:i:s'),
                'file_path'       => $zipPath,
                'file_size_bytes' => $size,
                'sha256'          => $sha256,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            return [
                'request_id' => $requestId,
                'file_path'  => $zipPath,
                'size_bytes' => $size,
                'sha256'     => $sha256,
                'tables'     => count($tableManifest),
            ];
        } catch (\Throwable $e) {
            $db->table('data_export_requests')->where('id', $requestId)->update([
                'status'        => 'FAILED',
                'finished_at'   => date('Y-m-d H:i:s'),
                'error_message' => $e->getMessage(),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
    }

    /**
     * Resolve daftar tabel yang harus di-export. Kalau scope = null → semua
     * default. Kalau scope = ['include' => ['employees', 'payslips']] → subset.
     */
    private function resolveTables(?array $scope): array
    {
        if ($scope === null || empty($scope['include'])) {
            return self::ALLOWED_TABLES;
        }
        $out = [];
        foreach ($scope['include'] as $name) {
            if (isset(self::ALLOWED_TABLES[$name])) {
                $out[$name] = self::ALLOWED_TABLES[$name];
            }
        }
        return $out;
    }

    /**
     * Fetch rows dari 1 tabel dengan filter company_id.
     */
    private function fetchTableRows($db, string $tableName, int $companyId, array $opts): array
    {
        if (! $db->tableExists($tableName)) {
            return [];
        }

        // Khusus payslip_details → join via payslips
        if (! empty($opts['requires_join']) && $tableName === 'payslip_details') {
            $builder = $db->table('payslip_details pd')
                ->select('pd.*')
                ->join('payslips p', 'p.id = pd.payslip_id', 'inner')
                ->where('p.company_id', $companyId);
            return $builder->get()->getResultArray();
        }

        $builder = $db->table($tableName);

        if (! empty($opts['columns'])) {
            $builder->select($opts['columns']);
        }
        $filterCol = $opts['filter_column'] ?? 'company_id';
        $builder->where($filterCol, $companyId);

        // Special: audit_logs default last 90 days
        if ($tableName === 'audit_logs') {
            $builder->where('created_at >=', date('Y-m-d H:i:s', strtotime('-90 days')));
        }

        // Apply where_extra (kalau ada)
        foreach (($opts['where_extra'] ?? []) as $k => $v) {
            if ($v !== null) {
                $builder->where($k, $v);
            }
        }

        return $builder->get()->getResultArray();
    }
}