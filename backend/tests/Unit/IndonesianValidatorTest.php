<?php

namespace Tests\Unit;

use App\Libraries\System\IndonesianValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * IndonesianValidatorTest — pure-function unit test, tidak butuh DB.
 *
 * Pakai sebagai contoh / template untuk migrasi smoke script lain ke PHPUnit.
 * Lihat docs/AUDIT.md item A.
 *
 * Run: vendor/bin/phpunit --testsuite Unit
 */
class IndonesianValidatorTest extends TestCase
{
    // ─── NIK ────────────────────────────────────────────────────────

    public static function nikProvider(): array
    {
        return [
            // Valid: 16 digit, 6 digit ke-7..12 = tanggal (DDMMYY) yg masuk akal
            'valid 16 digit standard' => ['3173011505900001', true],
            'valid female (DD+40)'    => ['3173015505900001', true],

            // Invalid
            'kosong'         => ['', false],
            'null'           => [null, false],
            '15 digit'       => ['317301150590001', false],
            '17 digit'       => ['31730115059000011', false],
            'huruf'          => ['317301150590ABC1', false],
            'all zero'       => ['0000000000000000', false],
        ];
    }

    #[DataProvider('nikProvider')]
    public function testNikValidation(?string $nik, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidNik($nik));
    }

    // ─── NPWP ───────────────────────────────────────────────────────

    public static function npwpProvider(): array
    {
        return [
            'valid 15 digit'             => ['012345678901234', true],
            'valid 16 digit (NPWP baru)' => ['1234567890123456', true],
            'valid dengan format'        => ['01.234.567.8-901.234', true],
            '14 digit'                   => ['01234567890123', false],
            '17 digit'                   => ['12345678901234567', false],
            'huruf'                      => ['01234567890123A', false],
            'kosong'                     => ['', false],
            'null'                       => [null, false],
        ];
    }

    #[DataProvider('npwpProvider')]
    public function testNpwpValidation(?string $npwp, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidNpwp($npwp));
    }

    // ─── Phone ID ───────────────────────────────────────────────────

    public static function phoneProvider(): array
    {
        return [
            'format 08'             => ['081234567890', true],
            'format 8 (tanpa 0)'    => ['81234567890', true],
            'format +62'            => ['+6281234567890', true],
            'format 62'             => ['6281234567890', true],
            'dengan spasi & dash'   => ['+62 812-3456-7890', true],
            'kepanjangan 14 digit'  => ['081234567890123456', false],
            'kependekan 7 digit'    => ['0812345', false],
            'huruf'                 => ['08123ABCDEF', false],
            'kosong'                => ['', false],
            'null'                  => [null, false],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function testPhoneValidation(?string $phone, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidPhoneId($phone));
    }

    public function testPhoneNormalizationToZeroPrefix(): void
    {
        $this->assertSame('081234567890', IndonesianValidator::normalizePhoneId('+6281234567890'));
        $this->assertSame('081234567890', IndonesianValidator::normalizePhoneId('6281234567890'));
        $this->assertSame('081234567890', IndonesianValidator::normalizePhoneId('+62 812-3456-7890'));
        $this->assertSame('081234567890', IndonesianValidator::normalizePhoneId('081234567890'));
    }

    public function testPhoneNormalizationReturnsNullForInvalid(): void
    {
        $this->assertNull(IndonesianValidator::normalizePhoneId('abc'));
        $this->assertNull(IndonesianValidator::normalizePhoneId(''));
        $this->assertNull(IndonesianValidator::normalizePhoneId(null));
    }

    // ─── BPJS ───────────────────────────────────────────────────────

    public static function bpjsKesehatanProvider(): array
    {
        return [
            '13 digit angka' => ['0123456789012', true],
            '12 digit'       => ['012345678901', false],
            '14 digit'       => ['01234567890123', false],
            'huruf'          => ['012345678901A', false],
            'null'           => [null, false],
        ];
    }

    #[DataProvider('bpjsKesehatanProvider')]
    public function testBpjsKesehatan(?string $no, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidBpjsKesehatan($no));
    }

    public static function bpjsKetenagakerjaanProvider(): array
    {
        return [
            '11 digit valid' => ['12345678901', true],
            '10 digit'       => ['1234567890', false],
            '12 digit'       => ['123456789012', false],
            'mixed'          => ['1234567890A', false],
        ];
    }

    #[DataProvider('bpjsKetenagakerjaanProvider')]
    public function testBpjsKetenagakerjaan(?string $no, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidBpjsKetenagakerjaan($no));
    }

    // ─── Postal & PTKP ──────────────────────────────────────────────

    public function testPostalCode(): void
    {
        $this->assertTrue(IndonesianValidator::isValidPostalCode('12345'));
        $this->assertFalse(IndonesianValidator::isValidPostalCode('1234'));
        $this->assertFalse(IndonesianValidator::isValidPostalCode('123456'));
        $this->assertFalse(IndonesianValidator::isValidPostalCode('1234A'));
    }

    public static function ptkpProvider(): array
    {
        return [
            ['TK0', true],
            ['TK1', true],
            ['TK2', true],
            ['TK3', true],
            ['K0',  true],
            ['K1',  true],
            ['K2',  true],
            ['K3',  true],
            ['HB0', true],   // Hidup berpisah
            ['HB3', true],
            // Invalid
            ['TK4', false],  // PTKP max 3 tanggungan
            ['K4',  false],
            ['ABC', false],
            ['',    false],
            [null,  false],
        ];
    }

    #[DataProvider('ptkpProvider')]
    public function testPtkp(?string $code, bool $expected): void
    {
        $this->assertSame($expected, IndonesianValidator::isValidPtkp($code));
    }

    // Catatan: testFindMinimumWage di-skip dari unit test karena butuh
    // CodeIgniter framework untuk DB access. Akan dipindah ke integration
    // test di tahap berikutnya. Lihat AUDIT.md item A.
}
