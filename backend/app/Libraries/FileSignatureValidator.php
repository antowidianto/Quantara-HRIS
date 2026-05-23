<?php

namespace App\Libraries;

/**
 * FileSignatureValidator
 *
 * Cek tipe file berdasarkan magic bytes (file signature) — bukan hanya
 * MIME yang dikirim browser (mudah di-spoof). Penting untuk hindari upload
 * berbahaya (mis. file PHP yang disamar sebagai .jpg).
 *
 * Coverage: format yang umum dipakai HRIS — gambar (JPEG/PNG/WebP/GIF),
 * dokumen (PDF), dan kantor (DOCX/XLSX/PPTX, semuanya ZIP-based).
 *
 * Pakai sebagai static helper:
 *
 *   if (! FileSignatureValidator::isImage($filePath)) {
 *       return $this->fail('File bukan gambar valid', 415);
 *   }
 *
 * Atau cek satu tipe spesifik:
 *
 *   if (! FileSignatureValidator::matches($filePath, ['image/jpeg', 'image/png'])) { ... }
 */
class FileSignatureValidator
{
    /**
     * Mapping mime → list of byte signatures.
     * Setiap signature adalah array `['offset' => N, 'bytes' => "\xFF\xD8...]`.
     * Kalau salah satu match, file dianggap match mime tsb.
     */
    private const SIGNATURES = [
        'image/jpeg' => [
            ['offset' => 0, 'bytes' => "\xFF\xD8\xFF"],
        ],
        'image/png' => [
            ['offset' => 0, 'bytes' => "\x89PNG\r\n\x1A\n"],
        ],
        'image/gif' => [
            ['offset' => 0, 'bytes' => 'GIF87a'],
            ['offset' => 0, 'bytes' => 'GIF89a'],
        ],
        'image/webp' => [
            // RIFF....WEBP — signature di offset 0 + 8
            ['offset' => 0, 'bytes' => 'RIFF', 'and' => ['offset' => 8, 'bytes' => 'WEBP']],
        ],
        'image/bmp' => [
            ['offset' => 0, 'bytes' => 'BM'],
        ],
        'application/pdf' => [
            ['offset' => 0, 'bytes' => '%PDF-'],
        ],
        // ZIP-based: .docx, .xlsx, .pptx, .odt, .zip — semua mulai dengan
        // "PK\x03\x04" (local file header). Kita tidak bisa bedakan format
        // spesifik dari magic bytes saja — caller harus combine dengan ext.
        'application/zip' => [
            ['offset' => 0, 'bytes' => "PK\x03\x04"],
            ['offset' => 0, 'bytes' => "PK\x05\x06"], // empty zip
            ['offset' => 0, 'bytes' => "PK\x07\x08"], // spanned zip
        ],
    ];

    /** Allowed image MIME types untuk avatar/foto. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Allowed document MIME types untuk lampiran. */
    public const DOCUMENT_MIMES = ['application/pdf'];

    /**
     * Cek apakah file match salah satu MIME type yang diizinkan.
     */
    public static function matches(string $filePath, array $allowedMimes): bool
    {
        $detected = self::detect($filePath);
        if ($detected === null) return false;

        // ZIP-based format (docx/xlsx/dll) → cocokan ke 'application/zip'
        // dan biarkan caller cek ext-nya.
        $zipBased = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/zip',
        ];
        if (in_array($detected, ['application/zip'], true)) {
            foreach ($allowedMimes as $a) {
                if (in_array($a, $zipBased, true)) return true;
            }
        }

        return in_array($detected, $allowedMimes, true);
    }

    /**
     * Shorthand: cek file adalah gambar (JPEG/PNG/WebP/GIF).
     */
    public static function isImage(string $filePath): bool
    {
        return self::matches($filePath, self::IMAGE_MIMES);
    }

    /**
     * Shorthand: cek file adalah PDF.
     */
    public static function isPdf(string $filePath): bool
    {
        return self::matches($filePath, ['application/pdf']);
    }

    /**
     * Detect MIME type berdasarkan magic bytes. Return null kalau tidak
     * match signature mana pun (file mungkin executable / script / unknown).
     */
    public static function detect(string $filePath): ?string
    {
        if (! is_file($filePath) || ! is_readable($filePath)) return null;

        // Baca 32 byte pertama — cukup untuk semua signature di atas.
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) return null;
        $head = fread($fp, 32);
        fclose($fp);
        if ($head === false || $head === '') return null;

        foreach (self::SIGNATURES as $mime => $sigs) {
            foreach ($sigs as $sig) {
                if (! self::matchAt($head, $sig['offset'], $sig['bytes'])) continue;
                if (isset($sig['and']) && ! self::matchAt($head, $sig['and']['offset'], $sig['and']['bytes'])) {
                    continue;
                }
                return $mime;
            }
        }
        return null;
    }

    /**
     * Get safe file extension berdasarkan magic bytes (bukan dari nama
     * file). Berguna untuk normalize ekstensi saat simpan.
     *
     * Return null kalau tidak terdeteksi atau format tidak punya ext baku.
     */
    public static function safeExtension(string $filePath): ?string
    {
        $mime = self::detect($filePath);
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/bmp'  => 'bmp',
            'application/pdf' => 'pdf',
            default => null,
        };
    }

    /**
     * Cek bytes di posisi $offset dari $haystack match $needle.
     */
    private static function matchAt(string $haystack, int $offset, string $needle): bool
    {
        if ($needle === '') return false;
        if (strlen($haystack) < $offset + strlen($needle)) return false;
        return substr($haystack, $offset, strlen($needle)) === $needle;
    }
}