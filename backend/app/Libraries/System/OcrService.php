<?php

namespace App\Libraries\System;

/**
 * OcrService (Sprint 13 / H2.8)
 *
 * OCR engine adapter untuk extract field dari struk reimbursement
 * (PDF / JPG / PNG). Tujuan: prefill field di form reimbursement agar
 * karyawan tidak perlu input manual amount, vendor, transaction_date.
 *
 * Strategy:
 *   1. Driver pluggable via env `OCR_DRIVER`:
 *      - `null` / `mock` (default DEV): return regex-based dummy result
 *      - `tesseract`: shell out ke `tesseract` CLI (lokal, gratis)
 *      - `gcloud_vision`: Google Cloud Vision API (production)
 *      - `aws_textract`: AWS Textract (alt production)
 *   2. Output normalized ke struktur yang sama: vendor, total, date, currency.
 *   3. Confidence score 0-1 — caller tampilkan ke user untuk review.
 *   4. Persist raw response ke `reimbursements.ocr_raw_data` untuk debug
 *      + re-extraction kalau model di-update.
 *
 * Tidak melakukan validasi server-side wajib — hasil OCR hanya hint.
 * User tetap harus konfirmasi field sebelum submit.
 */
class OcrService
{
    private string $driver;

    public function __construct(?string $driver = null)
    {
        $this->driver = strtolower($driver ?? (string) env('OCR_DRIVER', 'mock'));
    }

