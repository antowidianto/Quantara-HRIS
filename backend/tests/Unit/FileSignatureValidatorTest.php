<?php

namespace Tests\Unit;

use App\Libraries\FileSignatureValidator;
use PHPUnit\Framework\TestCase;

/**
 * FileSignatureValidatorTest — verifikasi magic-byte detection untuk
 * file upload security. Test pakai file fixture yang ditulis on-the-fly
 * ke temp dir supaya tidak butuh real binary.
 *
 * Run: vendor/bin/phpunit --filter FileSignatureValidatorTest
 */
class FileSignatureValidatorTest extends TestCase
{
    private string $tmpDir;
    /** @var string[] */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hris_filesig_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Tulis byte content ke file temp dan return path-nya.
     */
    private function writeFile(string $content, string $name = 'test.bin'): string
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;
        return $path;
    }

    // ─── JPEG ───────────────────────────────────────────────────────

    public function testDetectsJpegFromMagicBytes(): void
    {
        $path = $this->writeFile("\xFF\xD8\xFF\xE0\x00\x10JFIF" . str_repeat("\x00", 100));
        $this->assertSame('image/jpeg', FileSignatureValidator::detect($path));
        $this->assertTrue(FileSignatureValidator::isImage($path));
    }

    public function testJpegFilenameDoesNotMatterIfBytesValid(): void
    {
        // Walaupun nama file .pdf, magic byte JPEG → tetap detect sebagai JPEG
        $path = $this->writeFile("\xFF\xD8\xFF" . str_repeat("\x00", 100), 'fake.pdf');
        $this->assertSame('image/jpeg', FileSignatureValidator::detect($path));
    }

    // ─── PNG ────────────────────────────────────────────────────────

    public function testDetectsPng(): void
    {
        $path = $this->writeFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 100));
        $this->assertSame('image/png', FileSignatureValidator::detect($path));
    }

    // ─── GIF ────────────────────────────────────────────────────────

    public function testDetectsGif87a(): void
    {
        $path = $this->writeFile('GIF87a' . str_repeat("\x00", 100));
        $this->assertSame('image/gif', FileSignatureValidator::detect($path));
    }

    public function testDetectsGif89a(): void
    {
        $path = $this->writeFile('GIF89a' . str_repeat("\x00", 100));
        $this->assertSame('image/gif', FileSignatureValidator::detect($path));
    }

    // ─── WebP ───────────────────────────────────────────────────────

    public function testDetectsWebp(): void
    {
        // RIFF + 4 byte size + WEBP
        $path = $this->writeFile('RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat("\x00", 100));
        $this->assertSame('image/webp', FileSignatureValidator::detect($path));
    }

    public function testRiffWithoutWebpIsNotWebp(): void
    {
        // RIFF tapi bukan WEBP (mis. WAV audio yang RIFF...WAVE)
        $path = $this->writeFile('RIFF' . "\x00\x00\x00\x00" . 'WAVE' . str_repeat("\x00", 100));
        $this->assertNotSame('image/webp', FileSignatureValidator::detect($path));
    }

    // ─── PDF ────────────────────────────────────────────────────────

    public function testDetectsPdf(): void
    {
        $path = $this->writeFile('%PDF-1.4' . str_repeat("\x00", 100));
        $this->assertSame('application/pdf', FileSignatureValidator::detect($path));
        $this->assertTrue(FileSignatureValidator::isPdf($path));
    }

    public function testPdfIsNotImage(): void
    {
        $path = $this->writeFile('%PDF-1.4' . str_repeat("\x00", 100));
        $this->assertFalse(FileSignatureValidator::isImage($path));
    }

    // ─── Reject malicious payloads ──────────────────────────────────

    public function testRejectsPhpScriptDisguisedAsImage(): void
    {
        // Penyerang upload .php yang di-rename ke .jpg → magic byte beda
        $path = $this->writeFile('<?php echo "hack"; ?>', 'malicious.jpg');
        $this->assertNull(FileSignatureValidator::detect($path));
        $this->assertFalse(FileSignatureValidator::isImage($path));
    }

    public function testRejectsHtmlDisguisedAsImage(): void
    {
        $path = $this->writeFile('<html><body>not a real image</body></html>', 'fake.png');
        $this->assertNull(FileSignatureValidator::detect($path));
        $this->assertFalse(FileSignatureValidator::isImage($path));
    }

    public function testRejectsExecutableDisguisedAsImage(): void
    {
        // Windows PE: "MZ" header
        $path = $this->writeFile('MZ' . "\x90\x00\x03" . str_repeat("\x00", 100), 'fake.png');
        $this->assertNull(FileSignatureValidator::detect($path));
        $this->assertFalse(FileSignatureValidator::isImage($path));
    }

    public function testRejectsEmptyFile(): void
    {
        $path = $this->writeFile('');
        $this->assertNull(FileSignatureValidator::detect($path));
    }

    public function testReturnsNullForMissingFile(): void
    {
        $this->assertNull(FileSignatureValidator::detect('/path/that/does/not/exist'));
    }

    // ─── matches() with allowlist ───────────────────────────────────

    public function testMatchesPositive(): void
    {
        $path = $this->writeFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 100));
        $this->assertTrue(FileSignatureValidator::matches($path, ['image/jpeg', 'image/png']));
    }

    public function testMatchesNegative(): void
    {
        $path = $this->writeFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 100));
        $this->assertFalse(FileSignatureValidator::matches($path, ['image/jpeg', 'application/pdf']));
    }

    // ─── safeExtension() ────────────────────────────────────────────

    public function testSafeExtensionForJpeg(): void
    {
        $path = $this->writeFile("\xFF\xD8\xFF" . str_repeat("\x00", 100));
        $this->assertSame('jpg', FileSignatureValidator::safeExtension($path));
    }

    public function testSafeExtensionForPng(): void
    {
        $path = $this->writeFile("\x89PNG\r\n\x1A\n" . str_repeat("\x00", 100));
        $this->assertSame('png', FileSignatureValidator::safeExtension($path));
    }

    public function testSafeExtensionForPdf(): void
    {
        $path = $this->writeFile('%PDF-1.4' . str_repeat("\x00", 100));
        $this->assertSame('pdf', FileSignatureValidator::safeExtension($path));
    }

    public function testSafeExtensionReturnsNullForUnknown(): void
    {
        $path = $this->writeFile('<?php hack ?>');
        $this->assertNull(FileSignatureValidator::safeExtension($path));
    }
}