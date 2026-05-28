<?php

namespace App\Libraries\Import;

use Config\Services;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XlsxImportService — pipeline tunggal untuk semua import xlsx.
 *
 * Tahapan (lihat ImportController):
 *
 *   1. preview(file, resourceSlug, companyId, userId):
 *      - Hash isi file (sha256), cek `import_batches` yang sudah ada untuk
 *        resource + company + hash sama → kalau ada (status PREVIEW/COMMITTED),
 *        return batch existing (idempotent).
 *      - Simpan file ke writable/imports/<companyId>/<batchId>.xlsx
 *      - Parse pakai PhpSpreadsheet → array of rows by header
 *      - Validate per kolom (required, type, regex, enum, FK lookup, unique)
 *      - Simpan errors_json + preview_json (50 row pertama) ke batch
 *      - Return batch ID + summary (total, valid, error)
 *
 *   2. commit(batchId):
 *      - Load batch yang status PREVIEW dan punya 0 error_rows
 *      - Insert per-row ke tabel target dalam transaction
 *      - Field encrypted (NPWP, bank account) di-handle khusus
 *      - Update status → COMMITTED + audit log
 *
 *   3. generateTemplate(resourceSlug):
 *      - Generate xlsx dengan 2 sheet: "Data" (header + sample rows) + "Petunjuk"
 *      - Header di-bold + warna biru, freeze pane row 1
 *      - Return path xlsx temp untuk di-stream sebagai download
 *
 * Limit:
 *   - File size max 10 MB
 *   - Total row max 5000 (config-able lewat env IMPORT_MAX_ROWS)
 */
class XlsxImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;     // 10 MB
    public const MAX_ROWS  = 5000;
    public const PREVIEW_ROWS = 50;

    /**
     * Generate xlsx template kosong + sample rows + sheet petunjuk.
     * Return: full path file temp (caller bertanggung jawab cleanup setelah stream).
     */
    public function generateTemplate(array $spec): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Data');

        // ─── Header row ────────────────────────────────────────────
        $col = 1;
        foreach ($spec['columns'] as $c) {
            $cellRef = $this->colLetter($col) . '1';
            $header  = $c['header'];
            if (! empty($c['required'])) {
                $header .= ' *';
            }
            $sheet->setCellValue($cellRef, $header);
            $col++;
        }
        // Format header: bold, white-on-blue, center
        $lastCol = $this->colLetter(count($spec['columns']));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->freezePane('A2');

        // Auto-width approx
        foreach ($spec['columns'] as $idx => $c) {
            $sheet->getColumnDimension($this->colLetter($idx + 1))
                ->setWidth(max(12, min(40, mb_strlen($c['header']) + 6)));
        }

        // ─── Sample rows ───────────────────────────────────────────
        $row = 2;
        foreach ($spec['sample_row'] ?? [] as $sample) {
            $col = 1;
            foreach ($sample as $val) {
                $cellRef = $this->colLetter($col) . $row;
                // Force string mode untuk kolom kode/no biar tidak ke-format jadi number
                $sheet->setCellValueExplicit(
                    $cellRef,
                    (string) $val,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                $col++;
            }
            $row++;
        }
        if ($row > 2) {
            $sheet->getStyle("A2:{$lastCol}" . ($row - 1))->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFBEB']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'EEEEEE']]],
            ]);
        }

        // ─── Petunjuk sheet ─────────────────────────────────────────
        $help = $ss->createSheet();
        $help->setTitle('Petunjuk');
        $help->setCellValue('A1', 'PETUNJUK PENGISIAN — ' . ($spec['label'] ?? $spec['slug']));
        $help->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '1F2937']],
        ]);
        $help->setCellValue('A3', $spec['notes_md'] ?? '');
        $help->getStyle('A3')->getAlignment()->setWrapText(true);
        $help->getColumnDimension('A')->setWidth(120);
        $help->getStyle('A3')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // List kolom
        $help->setCellValue('A5', 'KOLOM YANG TERSEDIA');
        $help->getStyle('A5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4B5563']],
        ]);
        $help->setCellValue('A6', 'Header');
        $help->setCellValue('B6', 'Wajib?');
        $help->setCellValue('C6', 'Tipe');
        $help->setCellValue('D6', 'Catatan / Pilihan Nilai');
        $help->getStyle('A6:D6')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
        ]);
        $help->getColumnDimension('B')->setWidth(10);
        $help->getColumnDimension('C')->setWidth(14);
        $help->getColumnDimension('D')->setWidth(80);

        $r = 7;
        foreach ($spec['columns'] as $c) {
            $help->setCellValue("A{$r}", $c['header']);
            $help->setCellValue("B{$r}", ! empty($c['required']) ? 'Wajib' : '-');
            $help->setCellValue("C{$r}", strtoupper($c['type']));
            $note = $c['help'] ?? '';
            if (! empty($c['in'])) {
                $note .= ($note ? "\n" : '') . 'Nilai yang diizinkan: ' . implode(', ', $c['in']);
            }
            if (! empty($c['fk_table'])) {
                $note .= ($note ? "\n" : '') . "Referensi ke {$c['fk_table']}.{$c['fk_column']} (master harus sudah ada).";
            }
            if (! empty($c['regex'])) {
                $note .= ($note ? "\n" : '') . 'Format: ' . $c['regex'];
            }
            if (isset($c['default'])) {
                $note .= ($note ? "\n" : '') . 'Default kalau kosong: ' . $c['default'];
            }
            $help->setCellValue("D{$r}", $note);
            $help->getStyle("D{$r}")->getAlignment()->setWrapText(true);
            $r++;
        }

        // ─── Write to temp file ─────────────────────────────────────
        $tmpDir = WRITEPATH . 'imports/templates';
        if (! is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $path = $tmpDir . DIRECTORY_SEPARATOR . sprintf(
            'template-%s-%s.xlsx', $spec['slug'], date('YmdHis')
        );
        $writer = new XlsxWriter($ss);
        $writer->save($path);

        // Cleanup memory
        $ss->disconnectWorksheets();
        unset($ss, $writer);

        return $path;
    }

    /**
     * Parse + validate file. Tidak commit ke DB target.
     *
     * @return array{batch_id:int, total:int, valid:int, errors:int, errors_list:array, preview:array, status:string}
     */
    public function preview(
        string $tmpPath,
        string $originalName,
        int $fileSize,
        array $spec,
        int $companyId,
        int $userId
    ): array {
        // ─── 1. Idempotency check by content hash ──────────────────
        $hash = hash_file('sha256', $tmpPath);
        $db = \Config\Database::connect();
        $existing = $db->table('import_batches')
            ->where('company_id', $companyId)
            ->where('resource', $spec['slug'])
            ->where('content_hash', $hash)
            ->whereIn('status', ['PREVIEW', 'COMMITTED'])
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        if ($existing) {
            return [
                'batch_id'    => (int) $existing['id'],
                'total'       => (int) $existing['total_rows'],
                'valid'       => (int) $existing['valid_rows'],
                'errors'      => (int) $existing['error_rows'],
                'errors_list' => json_decode($existing['errors_json'] ?? '[]', true) ?: [],
                'preview'     => json_decode($existing['preview_json'] ?? '[]', true) ?: [],
                'status'      => $existing['status'],
                'idempotent'  => true,
            ];
        }

        // ─── 2. Parse via PhpSpreadsheet ───────────────────────────
        try {
            $reader = IOFactory::createReaderForFile($tmpPath);
            $reader->setReadDataOnly(true);
            $ss = $reader->load($tmpPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Gagal membaca file xlsx: " . $e->getMessage());
        }
        $sheet = $ss->getSheet(0);
        $rowsRaw = $sheet->toArray(null, true, true, false);
        $ss->disconnectWorksheets();
        unset($ss);

        if (empty($rowsRaw)) {
            throw new \RuntimeException('File kosong atau tidak ada sheet pertama.');
        }

        // First row = header, sisanya data
        $headerRow = array_shift($rowsRaw);
        $headerMap = $this->mapHeaders($headerRow, $spec['columns']);

        // ─── 3. Save file + create batch row ───────────────────────
        $batchInsert = [
            'company_id'    => $companyId,
            'resource'      => $spec['slug'],
            'user_id'       => $userId,
            'original_name' => mb_substr($originalName, 0, 255),
            'file_size'     => $fileSize,
            'content_hash'  => $hash,
            'status'        => 'PENDING',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        $db->table('import_batches')->insert($batchInsert);
        $batchId = (int) $db->insertID();

        // Pindahkan file ke writable storage permanent
        $storageDir = WRITEPATH . 'imports' . DIRECTORY_SEPARATOR . $companyId;
        if (! is_dir($storageDir)) @mkdir($storageDir, 0775, true);
        $finalPath = $storageDir . DIRECTORY_SEPARATOR . "batch-{$batchId}.xlsx";
        @copy($tmpPath, $finalPath);
        $db->table('import_batches')->where('id', $batchId)->update([
            'file_path' => 'imports/' . $companyId . '/batch-' . $batchId . '.xlsx',
        ]);

        // ─── 4. Validate per row ───────────────────────────────────
        // Pre-load FK lookup tables sekali saja (huge perf win untuk file besar)
        $fkLookups = $this->buildFkLookups($spec['columns'], $companyId);

        // Pre-load existing values untuk unique_in_table
        $uniqueLookups = $this->buildUniqueLookups($spec['columns'], $spec['table'], $companyId);

        $errors  = [];
        $valid   = 0;
        $preview = [];
        $batchSeen = []; // [field => [normalizedValue => firstRowIdx]] untuk unique_in_batch
        $compositeSeenBatch = []; // composite_unique: [key => firstRowIdx]

        // Pre-load composite unique existing data dari DB
        $compositeExistingDb = [];
        if (! empty($spec['composite_unique'])) {
            $compositeExistingDb = $this->buildCompositeUniqueLookup(
                $spec['composite_unique'], $spec['table'], $companyId
            );
        }

        $totalRows = count($rowsRaw);
        if ($totalRows > self::MAX_ROWS) {
            $db->table('import_batches')->where('id', $batchId)->update([
                'status'        => 'FAILED',
                'failed_reason' => "Jumlah baris {$totalRows} melebihi batas " . self::MAX_ROWS,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            throw new \RuntimeException("Jumlah baris {$totalRows} melebihi batas " . self::MAX_ROWS);
        }

        foreach ($rowsRaw as $idx => $row) {
            $rowNum = $idx + 2; // baris di Excel (1-based, header = 1)

            // Skip baris kosong total
            $allEmpty = true;
            foreach ($row as $v) {
                if ($v !== null && trim((string) $v) !== '') { $allEmpty = false; break; }
            }
            if ($allEmpty) continue;

            $rowData   = []; // db field → final value (after coerce + FK lookup)
            $rowErrors = [];

            foreach ($spec['columns'] as $col) {
                $excelCol = $headerMap[$col['header']] ?? null;
                $rawVal = $excelCol !== null ? ($row[$excelCol] ?? null) : null;
                $rawVal = $rawVal === null ? '' : trim((string) $rawVal);

                $field = $col['field'];

                // Apply default if blank
                if ($rawVal === '' && isset($col['default'])) {
                    $rowData[$field] = $col['default'];
                    continue;
                }

                // Required
                if ($rawVal === '' && ! empty($col['required'])) {
                    $rowErrors[] = ['column' => $col['header'], 'error' => 'Wajib diisi'];
                    continue;
                }
                if ($rawVal === '') {
                    $rowData[$field] = null;
                    continue;
                }

                // Max length
                if (! empty($col['max_length']) && mb_strlen($rawVal) > $col['max_length']) {
                    $rowErrors[] = [
                        'column' => $col['header'],
                        'error'  => "Maks {$col['max_length']} karakter, sekarang " . mb_strlen($rawVal),
                    ];
                    continue;
                }

                // Type coerce + validate
                try {
                    $coerced = $this->coerceValue($rawVal, $col, $fkLookups);
                } catch (\Throwable $e) {
                    $rowErrors[] = ['column' => $col['header'], 'error' => $e->getMessage()];
                    continue;
                }

                // Regex
                if (! empty($col['regex']) && ! preg_match($col['regex'], (string) $rawVal)) {
                    $rowErrors[] = ['column' => $col['header'], 'error' => "Format tidak sesuai pola {$col['regex']}"];
                    continue;
                }

                // unique_in_batch
                if (! empty($col['unique_in_batch'])) {
                    $key = strtolower((string) $rawVal);
                    if (isset($batchSeen[$field][$key])) {
                        $rowErrors[] = [
                            'column' => $col['header'],
                            'error'  => "Duplikat di baris " . $batchSeen[$field][$key] . " (di file ini)",
                        ];
                        continue;
                    }
                    $batchSeen[$field][$key] = $rowNum;
                }

                // unique_in_table
                if (! empty($col['unique_in_table'])) {
                    $key = strtolower((string) $rawVal);
                    if (isset($uniqueLookups[$field][$key])) {
                        $rowErrors[] = [
                            'column' => $col['header'],
                            'error'  => "Sudah ada di database (id=" . $uniqueLookups[$field][$key] . ")",
                        ];
                        continue;
                    }
                }

                $rowData[$field] = $coerced;
            }

            if (! empty($rowErrors)) {
                foreach ($rowErrors as $e) {
                    $errors[] = ['row' => $rowNum, 'column' => $e['column'], 'error' => $e['error']];
                }
            } else {
                // ─── Composite unique check ─────────────────────────
                if (! empty($spec['composite_unique'])) {
                    $compositeKey = implode('|', array_map(
                        fn ($f) => strtolower((string) ($rowData[$f] ?? '')),
                        $spec['composite_unique']
                    ));
                    if (isset($compositeSeenBatch[$compositeKey])) {
                        $rowErrors[] = [
                            'column' => implode(' + ', $spec['composite_unique']),
                            'error'  => "Duplikat kombinasi di baris " . $compositeSeenBatch[$compositeKey] . " (di file ini)",
                        ];
                        foreach ($rowErrors as $e) {
                            $errors[] = ['row' => $rowNum, 'column' => $e['column'], 'error' => $e['error']];
                        }
                    } elseif (isset($compositeExistingDb[$compositeKey]) && empty($spec['upsert_on_conflict'])) {
                        $rowErrors[] = [
                            'column' => implode(' + ', $spec['composite_unique']),
                            'error'  => "Kombinasi sudah ada di database (id=" . $compositeExistingDb[$compositeKey] . ")",
                        ];
                        foreach ($rowErrors as $e) {
                            $errors[] = ['row' => $rowNum, 'column' => $e['column'], 'error' => $e['error']];
                        }
                    } else {
                        $compositeSeenBatch[$compositeKey] = $rowNum;
                    }
                }
                if (empty($rowErrors)) $valid++;
            }

            if (count($preview) < self::PREVIEW_ROWS) {
                $preview[] = [
                    'row'       => $rowNum,
                    'data'      => $rowData,
                    'has_error' => ! empty($rowErrors),
                    'errors'    => $rowErrors,
                ];
            }

            // Simpan SEMUA rowData yang valid ke preview_json supaya commit() tidak perlu re-parse.
            // (Untuk file kecil <5000 row ini muat di MEDIUMTEXT.)
            // → kita simpan di field tersembunyi nanti via "rows_to_insert"
        }

        // Re-parse all valid rows untuk commit (kecil: di-cache di preview_json field "rows")
        // Strategi simpel: simpan ulang valid rows full-data di preview_json bersama summary.
        // (Untuk avoid second parse di commit step.)
        $validRowsFull = [];
        // Kalau preview cuma 50 row + total > 50, kita perlu parse lagi → do that now efficiently.
        if ($valid > self::PREVIEW_ROWS) {
            // Re-iterate from rowsRaw karena kita sudah punya parsed array
            foreach ($rowsRaw as $idx => $row) {
                $rowNum = $idx + 2;
                $allEmpty = true;
                foreach ($row as $v) { if ($v !== null && trim((string) $v) !== '') { $allEmpty = false; break; } }
                if ($allEmpty) continue;

                $rowData = [];
                $rowOk = true;
                foreach ($spec['columns'] as $col) {
                    $excelCol = $headerMap[$col['header']] ?? null;
                    $rawVal = $excelCol !== null ? ($row[$excelCol] ?? null) : null;
                    $rawVal = $rawVal === null ? '' : trim((string) $rawVal);
                    if ($rawVal === '' && isset($col['default'])) { $rowData[$col['field']] = $col['default']; continue; }
                    if ($rawVal === '' && ! empty($col['required'])) { $rowOk = false; break; }
                    if ($rawVal === '') { $rowData[$col['field']] = null; continue; }
                    try {
                        $rowData[$col['field']] = $this->coerceValue($rawVal, $col, $fkLookups);
                    } catch (\Throwable $e) { $rowOk = false; break; }
                }
                if ($rowOk) $validRowsFull[] = ['row' => $rowNum, 'data' => $rowData];
            }
        } else {
            // Cukup ambil dari preview
            foreach ($preview as $p) {
                if (! $p['has_error']) $validRowsFull[] = ['row' => $p['row'], 'data' => $p['data']];
            }
        }

        // ─── 5. Update batch dengan hasil ──────────────────────────
        $errorRows = count(array_unique(array_column($errors, 'row')));
        $finalStatus = ($errorRows === 0 && $valid > 0) ? 'PREVIEW' : ($valid === 0 ? 'FAILED' : 'PREVIEW');

        $payload = [
            'status'         => $finalStatus,
            'total_rows'     => $totalRows,
            'valid_rows'     => $valid,
            'error_rows'     => $errorRows,
            'errors_json'    => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'preview_json'   => json_encode([
                'preview' => $preview,
                'rows'    => $validRowsFull,
            ], JSON_UNESCAPED_UNICODE),
            'previewed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        if ($valid === 0 && $errorRows > 0) {
            $payload['failed_reason'] = "Semua {$totalRows} baris gagal validasi.";
        }
        $db->table('import_batches')->where('id', $batchId)->update($payload);

        return [
            'batch_id'    => $batchId,
            'total'       => $totalRows,
            'valid'       => $valid,
            'errors'      => $errorRows,
            'errors_list' => $errors,
            'preview'     => $preview,
            'status'      => $finalStatus,
            'idempotent'  => false,
        ];
    }

    /**
     * Commit batch yang sudah PREVIEW dan 0 error ke tabel target.
     *
     * @return array{batch_id:int, inserted:int, skipped:int}
     */
    public function commit(int $batchId, array $spec, int $companyId, int $userId): array
    {
        $db = \Config\Database::connect();
        $batch = $db->table('import_batches')
            ->where('id', $batchId)
            ->where('company_id', $companyId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if (! $batch) {
            throw new \RuntimeException('Batch tidak ditemukan');
        }
        if ($batch['resource'] !== $spec['slug']) {
            throw new \RuntimeException('Resource batch tidak match');
        }
        if ($batch['status'] === 'COMMITTED') {
            return [
                'batch_id' => $batchId,
                'inserted' => (int) $batch['inserted_count'],
                'skipped'  => (int) $batch['skipped_count'],
                'already_committed' => true,
            ];
        }
        if ($batch['status'] !== 'PREVIEW') {
            throw new \RuntimeException("Batch status {$batch['status']}, hanya status PREVIEW yang bisa commit");
        }
        if ((int) $batch['error_rows'] > 0) {
            throw new \RuntimeException(
                "Batch masih punya {$batch['error_rows']} baris error. Perbaiki file dulu lalu re-upload."
            );
        }

        $previewPayload = json_decode($batch['preview_json'] ?? '{}', true) ?: [];
        $rows = $previewPayload['rows'] ?? [];
        if (empty($rows)) {
            throw new \RuntimeException('Tidak ada baris valid untuk di-commit');
        }

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $skipped  = 0;

        $db->transStart();
        foreach ($rows as $r) {
            $data = $r['data'];

            // Inject company_id & timestamps
            $data['company_id'] = $companyId;
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if (! isset($data['is_active'])) $data['is_active'] = 1;

            // Handle encrypted fields (npwp, bank account). Encryption::encrypt
            // mengembalikan ciphertext string ber-prefix versi (mis. "v2:..."),
            // jadi nilai dari Encryption::activeVersion() di-mirror ke
            // kolom `key_version` kalau tabel target punya kolom itu.
            $encColumns = $this->encryptedColumns($spec['columns']);
            if (! empty($encColumns)) {
                $enc = Services::encryption();
                $activeVer = method_exists($enc, 'activeVersion') ? $enc->activeVersion() : 'v1';
                foreach ($encColumns as $field) {
                    if (! empty($data[$field])) {
                        try {
                            $cipher = $enc->encrypt((string) $data[$field]);
                            if ($cipher !== null) {
                                $data[$field] = $cipher;
                                // key_version disimpan sebagai integer
                                // (skema lama). "v2" → 2, "v1" → 1.
                                $data['key_version'] = (int) preg_replace('/[^0-9]/', '', $activeVer) ?: 1;
                            }
                        } catch (\Throwable $e) {
                            log_message('warning', 'Import encrypt failed: ' . $e->getMessage());
                        }
                    }
                }
            }

            // Filter ke field yang ada di tabel
            $existingFields = $db->getFieldNames($spec['table']);
            $insertSafe = array_intersect_key($data, array_flip($existingFields));

            // Khusus employees: derive full_name + nik_hash kalau ada
            if ($spec['slug'] === 'employees') {
                $first = (string) ($insertSafe['first_name'] ?? '');
                $last  = (string) ($insertSafe['last_name']  ?? '');
                if (in_array('full_name', $existingFields, true)) {
                    $insertSafe['full_name'] = trim($first . ' ' . $last);
                }
                if (! empty($data['nik_encrypted']) && in_array('nik_hash', $existingFields, true)) {
                    $insertSafe['nik_hash'] = hash('sha256', (string) $data['nik_encrypted']);
                }
            }

            // Khusus attendances: auto-set is_corrected + correction_reason
            if ($spec['slug'] === 'attendances') {
                if (in_array('is_corrected', $existingFields, true)) {
                    $insertSafe['is_corrected'] = 1;
                }
                if (in_array('correction_reason', $existingFields, true)) {
                    $insertSafe['correction_reason'] = 'Bulk import backfill';
                }
                // Resolve time-only clock_in_at / clock_out_at with work_date
                $workDate = $insertSafe['work_date'] ?? '';
                foreach (['clock_in_at', 'clock_out_at'] as $dtField) {
                    $v = $insertSafe[$dtField] ?? null;
                    if ($v && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) {
                        $insertSafe[$dtField] = $workDate . ' ' . str_pad($v, 8, ':00');
                    }
                }
            }

            // Auto-inject fields dari spec (mis. bonus_recipients.amount_overridden = 1)
            if (! empty($spec['auto_inject'])) {
                foreach ($spec['auto_inject'] as $field => $value) {
                    if (in_array($field, $existingFields, true)) {
                        $insertSafe[$field] = $value;
                    }
                }
            }

            // Khusus bonus_recipients: snapshot employee data
            if ($spec['slug'] === 'bonus_recipients') {
                $empId = $insertSafe['employee_id'] ?? null;
                if ($empId) {
                    $emp = $db->table('employees')
                        ->select('employee_number, first_name, last_name, basic_salary, join_date, department_id, position_id')
                        ->where('id', $empId)
                        ->get()->getRowArray();
                    if ($emp) {
                        if (in_array('employee_number', $existingFields, true)) {
                            $insertSafe['employee_number'] = $emp['employee_number'] ?? '';
                        }
                        if (in_array('employee_name', $existingFields, true)) {
                            $insertSafe['employee_name'] = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                        }
                        if (in_array('monthly_salary', $existingFields, true)) {
                            $insertSafe['monthly_salary'] = (float) ($emp['basic_salary'] ?? 0);
                        }
                        if (in_array('tenure_months', $existingFields, true) && ! empty($emp['join_date'])) {
                            $joinTs = strtotime($emp['join_date']);
                            $insertSafe['tenure_months'] = $joinTs ? max(0, (int) round((time() - $joinTs) / (30.44 * 86400))) : 0;
                        }
                        if (in_array('department_name', $existingFields, true) && ! empty($emp['department_id'])) {
                            $dept = $db->table('departments')->select('name')->where('id', $emp['department_id'])->get()->getRowArray();
                            $insertSafe['department_name'] = $dept['name'] ?? '';
                        }
                        if (in_array('position_name', $existingFields, true) && ! empty($emp['position_id'])) {
                            $pos = $db->table('positions')->select('name')->where('id', $emp['position_id'])->get()->getRowArray();
                            $insertSafe['position_name'] = $pos['name'] ?? '';
                        }
                    }
                }
            }

            try {
                // Upsert support: kalau spec punya upsert_on_conflict, update existing row
                if (! empty($spec['upsert_on_conflict']) && ! empty($spec['composite_unique'])) {
                    $whereConditions = [];
                    foreach ($spec['composite_unique'] as $f) {
                        $whereConditions[$f] = $insertSafe[$f] ?? null;
                    }
                    $whereConditions['company_id'] = $companyId;
                    $existingRow = $db->table($spec['table'])->where($whereConditions)->get()->getRowArray();
                    if ($existingRow) {
                        unset($insertSafe['created_at']);
                        $db->table($spec['table'])->where('id', $existingRow['id'])->update($insertSafe);
                        $inserted++;
                        continue;
                    }
                }
                $db->table($spec['table'])->insert($insertSafe);
                $inserted++;
            } catch (\Throwable $e) {
                $skipped++;
                log_message('warning', "Import row #{$r['row']} skip: " . $e->getMessage());
            }
        }
        $db->transComplete();

        $finalStatus = $db->transStatus() ? 'COMMITTED' : 'FAILED';
        $db->table('import_batches')->where('id', $batchId)->update([
            'status'         => $finalStatus,
            'inserted_count' => $inserted,
            'skipped_count'  => $skipped,
            'committed_at'   => $finalStatus === 'COMMITTED' ? $now : null,
            'updated_at'     => $now,
        ]);

        Services::audit()->forModule('IMPORT')->log(
            action: $spec['slug'] . '.bulk_import',
            entity: 'import_batch',
            entityId: $batchId,
            after: ['inserted' => $inserted, 'skipped' => $skipped],
            description: "Bulk import {$spec['label']}: {$inserted} berhasil, {$skipped} gagal",
            severity: 'NOTICE',
        );

        return [
            'batch_id' => $batchId,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'status'   => $finalStatus,
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Map header xlsx ke index kolom (case-insensitive, ignore whitespace).
     * Return: [header_label => column_index_in_excel]
     */
    private function mapHeaders(array $headerRow, array $columns): array
    {
        $normalized = [];
        foreach ($headerRow as $idx => $h) {
            $key = strtolower(preg_replace('/\s+/', ' ', trim((string) $h)));
            // Strip "*" suffix yang dipakai di template
            $key = rtrim($key, " *");
            $normalized[$key] = $idx;
        }
        $map = [];
        foreach ($columns as $c) {
            $key = strtolower(preg_replace('/\s+/', ' ', trim($c['header'])));
            $map[$c['header']] = $normalized[$key] ?? null;
        }
        return $map;
    }

    /**
     * Build FK lookup tables sekali saja: { field => { fk_value_lower => id } }
     */
    private function buildFkLookups(array $columns, int $companyId): array
    {
        $db = \Config\Database::connect();
        $lookups = [];
        foreach ($columns as $c) {
            if ($c['type'] !== 'fk') continue;
            $tbl = $c['fk_table'];
            $col = $c['fk_column'];
            if (! $db->tableExists($tbl)) continue;
            $b = $db->table($tbl)->select("id, {$col}");
            // Filter by company_id kalau tabel target multi-tenant
            $tblFields = $db->getFieldNames($tbl);
            if (in_array('company_id', $tblFields, true)) {
                $b->where('company_id', $companyId);
            }
            if (in_array('deleted_at', $tblFields, true)) {
                $b->where('deleted_at IS NULL');
            }
            $rows = $b->get()->getResultArray();
            $map = [];
            foreach ($rows as $r) {
                $map[strtolower((string) $r[$col])] = (int) $r['id'];
            }
            $lookups[$c['field']] = $map;
        }
        return $lookups;
    }

    /**
     * Build unique lookup: ambil semua nilai existing untuk kolom unique_in_table
     */
    private function buildUniqueLookups(array $columns, string $table, int $companyId): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists($table)) return [];
        $tblFields = $db->getFieldNames($table);

        $lookups = [];
        foreach ($columns as $c) {
            if (empty($c['unique_in_table'])) continue;
            $f = $c['field'];
            if (! in_array($f, $tblFields, true)) continue;
            $b = $db->table($table)->select("id, {$f}");
            if (in_array('company_id', $tblFields, true)) $b->where('company_id', $companyId);
            if (in_array('deleted_at', $tblFields, true)) $b->where('deleted_at IS NULL');
            $rows = $b->get()->getResultArray();
            $map = [];
            foreach ($rows as $r) {
                $v = $r[$f] ?? null;
                if ($v === null || $v === '') continue;
                $map[strtolower((string) $v)] = (int) $r['id'];
            }
            $lookups[$f] = $map;
        }
        return $lookups;
    }

    /**
     * Coerce raw string ke tipe yang sesuai. Throw kalau tidak valid.
     */
    private function coerceValue(string $val, array $col, array $fkLookups)
    {
        switch ($col['type']) {
            case 'int':
                if (! preg_match('/^-?\d+$/', $val)) {
                    throw new \RuntimeException('Harus angka bulat');
                }
                return (int) $val;

            case 'float':
                $clean = str_replace([',', ' '], ['', ''], $val);
                if (! is_numeric($clean)) {
                    throw new \RuntimeException('Harus angka');
                }
                return (float) $clean;

            case 'bool':
                $lower = strtolower($val);
                if (in_array($lower, ['1','y','yes','true','aktif','active'], true)) return 1;
                if (in_array($lower, ['0','n','no','false','non-aktif','inactive'], true)) return 0;
                throw new \RuntimeException("Nilai bool tidak valid (pakai 1/0 atau yes/no)");

            case 'date':
                // Accept YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY
                $ts = false;
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $fmt) {
                    $dt = \DateTime::createFromFormat($fmt, $val);
                    if ($dt && $dt->format($fmt) === $val) {
                        return $dt->format('Y-m-d');
                    }
                }
                // Last-resort: PHP strtotime
                $ts = strtotime($val);
                if ($ts !== false) {
                    return date('Y-m-d', $ts);
                }
                throw new \RuntimeException("Format tanggal tidak valid (pakai YYYY-MM-DD)");

            case 'datetime':
                // Accept full datetime: YYYY-MM-DD HH:MM:SS, YYYY-MM-DD HH:MM
                // Also accept time-only HH:MM or HH:MM:SS (will be combined with work_date at commit)
                if (preg_match('/^\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}(:\d{2})?$/', $val)) {
                    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', str_pad($val, 19, ':00'));
                    if (! $dt) $dt = \DateTime::createFromFormat('Y-m-d H:i', substr($val, 0, 16));
                    if ($dt) return $dt->format('Y-m-d H:i:s');
                }
                // Time-only: HH:MM or HH:MM:SS — return as-is, commit will combine with work_date
                if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $val)) {
                    return $val; // time-only, will be resolved at commit
                }
                // DD/MM/YYYY HH:MM:SS
                $dt = \DateTime::createFromFormat('d/m/Y H:i:s', $val);
                if ($dt) return $dt->format('Y-m-d H:i:s');
                $dt = \DateTime::createFromFormat('d/m/Y H:i', $val);
                if ($dt) return $dt->format('Y-m-d H:i:s');
                // Last-resort
                $ts = strtotime($val);
                if ($ts !== false) return date('Y-m-d H:i:s', $ts);
                throw new \RuntimeException("Format datetime tidak valid (pakai YYYY-MM-DD HH:MM:SS)");

            case 'enum':
                if (! in_array($val, $col['in'] ?? [], true)) {
                    throw new \RuntimeException("Nilai harus salah satu: " . implode(', ', $col['in'] ?? []));
                }
                return $val;

            case 'fk':
                $lookup = $fkLookups[$col['field']] ?? [];
                $id = $lookup[strtolower($val)] ?? null;
                if (! $id) {
                    throw new \RuntimeException(
                        "Nilai '{$val}' tidak ditemukan di master {$col['fk_table']} (kolom {$col['fk_column']})"
                    );
                }
                return $id;

            case 'email':
                if (! filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Format email tidak valid');
                }
                return $val;

            case 'phone':
                $clean = preg_replace('/[\s\-()]/', '', $val);
                if (! preg_match('/^(\+?62|0)\d{8,15}$/', $clean)) {
                    throw new \RuntimeException('Format nomor HP tidak valid (pakai 08xx atau +62)');
                }
                return $val;

            case 'encrypted':
                // Plain string di sini, encryption dilakukan saat commit
                return $val;

            case 'string':
            default:
                return $val;
        }
    }

    private function encryptedColumns(array $columns): array
    {
        $list = [];
        foreach ($columns as $c) {
            if (($c['type'] ?? '') === 'encrypted') $list[] = $c['field'];
        }
        return $list;
    }

    /**
     * Build composite unique lookup: load existing combinations from DB.
     * Return: [compositeKey => id]
     */
    private function buildCompositeUniqueLookup(array $fields, string $table, int $companyId): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists($table)) return [];
        $tblFields = $db->getFieldNames($table);

        // Pastikan semua field composite ada di tabel
        foreach ($fields as $f) {
            if (! in_array($f, $tblFields, true)) return [];
        }

        $selectFields = array_merge(['id'], $fields);
        $b = $db->table($table)->select(implode(',', $selectFields));
        if (in_array('company_id', $tblFields, true)) $b->where('company_id', $companyId);
        if (in_array('deleted_at', $tblFields, true)) $b->where('deleted_at IS NULL');
        $rows = $b->get()->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $key = implode('|', array_map(fn ($f) => strtolower((string) ($r[$f] ?? '')), $fields));
            $map[$key] = (int) $r['id'];
        }
        return $map;
    }

    private function colLetter(int $col): string
    {
        $letters = '';
        while ($col > 0) {
            $col--;
            $letters = chr(65 + ($col % 26)) . $letters;
            $col = intdiv($col, 26);
        }
        return $letters;
    }
}