    /**
     * Extract dari file path. Return:
     *   [
     *     'vendor'       => string|null,
     *     'total'        => float|null,
     *     'date'         => string|null  (Y-m-d),
     *     'currency'     => string  (default IDR),
     *     'confidence'   => float  (0-1),
     *     'raw'          => array  (raw response untuk debug),
     *     'driver'       => string,
     *   ]
     *
     * @throws \RuntimeException kalau file tidak ada / unreadable
     */
    public function extract(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new \RuntimeException('OCR: file tidak ditemukan atau unreadable: ' . $filePath);
        }
        return match ($this->driver) {
            'tesseract'     => $this->driverTesseract($filePath),
            'gcloud_vision' => $this->driverGcloudVision($filePath),
            'aws_textract'  => $this->driverAwsTextract($filePath),
            default         => $this->driverMock($filePath),
        };
    }

    /**
     * Persist hasil extract ke reimbursement row + tabel raw_data.
     * Idempotent — re-extract akan overwrite kolom OCR.
     *
     * @return array<string,mixed>  Hasil extract (untuk return ke API)
     */
    public function extractAndPersist(int $reimbursementId, string $filePath): array
    {
        $result = $this->extract($filePath);
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('reimbursements')->where('id', $reimbursementId)->update([
            'ocr_extracted_at' => $now,
            'ocr_confidence'   => $result['confidence'] ?? null,
            'ocr_raw_data'     => json_encode($result['raw'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'       => $now,
        ]);
        return $result;
    }

    /**
     * Pure parser — input plaintext OCR output, output normalized field.
     * Exposed untuk testability + bisa dipanggil ulang dari raw_data
     * tanpa hit OCR engine.
     *
     * Heuristic:
     *   - VENDOR: line 1-3 yang panjangnya 3-50 char, bukan tanggal/total
     *   - TOTAL: line dengan keyword "TOTAL"/"GRAND TOTAL"/"JUMLAH" + angka
     *   - DATE: regex DD/MM/YYYY atau YYYY-MM-DD
     *   - CURRENCY: "Rp" → IDR, "$" → USD, "€" → EUR
     *
     * @return array{vendor: string|null, total: float|null, date: string|null, currency: string, confidence: float}
     */
    public static function parseText(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));

        // VENDOR — biasanya 1-3 line pertama
        $vendor = null;
        foreach (array_slice($lines, 0, 3) as $line) {
            $l = trim($line);
            $len = strlen($l);
            if ($len < 3 || $len > 60) continue;
            // Skip kalau looks seperti tanggal atau angka utama
            if (preg_match('/^\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}$/', $l)) continue;
            if (preg_match('/^[\d.,\s]+$/', $l)) continue;
            $vendor = $l;
            break;
        }

        // TOTAL — cari keyword "total" + extract angka
        $total = null;
        foreach ($lines as $line) {
            if (! preg_match('/(grand\s*total|total|jumlah|amount|subtotal)\s*[:.]?\s*(?:rp\s*\.?|\$|€)?\s*([\d.,]+)/i', $line, $m)) {
                continue;
            }
            $num = self::normalizeNumber($m[2]);
            if ($num === null) continue;
            // Ambil yang terbesar — biasanya GRAND TOTAL
            if ($total === null || $num > $total) $total = $num;
        }

        // DATE — DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD
        $date = null;
        foreach ($lines as $line) {
            if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $line, $m)) {
                $date = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
                break;
            }
            if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})/', $line, $m)) {
                $year = (int) $m[3];
                if ($year < 100) $year += 2000;
                $date = sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
                break;
            }
        }

        // CURRENCY heuristic
        $currency = 'IDR';
        if (preg_match('/\$\s*\d/', $text)) $currency = 'USD';
        elseif (preg_match('/€\s*\d/', $text)) $currency = 'EUR';
        elseif (stripos($text, 'rp') !== false || stripos($text, 'rupiah') !== false) $currency = 'IDR';

        // Confidence: weighted sum dari field yang berhasil di-extract
        $hits = 0;
        if ($vendor !== null) $hits++;
        if ($total !== null) $hits += 2;  // total paling penting
        if ($date !== null) $hits++;
        $maxHits = 4;
        $confidence = round($hits / $maxHits, 2);

        return [
            'vendor'     => $vendor,
            'total'      => $total,
            'date'       => $date,
            'currency'   => $currency,
            'confidence' => $confidence,
        ];
    }

    /**
     * Normalize string angka ke float, handle separator ID/EU/US.
     * "1.234.567,89" → 1234567.89
     * "1,234,567.89" → 1234567.89
     * "1234567"     → 1234567.0
     */
    public static function normalizeNumber(string $raw): ?float
    {
        $s = trim($raw);
        if ($s === '') return null;
        // Hapus kecuali digit, koma, titik, minus
        $s = preg_replace('/[^\d.,\-]/', '', $s);
        if ($s === '' || $s === null) return null;

        $hasComma = strpos($s, ',') !== false;
        $hasDot   = strpos($s, '.') !== false;

        if ($hasComma && $hasDot) {
            // Yang muncul terakhir = decimal separator
            if (strrpos($s, ',') > strrpos($s, '.')) {
                // Format ID: 1.234.567,89
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                // Format US: 1,234,567.89
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            // Hanya koma — kemungkinan decimal (ID) atau thousand (US-like)
            // Heuristic: kalau ada 3 digit setelah koma terakhir → thousand
            $parts = explode(',', $s);
            $last = end($parts);
            if (strlen($last) === 3 && count($parts) > 1) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace(',', '.', $s);
            }
        } elseif ($hasDot) {
            // Hanya titik — bisa decimal (US) atau thousand (ID).
            // Heuristic: kalau pola seperti "80.000" / "1.234.567" (semua
            // group setelah titik = 3 digit), treat sebagai thousand ID.
            $parts = explode('.', $s);
            $allThree = count($parts) > 1;
            for ($i = 1; $i < count($parts); $i++) {
                if (strlen($parts[$i]) !== 3) { $allThree = false; break; }
            }
            if ($allThree) {
                $s = str_replace('.', '', $s);
            }
            // else: format US biasa "1.5" → leave as is
        }

        if (! is_numeric($s)) return null;
        return (float) $s;
    }

    // ─── Drivers ────────────────────────────────────────────────────

    private function driverMock(string $filePath): array
    {
        // DEV / fallback — return dummy data sehingga FE bisa develop UI
        // tanpa setup OCR engine real. Confidence 0 supaya jelas mock.
        $name = basename($filePath);
        return [
            'vendor'     => 'Mock Vendor (' . $name . ')',
            'total'      => 0.0,
            'date'       => date('Y-m-d'),
            'currency'   => 'IDR',
            'confidence' => 0.0,
            'raw'        => ['driver' => 'mock', 'file' => $name,
                'note' => 'Set OCR_DRIVER env ke tesseract|gcloud_vision|aws_textract untuk extract real'],
            'driver'     => 'mock',
        ];
    }

    private function driverTesseract(string $filePath): array
    {
        // Shell out ke tesseract CLI. Convert PDF ke image dulu kalau perlu.
        $bin = (string) env('OCR_TESSERACT_BIN', 'tesseract');
        $tmpOut = tempnam(sys_get_temp_dir(), 'tess_');
        $cmd = sprintf('%s %s %s -l eng+ind 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($filePath),
            escapeshellarg($tmpOut),
        );
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        if ($exit !== 0) {
            @unlink($tmpOut . '.txt');
            throw new \RuntimeException('OCR tesseract gagal: ' . implode("\n", $output));
        }
        $text = @file_get_contents($tmpOut . '.txt') ?: '';
        @unlink($tmpOut . '.txt');

        $parsed = self::parseText($text);
        return [
            'vendor'     => $parsed['vendor'],
            'total'      => $parsed['total'],
            'date'       => $parsed['date'],
            'currency'   => $parsed['currency'],
            'confidence' => $parsed['confidence'],
            'raw'        => ['driver' => 'tesseract', 'text' => $text,
                'parsed' => $parsed],
            'driver'     => 'tesseract',
        ];
    }

    private function driverGcloudVision(string $filePath): array
    {
        $apiKey = (string) env('OCR_GCLOUD_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OCR_GCLOUD_API_KEY belum di-set');
        }
        $imageContent = base64_encode((string) file_get_contents($filePath));
        $payload = [
            'requests' => [[
                'image'    => ['content' => $imageContent],
                'features' => [['type' => 'TEXT_DETECTION', 'maxResults' => 1]],
            ]],
        ];
        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException('OCR gcloud_vision HTTP ' . $httpCode);
        }
        $decoded = json_decode((string) $response, true) ?: [];
        $text = (string) ($decoded['responses'][0]['fullTextAnnotation']['text'] ?? '');
        $parsed = self::parseText($text);
        return [
            'vendor'     => $parsed['vendor'],
            'total'      => $parsed['total'],
            'date'       => $parsed['date'],
            'currency'   => $parsed['currency'],
            'confidence' => $parsed['confidence'],
            'raw'        => ['driver' => 'gcloud_vision', 'text' => $text,
                'response' => $decoded, 'parsed' => $parsed],
            'driver'     => 'gcloud_vision',
        ];
    }

    private function driverAwsTextract(string $filePath): array
    {
        // Stub — implementation perlu AWS SDK (composer require aws/aws-sdk-php)
        // dan IAM role/credentials. Untuk sekarang throw → admin pilih driver
        // lain atau install SDK.
        throw new \RuntimeException(
            'OCR aws_textract belum diimplementasi. Install aws/aws-sdk-php dan '
            . 'ganti driver ke gcloud_vision atau tesseract sementara.'
        );
    }
}