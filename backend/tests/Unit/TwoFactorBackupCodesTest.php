<?php

namespace Tests\Unit;

use App\Libraries\TwoFactorBackupCodes;
use PHPUnit\Framework\TestCase;

/**
 * TwoFactorBackupCodesTest — verifikasi generator + normalize tanpa DB.
 *
 * verify/persist/regenerate butuh DB → di-cover di integration test.
 *
 * Run: vendor/bin/phpunit --filter TwoFactorBackupCodesTest
 */
class TwoFactorBackupCodesTest extends TestCase
{
    private TwoFactorBackupCodes $svc;

    protected function setUp(): void
    {
        $this->svc = new TwoFactorBackupCodes();
    }

    public function testGenerateReturnsExpectedCount(): void
    {
        $codes = $this->svc->generate();
        $this->assertCount(TwoFactorBackupCodes::COUNT, $codes);
    }

    public function testGenerateCustomCount(): void
    {
        $codes = $this->svc->generate(3);
        $this->assertCount(3, $codes);
    }

    public function testEachCodeHasPlainAndHash(): void
    {
        $codes = $this->svc->generate(5);
        foreach ($codes as $c) {
            $this->assertArrayHasKey('code', $c);
            $this->assertArrayHasKey('hash', $c);
            $this->assertNotEmpty($c['code']);
            $this->assertNotEmpty($c['hash']);
        }
    }

    public function testCodeFormatIs5Dash5Uppercase(): void
    {
        $codes = $this->svc->generate(10);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z0-9]{5}-[A-Z0-9]{5}$/',
                $c['code'],
                "Code '{$c['code']}' tidak match format XXXXX-XXXXX"
            );
        }
    }

    public function testCodeExcludesAmbiguousChars(): void
    {
        // Pool exclude 0/O/1/I supaya user tidak bingung
        $codes = $this->svc->generate(20);
        foreach ($codes as $c) {
            $this->assertStringNotContainsString('0', $c['code']);
            $this->assertStringNotContainsString('O', $c['code']);
            $this->assertStringNotContainsString('1', $c['code']);
            $this->assertStringNotContainsString('I', $c['code']);
        }
    }

    public function testGeneratedCodesAreUnique(): void
    {
        // 50 kode harus semua unik (probabilitas collision ~0)
        $codes = $this->svc->generate(50);
        $plains = array_map(fn($c) => $c['code'], $codes);
        $this->assertCount(50, array_unique($plains),
            'Semua generated codes harus unik');
    }

    public function testHashIsArgon2id(): void
    {
        $codes = $this->svc->generate(1);
        $hash = $codes[0]['hash'];
        // Argon2id hash prefix
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testHashVerifiesAgainstPlain(): void
    {
        $codes = $this->svc->generate(3);
        foreach ($codes as $c) {
            $this->assertTrue(
                password_verify($c['code'], $c['hash']),
                "Hash harus match plaintext code"
            );
        }
    }

    public function testHashesAreDifferentForSameCode(): void
    {
        // Argon2id pakai random salt → hash sama plain harus beda
        $hash1 = password_hash('SAMETEST', PASSWORD_ARGON2ID);
        $hash2 = password_hash('SAMETEST', PASSWORD_ARGON2ID);
        $this->assertNotSame($hash1, $hash2);
    }

    // ─── normalize() ─────────────────────────────────────────────────

    public function testNormalizeRemovesSpacesAndDashes(): void
    {
        $this->assertSame('ABCDE12345', $this->svc->normalize('ABCDE-12345'));
        $this->assertSame('ABCDE12345', $this->svc->normalize('ABCDE 12345'));
        $this->assertSame('ABCDE12345', $this->svc->normalize(' ABCDE - 12345 '));
        $this->assertSame('ABCDE12345', $this->svc->normalize('ABCDE_12345'));
    }

    public function testNormalizeUppercases(): void
    {
        $this->assertSame('ABCDE12345', $this->svc->normalize('abcde-12345'));
    }

    public function testNormalizeEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->svc->normalize(''));
        $this->assertSame('', $this->svc->normalize('  -  '));
    }
}