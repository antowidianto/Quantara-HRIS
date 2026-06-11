<?php

namespace App\Libraries\Erp;

use Config\Database;

/**
 * H3.4 — ERP Journal Service.
 *
 * Bertanggung jawab untuk:
 *  1. Menyusun journal entries dari sumber HR (payroll period, bonus
 *     run, THR run) menggunakan COA mapping yang dikelola admin.
 *  2. Memvalidasi balance debit/credit (SAK requirement).
 *  3. Membuat record `erp_journal_exports` snapshot untuk audit.
 *  4. Posting actual ke ERP via adapter (atau export CSV fallback).
 *
 * Pure helpers ada di akhir class supaya bisa di-unit-test tanpa DB.
 *
 * Adapter posting external belum implementasi penuh — pattern ada,
 * tapi default jalur adalah CSV export. Posting via API ke Accurate /
 * Odoo / Zahir / SAP perlu kontrak komersial dengan vendor.
 */
class ErpJournalService
{
    /** Account types yang valid untuk mapping. Disinkronkan dengan
     *  comment kolom `erp_journal_mappings.account_type`. */
    public const ACCOUNT_TYPES = [
        'SALARY',
        'OVERTIME',
        'THR',
        'BONUS',
        'BPJS_KES',
        'BPJS_TK',
        'PPH21',
        'ALLOWANCE_CASH',
        'ALLOWANCE_NATURA',
        'LOAN_DEDUCTION',
        'OTHER_DEDUCTION',
    ];

    public const SOURCE_TYPES = ['PAYROLL_PERIOD', 'BONUS_RUN', 'THR_RUN'];

    public const FORMATS = [
        'ACCURATE_API',
        'ZAHIR_CSV',
        'ODOO_API',
        'SAP_IDOC',
        'GENERIC_CSV',
    ];

    /**
     * Bangun journal entries untuk satu payroll period.
     * Entries diaggregasi per cost_center × account_type → satu line per
     * kombinasi supaya jurnal ringkas (bukan satu line per karyawan).
     *
     * @return array{
     *   total_debit: float,
     *   total_credit: float,
     *   line_count: int,
     *   lines: array<int, array<string, mixed>>,
     *   warnings: array<int, string>
     * }
     */
    public function buildForPayrollPeriod(int $companyId, int $periodId): array
    {
        $db = Database::connect();
        if (! $db->tableExists('payslips') || ! $db->tableExists('payslip_details')) {
            return $this->emptyResult(['Tabel payslips/payslip_details belum ada — payroll module belum siap.']);
        }

        // Ambil semua payslip yang sudah finalize di period ini
        $payslips = $db->table('payslips')
            ->select('id, employee_id, gross_earning, net_pay, total_deduction, bpjs_employee, bpjs_company, pph21')
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->get()
            ->getResultArray();

        if ($payslips === []) {
            return $this->emptyResult(['Tidak ada payslip untuk period ini, calculate dulu.']);
        }

        $payslipIds = array_column($payslips, 'id');

        // Group payslip per cost_center via employees.cost_center_id
        $employeeMap = [];
        $empIds = array_unique(array_column($payslips, 'employee_id'));
        if ($empIds !== [] && $db->tableExists('employees')) {
            $empRows = $db->table('employees')
                ->select('id, cost_center_id')
                ->whereIn('id', $empIds)
                ->get()
                ->getResultArray();
            foreach ($empRows as $row) {
                $employeeMap[(int) $row['id']] = (int) ($row['cost_center_id'] ?? 0);
            }
        }

        // Ambil detail komponen earning/deduction
        $details = [];
        if ($db->tableExists('payslip_details')) {
            $details = $db->table('payslip_details')
                ->whereIn('payslip_id', $payslipIds)
                ->get()
                ->getResultArray();
        }

        // Aggregate per cost_center_id × account_type
        $bucket = [];
        foreach ($details as $d) {
            $payslipId = (int) ($d['payslip_id'] ?? 0);
            $payslip   = $this->findPayslip($payslips, $payslipId);
            if (! $payslip) {
                continue;
            }
            $ccId        = $employeeMap[(int) $payslip['employee_id']] ?? null;
            $accountType = $this->mapDetailToAccountType($d);
            if ($accountType === null) {
                continue;
            }
            $amount = (float) ($d['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $key = ($ccId ?? 0) . '|' . $accountType;
            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'cost_center_id' => $ccId,
                    'account_type'   => $accountType,
                    'amount'         => 0.0,
                ];
            }
            $bucket[$key]['amount'] += $amount;
        }

        // Tambah header level: BPJS company, BPJS employee, PPh21 sebagai line
        // tersendiri dari kolom payslip (bukan dari payslip_details)
        $totalsByCC = [];
        foreach ($payslips as $ps) {
            $ccId = $employeeMap[(int) $ps['employee_id']] ?? null;
            $key  = $ccId ?? 0;
            $totalsByCC[$key] ??= [
                'cost_center_id' => $ccId,
                'bpjs_employee'  => 0.0,
                'bpjs_company'   => 0.0,
                'pph21'          => 0.0,
            ];
            $totalsByCC[$key]['bpjs_employee'] += (float) ($ps['bpjs_employee'] ?? 0);
            $totalsByCC[$key]['bpjs_company']  += (float) ($ps['bpjs_company']  ?? 0);
            $totalsByCC[$key]['pph21']         += (float) ($ps['pph21']         ?? 0);
        }
        foreach ($totalsByCC as $row) {
            if ($row['bpjs_company'] > 0) {
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|BPJS_TK'] ??= [
                    'cost_center_id' => $row['cost_center_id'],
                    'account_type'   => 'BPJS_TK',
                    'amount'         => 0.0,
                ];
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|BPJS_TK']['amount'] += $row['bpjs_company'];
            }
            if ($row['bpjs_employee'] > 0) {
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|BPJS_KES'] ??= [
                    'cost_center_id' => $row['cost_center_id'],
                    'account_type'   => 'BPJS_KES',
                    'amount'         => 0.0,
                ];
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|BPJS_KES']['amount'] += $row['bpjs_employee'];
            }
            if ($row['pph21'] > 0) {
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|PPH21'] ??= [
                    'cost_center_id' => $row['cost_center_id'],
                    'account_type'   => 'PPH21',
                    'amount'         => 0.0,
                ];
                $bucket['' . ($row['cost_center_id'] ?? 0) . '|PPH21']['amount'] += $row['pph21'];
            }
        }

        // Lookup mapping dengan fallback (catch-all NULL cost_center)
        $mappings = $this->loadMappings($companyId);

        $lines    = [];
        $warnings = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($bucket as $entry) {
            $mapping = self::pickMappingPure(
                $mappings,
                $entry['cost_center_id'],
                $entry['account_type'],
            );
            if (! $mapping) {
                $warnings[] = sprintf(
                    'COA mapping belum ada untuk cost_center=%s account_type=%s',
                    $entry['cost_center_id'] ?? '(catch-all)',
                    $entry['account_type'],
                );
                continue;
            }
            $amount = round((float) $entry['amount'], 2);
            $lines[] = [
                'cost_center_id' => $entry['cost_center_id'],
                'account_type'   => $entry['account_type'],
                'debit_coa'      => $mapping['debit_coa'],
                'credit_coa'     => $mapping['credit_coa'],
                'amount'         => $amount,
                'description'    => $this->renderDescription(
                    $mapping['description_template'] ?? null,
                    [
                        'period'        => '#' . $periodId,
                        'cost_center_id'=> (string) ($entry['cost_center_id'] ?? '0'),
                        'account_type'  => $entry['account_type'],
                    ],
                ),
            ];
            $totalDebit  += $amount;
            $totalCredit += $amount;
        }

        return [
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'line_count'   => count($lines),
            'lines'        => $lines,
            'warnings'     => $warnings,
        ];
    }

    /**
     * Persist hasil build ke `erp_journal_exports`. Mode default DRAFT —
     * butuh action `post` terpisah agar reviewable.
     */
    public function persistExport(
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $format,
        array $build,
        ?int $connectionId = null,
        ?string $periodLabel = null
    ): int {
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('source_type tidak valid');
        }
        if (! in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('format tidak valid');
        }
        if (! self::isBalancedPure((float) $build['total_debit'], (float) $build['total_credit'])) {
            throw new \RuntimeException('Jurnal tidak balance: debit ≠ kredit. Tidak boleh disimpan.');
        }

        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $row = [
            'company_id'   => $companyId,
            'connection_id'=> $connectionId,
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'period_label' => $periodLabel,
            'format'       => $format,
            'total_debit'  => $build['total_debit'],
            'total_credit' => $build['total_credit'],
            'line_count'   => $build['line_count'],
            'lines_json'   => json_encode($build['lines'], JSON_UNESCAPED_UNICODE),
            'status'       => 'DRAFT',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $db->table('erp_journal_exports')->insert($row);
        return (int) $db->insertID();
    }

    /**
     * Render export ke CSV string (generic format).
     * Format: cost_center_id;account_type;debit_coa;credit_coa;amount;description
     */
    public function renderCsv(array $build): string
    {
        $rows = [['cost_center_id', 'account_type', 'debit_coa', 'credit_coa', 'amount', 'description']];
        foreach ($build['lines'] ?? [] as $line) {
            $rows[] = [
                (string) ($line['cost_center_id'] ?? ''),
                (string) ($line['account_type']   ?? ''),
                (string) ($line['debit_coa']      ?? ''),
                (string) ($line['credit_coa']     ?? ''),
                number_format((float) ($line['amount'] ?? 0), 2, '.', ''),
                (string) ($line['description']    ?? ''),
            ];
        }
        return self::toCsvPure($rows);
    }

    /**
     * Mark export sebagai POSTED. Idealnya dipanggil setelah adapter
     * eksternal sukses. Untuk CSV-only flow, dipanggil setelah download.
     */
    public function markPosted(int $exportId, int $userId, ?string $responseLog = null): void
    {
        $db = Database::connect();
        $db->table('erp_journal_exports')
            ->where('id', $exportId)
            ->update([
                'status'            => 'POSTED',
                'posted_at'         => date('Y-m-d H:i:s'),
                'posted_by_user_id' => $userId,
                'response_log'      => $responseLog,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Mark export sebagai REVERSED (audit trail untuk koreksi).
     */
    public function markReversed(int $exportId, ?string $reason = null): void
    {
        $db = Database::connect();
        $db->table('erp_journal_exports')
            ->where('id', $exportId)
            ->update([
                'status'       => 'REVERSED',
                'reversed_at'  => date('Y-m-d H:i:s'),
                'response_log' => $reason,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
    }

    // ─── Helpers internal ──────────────────────────────────────────────

    /** @param array<int, array<string,mixed>> $payslips */
    private function findPayslip(array $payslips, int $id): ?array
    {
        foreach ($payslips as $p) {
            if ((int) $p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Map payslip_detail row ke account_type berdasarkan kategori
     * komponen. Kolom payslip_details umumnya punya `category`
     * (EARNING/DEDUCTION) + `code` / `name`. Kita lakukan classification
     * yang konservatif: kalau tidak yakin → kembalikan null (skip).
     */
    private function mapDetailToAccountType(array $detail): ?string
    {
        $category = strtoupper((string) ($detail['category'] ?? ''));
        $code     = strtoupper((string) ($detail['code']     ?? $detail['name'] ?? ''));
        $isNatura = (int) ($detail['is_natura'] ?? 0) === 1;

        if ($category === 'EARNING') {
            if (str_contains($code, 'OVERTIME') || str_contains($code, 'LEMBUR')) {
                return 'OVERTIME';
            }
            if (str_contains($code, 'THR')) {
                return 'THR';
            }
            if (str_contains($code, 'BONUS') || str_contains($code, 'KOMPENSASI')) {
                return 'BONUS';
            }
            if (str_contains($code, 'BASIC') || str_contains($code, 'GAJI POKOK') || str_contains($code, 'POKOK')) {
                return 'SALARY';
            }
            // Tunjangan
            return $isNatura ? 'ALLOWANCE_NATURA' : 'ALLOWANCE_CASH';
        }
        if ($category === 'DEDUCTION') {
            if (str_contains($code, 'LOAN') || str_contains($code, 'CICILAN') || str_contains($code, 'PINJAMAN')) {
                return 'LOAN_DEDUCTION';
            }
            if (str_contains($code, 'BPJS_KES') || str_contains($code, 'BPJS KESEHATAN')) {
                return 'BPJS_KES';
            }
            if (str_contains($code, 'BPJS') || str_contains($code, 'JKN') || str_contains($code, 'JHT') || str_contains($code, 'JP')) {
                return 'BPJS_TK';
            }
            if (str_contains($code, 'PPH') || str_contains($code, 'PAJAK')) {
                return 'PPH21';
            }
            return 'OTHER_DEDUCTION';
        }
        return null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadMappings(int $companyId): array
    {
        $db = Database::connect();
        if (! $db->tableExists('erp_journal_mappings')) {
            return [];
        }
        return $db->table('erp_journal_mappings')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->get()
            ->getResultArray();
    }

    private function renderDescription(?string $template, array $vars): string
    {
        if ($template === null || $template === '') {
            return sprintf(
                '%s · cost_center=%s',
                $vars['account_type'] ?? '',
                $vars['cost_center_id'] ?? '',
            );
        }
        $out = $template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    /**
     * @param array<int, string> $warnings
     */
    private function emptyResult(array $warnings): array
    {
        return [
            'total_debit'  => 0.0,
            'total_credit' => 0.0,
            'line_count'   => 0,
            'lines'        => [],
            'warnings'     => $warnings,
        ];
    }

    // ─── PURE HELPERS (untuk unit test) ─────────────────────────────────

    /**
     * Cari mapping yang paling spesifik:
     *  1. cost_center_id match + account_type match
     *  2. cost_center_id NULL (catch-all) + account_type match
     *  3. NULL kalau tidak ada
     *
     * @param array<int, array<string,mixed>> $mappings
     */
    public static function pickMappingPure(
        array $mappings,
        ?int $costCenterId,
        string $accountType
    ): ?array {
        // Priority 1: spesifik cost center
        if ($costCenterId !== null) {
            foreach ($mappings as $m) {
                if ((int) ($m['cost_center_id'] ?? 0) === $costCenterId
                    && (string) ($m['account_type'] ?? '') === $accountType
                    && (int) ($m['is_active'] ?? 0) === 1
                ) {
                    return $m;
                }
            }
        }
        // Priority 2: catch-all
        foreach ($mappings as $m) {
            if (($m['cost_center_id'] === null || $m['cost_center_id'] === 0 || $m['cost_center_id'] === '0')
                && (string) ($m['account_type'] ?? '') === $accountType
                && (int) ($m['is_active'] ?? 0) === 1
            ) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Cek apakah jurnal balance dalam toleransi 1 sen
     * (akumulasi rounding bisa drift sedikit).
     */
    public static function isBalancedPure(float $totalDebit, float $totalCredit, float $tolerance = 0.01): bool
    {
        return abs($totalDebit - $totalCredit) <= $tolerance;
    }

    /**
     * Convert array of rows ke CSV string. Pure agar bisa di-test.
     *
     * @param array<int, array<int, string>> $rows
     */
    public static function toCsvPure(array $rows, string $delimiter = ';'): string
    {
        $out = [];
        foreach ($rows as $row) {
            $escaped = array_map(static function ($v) use ($delimiter) {
                $s = (string) $v;
                if (str_contains($s, '"') || str_contains($s, $delimiter) || str_contains($s, "\n")) {
                    $s = '"' . str_replace('"', '""', $s) . '"';
                }
                return $s;
            }, $row);
            $out[] = implode($delimiter, $escaped);
        }
        return implode("\n", $out);
    }

    /**
     * Validasi konfig mapping baru.
     *
     * @return array<int, string> daftar error (kosong = valid)
     */
    public static function validateMappingPure(array $row): array
    {
        $errors = [];
        $type = (string) ($row['account_type'] ?? '');
        if (! in_array($type, self::ACCOUNT_TYPES, true)) {
            $errors[] = 'account_type tidak valid';
        }
        $debit = trim((string) ($row['debit_coa'] ?? ''));
        $credit = trim((string) ($row['credit_coa'] ?? ''));
        if ($debit === '') {
            $errors[] = 'debit_coa wajib diisi';
        }
        if ($credit === '') {
            $errors[] = 'credit_coa wajib diisi';
        }
        if ($debit !== '' && $debit === $credit) {
            $errors[] = 'debit_coa dan credit_coa tidak boleh sama';
        }
        return $errors;
    }
}